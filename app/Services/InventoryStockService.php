<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\InventoryItemBatch;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryStockBalance;
use App\Models\InventoryUnit;
use App\Models\RestaurantAuditLog;
use App\Models\SuperAdmin;
use App\Models\User;
use App\Models\VendorGoodsReceipt;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\NumberFormatException;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InventoryStockService
{
    public function postGoodsReceipt(User $actor, VendorGoodsReceipt $receipt): array
    {
        abort_unless((int) $actor->restaurant_id === (int) $receipt->restaurant_id, 404);

        return DB::transaction(function () use ($actor, $receipt): array {
            $receipt = VendorGoodsReceipt::query()->where('restaurant_id', $actor->restaurant_id)
                ->with(['items.purchaseOrderItem'])->lockForUpdate()->findOrFail($receipt->id);
            $receiptItems = $receipt->items->filter(fn ($line): bool => BigDecimal::of((string) $line->accepted_quantity)->isGreaterThan(0));
            $pending = [];
            $mappings = DB::table('inventory_vendor_item_mappings')
                ->where('restaurant_id', $receipt->restaurant_id)
                ->whereIn('purchase_order_item_id', $receiptItems->pluck('purchase_order_item_id'))
                ->get()->keyBy('purchase_order_item_id');
            foreach ($receiptItems as $line) {
                $mapping = $mappings->get($line->purchase_order_item_id);
                if (! $mapping) {
                    $pending[] = $line->item_name.' (mapping required)';
                    continue;
                }
                $item = InventoryItem::query()->where('restaurant_id', $receipt->restaurant_id)->findOrFail($mapping->inventory_item_id);
                $details = DB::table('inventory_grn_item_details')->where('restaurant_id', $receipt->restaurant_id)
                    ->where('receipt_item_id', $line->id)->first();
                if (($item->track_batches || $item->track_expiry)
                    && (blank($details?->batch_number) || ($item->track_expiry && blank($details?->expires_at)))) {
                    $pending[] = $line->item_name.' (batch and expiry details required)';
                }
            }
            if ($pending !== []) {
                return ['complete' => false, 'pending' => $pending];
            }

            foreach ($receiptItems as $line) {
                $idempotencyKey = 'vendor-grn:'.$receipt->id.':line:'.$line->id;
                if (InventoryMovement::query()->where('restaurant_id', $receipt->restaurant_id)
                    ->where('idempotency_key', $idempotencyKey)->exists()) {
                    continue;
                }
                $mapping = $mappings->get($line->purchase_order_item_id);
                $item = InventoryItem::query()->where('restaurant_id', $receipt->restaurant_id)->findOrFail($mapping->inventory_item_id);
                $location = InventoryLocation::query()->where('restaurant_id', $receipt->restaurant_id)->findOrFail($mapping->location_id);
                $factor = BigDecimal::of((string) $mapping->base_quantity_per_unit);
                $quantity = BigDecimal::of((string) $line->accepted_quantity)
                    ->multipliedBy($factor)->toScale(6, RoundingMode::HALF_UP);
                $orderItem = $line->purchaseOrderItem;
                $unitCost = BigDecimal::of((string) $orderItem->line_total)
                    ->dividedBy((string) $orderItem->quantity, 9, RoundingMode::HALF_UP)
                    ->dividedBy($factor, 6, RoundingMode::HALF_UP);
                $batch = null;
                $batchReceivedQuantity = BigDecimal::zero();
                $batchCost = BigDecimal::zero();
                $details = DB::table('inventory_grn_item_details')->where('restaurant_id', $receipt->restaurant_id)
                    ->where('receipt_item_id', $line->id)->first();
                if ($item->track_batches || $item->track_expiry) {
                    $batch = DB::table('inventory_item_batches')->where('restaurant_id', $receipt->restaurant_id)
                        ->where('inventory_item_id', $item->id)->where('location_id', $location->id)
                        ->where('batch_number', $details->batch_number)->lockForUpdate()->first();
                    if (! $batch) {
                        $batchId = DB::table('inventory_item_batches')->insertGetId([
                            'restaurant_id' => $receipt->restaurant_id, 'inventory_item_id' => $item->id,
                            'location_id' => $location->id, 'vendor_id' => $receipt->vendor_id,
                            'batch_number' => $details->batch_number,
                            'manufactured_at' => $details->manufactured_at, 'expires_at' => $details->expires_at,
                            'received_quantity' => '0', 'available_quantity' => '0', 'unit_cost' => '0',
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                    } else {
                        abort_unless($batch->expires_at === $details->expires_at
                            && $batch->manufactured_at === $details->manufactured_at, 422,
                            'A vendor batch number cannot be reused with different expiry dates.');
                        $batchId = (int) $batch->id;
                        $batchReceivedQuantity = BigDecimal::of((string) $batch->received_quantity);
                        $batchCost = BigDecimal::of((string) $batch->unit_cost);
                    }
                    $batch = (object) ['id' => $batchId];
                }
                $movement = $this->receive(
                    $actor, $item, $location, $quantity->__toString(), $unitCost->__toString(),
                    'purchase_receipt', $idempotencyKey, [
                        'batch_id' => $batch?->id,
                        'source_type' => 'vendor_grn', 'source_id' => $receipt->id,
                        'source_line_id' => $line->id, 'reference' => $receipt->grn_number,
                        'occurred_at' => $receipt->received_date?->startOfDay() ?? now(),
                    ],
                );
                if ($batch) {
                    $newBatchQuantity = $batchReceivedQuantity->plus($quantity);
                    $newBatchCost = $newBatchQuantity->isGreaterThan(0)
                        ? $batchReceivedQuantity->multipliedBy($batchCost)->plus($quantity->multipliedBy($unitCost))
                            ->dividedBy($newBatchQuantity, 6, RoundingMode::HALF_UP)
                        : $unitCost;
                    DB::table('inventory_item_batches')->where('id', $batch->id)->update([
                        'received_quantity' => $newBatchQuantity->toScale(6, RoundingMode::HALF_UP)->__toString(),
                        'unit_cost' => $newBatchCost->toScale(6, RoundingMode::HALF_UP)->__toString(),
                        'updated_at' => now(),
                    ]);
                }
            }

            return ['complete' => true, 'pending' => []];
        });
    }

    public function convertQuantity(int $restaurantId, string $quantity, int $fromUnitId, int $toUnitId): string
    {
        $amount = $this->decimal($quantity);
        $from = InventoryUnit::query()->where('restaurant_id', $restaurantId)->findOrFail($fromUnitId);
        $to = InventoryUnit::query()->where('restaurant_id', $restaurantId)->findOrFail($toUnitId);
        if ($from->id === $to->id) {
            return $amount->toScale(6, RoundingMode::HALF_UP)->__toString();
        }
        if ($from->dimension !== $to->dimension) {
            throw ValidationException::withMessages(['unit_id' => 'Units from different dimensions cannot be converted.']);
        }

        $conversion = DB::table('inventory_unit_conversions')
            ->where('restaurant_id', $restaurantId)
            ->where('from_unit_id', $from->id)
            ->where('to_unit_id', $to->id)
            ->first();
        if ($conversion) {
            return $amount->multipliedBy((string) $conversion->factor)
                ->toScale(6, RoundingMode::HALF_UP)->__toString();
        }

        $inverse = DB::table('inventory_unit_conversions')
            ->where('restaurant_id', $restaurantId)
            ->where('from_unit_id', $to->id)
            ->where('to_unit_id', $from->id)
            ->first();
        if ($inverse && BigDecimal::of((string) $inverse->factor)->isGreaterThan(0)) {
            return $amount->dividedBy((string) $inverse->factor, 6, RoundingMode::HALF_UP)->__toString();
        }

        throw ValidationException::withMessages(['unit_id' => 'Configure a conversion between these units before using them together.']);
    }

    public function receive(
        User $actor,
        InventoryItem $item,
        InventoryLocation $location,
        string $quantity,
        string $unitCost,
        string $movementType,
        string $idempotencyKey,
        array $options = [],
    ): InventoryMovement {
        $this->assertScope($actor, $item, $location);
        $amount = $this->positiveDecimal($quantity, 'quantity');
        $cost = $this->nonNegativeDecimal($unitCost, 'unit_cost');
        if (isset($options['unit_id'])) {
            $baseUnitsPerInputUnit = BigDecimal::of($this->convertQuantity(
                (int) $item->restaurant_id,
                '1',
                (int) $options['unit_id'],
                (int) $item->base_unit_id,
            ));
            $amount = BigDecimal::of($this->convertQuantity(
                (int) $item->restaurant_id,
                $amount,
                (int) $options['unit_id'],
                (int) $item->base_unit_id,
            ));
            $cost = $cost->dividedBy($baseUnitsPerInputUnit, 6, RoundingMode::HALF_UP);
        }
        if (($item->track_batches || $item->track_expiry) && ! isset($options['batch_id'])) {
            throw ValidationException::withMessages(['batch_number' => 'A tracked receipt must identify its batch.']);
        }

        return DB::transaction(function () use ($actor, $item, $location, $amount, $cost, $movementType, $idempotencyKey, $options): InventoryMovement {
            $this->lockRestaurant((int) $item->restaurant_id);
            $existing = InventoryMovement::query()
                ->where('restaurant_id', $item->restaurant_id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing) {
                return $existing;
            }

            return $this->recordMovement($actor, $item, $location, $amount, true, $cost, $movementType, $idempotencyKey, $options);
        });
    }

    public function issue(
        User $actor,
        InventoryItem $item,
        InventoryLocation $location,
        string $quantity,
        string $movementType,
        string $idempotencyKey,
        array $options = [],
    ): array {
        $this->assertScope($actor, $item, $location);
        $amount = $this->positiveDecimal($quantity, 'quantity');
        if (isset($options['unit_id'])) {
            $amount = BigDecimal::of($this->convertQuantity(
                (int) $item->restaurant_id,
                $amount,
                (int) $options['unit_id'],
                (int) $item->base_unit_id,
            ));
        }

        return DB::transaction(function () use ($actor, $item, $location, $amount, $movementType, $idempotencyKey, $options): array {
            $this->lockRestaurant((int) $item->restaurant_id);
            $existing = InventoryMovement::query()
                ->where('restaurant_id', $item->restaurant_id)
                ->where('idempotency_key', $idempotencyKey)
                ->orWhere(function ($query) use ($item, $idempotencyKey): void {
                    $query->where('restaurant_id', $item->restaurant_id)
                        ->where('idempotency_key', 'like', $idempotencyKey.':batch:%');
                })->get();
            if ($existing->isNotEmpty()) {
                return $existing->all();
            }

            $balance = $this->lockBalance($item, $location);
            if (BigDecimal::of((string) $balance->quantity)->compareTo($amount) < 0
                && ! $this->allowsNegativeStock((int) $item->restaurant_id)) {
                throw ValidationException::withMessages(['quantity' => 'There is not enough available stock for this movement.']);
            }

            if (isset($options['batch_id'])) {
                $batch = InventoryItemBatch::query()
                    ->where('restaurant_id', $item->restaurant_id)
                    ->where('inventory_item_id', $item->id)
                    ->where('location_id', $location->id)
                    ->lockForUpdate()->findOrFail((int) $options['batch_id']);
                $batches = collect([$batch]);
            } elseif ($item->track_batches || $item->track_expiry) {
                $batches = InventoryItemBatch::query()
                    ->where('restaurant_id', $item->restaurant_id)
                    ->where('inventory_item_id', $item->id)
                    ->where('location_id', $location->id)
                    ->where('available_quantity', '>', 0)
                    ->where(fn ($query) => $query->whereNull('expires_at')->orWhereDate('expires_at', '>=', today()))
                    ->orderByRaw('expires_at IS NULL')
                    ->orderBy('expires_at')->orderBy('id')->lockForUpdate()->get();
            } else {
                $batches = collect();
            }

            if ($batches->isEmpty()) {
                if ($item->track_batches || $item->track_expiry) {
                    throw ValidationException::withMessages(['quantity' => 'No eligible, unexpired batch is available for this item.']);
                }
                return [$this->recordMovement($actor, $item, $location, $amount, false, null, $movementType, $idempotencyKey, $options)];
            }

            $remaining = $amount;
            $movements = [];
            foreach ($batches as $batch) {
                $available = BigDecimal::of((string) $batch->available_quantity);
                if ($available->isLessThanOrEqualTo(0)) {
                    continue;
                }
                $take = $available->isLessThan($remaining) ? $available : $remaining;
                $movements[] = $this->recordMovement(
                    $actor,
                    $item,
                    $location,
                    $take,
                    false,
                    BigDecimal::of((string) $batch->unit_cost),
                    $movementType,
                    $idempotencyKey.':batch:'.$batch->id,
                    $options + ['batch_id' => $batch->id],
                );
                $remaining = $remaining->minus($take);
                if ($remaining->isZero()) {
                    break;
                }
            }
            if ($remaining->isGreaterThan(0)
                && (($item->track_batches || $item->track_expiry) || ! $this->allowsNegativeStock((int) $item->restaurant_id))) {
                throw ValidationException::withMessages(['quantity' => 'Available unexpired batch stock is insufficient.']);
            }
            if ($remaining->isGreaterThan(0)) {
                $movements[] = $this->recordMovement($actor, $item, $location, $remaining, false, null, $movementType, $idempotencyKey.':unbatched', $options);
            }

            return $movements;
        });
    }

    public function transfer(
        User $actor,
        InventoryItem $item,
        InventoryLocation $source,
        InventoryLocation $destination,
        string $quantity,
        string $idempotencyKey,
        ?string $occurredAt = null,
        ?string $notes = null,
    ): void {
        $this->assertScope($actor, $item, $source);
        $this->assertScope($actor, $item, $destination);
        if ($source->id === $destination->id) {
            throw ValidationException::withMessages(['destination_location_id' => 'Choose a different destination location.']);
        }
        $amount = $this->positiveDecimal($quantity, 'quantity');
        DB::transaction(function () use ($actor, $item, $source, $destination, $amount, $idempotencyKey, $occurredAt, $notes): void {
            $this->lockRestaurant((int) $item->restaurant_id);
            $table = DB::table('inventory_stock_transfers');
            $existing = $table->where('restaurant_id', $item->restaurant_id)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return;
            }
            $transferId = $table->insertGetId([
                'restaurant_id' => $item->restaurant_id,
                'inventory_item_id' => $item->id,
                'source_location_id' => $source->id,
                'destination_location_id' => $destination->id,
                'performed_by' => $actor->id,
                'transfer_number' => 'TMP-'.Str::random(20),
                'idempotency_key' => $idempotencyKey,
                'quantity' => $amount->toScale(6, RoundingMode::HALF_UP)->__toString(),
                'transferred_at' => $occurredAt ?? now(),
                'notes' => $notes,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $number = sprintf('TRF%06d', $transferId);
            $table->where('id', $transferId)->update(['transfer_number' => $number]);
            $balance = $this->lockBalance($item, $source);
            if ($item->track_batches || $item->track_expiry) {
                $batches = InventoryItemBatch::query()->where('restaurant_id', $item->restaurant_id)
                    ->where('inventory_item_id', $item->id)->where('location_id', $source->id)
                    ->where('available_quantity', '>', 0)
                    ->where(fn ($query) => $query->whereNull('expires_at')->orWhereDate('expires_at', '>=', today()))
                    ->orderByRaw('expires_at IS NULL')->orderBy('expires_at')->orderBy('id')->lockForUpdate()->get();
                $available = BigDecimal::zero();
                foreach ($batches as $batch) {
                    $available = $available->plus((string) $batch->available_quantity);
                }
                if ($available->isLessThan($amount)
                    && (($item->track_batches || $item->track_expiry) || ! $this->allowsNegativeStock((int) $item->restaurant_id))) {
                    throw ValidationException::withMessages(['quantity' => 'Available unexpired batch stock is insufficient.']);
                }
                $remaining = $amount;
                foreach ($batches as $batch) {
                    if ($remaining->isZero()) {
                        break;
                    }
                    $take = BigDecimal::min(BigDecimal::of((string) $batch->available_quantity), $remaining);
                    $destinationBatch = InventoryItemBatch::query()->firstOrCreate([
                        'restaurant_id' => $item->restaurant_id,
                        'inventory_item_id' => $item->id,
                        'location_id' => $destination->id,
                        'batch_number' => $batch->batch_number,
                    ], [
                        'vendor_id' => $batch->vendor_id,
                        'manufactured_at' => $batch->manufactured_at,
                        'expires_at' => $batch->expires_at,
                        'received_quantity' => '0',
                        'available_quantity' => '0',
                        'unit_cost' => $batch->unit_cost,
                    ]);
                    $this->issue($actor, $item, $source, $take->__toString(), 'transfer_out', $idempotencyKey.':out:'.$batch->id, [
                        'batch_id' => $batch->id, 'source_type' => 'transfer', 'source_id' => $transferId,
                        'reference' => $number, 'notes' => $notes,
                    ]);
                    $this->receive($actor, $item, $destination, $take->__toString(), (string) $batch->unit_cost, 'transfer_in', $idempotencyKey.':in:'.$batch->id, [
                        'batch_id' => $destinationBatch->id, 'source_type' => 'transfer', 'source_id' => $transferId,
                        'reference' => $number, 'notes' => $notes,
                    ]);
                    $destinationBatch->increment('received_quantity', $take->toScale(6, RoundingMode::HALF_UP)->__toString());
                    $remaining = $remaining->minus($take);
                }
                if ($remaining->isGreaterThan(0) && ($item->track_batches || $item->track_expiry)) {
                    throw ValidationException::withMessages(['quantity' => 'Tracked stock transfers require eligible batch quantities.']);
                }
                if ($remaining->isGreaterThan(0)) {
                    $this->issue($actor, $item, $source, $remaining->__toString(), 'transfer_out', $idempotencyKey.':out:unbatched', [
                        'source_type' => 'transfer', 'source_id' => $transferId, 'reference' => $number, 'notes' => $notes,
                    ]);
                    $this->receive($actor, $item, $destination, $remaining->__toString(), (string) $balance->average_unit_cost, 'transfer_in', $idempotencyKey.':in:unbatched', [
                        'source_type' => 'transfer', 'source_id' => $transferId, 'reference' => $number, 'notes' => $notes,
                    ]);
                }
            } else {
                $cost = BigDecimal::of((string) $balance->average_unit_cost);
                $this->issue($actor, $item, $source, $amount->__toString(), 'transfer_out', $idempotencyKey.':out', [
                    'source_type' => 'transfer', 'source_id' => $transferId, 'reference' => $number, 'notes' => $notes,
                ]);
                $this->receive($actor, $item, $destination, $amount->__toString(), $cost->__toString(), 'transfer_in', $idempotencyKey.':in', [
                    'source_type' => 'transfer', 'source_id' => $transferId, 'reference' => $number, 'notes' => $notes,
                ]);
            }
        });
    }

    public function reverseIssue(User $actor, InventoryMovement $movement, string $idempotencyKey): InventoryMovement
    {
        abort_unless(BigDecimal::of((string) $movement->quantity_out)->isGreaterThan(0), 409);
        abort_unless((int) $actor->restaurant_id === (int) $movement->restaurant_id, 404);
        return DB::transaction(function () use ($actor, $movement, $idempotencyKey): InventoryMovement {
            $existingReversal = InventoryMovement::query()
                ->where('restaurant_id', $movement->restaurant_id)
                ->where('reversal_of_id', $movement->id)->first();
            if ($existingReversal) {
                return $existingReversal;
            }
            return $this->receive($actor, $this->itemInRestaurant($actor, (int) $movement->inventory_item_id),
                InventoryLocation::query()->where('restaurant_id', $movement->restaurant_id)->findOrFail($movement->location_id),
                (string) $movement->quantity_out, (string) $movement->unit_cost, 'reversal', $idempotencyKey, [
                    'batch_id' => $movement->batch_id,
                    'source_type' => 'pos_reversal',
                    'source_id' => $movement->source_id,
                    'source_line_id' => $movement->source_line_id,
                    'reference' => $movement->reference,
                    'reason' => 'order_cancellation',
                    'reversal_of_id' => $movement->id,
                    'notes' => 'Compensating stock return for '.$movement->movement_number,
                ]);
        });
    }

    public function adjust(
        User $actor,
        InventoryItem $item,
        InventoryLocation $location,
        string $actualQuantity,
        string $reason,
        string $idempotencyKey,
        ?string $occurredAt = null,
        ?string $notes = null,
        string $numberPrefix = 'ADJ',
        array $batchDetails = [],
    ): void {
        $this->assertScope($actor, $item, $location);
        $actual = $this->nonNegativeDecimal($actualQuantity, 'actual_quantity');
        DB::transaction(function () use ($actor, $item, $location, $actual, $reason, $idempotencyKey, $occurredAt, $notes, $numberPrefix, $batchDetails): void {
            $this->lockRestaurant((int) $item->restaurant_id);
            $existing = DB::table('inventory_adjustments')
                ->where('restaurant_id', $item->restaurant_id)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return;
            }
            $balance = $this->lockBalance($item, $location);
            $expected = BigDecimal::of((string) $balance->quantity);
            $difference = $actual->minus($expected);
            if ($difference->isZero()) {
                throw ValidationException::withMessages(['actual_quantity' => 'The actual quantity matches the current stock; no adjustment is needed.']);
            }
            $adjustmentId = DB::table('inventory_adjustments')->insertGetId([
                'restaurant_id' => $item->restaurant_id,
                'inventory_item_id' => $item->id,
                'location_id' => $location->id,
                'performed_by' => $actor->id,
                'adjustment_number' => 'TMP-'.Str::random(20),
                'expected_quantity' => $expected->toScale(6, RoundingMode::HALF_UP)->__toString(),
                'actual_quantity' => $actual->toScale(6, RoundingMode::HALF_UP)->__toString(),
                'difference' => $difference->toScale(6, RoundingMode::HALF_UP)->__toString(),
                'reason' => $reason,
                'idempotency_key' => $idempotencyKey,
                'adjusted_at' => $occurredAt ?? now(),
                'notes' => $notes,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $number = sprintf($numberPrefix.'%06d', $adjustmentId);
            DB::table('inventory_adjustments')->where('id', $adjustmentId)->update(['adjustment_number' => $number]);
            $options = ['source_type' => 'adjustment', 'source_id' => $adjustmentId, 'reference' => $number, 'notes' => $notes];
            if ($difference->isGreaterThan(0)) {
                $batch = null;
                if ($item->track_batches || $item->track_expiry) {
                    $batch = $this->prepareAdjustmentBatch($item, $location, $difference, $batchDetails);
                    $options['batch_id'] = $batch->id;
                }
                $movement = $this->receive($actor, $item, $location, $difference->__toString(), (string) $item->standard_cost, 'adjustment_in', $idempotencyKey.':movement', $options);
                if ($batch) {
                    $newQuantity = $batch->received_quantity->plus($difference)->toScale(6, RoundingMode::HALF_UP);
                    $newCost = $newQuantity->isGreaterThan(0)
                        ? $batch->received_quantity->multipliedBy($batch->unit_cost)
                            ->plus($difference->multipliedBy((string) $item->standard_cost))
                            ->dividedBy($newQuantity, 6, RoundingMode::HALF_UP)
                        : BigDecimal::of((string) $item->standard_cost);
                    DB::table('inventory_item_batches')->where('id', $batch->id)->update([
                        'received_quantity' => $newQuantity->__toString(),
                        'unit_cost' => $newCost->toScale(6, RoundingMode::HALF_UP)->__toString(),
                        'updated_at' => now(),
                    ]);
                }
            } else {
                $movement = $this->issue($actor, $item, $location, $difference->abs()->__toString(), 'adjustment_out', $idempotencyKey.':movement', $options)[0];
            }
            DB::table('inventory_adjustments')->where('id', $adjustmentId)->update(['movement_id' => $movement->id]);
        });
    }

    private function prepareAdjustmentBatch(
        InventoryItem $item,
        InventoryLocation $location,
        BigDecimal $quantity,
        array $details,
    ): object {
        $batchNumber = trim((string) ($details['batch_number'] ?? ''));
        if ($batchNumber === '') {
            throw ValidationException::withMessages(['batch_number' => 'A batch number is required for positive adjustments to tracked items.']);
        }
        if ($item->track_expiry && blank($details['expires_at'] ?? null)) {
            throw ValidationException::withMessages(['expires_at' => 'An expiry date is required for this tracked item.']);
        }
        $manufacturedAt = $details['manufactured_at'] ?? null;
        $expiresAt = $details['expires_at'] ?? null;
        if ($manufacturedAt && $expiresAt && $expiresAt < $manufacturedAt) {
            throw ValidationException::withMessages(['expires_at' => 'The expiry date must not be before the manufacturing date.']);
        }
        $batch = DB::table('inventory_item_batches')
            ->where('restaurant_id', $item->restaurant_id)
            ->where('inventory_item_id', $item->id)
            ->where('location_id', $location->id)
            ->where('batch_number', $batchNumber)
            ->lockForUpdate()->first();
        if ($batch) {
            if ($batch->manufactured_at !== $manufacturedAt || $batch->expires_at !== $expiresAt) {
                throw ValidationException::withMessages(['batch_number' => 'An existing batch number cannot be reused with different dates.']);
            }

            return (object) [
                'id' => (int) $batch->id,
                'received_quantity' => BigDecimal::of((string) $batch->received_quantity),
                'unit_cost' => BigDecimal::of((string) $batch->unit_cost),
            ];
        }

        $id = DB::table('inventory_item_batches')->insertGetId([
            'restaurant_id' => $item->restaurant_id,
            'inventory_item_id' => $item->id,
            'location_id' => $location->id,
            'batch_number' => $batchNumber,
            'manufactured_at' => $manufacturedAt,
            'expires_at' => $expiresAt,
            'received_quantity' => '0',
            'available_quantity' => '0',
            'unit_cost' => $item->standard_cost,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (object) [
            'id' => $id,
            'received_quantity' => BigDecimal::zero(),
            'unit_cost' => BigDecimal::of((string) $item->standard_cost),
        ];
    }

    private function recordMovement(
        User $actor,
        InventoryItem $item,
        InventoryLocation $location,
        BigDecimal $quantity,
        bool $incoming,
        ?BigDecimal $unitCost,
        string $movementType,
        string $idempotencyKey,
        array $options,
    ): InventoryMovement {
        $existing = InventoryMovement::query()
            ->where('restaurant_id', $item->restaurant_id)->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing;
        }
        $balance = $this->lockBalance($item, $location);
        $oldQuantity = BigDecimal::of((string) $balance->quantity);
        $oldCost = BigDecimal::of((string) $balance->average_unit_cost);
        $quantity = $quantity->toScale(6, RoundingMode::HALF_UP);
        $quantityDelta = $incoming ? $quantity : $quantity->negated();
        $newQuantity = $oldQuantity->plus($quantityDelta);
        if ($newQuantity->isLessThan(0) && ! $this->allowsNegativeStock((int) $item->restaurant_id)) {
            throw ValidationException::withMessages(['quantity' => 'This movement would make stock negative.']);
        }
        $unitCost ??= $oldCost->isGreaterThan(0) ? $oldCost : BigDecimal::of((string) $item->standard_cost);
        $value = $quantity->multipliedBy($unitCost)->toScale(6, RoundingMode::HALF_UP);
        $newAverage = $oldCost;
        if ($incoming && $newQuantity->isGreaterThan(0)) {
            $newAverage = $oldQuantity->multipliedBy($oldCost)->plus($value)
                ->dividedBy($newQuantity, 6, RoundingMode::HALF_UP);
        } elseif ($newQuantity->isZero()) {
            $newAverage = BigDecimal::zero();
        }

        $movementId = DB::table('inventory_movements')->insertGetId([
            'restaurant_id' => $item->restaurant_id,
            'inventory_item_id' => $item->id,
            'location_id' => $location->id,
            'batch_id' => $options['batch_id'] ?? null,
            'performed_by' => $actor->id,
            'reversal_of_id' => $options['reversal_of_id'] ?? null,
            'movement_number' => 'TMP-'.Str::random(20),
            'movement_type' => $movementType,
            'reason' => $options['reason'] ?? null,
            'quantity_in' => $incoming ? $quantity->__toString() : '0',
            'quantity_out' => $incoming ? '0' : $quantity->__toString(),
            'balance_after' => $newQuantity->toScale(6, RoundingMode::HALF_UP)->__toString(),
            'unit_cost' => $unitCost->toScale(6, RoundingMode::HALF_UP)->__toString(),
            'value' => ($incoming ? $value : $value->negated())->toScale(6, RoundingMode::HALF_UP)->__toString(),
            'source_type' => $options['source_type'] ?? null,
            'source_id' => $options['source_id'] ?? null,
            'source_line_id' => $options['source_line_id'] ?? null,
            'idempotency_key' => $idempotencyKey,
            'reference' => $options['reference'] ?? null,
            'occurred_at' => $options['occurred_at'] ?? now(),
            'notes' => $options['notes'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $prefix = $incoming ? 'STIN' : 'STOUT';
        DB::table('inventory_movements')->where('id', $movementId)
            ->update(['movement_number' => sprintf($prefix.'%06d', $movementId)]);
        $balance->forceFill([
            'quantity' => $newQuantity->toScale(6, RoundingMode::HALF_UP)->__toString(),
            'average_unit_cost' => $newAverage->toScale(6, RoundingMode::HALF_UP)->__toString(),
        ])->save();

        if (isset($options['batch_id'])) {
            $batch = InventoryItemBatch::query()->where('restaurant_id', $item->restaurant_id)
                ->lockForUpdate()->findOrFail((int) $options['batch_id']);
            $batchQuantity = BigDecimal::of((string) $batch->available_quantity);
            $batchQuantity = $incoming ? $batchQuantity->plus($quantity) : $batchQuantity->minus($quantity);
            if ($batchQuantity->isLessThan(0)) {
                throw ValidationException::withMessages(['batch_id' => 'The selected batch does not have enough stock.']);
            }
            $batch->update(['available_quantity' => $batchQuantity->toScale(6, RoundingMode::HALF_UP)->__toString()]);
        }

        $movement = InventoryMovement::query()->findOrFail($movementId);
        $this->audit($actor, (int) $item->restaurant_id, 'inventory_movement_posted', 'inventory_movement', $movementId, [
            'movement_number' => $movement->movement_number,
            'movement_type' => $movementType,
            'inventory_item_id' => $item->id,
            'location_id' => $location->id,
            'quantity' => $quantity->toScale(6, RoundingMode::HALF_UP)->__toString(),
        ]);

        return $movement;
    }

    private function lockBalance(InventoryItem $item, InventoryLocation $location): InventoryStockBalance
    {
        InventoryStockBalance::query()->firstOrCreate(
            ['restaurant_id' => $item->restaurant_id, 'inventory_item_id' => $item->id, 'location_id' => $location->id],
            ['quantity' => '0', 'average_unit_cost' => '0'],
        );

        return InventoryStockBalance::query()
            ->where('restaurant_id', $item->restaurant_id)
            ->where('inventory_item_id', $item->id)
            ->where('location_id', $location->id)
            ->lockForUpdate()->firstOrFail();
    }

    private function lockRestaurant(int $restaurantId): void
    {
        $restaurant = DB::table('restaurants')->where('id', $restaurantId)->lockForUpdate()->first();
        abort_unless($restaurant !== null, 404);
    }

    private function itemInRestaurant(User $actor, int $itemId): InventoryItem
    {
        return InventoryItem::query()->where('restaurant_id', $actor->restaurant_id)->findOrFail($itemId);
    }

    private function allowsNegativeStock(int $restaurantId): bool
    {
        return (bool) DB::table('inventory_settings')->where('restaurant_id', $restaurantId)->value('allow_negative_stock');
    }

    private function assertScope(User $actor, InventoryItem $item, InventoryLocation $location): void
    {
        abort_unless((int) $actor->restaurant_id === (int) $item->restaurant_id, 404);
        abort_unless((int) $actor->restaurant_id === (int) $location->restaurant_id, 404);
    }

    private function decimal(string $value): BigDecimal
    {
        try {
            $decimal = BigDecimal::of($value);
        } catch (NumberFormatException) {
            throw ValidationException::withMessages(['quantity' => 'Enter a valid decimal quantity.']);
        }
        if ($decimal->getScale() > 6) {
            throw ValidationException::withMessages(['quantity' => 'Use no more than six decimal places.']);
        }

        return $decimal;
    }

    private function positiveDecimal(string $value, string $field): BigDecimal
    {
        $decimal = $this->decimal($value);
        if ($decimal->isLessThanOrEqualTo(0)) {
            throw ValidationException::withMessages([$field => 'Enter a value greater than zero.']);
        }

        return $decimal;
    }

    private function nonNegativeDecimal(string $value, string $field): BigDecimal
    {
        $decimal = $this->decimal($value);
        if ($decimal->isLessThan(0)) {
            throw ValidationException::withMessages([$field => 'Enter zero or a positive value.']);
        }

        return $decimal;
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
