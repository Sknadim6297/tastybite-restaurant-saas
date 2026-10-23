<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantPosRefund extends Model
{
    protected $fillable = [
        'restaurant_id',
        'pos_order_id',
        'payment_id',
        'recorded_by',
        'refund_reference',
        'idempotency_key',
        'amount',
        'status',
        'reason',
        'recorded_at',
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

    public function payment(): BelongsTo
    {
        return $this->belongsTo(RestaurantPosPayment::class, 'payment_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
