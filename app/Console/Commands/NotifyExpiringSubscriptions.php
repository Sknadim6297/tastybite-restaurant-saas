<?php

namespace App\Console\Commands;

use App\Services\SuperAdminNotificationService;
use App\Services\PlatformSettingsService;
use Illuminate\Console\Command;

class NotifyExpiringSubscriptions extends Command
{
    protected $signature = 'super-admin:notify-subscription-expiry';

    protected $description = 'Create idempotent in-app alerts for expiring and expired subscriptions';

    public function handle(
        PlatformSettingsService $settings,
        SuperAdminNotificationService $notifications,
    ): int
    {
        $settings->applyRuntime();
        $count = $notifications->subscriptionExpiryAlerts();
        $this->info("Created {$count} subscription alert event(s).");

        return self::SUCCESS;
    }
}
