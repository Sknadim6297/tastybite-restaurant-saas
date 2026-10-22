<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

class RestaurantAccessContext
{
    public function owner(): User
    {
        $adminAccessOwner = request()->attributes->get('restaurant_admin_access_owner');
        $user = $adminAccessOwner instanceof User ? $adminAccessOwner : Auth::guard('web')->user();

        abort_unless($user instanceof User && $user->isOwner() && $user->restaurant, 403);

        return $user;
    }

    public function isSuperAdminAccess(): bool
    {
        return request()->attributes->get('restaurant_admin_access_restaurant') !== null
            && request()->attributes->get('restaurant_admin_access_admin') !== null;
    }
}
