<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Public status pages (Sprint 6). Components expose monitors under a
     * public display name; the custom domain columns are used once verified.
     */
    public function up(): void
    {
        Schema::create('status_pages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 60)->unique();
            $table->string('title', 120);
            $table->text('description')->nullable();
            $table->boolean('is_published')->default(false);
            $table->boolean('show_uptime')->default(true);
            $table->string('custom_domain', 253)->nullable()->unique();
            $table->string('domain_verification_token', 64)->nullable();
            $table->dateTime('domain_verified_at')->nullable();
            $table->dateTime('domain_checked_at')->nullable();
            $table->unsignedTinyInteger('domain_failures')->default(0);
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
        });

        Schema::create('status_page_components', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('status_page_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('monitor_id')->constrained()->cascadeOnDelete();
            $table->string('display_name', 100);
            $table->string('group_name', 100)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['status_page_id', 'monitor_id']);
            $table->index('monitor_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('status_page_components');
        Schema::dropIfExists('status_pages');
    }
};
