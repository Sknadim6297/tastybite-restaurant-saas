<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_announcements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('created_by')->nullable()->constrained('super_admins')->nullOnDelete();
            $table->string('title', 180);
            $table->text('body');
            $table->string('audience', 20);
            $table->string('status', 20)->default('draft')->index();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'published_at', 'expires_at'], 'platform_announcements_visibility_idx');
        });

        Schema::create('platform_announcement_restaurants', function (Blueprint $table): void {
            $table->foreignId('platform_announcement_id')->constrained('platform_announcements')->restrictOnDelete();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->primary(['platform_announcement_id', 'restaurant_id'], 'announcement_restaurant_unique');
        });

        Schema::create('restaurant_announcement_reads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('platform_announcement_id')->constrained('platform_announcements')->restrictOnDelete();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->timestamp('read_at');
            $table->unique(['platform_announcement_id', 'restaurant_id'], 'announcement_restaurant_read_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_announcement_reads');
        Schema::dropIfExists('platform_announcement_restaurants');
        Schema::dropIfExists('platform_announcements');
    }
};
