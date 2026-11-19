<?php

namespace App\Services;

use App\Models\Inventory\InventoryMovement;
use App\Models\Inventory\InventoryStock;
use App\Models\Inventory\Warehouse;
use App\Models\Product\ProductModel;

class InventoryService
{
    // Caller must hold the model lock in a transaction before allocating its opening balance.
    public function initializeWarehouseStock(ProductModel $model, Warehouse $warehouse): InventoryStock
    {
        $openingQuantity = $model->inventoryStocks()->exists() ? 0 : (int) $model->stock;
        $stock = InventoryStock::firstOrCreate(
            ['warehouse_id' => $warehouse->id, 'product_model_id' => $model->id],
            ['quantity' => $openingQuantity, 'reserved_quantity' => 0],
        );

        if ($stock->wasRecentlyCreated && $openingQuantity > 0) {
            InventoryMovement::create([
                'warehouse_id' => $warehouse->id,
                'product_model_id' => $model->id,
                'movement_type' => 'opening_balance',
                'quantity_change' => $openingQuantity,
                'quantity_after' => $openingQuantity,
                'note' => 'Phân bổ tồn đầu kỳ vào kho, không tăng tồn tổng.',
            ]);
        }

        return $stock;
    }
}
