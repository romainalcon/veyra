<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Status page audience (Sprint 14, ADR-036): branding, visitor
     * subscriptions and their announcements.
     */
    public function up(): void
    {
        Schema::table('status_pages', function (Blueprint $table) {
            $table->char('accent_color', 7)->nullable()->after('show_uptime');
            $table->boolean('subscriptions_enabled')->default(false)->after('accent_color');
        });

        /*
         * Logo and favicon bytes live in MariaDB: no shared volume to
         * provision, and the existing database backups cover them.
         */
        Schema::create('status_page_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('status_page_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('mime', 32);
            $table->char('sha256', 64);
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->unsignedInteger('size');
            $table->binary('content');
            $table->timestamps();

            $table->unique(['status_page_id', 'kind']);
        });

        // Blueprint::binary() is a 64 KiB BLOB; logos may be larger.
        DB::statement('ALTER TABLE status_page_assets MODIFY content MEDIUMBLOB NOT NULL');

        Schema::create('status_page_subscribers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('status_page_id')->constrained()->cascadeOnDelete();
            $table->text('email');
            $table->char('email_hash', 64);
            $table->dateTime('confirmed_at')->nullable();
            $table->dateTime('confirmation_sent_at')->nullable();
            $table->timestamps();

            $table->unique(['status_page_id', 'email_hash']);
            $table->index(['status_page_id', 'confirmed_at']);
            $table->index(['organization_id', 'created_at']);
            $table->index(['confirmed_at', 'created_at']);
        });

        Schema::create('status_page_announcements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('status_page_id')->constrained()->cascadeOnDelete();
            $table->uuid('subject_id');
            $table->string('event', 32);
            $table->json('payload');
            $table->timestamps();

            $table->unique(['status_page_id', 'subject_id', 'event'], 'sp_announcements_page_subject_event_unique');
            $table->index('created_at');
        });

        Schema::create('status_page_subscriber_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('announcement_id')->constrained('status_page_announcements')->cascadeOnDelete();
            $table->foreignUuid('subscriber_id')->constrained('status_page_subscribers')->cascadeOnDelete();
            $table->string('status', 16);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('sent_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();

            $table->unique(['announcement_id', 'subscriber_id'], 'sp_deliveries_announcement_subscriber_unique');
            $table->index('subscriber_id');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('status_page_subscriber_deliveries');
        Schema::dropIfExists('status_page_announcements');
        Schema::dropIfExists('status_page_subscribers');
        Schema::dropIfExists('status_page_assets');

        Schema::table('status_pages', function (Blueprint $table) {
            $table->dropColumn(['accent_color', 'subscriptions_enabled']);
        });
    }
};
