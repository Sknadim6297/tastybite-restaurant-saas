<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Throwable;

class SystemHealthController extends Controller
{
    public function index(): View
    {
        $databaseStatus = 'healthy';
        $databaseNote = 'Connectivity check passed.';
        $databaseDriver = (string) config('database.default');
        $pendingMigrations = [];

        try {
            $connection = DB::connection();
            $databaseDriver = $connection->getDriverName();
            $connection->select('SELECT 1');
            if (Schema::hasTable('migrations')) {
                $applied = DB::table('migrations')->pluck('migration')->all();
                $available = collect(glob(database_path('migrations/*.php')) ?: [])
                    ->map(fn (string $path): string => pathinfo($path, PATHINFO_FILENAME));
                $pendingMigrations = $available->diff($applied)->values()->all();
            } else {
                $pendingMigrations = collect(glob(database_path('migrations/*.php')) ?: [])
                    ->map(fn (string $path): string => pathinfo($path, PATHINFO_FILENAME))->values()->all();
                $databaseStatus = 'attention';
                $databaseNote = 'Database is reachable, but the migration ledger table is missing.';
            }
        } catch (Throwable $exception) {
            $databaseStatus = 'unavailable';
            $databaseNote = 'Database connectivity check failed. Technical details were recorded in the application log.';
            Log::warning('System health database check failed.', [
                'exception_type' => $exception::class,
            ]);
        }

        if ($databaseStatus === 'healthy' && $pendingMigrations !== []) {
            $databaseStatus = 'attention';
            $databaseNote = count($pendingMigrations).' application migration(s) are pending. This page does not run migrations.';
        }

        $storagePath = config('filesystems.disks.local.root');
        $storageReady = is_string($storagePath) && is_dir($storagePath) && is_writable($storagePath);

        return view('super-admin.system-health.index', [
            'environment' => app()->environment(),
            'debugEnabled' => (bool) config('app.debug'),
            'applicationKeyConfigured' => filled(config('app.key')),
            'database' => [
                'status' => $databaseStatus,
                'note' => $databaseNote,
                'driver' => $databaseDriver,
                'pendingMigrations' => $pendingMigrations,
            ],
            'cacheDriver' => (string) config('cache.default'),
            'queueDriver' => (string) config('queue.default'),
            'mailDriver' => (string) config('mail.default'),
            'storage' => [
                'writable' => $storageReady,
            ],
        ]);
    }
}
