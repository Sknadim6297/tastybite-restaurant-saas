<?php

namespace App\Auth;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;

class RestaurantUserProvider extends EloquentUserProvider
{
    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        $user = parent::retrieveByCredentials($credentials);

        if (! $user) {
            return null;
        }

        // Must be active and associated with an active restaurant
        if ($user->status !== 'active') {
            return null;
        }

        if (! $user->restaurant || $user->restaurant->status !== 'active') {
            return null;
        }

        return $user;
    }
}
