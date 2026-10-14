<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('restaurant_menu_categories', 'icon')) {
            Schema::table('restaurant_menu_categories', function (Blueprint $table): void {
                $table->string('icon', 100)->nullable();
            });
        }

        if (! Schema::hasColumn('restaurant_menu_categories', 'image_path')) {
            Schema::table('restaurant_menu_categories', function (Blueprint $table): void {
                $table->string('image_path', 255)->nullable();
            });
        }
    }

    public function down(): void
    {
        $columns = array_values(array_filter([
            Schema::hasColumn('restaurant_menu_categories', 'icon') ? 'icon' : null,
            Schema::hasColumn('restaurant_menu_categories', 'image_path') ? 'image_path' : null,
        ]));

        if ($columns !== []) {
            Schema::table('restaurant_menu_categories', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }
};
