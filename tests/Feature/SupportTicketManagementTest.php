<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\RestaurantSupportTicketNotification;
use App\Models\SuperAdmin;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SupportTicketManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_restaurant_owner_can_create_and_list_tickets_without_an_active_subscription(): void
    {
        $owner = $this->createOwner($this->createRestaurant('support-create'));
        $this->actingAsOwner($owner);

        $this->get(route('restaurant.support.index'))
            ->assertOk()
            ->assertSee('No tickets yet');
        $this->post(route('restaurant.support.store'), [
            'subject' => 'Cannot open daily report',
            'category' => 'technical',
            'body' => 'The reports page is unavailable after I sign in.',
        ])->assertRedirect();

        $ticket = SupportTicket::query()->firstOrFail();
        $this->assertSame($owner->restaurant_id, $ticket->restaurant_id);
        $this->assertSame('open', $ticket->status);
        $this->assertDatabaseHas('support_ticket_messages', [
            'support_ticket_id' => $ticket->id,
            'author_user_id' => $owner->id,
            'visibility' => 'public',
        ]);
        $this->assertDatabaseHas('support_ticket_status_history', [
            'support_ticket_id' => $ticket->id,
            'new_status' => 'open',
        ]);
        $this->get(route('restaurant.support.index'))->assertOk()->assertSee($ticket->ticket_number);

        $this->actingAsAdmin();
        $this->get(route('super-admin.support-tickets.index'))
            ->assertOk()
            ->assertSee($ticket->ticket_number)
            ->assertSee('Cannot open daily report');
    }

    public function test_restaurant_ticket_queries_are_tenant_scoped_and_owner_role_is_required(): void
    {
        $owner = $this->createOwner($this->createRestaurant('support-tenant-one'));
        $otherOwner = $this->createOwner($this->createRestaurant('support-tenant-two'));
        $ticket = $this->createTicket($otherOwner);

        $this->actingAsOwner($owner);
        $this->get(route('restaurant.support.show', $ticket))->assertNotFound();

        $staff = User::create([
            'restaurant_id' => $owner->restaurant_id,
            'name' => 'Support Staff',
            'email' => 'support-staff@example.test',
            'role' => 'staff',
            'status' => 'active',
            'password' => Hash::make('StrongRestaurantPass1!'),
        ]);
        $this->actingAsOwner($staff)
            ->get(route('restaurant.support.index'))
            ->assertForbidden();
    }

    public function test_public_replies_notify_owners_while_internal_notes_remain_private(): void
    {
        $owner = $this->createOwner($this->createRestaurant('support-thread'));
        $ticket = $this->createTicket($owner);
        $admin = $this->actingAsAdmin();

        $this->post(route('super-admin.support-tickets.messages.store', $ticket), [
            'visibility' => 'internal',
            'body' => 'Internal diagnostic note not for the restaurant.',
        ])->assertRedirect();

        $this->actingAsOwner($owner)
            ->get(route('restaurant.support.show', $ticket))
            ->assertOk()
            ->assertDontSee('Internal diagnostic note not for the restaurant.');

        $this->actingAs($admin, 'super_admin');
        $this->app['session']->put('super_admin_password_version', hash('sha256', $admin->getAuthPassword()));
        $this->post(route('super-admin.support-tickets.messages.store', $ticket), [
            'visibility' => 'public',
            'body' => 'We are reviewing your request.',
        ])->assertRedirect();

        $notification = RestaurantSupportTicketNotification::query()->firstOrFail();
        $this->assertSame($owner->id, $notification->user_id);
        $this->assertSame('waiting_for_restaurant', $ticket->fresh()->status);
        $this->actingAsOwner($owner)
            ->get(route('restaurant.support.index'))
            ->assertOk()
            ->assertSee('Support replied')
            ->assertSee('1 unread');
        $this->post(route('restaurant.support.notifications.read', $notification))->assertRedirect();
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_status_transitions_are_validated_and_status_history_is_retained(): void
    {
        $owner = $this->createOwner($this->createRestaurant('support-status'));
        $ticket = $this->createTicket($owner);
        $this->actingAsAdmin();

        $this->put(route('super-admin.support-tickets.update', $ticket), [
            'status' => 'closed',
            'priority' => 'urgent',
            'assigned_admin_id' => '',
            'reason' => 'Issue resolved.',
        ])->assertRedirect();
        $this->assertSame('closed', $ticket->fresh()->status);

        $this->from(route('super-admin.support-tickets.show', $ticket))
            ->put(route('super-admin.support-tickets.update', $ticket), [
                'status' => 'in_progress',
                'priority' => 'normal',
                'assigned_admin_id' => '',
            ])
            ->assertSessionHasErrors('status');
        $this->assertSame('closed', $ticket->fresh()->status);
        $this->assertDatabaseHas('support_ticket_status_history', [
            'support_ticket_id' => $ticket->id,
            'new_status' => 'closed',
            'reason' => 'Issue resolved.',
        ]);

        $this->actingAsOwner($owner);
        $this->post(route('restaurant.support.messages.store', $ticket), ['body' => 'Please reopen this.'])
            ->assertSessionHasErrors('body');
    }

    public function test_attachments_are_stored_privately_and_downloads_are_tenant_scoped(): void
    {
        Storage::fake('local');
        $owner = $this->createOwner($this->createRestaurant('support-attachment-one'));
        $otherOwner = $this->createOwner($this->createRestaurant('support-attachment-two'));
        $this->actingAsOwner($owner);

        $this->post(route('restaurant.support.store'), [
            'subject' => 'Receipt upload problem',
            'category' => 'billing',
            'body' => 'The receipt upload is not completing correctly.',
            'attachment' => UploadedFile::fake()->create('receipt.pdf', 20, 'application/pdf'),
        ])->assertRedirect();

        $ticket = SupportTicket::query()->firstOrFail();
        $message = $ticket->messages()->firstOrFail();
        $this->assertNotNull($message->attachment_path);
        Storage::disk('local')->assertExists($message->attachment_path);
        $this->get(route('restaurant.support.attachments.show', [$ticket, $message]))->assertOk();

        $otherTicket = $this->createTicket($otherOwner);
        $this->get(route('restaurant.support.attachments.show', [$otherTicket, $message]))->assertNotFound();
        $this->actingAsAdmin();
        $this->get(route('super-admin.support-tickets.attachments.show', [$ticket, $message]))->assertOk();
    }

    public function test_ticket_search_filters_and_pagination_are_server_side(): void
    {
        $owner = $this->createOwner($this->createRestaurant('support-filter'));
        $matching = $this->createTicket($owner, ['subject' => 'Payment confirmation']);
        $this->createTicket($owner, ['subject' => 'Menu question', 'priority' => 'high']);
        $this->actingAsAdmin();

        $this->get(route('super-admin.support-tickets.index', ['search' => 'Payment', 'priority' => 'normal']))
            ->assertOk()
            ->assertSee($matching->ticket_number)
            ->assertDontSee('Menu question');
    }

    private function actingAsAdmin(): SuperAdmin
    {
        $admin = SuperAdmin::create([
            'name' => 'Support Test Admin',
            'email' => 'support-admin@example.test',
            'password' => 'StrongAdminPass123!',
            'is_active' => true,
        ]);
        $this->actingAs($admin, 'super_admin');
        $this->app['session']->put('super_admin_password_version', hash('sha256', $admin->getAuthPassword()));

        return $admin;
    }

    private function actingAsOwner(User $owner): self
    {
        $this->actingAs($owner, 'web');
        $this->app['session']->put('restaurant_user_password_version', hash('sha256', $owner->getAuthPassword()));

        return $this;
    }

    private function createRestaurant(string $slug): Restaurant
    {
        return Restaurant::create([
            'name' => str($slug)->replace('-', ' ')->title()->toString(),
            'slug' => $slug,
            'email' => $slug.'@example.test',
            'status' => 'active',
        ]);
    }

    private function createOwner(Restaurant $restaurant): User
    {
        return User::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Support Restaurant Owner',
            'email' => $restaurant->slug.'-owner@example.test',
            'role' => 'owner',
            'status' => 'active',
            'password' => Hash::make('StrongRestaurantPass1!'),
        ]);
    }

    private function createTicket(User $owner, array $overrides = []): SupportTicket
    {
        return SupportTicket::create([
            'ticket_number' => 'TB-'.strtoupper(str()->random(15)),
            'restaurant_id' => $owner->restaurant_id,
            'created_by_user_id' => $owner->id,
            'subject' => $overrides['subject'] ?? 'Test support request',
            'category' => $overrides['category'] ?? 'account',
            'priority' => $overrides['priority'] ?? 'normal',
            'status' => 'open',
            'last_activity_at' => now(),
        ]);
    }
}
