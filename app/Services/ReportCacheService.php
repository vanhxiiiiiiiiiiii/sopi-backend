<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Throwable;

class ReportCacheService
{
    public function __construct(private ReportService $reports)
    {
    }

    public function dashboard(string $from, string $to): array
    {
        $resolver = fn () => $this->buildDashboard($from, $to);

        try {
            return Cache::store(config('cache.reports_store'))->remember(
                'reports:dashboard:' . sha1($from . '|' . $to),
                now()->addMinutes(5),
                $resolver,
            );
        } catch (Throwable) {
            return $resolver();
        }
    }

    public function benchmark(string $from, string $to): array
    {
        $started = hrtime(true);
        $this->buildDashboard($from, $to);
        $withoutCacheMs = (hrtime(true) - $started) / 1_000_000;

        $store = Cache::store(config('cache.reports_store'));
        $key = 'reports:benchmark:' . sha1($from . '|' . $to . '|' . microtime(true));
        try {
            $store->remember($key, 60, fn () => $this->buildDashboard($from, $to));
            $started = hrtime(true);
            $store->get($key);
            $withCacheMs = (hrtime(true) - $started) / 1_000_000;
            $store->forget($key);
            $cacheStore = config('cache.reports_store');
        } catch (Throwable $exception) {
            $withCacheMs = null;
            $cacheStore = 'unavailable: ' . $exception->getMessage();
        }

        return [
            'cache_store' => $cacheStore,
            'without_cache_ms' => round($withoutCacheMs, 3),
            'with_cache_ms' => $withCacheMs === null ? null : round($withCacheMs, 3),
            'speedup' => $withCacheMs ? round($withoutCacheMs / $withCacheMs, 2) : null,
        ];
    }

    private function buildDashboard(string $from, string $to): array
    {
        return [
            'top_products' => $this->reports->topSelling($from, $to),
            'top_sellers' => $this->reports->sellerRevenue($from, $to)->take(10),
            'monthly_revenue' => $this->reports->monthlyPlatformRevenue(),
            'order_rates' => $this->reports->monthlyOrderRates(),
            'totals' => [
                'orders' => \DB::table('orders')->count(),
                'customers' => \DB::table('users')->count(),
                'sellers' => \DB::table('shops')->count(),
                'products' => \DB::table('products')->count(),
                'revenue' => \DB::table('orders')->where('status_id', 4)->sum('payment_total'),
            ],
        ];
    }
}
