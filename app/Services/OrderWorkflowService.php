<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Inventory\InventoryMovement;
use App\Models\Inventory\InventoryStock;
use App\Models\Inventory\Warehouse;
use App\Models\Order\Order;
use App\Models\Product\ProductModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderWorkflowService
{
    private const TRANSITIONS = [
        1 => [2, 5],
        2 => [3, 5],
        3 => [4, 5],
        4 => [6],
        5 => [],
        6 => [],
    ];

    public function transition(Order $order, OrderStatus $newStatus, string $note = '', ?int $pickupAddressId = null, ?OrderStatus $requiredStatus = null): Order
    {
        return DB::transaction(function () use ($order, $newStatus, $note, $pickupAddressId, $requiredStatus) {
            $lockedOrder = Order::whereKey($order->id)->with('items')->lockForUpdate()->firstOrFail();
            $currentStatus = (int) $lockedOrder->status_id;

            if (($requiredStatus && $currentStatus !== $requiredStatus->value)
                || !in_array($newStatus->value, self::TRANSITIONS[$currentStatus] ?? [], true)) {
                throw ValidationException::withMessages(['status_id' => 'Invalid order status transition.']);
            }

            if ($newStatus === OrderStatus::Ready) {
                if (!$lockedOrder->stock_committed_at) {
                    $this->deductInventory($lockedOrder);
                    $lockedOrder->stock_committed_at = now();
                }
                if ($pickupAddressId) {
                    $lockedOrder->pickup_address_id = $pickupAddressId;
                }
                $lockedOrder->shipment()->firstOrCreate(
                    [],
                    [
                        'tracking_code' => 'SHP-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(8)),
                        'carrier' => 'Pending assignment',
                        'shipping_fee' => $lockedOrder->shipping_fee,
                        'status' => 'preparing',
                    ],
                );
            }

            if ($newStatus === OrderStatus::Delivering) {
                $lockedOrder->shipment()->update(['status' => 'shipping', 'picked_up_at' => now()]);
            }

            if ($newStatus === OrderStatus::Delivered) {
                $lockedOrder->shipment()->update(['status' => 'delivered', 'delivered_at' => now()]);
                $lockedOrder->payments()->where('method', 'cod')->where('status', 'pending')->update([
                    'status' => 'paid',
                    'paid_at' => now(),
                ]);
                foreach ($lockedOrder->items as $item) {
                    DB::table('products')->where('id', $item->product_id)->increment('sold', $item->quantity);
                }
            }

            if (in_array($newStatus, [OrderStatus::Cancelled, OrderStatus::Return], true)) {
                $this->restoreInventory($lockedOrder, $newStatus === OrderStatus::Return ? 'return' : 'cancel');
                $lockedOrder->shipment()->update([
                    'status' => $newStatus === OrderStatus::Return ? 'returned' : 'cancelled',
                ]);
                $lockedOrder->payments()->where('status', 'pending')->update(['status' => 'cancelled']);
            }

            $lockedOrder->status_id = $newStatus->value;
            $lockedOrder->save();
            $lockedOrder->progresses()->create([
                'status_id' => $newStatus->value,
                'note' => $note ?: "Status changed to {$newStatus->name}",
            ]);

            return $lockedOrder->fresh(['items', 'payments', 'shipment', 'progresses']);
        }, 3);
    }

    // The caller holds an order transaction and owns the inventory locks until commit.
    public function deductInventory(Order $order): void
    {
        foreach ($order->items as $item) {
            $model = ProductModel::whereKey($item->product_model_id)->lockForUpdate()->firstOrFail();
            if ($model->stock < $item->quantity) {
                throw ValidationException::withMessages(['inventory' => "Insufficient stock for product model {$model->id}."]);
            }

            $stocks = InventoryStock::query()
                ->with('warehouse')
                ->where('product_model_id', $model->id)
                ->orderBy('warehouse_id')
                ->lockForUpdate()
                ->get();

            if ($stocks->isEmpty()) {
                $warehouse = Warehouse::where('shop_id', $order->shop_id)->where('is_active', true)->orderBy('id')->first()
                    ?? Warehouse::firstOrCreate(
                        ['warehouse_code' => 'WH-' . $order->shop_id . '-DEFAULT'],
                        ['shop_id' => $order->shop_id, 'name' => 'Default warehouse',
                            'address' => 'Not specified', 'manager_user_id' => $order->shop_id, 'is_active' => true],
                    );
                if (!$warehouse->is_active) {
                    throw ValidationException::withMessages(['inventory' => 'The warehouse is inactive.']);
                }
                $stocks = collect([InventoryStock::create([
                    'warehouse_id' => $warehouse->id,
                    'product_model_id' => $model->id,
                    'quantity' => $model->stock,
                    'reserved_quantity' => 0,
                ])]);
            } else {
                $stocks = $stocks->filter(fn ($stock) => $stock->warehouse?->is_active
                    && (int) $stock->warehouse->shop_id === (int) $order->shop_id);
            }

            if ($stocks->sum(fn ($stock) => max(0, $stock->quantity - $stock->reserved_quantity)) < $item->quantity) {
                throw ValidationException::withMessages(['inventory' => "Insufficient warehouse stock for product model {$model->id}."]);
            }

            $remaining = $item->quantity;
            foreach ($stocks as $stock) {
                if ($remaining === 0) {
                    break;
                }
                $deduct = min($remaining, max(0, $stock->quantity - $stock->reserved_quantity));
                if (!$deduct) {
                    continue;
                }
                $stock->decrement('quantity', $deduct);
                $stock->refresh();
                InventoryMovement::create([
                    'warehouse_id' => $stock->warehouse_id,
                    'product_model_id' => $model->id,
                    'order_id' => $order->id,
                    'movement_type' => 'sale',
                    'quantity_change' => -$deduct,
                    'quantity_after' => $stock->quantity,
                    'note' => 'Stock committed for order',
                ]);
                $remaining -= $deduct;
            }

            $model->decrement('stock', $item->quantity);
        }
    }

    private function restoreInventory(Order $order, string $reason): void
    {
        if (InventoryMovement::where('order_id', $order->id)->whereIn('movement_type', ['cancel', 'return'])->exists()) {
            return;
        }

        $saleMovements = InventoryMovement::where('order_id', $order->id)
            ->where('movement_type', 'sale')
            ->lockForUpdate()
            ->get();

        foreach ($saleMovements as $movement) {
            $quantity = abs($movement->quantity_change);
            $stock = InventoryStock::where('warehouse_id', $movement->warehouse_id)
                ->where('product_model_id', $movement->product_model_id)
                ->lockForUpdate()
                ->firstOrFail();
            $stock->increment('quantity', $quantity);
            $stock->refresh();
            ProductModel::whereKey($movement->product_model_id)->increment('stock', $quantity);
            InventoryMovement::create([
                'warehouse_id' => $movement->warehouse_id,
                'product_model_id' => $movement->product_model_id,
                'order_id' => $order->id,
                'movement_type' => $reason,
                'quantity_change' => $quantity,
                'quantity_after' => $stock->quantity,
                'note' => 'Stock restored after order ' . $reason,
            ]);
        }
    }
}
