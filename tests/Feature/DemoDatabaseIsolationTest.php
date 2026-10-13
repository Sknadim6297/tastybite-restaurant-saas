<?php

namespace Tests\Feature;

use Database\Seeders\TastyBiteDemoSeeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class DemoDatabaseIsolationTest extends TestCase
{
    public function test_phpunit_runtime_database_is_isolated_in_memory_sqlite(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }

    public function test_demo_seeder_refuses_non_local_environment_before_writing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('may only run in the local environment');

        app(TastyBiteDemoSeeder::class)->run();
    }
}
