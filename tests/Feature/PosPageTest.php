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
            ->assertDontSee('Point of Sale')
            ->assertDontSee('Select menu items to add them to the current bill.')
            ->assertDontSee('class="pos-section-heading"', false)
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

    public function test_pos_renders_a_persistent_live_billing_workspace(): void
    {
        $response = $this->get(route('pos.index'));

        $response->assertOk()
            ->assertSee('id="posLayout"', false)
            ->assertSee('class="pos-left"', false)
            ->assertSee('id="categoryScroller"', false)
            ->assertSee('data-cat-scroll="1"', false)
            ->assertSee('data-category-name="Items"', false)
            ->assertSee('id="billingPanel"', false)
            ->assertSee('id="checkoutTitle"', false)
            ->assertSee('assets/app-font.css', false)
            ->assertSee('fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap', false)
            ->assertSee('id="openHeldBills"', false)
            ->assertSee('id="heldBillsDialog"', false)
            ->assertSee('id="heldBillsCount"', false)
            ->assertSee('No held bills')
            ->assertSee('Orders you put on hold will appear here.')
            ->assertSee('fonts.googleapis.com/css2?family=Inter', false)
            ->assertSee('Orders')
            ->assertDontSee('New Order')
            ->assertDontSee('Choose order type and table details')
            ->assertDontSee('Live order')
            ->assertSee('Order history')
            ->assertSee('id="nextBillingCount"', false)
            ->assertSee('Payment Method')
            ->assertSee('data-payment-method="Cash"', false)
            ->assertSee('data-payment-method="Card"', false)
            ->assertSee('data-payment-method="UPI"', false)
            ->assertDontSee('Choose a method, then confirm receipt')
            ->assertDontSee('I confirm the full payment was received')
            ->assertDontSee('payment_confirmed')
            ->assertDontSee('Order Notes')
            ->assertDontSee('Completing records the sale and consumes configured recipe stock')
            ->assertSee('id="tableNo"', false)
            ->assertSee('id="guestNo"', false)
            ->assertSee('id="discount"', false)
            ->assertSee('id="cartItems"', false)
            ->assertSee('id="grandTotal"', false)
            ->assertSee('onclick="clearCart()"', false)
            ->assertSee('id="holdOrderButton"', false)
            ->assertSee('id="printBillButton"', false)
            ->assertSee('id="completeSale"', false)
            ->assertDontSee('Held Bills')
            ->assertDontSee('id="heldOrdersList"', false)
            ->assertDontSee('id="heldOrderCount"', false)
            ->assertDontSee('class="sidebar"', false)
            ->assertDontSee('holdOrdersModal')
            ->assertDontSee('nextBilling"')
            ->assertDontSee('generateBill');

        $this->assertSame(1, substr_count($response->getContent(), 'fonts.googleapis.com/css2?family=Inter'));

        $script = file_get_contents(public_path('assets/script.js'));
        $this->assertIsString($script);
        $this->assertStringContainsString('[...cart.values()].reduce((total, item) => total + item.qty, 0)', $script);
        $this->assertStringNotContainsString('paymentMethodNotices', $script);
        $this->assertStringNotContainsString('paymentConfirmedField', $script);
        $this->assertStringNotContainsString('posOrderNotes', $script);
        $this->assertStringContainsString('function holdOrder()', $script);
        $this->assertStringContainsString('async function resumeHeldBill(order, button)', $script);
        $this->assertStringContainsString('async function deleteHeldBill(order, button)', $script);
        $this->assertStringContainsString('heldBillsRequest', $script);
        $this->assertStringContainsString('window.print();', $script);
        $this->assertStringContainsString('form.submit();', $script);
        $this->assertStringNotContainsString('DEMO BILL', $script);

        $style = file_get_contents(public_path('assets/style.css'));
        $this->assertIsString($style);
        $this->assertStringContainsString('body.pos-page .pos-layout', $style);
        $this->assertStringContainsString('position: sticky;', $style);
        $this->assertStringContainsString('body.pos-page.pos-printing', $style);
        $this->assertStringContainsString('grid-template-columns: minmax(0, 1fr) minmax(350px, 360px);', $style);
        $this->assertStringContainsString('@media (max-width: 767.98px)', $style);
        $this->assertStringContainsString('prefers-reduced-motion: reduce', $style);
    }
}
