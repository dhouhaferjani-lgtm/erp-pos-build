<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Domain\ValueObjects\ReservationSettings;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\ReceiptReturnService;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Enums\RefundDestination;
use App\Modules\POS\Domain\Enums\ReturnReason;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Exceptions\DailyRefundCapExceededException;
use App\Modules\POS\Domain\Exceptions\ManagerOverrideRequiredException;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptLine;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Voucher\Domain\VoucherLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase E integration tests — ReceiptReturnService refactor.
 *
 * Covers:
 *   Task 27: audit fields persisted on return receipt
 *   Task 28: return-receipt drafted as pending_seal (chain advance via finalization only)
 *   Task 29: RefundDestinationResolver wired — out-of-window voucher_only forces StoreVoucher
 *   Task 30: voucher issuance happens BEFORE finalization (VoucherLedger receipt_id = return receipt)
 *   Task 31: manager-override threshold + daily cap enforcement
 *   Task 32: ReceiptFinalizationService seals; chain_sequence and fiscal_hash set
 *   Task 33: rollback on mid-transaction failure; DB-level idempotency replay
 */
final class ReceiptReturnRefactorTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $cashier;

    private Location $location;

    private Terminal $terminal;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFixtures();
    }

    // =========================================================================
    // Task 28 — pending_seal draft then finalization
    // =========================================================================

    public function test_return_receipt_is_fiscalized_after_process_return(): void
    {
        $saleReceipt = $this->createSaleReceipt();
        $line = $this->createLine($saleReceipt, '3.000', '30.000');

        $returnReceipt = $this->callProcessReturn($saleReceipt, $line, '2');

        // Sealed by ReceiptFinalizationService
        $this->assertSame(FiscalStatus::Fiscalized, $returnReceipt->fiscal_status);
        $this->assertNotNull($returnReceipt->fiscal_hash);
        $this->assertNotNull($returnReceipt->chain_sequence);
    }

    public function test_terminal_chain_advances_exactly_once(): void
    {
        $sequenceBefore = $this->terminal->fresh()->current_sequence;

        $saleReceipt = $this->createSaleReceipt();
        $line = $this->createLine($saleReceipt, '3.000', '30.000');

        $this->callProcessReturn($saleReceipt, $line, '1');

        $this->terminal->refresh();
        $this->assertSame($sequenceBefore + 1, $this->terminal->current_sequence);
    }

    public function test_return_receipt_type_is_return_and_totals_are_negative(): void
    {
        $saleReceipt = $this->createSaleReceipt();
        $line = $this->createLine($saleReceipt, '4.000', '40.000');

        $returnReceipt = $this->callProcessReturn($saleReceipt, $line, '2');

        $this->assertSame(ReceiptType::Return, $returnReceipt->receipt_type);
        $this->assertTrue(
            bccomp((string) $returnReceipt->total, '0', 3) < 0,
            'Return receipt total should be negative'
        );
    }

    // =========================================================================
    // Task 27 — audit fields persisted on return receipt
    // =========================================================================

    public function test_audit_fields_persisted_on_return_receipt(): void
    {
        $saleReceipt = $this->createSaleReceipt();
        $line = $this->createLine($saleReceipt, '3.000', '30.000');
        $managerId = (string) $this->cashier->id; // use cashier as manager for test simplicity

        $returnReceipt = $this->callProcessReturn(
            saleReceipt: $saleReceipt,
            line: $line,
            qty: '1',
            authorizedByUserId: $managerId,
            overrideReason: 'Customer insistence',
        );

        $this->assertSame($managerId, $returnReceipt->authorized_by_user_id);
        $this->assertSame('Customer insistence', $returnReceipt->override_reason);
    }

    public function test_refund_request_id_persisted_on_return_receipt(): void
    {
        $saleReceipt = $this->createSaleReceipt();
        $line = $this->createLine($saleReceipt, '3.000', '30.000');
        $requestId = (string) Str::uuid();

        $returnReceipt = $this->callProcessReturn(
            saleReceipt: $saleReceipt,
            line: $line,
            qty: '1',
            refundRequestId: $requestId,
        );

        $this->assertSame($requestId, $returnReceipt->refund_request_id);
    }

    // =========================================================================
    // Task 31 — manager override threshold
    // =========================================================================

    public function test_throws_manager_override_required_when_refund_exceeds_threshold(): void
    {
        // Set a very low override threshold so a normal return triggers it
        $this->setCompanyPolicy([
            'manager_override_threshold_amount' => '0.01',
            'manager_override_threshold_percent' => '0',
            'allowed_refund_destinations' => ['cash'],
        ]);

        $saleReceipt = $this->createSaleReceipt();
        $line = $this->createLine($saleReceipt, '3.000', '30.000');

        $this->expectException(ManagerOverrideRequiredException::class);

        $this->callProcessReturn($saleReceipt, $line, '1');
    }

    public function test_does_not_throw_manager_override_when_authorizer_supplied(): void
    {
        $this->setCompanyPolicy([
            'manager_override_threshold_amount' => '0.01',
            'manager_override_threshold_percent' => '0',
            'allowed_refund_destinations' => ['cash'],
        ]);

        $saleReceipt = $this->createSaleReceipt();
        $line = $this->createLine($saleReceipt, '3.000', '30.000');

        // Should NOT throw when authorized_by_user_id is provided
        $returnReceipt = $this->callProcessReturn(
            saleReceipt: $saleReceipt,
            line: $line,
            qty: '1',
            authorizedByUserId: (string) $this->cashier->id,
        );

        $this->assertSame(FiscalStatus::Fiscalized, $returnReceipt->fiscal_status);
        $this->assertSame('over_threshold', $returnReceipt->policy_trigger);
    }

    // =========================================================================
    // Task 31 — daily refund cap
    // =========================================================================

    public function test_throws_daily_cap_exceeded_when_cap_reached(): void
    {
        $this->setCompanyPolicy([
            'daily_refund_cap_per_cashier' => '5.00',
            'daily_refund_cap_override_allowed' => false,
            'manager_override_threshold_amount' => '99999.00', // Don't block for manager override
            'allowed_refund_destinations' => ['cash'],
        ]);

        // First return: within cap (small amount)
        $saleReceipt1 = $this->createSaleReceipt('3.000', '3.000');
        $line1 = $this->createLine($saleReceipt1, '1.000', '3.000');
        $this->callProcessReturn($saleReceipt1, $line1, '1');

        // Second return would exceed the 5.00 cap
        $saleReceipt2 = $this->createSaleReceipt('100.000', '100.000');
        $line2 = $this->createLine($saleReceipt2, '1.000', '100.000');

        $this->expectException(DailyRefundCapExceededException::class);

        $this->callProcessReturn($saleReceipt2, $line2, '1');
    }

    public function test_daily_cap_not_enforced_when_cap_is_null(): void
    {
        $this->setCompanyPolicy([
            'daily_refund_cap_per_cashier' => null,
            'manager_override_threshold_amount' => '99999.00', // Disable override threshold
            'allowed_refund_destinations' => ['cash'],
        ]);

        $saleReceipt = $this->createSaleReceipt('200.000', '200.000');
        $line = $this->createLine($saleReceipt, '1.000', '200.000');

        // Should NOT throw
        $returnReceipt = $this->callProcessReturn($saleReceipt, $line, '1');

        $this->assertSame(FiscalStatus::Fiscalized, $returnReceipt->fiscal_status);
    }

    // =========================================================================
    // Task 29 — RefundDestinationResolver integration
    // =========================================================================

    public function test_out_of_window_voucher_only_policy_sets_out_of_window_flag(): void
    {
        // Seed GL accounts required for voucher issuance
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $this->setCompanyPolicy([
            'customer_return_expiry_days' => 1,
            'out_of_window_policy' => 'voucher_only',
            'allowed_refund_destinations' => ['cash', 'store_voucher'],
            'voucher_default_expiry_days' => 365,
            'manager_override_threshold_amount' => '99999.00',
        ]);

        // Original receipt posted 2 days ago → out of window
        $saleReceipt = $this->createSaleReceipt();
        $saleReceipt->posted_at = Carbon::now()->subDays(2);
        $saleReceipt->save();

        $line = $this->createLine($saleReceipt, '3.000', '30.000');

        // Requesting Cash but policy forces StoreVoucher for out-of-window
        // The issuance creates a VoucherLedger row
        $returnReceipt = $this->callProcessReturn(
            saleReceipt: $saleReceipt,
            line: $line,
            qty: '1',
            destination: RefundDestination::Cash,
        );

        $returnReceipt->refresh();
        $this->assertTrue((bool) $returnReceipt->out_of_window);
        $this->assertSame('out_of_window', $returnReceipt->policy_trigger);

        // Voucher ledger entry should be linked to the return receipt
        $this->assertDatabaseHas('voucher_ledger', [
            'receipt_id' => $returnReceipt->id,
        ]);
    }

    // =========================================================================
    // Task 30 — voucher issuance BEFORE finalization (receipt_id on VoucherLedger)
    // =========================================================================

    public function test_voucher_ledger_receipt_id_matches_return_receipt(): void
    {
        // Seed GL accounts required for voucher issuance
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $this->setCompanyPolicy([
            'allowed_refund_destinations' => ['store_voucher'],
            'voucher_default_expiry_days' => 365,
            'customer_return_expiry_days' => 90,
            'out_of_window_policy' => 'voucher_only',
            'manager_override_threshold_amount' => '99999.00',
        ]);

        $saleReceipt = $this->createSaleReceipt();
        $line = $this->createLine($saleReceipt, '3.000', '30.000');

        $returnReceipt = $this->callProcessReturn(
            saleReceipt: $saleReceipt,
            line: $line,
            qty: '1',
            destination: RefundDestination::StoreVoucher,
        );

        // VoucherLedger row must point to the finalized return receipt
        $ledger = VoucherLedger::where('receipt_id', $returnReceipt->id)->first();
        $this->assertNotNull($ledger, 'VoucherLedger row should exist for the return receipt');
        $this->assertSame($returnReceipt->id, $ledger->receipt_id);

        // Return receipt must be fiscalized (chain ran after voucher issuance)
        $this->assertSame(FiscalStatus::Fiscalized, $returnReceipt->fiscal_status);
    }

    // =========================================================================
    // Task 33 — mid-transaction rollback
    // =========================================================================

    /**
     * Tests that a mid-transaction failure (here: GL account not seeded for voucher issuance)
     * causes the entire return to roll back: no return receipt, no stock movement, and the
     * terminal sequence is unchanged.
     *
     * Note: VoucherIssuanceService is marked final so we cannot mock it with Mockery.
     * We instead rely on the GL-account-missing RuntimeException that fires naturally when
     * no chart-of-accounts is seeded for the test company.
     */
    public function test_mid_transaction_failure_rolls_back_everything(): void
    {
        // Configure StoreVoucher destination WITHOUT seeding GL accounts.
        // VoucherIssuanceService will throw a RuntimeException ("Missing GL account: ...").
        $this->setCompanyPolicy([
            'allowed_refund_destinations' => ['store_voucher'],
            'voucher_default_expiry_days' => 365,
            'customer_return_expiry_days' => 90,
            'out_of_window_policy' => 'voucher_only',
            'manager_override_threshold_amount' => '99999.00',
        ]);

        $saleReceipt = $this->createSaleReceipt();
        $line = $this->createLine($saleReceipt, '3.000', '30.000');

        $sequenceBefore = $this->terminal->fresh()->current_sequence;
        $receiptCountBefore = Receipt::count();

        $threw = false;

        try {
            $this->callProcessReturn(
                saleReceipt: $saleReceipt,
                line: $line,
                qty: '1',
                destination: RefundDestination::StoreVoucher,
            );
        } catch (\RuntimeException) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Expected RuntimeException (missing GL account) to propagate');

        // No return receipt persisted (transaction rolled back)
        $this->assertSame($receiptCountBefore, Receipt::count(), 'Receipt count should be unchanged after rollback');
        $this->assertDatabaseMissing('pos_receipts', [
            'receipt_type' => ReceiptType::Return->value,
            'original_receipt_id' => $saleReceipt->id,
        ]);

        // No voucher ledger entries
        $this->assertDatabaseEmpty('voucher_ledger');

        // Terminal sequence unchanged
        $this->terminal->refresh();
        $this->assertSame($sequenceBefore, $this->terminal->current_sequence);
    }

    // =========================================================================
    // Task 33 — DB-level idempotency
    // =========================================================================

    public function test_same_refund_request_id_returns_existing_receipt(): void
    {
        $saleReceipt = $this->createSaleReceipt();
        $line = $this->createLine($saleReceipt, '5.000', '50.000');
        $requestId = (string) Str::uuid();

        // First call — creates the return receipt
        $first = $this->callProcessReturn(
            saleReceipt: $saleReceipt,
            line: $line,
            qty: '2',
            refundRequestId: $requestId,
        );

        $sequenceAfterFirst = $this->terminal->fresh()->current_sequence;

        // Second call with same refund_request_id — should return the same row
        $second = $this->callProcessReturn(
            saleReceipt: $saleReceipt,
            line: $line,
            qty: '2',
            refundRequestId: $requestId,
        );

        $this->assertSame($first->id, $second->id, 'Second call should return the same receipt');

        // Terminal sequence must not have advanced a second time
        $this->terminal->refresh();
        $this->assertSame($sequenceAfterFirst, $this->terminal->current_sequence);

        // Only one return receipt in the DB
        $this->assertSame(
            1,
            Receipt::where('refund_request_id', $requestId)->count(),
            'Exactly one receipt should exist for the given refund_request_id'
        );
    }

    public function test_different_refund_request_ids_produce_separate_receipts(): void
    {
        $saleReceipt = $this->createSaleReceipt();
        $line = $this->createLine($saleReceipt, '5.000', '50.000');

        $first = $this->callProcessReturn($saleReceipt, $line, '1', refundRequestId: (string) Str::uuid());
        $second = $this->callProcessReturn($saleReceipt, $line, '1', refundRequestId: (string) Str::uuid());

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(
            2,
            Receipt::where('original_receipt_id', $saleReceipt->id)
                ->where('receipt_type', ReceiptType::Return->value)
                ->count()
        );
    }

    // =========================================================================
    // Task 32 — finalization integration
    // =========================================================================

    public function test_fiscal_hash_set_and_matches_terminal_last_hash(): void
    {
        $saleReceipt = $this->createSaleReceipt();
        $line = $this->createLine($saleReceipt, '3.000', '30.000');

        $returnReceipt = $this->callProcessReturn($saleReceipt, $line, '1');

        $this->terminal->refresh();
        $this->assertSame(
            $returnReceipt->fiscal_hash,
            $this->terminal->last_hash,
            'Terminal last_hash should match the return receipt fiscal_hash'
        );
    }

    // =========================================================================
    // Test helpers
    // =========================================================================

    private function setUpFixtures(): void
    {
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
        $this->cashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->cashier->id,
            'company_id' => $this->company->id,
            'role' => 'cashier',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('pos.process_returns', 'sanctum');
        Permission::findOrCreate('pos.refund_above_threshold', 'sanctum');
        Permission::findOrCreate('pos.refund_extend_daily_cap', 'sanctum');
        $this->cashier->givePermissionTo('pos.process_returns');

        $this->location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);

        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'fiscal_schema_version' => 2,
            'current_sequence' => 1,
        ]);

        $this->shift = Shift::create([
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->cashier->id,
            'shift_number' => 1,
            'opening_cash' => '100.000',
            'status' => ShiftStatus::Open,
            'opened_at' => now(),
        ]);
    }

    /**
     * Set company reservation_settings JSONB to the given partial overrides.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function setCompanyPolicy(array $overrides): void
    {
        $defaults = (new ReservationSettings)->toArray();
        $merged = array_merge($defaults, $overrides);
        $this->company->reservation_settings = $merged;
        $this->company->save();
    }

    private function createSaleReceipt(
        string $subtotal = '100.000',
        string $total = '119.000',
    ): Receipt {
        return Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->cashier->id,
            'receipt_type' => ReceiptType::Sale,
            'subtotal' => $subtotal,
            'tax_amount' => '19.000',
            'total' => $total,
            'currency' => 'EUR',
            'fiscal_status' => FiscalStatus::Fiscalized,
        ]);
    }

    private function createLine(
        Receipt $receipt,
        string $quantity,
        string $lineTotal,
    ): ReceiptLine {
        return ReceiptLine::create([
            'receipt_id' => $receipt->id,
            'line_number' => 1,
            'product_id' => null,
            'product_code' => 'PROD-001',
            'product_name' => 'Widget A',
            'quantity' => $quantity,
            'unit' => 'pcs',
            'unit_price' => '10.000',
            'line_total' => $lineTotal,
            'tax_rate' => '19.00',
            'tax_amount' => '0.000',
            'discount_amount' => '0.000',
        ]);
    }

    /**
     * Helper to call ReceiptReturnService::processReturn with sensible defaults.
     *
     * Resolves the service via the Laravel container so auto-wiring is tested.
     */
    private function callProcessReturn(
        Receipt $saleReceipt,
        ReceiptLine $line,
        string $qty,
        ?string $refundRequestId = null,
        ?string $authorizedByUserId = null,
        ?string $overrideReason = null,
        ?RefundDestination $destination = null,
    ): Receipt {
        // Bind companyContext to return this company's id
        $this->app->bind(CompanyContext::class, function () {
            $context = $this->createMock(CompanyContext::class);
            $context->method('requireCompanyId')->willReturn($this->company->id);

            return $context;
        });

        /** @var ReceiptReturnService $service */
        $service = $this->app->make(ReceiptReturnService::class);

        return $service->processReturn(
            originalReceiptId: $saleReceipt->id,
            returnLines: [['line_id' => $line->id, 'quantity' => $qty]],
            returnReason: ReturnReason::CustomerChangedMind,
            cashier: $this->cashier,
            terminalId: $this->terminal->id,
            notes: null,
            destination: $destination,
            refundRequestId: $refundRequestId,
            authorizedByUserId: $authorizedByUserId,
            overrideReason: $overrideReason,
        );
    }
}
