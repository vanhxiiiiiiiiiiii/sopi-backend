<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account\Admin\Admin;
use App\Models\Account\Admin\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AdminAccessController extends Controller
{
    public function me(Request $request)
    {
        return response()->json(['data' => $request->user()->load('role')]);
    }

    public function roles()
    {
        return response()->json(['data' => Role::whereIn('code', array_keys(config('admin_roles')))->get()
            ->map(fn ($role) => [...$role->toArray(), 'permissions' => config('admin_roles.' . $role->code . '.permissions')])]);
    }

    public function overview(Request $request)
    {
        $admin = $request->user();
        $counts = [];
        if ($admin->hasAccess('super')) $counts['administrators'] = Admin::count();
        if ($admin->hasAccess('commerce')) {
            foreach (['users', 'shops', 'products', 'orders'] as $table) $counts[$table] = DB::table($table)->count();
        }
        if ($admin->hasAccess('logistics')) {
            foreach (['shipments', 'delivery_staff', 'shipping_providers'] as $table) $counts[$table] = DB::table($table)->count();
        }
        return response()->json(['data' => ['counts' => $counts]]);
    }

    public function index(Request $request)
    {
        $data = $request->validate(['q' => 'nullable|string|max:100', 'limit' => 'nullable|integer|min:1|max:100']);
        return response()->json(['data' => Admin::when($data['q'] ?? null, fn ($q, $text) => $q->where(fn ($q) =>
            $q->where('username', 'like', '%' . $text . '%')->orWhere('email', 'like', '%' . $text . '%')))
            ->orderBy('id')->paginate($data['limit'] ?? 15)]);
    }

    public function store(Request $request) { return $this->save($request); }
    public function update(Request $request, int $id) { return $this->save($request, $id); }

    private function save(Request $request, ?int $id = null)
    {
        $data = $request->validate([
            'username' => ['required', 'alpha_dash', 'max:50', Rule::unique('administrators')->ignore($id)],
            'email' => ['required', 'email', 'max:50', Rule::unique('administrators')->ignore($id)],
            'role_code' => ['required', Rule::in(array_keys(config('admin_roles')))],
            'is_active' => 'required|boolean',
            'password' => ($id ? 'nullable' : 'required') . '|string|min:12|max:128|confirmed',
        ]);
        $admin = DB::transaction(function () use ($request, $id, $data) {
            // Serialize role changes, including concurrent attempts to remove the last Super Admin.
            $super = Role::where('code', 'super_admin')->lockForUpdate()->firstOrFail();
            $actor = Admin::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->hasAccess('super'), 403);
            $target = $id ? Admin::whereKey($id)->lockForUpdate()->firstOrFail() : new Admin();
            $role = Role::where('code', $data['role_code'])->firstOrFail();
            $before = $id ? $target->only(['role_id', 'is_active']) : null;
            if ($id === $actor->id) {
                abort_if(!$data['is_active'] || (int) $role->id !== (int) $target->role_id, 422, 'Không thể tự khóa hoặc tự đổi vai trò của mình.');
            }
            if ($id && (int) $target->role_id === (int) $super->id && $target->is_active
                && (!$data['is_active'] || (int) $role->id !== (int) $super->id)) {
                abort_if(Admin::where('role_id', $super->id)->where('is_active', true)->count() <= 1,
                    422, 'Phải giữ ít nhất một Super Admin đang hoạt động.');
            }
            $target->forceFill(['username' => $data['username'], 'email' => $data['email'],
                'role_id' => $role->id, 'is_active' => $data['is_active']]);
            if (!empty($data['password'])) $target->password = Hash::make($data['password']);
            $target->save();
            if ($id && (!$target->is_active || !empty($data['password']) || (int) $before['role_id'] !== (int) $role->id)) {
                $target->tokens()->delete();
            }
            DB::table('admin_activity_logs')->insert([
                'actor_id' => $actor->id, 'action' => $id ? 'admin_updated' : 'admin_created',
                'subject_type' => 'administrator', 'subject_id' => $target->id,
                'details' => json_encode(['before' => $before, 'after' => $target->only(['role_id', 'is_active']),
                    'password_changed' => !empty($data['password'])], JSON_THROW_ON_ERROR),
            ]);
            return $target->fresh('role');
        }, 3);
        return response()->json(['data' => $admin], $id ? 200 : 201);
    }

    public function activity()
    {
        return response()->json(['data' => DB::table('admin_activity_logs as l')
            ->leftJoin('administrators as a', 'a.id', '=', 'l.actor_id')
            ->select('l.*', 'a.username as actor_name')->orderByDesc('l.id')->paginate(20)]);
    }
}
