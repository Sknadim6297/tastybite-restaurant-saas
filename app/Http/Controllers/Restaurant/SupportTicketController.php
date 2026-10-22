<?php

namespace App\Http\Controllers\Restaurant;

use App\Http\Controllers\Controller;
use App\Models\RestaurantSupportTicketNotification;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use App\Services\RestaurantAccessContext;
use App\Services\SupportTicketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SupportTicketController extends Controller
{
    public function index(): View
    {
        $owner = $this->owner();

        return view('restaurant.support-tickets.index', [
            'user' => $owner,
            'restaurant' => $owner->restaurant,
            'tickets' => SupportTicket::query()
                ->where('restaurant_id', $owner->restaurant_id)
                ->with('assignedAdmin')
                ->orderByDesc('last_activity_at')
                ->paginate(15),
            'notifications' => RestaurantSupportTicketNotification::query()
                ->where('user_id', $owner->id)
                ->latest()
                ->limit(10)
                ->get(),
            'unreadCount' => RestaurantSupportTicketNotification::query()
                ->where('user_id', $owner->id)
                ->whereNull('read_at')
                ->count(),
        ]);
    }

    public function create(): View
    {
        $owner = $this->owner();

        return view('restaurant.support-tickets.create', [
            'user' => $owner,
            'restaurant' => $owner->restaurant,
        ]);
    }

    public function store(Request $request, SupportTicketService $service): RedirectResponse
    {
        $owner = $this->owner();
        $validated = $request->validate([
            'subject' => ['required', 'string', 'min:4', 'max:180'],
            'category' => ['required', Rule::in(['billing', 'technical', 'account', 'feature_request', 'other'])],
            'body' => ['required', 'string', 'min:10', 'max:10000'],
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ]);

        $ticket = $service->create(
            $owner,
            $validated,
            $request->file('attachment'),
        );

        return redirect()->route('restaurant.support.show', $ticket)
            ->with('status', 'Your support ticket was submitted.');
    }

    public function show(SupportTicket $ticket): View
    {
        $owner = $this->owner();
        $ticket = $this->tenantTicket($ticket, $owner);
        $ticket->load([
            'restaurant',
            'messages' => fn ($query) => $query->where('visibility', 'public')
                ->with(['authorUser', 'authorAdmin'])
                ->orderBy('created_at'),
            'statusHistory' => fn ($query) => $query->with(['actorUser', 'actorAdmin'])->orderBy('created_at'),
        ]);

        return view('restaurant.support-tickets.show', [
            'ticket' => $ticket,
            'user' => $owner,
            'restaurant' => $owner->restaurant,
        ]);
    }

    public function reply(Request $request, SupportTicket $ticket, SupportTicketService $service): RedirectResponse
    {
        $owner = $this->owner();
        $ticket = $this->tenantTicket($ticket, $owner);
        $validated = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:10000'],
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ]);

        $service->replyAsOwner($ticket, $owner, $validated['body'], $request->file('attachment'));

        return redirect()->route('restaurant.support.show', $ticket)
            ->with('status', 'Your reply was sent.');
    }

    public function markNotificationRead(
        RestaurantSupportTicketNotification $notification,
        SupportTicketService $service,
    ): RedirectResponse {
        $owner = $this->owner();
        $service->markNotificationRead($notification, $owner);

        return redirect()->route('restaurant.support.show', $notification->support_ticket_id);
    }

    public function attachment(SupportTicket $ticket, SupportTicketMessage $message)
    {
        $owner = $this->owner();
        $ticket = $this->tenantTicket($ticket, $owner);
        abort_unless(
            $message->support_ticket_id === $ticket->id
                && $message->visibility === 'public'
                && $message->attachment_path,
            404,
        );
        abort_unless(Storage::disk('local')->exists($message->attachment_path), 404);

        return response()->download(
            Storage::disk('local')->path($message->attachment_path),
            $message->attachment_name ?? 'support-attachment',
            ['Content-Type' => $message->attachment_mime ?? 'application/octet-stream'],
        );
    }

    private function owner(): User
    {
        return app(RestaurantAccessContext::class)->owner();
    }

    private function tenantTicket(SupportTicket $ticket, User $owner): SupportTicket
    {
        return SupportTicket::query()
            ->where('restaurant_id', $owner->restaurant_id)
            ->findOrFail($ticket->id);
    }
}
