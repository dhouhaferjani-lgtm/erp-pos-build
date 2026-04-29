<?php

declare(strict_types=1);

namespace Tests\Feature\POS\Sync;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\TenantSigningKey;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Feature tests for GET /api/v1/pos/receipts/qr-index.
 *
 * Verifies:
 *   - Single-terminal scope (only this terminal's receipts)
 *   - Pending-seal receipts excluded (only fiscalised)
 *   - QR token populated when an active signing key exists for the tenant
 *   - QR token null (but row still emitted) when no active signing key
 */
final class ReceiptQrIndexControllerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $location;

    private Terminal $terminalA;

    private Terminal $terminalB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestData();
        Sanctum::actingAs($this->user);
    }

    public function test_returns_only_receipts_for_this_terminal(): void
    {
        // Two fiscalised receipts on terminal A, one on terminal B.
        Receipt::factory()->count(2)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminalA->id,
            'fiscal_status' => FiscalStatus::Fiscalized,
        ]);
        Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminalB->id,
            'fiscal_status' => FiscalStatus::Fiscalized,
        ]);

        $response = $this->getJson(
            "/api/v1/pos/receipts/qr-index?terminal_id={$this->terminalA->id}"
        );

        $response->assertStatus(200);
        $entries = $response->json('data.entries');
        $this->assertCount(2, $entries);
        foreach ($entries as $row) {
            $this->assertSame($this->terminalA->id, $row['terminal_id']);
        }
    }

    public function test_excludes_pending_seal_receipts(): void
    {
        // One fiscalised, one pending-seal.
        $fiscalised = Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminalA->id,
            'fiscal_status' => FiscalStatus::Fiscalized,
        ]);
        Receipt::factory()->pendingSeal()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminalA->id,
        ]);

        $response = $this->getJson(
            "/api/v1/pos/receipts/qr-index?terminal_id={$this->terminalA->id}"
        );

        $response->assertStatus(200);
        $entries = $response->json('data.entries');
        $this->assertCount(1, $entries);
        $this->assertSame($fiscalised->id, $entries[0]['receipt_uuid']);
    }

    public function test_qr_token_populated_when_active_signing_key_exists(): void
    {
        TenantSigningKey::factory()->forTenant($this->tenant)->create();

        Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminalA->id,
            'fiscal_status' => FiscalStatus::Fiscalized,
        ]);

        $response = $this->getJson(
            "/api/v1/pos/receipts/qr-index?terminal_id={$this->terminalA->id}"
        );

        $response->assertStatus(200);
        $row = $response->json('data.entries.0');
        $this->assertNotNull($row['qr_token']);
        // Token format is v:kid:receipt_uuid:mac (4 colon-separated fields).
        $parts = explode(':', $row['qr_token']);
        $this->assertCount(4, $parts);
    }

    public function test_qr_token_null_when_no_active_signing_key(): void
    {
        // No TenantSigningKey seeded — the issuer returns null.
        Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminalA->id,
            'fiscal_status' => FiscalStatus::Fiscalized,
        ]);

        $response = $this->getJson(
            "/api/v1/pos/receipts/qr-index?terminal_id={$this->terminalA->id}"
        );

        $response->assertStatus(200);
        $entries = $response->json('data.entries');
        $this->assertCount(1, $entries, 'row must still be emitted with null qr_token');
        $this->assertNull($entries[0]['qr_token']);
    }

    private function setupTestData(): void
    {
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('pos.operate_terminal', 'sanctum');
        $this->user->givePermissionTo('pos.operate_terminal');

        $this->location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);

        $this->terminalA = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'code' => 'POS-A',
        ]);

        $this->terminalB = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'code' => 'POS-B',
        ]);
    }
}
