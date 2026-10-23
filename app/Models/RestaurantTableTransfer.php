<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantTableTransfer extends Model
{
    public $timestamps = false;

    protected $fillable = ['restaurant_id', 'source_table_id', 'destination_table_id', 'pos_order_id', 'actor_id', 'actor_admin_id', 'reason', 'transferred_at'];

    protected function casts(): array
    {
        return ['transferred_at' => 'immutable_datetime'];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class, 'source_table_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class, 'destination_table_id');
    }
}
