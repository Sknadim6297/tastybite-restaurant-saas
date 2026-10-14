    <?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('subscription_plans')) {
            Schema::create('subscription_plans', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('code')->unique();
                $table->text('description')->nullable();
                $table->decimal('price', 10, 2);
                $table->char('currency', 3)->default('INR');
                $table->string('billing_period', 30);
                $table->unsignedSmallInteger('duration_months')->nullable();
                $table->unsignedSmallInteger('duration_days')->nullable();
                $table->boolean('trial_available')->default(false);
                $table->unsignedSmallInteger('trial_duration_days')->nullable();
                $table->string('status', 20)->default('active')->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('restaurant_subscriptions')) {
            Schema::create('restaurant_subscriptions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
                $table->foreignId('subscription_plan_id')->constrained('subscription_plans')->restrictOnDelete();
                $table->string('plan_name');
                $table->string('plan_code');
                $table->decimal('price', 10, 2);
                $table->char('currency', 3);
                $table->string('billing_period', 30);
                $table->unsignedSmallInteger('duration_months')->nullable();
                $table->unsignedSmallInteger('duration_days')->nullable();
                $table->string('status', 20)->index();
                $table->boolean('is_trial')->default(false);
                $table->dateTime('starts_at');
                $table->dateTime('expires_at');
                $table->foreignId('created_by')->nullable()->constrained('super_admins')->nullOnDelete();
                $table->foreignId('renewal_of_id')->nullable()->constrained('restaurant_subscriptions')->nullOnDelete();
                $table->timestamps();

                $table->index(['restaurant_id', 'status', 'starts_at', 'expires_at'], 'restaurant_subscription_access_idx');
            });
        }

        if (! Schema::hasTable('restaurant_subscription_events')) {
            Schema::create('restaurant_subscription_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
                $table->foreignId('restaurant_subscription_id')
                    ->nullable()
                    ->constrained('restaurant_subscriptions', 'id', 'subscription_events_subscription_foreign')
                    ->nullOnDelete();
                $table->foreignId('subscription_plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
                $table->foreignId('actor_id')->nullable()->constrained('super_admins')->nullOnDelete();
                $table->string('action', 40);
                $table->string('previous_status', 20)->nullable();
                $table->string('new_status', 20)->nullable();
                $table->dateTime('starts_at')->nullable();
                $table->dateTime('expires_at')->nullable();
                $table->json('details')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['restaurant_id', 'created_at'], 'restaurant_subscription_events_history_idx');
                $table->index(['restaurant_subscription_id', 'created_at'], 'subscription_events_history_idx');
            });
        }

        $this->assertColumns('subscription_plans', [
            'id', 'name', 'code', 'description', 'price', 'currency', 'billing_period',
            'duration_months', 'duration_days', 'trial_available', 'trial_duration_days',
            'status', 'created_at', 'updated_at',
        ]);
        $this->assertColumns('restaurant_subscriptions', [
            'id', 'restaurant_id', 'subscription_plan_id', 'plan_name', 'plan_code', 'price',
            'currency', 'billing_period', 'duration_months', 'duration_days', 'status',
            'is_trial', 'starts_at', 'expires_at', 'created_by', 'renewal_of_id',
            'created_at', 'updated_at',
        ]);
        $this->assertColumns('restaurant_subscription_events', [
            'id', 'restaurant_id', 'restaurant_subscription_id', 'subscription_plan_id',
            'actor_id', 'action', 'previous_status', 'new_status', 'starts_at', 'expires_at',
            'details', 'created_at',
        ]);

        $this->ensureIndex(
            'restaurant_subscription_events',
            ['restaurant_id', 'created_at'],
            'restaurant_subscription_events_history_idx',
        );
        $this->ensureIndex(
            'restaurant_subscription_events',
            ['restaurant_subscription_id', 'created_at'],
            'subscription_events_history_idx',
        );

        $this->ensureForeignKey(
            'restaurant_subscription_events',
            'restaurant_subscription_id',
            'restaurant_subscriptions',
            'subscription_events_subscription_foreign',
        );
        $this->ensureForeignKey(
            'restaurant_subscription_events',
            'subscription_plan_id',
            'subscription_plans',
            'subscription_events_plan_foreign',
        );
        $this->ensureForeignKey(
            'restaurant_subscription_events',
            'actor_id',
            'super_admins',
            'subscription_events_actor_foreign',
        );
    }

    public function down(): void
    {
        throw new RuntimeException('Subscription history is retained permanently; this migration cannot be rolled back.');
    }

    private function assertColumns(string $table, array $columns): void
    {
        if (! Schema::hasColumns($table, $columns)) {
            throw new RuntimeException("The existing {$table} table does not match the subscription schema.");
        }
    }

    private function ensureIndex(string $table, array $columns, string $name): void
    {
        foreach (Schema::getIndexes($table) as $index) {
            if ($index['name'] === $name) {
                return;
            }
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $name): void {
            $blueprint->index($columns, $name);
        });
    }

    private function ensureForeignKey(string $table, string $column, string $parentTable, string $name): void
    {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if (
                $foreignKey['columns'] === [$column]
                && $foreignKey['foreign_table'] === $parentTable
                && $foreignKey['foreign_columns'] === ['id']
            ) {
                return;
            }
        }

        $hasOrphans = DB::table($table.' as child')
            ->leftJoin($parentTable.' as parent', 'parent.id', '=', 'child.'.$column)
            ->whereNotNull('child.'.$column)
            ->whereNull('parent.id')
            ->exists();

        if ($hasOrphans) {
            throw new RuntimeException("Cannot add the {$table}.{$column} foreign key because orphaned records exist.");
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column, $parentTable, $name): void {
            $blueprint->foreign($column, $name)->references('id')->on($parentTable)->nullOnDelete();
        });
    }
};
