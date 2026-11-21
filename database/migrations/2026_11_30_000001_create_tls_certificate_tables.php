<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TLS certificate expiry (Sprint 7, ADR-026).
     *
     * - `monitor_tls_certificates`: the leaf certificate last seen by the
     *   HTTPS probe of a monitor (public data only, no keys).
     * - `tls_certificate_alerts`: one row per (monitor, certificate, threshold),
     *   the idempotency key of expiry warnings.
     * - `notification_deliveries` may now belong to a certificate alert instead
     *   of an incident (exactly one of both). Existing rows are untouched.
     */
    public function up(): void
    {
        Schema::create('monitor_tls_certificates', function (Blueprint $table) {
            $table->foreignUuid('monitor_id')->primary()->constrained()->cascadeOnDelete();
            $table->char('fingerprint', 64);
            $table->string('subject', 255)->nullable();
            $table->string('issuer', 255)->nullable();
            $table->dateTime('not_before');
            $table->dateTime('not_after');
            $table->dateTime('checked_at');
            $table->timestamps();

            $table->index('not_after');
        });

        Schema::create('tls_certificate_alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('monitor_id')->constrained()->cascadeOnDelete();
            $table->char('fingerprint', 64);
            $table->unsignedSmallInteger('threshold_days');
            $table->dateTime('not_after');
            $table->dateTime('created_at');

            $table->unique(['monitor_id', 'fingerprint', 'threshold_days'], 'tls_alerts_monitor_cert_threshold_unique');
            $table->index('created_at');
        });

        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->uuid('incident_id')->nullable()->change();
            $table->foreignUuid('certificate_alert_id')->nullable()->after('incident_id')
                ->constrained('tls_certificate_alerts')->cascadeOnDelete();

            $table->unique(['certificate_alert_id', 'channel_id'], 'deliveries_certificate_alert_channel_unique');
        });

        DB::statement('ALTER TABLE notification_deliveries ADD CONSTRAINT notification_deliveries_subject_check
            CHECK ((incident_id IS NULL) <> (certificate_alert_id IS NULL))');
    }

    /**
     * Rolling back removes certificate deliveries (they cannot exist without
     * an alert) before restoring the NOT NULL incident column.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE notification_deliveries DROP CONSTRAINT IF EXISTS notification_deliveries_subject_check');

        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->dropForeign(['certificate_alert_id']);
            $table->dropUnique('deliveries_certificate_alert_channel_unique');
            $table->dropColumn('certificate_alert_id');
        });

        DB::table('notification_deliveries')->whereNull('incident_id')->delete();

        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->uuid('incident_id')->nullable(false)->change();
        });

        Schema::dropIfExists('tls_certificate_alerts');
        Schema::dropIfExists('monitor_tls_certificates');
    }
};
