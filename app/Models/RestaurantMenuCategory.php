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
        'cup-hot' => 'Hot drinks',
        'cup-straw' => 'Drinks',
        'egg-fried' => 'Breakfast',
        'fire' => 'Grill',
        'fish' => 'Fish',
        'flower1' => 'Desserts',
        'grid' => 'General',
        'leaf' => 'Vegetarian',
        'stars' => 'Specials',
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

        return asset('storage/'.$path);
    }
}
