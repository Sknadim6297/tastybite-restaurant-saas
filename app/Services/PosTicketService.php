<?php

namespace App\Services;

use App\Models\RestaurantAuditLog;
use App\Models\RestaurantPosKot;
use App\Models\RestaurantPosOrder;
use App\Models\RestaurantPosOrderItem;
use App\Models\SuperAdmin;
use App\Models\User;
use Illuminate\Support\Str;

class PosTicketService
{
    public function issue(User $actor, RestaurantPosOrder $order, bool $refreshItems): RestaurantPosKot
    {
        $order->loadMissing('restaurant');
        $order->load('items');

        $existing = RestaurantPosKot::query()
            ->where('restaurant_id', $order->restaurant_id)
            ->where('pos_order_id', $order->id)
            ->lockForUpdate()
            ->first();

        if (! $existing) {
            $kot = RestaurantPosKot::create([
                'restaurant_id' => $order->restaurant_id,
                'pos_order_id' => $order->id,
                'kot_number' => 'TMP-'.Str::upper(Str::random(12)),
                'restaurant_name' => (string) $order->restaurant?->name,
                'order_number' => $order->order_number,
                'items' => $this->snapshot($order),
                'order_type' => $order->order_type ?: 'dine_in',
                'table_number' => $order->table_number,
                'reprint_count' => 0,
                'printed_at' => now(),
                'last_printed_at' => now(),
            ]);
            $kot->update(['kot_number' => sprintf('KOT%06d', $kot->id)]);
            $this->audit($actor, 'pos_kot_printed', $order, ['kot_number' => $kot->kot_number]);

            return $kot->refresh();
        }

        if ($refreshItems && $order->status === 'draft') {
            $existing->items = $this->snapshot($order);
            $existing->order_type = $order->order_type ?: $existing->order_type;
            $existing->table_number = $order->table_number;
        }

        $existing->reprint_count = (int) $existing->reprint_count + 1;
        $existing->last_printed_at = now();
        $existing->save();
        $this->audit($actor, 'pos_kot_reprinted', $order, [
            'kot_number' => $existing->kot_number,
            'reprint_count' => $existing->reprint_count,
        ]);

        return $existing->refresh();
    }

    public function refreshSnapshot(RestaurantPosOrder $order): void
    {
        $kot = RestaurantPosKot::query()
            ->where('restaurant_id', $order->restaurant_id)
            ->where('pos_order_id', $order->id)
            ->lockForUpdate()
            ->first();

        if (! $kot) {
            return;
        }

        $order->load('items');
        $kot->update([
            'items' => $this->snapshot($order),
            'order_type' => $order->order_type ?: $kot->order_type,
            'table_number' => $order->table_number,
        ]);
    }

    private function snapshot(RestaurantPosOrder $order): array
    {
        return $order->items->map(fn (RestaurantPosOrderItem $item): array => [
            'name' => $item->item_name,
            'quantity' => (int) $item->quantity,
            'instructions' => $item->special_instructions ?: null,
        ])->values()->all();
    }

    private function audit(User $actor, string $action, RestaurantPosOrder $order, array $details): void
    {
        $admin = request()->attributes->get('restaurant_admin_access_admin');
        $superAdmin = $admin instanceof SuperAdmin ? $admin : null;

        RestaurantAuditLog::create([
            'actor_id' => $superAdmin?->id,
            'actor_type' => $superAdmin ? 'super_admin' : 'restaurant_owner',
            'restaurant_id' => (int) $actor->restaurant_id,
            'action' => $action,
            'target_type' => 'pos_order',
            'target_id' => $order->id,
            'details' => $details + ($superAdmin ? ['admin_id' => $superAdmin->id] : ['owner_id' => $actor->id]),
        ]);
    }
}
