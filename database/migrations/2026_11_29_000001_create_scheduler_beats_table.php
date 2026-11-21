<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Durable, minute-granular record of scheduler activity (Sprint 7). A
     * missed heartbeat ping is only recorded when the scheduler was alive for
     * the whole expected window (ADR-025). Purged after SchedulerBeatLog::RETENTION_DAYS.
     */
    public function up(): void
    {
        Schema::create('scheduler_beats', function (Blueprint $table) {
            $table->dateTime('beat_at')->primary();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduler_beats');
    }
};
