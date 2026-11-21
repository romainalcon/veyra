<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TCP monitor targets (Sprint 7). One row per `tcp` monitor, like
     * `monitor_http_configs` for HTTP monitors.
     */
    public function up(): void
    {
        Schema::create('monitor_tcp_configs', function (Blueprint $table) {
            $table->foreignUuid('monitor_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('host', 253);
            $table->unsignedSmallInteger('port');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_tcp_configs');
    }
};
