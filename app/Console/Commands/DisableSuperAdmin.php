<?php

namespace App\Console\Commands;

use App\Models\RestaurantAuditLog;
use App\Models\SuperAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class DisableSuperAdmin extends Command
{
    protected $signature = 'super-admin:disable {email}';

    protected $description = 'Disable a Super Admin account';

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $superAdmin = SuperAdmin::where('email', $email)->first();

        if (! $superAdmin) {
            $this->error('No Super Admin account was found for that email.');

            return self::FAILURE;
        }

        if (! $superAdmin->is_active) {
            $this->info('That Super Admin account is already disabled.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Disable this Super Admin account?')) {
            $this->info('No account was changed.');

            return self::SUCCESS;
        }

        $superAdmin->forceFill(['is_active' => false])->save();
        RestaurantAuditLog::create([
            'actor_type' => 'system',
            'action' => 'super_admin_disabled',
            'target_type' => 'super_admin',
            'target_id' => $superAdmin->id,
            'details' => [],
        ]);
        $this->info('Super Admin account disabled.');

        return self::SUCCESS;
    }
}
