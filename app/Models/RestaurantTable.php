<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RestaurantTable extends Model
{
    protected $table = 'restaurant_tables';

    protected $fillable = [
        'restaurant_id', 'floor_id', 'section_id', 'table_number', 'name', 'capacity',
        'shape', 'type', 'description', 'sort_order', 'is_active', 'status', 'archived_at',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'archived_at' => 'immutable_datetime'];
    }

    public function floor(): BelongsTo
    {
        return $this->belongsTo(RestaurantFloor::class, 'floor_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(RestaurantSection::class, 'section_id');
    }

    public function occupancies(): HasMany
    {
        return $this->hasMany(RestaurantTableOccupancy::class, 'table_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(RestaurantTableReservation::class, 'table_id');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(RestaurantTableStatusHistory::class, 'table_id')->orderByDesc('changed_at');
    }

    public function activeOccupancy()
    {
        return $this->hasOne(RestaurantTableOccupancy::class, 'table_id')->whereNull('ended_at');
    }

    public function activeOrder()
    {
        return $this->hasOneThrough(
            RestaurantPosOrder::class,
            RestaurantTableOccupancy::class,
            'table_id',
            'id',
            'id',
            'pos_order_id'
        )->whereNull('restaurant_table_occupancies.ended_at')->where('restaurant_pos_orders.status', 'completed');
    }
}
