<?php

namespace App\Services;

use App\Models\RestaurantAuditLog;
use App\Models\SuperAdmin;
use App\Models\SupportAccessRequest;
use App\Models\SupportAccessRequestEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupportAccessRequestService
{
    public function create(SuperAdmin $requester, array $data): SupportAccessRequest
    {
        return DB::transaction(function () use ($requester, $data): SupportAccessRequest {
            $request = SupportAccessRequest::create([
                'restaurant_id' => $data['restaurant_id'],
                'requester_admin_id' => $requester->id,
                'requested_scope' => $data['requested_scope'],
                'reason' => $data['reason'],
                'requested_duration_minutes' => $data['requested_duration_minutes'],
                'status' => 'pending',
            ]);
            $this->event($request, $requester, 'requested', null, 'pending', $data['reason']);
            $this->audit($requester, $request, 'support_access_requested', [
                'restaurant_id' => $request->restaurant_id,
                'requested_scope' => $request->requested_scope,
                'requested_duration_minutes' => $request->requested_duration_minutes,
            ]);

            return $request;
        });
    }

    public function decide(SupportAccessRequest $request, SuperAdmin $reviewer, string $decision, string $reason): SupportAccessRequest
    {
        return DB::transaction(function () use ($request, $reviewer, $decision, $reason): SupportAccessRequest {
            $request = SupportAccessRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($request->status !== 'pending' || $request->requester_admin_id === $reviewer->id) {
                throw ValidationException::withMessages([
                    'decision' => 'Only another Super Admin may decide a pending support access request.',
                ]);
            }
            if (! in_array($decision, ['approved', 'denied'], true)) {
                throw ValidationException::withMessages(['decision' => 'The requested support access decision is invalid.']);
            }

            $previous = $request->status;
            $request->update([
                'reviewer_admin_id' => $reviewer->id,
                'status' => $decision,
                'review_reason' => $reason,
                'reviewed_at' => now(),
            ]);
            $this->event($request, $reviewer, $decision, $previous, $decision, $reason);
            $this->audit($reviewer, $request, 'support_access_'.$decision, [
                'restaurant_id' => $request->restaurant_id,
                'requested_scope' => $request->requested_scope,
                'reason' => $reason,
            ]);

            return $request;
        });
    }

    public function cancel(SupportAccessRequest $request, SuperAdmin $actor, ?string $reason = null): SupportAccessRequest
    {
        return DB::transaction(function () use ($request, $actor, $reason): SupportAccessRequest {
            $request = SupportAccessRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($request->status !== 'pending' || $request->requester_admin_id !== $actor->id) {
                throw ValidationException::withMessages([
                    'request' => 'Only the requester may cancel their own pending request.',
                ]);
            }

            $request->update(['status' => 'cancelled']);
            $this->event($request, $actor, 'cancelled', 'pending', 'cancelled', $reason);
            $this->audit($actor, $request, 'support_access_cancelled', [
                'restaurant_id' => $request->restaurant_id,
                'requested_scope' => $request->requested_scope,
                'reason' => $reason,
            ]);

            return $request;
        });
    }

    private function event(
        SupportAccessRequest $request,
        SuperAdmin $actor,
        string $action,
        ?string $previous,
        string $new,
        ?string $reason,
    ): void {
        SupportAccessRequestEvent::create([
            'support_access_request_id' => $request->id,
            'actor_admin_id' => $actor->id,
            'action' => $action,
            'previous_status' => $previous,
            'new_status' => $new,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }

    private function audit(SuperAdmin $actor, SupportAccessRequest $request, string $action, array $details): void
    {
        RestaurantAuditLog::create([
            'actor_id' => $actor->id,
            'actor_type' => 'super_admin',
            'action' => $action,
            'target_type' => 'support_access_request',
            'target_id' => $request->id,
            'details' => $details,
        ]);
    }
}
