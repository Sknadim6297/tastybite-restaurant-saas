<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\RestaurantSubscription;
use App\Models\SaasFeature;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RestaurantBrandingTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private Restaurant $otherRestaurant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->restaurant = $this->createRestaurant('Bluebird Bistro', 'bluebird-bistro');
        $this->otherRestaurant = $this->createRestaurant('Greenleaf Grill', 'greenleaf-grill');
        $this->owner = User::create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Bluebird Owner',
            'email' => 'owner@example.test',
            'password' => 'StrongTestPass123!',
            'role' => 'owner',
            'status' => 'active',
        ]);

        $plan = SubscriptionPlan::create([
            'name' => 'POS Access',
            'code' => 'POS-ACCESS',
            'price' => '0.00',
            'currency' => 'INR',
            'billing_period' => 'monthly',
            'duration_months' => 1,
            'trial_available' => false,
            'status' => 'active',
        ]);
        $feature = SaasFeature::query()->where('code', 'pos')->firstOrFail();
        $plan->features()->attach($feature->id, ['is_enabled' => true, 'limit_value' => null]);
        RestaurantSubscription::create([
            'restaurant_id' => $this->restaurant->id,
            'subscription_plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'plan_code' => $plan->code,
            'price' => '0.00',
            'currency' => 'INR',
            'billing_period' => 'monthly',
            'duration_months' => 1,
            'status' => 'active',
            'is_trial' => false,
            'starts_at' => CarbonImmutable::now(config('app.timezone'))->subDay(),
            'expires_at' => CarbonImmutable::now(config('app.timezone'))->addMonth(),
        ]);

        $this->actingAs($this->owner, 'web')
            ->withSession([
                'restaurant_user_password_version' => hash('sha256', $this->owner->getAuthPassword()),
            ]);
    }

    public function test_owner_can_upload_branding_and_pos_uses_restaurant_and_owner_records(): void
    {
        $this->get(route('restaurant.profile'))
            ->assertOk()
            ->assertSee('Restaurant branding')
            ->assertSee('data-branding-image-input', false)
            ->assertSee('branding-image-preview', false)
            ->assertSee('profile-branding-card', false)
            ->assertSee('Save branding');

        $this->put(route('restaurant.profile.branding'), [
            'logo' => UploadedFile::fake()->image('restaurant.png', 256, 256),
            'restaurant_id' => $this->otherRestaurant->id,
        ])->assertRedirect(route('restaurant.profile'));

        $this->restaurant->refresh();
        $this->assertDatabaseHas('restaurants', [
            'id' => $this->restaurant->id,
            'name' => 'Bluebird Bistro',
            'logo_path' => $this->restaurant->logo_path,
        ]);
        $this->assertStringStartsWith('restaurant-branding/'.$this->restaurant->id.'/', $this->restaurant->logo_path);
        Storage::disk('public')->assertExists($this->restaurant->logo_path);

        $this->get(route('pos.index'))
            ->assertOk()
            ->assertSee('Bluebird Bistro')
            ->assertSee('Bluebird Owner')
            ->assertSee('Owner')
            ->assertSee('brand-logo-image', false)
            ->assertSee($this->restaurant->logo_url, false)
            ->assertDontSee('bi-cup-hot-fill', false);

        $this->assertNull($this->otherRestaurant->fresh()->logo_path);
    }

    public function test_owner_can_replace_and_remove_their_restaurant_logo(): void
    {
        $oldPath = UploadedFile::fake()->image('old.jpg')->store(
            'restaurant-branding/'.$this->restaurant->id,
            'public',
        );
        $this->restaurant->update(['logo_path' => $oldPath]);

        $this->put(route('restaurant.profile.branding'), [
            'logo' => UploadedFile::fake()->image('new.webp', 256, 256),
        ])->assertRedirect(route('restaurant.profile'));

        $newPath = $this->restaurant->fresh()->logo_path;
        $this->assertNotSame($oldPath, $newPath);
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($newPath);

        $this->put(route('restaurant.profile.branding'), ['remove_logo' => '1'])
            ->assertRedirect(route('restaurant.profile'));
        $this->assertNull($this->restaurant->fresh()->logo_path);
        Storage::disk('public')->assertMissing($newPath);
    }

    public function test_failed_logo_replacement_preserves_the_existing_logo(): void
    {
        $oldPath = UploadedFile::fake()->image('preserved.png', 256, 256)
            ->store('restaurant-branding/'.$this->restaurant->id, 'public');
        $this->restaurant->update(['logo_path' => $oldPath]);

        $this->from(route('restaurant.profile'))
            ->put(route('restaurant.profile.branding'), [
                'logo' => UploadedFile::fake()->create('unsupported.txt', 10, 'text/plain'),
            ])
            ->assertRedirect(route('restaurant.profile'))
            ->assertSessionHasErrors('logo');

        $this->assertSame($oldPath, $this->restaurant->fresh()->logo_path);
        Storage::disk('public')->assertExists($oldPath);
    }

    public function test_branding_rejects_invalid_and_oversized_dimensions(): void
    {
        $this->from(route('restaurant.profile'))
            ->put(route('restaurant.profile.branding'), [
                'logo' => UploadedFile::fake()->create('not-an-image.txt', 10, 'text/plain'),
            ])
            ->assertRedirect(route('restaurant.profile'))
            ->assertSessionHasErrors('logo');

        $this->from(route('restaurant.profile'))
            ->put(route('restaurant.profile.branding'), [
                'logo' => UploadedFile::fake()->image('too-small.png', 32, 32),
            ])
            ->assertRedirect(route('restaurant.profile'))
            ->assertSessionHasErrors('logo');

        $this->assertNull($this->restaurant->fresh()->logo_path);
    }

    public function test_only_the_authenticated_restaurant_owner_can_change_branding(): void
    {
        $staff = User::create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Bluebird Staff',
            'email' => 'staff@example.test',
            'password' => 'StrongTestPass123!',
            'role' => 'staff',
            'status' => 'active',
        ]);

        $this->actingAs($staff, 'web')
            ->withSession([
                'restaurant_user_password_version' => hash('sha256', $staff->getAuthPassword()),
            ])
            ->put(route('restaurant.profile.branding'), [
                'logo' => UploadedFile::fake()->image('forbidden.png', 256, 256),
            ])
            ->assertForbidden();

        $this->assertNull($this->restaurant->fresh()->logo_path);
        $this->assertNull($this->otherRestaurant->fresh()->logo_path);
    }

    public function test_missing_or_foreign_logo_path_uses_default_pos_icon(): void
    {
        $foreignPath = UploadedFile::fake()->image('foreign.png')->store(
            'restaurant-branding/'.$this->otherRestaurant->id,
            'public',
        );
        $this->restaurant->update(['logo_path' => $foreignPath]);

        $this->get(route('pos.index'))
            ->assertOk()
            ->assertSee('bi-cup-hot-fill', false)
            ->assertDontSee(route('public.uploads.show', [
                'directory' => 'restaurant-branding',
                'restaurantId' => $this->otherRestaurant->id,
                'filename' => basename($foreignPath),
            ]), false);
    }

    private function createRestaurant(string $name, string $slug): Restaurant
    {
        return Restaurant::create([
            'name' => $name,
            'slug' => $slug,
            'email' => $slug.'@example.test',
            'status' => 'active',
        ]);
    }
}
