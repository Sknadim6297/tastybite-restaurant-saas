<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RestaurantPosPayment extends Model
{
    protected $fillable = [
        'restaurant_id',
        'pos_order_id',
        'recorded_by',
        'payment_reference',
        'idempotency_key',
        'amount',
        'method',
        'status',
        'recorded_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(RestaurantPosOrder::class, 'pos_order_id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(RestaurantPosRefund::class, 'payment_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
