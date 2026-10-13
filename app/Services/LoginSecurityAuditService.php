<?php

namespace App\Services;

use App\Models\RestaurantAuditLog;
use Illuminate\Support\Str;
use LogicException;

class LoginSecurityAuditService
{
    public function recordFailure(string $guard, string $email): void
    {
        $targetType = match ($guard) {
            'super_admin' => 'super_admin',
            'restaurant' => 'restaurant_user',
            default => throw new LogicException('Unsupported login guard for security auditing.'),
        };
        $key = (string) config('app.key');
        if ($key === '') {
            throw new LogicException('Application key is required to create a private login identifier.');
        }

        RestaurantAuditLog::create([
            'actor_id' => null,
            'actor_type' => 'guest',
            'action' => $guard === 'super_admin' ? 'super_admin_login_failed' : 'restaurant_login_failed',
            'target_type' => $targetType,
            'target_id' => null,
            'details' => [
                'account_fingerprint' => hash_hmac('sha256', Str::lower(trim($email)), $key),
            ],
        ]);
    }
}
