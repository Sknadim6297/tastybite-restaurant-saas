<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_plan_id')->constrained('subscription_plans')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('super_admins')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('super_admins')->nullOnDelete();
            $table->foreignId('rejected_by')->nullable()->constrained('super_admins')->nullOnDelete();
            $table->foreignId('restaurant_subscription_id')->nullable()->constrained('restaurant_subscriptions')->nullOnDelete();
            $table->string('plan_name');
            $table->string('plan_code');
            $table->decimal('amount', 10, 2);
            $table->char('currency', 3)->default('INR');
            $table->string('payment_method', 30);
            $table->string('transaction_reference')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->dateTime('paid_at');
            $table->text('notes')->nullable();
            $table->string('proof_path')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('rejected_at')->nullable();
            $table->timestamps();

            $table->unique(['payment_method', 'transaction_reference'], 'subscription_payments_method_reference_unique');
            $table->index(['restaurant_id', 'status', 'paid_at'], 'subscription_payments_restaurant_status_date_idx');
            $table->index(['subscription_plan_id', 'status'], 'subscription_payments_plan_status_idx');
        });

        Schema::create('subscription_payment_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_payment_id')->constrained('subscription_payments')->restrictOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('super_admins')->nullOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('method', 30);
            $table->string('reference')->nullable();
            $table->dateTime('refunded_at');
            $table->text('reason');
            $table->timestamps();

            $table->unique(['method', 'reference'], 'subscription_payment_refunds_method_reference_unique');
            $table->index(['subscription_payment_id', 'refunded_at'], 'subscription_payment_refunds_payment_date_idx');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Financial payment and refund records are retained permanently; this migration cannot be rolled back.');
    }
};
