<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Delivery\DeliveryStaff;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class DeliveryAuthController extends Controller
{
    use \App\Http\Controllers\Api\Concerns\ValidatesOperationalInput;
    public function login(Request $request)
    {
        $data = $this->validated($request, ['usernameOrEmail' => 'required|string|max:150', 'password' => 'required|string|max:200']);
        $staff = DeliveryStaff::with('provider')->where('email', $data['usernameOrEmail'])->orWhere('username', $data['usernameOrEmail'])->first();
        if (!$staff || !$staff->is_active || !$staff->provider?->is_active || !Hash::check($data['password'], $staff->password)) {
            throw ValidationException::withMessages(['credentials' => 'Thông tin đăng nhập không đúng hoặc tài khoản đã bị khóa.']);
        }
        $token = $staff->createToken('delivery', ['delivery'], now()->addHours(8))->plainTextToken;
        return response()->json(['status' => true, 'data' => $staff, 'token' => $token]);
    }

    public function me(Request $request)
    {
        return response()->json(['status' => true, 'data' => $request->user()->load('provider')]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['status' => true]);
    }
}
