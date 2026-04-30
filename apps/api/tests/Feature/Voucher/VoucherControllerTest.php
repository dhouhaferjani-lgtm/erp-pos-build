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
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Feature tests for VoucherController (Phase H Stage 1).
 *
 * Covers paginated listing, filtering, tenant isolation, detail with ledger,
 * goodwill issuance (happy path + four-eyes guard), void, transfer, and extend-expiry.
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

        // Seed canonical permissions for primary tenant, then grant to primary user.
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user->givePermissionTo('pos.void_voucher');
        $this->user->givePermissionTo('pos.issue_goodwill_voucher');
        $this->user->givePermissionTo('pos.transfer_voucher');
        $this->user->givePermissionTo('pos.extend_voucher_expiry');

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
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->otherUser->givePermissionTo('pos.void_voucher');
        $this->otherUser->givePermissionTo('pos.issue_goodwill_voucher');
        $this->otherUser->givePermissionTo('pos.transfer_voucher');
        $this->otherUser->givePermissionTo('pos.extend_voucher_expiry');
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

        // Assert denormalized fields are present in list items
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id', 'code', 'source', 'status', 'partner_name',
                    'cashier_id', 'cashier_name', 'terminal_id', 'terminal_name', 'created_at',
                ],
            ],
        ]);
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
    // GET /api/v1/vouchers — OR-gate permission tests
    // -------------------------------------------------------------------------

    public function test_index_succeeds_for_user_with_only_void_voucher_permission(): void
    {
        $voidOnlyUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $voidOnlyUser->id,
            'company_id' => $this->company->id,
            'role' => 'manager',
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $voidOnlyUser->givePermissionTo('pos.void_voucher');

        Sanctum::actingAs($voidOnlyUser);

        $response = $this->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/vouchers');

        $response->assertOk();
    }

    public function test_index_succeeds_for_user_with_only_issue_goodwill_permission(): void
    {
        $goodwillOnlyUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $goodwillOnlyUser->id,
            'company_id' => $this->company->id,
            'role' => 'manager',
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $goodwillOnlyUser->givePermissionTo('pos.issue_goodwill_voucher');

        Sanctum::actingAs($goodwillOnlyUser);

        $response = $this->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/vouchers');

        $response->assertOk();
    }

    public function test_index_returns_403_for_user_with_neither_voucher_permission(): void
    {
        $noPermUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $noPermUser->id,
            'company_id' => $this->company->id,
            'role' => 'cashier',
        ]);

        Sanctum::actingAs($noPermUser);

        $response = $this->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/vouchers');

        $response->assertForbidden();
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
            'id' => (string) Str::uuid(),
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

        // Assert denormalized voucher fields
        $response->assertJsonStructure([
            'data' => [
                'partner_name', 'cashier_id', 'cashier_name',
                'terminal_id', 'terminal_name', 'created_at',
            ],
        ]);
        // The issuing user name is resolved via issuedBy relation
        $this->assertSame($this->user->name, $response->json('data.cashier_name'));

        // Assert ledger row shape
        $response->assertJsonStructure([
            'data' => [
                'ledger' => [
                    '*' => [
                        'id', 'event', 'amount', 'receipt_id', 'receipt_number',
                        'terminal_id', 'terminal_name', 'user_id', 'user_name',
                        'policy_trigger', 'occurred_at',
                    ],
                ],
            ],
        ]);
        $this->assertSame($this->user->name, $response->json('data.ledger.0.user_name'));
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

    public function test_show_returns_403_for_user_with_neither_voucher_permission(): void
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
            ->getJson("/api/v1/vouchers/{$voucher->id}");

        $response->assertForbidden();
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

        $partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        // No chart-of-accounts seed needed — the exception fires before any GL call
        $response = $this->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/vouchers/issue-goodwill', [
                'amount' => '1000.00', // clearly exceeds 250.00 four-eyes threshold
                'currency' => 'EUR',
                'partner_id' => $partner->id, // named customer — passes that check
                'redemption_mode' => RedemptionMode::Bearer->value,
                'expires_at' => null,
                'notes' => 'Large goodwill gesture',
                'terminal_id' => null,
                'second_admin_user_id' => null, // missing — must trigger four-eyes 422
            ]);

        $response->assertUnprocessable();
        $response->assertJsonPath('error.code', 'FOUR_EYES_REQUIRED');
        $this->assertStringContainsString(
            'four-eyes approval threshold',
            (string) $response->json('error.message')
        );
    }

    public function test_issue_goodwill_201_when_amount_over_threshold_and_second_admin_provided(): void
    {
        Sanctum::actingAs($this->user);

        // Seed chart of accounts — the GL write fires on this path
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $secondAdmin = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $secondAdmin->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $response = $this->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/vouchers/issue-goodwill', [
                'amount' => '1000.00',
                'currency' => 'EUR',
                'partner_id' => $partner->id,
                'redemption_mode' => RedemptionMode::Bearer->value,
                'expires_at' => null,
                'notes' => 'High-value goodwill with second-admin approval',
                'terminal_id' => null,
                'second_admin_user_id' => $secondAdmin->id,
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.source', VoucherSource::Goodwill->value);
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

    public function test_void_persists_reason_to_override_reason_and_notes(): void
    {
        Sanctum::actingAs($this->user);

        $voucher = Voucher::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'issued_by_user_id' => $this->user->id,
            'status' => VoucherStatus::Issued,
            'current_balance' => '50.00000',
        ]);

        $reason = 'Issued in error by cashier';

        $this->withHeader('X-Company-Id', $this->company->id)
            ->postJson("/api/v1/vouchers/{$voucher->id}/void", [
                'reason' => $reason,
            ]);

        $voucher->refresh();

        $this->assertSame($reason, $voucher->override_reason);
        $this->assertStringContainsString('[VOID ', (string) $voucher->notes);
        $this->assertStringContainsString($reason, (string) $voucher->notes);
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

    public function test_transfer_appends_reason_to_notes(): void
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

        $reason = 'Customer requested transfer to spouse';

        $this->withHeader('X-Company-Id', $this->company->id)
            ->postJson("/api/v1/vouchers/{$voucher->id}/transfer", [
                'to_partner_id' => $partner->id,
                'reason' => $reason,
            ]);

        $voucher->refresh();

        $this->assertStringContainsString('[TRANSFER ', (string) $voucher->notes);
        $this->assertStringContainsString($partner->id, (string) $voucher->notes);
        $this->assertStringContainsString($reason, (string) $voucher->notes);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/vouchers/{id}/extend-expiry
    // -------------------------------------------------------------------------

    public function test_extend_expiry_persists_new_expires_at_and_notes(): void
    {
        Sanctum::actingAs($this->user);

        $voucher = Voucher::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'issued_by_user_id' => $this->user->id,
            'status' => VoucherStatus::Issued,
            'expires_at' => now()->addDays(30)->toDateString(),
        ]);

        $newExpiry = now()->addDays(90)->toDateString();
        $reason = 'Customer on extended sick leave';

        $response = $this->withHeader('X-Company-Id', $this->company->id)
            ->postJson("/api/v1/vouchers/{$voucher->id}/extend-expiry", [
                'new_expires_at' => $newExpiry,
                'reason' => $reason,
            ]);

        $response->assertOk();

        $voucher->refresh();

        $this->assertSame($newExpiry, $voucher->expires_at?->toDateString());
        $this->assertStringContainsString('[EXPIRY-EXTENDED ', (string) $voucher->notes);
        $this->assertStringContainsString($reason, (string) $voucher->notes);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/vouchers — N+1 eager-load guard
    // -------------------------------------------------------------------------

    public function test_index_eager_loads_partner_terminal_cashier_to_avoid_n_plus_1(): void
    {
        Sanctum::actingAs($this->user);

        // Seed 3 vouchers each with a distinct partner, terminal, and cashier.
        foreach (range(1, 3) as $i) {
            $partner = Partner::factory()->create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
            ]);
            $cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);

            Voucher::factory()->create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'issued_by_user_id' => $cashier->id,
                'partner_id' => $partner->id,
                'issued_at_terminal_id' => null,
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/vouchers');

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk();
        $response->assertJsonPath('meta.total', 3);

        // With eager loading we expect: 1 auth + 1 company context + 1 main SELECT +
        // 1 partner eager + 1 terminal eager + 1 issuedBy eager + pagination count = ≤ 8.
        // Without eager loading this would be 3 × 3 + base = 11+.
        $this->assertLessThan(8, $queryCount, "Expected fewer than 8 queries but got {$queryCount} — eager loading may have been dropped.");

        // Assert denormalized names are resolved
        $data = $response->json('data');
        foreach ($data as $row) {
            $this->assertArrayHasKey('partner_name', $row);
            $this->assertArrayHasKey('cashier_name', $row);
            $this->assertNotNull($row['cashier_name']);
        }
    }

    // -------------------------------------------------------------------------
    // Fix I1 — Manager can extend voucher expiry
    // -------------------------------------------------------------------------

    public function test_manager_can_extend_voucher_expiry(): void
    {
        // Create a manager user for this tenant
        $manager = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $manager->id,
            'company_id' => $this->company->id,
            'role' => 'manager',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

        // Assign via role (the seeder now includes pos.extend_voucher_expiry in Manager)
        $manager->assignRole('manager');

        Sanctum::actingAs($manager);

        $voucher = Voucher::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'issued_by_user_id' => $this->user->id,
            'status' => VoucherStatus::Issued,
            'expires_at' => now()->addDays(30)->toDateString(),
        ]);

        $newExpiry = now()->addDays(90)->toDateString();

        $response = $this->withHeader('X-Company-Id', $this->company->id)
            ->postJson("/api/v1/vouchers/{$voucher->id}/extend-expiry", [
                'new_expires_at' => $newExpiry,
                'reason' => 'Manager-approved extension for loyalty customer',
            ]);

        // Must be 200 — not 403 — because Manager now holds pos.extend_voucher_expiry
        $response->assertOk();
    }
}
