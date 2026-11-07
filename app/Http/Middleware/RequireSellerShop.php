<?php

namespace App\Http\Middleware;

use App\Models\Account\User\User;
use Closure;

class RequireSellerShop
{
    public function handle($request, Closure $next)
    {
        abort_unless($request->user() instanceof User, 403);
        if ($request->is('api/v2/shop') && $request->isMethod('GET')) {
            return $next($request);
        }
        abort_unless($request->user()->shop && $request->user()->shop->is_active, 403, 'Cửa hàng chưa được phê duyệt hoặc đã ngừng hoạt động.');
        return $next($request);
    }
}
