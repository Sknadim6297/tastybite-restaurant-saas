<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class RestaurantMenuItem extends Model
{
    use HasFactory;

    protected $table = 'restaurant_menu_items';

    protected $fillable = [
        'restaurant_id',
        'category_id',
        'name',
        'description',
        'price',
        'image_path',
        'food_type',
        'is_available',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_available' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(RestaurantMenuCategory::class, 'category_id');
    }

    public function getImageUrlAttribute(): string
    {
        $path = $this->image_path;
        $ownedPathPattern = '/\Arestaurant-menu\/'.preg_quote((string) $this->restaurant_id, '/').'\/[A-Za-z0-9._-]+\z/';

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
