<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table): void {
            $table->string('approval_status', 32)->default('approved')->index();
        });

        Schema::create('restaurant_approval_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('super_admins')->nullOnDelete();
            $table->string('previous_status', 32)->nullable();
            $table->string('new_status', 32);
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['restaurant_id', 'created_at'], 'restaurant_approval_history_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_approval_history');
        Schema::table('restaurants', function (Blueprint $table): void {
            $table->dropColumn('approval_status');
        });
    }
};
