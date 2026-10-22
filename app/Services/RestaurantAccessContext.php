<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

class RestaurantAccessContext
{
    public function owner(): User
    {
        $supportOwner = request()->attributes->get('restaurant_support_owner');
        $user = $supportOwner instanceof User ? $supportOwner : Auth::guard('web')->user();

        abort_unless($user instanceof User && $user->isOwner() && $user->restaurant, 403);

        return $user;
    }

    public function isSupportAccess(): bool
    {
        return request()->attributes->get('restaurant_support_request') !== null;
    }

    public function isSuperAdminAccess(): bool
    {
        return request()->attributes->get('restaurant_admin_access_restaurant') !== null
            && request()->attributes->get('restaurant_support_admin') !== null;
    }
}
