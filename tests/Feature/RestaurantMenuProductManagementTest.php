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
use Database\Seeders\DemoRestaurantMenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RestaurantMenuProductManagementTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private Restaurant $otherRestaurant;

    private User $owner;

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
        $posFeature = SaasFeature::query()->where('code', 'pos')->firstOrFail();
        $plan->features()->attach($posFeature->id, [
            'is_enabled' => true,
            'limit_value' => null,
        ]);
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

    public function test_owner_can_create_product_with_image_and_submitted_tenant_fields_are_ignored(): void
    {
        $category = $this->createCategory($this->restaurant, 'Breakfast');
        $response = $this->post(route('restaurant.menu.products.store'), [
            'name' => 'Masala Dosa',
            'category_id' => $category->id,
            'description' => 'Crisp dosa with potato filling',
            'price' => '125.50',
            'image' => UploadedFile::fake()->image('dosa.png'),
            'food_type' => 'veg',
            'is_available' => '1',
            'is_active' => '1',
            'sort_order' => '3',
            'restaurant_id' => $this->otherRestaurant->id,
            'image_path' => 'forged/path.png',
        ]);

        $response->assertRedirect(route('restaurant.menu.products.index'))
            ->assertSessionHas('status', 'Product created successfully.');

        $product = RestaurantMenuItem::query()->where('name', 'Masala Dosa')->firstOrFail();
        $this->assertSame($this->restaurant->id, $product->restaurant_id);
        $this->assertSame($category->id, $product->category_id);
        $this->assertSame('125.50', $product->price);
        $this->assertStringStartsWith('restaurant-menu/'.$this->restaurant->id.'/', $product->image_path);
        Storage::disk('public')->assertExists($product->image_path);
    }

    public function test_owner_can_upload_valid_jpeg_png_and_webp_product_images(): void
    {
        foreach (['jpeg', 'png', 'webp'] as $extension) {
            $name = 'Format '.$extension;
            $response = $this->post(route('restaurant.menu.products.store'), $this->validProductData([
                'name' => $name,
                'image' => UploadedFile::fake()->image('food.'.$extension, 128, 128),
            ]));

            $response->assertRedirect(route('restaurant.menu.products.index'));
            $product = RestaurantMenuItem::query()->where('name', $name)->firstOrFail();
            $this->assertStringStartsWith('restaurant-menu/'.$this->restaurant->id.'/', $product->image_path);
            Storage::disk('public')->assertExists($product->image_path);
            $this->assertStringContainsString('/uploads/restaurant-menu/'.$this->restaurant->id.'/', $product->image_url);
        }
    }

    public function test_owner_can_edit_product_fields_and_keep_existing_image(): void
    {
        $product = $this->createProduct($this->restaurant, 'Tea');
        $oldPath = $this->storeImage($this->restaurant, 'old.jpg');
        $product->update(['image_path' => $oldPath]);

        $this->put(route('restaurant.menu.products.update', $product), [
            'name' => 'Ginger Tea',
            'category_id' => '',
            'description' => 'Fresh ginger',
            'price' => '35.00',
            'food_type' => 'non_veg',
            'is_available' => '0',
            'is_active' => '0',
            'sort_order' => '8',
            'restaurant_id' => $this->otherRestaurant->id,
            'image_path' => 'forged/path.jpg',
        ])->assertRedirect(route('restaurant.menu.products.index'));

        $this->assertDatabaseHas('restaurant_menu_items', [
            'id' => $product->id,
            'restaurant_id' => $this->restaurant->id,
            'category_id' => null,
            'name' => 'Ginger Tea',
            'image_path' => $oldPath,
            'food_type' => 'non_veg',
            'is_available' => 0,
            'is_active' => 0,
            'sort_order' => 8,
        ]);
        Storage::disk('public')->assertExists($oldPath);
    }

    public function test_replacing_or_removing_a_product_image_cleans_up_only_the_old_file(): void
    {
        $product = $this->createProduct($this->restaurant, 'Juice');
        $oldPath = $this->storeImage($this->restaurant, 'old.jpg');
        $product->update(['image_path' => $oldPath]);

        $this->put(route('restaurant.menu.products.update', $product), $this->validProductData([
            'image' => UploadedFile::fake()->image('new.webp'),
        ]))->assertRedirect(route('restaurant.menu.products.index'));

        $product->refresh();
        $newPath = $product->image_path;
        $this->assertNotSame($oldPath, $newPath);
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($newPath);

        $this->put(route('restaurant.menu.products.update', $product), $this->validProductData([
            'remove_image' => '1',
        ]))->assertRedirect(route('restaurant.menu.products.index'));

        $this->assertNull($product->fresh()->image_path);
        Storage::disk('public')->assertMissing($newPath);
    }

    public function test_invalid_or_oversized_replacement_keeps_the_existing_product_image(): void
    {
        $product = $this->createProduct($this->restaurant, 'Preserved Image');
        $oldPath = $this->storeImage($this->restaurant, 'preserved.jpg');
        $product->update(['image_path' => $oldPath]);

        foreach ([
            UploadedFile::fake()->create('unsupported.txt', 10, 'text/plain'),
            UploadedFile::fake()->image('oversized.png', 128, 128)->size(5121),
        ] as $replacement) {
            $this->from(route('restaurant.menu.products.edit', $product))
                ->put(route('restaurant.menu.products.update', $product), $this->validProductData([
                    'image' => $replacement,
                ]))
                ->assertRedirect(route('restaurant.menu.products.edit', $product))
                ->assertSessionHasErrors('image');

            $this->assertSame($oldPath, $product->fresh()->image_path);
            Storage::disk('public')->assertExists($oldPath);
        }
    }

    public function test_owner_can_delete_a_product_and_its_owned_image(): void
    {
        $product = $this->createProduct($this->restaurant, 'Lassi');
        $path = $this->storeImage($this->restaurant, 'lassi.jpg');
        $product->update(['image_path' => $path]);

        $this->delete(route('restaurant.menu.products.destroy', $product))
            ->assertRedirect(route('restaurant.menu.products.index'));

        $this->assertDatabaseMissing('restaurant_menu_items', ['id' => $product->id]);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_edit_form_previews_the_saved_image(): void
    {
        $product = $this->createProduct($this->restaurant, 'Preview Product');
        $path = $this->storeImage($this->restaurant, 'preview.jpg');
        $product->update(['image_path' => $path]);

        $this->get(route('restaurant.menu.products.edit', $product))
            ->assertOk()
            ->assertSee('data-product-image-preview', false)
            ->assertSee(route('public.uploads.show', [
                'directory' => 'restaurant-menu',
                'restaurantId' => $this->restaurant->id,
                'filename' => basename($path),
            ]), false)
            ->assertSee('form-option-grid', false)
            ->assertSee('name="is_available" value="0"', false)
            ->assertSee('name="is_available" value="1"', false)
            ->assertSee('name="is_active" value="0"', false)
            ->assertSee('name="is_active" value="1"', false)
            ->assertSee('assets/menu-product.js');
    }

    public function test_product_listing_shows_owned_image_and_placeholder_for_missing_image(): void
    {
        $product = $this->createProduct($this->restaurant, 'Image Product');
        $path = $this->storeImage($this->restaurant, 'listing.jpg');
        $product->update(['image_path' => $path]);
        $this->createProduct($this->restaurant, 'No Image Product');

        $this->get(route('restaurant.menu.products.index'))
            ->assertOk()
            ->assertSee('src="'.route('public.uploads.show', [
                'directory' => 'restaurant-menu',
                'restaurantId' => $this->restaurant->id,
                'filename' => basename($path),
            ]).'"', false)
            ->assertSee('src="'.asset('assets/menu-item-placeholder.svg').'"', false);
    }

    public function test_edit_form_uses_placeholder_when_saved_image_file_is_missing(): void
    {
        $product = $this->createProduct($this->restaurant, 'Missing Image Product');
        $product->update(['image_path' => 'restaurant-menu/'.$this->restaurant->id.'/missing.jpg']);

        $this->get(route('restaurant.menu.products.edit', $product))
            ->assertOk()
            ->assertSee('src="'.asset('assets/menu-item-placeholder.svg').'"', false)
            ->assertSee('name="remove_image"', false);
    }

    public function test_non_owner_restaurant_user_cannot_manage_products(): void
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
            ]);

        $this->get(route('restaurant.menu.products.index'))->assertForbidden();
    }

    public function test_owner_can_search_their_products(): void
    {
        $this->createProduct($this->restaurant, 'Masala Dosa', 'South Indian breakfast');
        $this->createProduct($this->restaurant, 'Filter Coffee', 'Fresh brew');
        $this->createProduct($this->otherRestaurant, 'Foreign Product', 'South Indian breakfast');

        $this->get(route('restaurant.menu.products.index', ['search' => 'South Indian']))
            ->assertOk()
            ->assertSee('id="product-filters"', false)
            ->assertSee('menu-product-filters.js')
            ->assertDontSee('Apply filters')
            ->assertSee('Masala Dosa')
            ->assertDontSee('Filter Coffee')
            ->assertDontSee('Foreign Product');
    }

    public function test_product_filter_ajax_combines_search_and_category_and_keeps_pagination(): void
    {
        $breakfast = $this->createCategory($this->restaurant, 'Breakfast');
        $beverages = $this->createCategory($this->restaurant, 'Beverages');
        $foreignCategory = $this->createCategory($this->otherRestaurant, 'Foreign Breakfast');
        for ($number = 1; $number <= 16; $number++) {
            $this->createProduct($this->restaurant, 'Masala Product '.$number, '', $breakfast);
        }
        $this->createProduct($this->restaurant, 'Masala Beverage', '', $beverages);
        $this->createProduct($this->otherRestaurant, 'Masala Foreign Product', '', $foreignCategory);

        $response = $this->getJson(route('restaurant.menu.products.index', [
            'search' => 'Masala',
            'category_id' => $breakfast->id,
            'page' => 2,
        ]))->assertOk();

        $html = $response->json('html');
        $this->assertIsString($html);
        $this->assertStringContainsString('Masala Product 9', $html);
        $this->assertStringNotContainsString('Masala Product 1</strong>', $html);
        $this->assertStringNotContainsString('Masala Beverage', $html);
        $this->assertStringNotContainsString('Masala Foreign Product', $html);
        $this->assertStringContainsString('search=Masala', $html);
        $this->assertStringContainsString('category_id='.$breakfast->id, $html);

        $emptyResponse = $this->getJson(route('restaurant.menu.products.index', [
            'search' => 'No matching item',
            'category_id' => $breakfast->id,
        ]))->assertOk();
        $this->assertStringContainsString('No matching products', $emptyResponse->json('html'));
    }

    public function test_owner_can_filter_products_by_an_owned_category_only(): void
    {
        $breakfast = $this->createCategory($this->restaurant, 'Breakfast');
        $beverages = $this->createCategory($this->restaurant, 'Beverages');
        $foreignCategory = $this->createCategory($this->otherRestaurant, 'Foreign Category');
        $this->createProduct($this->restaurant, 'Masala Dosa', '', $breakfast);
        $this->createProduct($this->restaurant, 'Filter Coffee', '', $beverages);
        $this->createProduct($this->otherRestaurant, 'Foreign Product', '', $foreignCategory);

        $this->get(route('restaurant.menu.products.index', ['category_id' => $breakfast->id]))
            ->assertOk()
            ->assertSee('id="product-category"', false)
            ->assertSee('value="'.$breakfast->id.'" selected', false)
            ->assertSee('Masala Dosa')
            ->assertDontSee('Filter Coffee')
            ->assertDontSee('Foreign Product');

        $this->from(route('restaurant.menu.products.index'))
            ->get(route('restaurant.menu.products.index', ['category_id' => $foreignCategory->id]))
            ->assertRedirect(route('restaurant.menu.products.index'))
            ->assertSessionHasErrors('category_id');
    }

    public function test_product_listing_is_tenant_scoped_and_paginated(): void
    {
        for ($number = 1; $number <= 16; $number++) {
            $this->createProduct($this->restaurant, 'Owner Product '.$number);
        }
        $this->createProduct($this->otherRestaurant, 'Foreign Product');

        $this->get(route('restaurant.menu.products.index'))
            ->assertOk()
            ->assertSee('Owner Product 1')
            ->assertDontSee('Foreign Product')
            ->assertSee('Next');
    }

    public function test_invalid_image_and_product_values_are_rejected(): void
    {
        $category = $this->createCategory($this->restaurant, 'Snacks');

        $this->from(route('restaurant.menu.products.create'))
            ->post(route('restaurant.menu.products.store'), [
                ...$this->validProductData([
                    'category_id' => $category->id,
                    'price' => '-1',
                    'image' => UploadedFile::fake()->create('payload.txt', 10, 'text/plain'),
                    'food_type' => 'unknown',
                    'sort_order' => '-1',
                ]),
            ])
            ->assertRedirect(route('restaurant.menu.products.create'))
            ->assertSessionHasErrors(['price', 'image', 'food_type', 'sort_order']);

        $this->assertDatabaseCount('restaurant_menu_items', 0);
    }

    public function test_product_must_use_a_category_belonging_to_the_owner_restaurant(): void
    {
        $foreignCategory = $this->createCategory($this->otherRestaurant, 'Foreign');

        $this->from(route('restaurant.menu.products.create'))
            ->post(route('restaurant.menu.products.store'), $this->validProductData([
                'category_id' => $foreignCategory->id,
            ]))
            ->assertRedirect(route('restaurant.menu.products.create'))
            ->assertSessionHasErrors('category_id');

        $this->assertDatabaseCount('restaurant_menu_items', 0);
    }

    public function test_owner_cannot_view_update_or_delete_another_restaurants_product(): void
    {
        $product = $this->createProduct($this->otherRestaurant, 'Private Product');

        $this->get(route('restaurant.menu.products.edit', $product))->assertNotFound();
        $this->put(route('restaurant.menu.products.update', $product), $this->validProductData([
            'name' => 'Changed Product',
        ]))->assertNotFound();
        $this->delete(route('restaurant.menu.products.destroy', $product))->assertNotFound();

        $this->assertDatabaseHas('restaurant_menu_items', [
            'id' => $product->id,
            'restaurant_id' => $this->otherRestaurant->id,
            'name' => 'Private Product',
        ]);
    }

    public function test_product_deletion_never_removes_a_file_outside_the_owner_storage_directory(): void
    {
        $product = $this->createProduct($this->restaurant, 'Stored Product');
        $foreignPath = $this->storeImage($this->otherRestaurant, 'foreign.jpg');
        $product->update(['image_path' => $foreignPath]);

        $this->delete(route('restaurant.menu.products.destroy', $product))
            ->assertRedirect(route('restaurant.menu.products.index'));

        Storage::disk('public')->assertExists($foreignPath);
    }

    public function test_product_display_never_uses_another_restaurants_image_path(): void
    {
        $product = $this->createProduct($this->restaurant, 'Isolated Image Product');
        $foreignPath = $this->storeImage($this->otherRestaurant, 'foreign-display.jpg');
        $product->update(['image_path' => $foreignPath]);

        $this->get(route('restaurant.menu.products.index'))
            ->assertOk()
            ->assertSee('src="'.asset('assets/menu-item-placeholder.svg').'"', false)
            ->assertDontSee('src="'.route('public.uploads.show', [
                'directory' => 'restaurant-menu',
                'restaurantId' => $this->otherRestaurant->id,
                'filename' => basename($foreignPath),
            ]).'"', false);
    }

    public function test_pos_shows_only_active_available_owner_products_with_working_category_attributes(): void
    {
        $category = $this->createCategory($this->restaurant, 'Breakfast');
        $inactiveCategory = $this->createCategory($this->restaurant, 'Archived', false);
        $this->createProduct($this->restaurant, 'Masala Dosa', 'Dosa description', $category);
        $this->createProduct($this->restaurant, 'Unavailable Dosa', '', $category, true, false);
        $this->createProduct($this->restaurant, 'Inactive Dosa', '', $category, false, true);
        $this->createProduct($this->otherRestaurant, 'Foreign Dosa', '', $category);
        $this->createProduct($this->restaurant, 'Archived Category Dosa', '', $inactiveCategory);

        $response = $this->get(route('pos.index'));

        $response->assertOk()
            ->assertSee('Masala Dosa')
            ->assertSee('data-category="'.$category->id.'"', false)
            ->assertSee('data-category-name="Breakfast"', false)
            ->assertSee('assets/menu-item-placeholder.svg')
            ->assertDontSee('Unavailable Dosa')
            ->assertDontSee('Inactive Dosa')
            ->assertDontSee('Foreign Dosa')
            ->assertSee('Archived Category Dosa');
    }

    public function test_pos_uses_request_aware_url_for_existing_owned_product_image(): void
    {
        $product = $this->createProduct($this->restaurant, 'POS Image Product');
        $path = $this->storeImage($this->restaurant, 'pos.jpg');
        $product->update(['image_path' => $path]);

        $this->get(route('pos.index'))
            ->assertOk()
            ->assertSee('src="'.route('public.uploads.show', [
                'directory' => 'restaurant-menu',
                'restaurantId' => $this->restaurant->id,
                'filename' => basename($path),
            ]).'"', false)
            ->assertSee('data-img="'.route('public.uploads.show', [
                'directory' => 'restaurant-menu',
                'restaurantId' => $this->restaurant->id,
                'filename' => basename($path),
            ]).'"', false);
    }

    public function test_pos_categories_and_products_match_the_menu_search_and_category_filter_contract(): void
    {
        $category = $this->createCategory($this->restaurant, 'Cold Drinks');
        $this->createProduct($this->restaurant, 'Lemon Soda', 'Fresh lemon', $category);

        $response = $this->get(route('pos.index'));
        $response->assertOk()
            ->assertSee('data-category="'.$category->id.'"', false)
            ->assertSee('data-name="Lemon Soda"', false)
            ->assertSee('data-category-name="Cold Drinks"', false);

        $script = file_get_contents(public_path('assets/script.js'));
        $this->assertIsString($script);
        $this->assertStringContainsString('card.dataset.category === activeCategory', $script);
        $this->assertStringContainsString('searchableText.includes(search)', $script);
        $this->assertSame(1, substr_count($script, 'setInterval(updateClock, 1000)'));
        $this->assertStringContainsString('new Intl.DateTimeFormat("en-IN"', $script);
        $this->assertStringContainsString('dateEl.textContent !== dateText', $script);
        $this->assertStringContainsString('timeEl.textContent !== timeText', $script);
        $view = file_get_contents(resource_path('views/pos.blade.php'));
        $this->assertIsString($view);
        $this->assertStringContainsString('data-category="{{ $product->category_id ?? 0 }}"', $view);
        $this->assertStringContainsString('data-category="{{ $category->id }}"', $view);
        $this->assertStringNotContainsString('setInterval(updateClock', $view);

        $styles = file_get_contents(public_path('assets/style.css'));
        $this->assertIsString($styles);
        $this->assertStringContainsString('font-variant-numeric: tabular-nums', $styles);
        $this->assertStringContainsString('#currentTime', $styles);
        $this->assertStringContainsString('@media (max-width: 1100px)', $styles);
        $this->assertStringContainsString('@media (max-width: 767.98px)', $styles);
        $this->assertStringNotContainsString('images.unsplash.com', $view);
    }

    public function test_demo_menu_seeder_creates_tenant_scoped_idempotent_menu_and_images_for_menu_and_pos(): void
    {
        $seeder = app(DemoRestaurantMenuSeeder::class);
        $result = $seeder->seedForRestaurant($this->restaurant->id);

        $this->assertSame([
            'categories_created' => 10,
            'products_created' => 50,
            'images_created' => 50,
        ], $result);
        $this->assertSame(10, $this->restaurant->menuCategories()->count());
        $this->assertSame(50, $this->restaurant->menuItems()->count());
        $this->assertSame(0, $this->otherRestaurant->menuCategories()->count());
        $this->assertSame(0, $this->otherRestaurant->menuItems()->count());

        $products = $this->restaurant->menuItems()->with('category')->get();
        $this->assertCount(50, $products);
        foreach ($products as $product) {
            $this->assertSame($this->restaurant->id, $product->restaurant_id);
            $this->assertSame($this->restaurant->id, $product->category->restaurant_id);
            $this->assertTrue($product->is_active);
            $this->assertTrue($product->is_available);
            $this->assertStringStartsWith('restaurant-menu/'.$this->restaurant->id.'/demo-', $product->image_path);
            Storage::disk('public')->assertExists($product->image_path);
            $this->assertStringContainsString('/uploads/restaurant-menu/'.$this->restaurant->id.'/', $product->image_url);
        }

        $chickenBiryani = $products->firstWhere('name', 'Chicken Biryani');
        $this->assertSame('200.00', $chickenBiryani->price);
        $this->assertSame('Biryani & Rice', $chickenBiryani->category->name);
        $this->assertSame('non_veg', $chickenBiryani->food_type);

        $this->get(route('restaurant.menu.categories.index'))
            ->assertOk()
            ->assertSee('Biryani & Rice')
            ->assertSee('Beverages');
        $this->get(route('restaurant.menu.products.index'))
            ->assertOk()
            ->assertSee('Chicken Biryani')
            ->assertSee('menu-product-thumbnail');
        $this->get(route('restaurant.menu.products.edit', $chickenBiryani))
            ->assertOk()
            ->assertSee('src="'.$chickenBiryani->image_url.'"', false);
        $this->get(route('pos.index'))
            ->assertOk()
            ->assertSee('data-name="Chicken Biryani"', false)
            ->assertSee('src="'.$chickenBiryani->image_url.'"', false);

        $secondRun = $seeder->seedForRestaurant($this->restaurant->id);
        $this->assertSame([
            'categories_created' => 0,
            'products_created' => 0,
            'images_created' => 0,
        ], $secondRun);
        $this->assertSame(10, $this->restaurant->menuCategories()->count());
        $this->assertSame(50, $this->restaurant->menuItems()->count());
    }

    public function test_demo_menu_seeder_does_not_overwrite_matching_existing_menu_data(): void
    {
        $category = RestaurantMenuCategory::create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Biryani & Rice',
            'slug' => 'biryani-rice',
            'description' => 'Existing restaurant description',
            'icon' => 'stars',
            'is_active' => false,
            'sort_order' => 77,
        ]);
        $existingProduct = RestaurantMenuItem::create([
            'restaurant_id' => $this->restaurant->id,
            'category_id' => $category->id,
            'name' => 'Chicken Biryani',
            'description' => 'Existing product description',
            'price' => '999.00',
            'food_type' => 'veg',
            'is_available' => false,
            'is_active' => false,
            'sort_order' => 88,
        ]);

        $result = app(DemoRestaurantMenuSeeder::class)->seedForRestaurant($this->restaurant->id);

        $this->assertSame([
            'categories_created' => 9,
            'products_created' => 49,
            'images_created' => 49,
        ], $result);
        $this->assertSame('Existing restaurant description', $category->fresh()->description);
        $this->assertSame('stars', $category->fresh()->icon);
        $this->assertFalse($category->fresh()->is_active);
        $this->assertSame('999.00', $existingProduct->fresh()->price);
        $this->assertSame('Existing product description', $existingProduct->fresh()->description);
        $this->assertFalse($existingProduct->fresh()->is_active);
        $this->assertFalse($existingProduct->fresh()->is_available);
        $this->assertNull($existingProduct->fresh()->image_path);
    }

    private function validProductData(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Test Product',
            'category_id' => '',
            'description' => '',
            'price' => '10.00',
            'food_type' => 'veg',
            'is_available' => '1',
            'is_active' => '1',
            'sort_order' => '0',
        ], $overrides);
    }

    private function createCategory(
        Restaurant $restaurant,
        string $name,
        bool $active = true,
    ): RestaurantMenuCategory {
        return RestaurantMenuCategory::create([
            'restaurant_id' => $restaurant->id,
            'name' => $name,
            'slug' => strtolower(str_replace(' ', '-', $name)),
            'is_active' => $active,
            'sort_order' => 0,
        ]);
    }

    private function createProduct(
        Restaurant $restaurant,
        string $name,
        string $description = '',
        ?RestaurantMenuCategory $category = null,
        bool $active = true,
        bool $available = true,
    ): RestaurantMenuItem {
        return RestaurantMenuItem::create([
            'restaurant_id' => $restaurant->id,
            'category_id' => $category?->id,
            'name' => $name,
            'description' => $description,
            'price' => '10.00',
            'food_type' => 'veg',
            'is_available' => $available,
            'is_active' => $active,
            'sort_order' => 0,
        ]);
    }

    private function storeImage(Restaurant $restaurant, string $name): string
    {
        return UploadedFile::fake()->image($name)->store('restaurant-menu/'.$restaurant->id, 'public');
    }
}
