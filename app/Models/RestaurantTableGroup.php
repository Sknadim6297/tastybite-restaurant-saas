<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RestaurantTableGroup extends Model
{
    protected $fillable = ['restaurant_id', 'created_by', 'created_by_admin_id', 'guest_count', 'ended_at'];

    protected function casts(): array
    {
        return ['ended_at' => 'immutable_datetime'];
    }

    public function members(): HasMany
    {
        return $this->hasMany(RestaurantTableGroupMember::class, 'group_id');
    }
}
