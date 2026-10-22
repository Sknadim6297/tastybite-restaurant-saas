<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createTableIfMissing('inventory_units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->string('name', 80);
            $table->string('abbreviation', 16);
            $table->string('dimension', 32);
            $table->string('status', 16)->default('active');
            $table->timestamps();
            $table->unique(['restaurant_id', 'abbreviation'], 'inventory_units_rest_code_unique');
            $table->index(['restaurant_id', 'dimension'], 'inventory_units_rest_dimension_idx');
        });

        $this->createTableIfMissing('inventory_unit_conversions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('from_unit_id')->constrained('inventory_units')->restrictOnDelete();
            $table->foreignId('to_unit_id')->constrained('inventory_units')->restrictOnDelete();
            $table->decimal('factor', 18, 9);
            $table->timestamps();
            $table->unique(['restaurant_id', 'from_unit_id', 'to_unit_id'], 'inventory_conversion_pair_unique');
        });

        $this->createTableIfMissing('inventory_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();
            $table->unique(['restaurant_id', 'name'], 'inventory_categories_rest_name_unique');
            $table->index(['restaurant_id', 'status', 'name'], 'inventory_categories_rest_status_name_idx');
        });

        $this->createTableIfMissing('inventory_locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->string('code', 24);
            $table->text('description')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();
            $table->unique(['restaurant_id', 'code'], 'inventory_locations_rest_code_unique');
            $table->unique(['restaurant_id', 'name'], 'inventory_locations_rest_name_unique');
        });

        $this->createTableIfMissing('inventory_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->constrained('inventory_categories')->restrictOnDelete();
            $table->foreignId('base_unit_id')->constrained('inventory_units')->restrictOnDelete();
            $table->foreignId('purchase_unit_id')->nullable()->constrained('inventory_units')->restrictOnDelete();
            $table->foreignId('consumption_unit_id')->nullable()->constrained('inventory_units')->restrictOnDelete();
            $table->foreignId('preferred_vendor_id')->nullable()->constrained('vendors')->restrictOnDelete();
            $table->foreignId('default_location_id')->nullable()->constrained('inventory_locations')->restrictOnDelete();
            $table->string('item_code', 24);
            $table->string('name', 180);
            $table->string('sku', 80)->nullable();
            $table->text('description')->nullable();
            $table->decimal('reorder_level', 18, 6)->default(0);
            $table->decimal('reorder_quantity', 18, 6)->default(0);
            $table->decimal('standard_cost', 18, 6)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->boolean('track_expiry')->default(false);
            $table->boolean('track_batches')->default(false);
            $table->string('status', 16)->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['restaurant_id', 'item_code'], 'inventory_items_rest_code_unique');
            $table->unique(['restaurant_id', 'sku'], 'inventory_items_rest_sku_unique');
            $table->index(['restaurant_id', 'category_id', 'status'], 'inventory_items_rest_category_status_idx');
        });

        $this->createTableIfMissing('inventory_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('default_location_id')->nullable()->constrained('inventory_locations')->restrictOnDelete();
            $table->unsignedSmallInteger('expiry_warning_days')->default(30);
            $table->string('deduction_event', 24)->default('sale_completed');
            $table->boolean('allow_negative_stock')->default(false);
            $table->string('valuation_method', 24)->default('weighted_average');
            $table->boolean('consume_recipes')->default(true);
            $table->boolean('low_stock_alerts_enabled')->default(true);
            $table->boolean('expiry_alerts_enabled')->default(true);
            $table->timestamps();
        });

        $this->createTableIfMissing('inventory_stock_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->decimal('quantity', 18, 6)->default(0);
            $table->decimal('average_unit_cost', 18, 6)->default(0);
            $table->timestamps();
            $table->unique(['restaurant_id', 'inventory_item_id', 'location_id'], 'inventory_balance_scope_unique');
            $table->index(['restaurant_id', 'location_id', 'quantity'], 'inventory_balance_location_qty_idx');
        });

        $this->createTableIfMissing('inventory_item_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->restrictOnDelete();
            $table->string('batch_number', 80);
            $table->date('manufactured_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->decimal('received_quantity', 18, 6);
            $table->decimal('available_quantity', 18, 6);
            $table->decimal('unit_cost', 18, 6)->default(0);
            $table->timestamps();
            $table->unique(['restaurant_id', 'inventory_item_id', 'location_id', 'batch_number'], 'inventory_batch_scope_number_unique');
            $table->index(['restaurant_id', 'expires_at', 'available_quantity'], 'inventory_batches_expiry_qty_idx');
        });

        $this->createTableIfMissing('inventory_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('inventory_item_batches')->restrictOnDelete();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversal_of_id')->nullable()->constrained('inventory_movements')->restrictOnDelete();
            $table->string('movement_number', 32);
            $table->string('movement_type', 32);
            $table->string('reason', 64)->nullable();
            $table->decimal('quantity_in', 18, 6)->default(0);
            $table->decimal('quantity_out', 18, 6)->default(0);
            $table->decimal('balance_after', 18, 6);
            $table->decimal('unit_cost', 18, 6)->default(0);
            $table->decimal('value', 18, 6)->default(0);
            $table->string('source_type', 64)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('source_line_id')->nullable();
            $table->string('idempotency_key', 120);
            $table->string('reference', 120)->nullable();
            $table->dateTime('occurred_at');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['restaurant_id', 'idempotency_key'], 'inventory_movements_rest_idempotency_unique');
            $table->unique(['restaurant_id', 'movement_number'], 'inventory_movements_rest_number_unique');
            $table->unique('reversal_of_id', 'inventory_movements_reversal_once_unique');
            $table->index(['restaurant_id', 'inventory_item_id', 'occurred_at'], 'inventory_movements_rest_item_date_idx');
            $table->index(['restaurant_id', 'location_id', 'occurred_at'], 'inventory_movements_rest_location_date_idx');
            $table->index(['restaurant_id', 'source_type', 'source_id'], 'inventory_movements_rest_source_idx');
        });

        $this->createTableIfMissing('inventory_stock_transfers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->foreignId('source_location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->foreignId('destination_location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('transfer_number', 32);
            $table->string('idempotency_key', 120);
            $table->decimal('quantity', 18, 6);
            $table->dateTime('transferred_at');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['restaurant_id', 'idempotency_key'], 'inventory_transfer_rest_idempotency_unique');
            $table->unique(['restaurant_id', 'transfer_number'], 'inventory_transfer_rest_number_unique');
        });

        $this->createTableIfMissing('inventory_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->foreignId('movement_id')->nullable()->constrained('inventory_movements')->restrictOnDelete();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('adjustment_number', 32);
            $table->decimal('expected_quantity', 18, 6);
            $table->decimal('actual_quantity', 18, 6);
            $table->decimal('difference', 18, 6);
            $table->string('reason', 32);
            $table->string('idempotency_key', 120);
            $table->dateTime('adjusted_at');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['restaurant_id', 'idempotency_key'], 'inventory_adjustments_rest_idempotency_unique');
            $table->unique(['restaurant_id', 'adjustment_number'], 'inventory_adjustments_rest_number_unique');
        });

        $this->createTableIfMissing('inventory_stock_counts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('count_number', 32);
            $table->string('status', 16)->default('draft');
            $table->string('idempotency_key', 120);
            $table->dateTime('counted_at');
            $table->dateTime('posted_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['restaurant_id', 'idempotency_key'], 'inventory_counts_rest_idempotency_unique');
            $table->unique(['restaurant_id', 'count_number'], 'inventory_counts_rest_number_unique');
        });

        $this->createTableIfMissing('inventory_stock_count_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_count_id')->constrained('inventory_stock_counts')->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->decimal('expected_quantity', 18, 6);
            $table->decimal('actual_quantity', 18, 6)->nullable();
            $table->string('batch_number', 80)->nullable();
            $table->date('manufactured_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['stock_count_id', 'inventory_item_id'], 'inventory_count_item_unique');
        });

        $this->createTableIfMissing('inventory_wastage_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('inventory_item_batches')->restrictOnDelete();
            $table->foreignId('movement_id')->nullable()->constrained('inventory_movements')->restrictOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('wastage_number', 32);
            $table->decimal('quantity', 18, 6);
            $table->decimal('cost', 18, 6)->default(0);
            $table->string('reason', 32);
            $table->string('idempotency_key', 120);
            $table->dateTime('wasted_at');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['restaurant_id', 'idempotency_key'], 'inventory_wastage_rest_idempotency_unique');
            $table->unique(['restaurant_id', 'wastage_number'], 'inventory_wastage_rest_number_unique');
        });

        $this->createTableIfMissing('menu_recipes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('menu_item_id')->constrained('restaurant_menu_items')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->decimal('yield_quantity', 18, 6)->default(1);
            $table->boolean('is_active')->default(false);
            $table->text('preparation_notes')->nullable();
            $table->timestamps();
            $table->unique(['restaurant_id', 'menu_item_id', 'version'], 'menu_recipes_rest_product_version_unique');
            $table->index(['restaurant_id', 'menu_item_id', 'is_active'], 'menu_recipes_rest_active_idx');
        });

        $this->createTableIfMissing('menu_recipe_ingredients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('menu_recipe_id')->constrained('menu_recipes')->restrictOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('inventory_units')->restrictOnDelete();
            $table->decimal('quantity', 18, 6);
            $table->decimal('base_quantity', 18, 6);
            $table->timestamps();
            $table->unique(['menu_recipe_id', 'inventory_item_id'], 'menu_recipe_ingredient_unique');
        });

        $this->createTableIfMissing('inventory_vendor_item_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained('vendor_purchase_order_items')->restrictOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('inventory_units')->restrictOnDelete();
            $table->decimal('base_quantity_per_unit', 18, 9);
            $table->timestamps();
            $table->unique(['restaurant_id', 'purchase_order_item_id'], 'inventory_vendor_mapping_po_item_unique');
        });

        $this->createTableIfMissing('inventory_grn_item_details', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('receipt_item_id')->unique()->constrained('vendor_goods_receipt_items')->restrictOnDelete();
            $table->string('batch_number', 80)->nullable();
            $table->date('manufactured_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->timestamps();
        });

        $this->createTableIfMissing('restaurant_pos_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('order_number', 32);
            $table->string('status', 16)->default('draft');
            $table->string('idempotency_key', 120);
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount', 18, 2)->default(0);
            $table->decimal('tax', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamps();
            $table->unique(['restaurant_id', 'idempotency_key'], 'pos_orders_rest_idempotency_unique');
            $table->unique(['restaurant_id', 'order_number'], 'pos_orders_rest_number_unique');
        });

        $this->createTableIfMissing('restaurant_pos_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('pos_order_id')->constrained('restaurant_pos_orders')->restrictOnDelete();
            $table->foreignId('menu_item_id')->nullable()->constrained('restaurant_menu_items')->nullOnDelete();
            $table->foreignId('menu_recipe_id')->nullable()->constrained('menu_recipes')->restrictOnDelete();
            $table->unsignedInteger('recipe_version')->nullable();
            $table->string('item_name', 180);
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 18, 2);
            $table->timestamps();
            $table->index(['restaurant_id', 'pos_order_id'], 'pos_order_items_rest_order_idx');
        });

        $this->createTableIfMissing('restaurant_pos_order_item_ingredients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained('restaurant_pos_order_items')->restrictOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('inventory_units')->restrictOnDelete();
            $table->decimal('quantity_per_serving', 18, 6);
            $table->timestamps();
            $table->index(['restaurant_id', 'order_item_id'], 'pos_order_ingredients_rest_order_idx');
        });
    }

    private function createTableIfMissing(string $name, Closure $definition): void
    {
        if (! Schema::hasTable($name)) {
            Schema::create($name, $definition);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Inventory movements and stock history are retained and cannot be rolled back automatically.');
    }
};
