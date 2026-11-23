<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Organization tools (Sprint 21). `audit_logs` is append-only (ADR-046):
     * no foreign key on the actor or the subject, whose names are copied so
     * that entries survive their deletion; rows cascade with the organization
     * and are otherwise only removed by the batched retention purge.
     * `tags` and `monitor_tag` carry monitor tags (ADR-047). Additive only.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('actor_type', 16);
            $table->uuid('actor_id')->nullable();
            $table->string('actor_name', 255)->nullable();
            $table->string('action', 64);
            $table->string('subject_type', 32)->nullable();
            $table->uuid('subject_id')->nullable();
            $table->string('subject_label', 255)->nullable();
            $table->json('changes')->nullable();
            $table->json('metadata')->nullable();
            $table->dateTime('created_at', 6);

            $table->index(['organization_id', 'created_at']);
            $table->index(['organization_id', 'subject_type', 'created_at']);
            $table->index('created_at');
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 32);
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });

        Schema::create('monitor_tag', function (Blueprint $table) {
            $table->foreignUuid('monitor_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('tag_id')->constrained()->cascadeOnDelete();

            $table->primary(['monitor_id', 'tag_id']);
            $table->index('tag_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_tag');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('audit_logs');
    }
};
