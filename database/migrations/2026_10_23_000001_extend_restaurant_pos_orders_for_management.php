<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_pos_orders', function (Blueprint $table): void {
            $table->string('order_type', 16)->default('dine_in')->after('status');
            $table->string('table_number', 32)->nullable()->after('order_type');
            $table->unsignedSmallInteger('guest_count')->nullable()->after('table_number');
            $table->string('customer_name', 160)->nullable()->after('guest_count');
            $table->string('customer_mobile', 32)->nullable()->after('customer_name');
            $table->text('delivery_address')->nullable()->after('customer_mobile');
            $table->text('notes')->nullable()->after('delivery_address');
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->string('payment_status', 24)->default('unpaid')->after('total');
            $table->string('payment_method', 16)->nullable()->after('payment_status');
            $table->string('fulfillment_status', 24)->default('completed')->after('payment_method');
            $table->index(['restaurant_id', 'order_type', 'status', 'created_at'], 'pos_orders_rest_type_status_date_idx');
            $table->index(['restaurant_id', 'payment_status', 'created_at'], 'pos_orders_rest_payment_date_idx');
        });

        Schema::create('restaurant_pos_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('pos_order_id')->constrained('restaurant_pos_orders')->restrictOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('payment_reference', 48);
            $table->string('idempotency_key', 120);
            $table->decimal('amount', 18, 2);
            $table->string('method', 16);
            $table->string('status', 16)->default('recorded');
            $table->dateTime('recorded_at');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['restaurant_id', 'idempotency_key'], 'pos_payments_rest_idempotency_unique');
            $table->unique(['restaurant_id', 'payment_reference'], 'pos_payments_rest_reference_unique');
            $table->index(['restaurant_id', 'recorded_at'], 'pos_payments_rest_date_idx');
            $table->index(['restaurant_id', 'pos_order_id'], 'pos_payments_rest_order_idx');
        });

        Schema::create('restaurant_pos_refunds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('pos_order_id')->constrained('restaurant_pos_orders')->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('restaurant_pos_payments')->restrictOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('refund_reference', 48);
            $table->string('idempotency_key', 120);
            $table->decimal('amount', 18, 2);
            $table->string('status', 16)->default('recorded');
            $table->text('reason');
            $table->dateTime('recorded_at');
            $table->timestamps();
            $table->unique(['restaurant_id', 'idempotency_key'], 'pos_refunds_rest_idempotency_unique');
            $table->unique(['restaurant_id', 'refund_reference'], 'pos_refunds_rest_reference_unique');
            $table->index(['restaurant_id', 'pos_order_id'], 'pos_refunds_rest_order_idx');
        });

        Schema::create('restaurant_pos_order_status_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('pos_order_id')->constrained('restaurant_pos_orders')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_type', 24);
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->text('reason')->nullable();
            $table->dateTime('changed_at');
            $table->timestamps();
            $table->index(['restaurant_id', 'pos_order_id', 'changed_at'], 'pos_order_status_history_order_idx');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Restaurant POS payment, refund, and status history is retained permanently.');
    }
};
