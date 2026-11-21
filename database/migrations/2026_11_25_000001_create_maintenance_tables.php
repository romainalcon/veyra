<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Planned maintenance windows (Sprint 6). Checks keep running during a
     * window but are flagged `in_maintenance`: they never confirm a state,
     * open an incident or count towards uptime.
     */
    public function up(): void
    {
        Schema::create('maintenance_windows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            $table->text('description')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'starts_at']);
            $table->index(['starts_at', 'ends_at']);
        });

        Schema::create('maintenance_window_monitors', function (Blueprint $table) {
            $table->foreignUuid('maintenance_window_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('monitor_id')->constrained()->cascadeOnDelete();

            $table->primary(['maintenance_window_id', 'monitor_id']);
            $table->index('monitor_id');
        });

        // MariaDB 11.4 adds a trailing column with a default instantly (no table rebuild).
        Schema::table('monitor_checks', function (Blueprint $table) {
            $table->boolean('in_maintenance')->default(false);
        });

        Schema::table('monitor_daily_stats', function (Blueprint $table) {
            $table->unsignedInteger('maintenance_count')->default(0)->after('failure_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('monitor_daily_stats', function (Blueprint $table) {
            $table->dropColumn('maintenance_count');
        });

        Schema::table('monitor_checks', function (Blueprint $table) {
            $table->dropColumn('in_maintenance');
        });

        Schema::dropIfExists('maintenance_window_monitors');
        Schema::dropIfExists('maintenance_windows');
    }
};
