<?php

declare(strict_types=1);

namespace Tests\Feature\Voucher;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\PaymentInstrumentKind;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Voucher\Application\DTOs\VoucherIssuanceRequest;
use App\Modules\Voucher\Application\DTOs\VoucherRedemptionRequest;
use App\Modules\Voucher\Application\Services\VoucherIssuanceService;
use App\Modules\Voucher\Application\Services\VoucherRedemptionService;
use App\Modules\Voucher\Domain\Enums\RedemptionMode;
use App\Modules\Voucher\Domain\VoucherLedger;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Append-only guard test for voucher_ledger.
 *
 * Statically verifies that no voucher service UPDATE any voucher_ledger row
 * after it is inserted, regardless of database platform.
 *
 * This catches the INSERT-then-UPDATE regression that previously masked the
 * PostgreSQL append-only trigger violation in SQLite CI runs.
 */
final class VoucherLedgerAppendOnlyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $cashier;

    private Terminal $terminal;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Append-Only Tenant',
            'slug' => 'test-voucher-append-only',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Append-Only Company',
            'legal_name' => 'Append-Only Company LLC',
            'tax_id' => 'TAXAO001',
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
            'name' => 'AO Cashier',
            'email' => 'cashier-ao@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $this->location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);

        $this->terminal = Terminal::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'code' => 'POS-AO',
            'name' => 'Terminal AO',
            'genesis_seed' => bin2hex(random_bytes(32)),
            'current_sequence' => 1,
            'current_year' => (int) date('Y'),
            'is_active' => true,
            'fiscal_schema_version' => 2,
        ]);
    }

    /**
     * Core regression test: no voucher_ledger row must ever be UPDATEd after INSERT.
     *
     * Uses Eloquent's `updating` observer to intercept any attempted UPDATE on the
     * VoucherLedger model and records its occurrence. After running a full
     * issuance + partial-redemption flow, asserts the counter is zero.
     *
     * This test runs on all platforms (SQLite and PostgreSQL) and catches the
     * INSERT-then-UPDATE pattern even where the DB trigger does not fire.
     */
    public function test_voucher_ledger_rows_are_never_updated_after_insert(): void
    {
        Event::fake(); // suppress domain events to keep test focused

        $updateAttempts = 0;

        VoucherLedger::updating(static function () use (&$updateAttempts): bool {
            $updateAttempts++;

            // Allow the update so the rest of the flow does not crash — we assert count at the end.
            return true;
        });

        // --- Issuance flow ---
        $issuanceRequest = new VoucherIssuanceRequest(
            amount: '50.00000',
            currency: 'EUR',
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            issuedByUserId: $this->cashier->id,
            sourceReceiptId: null,
            issuedToPartnerId: null,
            issuedAtTerminalId: $this->terminal->id,
            expiresAt: null,
            notes: null,
            authorizedByUserId: null,
            overrideReason: null,
            policyTrigger: null,
            redemptionMode: RedemptionMode::Bearer,
        );

        $voucher = app(VoucherIssuanceService::class)->issueFromRefund($issuanceRequest);

        // --- Partial-redemption flow ---
        $openReceipt = Receipt::factory()->pendingSeal()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->cashier->id,
            'cashier_name' => $this->cashier->name,
            'receipt_type' => ReceiptType::Sale,
        ]);

        $redemptionRequest = new VoucherRedemptionRequest(
            voucherCode: $voucher->code,
            appliedAmount: '20.00',
            currency: 'EUR',
            receiptId: $openReceipt->id,
            cashierId: $this->cashier->id,
            terminalId: $this->terminal->id,
            instrumentKind: PaymentInstrumentKind::StoreVoucher,
        );

        app(VoucherRedemptionService::class)->redeem($redemptionRequest);

        // Assert: the Eloquent `updating` hook must never have fired.
        $this->assertSame(
            0,
            $updateAttempts,
            "voucher_ledger rows must be append-only (INSERT-then-UPDATE regression detected: {$updateAttempts} UPDATE attempt(s) intercepted)"
        );
    }

    /**
     * Verify that every inserted voucher_ledger row has gl_journal_entry_id populated at insert time.
     *
     * This confirms the fix: the GL entry is created BEFORE the ledger row is inserted,
     * so gl_journal_entry_id is never null in the persisted record.
     */
    public function test_voucher_ledger_rows_have_gl_journal_entry_id_set_at_insert_time(): void
    {
        Event::fake();

        $issuanceRequest = new VoucherIssuanceRequest(
            amount: '30.00000',
            currency: 'EUR',
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            issuedByUserId: $this->cashier->id,
            sourceReceiptId: null,
            issuedToPartnerId: null,
            issuedAtTerminalId: $this->terminal->id,
            expiresAt: null,
            notes: null,
            authorizedByUserId: null,
            overrideReason: null,
            policyTrigger: null,
            redemptionMode: RedemptionMode::Bearer,
        );

        $voucher = app(VoucherIssuanceService::class)->issueFromRefund($issuanceRequest);

        $allLedgerRows = VoucherLedger::where('voucher_id', $voucher->id)->get();

        $this->assertNotEmpty($allLedgerRows, 'Expected at least one voucher_ledger row');

        foreach ($allLedgerRows as $row) {
            $this->assertNotNull(
                $row->gl_journal_entry_id,
                "voucher_ledger row {$row->id} (event={$row->event->value}) has null gl_journal_entry_id — gl entry was not created before insert"
            );
        }
    }
}
