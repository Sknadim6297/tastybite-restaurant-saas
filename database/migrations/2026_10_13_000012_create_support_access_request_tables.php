<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_access_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('requester_admin_id')->constrained('super_admins')->restrictOnDelete();
            $table->foreignId('reviewer_admin_id')->nullable()->constrained('super_admins')->nullOnDelete();
            $table->string('requested_scope', 40);
            $table->text('reason');
            $table->unsignedSmallInteger('requested_duration_minutes');
            $table->string('status', 20)->default('pending')->index();
            $table->text('review_reason')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['restaurant_id', 'created_at'], 'support_access_restaurant_date_idx');
            $table->index(['requester_admin_id', 'status'], 'support_access_requester_status_idx');
        });

        Schema::create('support_access_request_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('support_access_request_id')
                ->constrained('support_access_requests', 'id', 'support_access_event_request_fk')
                ->restrictOnDelete();
            $table->foreignId('actor_admin_id')->nullable()->constrained('super_admins')->nullOnDelete();
            $table->string('action', 24);
            $table->string('previous_status', 20)->nullable();
            $table->string('new_status', 20);
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['support_access_request_id', 'created_at'], 'support_access_events_history_idx');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Support-access requests and their audit history are retained and cannot be rolled back.');
    }
};
