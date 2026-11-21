<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Temporal columns use DATETIME (UTC) rather than TIMESTAMP to avoid the
     * 2038 limit. `monitor_checks` deliberately has no cascading foreign key:
     * history is purged in bounded batches (see PurgeMonitorHistory).
     */
    public function up(): void
    {
        Schema::create('monitors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('type', 16);
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('interval_seconds');
            $table->unsignedTinyInteger('timeout_seconds');
            $table->dateTime('next_check_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['enabled', 'next_check_at']);
            $table->index(['organization_id', 'deleted_at']);
        });

        Schema::create('monitor_http_configs', function (Blueprint $table) {
            $table->foreignUuid('monitor_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('url', 2048);
            $table->string('method', 4);
            $table->json('expected_statuses');
            $table->boolean('follow_redirects')->default(true);
            $table->text('headers_encrypted')->nullable();
            $table->timestamps();
        });

        Schema::create('monitor_dispatches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('monitor_id');
            $table->dateTime('scheduled_for');
            $table->string('trigger', 16);
            $table->string('status', 16);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('dispatched_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['monitor_id', 'scheduled_for']);
            $table->index(['status', 'dispatched_at']);
        });

        Schema::create('monitor_checks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('monitor_id');
            $table->uuid('dispatch_id')->unique();
            $table->dateTime('scheduled_for');
            $table->dateTime('checked_at');
            $table->boolean('success');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('error_code', 32)->nullable();
            $table->json('metadata')->nullable();
            $table->dateTime('created_at')->nullable();

            $table->index(['monitor_id', 'checked_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monitor_checks');
        Schema::dropIfExists('monitor_dispatches');
        Schema::dropIfExists('monitor_http_configs');
        Schema::dropIfExists('monitors');
    }
};
