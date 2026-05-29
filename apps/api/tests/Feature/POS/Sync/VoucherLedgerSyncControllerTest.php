<?php

declare(strict_types=1);

namespace Tests\Feature\POS\Sync;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Voucher\Application\DTOs\VoucherIssuanceRequest;
use App\Modules\Voucher\Application\Services\VoucherIssuanceService;
use App\Modules\Voucher\Domain\Enums\RedemptionMode;
use App\Modules\Voucher\Domain\Enums\VoucherEvent;
use App\Modules\Voucher\Domain\Voucher;
use App\Modules\Voucher\Domain\VoucherLedger;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Feature tests for the voucher-ledger sync endpoints (GET + POST).
 *
 * GET /api/v1/pos/voucher-ledger/sync — pulls ledger rows for vouchers
 * redeemable at the requesting terminal. Cursor: created_at (the table
 * is append-only, no updated_at).
 *
 * POST /api/v1/pos/voucher-ledger/sync — pushes locally-written ledger
 * rows from the offline POS. Each entry is processed independently with
 * idempotency via client-supplied UUID. Routes through
 * VoucherRedemptionService so the GL leg is server-authored.
 */
final class VoucherLedgerSyncControllerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $cashier;

    private Location $location;

    private Terminal $terminalA;

    private Terminal $terminalB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-voucher-ledger-sync',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX-VL',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->cashier = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Cashier',
            'email' => 'cashier-vl@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        UserCompanyMembership::create([
            'user_id' => $this->cashier->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);
        $this->cashier->givePermissionTo('pos.operate_terminal');

        // Seed Chart of Accounts so VoucherRedemptionService can post GL legs.
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $this->location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);

        $this->terminalA = $this->createTerminal('POS-A');
        $this->terminalB = $this->createTerminal('POS-B');

        // Bind CompanyContext so CurrencyScaleResolver::getScale() resolves without throwing.
        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    // -------------------------------------------------------------------------
    // GET /pos/voucher-ledger/sync
    // -------------------------------------------------------------------------

    public function test_returns_ledger_for_vouchers_redeemable_at_this_terminal(): void
    {
        Sanctum::actingAs($this->cashier);

        $voucher = $this->issueVoucherAt($this->terminalA);

        // The issuance service already wrote one Issued ledger row.
        $response = $this->getJson(
            "/api/v1/pos/voucher-ledger/sync?terminal_id={$this->terminalA->id}"
        );

        $response->assertStatus(200);
        $entries = $response->json('data.entries');
        $this->assertCount(1, $entries);
        $this->assertSame($voucher->id, $entries[0]['voucher_id']);
        $this->assertSame('Issued', $entries[0]['event']);
    }

    public function test_excludes_ledger_for_other_terminals_vouchers(): void
    {
        Sanctum::actingAs($this->cashier);

        // Voucher A on terminal A, voucher B on terminal B.
        $this->issueVoucherAt($this->terminalA);
        $this->issueVoucherAt($this->terminalB);

        $response = $this->getJson(
            "/api/v1/pos/voucher-ledger/sync?terminal_id={$this->terminalA->id}"
        );

        $response->assertStatus(200);
        $entries = $response->json('data.entries');
        $this->assertCount(1, $entries);
    }

    public function test_respects_created_at_cursor(): void
    {
        Sanctum::actingAs($this->cashier);

        $voucher = $this->issueVoucherAt($this->terminalA);

        // Backdate the issuance ledger row.
        VoucherLedger::query()
            ->where('voucher_id', $voucher->id)
            ->update(['created_at' => Carbon::now()->subDays(5)]);

        $cursor = urlencode(Carbon::now()->subDays(2)->toIso8601String());

        $response = $this->getJson(
            "/api/v1/pos/voucher-ledger/sync?terminal_id={$this->terminalA->id}&updated_since={$cursor}"
        );

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data.entries'));
    }

    public function test_serializes_event_in_pascal_case(): void
    {
        Sanctum::actingAs($this->cashier);

        $this->issueVoucherAt($this->terminalA);

        $response = $this->getJson(
            "/api/v1/pos/voucher-ledger/sync?terminal_id={$this->terminalA->id}"
        );

        $response->assertStatus(200);
        $event = $response->json('data.entries.0.event');
        $this->assertSame('Issued', $event); // NOT 'issued'
    }

    public function test_returns_synced_status_and_synced_at_for_server_pulled_rows(): void
    {
        Sanctum::actingAs($this->cashier);

        $this->issueVoucherAt($this->terminalA);

        $response = $this->getJson(
            "/api/v1/pos/voucher-ledger/sync?terminal_id={$this->terminalA->id}"
        );

        $response->assertStatus(200);
        $row = $response->json('data.entries.0');
        $this->assertSame('synced', $row['sync_status']);
        $this->assertNull($row['sync_error']);
        $this->assertSame($row['occurred_at'], $row['synced_at']);
    }

    // -------------------------------------------------------------------------
    // POST /pos/voucher-ledger/sync
    // -------------------------------------------------------------------------

    public function test_pushes_redemption_creates_ledger_row_with_gl_journal_entry_id(): void
    {
        Sanctum::actingAs($this->cashier);

        $voucher = $this->issueVoucherAt($this->terminalA, '50.00000');
        $receipt = $this->createReceiptAt($this->terminalA);

        $payload = $this->makePushEntry(
            voucher: $voucher,
            receipt: $receipt,
            event: 'Redeemed',
            amountInternalSigned: '-30.0000',
        );

        $response = $this->postJson('/api/v1/pos/voucher-ledger/sync', [
            'entries' => [$payload],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.synced', 1);
        $response->assertJsonPath('data.duplicates', 0);
        $response->assertJsonPath('data.failed', 0);
        $response->assertJsonPath('data.results.0.status', 'synced');

        // Verify a Redeemed row exists in voucher_ledger with non-null gl_journal_entry_id.
        $redeemedLedger = VoucherLedger::query()
            ->where('voucher_id', $voucher->id)
            ->where('event', VoucherEvent::Redeemed)
            ->first();

        $this->assertNotNull($redeemedLedger);
        $this->assertNotNull($redeemedLedger->gl_journal_entry_id);
    }

    public function test_idempotent_on_client_id(): void
    {
        Sanctum::actingAs($this->cashier);

        $voucher = $this->issueVoucherAt($this->terminalA, '50.00000');
        $receipt = $this->createReceiptAt($this->terminalA);

        $payload = $this->makePushEntry(
            voucher: $voucher,
            receipt: $receipt,
            event: 'Redeemed',
            amountInternalSigned: '-25.0000',
        );

        // First push: succeeds.
        $first = $this->postJson('/api/v1/pos/voucher-ledger/sync', [
            'entries' => [$payload],
        ]);
        $first->assertStatus(200);
        $first->assertJsonPath('data.results.0.status', 'synced');

        // Second push of the SAME entry: must report duplicate.
        $second = $this->postJson('/api/v1/pos/voucher-ledger/sync', [
            'entries' => [$payload],
        ]);
        $second->assertStatus(200);
        $second->assertJsonPath('data.results.0.status', 'duplicate');

        // Only ONE Redeemed ledger row in DB.
        $count = VoucherLedger::query()
            ->where('voucher_id', $voucher->id)
            ->where('event', VoucherEvent::Redeemed)
            ->count();
        $this->assertSame(1, $count);
    }

    public function test_rejects_voucher_for_other_terminal(): void
    {
        Sanctum::actingAs($this->cashier);

        // Voucher belongs to terminal B…
        $voucher = $this->issueVoucherAt($this->terminalB, '20.00000');
        $receipt = $this->createReceiptAt($this->terminalA);

        // …but the entry claims terminal A.
        $payload = $this->makePushEntry(
            voucher: $voucher,
            receipt: $receipt,
            event: 'Redeemed',
            amountInternalSigned: '-10.0000',
            terminalId: $this->terminalA->id,
        );

        $response = $this->postJson('/api/v1/pos/voucher-ledger/sync', [
            'entries' => [$payload],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.results.0.status', 'failed');
        $response->assertJsonPath('data.results.0.error', 'voucher_not_for_this_terminal');

        // No ledger row created for this voucher beyond the original Issued row.
        $count = VoucherLedger::query()
            ->where('voucher_id', $voucher->id)
            ->where('event', VoucherEvent::Redeemed)
            ->count();
        $this->assertSame(0, $count);
    }

    public function test_rejects_unknown_voucher_id(): void
    {
        Sanctum::actingAs($this->cashier);

        $payload = [
            'id' => (string) Str::uuid(),
            'voucher_id' => (string) Str::uuid(), // does not exist
            'event' => 'Redeemed',
            'amount' => '-10.0000',
            'currency' => 'EUR',
            'receipt_id' => (string) Str::uuid(),
            'terminal_id' => $this->terminalA->id,
            'user_id' => $this->cashier->id,
            'occurred_at' => Carbon::now()->toIso8601String(),
        ];

        $response = $this->postJson('/api/v1/pos/voucher-ledger/sync', [
            'entries' => [$payload],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.results.0.status', 'failed');
        $response->assertJsonPath('data.results.0.error', 'voucher_not_found');
    }

    public function test_rejects_unsupported_event_kind(): void
    {
        Sanctum::actingAs($this->cashier);

        $voucher = $this->issueVoucherAt($this->terminalA);
        $receipt = $this->createReceiptAt($this->terminalA);

        // Push an Issued event — only Redeemed/PartiallyRedeemed allowed offline.
        $payload = $this->makePushEntry(
            voucher: $voucher,
            receipt: $receipt,
            event: 'Issued',
            amountInternalSigned: '50.0000',
        );

        $response = $this->postJson('/api/v1/pos/voucher-ledger/sync', [
            'entries' => [$payload],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.results.0.status', 'failed');
        $response->assertJsonPath(
            'data.results.0.error',
            'event_kind_not_supported_in_offline_path'
        );
    }

    public function test_returns_403_without_pos_operate_terminal_permission(): void
    {
        $unprivileged = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => UserStatus::Active,
        ]);
        UserCompanyMembership::create([
            'user_id' => $unprivileged->id,
            'company_id' => $this->company->id,
            'role' => 'viewer',
        ]);

        Sanctum::actingAs($unprivileged);

        $voucher = $this->issueVoucherAt($this->terminalA);
        $receipt = $this->createReceiptAt($this->terminalA);

        $payload = $this->makePushEntry(
            voucher: $voucher,
            receipt: $receipt,
            event: 'Redeemed',
            amountInternalSigned: '-10.0000',
        );

        $response = $this->postJson('/api/v1/pos/voucher-ledger/sync', [
            'entries' => [$payload],
        ]);

        $response->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param  numeric-string  $amount
     */
    private function issueVoucherAt(Terminal $terminal, string $amount = '50.00000'): Voucher
    {
        Event::fake();

        $request = new VoucherIssuanceRequest(
            amount: $amount,
            currency: 'EUR',
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            issuedByUserId: $this->cashier->id,
            sourceReceiptId: null,
            issuedToPartnerId: null,
            issuedAtTerminalId: $terminal->id,
            expiresAt: null,
            notes: null,
            authorizedByUserId: null,
            overrideReason: null,
            policyTrigger: null,
            redemptionMode: RedemptionMode::Bearer,
        );

        return app(VoucherIssuanceService::class)->issueFromRefund($request);
    }

    private function createReceiptAt(Terminal $terminal): Receipt
    {
        return Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $terminal->id,
            'cashier_id' => $this->cashier->id,
            'currency' => 'EUR',
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function makePushEntry(
        Voucher $voucher,
        Receipt $receipt,
        string $event,
        string $amountInternalSigned,
        ?string $terminalId = null,
    ): array {
        return [
            'id' => (string) Str::uuid(),
            'voucher_id' => $voucher->id,
            'event' => $event,
            'amount' => $amountInternalSigned,
            'currency' => 'EUR',
            'receipt_id' => $receipt->id,
            'terminal_id' => $terminalId ?? $voucher->redeemable_at_terminal_id ?? $this->terminalA->id,
            'user_id' => $this->cashier->id,
            'occurred_at' => Carbon::now()->toIso8601String(),
        ];
    }

    private function createTerminal(string $code): Terminal
    {
        return Terminal::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'code' => $code,
            'name' => "Terminal {$code}",
            'genesis_seed' => str_repeat('0', 64),
            'current_sequence' => 0,
            'current_year' => (int) date('Y'),
            'is_active' => true,
            'max_discount_percent' => 20.00,
            'allow_line_discounts' => true,
            'allow_transaction_discounts' => true,
        ]);
    }
}
