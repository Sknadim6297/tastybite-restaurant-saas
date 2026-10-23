<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_floors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->string('code', 32);
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['restaurant_id', 'code'], 'restaurant_floor_code_unique');
            $table->index(['restaurant_id', 'is_active', 'display_order'], 'restaurant_floor_order_idx');
        });

        Schema::create('restaurant_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('floor_id')->constrained('restaurant_floors')->restrictOnDelete();
            $table->string('name', 100);
            $table->string('code', 32);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['restaurant_id', 'floor_id', 'code'], 'restaurant_section_code_unique');
            $table->index(['restaurant_id', 'floor_id', 'is_active'], 'restaurant_section_floor_idx');
        });

        Schema::create('restaurant_tables', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('floor_id')->constrained('restaurant_floors')->restrictOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('restaurant_sections')->restrictOnDelete();
            $table->string('table_number', 32);
            $table->string('name', 100)->nullable();
            $table->unsignedSmallInteger('capacity');
            $table->string('shape', 16)->default('rectangle');
            $table->string('type', 16)->default('standard');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('status', 24)->default('available');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['restaurant_id', 'table_number'], 'restaurant_table_number_unique');
            $table->index(['restaurant_id', 'floor_id', 'status', 'is_active'], 'restaurant_table_floor_status_idx');
            $table->index(['restaurant_id', 'section_id'], 'restaurant_table_section_idx');
        });

        Schema::table('restaurant_pos_orders', function (Blueprint $table): void {
            $table->foreignId('floor_id')->nullable()->after('table_number')->constrained('restaurant_floors')->restrictOnDelete();
            $table->foreignId('section_id')->nullable()->after('floor_id')->constrained('restaurant_sections')->restrictOnDelete();
            $table->foreignId('table_id')->nullable()->after('section_id')->constrained('restaurant_tables')->restrictOnDelete();
            $table->foreignId('original_table_id')->nullable()->after('table_id')->constrained('restaurant_tables')->restrictOnDelete();
            $table->index(['restaurant_id', 'table_id', 'status'], 'pos_orders_restaurant_table_idx');
        });

        Schema::create('restaurant_table_occupancies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('table_id')->constrained('restaurant_tables')->restrictOnDelete();
            $table->foreignId('pos_order_id')->nullable()->constrained('restaurant_pos_orders')->restrictOnDelete();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('started_by_admin_id')->nullable()->constrained('super_admins')->nullOnDelete();
            $table->foreignId('ended_by_admin_id')->nullable()->constrained('super_admins')->nullOnDelete();
            $table->unsignedSmallInteger('guest_count');
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->index(['restaurant_id', 'table_id', 'ended_at'], 'restaurant_table_occupancy_active_idx');
            $table->index(['restaurant_id', 'started_at', 'ended_at'], 'restaurant_table_occupancy_date_idx');
        });

        Schema::create('restaurant_table_status_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('table_id')->constrained('restaurant_tables')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actor_admin_id')->nullable()->constrained('super_admins')->nullOnDelete();
            $table->string('actor_type', 24)->default('restaurant_user');
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->text('reason')->nullable();
            $table->timestamp('changed_at');
            $table->index(['restaurant_id', 'table_id', 'changed_at'], 'restaurant_table_history_idx');
        });

        Schema::create('restaurant_table_transfers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_table_id')->constrained('restaurant_tables')->restrictOnDelete();
            $table->foreignId('destination_table_id')->constrained('restaurant_tables')->restrictOnDelete();
            $table->foreignId('pos_order_id')->nullable()->constrained('restaurant_pos_orders')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actor_admin_id')->nullable()->constrained('super_admins')->nullOnDelete();
            $table->text('reason');
            $table->timestamp('transferred_at');
            $table->index(['restaurant_id', 'transferred_at'], 'restaurant_table_transfer_date_idx');
        });

        Schema::create('restaurant_table_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('super_admins')->nullOnDelete();
            $table->unsignedSmallInteger('guest_count');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->index(['restaurant_id', 'ended_at'], 'restaurant_table_group_active_idx');
        });

        Schema::create('restaurant_table_group_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_id')->constrained('restaurant_table_groups')->restrictOnDelete();
            $table->foreignId('table_id')->constrained('restaurant_tables')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['group_id', 'table_id']);
        });

        Schema::table('restaurant_table_occupancies', function (Blueprint $table): void {
            $table->foreignId('group_id')->nullable()->after('table_id')->constrained('restaurant_table_groups')->restrictOnDelete();
        });

        Schema::create('restaurant_table_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('floor_id')->nullable()->constrained('restaurant_floors')->restrictOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('restaurant_sections')->restrictOnDelete();
            $table->foreignId('table_id')->nullable()->constrained('restaurant_tables')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('super_admins')->nullOnDelete();
            $table->string('reference', 32);
            $table->string('customer_name', 160);
            $table->string('phone', 32);
            $table->string('email', 190)->nullable();
            $table->dateTime('starts_at');
            $table->unsignedSmallInteger('duration_minutes')->default(120);
            $table->unsignedSmallInteger('party_size');
            $table->string('status', 20)->default('pending');
            $table->text('special_requests')->nullable();
            $table->text('internal_notes')->nullable();
            $table->timestamps();
            $table->unique(['restaurant_id', 'reference'], 'restaurant_reservation_reference_unique');
            $table->index(['restaurant_id', 'starts_at', 'status'], 'restaurant_reservation_schedule_idx');
            $table->index(['restaurant_id', 'table_id', 'starts_at'], 'restaurant_reservation_table_idx');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Restaurant table and reservation history is retained permanently.');
    }
};
