<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_signing_keys', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('tenant_id')->index();

            // Key identifier — e.g. "current", "previous", or a versioned id.
            // Unique per tenant to allow kid-based lookup without leaking
            // cross-tenant key existence.
            $table->string('kid', 32);

            // Encrypted at rest via Laravel Crypt. Raw bytes of the signing key
            // are encrypted before storage and decrypted on read via model cast.
            $table->text('key_material');

            // Discriminator for future signing key purposes (e.g. webhook_signing).
            $table->string('purpose', 32)->default('receipt_qr');

            $table->boolean('is_active')->default(true);

            // Algorithm identifier — reserved for future schemes.
            $table->string('algorithm', 32)->default('HMAC-SHA256-128');

            $table->timestamps();

            // Nullable: set when a key is retired (rotated out of service).
            // A key with retired_at set should never be used for signing,
            // only for verification during the grace period.
            $table->timestamp('retired_at')->nullable();

            // Fast lookup for "give me all active keys for this tenant + purpose"
            $table->index(['tenant_id', 'purpose', 'is_active'], 'tskeys_tenant_purpose_active_idx');

            // kid-based lookup (used during verification). Unique within tenant.
            $table->unique(['tenant_id', 'kid'], 'tskeys_tenant_kid_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_signing_keys');
    }
};
