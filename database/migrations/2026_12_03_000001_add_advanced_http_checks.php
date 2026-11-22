<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Advanced HTTP checks (ADR-037): request bodies, basic auth, content
     * assertions and latency thresholds. Additive only; `slow` uses an
     * instant ADD COLUMN on MariaDB, so the check table is not rebuilt.
     */
    public function up(): void
    {
        Schema::table('monitor_http_configs', function (Blueprint $table) {
            $table->string('method', 8)->change();
            $table->text('body_encrypted')->nullable()->after('headers_encrypted');
            $table->string('body_content_type', 64)->nullable()->after('body_encrypted');
            $table->text('basic_auth_encrypted')->nullable()->after('body_content_type');
            $table->string('keyword', 255)->nullable()->after('basic_auth_encrypted');
            $table->string('keyword_mode', 16)->nullable()->after('keyword');
            $table->boolean('keyword_case_sensitive')->default(false)->after('keyword_mode');
            $table->unsignedInteger('latency_threshold_ms')->nullable()->after('keyword_case_sensitive');
        });

        Schema::table('monitor_checks', function (Blueprint $table) {
            $table->boolean('slow')->default(false)->after('success');
        });
    }

    public function down(): void
    {
        Schema::table('monitor_checks', function (Blueprint $table) {
            $table->dropColumn('slow');
        });

        Schema::table('monitor_http_configs', function (Blueprint $table) {
            $table->dropColumn(['body_encrypted', 'body_content_type', 'basic_auth_encrypted', 'keyword', 'keyword_mode', 'keyword_case_sensitive', 'latency_threshold_ms']);
        });
    }
};
