<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('inventory_settings', 'low_stock_alerts_enabled')) {
            Schema::table('inventory_settings', function (Blueprint $table): void {
                $table->boolean('low_stock_alerts_enabled')->default(true);
            });
        }

        if (! Schema::hasColumn('inventory_settings', 'expiry_alerts_enabled')) {
            Schema::table('inventory_settings', function (Blueprint $table): void {
                $table->boolean('expiry_alerts_enabled')->default(true);
            });
        }
    }

    public function down(): void
    {
        $columns = array_values(array_filter(
            ['low_stock_alerts_enabled', 'expiry_alerts_enabled'],
            static fn (string $column): bool => Schema::hasColumn('inventory_settings', $column),
        ));

        if ($columns !== []) {
            Schema::table('inventory_settings', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }
};
