<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Check diagnostics (Sprint 23, ADR-050). Phase timings are nullable
     * columns appended to `monitor_checks` (older checks and other types keep
     * null; MariaDB adds them in place) with their daily averages.
     * `check_evidence` keeps what the server answered on a failing HTTP check:
     * rows not attached to an incident are purged after a day, attached rows
     * go away with their incident. Additive only.
     */
    public function up(): void
    {
        Schema::table('monitor_checks', function (Blueprint $table) {
            $table->unsignedInteger('dns_ms')->nullable();
            $table->unsignedInteger('connect_ms')->nullable();
            $table->unsignedInteger('tls_ms')->nullable();
            $table->unsignedInteger('ttfb_ms')->nullable();
            $table->unsignedInteger('transfer_ms')->nullable();
            $table->unsignedInteger('redirect_ms')->nullable();
        });

        Schema::table('monitor_daily_stats', function (Blueprint $table) {
            $table->unsignedInteger('dns_avg_ms')->nullable();
            $table->unsignedInteger('connect_avg_ms')->nullable();
            $table->unsignedInteger('tls_avg_ms')->nullable();
            $table->unsignedInteger('ttfb_avg_ms')->nullable();
            $table->unsignedInteger('transfer_avg_ms')->nullable();
        });

        Schema::create('check_evidence', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('monitor_id');
            $table->uuid('check_id')->unique();
            $table->foreignUuid('incident_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('status_code');
            $table->string('http_version', 8)->nullable();
            $table->string('error_code', 32);
            $table->string('content_type', 128)->nullable();
            $table->json('headers');
            $table->text('body_excerpt')->nullable();
            $table->boolean('body_truncated')->default(false);
            $table->string('body_omitted_reason', 16)->nullable();
            $table->dateTime('checked_at');
            $table->dateTime('created_at');

            $table->index(['monitor_id', 'incident_id', 'created_at']);
            $table->index(['incident_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('check_evidence');

        Schema::table('monitor_daily_stats', function (Blueprint $table) {
            $table->dropColumn(['dns_avg_ms', 'connect_avg_ms', 'tls_avg_ms', 'ttfb_avg_ms', 'transfer_avg_ms']);
        });

        Schema::table('monitor_checks', function (Blueprint $table) {
            $table->dropColumn(['dns_ms', 'connect_ms', 'tls_ms', 'ttfb_ms', 'transfer_ms', 'redirect_ms']);
        });
    }
};
