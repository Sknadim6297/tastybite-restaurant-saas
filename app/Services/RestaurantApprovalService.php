<?php

namespace App\Services;

use App\Models\Restaurant;
use App\Models\RestaurantApprovalHistory;
use App\Models\RestaurantAuditLog;
use App\Models\SuperAdmin;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RestaurantApprovalService
{
    private const TRANSITIONS = [
        'pending' => ['approved', 'rejected', 'information_requested'],
        'information_requested' => ['approved', 'rejected', 'pending'],
        'approved' => [],
        'rejected' => [],
    ];

    public function recordInitialPending(Restaurant $restaurant, SuperAdmin $actor): void
    {
        RestaurantApprovalHistory::create([
            'restaurant_id' => $restaurant->id,
            'actor_id' => $actor->id,
            'previous_status' => null,
            'new_status' => 'pending',
            'reason' => 'Queued for review during Super Admin provisioning.',
            'created_at' => now(),
        ]);
    }

    public function decide(Restaurant $restaurant, SuperAdmin $actor, string $decision, ?string $reason): Restaurant
    {
        return DB::transaction(function () use ($restaurant, $actor, $decision, $reason): Restaurant {
            $restaurant = Restaurant::query()->lockForUpdate()->findOrFail($restaurant->id);
            $previous = $restaurant->approval_status;

            if (! in_array($decision, self::TRANSITIONS[$previous] ?? [], true)) {
                throw ValidationException::withMessages([
                    'decision' => 'This approval decision is not valid for the current restaurant status.',
                ]);
            }

            $restaurant->update(['approval_status' => $decision]);
            RestaurantApprovalHistory::create([
                'restaurant_id' => $restaurant->id,
                'actor_id' => $actor->id,
                'previous_status' => $previous,
                'new_status' => $decision,
                'reason' => $reason,
                'created_at' => now(),
            ]);
            RestaurantAuditLog::create([
                'actor_id' => $actor->id,
                'actor_type' => 'super_admin',
                'action' => 'restaurant_approval_'.$decision,
                'target_type' => 'restaurant',
                'target_id' => $restaurant->id,
                'details' => [
                    'previous_status' => $previous,
                    'new_status' => $decision,
                    'reason' => $reason,
                ],
            ]);

            return $restaurant;
        });
    }
}
