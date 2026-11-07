<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RequirementRoutesTest extends TestCase
{
    public function test_required_ecommerce_routes_are_registered(): void
    {
        $uris = collect(Route::getRoutes()->getRoutes())->pluck('uri');

        foreach ([
            'api/v2/admin/dashboard',
            'api/v2/admin/reports/{report}',
            'api/v2/admin/reports/cache-benchmark',
            'api/v2/admin/vouchers',
            'api/v2/admin/customers',
            'api/v2/admin/sellers',
            'api/v2/shop/warehouses',
            'api/v2/shop/inventory/adjust',
            'api/v2/shop/orders/{order}/status',
            'api/v2/user/favorites',
            'api/v2/user/orders',
            'api/v2/user/payments/{payment}/pay',
        ] as $uri) {
            $this->assertContains($uri, $uris, "Missing route: {$uri}");
        }
    }
}
