<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantTableStatusHistory extends Model
{
    protected $table = 'restaurant_table_status_history';

    public $timestamps = false;

    protected $fillable = ['restaurant_id', 'table_id', 'actor_id', 'actor_admin_id', 'actor_type', 'from_status', 'to_status', 'reason', 'changed_at'];

    protected function casts(): array
    {
        return ['changed_at' => 'immutable_datetime'];
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class, 'table_id');
    }
}
