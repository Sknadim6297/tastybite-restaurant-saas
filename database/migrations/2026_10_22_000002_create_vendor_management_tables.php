<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createTableIfMissing('vendors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->string('vendor_code', 24)->nullable();
            $table->string('name', 180);
            $table->string('contact_person', 120)->nullable();
            $table->string('mobile', 32);
            $table->string('alternate_mobile', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('category', 80)->nullable();
            $table->string('status', 20)->default('active');
            $table->string('business_name', 180)->nullable();
            $table->string('gstin', 32)->nullable();
            $table->string('pan_number', 20)->nullable();
            $table->string('registration_number', 80)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->default(0);
            $table->decimal('credit_limit', 12, 2)->nullable();
            $table->string('address_line_1', 180)->nullable();
            $table->string('address_line_2', 180)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('country', 100)->default('India');
            $table->string('account_holder_name', 180)->nullable();
            $table->string('bank_name', 120)->nullable();
            $table->text('account_number')->nullable();
            $table->string('ifsc_code', 20)->nullable();
            $table->string('bank_branch', 120)->nullable();
            $table->string('upi_id', 120)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['restaurant_id', 'vendor_code'], 'vendors_restaurant_code_unique');
            $table->unique(['restaurant_id', 'mobile'], 'vendors_restaurant_mobile_unique');
            $table->index(['restaurant_id', 'status', 'name'], 'vendors_restaurant_status_name_idx');
            $table->index(['restaurant_id', 'category'], 'vendors_restaurant_category_idx');
        });

        $this->createTableIfMissing('vendor_financial_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->string('adjustment_reference', 32)->unique();
            $table->string('entry_type', 24);
            $table->decimal('amount', 12, 2);
            $table->date('entry_date');
            $table->string('description', 255);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['restaurant_id', 'vendor_id', 'entry_date'], 'vendor_adj_rest_vendor_date_idx');
        });

        $this->createTableIfMissing('vendor_purchase_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->string('po_number', 24)->nullable();
            $table->date('order_date');
            $table->date('expected_delivery_date')->nullable();
            $table->string('status', 24)->default('draft');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->decimal('tax_total', 12, 2)->default(0);
            $table->decimal('grand_total', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['restaurant_id', 'po_number'], 'vendor_po_restaurant_number_unique');
            $table->index(['restaurant_id', 'status', 'order_date'], 'vendor_po_rest_status_date_idx');
            $table->index(['restaurant_id', 'vendor_id', 'order_date'], 'vendor_po_rest_vendor_date_idx');
        });

        $this->createTableIfMissing('vendor_purchase_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_id')->constrained('vendor_purchase_orders')->cascadeOnDelete();
            $table->string('item_name', 180);
            $table->decimal('quantity', 12, 3);
            $table->string('unit', 32);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('line_total', 12, 2)->default(0);
            $table->timestamps();

            $table->index(['restaurant_id', 'purchase_order_id'], 'vendor_po_items_rest_order_idx');
        });

        $this->createTableIfMissing('vendor_goods_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_id')->constrained('vendor_purchase_orders')->restrictOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->string('grn_number', 24)->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->date('received_date');
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['restaurant_id', 'grn_number'], 'vendor_grn_restaurant_number_unique');
            $table->unique(['restaurant_id', 'idempotency_key'], 'vendor_grn_restaurant_idempotency_unique');
            $table->index(['restaurant_id', 'vendor_id', 'received_date'], 'vendor_grn_rest_vendor_date_idx');
            $table->index(['restaurant_id', 'purchase_order_id'], 'vendor_grn_rest_order_idx');
        });

        $this->createTableIfMissing('vendor_goods_receipt_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('goods_receipt_id')->constrained('vendor_goods_receipts')->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained('vendor_purchase_order_items')->restrictOnDelete();
            $table->string('item_name', 180);
            $table->string('unit', 32);
            $table->decimal('ordered_quantity', 12, 3);
            $table->decimal('previously_received_quantity', 12, 3)->default(0);
            $table->decimal('received_quantity', 12, 3);
            $table->decimal('rejected_quantity', 12, 3)->default(0);
            $table->decimal('accepted_quantity', 12, 3);
            $table->timestamps();

            $table->index(['restaurant_id', 'purchase_order_item_id'], 'vendor_grn_item_rest_po_item_idx');
        });

        $this->createTableIfMissing('vendor_bills', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained('vendor_purchase_orders')->restrictOnDelete();
            $table->foreignId('goods_receipt_id')->nullable()->constrained('vendor_goods_receipts')->restrictOnDelete();
            $table->string('bill_number', 24)->nullable();
            $table->string('vendor_invoice_number', 100)->nullable();
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->decimal('taxable_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('status', 24)->default('draft');
            $table->string('attachment_path', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['restaurant_id', 'bill_number'], 'vendor_bill_restaurant_number_unique');
            $table->unique(['restaurant_id', 'vendor_id', 'vendor_invoice_number'], 'vendor_bill_invoice_unique');
            $table->index(['restaurant_id', 'status', 'due_date'], 'vendor_bill_rest_status_due_idx');
            $table->index(['restaurant_id', 'vendor_id', 'invoice_date'], 'vendor_bill_rest_vendor_date_idx');
        });

        $this->createTableIfMissing('vendor_bill_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('vendor_bill_id')->constrained('vendor_bills')->cascadeOnDelete();
            $table->string('item_name', 180);
            $table->decimal('quantity', 12, 3);
            $table->string('unit', 32);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('line_total', 12, 2)->default(0);
            $table->timestamps();

            $table->index(['restaurant_id', 'vendor_bill_id'], 'vendor_bill_items_rest_bill_idx');
        });

        $this->createTableIfMissing('vendor_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->string('payment_reference', 32)->nullable();
            $table->string('payment_type', 16)->default('payment');
            $table->foreignId('reverses_payment_id')->nullable()->constrained('vendor_payments')->restrictOnDelete();
            $table->date('payment_date');
            $table->decimal('amount', 12, 2);
            $table->string('payment_method', 24);
            $table->string('transaction_reference', 120)->nullable();
            $table->string('proof_path', 255)->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['restaurant_id', 'payment_reference'], 'vendor_payments_rest_ref_unique');
            $table->unique(['restaurant_id', 'idempotency_key'], 'vendor_payments_rest_idem_unique');
            $table->unique('reverses_payment_id', 'vendor_payments_reversal_once_unique');
            $table->index(['restaurant_id', 'vendor_id', 'payment_date'], 'vendor_payments_rest_vendor_date_idx');
        });

        $this->createTableIfMissing('vendor_payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('vendor_payment_id')->constrained('vendor_payments')->restrictOnDelete();
            $table->foreignId('vendor_bill_id')->constrained('vendor_bills')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->timestamps();

            $table->unique(['vendor_payment_id', 'vendor_bill_id'], 'vendor_payment_bill_allocation_unique');
            $table->index(['restaurant_id', 'vendor_bill_id'], 'vendor_allocations_rest_bill_idx');
        });
    }

    private function createTableIfMissing(string $name, \Closure $definition): void
    {
        if (! Schema::hasTable($name)) {
            Schema::create($name, $definition);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Vendor purchasing and financial history is retained and cannot be rolled back automatically.');
    }
};