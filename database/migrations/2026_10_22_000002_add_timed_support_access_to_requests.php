<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_access_requests', function (Blueprint $table): void {
            $table->timestamp('access_started_at')->nullable();
            $table->timestamp('access_expires_at')->nullable();
            $table->timestamp('access_ended_at')->nullable();
            $table->timestamp('access_revoked_at')->nullable();
            $table->index(
                ['requester_admin_id', 'restaurant_id', 'status', 'access_expires_at'],
                'support_access_session_lookup_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('support_access_requests', function (Blueprint $table): void {
            $table->dropIndex('support_access_session_lookup_idx');
            $table->dropColumn([
                'access_started_at',
                'access_expires_at',
                'access_ended_at',
                'access_revoked_at',
            ]);
        });
    }
};
