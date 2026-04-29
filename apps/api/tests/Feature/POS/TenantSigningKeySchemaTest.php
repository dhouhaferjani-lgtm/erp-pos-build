<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\POS\Domain\TenantSigningKey;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Task 23 — tenant_signing_keys schema + model tests.
 *
 * Covers:
 *   - Schema columns, nullability, and defaults
 *   - Unique (tenant_id, kid) constraint
 *   - key_material encrypted at rest (never stored as plain text)
 *   - Active scope
 *   - byTenant scope
 */
final class TenantSigningKeySchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_has_required_columns(): void
    {
        $tenant = Tenant::factory()->create();
        $rawKey = random_bytes(32);

        $key = TenantSigningKey::create([
            'tenant_id' => $tenant->id,
            'kid' => 'current',
            'key_material' => $rawKey,
            'purpose' => 'receipt_qr',
            'is_active' => true,
            'algorithm' => 'HMAC-SHA256-128',
            'retired_at' => null,
        ]);

        $this->assertNotEmpty($key->id);
        $this->assertSame($tenant->id, $key->tenant_id);
        $this->assertSame('current', $key->kid);
        $this->assertSame('receipt_qr', $key->purpose);
        $this->assertTrue($key->is_active);
        $this->assertSame('HMAC-SHA256-128', $key->algorithm);
        $this->assertNull($key->retired_at);
    }

    public function test_default_purpose_is_receipt_qr(): void
    {
        $tenant = Tenant::factory()->create();

        $key = TenantSigningKey::factory()->forTenant($tenant)->create();

        $this->assertSame('receipt_qr', $key->purpose);
    }

    public function test_default_algorithm_is_hmac_sha256_128(): void
    {
        $key = TenantSigningKey::factory()->create();

        $this->assertSame('HMAC-SHA256-128', $key->algorithm);
    }

    public function test_retired_at_is_nullable(): void
    {
        $key = TenantSigningKey::factory()->create(['retired_at' => null]);

        $this->assertNull($key->retired_at);
    }

    public function test_unique_tenant_kid_constraint_prevents_duplicates(): void
    {
        $this->expectException(QueryException::class);

        $tenant = Tenant::factory()->create();

        TenantSigningKey::factory()->forTenant($tenant)->withKid('current')->create();
        TenantSigningKey::factory()->forTenant($tenant)->withKid('current')->create(); // must throw
    }

    public function test_same_kid_allowed_on_different_tenants(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        TenantSigningKey::factory()->forTenant($tenantA)->withKid('current')->create();
        TenantSigningKey::factory()->forTenant($tenantB)->withKid('current')->create();

        $this->assertDatabaseCount('tenant_signing_keys', 2);
    }

    public function test_key_material_is_encrypted_at_rest(): void
    {
        $rawKey = random_bytes(32);

        $key = TenantSigningKey::factory()->create(['key_material' => $rawKey]);

        // Fetch the raw DB value — it must not equal the plaintext key material.
        $dbRow = DB::table('tenant_signing_keys')->where('id', $key->id)->first();

        $this->assertNotNull($dbRow);
        $this->assertNotSame($rawKey, $dbRow->key_material);
        // Crypt ciphertext always starts with 'eyJ' (base64 of Laravel's JSON envelope).
        $this->assertStringStartsWith('eyJ', $dbRow->key_material);
    }

    public function test_key_material_decrypts_correctly_via_model(): void
    {
        $rawKey = random_bytes(32);

        $key = TenantSigningKey::factory()->create(['key_material' => $rawKey]);

        // Re-load from DB to ensure the accessor is triggered on a fresh instance.
        $fresh = TenantSigningKey::findOrFail($key->id);

        $this->assertSame($rawKey, $fresh->key_material);
    }

    public function test_key_material_not_exposed_in_to_array(): void
    {
        $key = TenantSigningKey::factory()->create();

        $arr = $key->toArray();

        $this->assertArrayNotHasKey('key_material', $arr);
    }

    public function test_key_material_not_exposed_in_to_json(): void
    {
        $key = TenantSigningKey::factory()->create();

        $json = $key->toJson();
        $decoded = json_decode($json, true);

        $this->assertArrayNotHasKey('key_material', $decoded);
    }

    public function test_active_scope_excludes_inactive_keys(): void
    {
        $tenant = Tenant::factory()->create();

        TenantSigningKey::factory()->forTenant($tenant)->withKid('current')->create(['is_active' => true]);
        TenantSigningKey::factory()->forTenant($tenant)->withKid('previous')->create(['is_active' => false]);

        $active = TenantSigningKey::active()->where('tenant_id', $tenant->id)->get();

        $this->assertCount(1, $active);
        $this->assertSame('current', $active->first()->kid);
    }

    public function test_active_scope_excludes_retired_keys(): void
    {
        $tenant = Tenant::factory()->create();

        TenantSigningKey::factory()->forTenant($tenant)->withKid('current')->create(['retired_at' => null]);
        TenantSigningKey::factory()->forTenant($tenant)->withKid('v2023-01')->retired()->create();

        $active = TenantSigningKey::active()->where('tenant_id', $tenant->id)->get();

        $this->assertCount(1, $active);
    }

    public function test_by_tenant_scope_filters_by_purpose(): void
    {
        $tenant = Tenant::factory()->create();

        TenantSigningKey::factory()->forTenant($tenant)->withKid('current')->create(['purpose' => 'receipt_qr']);
        TenantSigningKey::factory()->forTenant($tenant)->withKid('webhook')->create(['purpose' => 'webhook_signing']);

        $receiptKeys = TenantSigningKey::byTenant($tenant->id, 'receipt_qr')->get();

        $this->assertCount(1, $receiptKeys);
        $this->assertSame('receipt_qr', $receiptKeys->first()->purpose);
    }

    public function test_factory_retired_state_sets_retired_at_and_is_active_false(): void
    {
        $key = TenantSigningKey::factory()->retired()->create();

        $this->assertFalse($key->is_active);
        $this->assertNotNull($key->retired_at);
    }
}
