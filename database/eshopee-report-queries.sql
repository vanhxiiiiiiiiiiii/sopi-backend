USE e_commerce;

-- 1. 20 đơn hàng mới nhất, kèm cửa hàng và trạng thái.
SELECT o.id, o.order_code, s.name AS shop_name, os.name AS order_status,
       o.payment_total, o.created_at
FROM orders AS o
LEFT JOIN shops AS s ON s.id = o.shop_id
LEFT JOIN order_status AS os ON os.id = o.status_id
ORDER BY o.created_at DESC, o.id DESC
LIMIT 20;

-- 2. 10 sản phẩm bán chạy nhất, tính từ các đơn đã giao (status_id = 4).
SELECT p.id, p.product_code, p.name,
       SUM(oi.quantity) AS units_sold, SUM(oi.line_total) AS item_revenue
FROM order_items AS oi
JOIN orders AS o ON o.id = oi.order_id
JOIN products AS p ON p.id = oi.product_id
WHERE o.status_id = 4
GROUP BY p.id, p.product_code, p.name
ORDER BY units_sold DESC, p.id
LIMIT 10;

-- 3. Tổng giá trị thanh toán và hoa hồng của đơn đã giao, theo tháng đặt hàng.
SELECT DATE_FORMAT(created_at, '%Y-%m') AS order_month,
       COUNT(*) AS delivered_orders,
       SUM(payment_total) AS total_order_value,
       SUM(commission_amount) AS platform_commission
FROM orders
WHERE status_id = 4
GROUP BY order_month
ORDER BY order_month DESC;

-- 4. Biến thể còn dưới 10 sản phẩm, gồm cả biến thể hết hàng.
SELECT p.name, pm.sku,
       COALESCE(SUM(ist.quantity), pm.stock) AS stock_on_hand
FROM product_models AS pm
JOIN products AS p ON p.id = pm.product_id
LEFT JOIN inventory_stocks AS ist ON ist.product_model_id = pm.id
GROUP BY pm.id, p.name, pm.sku, pm.stock
HAVING stock_on_hand < 10
ORDER BY stock_on_hand, pm.id;

-- 5. Lịch sử trạng thái đơn hàng. Thay 1 bằng id lấy ở truy vấn số 1.
SELECT o.order_code, os.name AS order_status, op.note, op.created_at
FROM order_progresses AS op
JOIN orders AS o ON o.id = op.order_id
JOIN order_status AS os ON os.id = op.status_id
WHERE op.order_id = 1
ORDER BY op.created_at, op.id;
