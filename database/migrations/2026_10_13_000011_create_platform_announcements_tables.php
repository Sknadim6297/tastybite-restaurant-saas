<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createIfMissing('platform_announcements', function (): void {
            Schema::create('platform_announcements', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('created_by')->nullable()->constrained('super_admins')->nullOnDelete();
                $table->string('title', 180);
                $table->text('body');
                $table->string('audience', 20);
                $table->string('status', 20)->default('draft')->index();
                $table->timestamp('published_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();

                $table->index(['status', 'published_at', 'expires_at'], 'platform_announcements_visibility_idx');
            });
        });

        $this->createIfMissing('platform_announcement_restaurants', function (): void {
            Schema::create('platform_announcement_restaurants', function (Blueprint $table): void {
                $table->foreignId('platform_announcement_id')
                    ->constrained('platform_announcements', 'id', 'announcement_restaurants_announcement_fk')
                    ->restrictOnDelete();
                $table->foreignId('restaurant_id')
                    ->constrained('restaurants', 'id', 'announcement_restaurants_restaurant_fk')
                    ->restrictOnDelete();
                $table->primary(['platform_announcement_id', 'restaurant_id'], 'announcement_restaurant_unique');
            });
        });

        $this->createIfMissing('restaurant_announcement_reads', function (): void {
            Schema::create('restaurant_announcement_reads', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('platform_announcement_id')
                    ->constrained('platform_announcements', 'id', 'announcement_reads_announcement_fk')
                    ->restrictOnDelete();
                $table->foreignId('restaurant_id')
                    ->constrained('restaurants', 'id', 'announcement_reads_restaurant_fk')
                    ->restrictOnDelete();
                $table->timestamp('read_at');
                $table->unique(['platform_announcement_id', 'restaurant_id'], 'announcement_restaurant_read_unique');
            });
        });

        $this->assertColumns('platform_announcements', [
            'id', 'created_by', 'title', 'body', 'audience', 'status', 'published_at', 'expires_at',
            'created_at', 'updated_at',
        ]);
        $this->assertColumns('platform_announcement_restaurants', ['platform_announcement_id', 'restaurant_id']);
        $this->assertColumns('restaurant_announcement_reads', [
            'id', 'platform_announcement_id', 'restaurant_id', 'read_at',
        ]);

        $this->ensureIndex('platform_announcements', ['status', 'published_at', 'expires_at'], 'platform_announcements_visibility_idx');
        $this->ensurePrimaryKey('platform_announcement_restaurants', ['platform_announcement_id', 'restaurant_id']);
        $this->ensureIndex('restaurant_announcement_reads', ['platform_announcement_id', 'restaurant_id'], 'announcement_restaurant_read_unique', true);

        $this->ensureForeignKey('platform_announcements', 'created_by', 'super_admins', 'set null');
        $this->ensureForeignKey('platform_announcement_restaurants', 'platform_announcement_id', 'platform_announcements', 'restrict', 'announcement_restaurants_announcement_fk');
        $this->ensureForeignKey('platform_announcement_restaurants', 'restaurant_id', 'restaurants', 'restrict', 'announcement_restaurants_restaurant_fk');
        $this->ensureForeignKey('restaurant_announcement_reads', 'platform_announcement_id', 'platform_announcements', 'restrict', 'announcement_reads_announcement_fk');
        $this->ensureForeignKey('restaurant_announcement_reads', 'restaurant_id', 'restaurants', 'restrict', 'announcement_reads_restaurant_fk');
    }

    public function down(): void
    {
        throw new RuntimeException('Platform announcements and their read history are retained and cannot be rolled back.');
    }

    private function createIfMissing(string $table, callable $create): void
    {
        if (! Schema::hasTable($table)) {
            $create();
        }
    }

    private function assertColumns(string $table, array $expected): void
    {
        if (Schema::getColumnListing($table) !== $expected) {
            throw new RuntimeException("The existing {$table} table does not match the announcement schema.");
        }
    }

    private function ensureIndex(string $table, array $columns, string $name, bool $unique = false): void
    {
        foreach (Schema::getIndexes($table) as $index) {
            if ($index['name'] === $name || ($index['columns'] === $columns && $index['unique'] === $unique)) {
                if ($index['columns'] !== $columns || $index['unique'] !== $unique) {
                    throw new RuntimeException("The existing {$name} index does not match the announcement schema.");
                }

                return;
            }
        }

        if ($unique && DB::table($table)->select($columns)->groupBy($columns)->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException("Cannot add the {$name} unique index because duplicate announcement records exist.");
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $name, $unique): void {
            if ($unique) {
                $blueprint->unique($columns, $name);
            } else {
                $blueprint->index($columns, $name);
            }
        });
    }

    private function ensurePrimaryKey(string $table, array $columns): void
    {
        foreach (Schema::getIndexes($table) as $index) {
            if ($index['primary']) {
                if ($index['columns'] !== $columns) {
                    throw new RuntimeException("The existing {$table} primary key does not match the announcement schema.");
                }

                return;
            }
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns): void {
            $blueprint->primary($columns);
        });
    }

    private function ensureForeignKey(
        string $table,
        string $column,
        string $parentTable,
        string $onDelete,
        ?string $name = null,
    ): void {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if ($foreignKey['columns'] === [$column]) {
                if (
                    $foreignKey['foreign_table'] !== $parentTable
                    || $foreignKey['foreign_columns'] !== ['id']
                    || strtolower($foreignKey['on_delete']) !== $onDelete
                ) {
                    throw new RuntimeException("The existing {$table}.{$column} foreign key does not match the announcement schema.");
                }

                return;
            }
        }

        $hasOrphans = DB::table($table.' as child')
            ->leftJoin($parentTable.' as parent', 'parent.id', '=', 'child.'.$column)
            ->whereNotNull('child.'.$column)
            ->whereNull('parent.id')
            ->exists();

        if ($hasOrphans) {
            throw new RuntimeException("Cannot add the {$table}.{$column} foreign key because orphaned announcement records exist.");
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column, $parentTable, $onDelete, $name): void {
            $foreignKey = $blueprint->foreign($column, $name)->references('id')->on($parentTable);

            match ($onDelete) {
                'cascade' => $foreignKey->cascadeOnDelete(),
                'set null' => $foreignKey->nullOnDelete(),
                default => $foreignKey->restrictOnDelete(),
            };
        });
    }
};
