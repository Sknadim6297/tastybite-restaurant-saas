<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscription_payment_id')->unique()->constrained('subscription_payments')->restrictOnDelete();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_plan_id')->constrained('subscription_plans')->restrictOnDelete();
            $table->foreignId('restaurant_subscription_id')->nullable()->constrained('restaurant_subscriptions')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('super_admins')->nullOnDelete();
            $table->string('invoice_number', 40)->unique();
            $table->string('document_type', 32)->default('non_tax_payment_receipt');
            $table->decimal('subtotal', 10, 2);
            $table->decimal('tax_amount', 10, 2)->default(0);
            $table->decimal('total', 10, 2);
            $table->dateTime('issue_date');
            $table->dateTime('billing_period_start')->nullable();
            $table->dateTime('billing_period_end')->nullable();
            $table->timestamps();

            $table->index(['restaurant_id', 'issue_date'], 'subscription_invoice_restaurant_date_idx');
            $table->index('issue_date', 'subscription_invoice_issue_date_idx');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Issued payment receipts are retained permanently and cannot be rolled back.');
    }
};
