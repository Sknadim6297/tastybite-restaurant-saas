<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saas_features', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->boolean('implemented')->default(false);
            $table->boolean('is_enabled')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('subscription_plan_features', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscription_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('saas_feature_id')->constrained('saas_features')->restrictOnDelete();
            $table->boolean('is_enabled')->default(false);
            $table->unsignedInteger('limit_value')->nullable();
            $table->timestamps();

            $table->unique(['subscription_plan_id', 'saas_feature_id'], 'plan_feature_unique');
            $table->index(['saas_feature_id', 'is_enabled'], 'plan_feature_access_idx');
        });

        $now = now();
        $features = [
            ['code' => 'pos', 'name' => 'Point of Sale', 'description' => 'Restaurant point-of-sale page. This is the only implemented restaurant operational module.', 'implemented' => true, 'is_enabled' => true, 'sort_order' => 10],
            ['code' => 'orders', 'name' => 'Orders', 'description' => 'Order management is not implemented yet.', 'implemented' => false, 'is_enabled' => false, 'sort_order' => 20],
            ['code' => 'tables', 'name' => 'Tables', 'description' => 'Table management is not implemented yet.', 'implemented' => false, 'is_enabled' => false, 'sort_order' => 30],
            ['code' => 'menu-management', 'name' => 'Menu Management', 'description' => 'Restaurant menu management is not implemented yet.', 'implemented' => false, 'is_enabled' => false, 'sort_order' => 40],
            ['code' => 'kitchen', 'name' => 'Kitchen / KOT', 'description' => 'Kitchen and KOT workflows are not implemented yet.', 'implemented' => false, 'is_enabled' => false, 'sort_order' => 50],
            ['code' => 'staff-management', 'name' => 'Staff Management', 'description' => 'Restaurant staff management is not implemented yet.', 'implemented' => false, 'is_enabled' => false, 'sort_order' => 60],
            ['code' => 'inventory', 'name' => 'Inventory', 'description' => 'Inventory management is not implemented yet.', 'implemented' => false, 'is_enabled' => false, 'sort_order' => 70],
            ['code' => 'customers', 'name' => 'Customers', 'description' => 'Customer management is not implemented yet.', 'implemented' => false, 'is_enabled' => false, 'sort_order' => 80],
            ['code' => 'expenses', 'name' => 'Expenses', 'description' => 'Expense management is not implemented yet.', 'implemented' => false, 'is_enabled' => false, 'sort_order' => 90],
            ['code' => 'reports', 'name' => 'Restaurant Reports', 'description' => 'Restaurant reporting is not implemented yet.', 'implemented' => false, 'is_enabled' => false, 'sort_order' => 100],
            ['code' => 'multi-branch', 'name' => 'Multi-Branch', 'description' => 'Multi-branch management is not implemented yet.', 'implemented' => false, 'is_enabled' => false, 'sort_order' => 110],
        ];

        foreach ($features as $feature) {
            DB::table('saas_features')->insert([
                ...$feature,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $posFeatureId = DB::table('saas_features')->where('code', 'pos')->value('id');
        $plans = DB::table('subscription_plans')->pluck('id');
        foreach ($plans as $planId) {
            DB::table('subscription_plan_features')->insert([
                'subscription_plan_id' => $planId,
                'saas_feature_id' => $posFeatureId,
                'is_enabled' => true,
                'limit_value' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Feature registry and plan-access configuration are retained to preserve platform access history.');
    }
};
