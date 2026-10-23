<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\RestaurantMenuCategory;
use App\Models\RestaurantMenuItem;
use App\Models\RestaurantSubscription;
use App\Models\SaasFeature;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RestaurantMenuCategoryManagementTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private Restaurant $otherRestaurant;

    private User $owner;

    private SubscriptionPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->restaurant = Restaurant::create([
            'name' => 'Bluebird Bistro',
            'slug' => 'bluebird-bistro',
            'email' => 'bluebird@example.test',
            'status' => 'active',
        ]);
        $this->otherRestaurant = Restaurant::create([
            'name' => 'Greenleaf Grill',
            'slug' => 'greenleaf-grill',
            'email' => 'greenleaf@example.test',
            'status' => 'active',
        ]);
        $this->owner = User::create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Bluebird Owner',
            'email' => 'owner@example.test',
            'password' => 'StrongTestPass123!',
            'role' => 'owner',
            'status' => 'active',
        ]);

        $this->plan = SubscriptionPlan::create([
            'name' => 'POS Access',
            'code' => 'POS-ACCESS',
            'price' => '0.00',
            'currency' => 'INR',
            'billing_period' => 'monthly',
            'duration_months' => 1,
            'trial_available' => false,
            'status' => 'active',
        ]);
        $posFeature = SaasFeature::query()->where('code', 'pos')->firstOrFail();
        $this->plan->features()->attach($posFeature->id, [
            'is_enabled' => true,
            'limit_value' => null,
        ]);
        RestaurantSubscription::create([
            'restaurant_id' => $this->restaurant->id,
            'subscription_plan_id' => $this->plan->id,
            'plan_name' => $this->plan->name,
            'plan_code' => $this->plan->code,
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

    public function test_category_listing_has_an_empty_state_when_no_categories_exist(): void
    {
        $this->get(route('restaurant.menu.categories.index'))
            ->assertOk()
            ->assertSee('No categories yet')
            ->assertSee('Add category')
            ->assertSee('Products')
            ->assertSee('bi bi-grid-3x3-gap', false)
            ->assertSee('bi bi-egg-fried', false);
    }

    public function test_category_uses_default_icon_when_no_icon_is_selected(): void
    {
        $this->createCategory($this->restaurant, 'General');

        $this->get(route('restaurant.menu.categories.index'))
            ->assertOk()
            ->assertSee('bi bi-grid', false);
        $this->get(route('pos.index'))
            ->assertOk()
            ->assertSee('bi bi-grid', false);
    }

    public function test_category_icon_picker_has_a_visible_mapped_icon_for_every_category_type(): void
    {
        $this->assertSame(
            [],
            array_diff(
                array_keys(RestaurantMenuCategory::ICONS),
                array_keys(RestaurantMenuCategory::ICON_CLASSES),
            ),
        );

        $this->get(route('restaurant.menu.categories.create'))
            ->assertOk()
            ->assertSee('data-category-icon-choice=""', false)
            ->assertSee('data-icon-class="grid"', false)
            ->assertSee('Biryani / Rice')
            ->assertSee('Mutton / Meat')
            ->assertSee('Grill / BBQ')
            ->assertSee('Ice Cream')
            ->assertSee('Kids Menu')
            ->assertSee('data-category-icon-preview-label', false);
    }

    public function test_create_and_edit_forms_render_for_the_authenticated_owner(): void
    {
        $this->get(route('restaurant.menu.categories.create'))
            ->assertOk()
            ->assertSee('Create category')
            ->assertSee('name="sort_order"', false)
            ->assertSee('class="form-field checkbox-field category-active-option"', false)
            ->assertSee('name="is_active" value="0"', false)
            ->assertSee('name="is_active" value="1"', false);

        $category = $this->createCategory($this->restaurant, 'Breakfast');
        $this->get(route('restaurant.menu.categories.edit', $category))
            ->assertOk()
            ->assertSee('Breakfast')
            ->assertSee('Save changes');
    }

    public function test_owner_can_create_a_category_and_submitted_restaurant_id_is_ignored(): void
    {
        $response = $this->post(route('restaurant.menu.categories.store'), [
            'name' => '  Hot Drinks  ',
            'description' => 'Tea and coffee',
            'is_active' => '1',
            'sort_order' => '4',
            'restaurant_id' => $this->otherRestaurant->id,
            'slug' => 'forged-slug',
        ]);

        $response->assertRedirect(route('restaurant.menu.categories.index'));
        $response->assertSessionHas('status', 'Category created successfully.');
        $this->assertDatabaseHas('restaurant_menu_categories', [
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Hot Drinks',
            'slug' => 'hot-drinks',
            'description' => 'Tea and coffee',
            'is_active' => 1,
            'sort_order' => 4,
        ]);
        $this->assertDatabaseMissing('restaurant_menu_categories', [
            'restaurant_id' => $this->otherRestaurant->id,
            'slug' => 'forged-slug',
        ]);
    }

    public function test_owner_can_create_category_with_icon_without_uploading_a_category_image(): void
    {
        $this->post(route('restaurant.menu.categories.store'), [
            'name' => 'Seafood',
            'icon' => 'fish',
            'image' => UploadedFile::fake()->image('seafood.png'),
            'is_active' => '1',
            'sort_order' => '1',
            'restaurant_id' => $this->otherRestaurant->id,
        ])->assertRedirect(route('restaurant.menu.categories.index'));

        $category = RestaurantMenuCategory::query()->where('name', 'Seafood')->firstOrFail();
        $this->assertSame($this->restaurant->id, $category->restaurant_id);
        $this->assertSame('fish', $category->icon);
        $this->assertNull($category->image_path);
        Storage::disk('public')->assertDirectoryEmpty('restaurant-menu-categories/'.$this->restaurant->id);
    }

    public function test_editing_category_preserves_an_existing_legacy_image(): void
    {
        $category = $this->createCategory($this->restaurant, 'Seasonal');
        $oldPath = UploadedFile::fake()->image('old.jpg')->store(
            'restaurant-menu-categories/'.$this->restaurant->id,
            'public',
        );
        $category->update(['image_path' => $oldPath]);

        $this->put(route('restaurant.menu.categories.update', $category), [
            'name' => $category->name,
            'icon' => 'flower1',
            'is_active' => '1',
            'sort_order' => '0',
        ])->assertRedirect(route('restaurant.menu.categories.index'));

        $category->refresh();
        $this->assertSame('flower1', $category->icon);
        $this->assertSame($oldPath, $category->image_path);
        Storage::disk('public')->assertExists($oldPath);
    }

    public function test_category_icon_picker_and_legacy_image_render_on_owner_pages_and_pos(): void
    {
        $category = $this->createCategory($this->restaurant, 'Fish');
        $path = UploadedFile::fake()->image('fish.jpg')->store(
            'restaurant-menu-categories/'.$this->restaurant->id,
            'public',
        );
        $category->update(['icon' => 'fish', 'image_path' => $path]);

        $this->get(route('restaurant.menu.categories.create'))
            ->assertOk()
            ->assertSee('data-category-icon-choice="fish"', false)
            ->assertSee('data-category-icon-search', false)
            ->assertSee('data-icon-class="water"', false)
            ->assertDontSee('Category image', false)
            ->assertDontSee('type="file"', false);
        $this->get(route('restaurant.menu.categories.edit', $category))
            ->assertOk()
            ->assertSee('value="fish"', false)
            ->assertSee('Selected: Fish')
            ->assertDontSee('Category image', false)
            ->assertDontSee('type="file"', false)
            ->assertSee('assets/menu-category.js');
        $this->get(route('restaurant.menu.categories.index'))
            ->assertOk()
            ->assertSee('menu-category-thumbnail', false)
            ->assertSee('bi bi-water', false)
            ->assertSee(route('public.uploads.show', [
                'directory' => 'restaurant-menu-categories',
                'restaurantId' => $this->restaurant->id,
                'filename' => basename($path),
            ]), false);
        $this->get(route('pos.index'))
            ->assertOk()
            ->assertSee('category-btn-image', false)
            ->assertSee('bi bi-water', false)
            ->assertSee(route('public.uploads.show', [
                'directory' => 'restaurant-menu-categories',
                'restaurantId' => $this->restaurant->id,
                'filename' => basename($path),
            ]), false);
    }

    public function test_category_image_display_is_isolated_to_its_restaurant(): void
    {
        $category = $this->createCategory($this->restaurant, 'Protected Image');
        $foreignPath = UploadedFile::fake()->image('foreign.jpg')->store(
            'restaurant-menu-categories/'.$this->otherRestaurant->id,
            'public',
        );
        $category->update(['image_path' => $foreignPath]);

        $this->get(route('restaurant.menu.categories.index'))
            ->assertOk()
            ->assertSee(asset('assets/menu-item-placeholder.svg'), false)
            ->assertDontSee(route('public.uploads.show', [
                'directory' => 'restaurant-menu-categories',
                'restaurantId' => $this->otherRestaurant->id,
                'filename' => basename($foreignPath),
            ]), false);
        $this->get(route('pos.index'))
            ->assertOk()
            ->assertSee(asset('assets/menu-item-placeholder.svg'), false)
            ->assertDontSee(route('public.uploads.show', [
                'directory' => 'restaurant-menu-categories',
                'restaurantId' => $this->otherRestaurant->id,
                'filename' => basename($foreignPath),
            ]), false);
    }

    public function test_category_icon_must_be_from_the_available_icon_set(): void
    {
        $this->from(route('restaurant.menu.categories.create'))
            ->post(route('restaurant.menu.categories.store'), [
                'name' => 'Invalid Icon',
                'icon' => 'script',
                'is_active' => '1',
                'sort_order' => '0',
            ])
            ->assertRedirect(route('restaurant.menu.categories.create'))
            ->assertSessionHasErrors('icon');

        $this->assertDatabaseCount('restaurant_menu_categories', 0);
    }

    public function test_owner_can_edit_a_category_and_slug_is_regenerated(): void
    {
        $category = $this->createCategory($this->restaurant, 'Tea');

        $this->put(route('restaurant.menu.categories.update', $category), [
            'name' => 'Herbal Tea',
            'description' => 'Caffeine-free options',
            'is_active' => '0',
            'sort_order' => '2',
        ])->assertRedirect(route('restaurant.menu.categories.index'));

        $this->assertDatabaseHas('restaurant_menu_categories', [
            'id' => $category->id,
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Herbal Tea',
            'slug' => 'herbal-tea',
            'description' => 'Caffeine-free options',
            'is_active' => 0,
            'sort_order' => 2,
        ]);
    }

    public function test_duplicate_category_names_are_rejected_within_one_restaurant(): void
    {
        $this->createCategory($this->restaurant, 'Beverages');

        $response = $this->from(route('restaurant.menu.categories.create'))
            ->post(route('restaurant.menu.categories.store'), [
                'name' => 'beverages',
                'description' => '',
                'is_active' => '1',
                'sort_order' => '0',
            ]);

        $response->assertRedirect(route('restaurant.menu.categories.create'))
            ->assertSessionHasErrors('name');
        $this->assertSame(1, RestaurantMenuCategory::query()
            ->where('restaurant_id', $this->restaurant->id)
            ->whereRaw('LOWER(name) = ?', ['beverages'])
            ->count());
    }

    public function test_category_validation_requires_a_name_and_non_negative_sort_order(): void
    {
        $this->from(route('restaurant.menu.categories.create'))
            ->post(route('restaurant.menu.categories.store'), [
                'name' => '   ',
                'is_active' => '1',
                'sort_order' => '-1',
            ])
            ->assertRedirect(route('restaurant.menu.categories.create'))
            ->assertSessionHasErrors(['name', 'sort_order']);

        $this->assertDatabaseCount('restaurant_menu_categories', 0);
    }

    public function test_duplicate_names_in_another_restaurant_are_allowed(): void
    {
        $this->createCategory($this->otherRestaurant, 'Beverages');

        $this->post(route('restaurant.menu.categories.store'), [
            'name' => 'Beverages',
            'is_active' => '1',
            'sort_order' => '0',
        ])->assertRedirect(route('restaurant.menu.categories.index'));

        $this->assertSame(1, RestaurantMenuCategory::query()
            ->where('restaurant_id', $this->restaurant->id)
            ->where('name', 'Beverages')
            ->count());
    }

    public function test_owner_can_toggle_category_status(): void
    {
        $category = $this->createCategory($this->restaurant, 'Desserts');

        $this->patch(route('restaurant.menu.categories.status', $category))
            ->assertRedirect(route('restaurant.menu.categories.index'));
        $this->assertDatabaseHas('restaurant_menu_categories', [
            'id' => $category->id,
            'is_active' => 0,
        ]);

        $this->patch(route('restaurant.menu.categories.status', $category))
            ->assertRedirect(route('restaurant.menu.categories.index'));
        $this->assertDatabaseHas('restaurant_menu_categories', [
            'id' => $category->id,
            'is_active' => 1,
        ]);
    }

    public function test_owner_can_delete_an_empty_category(): void
    {
        $category = $this->createCategory($this->restaurant, 'Seasonal');
        $imagePath = UploadedFile::fake()->image('seasonal.jpg')->store(
            'restaurant-menu-categories/'.$this->restaurant->id,
            'public',
        );
        $category->update(['image_path' => $imagePath]);

        $this->delete(route('restaurant.menu.categories.destroy', $category))
            ->assertRedirect(route('restaurant.menu.categories.index'));

        $this->assertDatabaseMissing('restaurant_menu_categories', ['id' => $category->id]);
        Storage::disk('public')->assertMissing($imagePath);
    }

    public function test_category_with_products_cannot_be_deleted(): void
    {
        $category = $this->createCategory($this->restaurant, 'Lunch');
        RestaurantMenuItem::create([
            'restaurant_id' => $this->restaurant->id,
            'category_id' => $category->id,
            'name' => 'Soup',
            'price' => '10.00',
            'food_type' => 'veg',
            'is_available' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->delete(route('restaurant.menu.categories.destroy', $category))
            ->assertRedirect(route('restaurant.menu.categories.index'))
            ->assertSessionHas('toast_type', 'warning');

        $this->assertDatabaseHas('restaurant_menu_categories', ['id' => $category->id]);
    }

    public function test_owner_cannot_view_edit_toggle_or_delete_another_restaurants_category(): void
    {
        $category = $this->createCategory($this->otherRestaurant, 'Private Category');

        $this->get(route('restaurant.menu.categories.edit', $category))->assertNotFound();
        $this->put(route('restaurant.menu.categories.update', $category), [
            'name' => 'Changed',
            'is_active' => '1',
            'sort_order' => '0',
        ])->assertNotFound();
        $this->patch(route('restaurant.menu.categories.status', $category))->assertNotFound();
        $this->delete(route('restaurant.menu.categories.destroy', $category))->assertNotFound();

        $this->assertDatabaseHas('restaurant_menu_categories', [
            'id' => $category->id,
            'restaurant_id' => $this->otherRestaurant->id,
            'name' => 'Private Category',
        ]);
    }

    public function test_category_routes_require_the_pos_subscription_feature(): void
    {
        $this->plan->features()->updateExistingPivot(
            SaasFeature::query()->where('code', 'pos')->value('id'),
            ['is_enabled' => false],
        );

        $this->get(route('restaurant.menu.categories.index'))->assertForbidden();
    }

    private function createCategory(Restaurant $restaurant, string $name): RestaurantMenuCategory
    {
        return RestaurantMenuCategory::create([
            'restaurant_id' => $restaurant->id,
            'name' => $name,
            'slug' => strtolower(str_replace(' ', '-', $name)),
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }
}
