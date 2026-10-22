<?php

namespace App\Services;

use App\Models\RestaurantSupportTicketNotification;
use App\Models\SuperAdmin;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\SupportTicketStatusHistory;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class SupportTicketService
{
    private const STATUS_TRANSITIONS = [
        'open' => ['in_progress', 'waiting_for_restaurant', 'resolved', 'closed'],
        'in_progress' => ['waiting_for_restaurant', 'resolved', 'closed'],
        'waiting_for_restaurant' => ['in_progress', 'resolved', 'closed'],
        'resolved' => ['in_progress', 'closed'],
        'closed' => [],
    ];

    public function __construct(private readonly SuperAdminNotificationService $adminNotifications) {}

    /**
     * @param  array{subject: string, category: string, body: string}  $data
     */
    public function create(User $owner, array $data, ?UploadedFile $attachment = null): SupportTicket
    {
        if (! $owner->isOwner() || ! $owner->restaurant) {
            abort(403, 'Only an active restaurant owner may create support tickets.');
        }

        return $this->withAttachmentCleanup($attachment, function (?string &$path) use ($owner, $data, $attachment): SupportTicket {
            $ticket = DB::transaction(function () use ($owner, $data, $attachment, &$path): SupportTicket {
                $ticket = SupportTicket::create([
                    'ticket_number' => 'TB-'.Str::upper((string) Str::ulid()),
                    'restaurant_id' => $owner->restaurant_id,
                    'created_by_user_id' => $owner->id,
                    'subject' => $data['subject'],
                    'category' => $data['category'],
                    'priority' => 'normal',
                    'status' => 'open',
                    'last_activity_at' => now(),
                ]);

                $message = $ticket->messages()->create([
                    'author_user_id' => $owner->id,
                    'visibility' => 'public',
                    'body' => $data['body'],
                ]);
                $this->attach($message, $attachment, $path);
                $this->recordStatus($ticket, null, 'open', $owner, null, 'Ticket created.');

                return $ticket;
            });

            $this->adminNotifications->businessEvent(
                'support_ticket_created',
                'support-ticket-created:'.$ticket->id,
                'New support ticket',
                $ticket->restaurant->name.' opened ticket '.$ticket->ticket_number.'.',
                route('super-admin.support-tickets.show', $ticket),
            );

            return $ticket;
        });
    }

    public function replyAsOwner(
        SupportTicket $ticket,
        User $owner,
        string $body,
        ?UploadedFile $attachment = null,
    ): SupportTicket {
        if (! $owner->isOwner() || $ticket->restaurant_id !== $owner->restaurant_id) {
            abort(404);
        }

        return $this->withAttachmentCleanup($attachment, function (?string &$path) use ($ticket, $owner, $body, $attachment): SupportTicket {
            return DB::transaction(function () use ($ticket, $owner, $body, $attachment, &$path): SupportTicket {
                $ticket = SupportTicket::query()->lockForUpdate()->findOrFail($ticket->id);
                if ($ticket->status === 'closed') {
                    throw ValidationException::withMessages(['body' => 'This ticket is closed. Open a new ticket for further assistance.']);
                }

                $message = $ticket->messages()->create([
                    'author_user_id' => $owner->id,
                    'visibility' => 'public',
                    'body' => $body,
                ]);
                $this->attach($message, $attachment, $path);
                $this->touch($ticket);

                if (in_array($ticket->status, ['waiting_for_restaurant', 'resolved'], true)) {
                    $this->changeStatus($ticket, 'in_progress', $owner, null, 'Restaurant owner replied.');
                }

                $this->adminNotifications->businessEvent(
                    'support_ticket_reply',
                    'support-ticket-owner-reply:'.$message->id,
                    'Restaurant replied to a support ticket',
                    $ticket->restaurant->name.' replied to '.$ticket->ticket_number.'.',
                    route('super-admin.support-tickets.show', $ticket),
                );

                return $ticket;
            });
        });
    }

    public function replyAsAdmin(
        SupportTicket $ticket,
        SuperAdmin $admin,
        string $body,
        string $visibility,
        ?UploadedFile $attachment = null,
    ): SupportTicketMessage {
        return $this->withAttachmentCleanup($attachment, function (?string &$path) use ($ticket, $admin, $body, $visibility, $attachment): SupportTicketMessage {
            return DB::transaction(function () use ($ticket, $admin, $body, $visibility, $attachment, &$path): SupportTicketMessage {
                $ticket = SupportTicket::query()->lockForUpdate()->findOrFail($ticket->id);
                if ($ticket->status === 'closed') {
                    throw ValidationException::withMessages(['body' => 'Closed tickets cannot receive new messages.']);
                }

                $message = $ticket->messages()->create([
                    'author_super_admin_id' => $admin->id,
                    'visibility' => $visibility,
                    'body' => $body,
                ]);
                $this->attach($message, $attachment, $path);
                $this->touch($ticket);

                if ($visibility === 'public') {
                    if ($ticket->status === 'resolved') {
                        $this->changeStatus($ticket, 'in_progress', null, $admin, 'Administrator replied to a resolved ticket.');
                    }
                    $this->changeStatus($ticket, 'waiting_for_restaurant', null, $admin, 'Administrator sent a public reply.');
                    $this->notifyOwners($ticket, 'support-ticket-admin-reply:'.$message->id, 'Support replied', 'A support administrator replied to ticket '.$ticket->ticket_number.'.');
                }

                return $message;
            });
        });
    }

    public function updateTicket(
        SupportTicket $ticket,
        SuperAdmin $admin,
        string $status,
        string $priority,
        ?int $assignedAdminId,
        ?string $reason,
    ): void {
        DB::transaction(function () use ($ticket, $admin, $status, $priority, $assignedAdminId, $reason): void {
            $ticket = SupportTicket::query()->lockForUpdate()->findOrFail($ticket->id);
            $before = ['status' => $ticket->status, 'priority' => $ticket->priority, 'assigned_admin_id' => $ticket->assigned_admin_id];

            if ($status !== $ticket->status) {
                $this->changeStatus($ticket, $status, null, $admin, $reason);
            }

            $ticket->update([
                'priority' => $priority,
                'assigned_admin_id' => $assignedAdminId,
            ]);
            $ticket->refresh();

            if ($before['priority'] !== $ticket->priority || $before['assigned_admin_id'] !== $ticket->assigned_admin_id) {
                $this->adminNotifications->businessEvent(
                    'support_ticket_updated',
                    'support-ticket-updated:'.$ticket->id.':'.hash('sha256', json_encode([$ticket->status, $ticket->priority, $ticket->assigned_admin_id])),
                    'Support ticket updated',
                    'Ticket '.$ticket->ticket_number.' assignment or priority was updated.',
                    route('super-admin.support-tickets.show', $ticket),
                );
            }

            if ($before['status'] !== $ticket->status) {
                $this->notifyOwners($ticket, 'support-ticket-status:'.$ticket->id.':'.$ticket->status.':'.now()->timestamp, 'Ticket status updated', 'Ticket '.$ticket->ticket_number.' is now '.\App\Support\StatusPresenter::label($ticket->status).'.');
            }
        });
    }

    public function markNotificationRead(RestaurantSupportTicketNotification $notification, User $owner): void
    {
        abort_unless($notification->user_id === $owner->id, 404);
        $notification->forceFill(['read_at' => now()])->save();
    }

    private function changeStatus(
        SupportTicket $ticket,
        string $status,
        ?User $user,
        ?SuperAdmin $admin,
        ?string $reason,
    ): void {
        if ($ticket->status === $status) {
            return;
        }

        if (! in_array($status, self::STATUS_TRANSITIONS[$ticket->status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => 'This status transition is not allowed.']);
        }

        $previous = $ticket->status;
        $ticket->update(['status' => $status, 'last_activity_at' => now()]);
        $ticket->statusHistory()->create([
            'actor_user_id' => $user?->id,
            'actor_super_admin_id' => $admin?->id,
            'previous_status' => $previous,
            'new_status' => $status,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }

    private function recordStatus(
        SupportTicket $ticket,
        ?string $previous,
        string $new,
        ?User $user,
        ?SuperAdmin $admin,
        ?string $reason,
    ): void {
        $ticket->statusHistory()->create([
            'actor_user_id' => $user?->id,
            'actor_super_admin_id' => $admin?->id,
            'previous_status' => $previous,
            'new_status' => $new,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }

    private function touch(SupportTicket $ticket): void
    {
        $ticket->update(['last_activity_at' => now()]);
    }

    private function notifyOwners(SupportTicket $ticket, string $eventKey, string $title, string $description): void
    {
        $owners = $ticket->restaurant->owners()->where('status', 'active')->get();
        foreach ($owners as $owner) {
            RestaurantSupportTicketNotification::query()->firstOrCreate(
                ['user_id' => $owner->id, 'event_key' => $eventKey],
                [
                    'support_ticket_id' => $ticket->id,
                    'title' => $title,
                    'description' => $description,
                ],
            );
        }
    }

    private function attach(SupportTicketMessage $message, ?UploadedFile $attachment, ?string &$path): void
    {
        if (! $attachment) {
            return;
        }

        $path = $attachment->store('support-tickets/'.$message->support_ticket_id, 'local');
        $message->update([
            'attachment_path' => $path,
            'attachment_name' => Str::of(basename($attachment->getClientOriginalName()))
                ->replaceMatches('/[^\w.\- ]/u', '_')
                ->limit(255, '')
                ->toString(),
            'attachment_mime' => $attachment->getMimeType(),
        ]);
    }

    private function withAttachmentCleanup(?UploadedFile $attachment, callable $callback): mixed
    {
        $path = null;
        try {
            return $callback($path);
        } catch (Throwable $exception) {
            if ($path !== null) {
                Storage::disk('local')->delete($path);
            }

            throw $exception;
        }
    }
}
