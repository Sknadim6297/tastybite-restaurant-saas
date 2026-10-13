<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('group', 40);
            $table->string('key', 80)->unique();
            $table->longText('value');
            $table->timestamps();

            $table->index(['group', 'key'], 'platform_settings_group_key_idx');
        });

        Schema::create('super_admin_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('super_admin_id')->constrained('super_admins')->cascadeOnDelete();
            $table->string('event_key', 191);
            $table->string('category', 40);
            $table->string('title', 180);
            $table->text('description');
            $table->string('url', 500)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['super_admin_id', 'event_key'], 'super_admin_notification_event_unique');
            $table->index(['super_admin_id', 'read_at', 'created_at'], 'super_admin_notification_inbox_idx');
        });

        Schema::table('restaurant_audit_logs', function (Blueprint $table): void {
            $table->string('ip_address', 45)->nullable();
            $table->foreignId('restaurant_id')->nullable()->constrained()->nullOnDelete();
            $table->index('created_at', 'restaurant_audit_created_at_idx');
            $table->index('action', 'restaurant_audit_action_idx');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Platform settings, notifications, and audit history are retained permanently.');
    }
};
