<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantApprovalHistory extends Model
{
    protected $table = 'restaurant_approval_history';

    public $timestamps = false;

    protected $fillable = [
        'restaurant_id',
        'actor_id',
        'previous_status',
        'new_status',
        'reason',
        'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class, 'actor_id');
    }
}
