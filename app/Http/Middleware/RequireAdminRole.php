<?php

namespace App\Http\Middleware;

use App\Models\Account\Admin\Admin;
use Closure;

class RequireAdminRole
{
    public function handle($request, Closure $next)
    {
        $admin = $request->user();
        abort_unless($admin instanceof Admin && $admin->is_active, 403, 'Tài khoản quản trị đã ngừng hoạt động.');
        $area = $request->segment(4);
        $permission = match ($area) {
            'administrators', 'roles', 'activity' => 'super',
            'shipping', 'shipments' => 'logistics',
            'customers', 'sellers', 'seller-applications', 'products', 'orders', 'payments', 'vouchers', 'reports' => 'commerce',
            'me', 'auth', 'password', 'dashboard', 'overview' => null,
            default => '__unknown__',
        };
        abort_if($permission !== null && !$admin->hasAccess($permission), 403, 'Vai trò của bạn không có quyền thực hiện chức năng này.');
        // Never expose commerce reports through the legacy shared dashboard.
        abort_if($area === 'dashboard' && !$admin->hasAccess('commerce'), 403, 'Hãy sử dụng trang quản lý vận chuyển.');
        return $next($request);
    }
}
