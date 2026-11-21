<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('monitor_states', function (Blueprint $table) {
            $table->foreignUuid('monitor_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('status', 16);
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->unsignedSmallInteger('consecutive_successes')->default(0);
            $table->dateTime('last_checked_at')->nullable();
            $table->dateTime('last_changed_at')->nullable();
            // Ordering guard: checks scheduled at or before this instant no longer change the state.
            $table->dateTime('last_applied_scheduled_for')->nullable();
            // Streaks only count checks scheduled at or after this instant (pause, resume, stale data).
            $table->dateTime('streak_since')->nullable();
            // Pointer enforcing at most one active incident per monitor (MariaDB has no partial unique index).
            $table->uuid('active_incident_id')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index('status');
        });

        Schema::create('incidents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('monitor_id');
            $table->string('status', 16);
            $table->dateTime('started_at');
            $table->dateTime('resolved_at')->nullable();
            $table->dateTime('acknowledged_at')->nullable();
            $table->foreignUuid('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cause', 255)->nullable();
            $table->timestamps();

            $table->index(['monitor_id', 'started_at']);
            $table->index(['organization_id', 'status', 'started_at']);
        });

        Schema::create('incident_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('incident_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('message')->nullable();
            $table->json('metadata')->nullable();
            $table->dateTime('created_at');

            $table->index(['incident_id', 'created_at']);
        });

        Schema::table('monitor_checks', function (Blueprint $table) {
            $table->index(['monitor_id', 'scheduled_for']);
        });

        // Existing monitors start in the UNKNOWN state.
        DB::statement("INSERT IGNORE INTO monitor_states (monitor_id, status, updated_at) SELECT id, 'unknown', UTC_TIMESTAMP() FROM monitors");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('monitor_checks', function (Blueprint $table) {
            $table->dropIndex(['monitor_id', 'scheduled_for']);
        });

        Schema::dropIfExists('incident_events');
        Schema::dropIfExists('incidents');
        Schema::dropIfExists('monitor_states');
    }
};
