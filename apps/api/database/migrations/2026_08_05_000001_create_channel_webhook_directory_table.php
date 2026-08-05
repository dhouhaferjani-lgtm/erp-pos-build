<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CENTRAL table (root migrations directory, not database/migrations/tenant).
 *
 * `POST api/v1/webhooks/channels/{channelId}` is unauthenticated — external
 * sales platforms call it directly — so no tenant is bound when it arrives, and
 * the channel id in the URL is the only identifier it carries. `channels` is a
 * TENANT table, so that id is unreadable without already knowing the tenant,
 * and the signature cannot be verified first because verification needs the
 * channel's adapter and credentials.
 *
 * This directory is the minimum central pointer that makes the callback
 * routable: channel id -> tenant id, and nothing else. It deliberately holds no
 * channel data (name, adapter, credentials, is_active all stay tenant-side), so
 * it leaks nothing across the boundary beyond the existence of an id an external
 * platform already knows.
 *
 * The alternative — fanning out across every tenant database until the channel
 * turns up — was rejected: on an unauthenticated endpoint that is a free
 * amplification vector, one forged request costing N database switches.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_webhook_directory', function (Blueprint $table): void {
            $table->uuid('channel_id')->primary();
            $table->uuid('tenant_id');
            $table->timestamps();

            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_webhook_directory');
    }
};
