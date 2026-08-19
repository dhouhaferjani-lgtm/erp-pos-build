<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Compliance\Domain\CompanyFraudSettings;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\ReportGenerationService;
use App\Modules\POS\Domain\DTOs\CashCountBreakdownDTO;
use App\Modules\POS\Domain\DTOs\CashCountInputDTO;
use App\Modules\POS\Domain\DTOs\VarianceAmount;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Events\CashCountRecorded;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptPayment;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\RepositoryAdjustment;
use App\Shared\Domain\Enums\VarianceDirection;
use App\Shared\Domain\Enums\VarianceSeverity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * DPA lane G3 — the shift-variance GL leg via BOTH real trigger paths.
 *
 * `CashCountRecorded` has two producers and the lane is only wired if both of
 * them reach the ledger:
 *   - LIVE:    ReportGenerationService::generateZReport() with cash-count inputs
 *   - OFFLINE: POST /api/v1/pos/reports/z/sync (device replay)
 *
 * The last test is the cross-path replay that the derived document id exists
 * for: a shift booked live, then re-synced offline, must still hold exactly ONE
 * document, ONE posted entry and ONE movement.
 */
final class ShiftCashVarianceTriggerPathsTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATE_PERMISSION = 'pos.operate_terminal';

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private User $cashier;

    private Terminal $terminal;

    private Shift $shift;

    private PaymentMethod $cashMethod;

    private PaymentRepository $till;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'TN',
            'currency' => 'TND',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $this->cashier->id,
            'company_id' => $this->company->id,
            'role' => 'cashier',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate(self::OPERATE_PERMISSION, 'sanctum');
        $this->cashier->givePermissionTo(self::OPERATE_PERMISSION);

        $this->location = Location::factory()->create(['company_id' => $this->company->id]);

        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);

        $this->till = PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'balance' => '400.000',
            'currency' => 'TND',
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => $this->accountFor(SystemAccountPurpose::Cash)->id,
            'is_active' => true,
        ]);

        $this->cashMethod = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH',
            'name' => 'Cash',
            'is_physical' => true,
            'is_active' => true,
            'default_repository_id' => $this->till->id,
        ]);

        $this->shift = Shift::create([
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->cashier->id,
            'shift_number' => 1,
            'status' => ShiftStatus::Open,
            'opened_at' => now()->subHour(),
            'opening_cash' => '0.0000',
        ]);

        $this->setFraudSettings();

        config()->set('treasury.shift_variance_gl_enabled', true);
    }

    public function test_the_live_z_report_path_books_the_variance_to_the_ledger(): void
    {
        $this->seedFiscalizedCashReceipt('100.0000');

        app(ReportGenerationService::class)->generateZReport(
            $this->terminal,
            $this->cashier,
            [new CashCountInputDTO(
                paymentMethodId: $this->cashMethod->id,
                currencyCode: 'TND',
                actualAmount: '95.0000',
            )],
            'Till short at close.',
            null,
            false,
        );

        $document = RepositoryAdjustment::query()->where('pos_shift_id', $this->shift->id)->firstOrFail();
        $this->assertSame('out', $document->direction->value);
        $this->assertSame(0, bccomp((string) $document->amount, '5.000', 3));
        $this->assertSame($this->till->id, $document->payment_repository_id);

        $entry = JournalEntry::query()->whereKey($document->journal_entry_id)->firstOrFail();
        $this->assertSame(JournalEntryStatus::Posted, $entry->status);
        $this->assertSame('395.000', $this->till->fresh()?->balance);
    }

    public function test_the_offline_z_report_sync_path_books_the_variance_to_the_ledger(): void
    {
        Sanctum::actingAs($this->cashier);

        $this->postJson('/api/v1/pos/reports/z/sync', $this->syncPayload('-5.0000'))
            ->assertStatus(201);

        $document = RepositoryAdjustment::query()->where('pos_shift_id', $this->shift->id)->firstOrFail();
        $this->assertSame('out', $document->direction->value);
        $this->assertSame(0, bccomp((string) $document->amount, '5.000', 3));

        $entry = JournalEntry::query()->whereKey($document->journal_entry_id)->firstOrFail();
        $this->assertSame(JournalEntryStatus::Posted, $entry->status);
        $this->assertSame('395.000', $this->till->fresh()?->balance);
    }

    /**
     * Replay through the OFFLINE path.
     *
     * `pos_z_reports.shift_id` is UNIQUE, so a device can never land a second Z
     * report on a shift — a re-sync of the same z_number+hash short-circuits to
     * `200 duplicate` and does not re-raise `CashCountRecorded`. That upstream
     * guard is not the one this lane relies on though: a queue retry or a
     * re-dispatch of the SAME event must also be harmless, which is what the
     * derived (UUIDv5-from-shift-id) document id buys. Both are asserted.
     */
    public function test_a_replayed_offline_sync_and_a_re_raised_event_write_no_second_document(): void
    {
        Sanctum::actingAs($this->cashier);

        $payload = $this->syncPayload('-5.0000');

        $this->postJson('/api/v1/pos/reports/z/sync', $payload)->assertStatus(201);
        $this->postJson('/api/v1/pos/reports/z/sync', $payload)
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'duplicate');

        $document = RepositoryAdjustment::query()->where('pos_shift_id', $this->shift->id)->firstOrFail();

        // And a bare re-dispatch of the same event (queue retry) — the path the
        // document id derivation actually guards.
        $this->reRaiseCashCountFor($document->id);

        $this->assertSame(1, RepositoryAdjustment::query()->count());
        $this->assertSame(1, DB::table('repository_movements')->count());
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'repository_adjustment')->count());
        $this->assertSame('395.000', $this->till->fresh()?->balance);
    }

    private function reRaiseCashCountFor(string $expectedDocumentId): void
    {
        $variance = bcsub('95.0000', '100.0000', 4);

        app(CompanyContext::class)->clear();

        event(new CashCountRecorded(
            zReportId: (string) Str::uuid(),
            shiftId: $this->shift->id,
            terminalId: $this->terminal->id,
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            cashierId: $this->cashier->id,
            managerOverrideBy: null,
            blindCountUsed: false,
            currencyCode: 'TND',
            aggregateVariance: new VarianceAmount(amount: $variance, currencyCode: 'TND'),
            varianceDirection: VarianceDirection::fromSignedAmount($variance),
            severity: VarianceSeverity::Warning,
            tenderBreakdown: [new CashCountBreakdownDTO(
                paymentMethodId: $this->cashMethod->id,
                currencyCode: 'TND',
                expectedAmount: '100.0000',
                actualAmount: '95.0000',
                varianceAmount: $variance,
                varianceDirection: VarianceDirection::fromSignedAmount($variance),
                transactionCount: 1,
            )],
            descriptionCode: 'pos.cash_count.warning',
            descriptionParams: [],
            recordedAt: now()->toIso8601String(),
        ));

        $this->assertSame(
            $expectedDocumentId,
            RepositoryAdjustment::query()->where('pos_shift_id', $this->shift->id)->firstOrFail()->id,
        );
    }

    /**
     * Gate finding M6 / fiscal C2 — the double-count guard proven by DRIVING the
     * retained schema-v2 compatibility path, not by asserting a balanced pair.
     *
     * A receipt with a genuine tolerance write-off: total 100.000, TENDERED
     * 99.950, 0.050 written off to 658 (Dr 658 / Cr ProductRevenue, no cash leg).
     * The deprecated takings-only helper computes 99.950, which matches the cash
     * receipt term for this no-float/no-drawer-operation fixture. An honest count
     * of 99.950 is BALANCED and the shift close books nothing. 658 must still
     * carry the per-receipt 0.050 alone. This fixture does not define the live
     * whole-drawer expected basis.
     */
    public function test_a_tolerance_bearing_receipt_leaves_an_honest_count_balanced_and_books_nothing(): void
    {
        $toleranceExpense = $this->accountFor(SystemAccountPurpose::PaymentToleranceExpense);
        $this->seedFiscalizedCashReceipt('100.0000', tendered: '99.9500', toleranceWriteoff: '0.050');
        $this->postPerReceiptTolerance('0.050');

        app(ReportGenerationService::class)->generateZReport(
            $this->terminal,
            $this->cashier,
            [new CashCountInputDTO(
                paymentMethodId: $this->cashMethod->id,
                currencyCode: 'TND',
                actualAmount: '99.9500',
            )],
            null,
            null,
            false,
        );

        $this->assertSame(0, RepositoryAdjustment::query()->count());
        $this->assertSame(
            '0.050',
            $this->postedDebit($toleranceExpense),
            '658 must carry the per-receipt tolerance ONLY — the shift close must not re-book it.',
        );
        $this->assertSame('400.000', $this->till->fresh()?->balance);
    }

    /**
     * The companion: the SAME tolerance-bearing receipt plus a genuine 5.000
     * shortfall. 658 ends at 5.050 — two distinct facts, correctly additive,
     * each with its own justifying document. Not one fact counted twice.
     */
    public function test_a_real_shortfall_on_a_tolerance_bearing_shift_books_only_the_shortfall(): void
    {
        $toleranceExpense = $this->accountFor(SystemAccountPurpose::PaymentToleranceExpense);
        $this->seedFiscalizedCashReceipt('100.0000', tendered: '99.9500', toleranceWriteoff: '0.050');
        $this->postPerReceiptTolerance('0.050');

        app(ReportGenerationService::class)->generateZReport(
            $this->terminal,
            $this->cashier,
            [new CashCountInputDTO(
                paymentMethodId: $this->cashMethod->id,
                currencyCode: 'TND',
                actualAmount: '94.9500',
            )],
            'Till short at close.',
            null,
            false,
        );

        $document = RepositoryAdjustment::query()->where('pos_shift_id', $this->shift->id)->firstOrFail();
        $this->assertSame(0, bccomp((string) $document->amount, '5.000', 3));
        $this->assertSame('5.050', $this->postedDebit($toleranceExpense));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function postPerReceiptTolerance(string $amount): void
    {
        $gl = app(GeneralLedgerService::class);

        $gl->postEntry(
            DB::transaction(fn (): JournalEntry => $gl->createPOSPaymentToleranceEntry(
                companyId: $this->company->id,
                receiptId: (string) Str::uuid(),
                amount: $amount,
                date: now(),
            )),
            $this->cashier,
        );
    }

    private function postedDebit(Account $account): string
    {
        $total = '0.000';

        $rows = DB::table('journal_lines')
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_lines.account_id', $account->id)
            ->where('journal_entries.company_id', $this->company->id)
            ->where('journal_entries.status', 'posted')
            ->select('journal_lines.debit')
            ->get();

        foreach ($rows as $row) {
            $total = bcadd($total, (string) $row->debit, 3);
        }

        return $total;
    }

    /**
     * @return array<string, mixed>
     */
    private function syncPayload(string $varianceAmount, int $zNumber = 1, string $previousHash = 'GENESIS'): array
    {
        return [
            'id' => Str::uuid()->toString(),
            'terminal_id' => $this->terminal->id,
            'shift_id' => $this->shift->id,
            'z_number' => $zNumber,
            'formatted_z_number' => 'Z000'.$zNumber,
            'generated_at' => now()->toIso8601String(),
            'fiscal_hash' => str_repeat((string) $zNumber, 64),
            'previous_hash' => $previousHash,
            'hash_sequence' => $zNumber,
            'report_data' => ['sales_count' => 1, 'gross_sales' => '100.000'],
            'opening_cash' => '0.000',
            'expected_cash' => '100.000',
            'receipt_snapshots' => [],
            'grand_totals' => [],
            'cash_counts' => [
                [
                    'payment_method_id' => $this->cashMethod->id,
                    'currency_code' => 'TND',
                    'expected_amount' => '100.000',
                    'actual_amount' => '95.000',
                    'variance_amount' => $varianceAmount,
                    'variance_direction' => 'under',
                    'transaction_count' => 1,
                ],
            ],
            'shift_fields' => [
                'variance_reason' => 'Till short at close.',
                'variance_severity' => 'medium',
                'actual_cash' => '95.0000',
                'variance_amount' => $varianceAmount,
            ],
        ];
    }

    private function seedFiscalizedCashReceipt(
        string $amount,
        ?string $tendered = null,
        ?string $toleranceWriteoff = null,
    ): Receipt {
        $receipt = Receipt::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'receipt_number' => 'T001-C001-L01-POS01-2026-'.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'chain_sequence' => 1,
            'receipt_year' => (int) date('Y'),
            'fiscal_hash' => hash('sha256', 'r-'.uniqid()),
            'previous_hash' => null,
            'vat_breakdown_hash' => hash('sha256', 'vat'),
            'payment_methods_hash' => hash('sha256', 'payments'),
            'posted_at' => now(),
            'cashier_id' => $this->cashier->id,
            'cashier_name' => 'Test Cashier',
            'subtotal' => $amount,
            'tax_amount' => '0.0000',
            'discount_amount' => '0.0000',
            'total' => $amount,
            'currency' => 'TND',
            'is_voided' => false,
            'is_training' => false,
            'tolerance_writeoff' => $toleranceWriteoff,
        ]);

        // `pos_receipt_payments.amount` stores the TENDERED amount (payment
        // v1.1 contract) — which is exactly why a tolerance write-off is already
        // netted out of the expected-cash basis.
        ReceiptPayment::create([
            'receipt_id' => $receipt->id,
            'payment_method_id' => $this->cashMethod->id,
            'payment_type' => 'Cash',
            'amount' => $tendered ?? $amount,
        ]);

        return $receipt;
    }

    private function setFraudSettings(): void
    {
        CompanyFraudSettings::query()->updateOrCreate(
            ['company_id' => $this->company->id],
            [
                'cash_variance_over_soft' => '1.0000',
                'cash_variance_over_hard' => '20.0000',
                'cash_variance_under_soft' => '1.0000',
                'cash_variance_under_hard' => '20.0000',
                'require_blind_cash_count' => false,
                'require_manager_pin_above_hard' => false,
                'cash_variance_email_severity' => 'none',
                'alert_enabled' => true,
                'auto_trigger_counting' => false,
                'auto_restrict_access' => false,
                'abandoned_draft_threshold' => 5,
                'time_window_days' => 30,
            ],
        );
    }

    private function accountFor(SystemAccountPurpose $purpose): Account
    {
        return Account::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('company_id', $this->company->id)
            ->where('system_purpose', $purpose->value)
            ->firstOrFail();
    }
}
