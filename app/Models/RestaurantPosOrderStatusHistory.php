<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantPosOrderStatusHistory extends Model
{
    protected $table = 'restaurant_pos_order_status_history';

    protected $fillable = [
        'restaurant_id',
        'pos_order_id',
        'actor_id',
        'actor_type',
        'from_status',
        'to_status',
        'reason',
        'changed_at',
    ];

    protected function casts(): array
    {
        return ['changed_at' => 'immutable_datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(RestaurantPosOrder::class, 'pos_order_id');
    }
}
