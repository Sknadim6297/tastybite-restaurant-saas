<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_pos_order_items', function (Blueprint $table): void {
            $table->string('special_instructions', 200)->nullable()->after('unit_price');
        });

        Schema::table('restaurant_pos_payments', function (Blueprint $table): void {
            $table->decimal('cash_received', 18, 2)->nullable()->after('amount');
            $table->decimal('change_amount', 18, 2)->nullable()->after('cash_received');
            $table->string('external_reference', 100)->nullable()->after('method');
            $table->string('card_last_four', 4)->nullable()->after('external_reference');
        });

        Schema::create('restaurant_pos_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('pos_order_id')->constrained('restaurant_pos_orders')->restrictOnDelete();
            $table->string('invoice_number', 32);
            $table->dateTime('issued_at');
            $table->string('restaurant_name');
            $table->text('restaurant_address')->nullable();
            $table->string('restaurant_phone')->nullable();
            $table->decimal('subtotal', 18, 2);
            $table->decimal('discount', 18, 2);
            $table->decimal('tax', 18, 2);
            $table->decimal('total', 18, 2);
            $table->string('payment_method', 16);
            $table->string('payment_reference', 48);
            $table->decimal('cash_received', 18, 2)->nullable();
            $table->decimal('change_amount', 18, 2)->nullable();
            $table->string('upi_reference', 100)->nullable();
            $table->string('card_last_four', 4)->nullable();
            $table->string('card_reference', 100)->nullable();
            $table->timestamps();
            $table->unique(['restaurant_id', 'invoice_number'], 'pos_invoices_rest_number_unique');
            $table->unique('pos_order_id', 'pos_invoices_order_unique');
        });

        Schema::create('restaurant_pos_kots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('pos_order_id')->constrained('restaurant_pos_orders')->restrictOnDelete();
            $table->string('kot_number', 32);
            $table->string('restaurant_name');
            $table->string('order_number', 32);
            $table->json('items');
            $table->string('order_type', 16);
            $table->string('table_number', 32)->nullable();
            $table->unsignedSmallInteger('reprint_count')->default(0);
            $table->dateTime('printed_at');
            $table->dateTime('last_printed_at');
            $table->timestamps();
            $table->unique(['restaurant_id', 'kot_number'], 'pos_kots_rest_number_unique');
            $table->unique('pos_order_id', 'pos_kots_order_unique');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('POS invoices and kitchen tickets are retained permanently.');
    }
};
