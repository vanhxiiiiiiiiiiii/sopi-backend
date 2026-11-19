<?php

namespace App\Http\Middleware;

use App\Models\Delivery\DeliveryStaff;
use Closure;
use Illuminate\Http\Request;

class RequireDeliveryStaff
{
    public function handle(Request $request, Closure $next)
    {
        $staff = $request->user();
        abort_unless($staff instanceof DeliveryStaff && $staff->is_active && $staff->provider?->is_active
            && $staff->tokenCan('delivery'), 403, 'Tài khoản không có quyền vận chuyển.');
        return $next($request);
    }
}
