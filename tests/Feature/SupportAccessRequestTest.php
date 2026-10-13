<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\SuperAdmin;
use App\Models\SupportAccessRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportAccessRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_support_request_requires_independent_review_and_never_creates_a_restaurant_session(): void
    {
        $requester = $this->actingAsSuperAdmin('requester@example.test');
        $restaurant = Restaurant::create([
            'name' => 'Support House',
            'slug' => 'support-house',
            'email' => 'support-house@example.test',
            'status' => 'active',
        ]);

        $this->post(route('super-admin.support-access.store'), [
            'restaurant_id' => $restaurant->id,
            'requested_scope' => 'technical',
            'reason' => 'Investigate a reported account configuration issue.',
            'requested_duration_minutes' => 60,
        ])->assertSessionHas('status');

        $supportRequest = SupportAccessRequest::query()->firstOrFail();
        $this->assertSame('pending', $supportRequest->status);
        $this->assertFalse(auth('web')->check());
        $this->assertDatabaseHas('support_access_request_events', [
            'support_access_request_id' => $supportRequest->id,
            'actor_admin_id' => $requester->id,
            'action' => 'requested',
        ]);

        $this->from(route('super-admin.support-access.show', $supportRequest))
            ->post(route('super-admin.support-access.decide', $supportRequest), [
                'decision' => 'approved',
                'reason' => 'Requester cannot review their own request.',
            ])
            ->assertRedirect(route('super-admin.support-access.show', $supportRequest))
            ->assertSessionHasErrors('decision');

        $this->assertSame('pending', $supportRequest->fresh()->status);

        $reviewer = $this->actingAsSuperAdmin('reviewer@example.test');
        $this->post(route('super-admin.support-access.decide', $supportRequest), [
            'decision' => 'approved',
            'reason' => 'Independent review approved for the stated purpose.',
        ])->assertRedirect();

        $this->assertSame('approved', $supportRequest->fresh()->status);
        $this->assertSame($reviewer->id, $supportRequest->fresh()->reviewer_admin_id);
        $this->assertDatabaseHas('restaurant_audit_logs', [
            'actor_id' => $reviewer->id,
            'action' => 'support_access_approved',
            'target_type' => 'support_access_request',
            'target_id' => $supportRequest->id,
        ]);
        $this->get(route('super-admin.support-access.show', $supportRequest))
            ->assertOk()
            ->assertSee('Approval does not grant system access')
            ->assertSee('No impersonation token');
        $this->assertFalse(auth('web')->check());
    }

    public function test_requester_can_cancel_only_their_own_pending_request(): void
    {
        $requester = $this->actingAsSuperAdmin('cancel@example.test');
        $restaurant = Restaurant::create([
            'name' => 'Cancel House',
            'slug' => 'cancel-house',
            'email' => 'cancel-house@example.test',
            'status' => 'active',
        ]);
        $this->post(route('super-admin.support-access.store'), [
            'restaurant_id' => $restaurant->id,
            'requested_scope' => 'billing',
            'reason' => 'Review a billing record before responding to the owner.',
            'requested_duration_minutes' => 30,
        ])->assertSessionHas('status');

        $supportRequest = SupportAccessRequest::query()->firstOrFail();
        $this->post(route('super-admin.support-access.cancel', $supportRequest), [
            'reason' => 'Issue resolved without support access.',
        ])->assertRedirect();

        $this->assertSame('cancelled', $supportRequest->fresh()->status);
        $this->assertDatabaseHas('support_access_request_events', [
            'support_access_request_id' => $supportRequest->id,
            'actor_admin_id' => $requester->id,
            'action' => 'cancelled',
        ]);
    }

    private function actingAsSuperAdmin(string $email): SuperAdmin
    {
        $admin = SuperAdmin::create([
            'name' => 'Support Admin',
            'email' => $email,
            'password' => 'StrongAdminPass1!',
            'is_active' => true,
        ]);
        $this->actingAs($admin, 'super_admin');
        $this->app['session']->put('super_admin_password_version', hash('sha256', $admin->getAuthPassword()));

        return $admin;
    }
}
