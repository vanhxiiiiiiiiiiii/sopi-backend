<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use App\Services\ReportCacheService;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(private ReportService $reports, private ReportCacheService $cache)
    {
        $this->middleware('auth:sanctum');
    }

    public function dashboard(Request $request)
    {
        $from = $request->input('from', now()->startOfYear()->toDateString());
        $to = $request->input('to', now()->endOfDay()->toDateTimeString());
        return response()->json(['status' => true, 'data' => $this->cache->dashboard($from, $to)]);
    }

    public function cacheBenchmark(Request $request)
    {
        $from = $request->input('from', now()->startOfYear()->toDateString());
        $to = $request->input('to', now()->endOfDay()->toDateTimeString());
        return response()->json(['status' => true, 'data' => $this->cache->benchmark($from, $to)]);
    }

    public function index(Request $request, string $report)
    {
        $from = $request->input('from', '2000-01-01');
        $to = $request->input('to', now()->endOfDay()->toDateTimeString());
        $data = match ($report) {
            'top-selling' => $this->reports->topSelling($from, $to, (int) $request->input('limit', 10)),
            'top-revenue' => $this->reports->topRevenue($request->input('month', now()->format('Y-m'))),
            'seller-revenue' => $this->reports->sellerRevenue($from, $to),
            'monthly-revenue' => $this->reports->monthlyPlatformRevenue(),
            'customer-spending' => $this->reports->customerSpending($from, $to),
            'top-customers' => $this->reports->customerSpending('2000-01-01', $to, 10),
            'unsold-products' => $this->reports->unsoldProducts($request->input('month')),
            'low-stock' => $this->reports->lowStock((int) $request->input('threshold', 10)),
            'sell-through' => $this->reports->sellThroughRates(),
            'customer-history' => $this->reports->customerOrderHistory((int) $request->input('user_id')),
            'order-timeline' => $this->reports->orderTimeline((int) $request->input('order_id')),
            'top-rated' => $this->reports->topRatedProducts(),
            'seller-problem-rates' => $this->reports->sellerCancellationRates(),
            'monthly-order-rates' => $this->reports->monthlyOrderRates(),
            'category-revenue' => $this->reports->categoryMonthlyRevenue(),
            'seller-monthly' => $this->reports->sellerMonthlyComparison(),
            'repeat-category-customers' => $this->reports->repeatCategoryCustomers($from, $to),
            'voucher-statistics' => $this->reports->voucherStatistics(),
            'invalid-vouchers' => $this->reports->invalidVoucherUsages(),
            'price-history' => $this->reports->priceChanges(),
            default => abort(404, 'Unknown report.'),
        };

        return response()->json(['status' => true, 'data' => $data]);
    }
}
