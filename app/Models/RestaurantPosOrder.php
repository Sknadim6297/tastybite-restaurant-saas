<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RestaurantPosOrder extends Model
{
    protected $fillable = [
        'restaurant_id', 'created_by', 'order_number', 'status', 'idempotency_key',
        'subtotal', 'discount', 'tax', 'total', 'completed_at', 'cancelled_at',
        'order_type', 'table_number', 'guest_count', 'customer_name', 'customer_mobile',
        'delivery_address', 'notes', 'updated_by', 'payment_status', 'payment_method',
        'fulfillment_status', 'floor_id', 'section_id', 'table_id', 'original_table_id',
    ];

    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax' => 'decimal:2',
            'completed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(RestaurantPosOrderItem::class, 'pos_order_id');
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(RestaurantPosPayment::class, 'pos_order_id');
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(RestaurantPosInvoice::class, 'pos_order_id');
    }

    public function kot(): HasOne
    {
        return $this->hasOne(RestaurantPosKot::class, 'pos_order_id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(RestaurantPosRefund::class, 'pos_order_id');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(RestaurantPosOrderStatusHistory::class, 'pos_order_id')->orderBy('changed_at');
    }

    public function diningTable(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class, 'table_id');
    }

    public function diningFloor(): BelongsTo
    {
        return $this->belongsTo(RestaurantFloor::class, 'floor_id');
    }

    public function diningSection(): BelongsTo
    {
        return $this->belongsTo(RestaurantSection::class, 'section_id');
    }

    public function originalDiningTable(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class, 'original_table_id');
    }
}
