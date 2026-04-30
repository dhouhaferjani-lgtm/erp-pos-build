<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Application\Services\ReceiptQrIndexSyncService;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Feature tests for ReceiptQrIndexSyncService and the HTTP endpoint it backs.
 *
 * Codex review finding M2: partner_id must flow from Receipt → DTO → wire payload.
 */
final class ReceiptQrIndexSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $location;

    private Terminal $terminal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('pos.operate_terminal', 'sanctum');
        $this->user->givePermissionTo('pos.operate_terminal');
        $this->location = Location::factory()->create(['company_id' => $this->company->id]);
        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);
    }

    /**
     * Test 1: Receipt with a non-null partner_id → DTO carries the partner UUID.
     */
    public function test_dto_carries_partner_id_when_receipt_has_a_partner(): void
    {
        $partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $partnerId = $partner->id;

        $receipt = Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'fiscal_status' => FiscalStatus::Fiscalized,
            'partner_id' => $partnerId,
        ]);

        /** @var ReceiptQrIndexSyncService $service */
        $service = app(ReceiptQrIndexSyncService::class);
        $rows = $service->pull($this->terminal->id, null);

        $this->assertCount(1, $rows);
        $this->assertSame($partnerId, $rows[0]->partnerId);
        $this->assertSame($partnerId, $rows[0]->toArray()['partner_id']);
    }

    /**
     * Test 2: Receipt with null partner_id → DTO carries null.
     */
    public function test_dto_carries_null_when_receipt_has_no_partner(): void
    {
        Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'fiscal_status' => FiscalStatus::Fiscalized,
            'partner_id' => null,
        ]);

        /** @var ReceiptQrIndexSyncService $service */
        $service = app(ReceiptQrIndexSyncService::class);
        $rows = $service->pull($this->terminal->id, null);

        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]->partnerId);
        $this->assertNull($rows[0]->toArray()['partner_id']);
    }

    /**
     * Test 3: HTTP GET /api/v1/pos/receipts/qr-index → response rows include partner_id field.
     */
    public function test_http_response_includes_partner_id_field(): void
    {
        Sanctum::actingAs($this->user);

        $partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $partnerId = $partner->id;

        Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'fiscal_status' => FiscalStatus::Fiscalized,
            'partner_id' => $partnerId,
        ]);
        Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'fiscal_status' => FiscalStatus::Fiscalized,
            'partner_id' => null,
        ]);

        $response = $this->getJson(
            "/api/v1/pos/receipts/qr-index?terminal_id={$this->terminal->id}"
        );

        $response->assertStatus(200);

        /** @var list<array<string, mixed>> $entries */
        $entries = $response->json('data.entries');
        $this->assertCount(2, $entries);

        // Each row must expose the partner_id key (present — value may be string or null)
        foreach ($entries as $row) {
            $this->assertArrayHasKey('partner_id', $row);
        }

        // The row with a partner should carry the UUID; the other should be null.
        $withPartner = null;
        $withoutPartner = null;
        foreach ($entries as $row) {
            if ($row['partner_id'] === $partnerId) {
                $withPartner = $row;
            } elseif ($row['partner_id'] === null) {
                $withoutPartner = $row;
            }
        }

        $this->assertNotNull($withPartner, 'Expected a row with partner_id set');
        $this->assertNotNull($withoutPartner, 'Expected a row with partner_id null');
        $this->assertSame($partnerId, $withPartner['partner_id']);
    }
}
