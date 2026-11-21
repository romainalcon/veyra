<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('notification_channels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            $table->string('name', 100);
            $table->text('config_encrypted');
            $table->boolean('enabled')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['organization_id', 'type']);
        });

        Schema::create('monitor_notification_channels', function (Blueprint $table) {
            $table->foreignUuid('monitor_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('notification_channel_id')->constrained()->cascadeOnDelete();

            $table->primary(['monitor_id', 'notification_channel_id']);
        });

        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('incident_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('channel_id')->constrained('notification_channels')->cascadeOnDelete();
            $table->string('event_type', 32);
            $table->string('status', 16);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('last_error', 255)->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['incident_id', 'channel_id', 'event_type']);
            $table->index(['channel_id', 'created_at']);
            $table->index('created_at');
        });

        Schema::create('monitor_daily_stats', function (Blueprint $table) {
            $table->uuid('monitor_id');
            $table->date('day');
            $table->unsignedInteger('checks_count')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('failure_count')->default(0);
            $table->unsignedInteger('latency_avg_ms')->nullable();
            $table->unsignedInteger('latency_max_ms')->nullable();
            $table->unsignedInteger('expected_checks')->default(0);
            $table->dateTime('updated_at')->nullable();

            $table->primary(['monitor_id', 'day']);
        });

        Schema::table('incidents', function (Blueprint $table) {
            $table->dateTime('reminder_sent_at')->nullable()->after('acknowledged_by');
            $table->index(['status', 'acknowledged_at', 'started_at']);
        });

        Schema::table('monitor_dispatches', function (Blueprint $table) {
            $table->index(['status', 'completed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('monitor_dispatches', function (Blueprint $table) {
            $table->dropIndex(['status', 'completed_at']);
        });

        Schema::table('incidents', function (Blueprint $table) {
            $table->dropIndex(['status', 'acknowledged_at', 'started_at']);
            $table->dropColumn('reminder_sent_at');
        });

        Schema::dropIfExists('monitor_daily_stats');
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('monitor_notification_channels');
        Schema::dropIfExists('notification_channels');
    }
};
