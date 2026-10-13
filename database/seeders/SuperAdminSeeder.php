<?php

namespace Database\Seeders;

use App\Models\SuperAdmin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('SuperAdminSeeder may only run in the local environment.');
        }

        $table = 'super_admins';
        if (! Schema::hasTable($table)) {
            throw new RuntimeException('The super_admins table is missing. No changes were made.');
        }

        $requiredColumns = ['name', 'email', 'password', 'is_active'];
        $missingColumns = array_diff($requiredColumns, Schema::getColumnListing($table));
        if ($missingColumns !== []) {
            throw new RuntimeException(
                'The super_admins table is missing required columns: '.implode(', ', $missingColumns).'. No changes were made.'
            );
        }

        $email = 'superadmin@gmail.com';
        if (SuperAdmin::where('email', $email)->exists()) {
            $this->command?->info('The Super Admin account already exists. Its password and account status were not changed.');

            return;
        }

        $password = env('LOCAL_SUPER_ADMIN_PASSWORD');
        if (! is_string($password) || $password === '') {
            throw new RuntimeException('Set LOCAL_SUPER_ADMIN_PASSWORD in the local environment before running this seeder.');
        }

        $admin = SuperAdmin::firstOrCreate(
            ['email' => Str::lower($email)],
            [
                'name' => 'Super Admin',
                'password' => Hash::make($password),
                'is_active' => true,
            ],
        );

        if ($admin->wasRecentlyCreated) {
            $this->command?->info('Local Super Admin account created.');
        } else {
            $this->command?->info('The Super Admin account already exists. Its password and account status were not changed.');
        }
    }
}
