<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table): void {
            $table->id();
            $table->string('ticket_number', 36)->unique();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_admin_id')->nullable()->constrained('super_admins')->nullOnDelete();
            $table->string('subject', 180);
            $table->string('category', 40);
            $table->string('priority', 20)->default('normal');
            $table->string('status', 32)->default('open');
            $table->timestamp('last_activity_at')->useCurrent();
            $table->timestamps();

            $table->index(['restaurant_id', 'status', 'last_activity_at'], 'support_tenant_status_activity_idx');
            $table->index(['status', 'priority', 'last_activity_at'], 'support_queue_status_priority_idx');
            $table->index(['assigned_admin_id', 'status'], 'support_assignee_status_idx');
        });

        Schema::create('support_ticket_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->restrictOnDelete();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('author_super_admin_id')->nullable()->constrained('super_admins')->nullOnDelete();
            $table->string('visibility', 16)->default('public');
            $table->text('body');
            $table->string('attachment_path', 500)->nullable();
            $table->string('attachment_name', 255)->nullable();
            $table->string('attachment_mime', 120)->nullable();
            $table->timestamps();

            $table->index(['support_ticket_id', 'visibility', 'created_at'], 'support_message_thread_idx');
        });

        Schema::create('support_ticket_status_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actor_super_admin_id')->nullable()->constrained('super_admins')->nullOnDelete();
            $table->string('previous_status', 32)->nullable();
            $table->string('new_status', 32);
            $table->string('reason', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['support_ticket_id', 'created_at'], 'support_status_history_idx');
        });

        Schema::create('restaurant_support_ticket_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('support_ticket_id')->constrained()->restrictOnDelete();
            $table->string('event_key', 191);
            $table->string('title', 180);
            $table->text('description');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'event_key'], 'restaurant_support_notice_event_unique');
            $table->index(['user_id', 'read_at', 'created_at'], 'restaurant_support_notice_inbox_idx');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Support ticket conversations and history are retained to preserve the support record.');
    }
};
