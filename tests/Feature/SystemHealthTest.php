<?php

namespace Tests\Feature;

use App\Models\SuperAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SystemHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_page_is_read_only_and_shows_runtime_status_without_secrets(): void
    {
        $admin = SuperAdmin::create([
            'name' => 'Health Admin',
            'email' => 'health-admin@example.test',
            'password' => 'StrongAdminPass1!',
            'is_active' => true,
        ]);
        $this->actingAs($admin, 'super_admin')
            ->withSession(['super_admin_password_version' => hash('sha256', $admin->getAuthPassword())]);
        $migrationCount = DB::table('migrations')->count();

        $this->get(route('super-admin.system-health.index'))
            ->assertOk()
            ->assertSee('System Health')
            ->assertSee('sqlite')
            ->assertSee('Configured (value hidden)')
            ->assertDontSee((string) config('app.key'));

        $this->assertSame($migrationCount, DB::table('migrations')->count());
    }
}
