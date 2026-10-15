<?php

namespace Database\Seeders;

use App\Models\Restaurant;
use App\Models\RestaurantMenuCategory;
use App\Models\RestaurantMenuItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class DemoRestaurantMenuSeeder extends Seeder
{
    /**
     * @return array{categories_created: int, products_created: int, images_created: int}
     */
    public function seedForRestaurant(int $restaurantId): array
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Demo menu data cannot be seeded in production.');
        }

        $restaurant = Restaurant::query()->findOrFail($restaurantId);
        $catalog = require __DIR__.'/data/demo-menu.php';
        $imageDirectory = __DIR__.'/data/demo-menu-images';
        $attributionPath = $imageDirectory.'/attributions.json';
        $attributions = is_file($attributionPath)
            ? json_decode((string) file_get_contents($attributionPath), true, flags: JSON_THROW_ON_ERROR)
            : null;

        if (! is_array($attributions)) {
            throw new RuntimeException('Demo food image attribution metadata is missing or invalid.');
        }

        foreach ($catalog['products'] as $product) {
            $imageName = Str::slug($product['name']);
            $imagePath = $imageDirectory.'/'.$imageName.'.jpg';
            $metadata = $attributions[$imageName] ?? null;
            $imageInfo = is_file($imagePath) ? getimagesize($imagePath) : false;

            if (
                ! is_array($metadata)
                || ! isset($metadata['title'], $metadata['source_url'], $metadata['creator'], $metadata['license'], $metadata['license_url'])
                || ! is_array($imageInfo)
                || ($imageInfo['mime'] ?? null) !== 'image/jpeg'
                || ($imageInfo[0] ?? 0) < 320
                || ($imageInfo[1] ?? 0) < 200
            ) {
                throw new RuntimeException("The licensed demo image for {$product['name']} is missing or invalid.");
            }
        }

        $copiedPaths = [];

        try {
            return DB::transaction(function () use ($restaurant, $catalog, $imageDirectory, &$copiedPaths): array {
                $categoriesBySlug = [];
                $categoriesCreated = 0;
                $productsCreated = 0;
                $imagesCreated = 0;

                foreach ($catalog['categories'] as $categoryData) {
                    $category = $restaurant->menuCategories()
                        ->where(function ($query) use ($categoryData): void {
                            $query->where('slug', $categoryData['slug'])
                                ->orWhereRaw('LOWER(name) = ?', [mb_strtolower($categoryData['name'])]);
                        })
                        ->first();

                    if (! $category) {
                        $slug = $categoryData['slug'];
                        $suffix = 1;

                        while ($restaurant->menuCategories()->where('slug', $slug)->exists()) {
                            $slug = $categoryData['slug'].'-'.$suffix++;
                        }

                        $category = $restaurant->menuCategories()->create([
                            ...$categoryData,
                            'slug' => $slug,
                            'is_active' => true,
                            'sort_order' => count($categoriesBySlug),
                        ]);
                        $categoriesCreated++;
                    }

                    $categoriesBySlug[$categoryData['slug']] = $category;
                }

                foreach ($catalog['products'] as $index => $productData) {
                    $category = $categoriesBySlug[$productData['category']];
                    $existing = $restaurant->menuItems()
                        ->whereRaw('LOWER(name) = ?', [mb_strtolower($productData['name'])])
                        ->first();

                    if ($existing) {
                        continue;
                    }

                    $imageName = Str::slug($productData['name']).'.jpg';
                    $sourcePath = $imageDirectory.'/'.$imageName;
                    $targetPath = 'restaurant-menu/'.$restaurant->id.'/demo-'.$imageName;
                    $disk = Storage::disk('public');

                    if ($disk->exists($targetPath)) {
                        $existingContents = $disk->get($targetPath);
                        $sourceContents = file_get_contents($sourcePath);

                        if (! is_string($sourceContents) || ! hash_equals(hash('sha256', $sourceContents), hash('sha256', $existingContents))) {
                            throw new RuntimeException("Refusing to overwrite the existing menu image at {$targetPath}.");
                        }
                    } else {
                        $contents = file_get_contents($sourcePath);

                        if (! is_string($contents) || ! $disk->put($targetPath, $contents)) {
                            throw new RuntimeException("The demo image for {$productData['name']} could not be copied to public storage.");
                        }

                        $copiedPaths[] = $targetPath;
                        $imagesCreated++;
                    }

                    $restaurant->menuItems()->create([
                        'category_id' => $category->id,
                        'name' => $productData['name'],
                        'description' => $productData['description'],
                        'price' => $productData['price'],
                        'image_path' => $targetPath,
                        'food_type' => $productData['food_type'],
                        'is_available' => true,
                        'is_active' => true,
                        'sort_order' => $index + 1,
                    ]);
                    $productsCreated++;
                }

                return [
                    'categories_created' => $categoriesCreated,
                    'products_created' => $productsCreated,
                    'images_created' => $imagesCreated,
                ];
            });
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($copiedPaths);

            throw $exception;
        }
    }
}
