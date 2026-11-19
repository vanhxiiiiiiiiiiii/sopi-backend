<?php

namespace App\Services;

use App\Models\Address;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class ShippingService
{
    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        $client = Http::acceptJson()->withUserAgent(config('shipping.user_agent'))->connectTimeout(5)->timeout(15);
        // PHP on Windows may have no PEM bundle configured. Use the OS trust store, preserving certificate verification.
        if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
            $client->withOptions(['curl' => [CURLOPT_SSL_OPTIONS => CURLSSLOPT_NATIVE_CA]]);
        }
        return $client;
    }

    public function settings(bool $lock = false): array
    {
        $row = DB::table('shipping_rate_settings')->where('id', 1)
            ->when($lock, fn ($query) => $query->sharedLock())->first();
        abort_unless($row, 503, 'Chưa thiết lập biểu phí vận chuyển.');
        return (array) $row;
    }

    public function route(Address $address, array $settings): array
    {
        if ($address->latitude === null || $address->longitude === null) {
            throw ValidationException::withMessages(['address_id' => 'Hãy xác nhận vị trí địa chỉ nhận hàng trên bản đồ.']);
        }
        $points = sprintf('%.7F,%.7F;%.7F,%.7F', $settings['hub_longitude'], $settings['hub_latitude'], $address->longitude, $address->latitude);
        $cache = Cache::store(config('shipping.cache_store'));
        $route = $cache->remember('shipping:route:' . hash('sha256', config('shipping.routing_url') . $points), 86400, function () use ($points) {
            try {
                $response = $this->client()
                    ->get(rtrim(config('shipping.routing_url'), '/') . '/route/v1/driving/' . $points,
                        ['overview' => 'simplified', 'geometries' => 'geojson', 'steps' => 'false', 'alternatives' => 'false', 'radiuses' => '1000;1000']);
            } catch (\Illuminate\Http\Client\ConnectionException $error) {
                abort(503, 'Không kết nối được bản đồ tính đường. Vui lòng thử lại.');
            }
            if (!$response->successful()) abort(503, 'Dịch vụ tính đường đang bận. Vui lòng thử lại.');
            $data = $response->json();
            if (($data['code'] ?? '') !== 'Ok' || !isset($data['routes'][0]['distance'])) {
                throw ValidationException::withMessages(['address_id' => 'Không tìm thấy tuyến đường ô tô tới điểm giao hàng. Hãy kiểm tra lại vị trí.']);
            }
            $distance = (float) $data['routes'][0]['distance'];
            abort_unless(is_finite($distance) && $distance >= 0 && count($data['waypoints'] ?? []) === 2, 503, 'Dữ liệu tuyến đường không hợp lệ.');
            foreach ($data['waypoints'] as $point) {
                if (!isset($point['distance']) || (float) $point['distance'] > 1000) {
                    throw ValidationException::withMessages(['address_id' => 'Điểm giao hàng quá xa đường ô tô. Hãy chọn lại vị trí.']);
                }
            }
            return ['distance_km' => ceil($distance / 100) / 10,
                'geometry' => $data['routes'][0]['geometry'] ?? null, 'source' => 'OSRM'];
        });
        if ($route['distance_km'] > (float) $settings['max_distance_km']) {
            throw ValidationException::withMessages(['address_id' => 'Địa chỉ nằm ngoài phạm vi giao hàng từ kho trung tâm.']);
        }
        return $route;
    }

    public function fee(float $subtotal, float $weightGrams, array $route, array $settings): array
    {
        if ($weightGrams <= 0) throw ValidationException::withMessages(['orders' => 'Sản phẩm cần có cân nặng hợp lệ để tính phí giao hàng.']);
        $extraKg = max(0, (int) ceil(($weightGrams - $settings['included_weight_grams']) / 1000));
        $base = (float) $settings['base_fee'];
        $weightFee = round($extraKg * (float) $settings['extra_kg_fee'], 2);
        $distanceFee = round(max(0, $route['distance_km'] - (float) $settings['included_distance_km']) * (float) $settings['per_km_fee'], 2);
        $free = $settings['free_shipping_threshold'] !== null && $subtotal >= (float) $settings['free_shipping_threshold'];
        return [
            'fee' => $free ? 0.0 : round($base + $weightFee + $distanceFee, 2),
            'base_fee' => $base, 'weight_fee' => $weightFee, 'distance_fee' => $distanceFee,
            'original_fee' => round($base + $weightFee + $distanceFee, 2), 'free_shipping' => $free,
            'free_shipping_threshold' => $settings['free_shipping_threshold'] === null ? null : (float) $settings['free_shipping_threshold'],
            'weight_grams' => round($weightGrams, 2), 'extra_kg' => $extraKg,
            'distance_km' => $route['distance_km'], 'source' => $route['source'],
            'hub_name' => $settings['hub_name'], 'hub_address' => $settings['hub_address'],
            'hub_latitude' => (float) $settings['hub_latitude'], 'hub_longitude' => (float) $settings['hub_longitude'],
            'rate_version' => $settings['version'],
        ];
    }

    public function search(string $query): array
    {
        $cache = Cache::store(config('shipping.cache_store'));
        $key = 'shipping:geocode:v2:' . hash('sha256', config('shipping.geocoding_url') . config('shipping.geocoding_fallback_url') . mb_strtolower(trim($query)));
        if ($cache->has($key)) return $cache->get($key);
        // A single application-wide gate, including all users, respects Nominatim's 1 request/second limit.
        abort_unless($cache->add('shipping:geocode:gate', true, 2), 429, 'Vui lòng chờ 2 giây rồi tìm địa chỉ lại.');
        try {
            $response = $this->client()
                ->get(rtrim(config('shipping.geocoding_url'), '/') . '/search',
                    ['q' => $query, 'format' => 'jsonv2', 'countrycodes' => 'vn', 'limit' => 5]);
        } catch (\Illuminate\Http\Client\ConnectionException $error) {
            $response = null;
        }
        if ($response?->successful() && is_array($response->json())) {
            $results = collect($response->json())->filter(fn ($item) => isset($item['lat'], $item['lon'], $item['display_name']))
                ->map(fn ($item) => ['latitude' => (float) $item['lat'], 'longitude' => (float) $item['lon'], 'label' => $item['display_name']])->values()->all();
        } else {
            $results = $this->fallbackSearch($query);
        }
        $cache->put($key, $results, 604800);
        return $results;
    }

    private function fallbackSearch(string $query): array
    {
        $url = config('shipping.geocoding_fallback_url');
        abort_unless($url, 503, 'Không kết nối được dịch vụ tìm địa chỉ. Bạn có thể chọn điểm trực tiếp trên bản đồ.');
        try {
            $response = $this->client()->get(rtrim($url, '/') . '/api/', ['q' => $query, 'limit' => 5]);
        } catch (\Illuminate\Http\Client\ConnectionException $error) {
            abort(503, 'Không kết nối được dịch vụ tìm địa chỉ. Bạn có thể chọn điểm trực tiếp trên bản đồ.');
        }
        abort_unless($response->successful() && is_array($response->json('features')), 503, 'Chưa tìm được địa chỉ. Vui lòng thử lại hoặc chọn trên bản đồ.');
        return collect($response->json('features'))->filter(function ($feature) {
            $point = $feature['geometry']['coordinates'] ?? [];
            return strtoupper($feature['properties']['countrycode'] ?? '') === 'VN' && count($point) >= 2
                && is_numeric($point[0]) && is_numeric($point[1]) && $point[1] >= 8 && $point[1] <= 24 && $point[0] >= 102 && $point[0] <= 110;
        })->map(function ($feature) {
            $properties = $feature['properties'];
            $label = collect(['name', 'housenumber', 'street', 'locality', 'city', 'state', 'country'])
                ->map(fn ($field) => $properties[$field] ?? null)->filter()->unique()->implode(', ');
            return ['latitude' => (float) $feature['geometry']['coordinates'][1],
                'longitude' => (float) $feature['geometry']['coordinates'][0], 'label' => $label];
        })->values()->all();
    }
}
