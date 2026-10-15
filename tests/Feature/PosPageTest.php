<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\RestaurantSubscription;
use App\Models\SaasFeature;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosPageTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $restaurant = Restaurant::create([
            'name' => 'POS Test Restaurant',
            'slug' => 'pos-test-restaurant',
            'email' => 'pos@example.test',
            'status' => 'active',
        ]);
        $this->owner = User::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'POS Test Owner',
            'email' => 'pos-owner@example.test',
            'password' => 'StrongTestPass123!',
            'role' => 'owner',
            'status' => 'active',
        ]);
        $plan = SubscriptionPlan::create([
            'name' => 'POS Test Plan',
            'code' => 'POS-TEST',
            'price' => '0.00',
            'currency' => 'INR',
            'billing_period' => 'monthly',
            'duration_months' => 1,
            'trial_available' => false,
            'status' => 'active',
        ]);
        $plan->features()->attach(SaasFeature::query()->where('code', 'pos')->value('id'), [
            'is_enabled' => true,
            'limit_value' => null,
        ]);
        RestaurantSubscription::create([
            'restaurant_id' => $restaurant->id,
            'subscription_plan_id' => $plan->id,
            'plan_name' => 'POS Test Plan',
            'plan_code' => 'POS-TEST',
            'price' => '0.00',
            'currency' => 'INR',
            'billing_period' => 'monthly',
            'duration_months' => 1,
            'status' => 'active',
            'is_trial' => false,
            'starts_at' => CarbonImmutable::now(config('app.timezone'))->subDay(),
            'expires_at' => CarbonImmutable::now(config('app.timezone'))->addMonth(),
        ]);

        $this->actingAs($this->owner, 'web');
        $this->app['session']->put('restaurant_user_password_version', hash('sha256', $this->owner->getAuthPassword()));
    }

    public function test_owner_login_session_marker_persists_through_regeneration_and_allows_pos_access(): void
    {
        $this->post(route('restaurant.logout'))
            ->assertRedirect(route('restaurant.login'));

        $this->post(route('restaurant.login.submit'), [
            'email' => $this->owner->email,
            'password' => 'StrongTestPass123!',
        ])
            ->assertRedirect(route('restaurant.dashboard'))
            ->assertSessionHas(
                'restaurant_user_password_version',
                hash('sha256', $this->owner->getAuthPassword())
            );

        $this->assertAuthenticatedAs($this->owner, 'web');

        $this->get(route('pos.index'))
            ->assertOk()
            ->assertSee('Tasty Bite POS')
            ->assertSee('id="billingPanel"', false);
    }

    public function test_pos_page_renders_with_local_assets_without_a_print_html_page(): void
    {
        $this->get(route('pos.index'))
            ->assertOk()
            ->assertSee('Tasty Bite POS')
            ->assertSee('Billing (POS)')
            ->assertSee('Restaurant POS')
            ->assertSee('data-bs-toggle="dropdown"', false)
            ->assertSee('data-search-focus', false)
            ->assertSee(route('restaurant.logout'), false)
            ->assertSee(asset('assets/style.css'), false)
            ->assertSee(asset('assets/style.css') . '?v=' . hash_file('sha256', public_path('assets/style.css')), false)
            ->assertSee(asset('assets/script.js'), false)
            ->assertSee(asset('assets/script.js') . '?v=' . hash_file('sha256', public_path('assets/script.js')), false)
            ->assertDontSee('/pos-print.html');

        $this->get('/pos-print.html')->assertNotFound();
    }

    public function test_pos_search_has_one_custom_clear_button_and_hides_native_search_controls(): void
    {
        $response = $this->get(route('pos.index'))->assertOk();
        $html = $response->getContent();
        $this->assertSame(1, substr_count($html, 'data-search-clear'));
        $this->assertStringContainsString('class="search-clear" type="button" data-search-clear', $html);
        $this->assertStringContainsString('<i class="bi bi-x-lg" aria-hidden="true"></i>', $html);
        $this->assertStringContainsString('class="search-submit" type="button" data-search-focus', $html);

        $style = file_get_contents(public_path('assets/style.css'));
        $this->assertIsString($style);
        $this->assertStringContainsString('#menuSearch::-webkit-search-cancel-button', $style);
        $this->assertStringContainsString('#menuSearch::-ms-clear', $style);

        $script = file_get_contents(public_path('assets/script.js'));
        $this->assertIsString($script);
        $this->assertStringContainsString('searchClear.hidden = menuSearch.value.trim().length === 0;', $script);
        $this->assertStringContainsString('menuSearch.dispatchEvent(new Event("input", { bubbles: true }));', $script);
        $this->assertStringContainsString('if (event.key === "Enter")', $script);
        $this->assertStringContainsString('menuSearch.focus();', $script);
    }

    public function test_print_html_page_is_not_available(): void
    {
        $this->get('/pos-print.html')->assertNotFound();
    }

    public function test_pos_renders_the_two_step_billing_controls_without_duplicating_billing_fields(): void
    {
        $response = $this->get(route('pos.index'));

        $response->assertOk()
            ->assertSee('id="posLayout"', false)
            ->assertSee('id="categoryScroller"', false)
            ->assertSee('data-cat-scroll="1"', false)
            ->assertSee('id="billingPanel"', false)
            ->assertSee('aria-hidden="true" inert', false)
            ->assertSee('id="checkoutTitle"', false)
            ->assertSee('Restaurant Checkout')
            ->assertSee('id="nextBilling"', false)
            ->assertSee('Next: Billing')
            ->assertSee('id="nextBillingCount"', false)
            ->assertSee('id="billingBack"', false)
            ->assertSee('Back to Products')
            ->assertSee('Payment Method')
            ->assertSee('Payment Details')
            ->assertSee('data-payment-method="Cash"', false)
            ->assertSee('data-payment-method="Card"', false)
            ->assertSee('data-payment-method="UPI"', false)
            ->assertSee('id="paymentNotice"', false)
            ->assertSee('id="billingClose"', false)
            ->assertSee('role="dialog"', false)
            ->assertSee('aria-modal="true"', false)
            ->assertSee('id="tableNo"', false)
            ->assertSee('id="guestNo"', false)
            ->assertSee('id="orderNotes"', false)
            ->assertSee('id="discount"', false)
            ->assertSee('id="cartItems"', false)
            ->assertSee('id="grandTotal"', false)
            ->assertSee('onclick="holdOrder()"', false)
            ->assertSee('onclick="clearCart()"', false)
            ->assertSee('onclick="generateBill()"', false);

        $script = file_get_contents(public_path('assets/script.js'));
        $this->assertIsString($script);
        $this->assertStringContainsString('[...cart.values()].reduce((total, item) => total + item.qty, 0)', $script);
        $this->assertStringContainsString('setBillingOpen(true)', $script);
        $this->assertStringContainsString('setBillingOpen(false)', $script);
        $this->assertStringContainsString('billingPanel.inert = !isOpen;', $script);
        $this->assertStringContainsString('paymentMethodNotices', $script);
        $this->assertStringContainsString('no card details are collected here', $script);

        $style = file_get_contents(public_path('assets/style.css'));
        $this->assertIsString($style);
        $this->assertStringContainsString('.pos-layout.is-billing-open', $style);
        $this->assertStringContainsString('width: min(960px, calc(100vw - 48px));', $style);
        $this->assertStringContainsString('grid-template-columns: 55fr 45fr;', $style);
        $this->assertStringNotContainsString('--pos-billing-width: 420px;', $style);
        $this->assertStringContainsString('@media (max-width: 767.98px)', $style);
        $this->assertStringContainsString('prefers-reduced-motion: reduce', $style);
    }
}
