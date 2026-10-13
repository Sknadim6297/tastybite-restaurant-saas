<?php

namespace App\Services;

use App\Models\PlatformAnnouncement;
use App\Models\Restaurant;
use App\Models\RestaurantAuditLog;
use App\Models\SuperAdmin;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlatformAnnouncementService
{
    public function createDraft(SuperAdmin $actor, array $data, array $restaurantIds): PlatformAnnouncement
    {
        return DB::transaction(function () use ($actor, $data, $restaurantIds): PlatformAnnouncement {
            $announcement = PlatformAnnouncement::create([
                'created_by' => $actor->id,
                'title' => $data['title'],
                'body' => $data['body'],
                'audience' => $data['audience'],
                'status' => 'draft',
                'expires_at' => $data['expires_at'] ?? null,
            ]);
            $announcement->restaurants()->sync($data['audience'] === 'selected' ? $restaurantIds : []);
            $this->audit($actor, $announcement, 'announcement_draft_created', [
                'title' => $announcement->title,
                'audience' => $announcement->audience,
            ]);

            return $announcement;
        });
    }

    public function updateDraft(PlatformAnnouncement $announcement, SuperAdmin $actor, array $data, array $restaurantIds): PlatformAnnouncement
    {
        return DB::transaction(function () use ($announcement, $actor, $data, $restaurantIds): PlatformAnnouncement {
            $announcement = PlatformAnnouncement::query()->lockForUpdate()->findOrFail($announcement->id);
            if ($announcement->status !== 'draft') {
                throw ValidationException::withMessages(['status' => 'Only draft announcements can be edited.']);
            }

            $announcement->update([
                'title' => $data['title'],
                'body' => $data['body'],
                'audience' => $data['audience'],
                'expires_at' => $data['expires_at'] ?? null,
            ]);
            $announcement->restaurants()->sync($data['audience'] === 'selected' ? $restaurantIds : []);
            $this->audit($actor, $announcement, 'announcement_draft_updated', [
                'title' => $announcement->title,
                'audience' => $announcement->audience,
            ]);

            return $announcement;
        });
    }

    public function publish(PlatformAnnouncement $announcement, SuperAdmin $actor): PlatformAnnouncement
    {
        return $this->transition($announcement, $actor, 'published');
    }

    public function archive(PlatformAnnouncement $announcement, SuperAdmin $actor): PlatformAnnouncement
    {
        return $this->transition($announcement, $actor, 'archived');
    }

    private function transition(PlatformAnnouncement $announcement, SuperAdmin $actor, string $status): PlatformAnnouncement
    {
        return DB::transaction(function () use ($announcement, $actor, $status): PlatformAnnouncement {
            $announcement = PlatformAnnouncement::query()->lockForUpdate()->findOrFail($announcement->id);
            $allowed = match ($status) {
                'published' => $announcement->status === 'draft',
                'archived' => in_array($announcement->status, ['draft', 'published'], true),
                default => false,
            };
            if (! $allowed) {
                throw ValidationException::withMessages(['status' => 'This announcement status transition is not allowed.']);
            }
            if ($status === 'published') {
                $eligibleRestaurants = $announcement->audience === 'all'
                    ? Restaurant::query()
                    : $announcement->restaurants();
                $hasRecipients = $eligibleRestaurants
                    ->where('restaurants.status', 'active')
                    ->whereHas('owners', fn ($owners) => $owners->where('status', 'active'))
                    ->exists();
                if (! $hasRecipients) {
                    throw ValidationException::withMessages([
                        'status' => 'This audience has no active restaurant owners to receive the announcement.',
                    ]);
                }
            }

            $previous = $announcement->status;
            $announcement->update([
                'status' => $status,
                'published_at' => $status === 'published' ? now() : $announcement->published_at,
            ]);
            $this->audit($actor, $announcement, 'announcement_'.$status, [
                'previous_status' => $previous,
                'new_status' => $status,
                'title' => $announcement->title,
            ]);

            return $announcement;
        });
    }

    private function audit(SuperAdmin $actor, PlatformAnnouncement $announcement, string $action, array $details): void
    {
        RestaurantAuditLog::create([
            'actor_id' => $actor->id,
            'actor_type' => 'super_admin',
            'action' => $action,
            'target_type' => 'announcement',
            'target_id' => $announcement->id,
            'details' => $details,
        ]);
    }
}
