<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Delivery\DeliveryStaff;
use App\Models\Delivery\ShippingProvider;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeliveryManagementController extends Controller
{
    use \App\Http\Controllers\Api\Concerns\ValidatesOperationalInput;
    public function __construct() { $this->middleware('auth:sanctum'); }

    public function providers()
    {
        return response()->json(['status' => true, 'data' => ShippingProvider::orderBy('name')->get()]);
    }

    public function saveProvider(Request $request, ?int $id = null)
    {
        $provider = $id ? ShippingProvider::findOrFail($id) : new ShippingProvider();
        $data = $this->validated($request, ['code' => ['required', 'alpha_dash', 'max:40', Rule::unique('shipping_providers')->ignore($id)],
            'name' => 'required|string|max:120', 'phone' => 'nullable|string|max:20', 'is_active' => 'required|boolean']);
        $provider->fill($data)->save();
        return response()->json(['status' => true, 'data' => $provider]);
    }

    public function staff(Request $request)
    {
        $data = $this->validated($request, ['provider_id' => 'nullable|integer|min:1', 'limit' => 'nullable|integer|min:1|max:100', 'q' => 'nullable|string|max:100']);
        $staff = DeliveryStaff::with('provider')->when($data['provider_id'] ?? null, fn ($q, $id) => $q->where('shipping_provider_id', $id))
            ->when($data['q'] ?? null, fn ($q, $text) => $q->where(fn ($search) => $search->where('name', 'like', '%' . $text . '%')->orWhere('username', 'like', '%' . $text . '%')))
            ->orderBy('name')->paginate($data['limit'] ?? 100);
        return response()->json(['status' => true, 'data' => $staff]);
    }

    public function saveStaff(Request $request, ?int $id = null)
    {
        $staff = $id ? DeliveryStaff::findOrFail($id) : new DeliveryStaff();
        $data = $this->validated($request, [
            'shipping_provider_id' => ['required', 'integer', Rule::exists('shipping_providers', 'id')],
            'username' => ['required', 'alpha_dash', 'max:64', Rule::unique('delivery_staff')->ignore($id)],
            'email' => ['required', 'email', 'max:150', Rule::unique('delivery_staff')->ignore($id)],
            'name' => 'required|string|max:100', 'phone' => 'required|digits_between:10,11', 'is_active' => 'required|boolean',
            'password' => ($id ? 'nullable' : 'required') . '|string|min:12|max:128|confirmed',
        ]);
        if ($data['is_active'] && !ShippingProvider::findOrFail($data['shipping_provider_id'])->is_active) {
            throw \Illuminate\Validation\ValidationException::withMessages(['shipping_provider_id' => 'Chỉ kích hoạt nhân viên thuộc đơn vị đang hoạt động.']);
        }
        // Changing carrier while parcels are assigned would orphan their authorization scope.
        if ($id && (int) $data['shipping_provider_id'] !== (int) $staff->shipping_provider_id
            && \App\Models\Order\Shipment::where('shipper_id', $id)->exists()) {
            abort(409, 'Nhân viên đã có lịch sử phân công. Hãy tạo tài khoản riêng cho đơn vị mới.');
        }
        if (empty($data['password'])) unset($data['password']);
        $passwordChanged = isset($data['password']);
        $staff->fill($data)->save();
        if (!$staff->is_active || $passwordChanged) $staff->tokens()->delete();
        return response()->json(['status' => true, 'data' => $staff->fresh('provider')]);
    }
}
