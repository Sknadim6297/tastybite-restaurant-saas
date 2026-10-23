<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('restaurant_table_occupancies') && ! Schema::hasColumn('restaurant_table_occupancies', 'group_id')) {
            Schema::table('restaurant_table_occupancies', function (Blueprint $table): void {
                $table->foreignId('group_id')->nullable()->after('table_id')->constrained('restaurant_table_groups')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('restaurant_table_occupancies') && Schema::hasColumn('restaurant_table_occupancies', 'group_id')) {
            Schema::table('restaurant_table_occupancies', function (Blueprint $table): void {
                $table->dropForeign(['group_id']);
                $table->dropColumn('group_id');
            });
        }
    }
};