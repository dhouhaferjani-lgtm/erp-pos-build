<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CENTRAL table (topology contract §9.1) — the email-first central identity
 * index. Maps an email to the tenant(s) it belongs to; stores pointers only,
 * never credentials. Lives in the central DB and MUST NOT be moved into
 * database/migrations/tenant/ when the Stancl flip lands in Phase 0b.
 *
 * No FK to `tenants` (Pattern A — cross-row resolution happens at the app
 * layer; post-flip the DB boundary cannot enforce a cross-DB FK anyway).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('central_identities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // NOT globally unique — the same email may map to many tenants.
            $table->string('email')->index();
            // Plain UUID, NO FK (Pattern A).
            $table->uuid('tenant_id');
            // Informational only: the tenant-side users.id, resolved post tenancy-init.
            $table->uuid('user_id')->nullable();
            $table->timestamps();

            // One index row per (email, tenant) membership.
            $table->unique(['email', 'tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('central_identities');
    }
};
