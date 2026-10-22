<?php

namespace App\Services;

use App\Models\RestaurantAuditLog;
use App\Models\SuperAdmin;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorGoodsReceipt;
use App\Models\VendorGoodsReceiptItem;
use App\Models\VendorPurchaseOrder;
use App\Models\VendorPurchaseOrderItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class VendorProcurementService
{
    public function createOrder(User $actor, Vendor $vendor, array $data): VendorPurchaseOrder
    {
        return DB::transaction(function () use ($actor, $vendor, $data): VendorPurchaseOrder {
            Vendor::query()->where('restaurant_id', $vendor->restaurant_id)->whereKey($vendor->id)->lockForUpdate()->firstOrFail();
            if ($vendor->status !== 'active') {
                throw ValidationException::withMessages(['vendor_id' => 'Only active vendors can be selected for a purchase order.']);
            }

            [$items, $totals] = $this->calculateItems($data['items']);
            $order = VendorPurchaseOrder::create([
                'restaurant_id' => $vendor->restaurant_id,
                'vendor_id' => $vendor->id,
                'order_date' => $data['order_date'],
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'status' => 'draft',
                ...$totals,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
            ]);
            $order->forceFill(['po_number' => sprintf('PO%06d', $order->id)])->save();
            $order->items()->createMany(array_map(
                fn (array $item): array => ['restaurant_id' => $order->restaurant_id, ...$item],
                $items,
            ));
            $this->audit($actor, $order->restaurant_id, 'purchase_order_created', 'vendor_purchase_order', $order->id, [
                'po_number' => $order->po_number,
                'vendor_id' => $vendor->id,
                'grand_total' => $order->grand_total,
            ]);

            return $order->load('items');
        });
    }

    public function updateDraft(User $actor, VendorPurchaseOrder $order, array $data): VendorPurchaseOrder
    {
        return DB::transaction(function () use ($actor, $order, $data): VendorPurchaseOrder {
            $order = VendorPurchaseOrder::query()->where('restaurant_id', $order->restaurant_id)
                ->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->status !== 'draft' || $order->goodsReceipts()->exists()) {
                throw ValidationException::withMessages(['purchase_order' => 'Only a draft without receipt history can be edited.']);
            }

            $vendor = Vendor::query()->where('restaurant_id', $order->restaurant_id)
                ->whereKey($data['vendor_id'])->firstOrFail();
            if ($vendor->status !== 'active') {
                throw ValidationException::withMessages(['vendor_id' => 'Only active vendors can be selected for a purchase order.']);
            }
            [$items, $totals] = $this->calculateItems($data['items']);
            $order->update([
                'vendor_id' => $vendor->id,
                'order_date' => $data['order_date'],
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                ...$totals,
                'notes' => $data['notes'] ?? null,
            ]);
            $order->items()->delete();
            $order->items()->createMany(array_map(
                fn (array $item): array => ['restaurant_id' => $order->restaurant_id, ...$item],
                $items,
            ));
            $this->audit($actor, $order->restaurant_id, 'purchase_order_updated', 'vendor_purchase_order', $order->id, [
                'po_number' => $order->po_number,
                'grand_total' => $order->grand_total,
            ]);

            return $order->load('items');
        });
    }

    public function confirm(User $actor, VendorPurchaseOrder $order): VendorPurchaseOrder
    {
        return DB::transaction(function () use ($actor, $order): VendorPurchaseOrder {
            $order = VendorPurchaseOrder::query()->where('restaurant_id', $order->restaurant_id)
                ->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->status !== 'draft' || ! $order->items()->exists()) {
                throw ValidationException::withMessages(['purchase_order' => 'Only a draft purchase order with line items can be confirmed.']);
            }

            $order->update(['status' => 'confirmed', 'confirmed_at' => now()]);
            $this->audit($actor, $order->restaurant_id, 'purchase_order_confirmed', 'vendor_purchase_order', $order->id, [
                'po_number' => $order->po_number,
            ]);

            return $order;
        });
    }

    public function cancel(User $actor, VendorPurchaseOrder $order): VendorPurchaseOrder
    {
        return DB::transaction(function () use ($actor, $order): VendorPurchaseOrder {
            $order = VendorPurchaseOrder::query()->where('restaurant_id', $order->restaurant_id)
                ->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array($order->status, ['draft', 'confirmed'], true) || $order->goodsReceipts()->exists()) {
                throw ValidationException::withMessages(['purchase_order' => 'A purchase order cannot be cancelled after receipt activity.']);
            }

            $order->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            $this->audit($actor, $order->restaurant_id, 'purchase_order_cancelled', 'vendor_purchase_order', $order->id, [
                'po_number' => $order->po_number,
            ]);

            return $order;
        });
    }

    public function receive(User $actor, VendorPurchaseOrder $order, array $data): VendorGoodsReceipt
    {
        return DB::transaction(function () use ($actor, $order, $data): VendorGoodsReceipt {
            $order = VendorPurchaseOrder::query()->where('restaurant_id', $order->restaurant_id)
                ->whereKey($order->id)->with('items')->lockForUpdate()->firstOrFail();
            $existing = VendorGoodsReceipt::query()->where('restaurant_id', $order->restaurant_id)
                ->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                if ($existing->purchase_order_id !== $order->id) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This receipt token was already used for another purchase order.']);
                }

                return $existing->load('items');
            }
            if (! in_array($order->status, ['confirmed', 'partially_received'], true)) {
                throw ValidationException::withMessages(['purchase_order' => 'Only confirmed, outstanding purchase orders can receive goods.']);
            }

            $preparedItems = [];
            foreach ($data['items'] as $submitted) {
                $poItem = $order->items->firstWhere('id', (int) $submitted['purchase_order_item_id']);
                if (! $poItem) {
                    throw ValidationException::withMessages(['items' => 'A received item does not belong to this purchase order.']);
                }
                $previous = VendorGoodsReceiptItem::query()
                    ->where('restaurant_id', $order->restaurant_id)
                    ->where('purchase_order_item_id', $poItem->id)
                    ->lockForUpdate()
                    ->sum('received_quantity');
                $previousMilli = self::quantityToMilli((string) $previous);
                $orderedMilli = self::quantityToMilli((string) $poItem->quantity);
                $receivedMilli = self::quantityToMilli((string) $submitted['received_quantity']);
                $rejectedMilli = self::quantityToMilli((string) ($submitted['rejected_quantity'] ?? '0'));
                if ($rejectedMilli > $receivedMilli || $receivedMilli > $orderedMilli - $previousMilli) {
                    throw ValidationException::withMessages([
                        'items' => 'Received and rejected quantities must be valid and cannot exceed the outstanding order quantity.',
                    ]);
                }
                if ($receivedMilli === 0) {
                    continue;
                }

                $preparedItems[] = [
                    'restaurant_id' => $order->restaurant_id,
                    'purchase_order_item_id' => $poItem->id,
                    'item_name' => $poItem->item_name,
                    'unit' => $poItem->unit,
                    'ordered_quantity' => $poItem->quantity,
                    'previously_received_quantity' => self::quantityFromMilli($previousMilli),
                    'received_quantity' => $submitted['received_quantity'],
                    'rejected_quantity' => $submitted['rejected_quantity'] ?? '0',
                    'accepted_quantity' => self::quantityFromMilli($receivedMilli - $rejectedMilli),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            if ($preparedItems === []) {
                throw ValidationException::withMessages(['items' => 'Enter at least one received line item.']);
            }

            $receipt = VendorGoodsReceipt::create([
                'restaurant_id' => $order->restaurant_id,
                'purchase_order_id' => $order->id,
                'vendor_id' => $order->vendor_id,
                'received_date' => $data['received_date'],
                'received_by' => $actor->id,
                'idempotency_key' => $data['idempotency_key'],
                'notes' => $data['notes'] ?? null,
            ]);
            $receipt->forceFill(['grn_number' => sprintf('GRN%06d', $receipt->id)])->save();
            $receipt->items()->createMany($preparedItems);
            $allComplete = $order->items->every(function (VendorPurchaseOrderItem $poItem) use ($order): bool {
                $received = VendorGoodsReceiptItem::query()
                    ->where('restaurant_id', $order->restaurant_id)
                    ->where('purchase_order_item_id', $poItem->id)
                    ->sum('received_quantity');

                return self::quantityToMilli((string) $received) >= self::quantityToMilli((string) $poItem->quantity);
            });
            $order->update(['status' => $allComplete ? 'received' : 'partially_received']);
            $this->audit($actor, $order->restaurant_id, 'goods_receipt_created', 'vendor_goods_receipt', $receipt->id, [
                'grn_number' => $receipt->grn_number,
                'purchase_order_id' => $order->id,
            ]);

            return $receipt->load('items');
        });
    }

    public function calculateItems(array $submittedItems): array
    {
        $items = [];
        $subtotal = 0;
        $discountTotal = 0;
        $taxTotal = 0;
        $grandTotal = 0;
        foreach ($submittedItems as $item) {
            $quantityMilli = self::quantityToMilli((string) $item['quantity']);
            $priceCents = VendorAccountingService::toCents((string) $item['unit_price']);
            $grossCents = intdiv(($quantityMilli * $priceCents) + 500, 1000);
            $discountCents = VendorAccountingService::toCents((string) ($item['discount_amount'] ?? '0'));
            if ($discountCents > $grossCents) {
                throw ValidationException::withMessages(['items' => 'A line discount cannot exceed its item value.']);
            }
            $taxRateHundredths = self::rateToHundredths((string) ($item['tax_rate'] ?? '0'));
            $taxCents = intdiv((($grossCents - $discountCents) * $taxRateHundredths) + 5000, 10000);
            $lineCents = $grossCents - $discountCents + $taxCents;
            $subtotal += $grossCents;
            $discountTotal += $discountCents;
            $taxTotal += $taxCents;
            $grandTotal += $lineCents;
            $items[] = [
                'item_name' => trim($item['item_name']),
                'quantity' => $item['quantity'],
                'unit' => trim($item['unit']),
                'unit_price' => $item['unit_price'],
                'discount_amount' => VendorAccountingService::formatCents($discountCents),
                'tax_rate' => number_format($taxRateHundredths / 100, 2, '.', ''),
                'tax_amount' => VendorAccountingService::formatCents($taxCents),
                'line_total' => VendorAccountingService::formatCents($lineCents),
            ];
        }

        return [$items, [
            'subtotal' => VendorAccountingService::formatCents($subtotal),
            'discount_total' => VendorAccountingService::formatCents($discountTotal),
            'tax_total' => VendorAccountingService::formatCents($taxTotal),
            'grand_total' => VendorAccountingService::formatCents($grandTotal),
        ]];
    }

    private static function quantityToMilli(string $quantity): int
    {
        if (! preg_match('/^\d{1,9}(?:\.\d{1,3})?$/', $quantity)) {
            throw ValidationException::withMessages(['items' => 'Quantity must be a positive number with up to three decimals.']);
        }
        [$whole, $fraction] = array_pad(explode('.', $quantity, 2), 2, '0');

        return ((int) $whole * 1000) + (int) str_pad($fraction, 3, '0');
    }

    private static function quantityFromMilli(int $quantityMilli): string
    {
        return intdiv($quantityMilli, 1000).'.'.str_pad((string) ($quantityMilli % 1000), 3, '0', STR_PAD_LEFT);
    }

    private static function rateToHundredths(string $rate): int
    {
        if (! preg_match('/^\d{1,3}(?:\.\d{1,2})?$/', $rate)) {
            throw ValidationException::withMessages(['items' => 'Tax rate must be between 0 and 100 percent.']);
        }
        $hundredths = VendorAccountingService::toCents($rate);
        if ($hundredths > 10000) {
            throw ValidationException::withMessages(['items' => 'Tax rate must be between 0 and 100 percent.']);
        }

        return $hundredths;
    }

    private function audit(User $owner, int $restaurantId, string $action, string $targetType, int $targetId, array $details): void
    {
        $admin = request()->attributes->get('restaurant_admin_access_admin');
        $superAdmin = $admin instanceof SuperAdmin ? $admin : null;

        RestaurantAuditLog::create([
            'actor_id' => $superAdmin?->id,
            'actor_type' => $superAdmin ? 'super_admin' : 'restaurant_owner',
            'restaurant_id' => $restaurantId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'details' => $superAdmin ? $details + ['admin_id' => $superAdmin->id] : $details + ['owner_id' => $owner->id],
        ]);
    }
}