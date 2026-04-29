<?php

declare(strict_types=1);

namespace Tests\Feature\Voucher;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Voucher\Domain\Enums\RedemptionMode;
use App\Modules\Voucher\Domain\Enums\VoucherEvent;
use App\Modules\Voucher\Domain\Enums\VoucherSource;
use App\Modules\Voucher\Domain\Enums\VoucherStatus;
use App\Modules\Voucher\Domain\Voucher;
use App\Modules\Voucher\Domain\VoucherLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Feature tests for VoucherController (Phase H Stage 1).
 *
 * Covers paginated listing, filtering, tenant isolation, detail with ledger,
 * goodwill issuance (happy path + four-eyes guard), void, and transfer.
 */
final class VoucherControllerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Tenant $otherTenant;

    private Company $otherCompany;

    private User $otherUser;

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

        // Grant voucher permissions for primary user
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->grantPermission($this->user, 'pos.void_voucher');
        $this->grantPermission($this->user, 'pos.issue_goodwill_voucher');
        $this->grantPermission($this->user, 'pos.transfer_voucher');
        $this->grantPermission($this->user, 'pos.extend_voucher_expiry');

        // Second tenant for isolation tests
        $this->otherTenant = Tenant::factory()->create();
        $this->otherCompany = Company::factory()->create(['tenant_id' => $this->otherTenant->id]);
        $this->otherUser = User::factory()->create(['tenant_id' => $this->otherTenant->id]);

        UserCompanyMembership::create([
            'user_id' => $this->otherUser->id,
            'company_id' => $this->otherCompany->id,
            'role' => 'admin',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->otherTenant->id);
        $this->grantPermission($this->otherUser, 'pos.void_voucher');
        $this->grantPermission($this->otherUser, 'pos.issue_goodwill_voucher');
        $this->grantPermission($this->otherUser, 'pos.transfer_voucher');
        $this->grantPermission($this->otherUser, 'pos.extend_voucher_expiry');
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/vouchers — index
    // -------------------------------------------------------------------------

    public function test_index_returns_paginated_list_with_meta(): void
    {
        Sanctum::actingAs($this->user);

        Voucher::factory()->count(3)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'issued_by_user_id' => $this->user->id,
        ]);

        $response = $this->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/vouchers');

        $response->assertOk();
        $response->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'last_page', 'total', 'per_page'],
        ]);
        $response->assertJsonPath('meta.total', 3);
    }

    public function test_index_filtered_by_source_returns_only_that_source(): void
    {
        Sanctum::actingAs($this->user);

        Voucher::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'source' => VoucherSource::Refund,
            'issued_by_user_id' => $this->user->id,
        ]);

        Voucher::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'source' => VoucherSource::Goodwill,
            'issued_by_user_id' => $this->user->id,
        ]);

        $response = $this->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/vouchers?source=refund');

        $response->assertOk();
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('data.0.source', 'refund');
    }

    public function test_index_tenant_isolation_does_not_leak_other_tenant_vouchers(): void
    {
        Sanctum::actingAs($this->user);

        // Voucher for our tenant
        Voucher::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'issued_by_user_id' => $this->user->id,
        ]);

        // Voucher for another tenant — must NOT appear
        Voucher::factory()->create([
            'tenant_id' => $this->otherTenant->id,
            'company_id' => $this->otherCompany->id,
            'issued_by_user_id' => $this->otherUser->id,
        ]);

        $response = $this->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/vouchers');

        $response->assertOk();
        $response->assertJsonPath('meta.total', 1);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/vouchers/{id} — show
    // -------------------------------------------------------------------------

    public function test_show_returns_voucher_with_ledger_array(): void
    {
        Sanctum::actingAs($this->user);

        $voucher = Voucher::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'issued_by_user_id' => $this->user->id,
        ]);

        // Create a ledger row manually
        VoucherLedger::forceCreate([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'voucher_id' => $voucher->id,
            'event' => VoucherEvent::Issued,
            'amount' => '50.00000',
            'currency' => 'EUR',
            'receipt_id' => null,
            'terminal_id' => null,
            'user_id' => $this->user->id,
            'gl_journal_entry_id' => null,
            'authorized_by_user_id' => null,
            'policy_trigger' => null,
            'reverses_voucher_ledger_id' => null,
            'occurred_at' => now(),
        ]);

        $response = $this->withHeader('X-Company-Id', $this->company->id)
            ->getJson("/api/v1/vouchers/{$voucher->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $voucher->id);
        $response->assertJsonStructure(['data' => ['ledger', 'provenance']]);
        $this->assertCount(1, $response->json('data.ledger'));
    }

    public function test_show_returns_404_on_wrong_tenant(): void
    {
        Sanctum::actingAs($this->user);

        // Voucher belonging to the other tenant
        $foreignVoucher = Voucher::factory()->create([
            'tenant_id' => $this->otherTenant->id,
            'company_id' => $this->otherCompany->id,
            'issued_by_user_id' => $this->otherUser->id,
        ]);

        $response = $this->withHeader('X-Company-Id', $this->company->id)
            ->getJson("/api/v1/vouchers/{$foreignVoucher->id}");

        $response->assertNotFound();
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/vouchers/issue-goodwill
    // -------------------------------------------------------------------------

    public function test_issue_goodwill_creates_voucher_and_ledger_row(): void
    {
        Sanctum::actingAs($this->user);

        // Seed chart of accounts required by the GL service
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $response = $this->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/vouchers/issue-goodwill', [
                'amount' => '50.00',
                'currency' => 'EUR',
                'partner_id' => null,
                'redemption_mode' => RedemptionMode::Bearer->value,
                'expires_at' => null,
                'notes' => 'Customer satisfaction gesture',
                'terminal_id' => null,
                'second_admin_user_id' => null,
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.source', VoucherSource::Goodwill->value);

        $this->assertDatabaseHas('vouchers', [
            'company_id' => $this->company->id,
            'source' => VoucherSource::Goodwill->value,
        ]);

        $voucherId = $response->json('data.id');
        $this->assertDatabaseHas('voucher_ledger', [
            'voucher_id' => $voucherId,
            'event' => VoucherEvent::Issued->value,
        ]);
    }

    public function test_issue_goodwill_422_when_amount_over_four_eyes_threshold_without_second_admin(): void
    {
        Sanctum::actingAs($this->user);

        // No chart-of-accounts seed needed — the exception fires before any GL call
        $response = $this->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/vouchers/issue-goodwill', [
                'amount' => '300.00', // exceeds 250.00 four-eyes threshold
                'currency' => 'EUR',
                'partner_id' => Partner::factory()->create([
                    'tenant_id' => $this->tenant->id,
                    'company_id' => $this->company->id,
                ])->id,
                'redemption_mode' => RedemptionMode::Bearer->value,
                'expires_at' => null,
                'notes' => 'Large goodwill gesture',
                'terminal_id' => null,
                'second_admin_user_id' => null, // missing — should 422
            ]);

        $response->assertUnprocessable();
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/vouchers/{id}/void
    // -------------------------------------------------------------------------

    public function test_void_writes_voided_ledger_row(): void
    {
        Sanctum::actingAs($this->user);

        $voucher = Voucher::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'issued_by_user_id' => $this->user->id,
            'status' => VoucherStatus::Issued,
            'current_balance' => '50.00000',
        ]);

        $response = $this->withHeader('X-Company-Id', $this->company->id)
            ->postJson("/api/v1/vouchers/{$voucher->id}/void", [
                'reason' => 'Issued in error by cashier',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', VoucherStatus::Voided->value);

        $this->assertDatabaseHas('voucher_ledger', [
            'voucher_id' => $voucher->id,
            'event' => VoucherEvent::Voided->value,
        ]);
    }

    public function test_void_returns_403_without_permission(): void
    {
        $noPermUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $noPermUser->id,
            'company_id' => $this->company->id,
            'role' => 'cashier',
        ]);

        Sanctum::actingAs($noPermUser);

        $voucher = Voucher::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'issued_by_user_id' => $this->user->id,
        ]);

        $response = $this->withHeader('X-Company-Id', $this->company->id)
            ->postJson("/api/v1/vouchers/{$voucher->id}/void", [
                'reason' => 'Issued in error',
            ]);

        $response->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/vouchers/{id}/transfer
    // -------------------------------------------------------------------------

    public function test_transfer_updates_partner_id_and_writes_transferred_ledger_row(): void
    {
        Sanctum::actingAs($this->user);

        $partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $voucher = Voucher::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'issued_by_user_id' => $this->user->id,
            'partner_id' => null,
        ]);

        $response = $this->withHeader('X-Company-Id', $this->company->id)
            ->postJson("/api/v1/vouchers/{$voucher->id}/transfer", [
                'to_partner_id' => $partner->id,
                'reason' => 'Customer requested transfer to spouse',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.partner_id', $partner->id);

        $this->assertDatabaseHas('voucher_ledger', [
            'voucher_id' => $voucher->id,
            'event' => VoucherEvent::Transferred->value,
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function grantPermission(User $user, string $permission): void
    {
        $perm = Permission::findOrCreate($permission, 'sanctum');
        $user->givePermissionTo($perm);
    }
}
