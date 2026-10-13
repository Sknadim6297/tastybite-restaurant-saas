<?php

namespace Tests\Feature;

use App\Models\SuperAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SuperAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_cli_command_creates_a_hashed_super_admin_and_is_idempotent(): void
    {
        $this->artisan('super-admin:create')
            ->expectsQuestion('Name', 'CLI Administrator')
            ->expectsQuestion('Email', 'cli-admin@example.com')
            ->expectsQuestion('Password', 'SecureCliPassword1!')
            ->expectsQuestion('Confirm password', 'SecureCliPassword1!')
            ->expectsOutput('Super Admin account created.')
            ->assertExitCode(0);

        $admin = SuperAdmin::where('email', 'cli-admin@example.com')->firstOrFail();
        $originalHash = $admin->getRawOriginal('password');

        $this->assertTrue(Hash::check('SecureCliPassword1!', $originalHash));

        $this->artisan('super-admin:create')
            ->expectsQuestion('Name', 'Different Name')
            ->expectsQuestion('Email', 'cli-admin@example.com')
            ->expectsOutput('A Super Admin with that email already exists. No account was changed.')
            ->assertExitCode(0);

        $this->assertSame($originalHash, $admin->fresh()->getRawOriginal('password'));
    }

    public function test_cli_command_can_disable_a_super_admin_after_confirmation(): void
    {
        $admin = SuperAdmin::create([
            'name' => 'CLI Administrator',
            'email' => 'cli-admin@example.com',
            'password' => 'SecureCliPassword1!',
            'is_active' => true,
        ]);

        $this->artisan('super-admin:disable', ['email' => $admin->email])
            ->expectsConfirmation('Disable this Super Admin account?', 'yes')
            ->expectsOutput('Super Admin account disabled.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('super_admins', [
            'id' => $admin->id,
            'is_active' => false,
        ]);
    }
}
