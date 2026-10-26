<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantPosInvoice extends Model
{
    protected $fillable = [
        'restaurant_id', 'pos_order_id', 'invoice_number', 'issued_at',
        'restaurant_name', 'restaurant_address', 'restaurant_phone',
        'subtotal', 'discount', 'tax', 'total', 'payment_method', 'payment_reference',
        'cash_received', 'change_amount', 'upi_reference', 'card_last_four', 'card_reference',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'immutable_datetime',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax' => 'decimal:2',
            'total' => 'decimal:2',
            'cash_received' => 'decimal:2',
            'change_amount' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(RestaurantPosOrder::class, 'pos_order_id');
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }
}
