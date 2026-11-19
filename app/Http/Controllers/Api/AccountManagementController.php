<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account\User\User;
use App\Models\Shop\Shop;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccountManagementController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function customers(Request $request)
    {
        $request->validate(['limit' => 'nullable|integer|min:1|max:100', 'q' => 'nullable|string|max:100']);
        $customers = User::with('profile')
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($q) => $q->where('username', 'like', '%' . $request->input('q') . '%')->orWhere('email', 'like', '%' . $request->input('q') . '%')))
            ->when($request->filled('status'), fn ($query) => $query->where('account_status', $request->input('status')))
            ->orderByDesc('created_at')->paginate((int) $request->input('limit', 20));
        return response()->json(['status' => true, 'data' => $customers]);
    }

    public function updateCustomer(Request $request, User $user)
    {
        $data = $request->validate(['account_status' => 'required|in:active,inactive,blocked']);
        $user = DB::transaction(function () use ($user, $data) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $user->update($data);
            if ($user->account_status !== 'active') {
                $user->tokens()->delete();
            }
            return $user->fresh('profile');
        }, 3);
        return response()->json(['status' => true, 'data' => $user]);
    }

    public function sellers(Request $request)
    {
        $request->validate(['limit' => 'nullable|integer|min:1|max:100', 'q' => 'nullable|string|max:100']);
        $sellers = Shop::with('statistic')
            ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', '%' . $request->input('q') . '%'))
            ->when($request->has('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->orderByDesc('created_at')->paginate((int) $request->input('limit', 20));
        return response()->json(['status' => true, 'data' => $sellers]);
    }

    public function updateSeller(Request $request, Shop $shop)
    {
        $data = $request->validate(['is_active' => 'required|boolean']);
        $shop->update($data);
        return response()->json(['status' => true, 'data' => $shop->fresh('statistic')]);
    }
}
