<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Heartbeat monitors (Sprint 7). The ping token is stored as a SHA-256
     * hash only. Heartbeat periods go up to one week, beyond the SMALLINT
     * range of `monitors.interval_seconds`, which is widened (non-destructive).
     */
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table) {
            $table->unsignedInteger('interval_seconds')->change();
        });

        Schema::create('monitor_heartbeat_configs', function (Blueprint $table) {
            $table->foreignUuid('monitor_id')->primary()->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->unsignedInteger('grace_seconds');
            $table->dateTime('last_ping_at')->nullable();
            $table->boolean('last_ping_failed')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_heartbeat_configs');
    }
};
