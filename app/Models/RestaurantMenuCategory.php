<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class RestaurantMenuCategory extends Model
{
    use HasFactory;

    public const ICONS = [
        'basket2' => 'Menu',
        'cake2' => 'Cake',
        'cup-hot' => 'Hot Drinks',
        'cup-straw' => 'Drinks',
        'egg-fried' => 'Breakfast',
        'fire' => 'Grill / BBQ',
        'fish' => 'Fish',
        'flower1' => 'Desserts',
        'grid' => 'All Food / General',
        'leaf' => 'Vegetarian',
        'stars' => 'Specials',
        'rice' => 'Biryani / Rice',
        'chicken' => 'Chicken',
        'meat' => 'Mutton / Meat',
        'seafood' => 'Seafood',
        'egg' => 'Egg',
        'vegan' => 'Vegan',
        'starters' => 'Starters',
        'main-course' => 'Main Course',
        'curry' => 'Curry',
        'tandoor' => 'Tandoor',
        'pizza' => 'Pizza',
        'burger' => 'Burger',
        'sandwich' => 'Sandwich',
        'noodles' => 'Noodles',
        'pasta' => 'Pasta',
        'soup' => 'Soup',
        'salad' => 'Salad',
        'snacks' => 'Snacks',
        'street-food' => 'Street Food',
        'rolls' => 'Rolls',
        'momos' => 'Momos',
        'bread-roti' => 'Bread / Roti',
        'ice-cream' => 'Ice Cream',
        'sweets' => 'Sweets',
        'tea' => 'Tea',
        'coffee' => 'Coffee',
        'cold-drinks' => 'Cold Drinks',
        'juice' => 'Juice',
        'mocktails' => 'Mocktails',
        'combos' => 'Combos',
        'kids-menu' => 'Kids Menu',
        'drinks' => 'Drinks',
    ];

    public const ICON_CLASSES = [
        'basket2' => 'basket2',
        'cake2' => 'cake2',
        'cup-hot' => 'cup-hot',
        'cup-straw' => 'cup-straw',
        'egg-fried' => 'egg-fried',
        'fire' => 'fire',
        'fish' => 'water',
        'flower1' => 'flower1',
        'grid' => 'grid',
        'leaf' => 'tree',
        'stars' => 'stars',
        'rice' => 'basket2',
        'chicken' => 'egg-fried',
        'meat' => 'fire',
        'seafood' => 'droplet',
        'egg' => 'egg',
        'vegan' => 'tree',
        'starters' => 'basket',
        'main-course' => 'basket2',
        'curry' => 'droplet',
        'tandoor' => 'fire',
        'pizza' => 'basket2',
        'burger' => 'basket',
        'sandwich' => 'basket2',
        'noodles' => 'steam',
        'pasta' => 'cup',
        'soup' => 'cup-hot',
        'salad' => 'egg-fried',
        'snacks' => 'basket',
        'street-food' => 'shop',
        'rolls' => 'egg-fried',
        'momos' => 'basket2',
        'bread-roti' => 'basket2',
        'ice-cream' => 'snow',
        'sweets' => 'cake2',
        'tea' => 'cup-hot',
        'coffee' => 'cup-hot-fill',
        'cold-drinks' => 'cup-straw',
        'juice' => 'cup-straw',
        'mocktails' => 'cup-straw',
        'combos' => 'boxes',
        'kids-menu' => 'emoji-smile',
        'drinks' => 'cup-straw',
    ];

    protected $table = 'restaurant_menu_categories';

    protected $fillable = [
        'restaurant_id',
        'name',
        'slug',
        'description',
        'icon',
        'image_path',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(RestaurantMenuItem::class, 'category_id');
    }

    public static function iconClassFor(?string $icon): string
    {
        return self::ICON_CLASSES[$icon ?? ''] ?? 'grid';
    }

    public function getImageUrlAttribute(): string
    {
        $path = $this->image_path;
        $ownedPathPattern = '/\Arestaurant-menu-categories\/'.preg_quote((string) $this->restaurant_id, '/').'\/[A-Za-z0-9._-]+\z/';

        if (
            ! \is_string($path)
            || ! preg_match($ownedPathPattern, $path)
            || \in_array(basename($path), ['.', '..'], true)
            || ! Storage::disk('public')->exists($path)
        ) {
            return asset('assets/menu-item-placeholder.svg');
        }

        return route('public.uploads.show', [
            'directory' => 'restaurant-menu-categories',
            'restaurantId' => $this->restaurant_id,
            'filename' => basename($path),
        ]);
    }
}
