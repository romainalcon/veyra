<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Recurring maintenance (ADR-041). A series is a rule expressed in the
     * organization time zone; its occurrences are materialized ahead of time
     * as ordinary maintenance_windows rows (ADR-021 semantics unchanged),
     * unique per (series, local date) so that generation is idempotent.
     * Additive only: existing organizations get UTC, existing windows no series.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('timezone', 64)->default('UTC')->after('locale');
        });

        Schema::create('maintenance_series', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            $table->text('description')->nullable();
            $table->string('frequency', 16);
            $table->json('weekdays')->nullable();
            $table->unsignedTinyInteger('month_day')->nullable();
            $table->time('start_time');
            $table->unsignedInteger('duration_minutes');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
        });

        Schema::create('maintenance_series_monitors', function (Blueprint $table) {
            $table->foreignUuid('maintenance_series_id')->constrained('maintenance_series')->cascadeOnDelete();
            $table->foreignUuid('monitor_id')->constrained()->cascadeOnDelete();

            $table->primary(['maintenance_series_id', 'monitor_id']);
            $table->index('monitor_id');
        });

        Schema::table('maintenance_windows', function (Blueprint $table) {
            $table->foreignUuid('series_id')->nullable()->after('organization_id')
                ->constrained('maintenance_series')->nullOnDelete();
            $table->date('occurrence_date')->nullable()->after('series_id');

            $table->unique(['series_id', 'occurrence_date']);
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_windows', function (Blueprint $table) {
            // The foreign key uses the unique index: drop it first.
            $table->dropForeign(['series_id']);
            $table->dropUnique(['series_id', 'occurrence_date']);
            $table->dropColumn(['series_id', 'occurrence_date']);
        });

        Schema::dropIfExists('maintenance_series_monitors');
        Schema::dropIfExists('maintenance_series');

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
