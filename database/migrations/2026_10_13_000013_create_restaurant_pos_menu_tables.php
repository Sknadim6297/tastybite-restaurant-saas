<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('restaurant_menu_categories')) {
            Schema::create('restaurant_menu_categories', function (Blueprint $table) {
                $table->id();
                $table->foreignId('restaurant_id')->constrained('restaurants')->cascadeOnDelete();
                $table->string('name');
                $table->string('slug');
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->unique(['restaurant_id', 'slug']);
                $table->index(
                    ['restaurant_id', 'is_active', 'sort_order'],
                    'restaurant_menu_categories_rest_active_sort_idx',
                );
            });
        } else {
            $hasCategoryIndex = collect(Schema::getIndexes('restaurant_menu_categories'))
                ->contains(fn (array $index): bool => $index['columns'] === ['restaurant_id', 'is_active', 'sort_order']);

            if (! $hasCategoryIndex) {
                Schema::table('restaurant_menu_categories', function (Blueprint $table) {
                    $table->index(
                        ['restaurant_id', 'is_active', 'sort_order'],
                        'restaurant_menu_categories_rest_active_sort_idx',
                    );
                });
            }
        }

        if (! Schema::hasTable('restaurant_menu_items')) {
            Schema::create('restaurant_menu_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('restaurant_id')->constrained('restaurants')->cascadeOnDelete();
                $table->foreignId('category_id')->nullable()->constrained('restaurant_menu_categories')->nullOnDelete();
                $table->string('name');
                $table->text('description')->nullable();
                $table->decimal('price', 10, 2);
                $table->string('image_path')->nullable();
                $table->string('food_type', 30)->default('veg');
                $table->boolean('is_available')->default(true);
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(
                    ['restaurant_id', 'is_active', 'is_available'],
                    'restaurant_menu_items_rest_active_avail_idx',
                );
                $table->index(['category_id', 'is_active'], 'restaurant_menu_items_category_active_idx');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('The restaurant menu category table pre-dates this migration and cannot be safely rolled back.');
    }
};
