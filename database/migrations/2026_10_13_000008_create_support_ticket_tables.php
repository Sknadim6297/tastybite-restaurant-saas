<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createIfMissing('support_tickets', function (): void {
            Schema::create('support_tickets', function (Blueprint $table): void {
                $table->id();
                $table->string('ticket_number', 36)->unique();
                $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
                $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
                $table->foreignId('assigned_admin_id')->nullable()->constrained('super_admins')->nullOnDelete();
                $table->string('subject', 180);
                $table->string('category', 40);
                $table->string('priority', 20)->default('normal');
                $table->string('status', 32)->default('open');
                $table->timestamp('last_activity_at')->useCurrent();
                $table->timestamps();

                $table->index(['restaurant_id', 'status', 'last_activity_at'], 'support_tenant_status_activity_idx');
                $table->index(['status', 'priority', 'last_activity_at'], 'support_queue_status_priority_idx');
                $table->index(['assigned_admin_id', 'status'], 'support_assignee_status_idx');
            });
        });

        $this->createIfMissing('support_ticket_messages', function (): void {
            Schema::create('support_ticket_messages', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('support_ticket_id')->constrained()->restrictOnDelete();
                $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('author_super_admin_id')->nullable()->constrained('super_admins')->nullOnDelete();
                $table->string('visibility', 16)->default('public');
                $table->text('body');
                $table->string('attachment_path', 500)->nullable();
                $table->string('attachment_name', 255)->nullable();
                $table->string('attachment_mime', 120)->nullable();
                $table->timestamps();

                $table->index(['support_ticket_id', 'visibility', 'created_at'], 'support_message_thread_idx');
            });
        });

        $this->createIfMissing('support_ticket_status_history', function (): void {
            Schema::create('support_ticket_status_history', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('support_ticket_id')->constrained()->restrictOnDelete();
                $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('actor_super_admin_id')->nullable()->constrained('super_admins')->nullOnDelete();
                $table->string('previous_status', 32)->nullable();
                $table->string('new_status', 32);
                $table->string('reason', 500)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['support_ticket_id', 'created_at'], 'support_status_history_idx');
            });
        });

        $this->createIfMissing('restaurant_support_ticket_notifications', function (): void {
            Schema::create('restaurant_support_ticket_notifications', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('support_ticket_id')->constrained('support_tickets', 'id', 'restaurant_support_notice_ticket_fk')->restrictOnDelete();
                $table->string('event_key', 191);
                $table->string('title', 180);
                $table->text('description');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'event_key'], 'restaurant_support_notice_event_unique');
                $table->index(['user_id', 'read_at', 'created_at'], 'restaurant_support_notice_inbox_idx');
            });
        });

        $this->assertColumns('support_tickets', [
            'id', 'ticket_number', 'restaurant_id', 'created_by_user_id', 'assigned_admin_id',
            'subject', 'category', 'priority', 'status', 'last_activity_at', 'created_at', 'updated_at',
        ]);
        $this->assertColumns('support_ticket_messages', [
            'id', 'support_ticket_id', 'author_user_id', 'author_super_admin_id', 'visibility', 'body',
            'attachment_path', 'attachment_name', 'attachment_mime', 'created_at', 'updated_at',
        ]);
        $this->assertColumns('support_ticket_status_history', [
            'id', 'support_ticket_id', 'actor_user_id', 'actor_super_admin_id', 'previous_status',
            'new_status', 'reason', 'created_at',
        ]);
        $this->assertColumns('restaurant_support_ticket_notifications', [
            'id', 'user_id', 'support_ticket_id', 'event_key', 'title', 'description', 'read_at',
            'created_at', 'updated_at',
        ]);

        $this->ensureIndex('support_tickets', ['ticket_number'], 'support_tickets_ticket_number_unique', true);
        $this->ensureIndex('support_tickets', ['restaurant_id', 'status', 'last_activity_at'], 'support_tenant_status_activity_idx');
        $this->ensureIndex('support_tickets', ['status', 'priority', 'last_activity_at'], 'support_queue_status_priority_idx');
        $this->ensureIndex('support_tickets', ['assigned_admin_id', 'status'], 'support_assignee_status_idx');
        $this->ensureIndex('support_ticket_messages', ['support_ticket_id', 'visibility', 'created_at'], 'support_message_thread_idx');
        $this->ensureIndex('support_ticket_status_history', ['support_ticket_id', 'created_at'], 'support_status_history_idx');
        $this->ensureIndex('restaurant_support_ticket_notifications', ['user_id', 'event_key'], 'restaurant_support_notice_event_unique', true);
        $this->ensureIndex('restaurant_support_ticket_notifications', ['user_id', 'read_at', 'created_at'], 'restaurant_support_notice_inbox_idx');

        $this->ensureForeignKey('support_tickets', 'restaurant_id', 'restaurants', 'restrict');
        $this->ensureForeignKey('support_tickets', 'created_by_user_id', 'users', 'restrict');
        $this->ensureForeignKey('support_tickets', 'assigned_admin_id', 'super_admins', 'set null');
        $this->ensureForeignKey('support_ticket_messages', 'support_ticket_id', 'support_tickets', 'restrict');
        $this->ensureForeignKey('support_ticket_messages', 'author_user_id', 'users', 'set null');
        $this->ensureForeignKey('support_ticket_messages', 'author_super_admin_id', 'super_admins', 'set null');
        $this->ensureForeignKey('support_ticket_status_history', 'support_ticket_id', 'support_tickets', 'restrict');
        $this->ensureForeignKey('support_ticket_status_history', 'actor_user_id', 'users', 'set null');
        $this->ensureForeignKey('support_ticket_status_history', 'actor_super_admin_id', 'super_admins', 'set null');
        $this->ensureForeignKey('restaurant_support_ticket_notifications', 'user_id', 'users', 'cascade');
        $this->ensureForeignKey(
            'restaurant_support_ticket_notifications',
            'support_ticket_id',
            'support_tickets',
            'restrict',
            'restaurant_support_notice_ticket_fk',
        );
    }

    public function down(): void
    {
        throw new RuntimeException('Support ticket conversations and history are retained to preserve the support record.');
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
            throw new RuntimeException("The existing {$table} table does not match the support-ticket schema.");
        }
    }

    private function ensureIndex(string $table, array $columns, string $name, bool $unique = false): void
    {
        foreach (Schema::getIndexes($table) as $index) {
            if ($index['name'] === $name) {
                if ($index['columns'] !== $columns || $index['unique'] !== $unique) {
                    throw new RuntimeException("The existing {$name} index does not match the support-ticket schema.");
                }

                return;
            }
        }

        if ($unique && DB::table($table)->select($columns)->groupBy($columns)->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException("Cannot add the {$name} unique index because duplicate support records exist.");
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $name, $unique): void {
            if ($unique) {
                $blueprint->unique($columns, $name);
            } else {
                $blueprint->index($columns, $name);
            }
        });
    }

    private function ensureForeignKey(
        string $table,
        string $column,
        string $parentTable,
        string $onDelete,
        ?string $name = null,
    ): void
    {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if ($foreignKey['columns'] === [$column]) {
                if (
                    $foreignKey['foreign_table'] !== $parentTable
                    || $foreignKey['foreign_columns'] !== ['id']
                    || strtolower($foreignKey['on_delete']) !== $onDelete
                ) {
                    throw new RuntimeException("The existing {$table}.{$column} foreign key does not match the support-ticket schema.");
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
            throw new RuntimeException("Cannot add the {$table}.{$column} foreign key because orphaned support records exist.");
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
