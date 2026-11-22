<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DNS monitors and domain expiry (Sprint 18, ADR-042, ADR-043).
     *
     * - `monitor_dns_configs`: one row per `dns` monitor (record, resolver,
     *   expected values) plus the last answers received.
     * - `monitor_domains`: registration expiry of the domain of an HTTP or DNS
     *   monitor, read through RDAP at most once a day (public data only).
     * - `domain_expiry_alerts`: one row per (monitor, domain, expiry date,
     *   threshold), the idempotency key of expiry warnings.
     * - `notification_deliveries` may now belong to a domain alert: exactly one
     *   of incident, certificate alert and domain alert is set.
     */
    public function up(): void
    {
        Schema::create('monitor_dns_configs', function (Blueprint $table) {
            $table->foreignUuid('monitor_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('record_name', 253);
            $table->string('record_type', 8);
            $table->string('resolver', 32);
            $table->json('expected_values')->nullable();
            $table->string('match_mode', 16);
            $table->json('last_answers')->nullable();
            $table->dateTime('last_resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('monitor_domains', function (Blueprint $table) {
            $table->foreignUuid('monitor_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('host', 253);
            $table->string('domain', 253)->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->string('registrar', 255)->nullable();
            $table->dateTime('checked_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();

            $table->index('checked_at');
            $table->index('expires_at');
        });

        Schema::create('domain_expiry_alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('monitor_id')->constrained()->cascadeOnDelete();
            $table->string('domain', 253);
            $table->unsignedSmallInteger('threshold_days');
            $table->dateTime('expires_at');
            $table->dateTime('created_at');

            $table->unique(['monitor_id', 'domain', 'expires_at', 'threshold_days'], 'domain_alerts_monitor_expiry_threshold_unique');
            $table->index('created_at');
        });

        DB::statement('ALTER TABLE notification_deliveries DROP CONSTRAINT IF EXISTS notification_deliveries_subject_check');

        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->foreignUuid('domain_alert_id')->nullable()->after('certificate_alert_id')
                ->constrained('domain_expiry_alerts')->cascadeOnDelete();

            $table->unique(['domain_alert_id', 'channel_id'], 'deliveries_domain_alert_channel_unique');
        });

        DB::statement('ALTER TABLE notification_deliveries ADD CONSTRAINT notification_deliveries_subject_check
            CHECK ((incident_id IS NOT NULL) + (certificate_alert_id IS NOT NULL) + (domain_alert_id IS NOT NULL) = 1)');
    }

    /**
     * Rolling back removes domain deliveries (they cannot exist without an
     * alert) before restoring the two-subject constraint.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE notification_deliveries DROP CONSTRAINT IF EXISTS notification_deliveries_subject_check');

        DB::table('notification_deliveries')->whereNotNull('domain_alert_id')->delete();

        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->dropForeign(['domain_alert_id']);
            $table->dropUnique('deliveries_domain_alert_channel_unique');
            $table->dropColumn('domain_alert_id');
        });

        DB::statement('ALTER TABLE notification_deliveries ADD CONSTRAINT notification_deliveries_subject_check
            CHECK ((incident_id IS NULL) <> (certificate_alert_id IS NULL))');

        Schema::dropIfExists('domain_expiry_alerts');
        Schema::dropIfExists('monitor_domains');
        Schema::dropIfExists('monitor_dns_configs');
    }
};
