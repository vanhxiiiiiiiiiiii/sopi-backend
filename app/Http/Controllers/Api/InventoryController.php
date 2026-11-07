<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory\InventoryMovement;
use App\Models\Inventory\InventoryStock;
use App\Models\Inventory\Warehouse;
use App\Models\Product\ProductModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function index(Request $request)
    {
        $warehouses = Warehouse::where('shop_id', $request->user()->id)
            ->with(['stocks.productModel.product'])->get();
        return response()->json(['status' => true, 'data' => $warehouses]);
    }

    public function storeWarehouse(Request $request)
    {
        $data = $request->validate([
            'warehouse_code' => 'required|string|max:30|unique:warehouses,warehouse_code',
            'name' => 'required|string|max:150',
            'address' => 'required|string',
            'is_active' => 'nullable|boolean',
        ]);
        $warehouse = Warehouse::create([
            ...$data,
            'shop_id' => $request->user()->id,
            'manager_user_id' => $request->user()->id,
            'is_active' => $data['is_active'] ?? true,
        ]);
        return response()->json(['status' => true, 'data' => $warehouse], 201);
    }

    public function adjust(Request $request)
    {
        $data = $request->validate([
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'product_model_id' => 'required|integer|exists:product_models,id',
            'quantity_change' => 'required|integer|not_in:0',
            'movement_type' => 'required|in:import,adjustment,damage',
            'note' => 'nullable|string',
        ]);

        $stock = DB::transaction(function () use ($data, $request) {
            $warehouse = Warehouse::whereKey($data['warehouse_id'])->where('shop_id', $request->user()->id)->lockForUpdate()->firstOrFail();
            $model = ProductModel::whereKey($data['product_model_id'])
                ->whereHas('product', fn ($query) => $query->where('shop_id', $request->user()->id))
                ->lockForUpdate()->firstOrFail();
            $stock = InventoryStock::firstOrCreate(
                ['warehouse_id' => $warehouse->id, 'product_model_id' => $model->id],
                ['quantity' => 0, 'reserved_quantity' => 0],
            );
            $stock = InventoryStock::whereKey($stock->id)->lockForUpdate()->first();
            $newQuantity = $stock->quantity + $data['quantity_change'];
            if ($newQuantity < 0) {
                throw ValidationException::withMessages(['quantity_change' => 'Inventory cannot become negative.']);
            }
            $stock->update(['quantity' => $newQuantity]);
            $model->increment('stock', $data['quantity_change']);
            InventoryMovement::create([
                ...$data,
                'quantity_after' => $newQuantity,
            ]);
            return $stock->fresh('productModel.product');
        }, 3);

        return response()->json(['status' => true, 'data' => $stock]);
    }
}
