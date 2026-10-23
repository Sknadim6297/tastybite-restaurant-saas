<?php

namespace Database\Seeders;

use App\Models\Restaurant;
use App\Models\RestaurantMenuCategory;
use App\Models\RestaurantMenuItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;

class TestRestaurantMenuDemoSeeder extends Seeder
{
    private const RESTAURANT_NAME = 'Test resturent';

    private const OWNER_NAME = 'Sk Nadim';

    private const CATEGORIES = [
        'Biryani' => ['icon' => 'rice'],
        'Rice' => ['icon' => 'rice'],
        'Chicken' => ['icon' => 'chicken'],
        'Mutton' => ['icon' => 'meat'],
        'Vegetarian' => ['icon' => 'leaf'],
        'Chinese' => ['icon' => 'noodles'],
        'Starters' => ['icon' => 'starters'],
        'Fast Food' => ['icon' => 'snacks'],
        'Beverages' => ['icon' => 'cup-straw'],
        'Desserts' => ['icon' => 'cake2'],
    ];

    private const PRODUCTS = [
        'Biryani' => [
            ['Chicken Biryani', '200.00', 'non_veg'],
            ['Mutton Biryani', '280.00', 'non_veg'],
            ['Egg Biryani', '150.00', 'non_veg'],
            ['Veg Biryani', '130.00', 'veg'],
            ['Special Chicken Biryani', '250.00', 'non_veg'],
        ],
        'Rice' => [
            ['Plain Rice', '70.00', 'veg'],
            ['Jeera Rice', '110.00', 'veg'],
            ['Fried Rice', '140.00', 'veg'],
            ['Egg Fried Rice', '160.00', 'non_veg'],
            ['Chicken Fried Rice', '190.00', 'non_veg'],
        ],
        'Chicken' => [
            ['Chicken Curry', '180.00', 'non_veg'],
            ['Chicken Butter Masala', '240.00', 'non_veg'],
            ['Chicken Kasha', '220.00', 'non_veg'],
            ['Chicken Tikka', '260.00', 'non_veg'],
            ['Chilli Chicken', '210.00', 'non_veg'],
        ],
        'Mutton' => [
            ['Mutton Curry', '300.00', 'non_veg'],
            ['Mutton Kasha', '330.00', 'non_veg'],
            ['Mutton Rogan Josh', '350.00', 'non_veg'],
            ['Mutton Rezala', '340.00', 'non_veg'],
            ['Mutton Keema', '270.00', 'non_veg'],
        ],
        'Vegetarian' => [
            ['Paneer Butter Masala', '220.00', 'veg'],
            ['Shahi Paneer', '240.00', 'veg'],
            ['Mixed Veg', '140.00', 'veg'],
            ['Dal Tadka', '120.00', 'veg'],
            ['Aloo Gobi', '130.00', 'veg'],
        ],
        'Chinese' => [
            ['Veg Chowmein', '130.00', 'veg'],
            ['Egg Chowmein', '150.00', 'non_veg'],
            ['Chicken Chowmein', '190.00', 'non_veg'],
            ['Veg Manchurian', '180.00', 'veg'],
            ['Chicken Manchurian', '220.00', 'non_veg'],
        ],
        'Starters' => [
            ['Chicken Pakora', '170.00', 'non_veg'],
            ['Paneer Pakora', '150.00', 'veg'],
            ['French Fries', '100.00', 'veg'],
            ['Chicken Lollipop', '240.00', 'non_veg'],
            ['Veg Spring Roll', '130.00', 'veg'],
        ],
        'Fast Food' => [
            ['Veg Burger', '90.00', 'veg'],
            ['Chicken Burger', '140.00', 'non_veg'],
            ['Veg Sandwich', '80.00', 'veg'],
            ['Chicken Sandwich', '120.00', 'non_veg'],
            ['Chicken Pizza', '280.00', 'non_veg'],
        ],
        'Beverages' => [
            ['Tea', '30.00', 'veg'],
            ['Coffee', '50.00', 'veg'],
            ['Cold Coffee', '100.00', 'veg'],
            ['Cola', '40.00', 'veg'],
            ['Fresh Lime Soda', '70.00', 'veg'],
        ],
        'Desserts' => [
            ['Gulab Jamun', '80.00', 'veg'],
            ['Rasgulla', '60.00', 'veg'],
            ['Ice Cream', '90.00', 'veg'],
            ['Mishti Doi', '70.00', 'veg'],
            ['Chocolate Brownie', '150.00', 'veg'],
        ],
    ];

    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('The restaurant menu demo seeder can only run in the local environment.');
        }

        $restaurant = Restaurant::query()
            ->where('name', self::RESTAURANT_NAME)
            ->sole();
        $restaurant->owners()
            ->where('name', self::OWNER_NAME)
            ->sole();

        $this->validateDefinitions();

        $result = DB::transaction(function () use ($restaurant): array {
            $categories = [];
            $createdCategories = 0;

            foreach (self::CATEGORIES as $name => $attributes) {
                $matches = $restaurant->menuCategories()
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                    ->get();

                if ($matches->count() > 1) {
                    throw new RuntimeException("More than one category named {$name} exists for the target restaurant.");
                }

                $category = $matches->first();
                if ($category === null) {
                    $category = $restaurant->menuCategories()->create([
                        'name' => $name,
                        'slug' => $this->uniqueSlug((int) $restaurant->id, $name),
                        'icon' => $attributes['icon'],
                        'is_active' => true,
                        'sort_order' => count($categories),
                    ]);
                    $createdCategories++;
                } else {
                    $category->update([
                        'icon' => $attributes['icon'],
                        'is_active' => true,
                    ]);
                }

                $categories[$name] = $category;
            }

            $createdProducts = 0;
            $existingProducts = 0;

            foreach (self::PRODUCTS as $categoryName => $products) {
                $category = $categories[$categoryName];

                foreach ($products as $sortOrder => [$name, $price, $foodType]) {
                    $this->validateProductDefinition(
                        (int) $restaurant->id,
                        (int) $category->id,
                        $sortOrder,
                        $name,
                        $price,
                        $foodType,
                    );

                    $matches = $restaurant->menuItems()
                        ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                        ->get();

                    if ($matches->count() > 1) {
                        throw new RuntimeException("More than one product named {$name} exists for the target restaurant.");
                    }

                    $existingProduct = $matches->first();
                    if ($existingProduct !== null) {
                        if (
                            (int) $existingProduct->category_id !== (int) $category->id
                            || (string) $existingProduct->price !== $price
                            || $existingProduct->food_type !== $foodType
                            || ! $existingProduct->is_active
                            || ! $existingProduct->is_available
                        ) {
                            throw new RuntimeException("The existing product {$name} does not match the demo definition and was not changed.");
                        }

                        $existingProducts++;

                        continue;
                    }

                    $restaurant->menuItems()->create([
                        'category_id' => $category->id,
                        'name' => $name,
                        'price' => $price,
                        'food_type' => $foodType,
                        'is_active' => true,
                        'is_available' => true,
                        'sort_order' => $sortOrder,
                        'image_path' => null,
                    ]);
                    $createdProducts++;
                }
            }

            $this->verifyResults($restaurant, $categories);

            return [
                'created_categories' => $createdCategories,
                'created_products' => $createdProducts,
                'existing_products' => $existingProducts,
                'category_count' => count($categories),
                'product_count' => array_sum(array_map('count', self::PRODUCTS)),
            ];
        });

        $this->command?->info("Restaurant ID {$restaurant->id}: {$result['category_count']} demo categories ({$result['created_categories']} created), {$result['product_count']} demo products ({$result['created_products']} created, {$result['existing_products']} already present).");
    }

    private function validateDefinitions(): void
    {
        foreach (self::CATEGORIES as $name => $attributes) {
            Validator::make([
                'name' => $name,
                'icon' => $attributes['icon'],
                'is_active' => true,
                'sort_order' => 0,
            ], [
                'name' => ['required', 'string', 'max:255'],
                'icon' => ['required', 'string', Rule::in(array_keys(RestaurantMenuCategory::ICONS))],
                'is_active' => ['required', 'boolean'],
                'sort_order' => ['required', 'integer', 'min:0', 'max:4294967295'],
            ])->validate();
        }
    }

    private function validateProductDefinition(
        int $restaurantId,
        int $categoryId,
        int $sortOrder,
        string $name,
        string $price,
        string $foodType,
    ): void {
        Validator::make([
            'name' => $name,
            'category_id' => $categoryId,
            'price' => $price,
            'food_type' => $foodType,
            'is_available' => true,
            'is_active' => true,
            'sort_order' => $sortOrder,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'category_id' => [
                'required',
                'integer',
                Rule::exists('restaurant_menu_categories', 'id')->where('restaurant_id', $restaurantId),
            ],
            'price' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
            'food_type' => ['required', Rule::in(['veg', 'non_veg'])],
            'is_available' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:4294967295'],
        ])->validate();
    }

    /**
     * @param array<string, RestaurantMenuCategory> $categories
     */
    private function verifyResults(Restaurant $restaurant, array $categories): void
    {
        foreach (self::PRODUCTS as $categoryName => $products) {
            $category = $categories[$categoryName];

            foreach ($products as [$name, $price, $foodType]) {
                $product = $restaurant->menuItems()
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                    ->sole();

                if (
                    (int) $product->category_id !== (int) $category->id
                    || (string) $product->price !== $price
                    || $product->food_type !== $foodType
                    || ! $product->is_active
                    || ! $product->is_available
                ) {
                    throw new RuntimeException("Product verification failed for {$name}.");
                }
            }
        }
    }

    private function uniqueSlug(int $restaurantId, string $name): string
    {
        $baseSlug = \Illuminate\Support\Str::slug($name) ?: 'category';
        $slug = $baseSlug;
        $suffix = 2;

        while (
            RestaurantMenuCategory::query()
                ->where('restaurant_id', $restaurantId)
                ->where('slug', $slug)
                ->exists()
        ) {
            $slug = $baseSlug.'-'.$suffix++;
        }

        return $slug;
    }
}
