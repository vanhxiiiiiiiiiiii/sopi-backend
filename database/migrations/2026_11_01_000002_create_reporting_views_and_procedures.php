<?php

use App\Enums\OrderStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        $delivered = OrderStatus::Delivered->value;

        DB::statement("CREATE OR REPLACE VIEW v_top_products AS
            SELECT
                oi.product_id,
                oi.product_code,
                oi.product_name,
                SUM(oi.quantity) AS quantity_sold,
                SUM(oi.line_total) AS revenue
            FROM order_items oi
            INNER JOIN orders o ON o.id = oi.order_id
            WHERE o.status_id = {$delivered}
            GROUP BY oi.product_id, oi.product_code, oi.product_name");

        DB::statement("CREATE OR REPLACE VIEW v_seller_revenue AS
            SELECT
                o.shop_id,
                s.seller_code,
                s.name AS shop_name,
                COUNT(DISTINCT o.id) AS successful_orders,
                SUM(o.payment_total) AS gross_revenue,
                SUM(o.commission_amount) AS platform_commission
            FROM orders o
            INNER JOIN shops s ON s.id = o.shop_id
            WHERE o.status_id = {$delivered}
            GROUP BY o.shop_id, s.seller_code, s.name");

        DB::statement("CREATE OR REPLACE VIEW v_monthly_revenue AS
            SELECT
                DATE_FORMAT(o.created_at, '%Y-%m-01') AS revenue_month,
                COUNT(DISTINCT o.id) AS successful_orders,
                SUM(o.payment_total) AS gross_revenue,
                SUM(o.commission_amount) AS platform_revenue
            FROM orders o
            WHERE o.status_id = {$delivered}
            GROUP BY DATE_FORMAT(o.created_at, '%Y-%m-01')");

        DB::unprepared("CREATE PROCEDURE sp_monthly_revenue(IN report_year INT)
            BEGIN
                SELECT *
                FROM v_monthly_revenue
                WHERE YEAR(revenue_month) = report_year
                ORDER BY revenue_month;
            END");

        DB::unprepared("CREATE PROCEDURE sp_customer_spending(
                IN customer_id BIGINT,
                IN date_from DATETIME,
                IN date_to DATETIME
            )
            BEGIN
                SELECT
                    u.id,
                    u.customer_code,
                    u.email,
                    COUNT(DISTINCT o.id) AS order_count,
                    COALESCE(SUM(o.payment_total), 0) AS total_spent
                FROM users u
                LEFT JOIN orders o
                    ON o.user_id = u.id
                    AND o.status_id = {$delivered}
                    AND o.created_at BETWEEN date_from AND date_to
                WHERE u.id = customer_id
                GROUP BY u.id, u.customer_code, u.email;
            END");

        DB::unprepared("CREATE PROCEDURE sp_create_order(
                IN p_user_id BIGINT,
                IN p_product_model_id BIGINT,
                IN p_quantity INT,
                IN p_received_address_id BIGINT,
                OUT p_order_id BIGINT
            )
            BEGIN
                DECLARE v_stock INT DEFAULT 0;
                DECLARE v_price DECIMAL(14, 2) DEFAULT 0;
                DECLARE v_product_id BIGINT;
                DECLARE v_shop_id BIGINT;
                DECLARE v_product_code VARCHAR(40);
                DECLARE v_product_name VARCHAR(255);
                DECLARE v_subtotal DECIMAL(14, 2);

                START TRANSACTION;

                SELECT pm.stock, pm.price, pm.product_id, p.shop_id, p.product_code, p.name
                INTO v_stock, v_price, v_product_id, v_shop_id, v_product_code, v_product_name
                FROM product_models pm
                INNER JOIN products p ON p.id = pm.product_id
                WHERE pm.id = p_product_model_id
                FOR UPDATE;

                IF v_stock < p_quantity OR p_quantity <= 0 THEN
                    ROLLBACK;
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Insufficient stock or invalid quantity';
                END IF;

                SET v_subtotal = ROUND(v_price * p_quantity, 2);

                INSERT INTO orders (
                    order_code, name, variations, quantity, total, grand_total,
                    subtotal, shipping_fee, discount_amount, payment_total,
                    commission_rate, commission_amount, user_id, product_id,
                    product_model_id, received_address_id, status_id, shop_id, created_at
                ) VALUES (
                    CONCAT('ORD-SP-', UNIX_TIMESTAMP(), '-', p_user_id),
                    v_product_name, '[]', p_quantity, v_subtotal, v_subtotal,
                    v_subtotal, 0, 0, v_subtotal,
                    5.00, ROUND(v_subtotal * 0.05, 2), p_user_id, v_product_id,
                    p_product_model_id, p_received_address_id, 1, v_shop_id, NOW()
                );

                SET p_order_id = LAST_INSERT_ID();

                INSERT INTO order_items (
                    order_id, product_id, product_model_id, product_code,
                    product_name, variations, quantity, unit_price, line_total,
                    created_at, updated_at
                ) VALUES (
                    p_order_id, v_product_id, p_product_model_id, v_product_code,
                    v_product_name, '[]', p_quantity, v_price, v_subtotal,
                    NOW(), NOW()
                );

                UPDATE product_models
                SET stock = stock - p_quantity
                WHERE id = p_product_model_id;

                INSERT INTO order_progresses (order_id, status_id, note, created_at)
                VALUES (p_order_id, 1, 'Order created via stored procedure', NOW());

                COMMIT;
            END");
    }

    public function down()
    {
        DB::unprepared('DROP PROCEDURE IF EXISTS sp_create_order');
        DB::unprepared('DROP PROCEDURE IF EXISTS sp_customer_spending');
        DB::unprepared('DROP PROCEDURE IF EXISTS sp_monthly_revenue');
        DB::statement('DROP VIEW IF EXISTS v_monthly_revenue');
        DB::statement('DROP VIEW IF EXISTS v_seller_revenue');
        DB::statement('DROP VIEW IF EXISTS v_top_products');
    }
};
