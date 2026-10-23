<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantTableGroupMember extends Model
{
    protected $fillable = ['group_id', 'table_id'];

    public function table(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class, 'table_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(RestaurantTableGroup::class, 'group_id');
    }
}
