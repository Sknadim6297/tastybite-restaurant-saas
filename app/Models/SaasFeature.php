<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SaasFeature extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'implemented',
        'is_enabled',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'implemented' => 'boolean',
            'is_enabled' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(SubscriptionPlan::class, 'subscription_plan_features')
            ->withPivot(['is_enabled', 'limit_value'])
            ->withTimestamps();
    }
}
