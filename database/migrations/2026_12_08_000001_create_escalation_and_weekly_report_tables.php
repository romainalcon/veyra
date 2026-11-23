<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Escalation policies (ADR-045) and weekly reports. Additive only:
     *
     * - `escalation_policies` / `escalation_policy_steps` / `escalation_step_channels`:
     *   ordered steps (delay + channels) per organization; monitors pick one
     *   (`monitors.escalation_policy_id`) or fall back to the organization default.
     * - `incidents.escalation_step`: last step whose deliveries were queued.
     * - `notification_deliveries.escalation_step`: 0 for every non-escalation
     *   delivery; part of the idempotency key so that a channel can be used by
     *   several steps of the same incident.
     * - `organization_members.weekly_report`: per-member opt-in.
     * - `weekly_reports`: one row per organization and reported week, so that
     *   a report is sent at most once.
     */
    public function up(): void
    {
        Schema::create('escalation_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['organization_id', 'is_default']);
        });

        Schema::create('escalation_policy_steps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('policy_id')->constrained('escalation_policies')->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->unsignedSmallInteger('delay_minutes');
            $table->timestamps();

            $table->unique(['policy_id', 'position']);
        });

        Schema::create('escalation_step_channels', function (Blueprint $table) {
            $table->foreignUuid('step_id')->constrained('escalation_policy_steps')->cascadeOnDelete();
            $table->foreignUuid('channel_id')->constrained('notification_channels')->cascadeOnDelete();

            $table->primary(['step_id', 'channel_id']);
        });

        Schema::table('monitors', function (Blueprint $table) {
            $table->foreignUuid('escalation_policy_id')->nullable()->constrained('escalation_policies')->nullOnDelete();
        });

        Schema::table('incidents', function (Blueprint $table) {
            $table->unsignedTinyInteger('escalation_step')->default(0)->after('reminder_sent_at');
        });

        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->unsignedTinyInteger('escalation_step')->default(0)->after('event_type');
            $table->unique(['incident_id', 'channel_id', 'event_type', 'escalation_step'], 'deliveries_incident_channel_event_step_unique');
        });

        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->dropUnique(['incident_id', 'channel_id', 'event_type']);
        });

        Schema::table('organization_members', function (Blueprint $table) {
            $table->boolean('weekly_report')->default(false)->after('role');
        });

        Schema::create('weekly_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->unsignedInteger('recipients_count')->default(0);
            $table->dateTime('queued_at')->nullable();
            $table->dateTime('created_at');

            $table->unique(['organization_id', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_reports');

        Schema::table('organization_members', function (Blueprint $table) {
            $table->dropColumn('weekly_report');
        });

        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->unique(['incident_id', 'channel_id', 'event_type']);
        });

        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->dropUnique('deliveries_incident_channel_event_step_unique');
            $table->dropColumn('escalation_step');
        });

        Schema::table('incidents', function (Blueprint $table) {
            $table->dropColumn('escalation_step');
        });

        Schema::table('monitors', function (Blueprint $table) {
            $table->dropConstrainedForeignId('escalation_policy_id');
        });

        Schema::dropIfExists('escalation_step_channels');
        Schema::dropIfExists('escalation_policy_steps');
        Schema::dropIfExists('escalation_policies');
    }
};
