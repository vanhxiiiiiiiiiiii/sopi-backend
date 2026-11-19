<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

trait UsesShippingRoutes
{
    protected function fakeShippingRoutes(float $distanceMeters = 10000): void
    {
        config(['shipping.cache_store' => 'array', 'shipping.routing_url' => 'https://routing.example.test',
            'shipping.geocoding_url' => 'https://geocoding.example.test', 'shipping.geocoding_fallback_url' => 'https://fallback.example.test']);
        DB::table('shipping_rate_settings')->where('id', 1)->update([
            'base_fee' => 2, 'included_weight_grams' => 1000, 'extra_kg_fee' => 1,
            'included_distance_km' => 3, 'per_km_fee' => 0.10,
            'free_shipping_threshold' => 100, 'max_distance_km' => 1500,
        ]);
        Http::preventStrayRequests();
        Http::fake(['https://routing.example.test/*' => Http::response([
            'code' => 'Ok', 'waypoints' => [['distance' => 1], ['distance' => 2]],
            'routes' => [['distance' => $distanceMeters, 'geometry' => ['type' => 'LineString',
                'coordinates' => [[105.7870861, 20.9809427], [105.8, 20.99]]]]],
        ])]);
    }
}
