<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\SuperAdmin;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Services\SupportTicketService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SupportTicketController extends Controller
{
    public function index(Request $request): View
    {
        $query = $this->businessTickets(SupportTicket::query())->with(['restaurant', 'creator', 'assignedAdmin']);
        $this->applyFilters($query, $request);

        return view('super-admin.support-tickets.index', [
            'tickets' => $query->orderByDesc('last_activity_at')->paginate(20)->withQueryString(),
            'counts' => $this->businessTickets(SupportTicket::query())
                ->selectRaw('status, count(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status'),
            'restaurants' => Restaurant::query()->where('slug', 'not like', 'demo-tb-2026-%')->orderBy('name')->get(['id', 'name']),
            'admins' => SuperAdmin::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'filters' => $request->only(['search', 'status', 'priority', 'restaurant_id', 'assigned_admin_id']),
        ]);
    }

    public function show(SupportTicket $ticket): View
    {
        $this->ensureBusinessTicket($ticket);
        $ticket->load([
            'restaurant',
            'creator',
            'assignedAdmin',
            'messages' => fn ($query) => $query->with(['authorUser', 'authorAdmin'])->orderBy('created_at'),
            'statusHistory' => fn ($query) => $query->with(['actorUser', 'actorAdmin'])->orderBy('created_at'),
        ]);

        return view('super-admin.support-tickets.show', [
            'ticket' => $ticket,
            'admins' => SuperAdmin::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, SupportTicket $ticket, SupportTicketService $service): RedirectResponse
    {
        $this->ensureBusinessTicket($ticket);
        $validated = $request->validate([
            'status' => ['required', Rule::in(['open', 'in_progress', 'waiting_for_restaurant', 'resolved', 'closed'])],
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'assigned_admin_id' => ['nullable', 'integer', Rule::exists('super_admins', 'id')->where('is_active', true)],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $admin = Auth::guard('super_admin')->user();
        if (! ($admin instanceof SuperAdmin)) {
            abort(403);
        }

        $service->updateTicket(
            $ticket,
            $admin,
            $validated['status'],
            $validated['priority'],
            $validated['assigned_admin_id'] ? (int) $validated['assigned_admin_id'] : null,
            $validated['reason'] ?? null,
        );

        return redirect()->route('super-admin.support-tickets.show', $ticket)
            ->with('status', 'Support ticket updated.');
    }

    public function reply(Request $request, SupportTicket $ticket, SupportTicketService $service): RedirectResponse
    {
        $this->ensureBusinessTicket($ticket);
        $validated = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:10000'],
            'visibility' => ['required', Rule::in(['public', 'internal'])],
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ]);

        $admin = Auth::guard('super_admin')->user();
        if (! ($admin instanceof SuperAdmin)) {
            abort(403);
        }

        $service->replyAsAdmin(
            $ticket,
            $admin,
            $validated['body'],
            $validated['visibility'],
            $request->file('attachment'),
        );

        return redirect()->route('super-admin.support-tickets.show', $ticket)
            ->with('status', $validated['visibility'] === 'internal' ? 'Internal note added.' : 'Reply sent to the restaurant.');
    }

    public function attachment(SupportTicket $ticket, SupportTicketMessage $message)
    {
        $this->ensureBusinessTicket($ticket);
        abort_unless($message->support_ticket_id === $ticket->id && $message->attachment_path, 404);

        return $this->download($message);
    }

    private function download(SupportTicketMessage $message)
    {
        abort_unless(Storage::disk('local')->exists($message->attachment_path), 404);

        return response()->download(
            Storage::disk('local')->path($message->attachment_path),
            $message->attachment_name ?? 'support-attachment',
            ['Content-Type' => $message->attachment_mime ?? 'application/octet-stream'],
        );
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        if ($request->filled('search')) {
            $search = trim($request->string('search')->toString());
            $query->where(fn ($builder) => $builder
                ->where('ticket_number', 'like', "%{$search}%")
                ->orWhere('subject', 'like', "%{$search}%")
                ->orWhereHas('restaurant', fn ($restaurant) => $restaurant->where('name', 'like', "%{$search}%")));
        }

        foreach ([
            'status' => ['open', 'in_progress', 'waiting_for_restaurant', 'resolved', 'closed'],
            'priority' => ['low', 'normal', 'high', 'urgent'],
        ] as $field => $values) {
            if (in_array($request->input($field), $values, true)) {
                $query->where($field, $request->input($field));
            }
        }

        foreach (['restaurant_id', 'assigned_admin_id'] as $field) {
            if ($request->filled($field) && ctype_digit((string) $request->input($field))) {
                $query->where($field, (int) $request->input($field));
            }
        }
    }

    private function businessTickets(Builder $query): Builder
    {
        return $query->whereHas('restaurant', fn (Builder $restaurant) => $restaurant
            ->where('slug', 'not like', 'demo-tb-2026-%'));
    }

    private function ensureBusinessTicket(SupportTicket $ticket): void
    {
        abort_if(str_starts_with((string) $ticket->restaurant?->slug, 'demo-tb-2026-'), 404);
    }
}
