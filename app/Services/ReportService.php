<?php

namespace App\Services;

use App\Enums\OrderStatus;
use Illuminate\Support\Facades\DB;

class ReportService
{
    public function topSelling(string $from, string $to, int $limit = 10)
    {
        return $this->deliveredItems($from, $to)
            ->selectRaw('oi.product_id, oi.product_code, oi.product_name, SUM(oi.quantity) quantity_sold, SUM(oi.line_total) revenue')
            ->groupBy('oi.product_id', 'oi.product_code', 'oi.product_name')
            ->orderByDesc('quantity_sold')->limit($limit)->get();
    }

    public function topRevenue(string $month, int $limit = 10)
    {
        return $this->deliveredItems($month . '-01', date('Y-m-t', strtotime($month . '-01')))
            ->selectRaw('oi.product_id, oi.product_code, oi.product_name, SUM(oi.line_total) revenue, SUM(oi.quantity) quantity_sold')
            ->groupBy('oi.product_id', 'oi.product_code', 'oi.product_name')
            ->orderByDesc('revenue')->limit($limit)->get();
    }

    public function sellerRevenue(string $from, string $to)
    {
        return DB::table('orders as o')->join('shops as s', 's.id', '=', 'o.shop_id')
            ->where('o.status_id', OrderStatus::Delivered->value)->whereBetween('o.created_at', [$from, $to])
            ->selectRaw('s.id, s.seller_code, s.name, COUNT(o.id) order_count, SUM(o.payment_total) revenue, SUM(o.commission_amount) platform_commission')
            ->groupBy('s.id', 's.seller_code', 's.name')->orderByDesc('revenue')->get();
    }

    public function monthlyPlatformRevenue()
    {
        return DB::table('orders')->where('status_id', OrderStatus::Delivered->value)
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') month, COUNT(*) order_count, SUM(payment_total) gross_revenue, SUM(commission_amount) platform_revenue")
            ->groupByRaw("DATE_FORMAT(created_at, '%Y-%m')")->orderBy('month')->get();
    }

    public function customerSpending(string $from, string $to, ?int $limit = null)
    {
        $query = DB::table('users as u')->leftJoin('user_profiles as up', 'up.user_id', '=', 'u.id')
            ->join('orders as o', 'o.user_id', '=', 'u.id')
            ->where('o.status_id', OrderStatus::Delivered->value)->whereBetween('o.created_at', [$from, $to])
            ->selectRaw('u.id, u.customer_code, u.email, up.display_name, COUNT(o.id) order_count, SUM(o.payment_total) total_spent')
            ->groupBy('u.id', 'u.customer_code', 'u.email', 'up.display_name')->orderByDesc('total_spent');
        return ($limit ? $query->limit($limit) : $query)->get();
    }

    public function unsoldProducts(?string $month = null)
    {
        return DB::table('products as p')->leftJoin('order_items as oi', 'oi.product_id', '=', 'p.id')
            ->leftJoin('orders as o', function ($join) use ($month) {
                $join->on('o.id', '=', 'oi.order_id')->where('o.status_id', '=', OrderStatus::Delivered->value);
                if ($month) {
                    $join->whereRaw("DATE_FORMAT(o.created_at, '%Y-%m') = ?", [$month]);
                }
            })
            ->selectRaw('p.id, p.product_code, p.name, COALESCE(SUM(CASE WHEN o.id IS NOT NULL THEN oi.quantity ELSE 0 END), 0) quantity_sold')
            ->groupBy('p.id', 'p.product_code', 'p.name')->having('quantity_sold', '=', 0)->get();
    }

    public function lowStock(int $threshold = 10)
    {
        return DB::table('product_models as pm')->join('products as p', 'p.id', '=', 'pm.product_id')
            ->leftJoin('inventory_stocks as ist', 'ist.product_model_id', '=', 'pm.id')
            ->selectRaw('pm.id product_model_id, pm.sku, p.id product_id, p.product_code, p.name, COALESCE(SUM(ist.quantity), pm.stock) stock')
            ->groupBy('pm.id', 'pm.sku', 'p.id', 'p.product_code', 'p.name', 'pm.stock')
            ->having('stock', '<', $threshold)->orderBy('stock')->get();
    }

    public function sellThroughRates()
    {
        return DB::table('products as p')->leftJoin('product_models as pm', 'pm.product_id', '=', 'p.id')
            ->leftJoin('order_items as oi', 'oi.product_model_id', '=', 'pm.id')
            ->leftJoin('orders as o', function ($join) {
                $join->on('o.id', '=', 'oi.order_id')->where('o.status_id', '=', OrderStatus::Delivered->value);
            })->leftJoin('inventory_movements as im', function ($join) {
                $join->on('im.product_model_id', '=', 'pm.id')->where('im.movement_type', '=', 'import');
            })
            ->selectRaw('p.id, p.product_code, p.name, COALESCE(SUM(DISTINCT CASE WHEN o.id IS NOT NULL THEN oi.quantity END), 0) sold_quantity, COALESCE(SUM(DISTINCT CASE WHEN im.quantity_change > 0 THEN im.quantity_change END), 0) imported_quantity')
            ->groupBy('p.id', 'p.product_code', 'p.name')
            ->selectRaw('CASE WHEN COALESCE(SUM(DISTINCT CASE WHEN im.quantity_change > 0 THEN im.quantity_change END), 0) = 0 THEN 0 ELSE COALESCE(SUM(DISTINCT CASE WHEN o.id IS NOT NULL THEN oi.quantity END), 0) / SUM(DISTINCT CASE WHEN im.quantity_change > 0 THEN im.quantity_change END) END sell_through_rate')
            ->orderByDesc('sell_through_rate')->get();
    }

    public function customerOrderHistory(int $userId)
    {
        return DB::table('orders as o')->join('order_items as oi', 'oi.order_id', '=', 'o.id')
            ->leftJoin('payments as pay', 'pay.order_id', '=', 'o.id')->leftJoin('shipments as sh', 'sh.order_id', '=', 'o.id')
            ->where('o.user_id', $userId)
            ->select('o.order_code', 'o.created_at', 'o.status_id', 'o.payment_total', 'oi.product_code', 'oi.product_name', 'oi.quantity', 'oi.unit_price', 'oi.line_total', 'pay.method', 'pay.status as payment_status', 'sh.tracking_code', 'sh.status as shipment_status')
            ->orderByDesc('o.created_at')->get();
    }

    public function orderTimeline(int $orderId)
    {
        return DB::table('order_progresses as op')->join('order_status as os', 'os.id', '=', 'op.status_id')
            ->where('op.order_id', $orderId)->select('op.id', 'os.name as status', 'op.note', 'op.created_at')
            ->orderBy('op.created_at')->get();
    }

    public function topRatedProducts(int $limit = 10)
    {
        return DB::table('products as p')->join('product_reviews as pr', 'pr.product_id', '=', 'p.id')
            ->leftJoin('order_items as oi', 'oi.product_id', '=', 'p.id')
            ->leftJoin('orders as o', function ($join) {
                $join->on('o.id', '=', 'oi.order_id')->where('o.status_id', '=', OrderStatus::Delivered->value);
            })->selectRaw('p.id, p.product_code, p.name, AVG(pr.rating) average_rating, COUNT(DISTINCT pr.id) review_count, COUNT(DISTINCT o.user_id) buyer_count')
            ->groupBy('p.id', 'p.product_code', 'p.name')->orderByDesc('average_rating')->orderByDesc('review_count')->limit($limit)->get();
    }

    public function sellerCancellationRates()
    {
        return DB::table('shops as s')->join('orders as o', 'o.shop_id', '=', 's.id')
            ->selectRaw('s.id, s.seller_code, s.name, COUNT(o.id) total_orders, SUM(o.status_id IN (?, ?)) problem_orders, SUM(o.status_id IN (?, ?)) / COUNT(o.id) problem_rate', [OrderStatus::Cancelled->value, OrderStatus::Return->value, OrderStatus::Cancelled->value, OrderStatus::Return->value])
            ->groupBy('s.id', 's.seller_code', 's.name')->orderByDesc('problem_rate')->get();
    }

    public function monthlyOrderRates()
    {
        return DB::table('orders')->selectRaw("DATE_FORMAT(created_at, '%Y-%m') month, COUNT(*) total_orders, SUM(status_id = ?) successful, SUM(status_id = ?) cancelled, SUM(status_id = ?) returned", [OrderStatus::Delivered->value, OrderStatus::Cancelled->value, OrderStatus::Return->value])
            ->selectRaw('SUM(status_id = ?) / COUNT(*) successful_rate, SUM(status_id = ?) / COUNT(*) cancelled_rate, SUM(status_id = ?) / COUNT(*) return_rate', [OrderStatus::Delivered->value, OrderStatus::Cancelled->value, OrderStatus::Return->value])
            ->groupByRaw("DATE_FORMAT(created_at, '%Y-%m')")->orderBy('month')->get();
    }

    public function categoryMonthlyRevenue()
    {
        return DB::table('product_categories as pc')->join('product_category_paths as pcp', 'pcp.category_id', '=', 'pc.id')
            ->join('order_items as oi', 'oi.product_id', '=', 'pcp.product_id')->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where('o.status_id', OrderStatus::Delivered->value)
            ->selectRaw("pc.id, pc.name, DATE_FORMAT(o.created_at, '%Y-%m') month, SUM(oi.line_total) revenue")
            ->groupByRaw("pc.id, pc.name, DATE_FORMAT(o.created_at, '%Y-%m')")->orderBy('month')->orderByDesc('revenue')->get();
    }

    public function sellerMonthlyComparison()
    {
        $monthly = DB::table('orders')->where('status_id', OrderStatus::Delivered->value)
            ->selectRaw("shop_id, DATE_FORMAT(created_at, '%Y-%m-01') month, SUM(payment_total) revenue")
            ->groupByRaw("shop_id, DATE_FORMAT(created_at, '%Y-%m-01')");
        return DB::query()->fromSub($monthly, 'm')->join('shops as s', 's.id', '=', 'm.shop_id')
            ->selectRaw('s.id, s.seller_code, s.name, m.month, m.revenue, LAG(m.revenue) OVER (PARTITION BY m.shop_id ORDER BY m.month) previous_month_revenue')
            ->orderBy('m.month')->get();
    }

    public function repeatCategoryCustomers(string $from, string $to)
    {
        return DB::table('users as u')->join('orders as o', 'o.user_id', '=', 'u.id')->join('order_items as oi', 'oi.order_id', '=', 'o.id')
            ->join('product_category_paths as pcp', 'pcp.product_id', '=', 'oi.product_id')->join('product_categories as pc', 'pc.id', '=', 'pcp.category_id')
            ->where('o.status_id', OrderStatus::Delivered->value)->whereBetween('o.created_at', [$from, $to])
            ->selectRaw('u.id, u.customer_code, u.email, pc.id category_id, pc.name category_name, SUM(oi.quantity) quantity_bought')
            ->groupBy('u.id', 'u.customer_code', 'u.email', 'pc.id', 'pc.name')->having('quantity_bought', '>', 1)->orderByDesc('quantity_bought')->get();
    }

    public function voucherStatistics()
    {
        return DB::table('vouchers as v')->leftJoin('voucher_usages as vu', 'vu.voucher_id', '=', 'v.id')
            ->leftJoin('orders as o', 'o.id', '=', 'vu.order_id')
            ->selectRaw('v.id, v.code, v.name, COUNT(vu.id) usage_count, COALESCE(SUM(vu.discount_amount), 0) total_discount, COALESCE(SUM(o.payment_total), 0) order_revenue')
            ->groupBy('v.id', 'v.code', 'v.name')->orderByDesc('usage_count')->get();
    }

    public function invalidVoucherUsages()
    {
        return DB::table('voucher_usages as vu')->join('vouchers as v', 'v.id', '=', 'vu.voucher_id')->join('orders as o', 'o.id', '=', 'vu.order_id')
            ->where(function ($query) {
                $query->whereColumn('vu.used_at', '<', 'v.starts_at')->orWhereColumn('vu.used_at', '>', 'v.ends_at')
                    ->orWhereColumn('o.subtotal', '<', 'v.minimum_order_value')
                    ->orWhereColumn('v.used_count', '>', 'v.max_uses');
            })->select('vu.*', 'v.code', 'v.starts_at', 'v.ends_at', 'v.used_count', 'v.max_uses', 'o.subtotal', 'v.minimum_order_value')->get();
    }

    public function priceChanges()
    {
        return DB::table('product_price_histories as ph')->join('product_models as pm', 'pm.id', '=', 'ph.product_model_id')
            ->join('products as p', 'p.id', '=', 'pm.product_id')
            ->select('p.id as product_id', 'p.product_code', 'p.name', 'pm.sku', 'ph.old_price', 'ph.new_price', 'ph.effective_at')
            ->orderBy('p.id')->orderBy('ph.effective_at')->get();
    }

    private function deliveredItems(string $from, string $to)
    {
        return DB::table('order_items as oi')->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where('o.status_id', OrderStatus::Delivered->value)->whereBetween('o.created_at', [$from, $to]);
    }
}
