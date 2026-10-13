<?php

namespace App\Auth;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;

class SuperAdminUserProvider extends EloquentUserProvider
{
    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        $superAdmin = parent::retrieveByCredentials($credentials);

        return $superAdmin?->is_active ? $superAdmin : null;
    }
}
