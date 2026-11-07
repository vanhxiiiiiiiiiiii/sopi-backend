<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account\User\User;
use App\Models\Shop\Shop;
use Illuminate\Http\Request;

class AccountManagementController extends Controller
{
    public function customers(Request $request)
    {
        $customers = User::with('profile')
            ->when($request->filled('status'), fn ($query) => $query->where('account_status', $request->input('status')))
            ->orderByDesc('created_at')->paginate((int) $request->input('limit', 20));
        return response()->json(['status' => true, 'data' => $customers]);
    }

    public function updateCustomer(Request $request, User $user)
    {
        $data = $request->validate(['account_status' => 'required|in:active,inactive,blocked']);
        $user->update($data);
        return response()->json(['status' => true, 'data' => $user->fresh('profile')]);
    }

    public function sellers(Request $request)
    {
        $sellers = Shop::with('statistic')
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
