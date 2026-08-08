<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Compliance\Domain\CompanyFraudSettings;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptPayment;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\RepositoryAdjustment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * DPA lane G3, gate fix round 1 — the REAL offline device payload.
 *
 * Both fiscal Criticals were about this shape specifically, so it gets its own
 * suite rather than a variation on a hand-built fixture:
 *
 * **C1** — `LocalZReportShiftFields` on the device is
 * `{blind_count_used, variance_severity, variance_reason, manager_override_by}`.
 * It carries NO `variance_amount` and NO `actual_cash`. Before the fix that left
 * `pos_shifts.variance` NULL and `CashCountRecorded::$aggregateVariance` at
 * 0.000 (so `OpenFraudAlertForShiftVariance` short-circuited on `isZero()`)
 * while the listener booked a journal entry from a THIRD figure — the sum of
 * `cash_counts[]`. The figure that posts and the figure that alerts must be the
 * SAME figure; that is what these tests assert, end to end.
 *
 * **C2** — the device's expected-cash basis is its own, not the server's
 * `buildExpectedPerMethod()`. Its normal term is tendered-based (so a per-receipt
 * tolerance is already netted out and cannot be re-booked), but its LEGACY
 * fallback attributes `receipt.total` when a receipt has no payment breakdown —
 * which inflates expected by exactly the tolerance shortfall and would re-book it
 * to 658. The server-visible shadow of that branch refuses the booking.
 */
final class ShiftCashVarianceOfflineDevicePayloadTest extends TestCase
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

        config()->set('treasury.shift_variance_gl_enabled', true);
    }

    /**
     * C1 — one number across `pos_shifts.variance`, the fraud alert and the JE,
     * driven by the payload the shipping device actually sends.
     */
    public function test_the_real_device_payload_stamps_the_variance_alerts_and_books_the_same_figure(): void
    {
        Sanctum::actingAs($this->cashier);

        $this->postJson('/api/v1/pos/reports/z/sync', $this->deviceShapedPayload())
            ->assertStatus(201);

        // 1. pos_shifts.variance is no longer NULL — it carries the per-tender sum.
        $shift = $this->shift->fresh();
        $this->assertNotNull($shift);
        $this->assertNotNull($shift->variance, 'pos_shifts.variance must not stay NULL for a real device payload.');
        $this->assertSame(0, bccomp((string) $shift->variance, '-5.0000', 4));

        // 2. The fraud alert fired (it used to short-circuit on isZero()).
        $alert = DB::table('fraud_alerts')->where('alert_type', 'SHIFT_CLOSE_VARIANCE')->first();
        $this->assertNotNull($alert, 'The fraud alert must see the same variance the ledger books.');

        // 3. The journal entry books EXACTLY that figure.
        $document = RepositoryAdjustment::query()->where('pos_shift_id', $this->shift->id)->firstOrFail();
        $this->assertSame('out', $document->direction->value);
        $this->assertSame(0, bccomp((string) $document->amount, '5.000', 3));
        $this->assertSame(
            0,
            bccomp((string) $document->amount, ltrim((string) $shift->variance, '-'), 3),
            'The booked amount and pos_shifts.variance must be the same figure.',
        );

        $this->assertSame('395.000', $this->till->fresh()?->balance);
        $this->assertSame('5.000', $this->postedDebit($this->accountFor(SystemAccountPurpose::PaymentToleranceExpense)));
    }

    /**
     * C2 — the device's LEGACY expected basis, refused.
     *
     * A receipt in the shift carries a tolerance write-off but no payment
     * breakdown: exactly the server-visible shadow of the device branch that
     * attributes `receipt.total` instead of the tendered amount, inflating
     * expected by the shortfall. Booking here would re-book that shortfall to
     * 658 — the double count the brief asked about, on the production path.
     */
    public function test_a_tolerance_write_off_without_a_payment_breakdown_refuses_the_booking(): void
    {
        $toleranceExpense = $this->accountFor(SystemAccountPurpose::PaymentToleranceExpense);
        $this->seedReceipt('100.000', toleranceWriteoff: '0.050', withPaymentRow: false);
        $this->postPerReceiptTolerance('0.050');

        Sanctum::actingAs($this->cashier);
        $this->postJson('/api/v1/pos/reports/z/sync', $this->deviceShapedPayload())
            ->assertStatus(201);

        $this->assertSame(0, RepositoryAdjustment::query()->count());
        $this->assertSame(0, DB::table('repository_movements')->count());
        $this->assertSame(
            '0.050',
            $this->postedDebit($toleranceExpense),
            '658 must still carry the per-receipt tolerance ONLY.',
        );

        // The omission is durable and queryable, not just a log line.
        $audit = DB::table('audit_events')
            ->where('event_type', 'treasury.shift_variance_gl_skipped')
            ->where('aggregate_id', $this->shift->id)
            ->first();
        $this->assertNotNull($audit);
        $this->assertStringContainsString('unattributable_tolerance_writeoff', (string) $audit->payload);
    }

    /**
     * Control for the test above: the SAME tolerance write-off, but on a receipt
     * that does carry its payment breakdown — the normal, tendered-based device
     * branch. Here the tolerance is already netted out of expected, so booking
     * the counted variance is correct and does NOT re-book the shortfall.
     */
    public function test_a_tolerance_write_off_with_a_payment_breakdown_still_books_without_re_booking_it(): void
    {
        $toleranceExpense = $this->accountFor(SystemAccountPurpose::PaymentToleranceExpense);
        $this->seedReceipt('100.000', toleranceWriteoff: '0.050', withPaymentRow: true);
        $this->postPerReceiptTolerance('0.050');

        Sanctum::actingAs($this->cashier);
        $this->postJson('/api/v1/pos/reports/z/sync', $this->deviceShapedPayload())
            ->assertStatus(201);

        $document = RepositoryAdjustment::query()->where('pos_shift_id', $this->shift->id)->firstOrFail();
        $this->assertSame(0, bccomp((string) $document->amount, '5.000', 3));

        // 658 = 0.050 per-receipt tolerance + 5.000 genuine shift shortfall.
        // Two distinct facts, correctly additive — not one fact counted twice.
        $this->assertSame('5.050', $this->postedDebit($toleranceExpense));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * The payload shape the shipping POS device actually produces: `shift_fields`
     * WITHOUT `variance_amount` / `actual_cash`, the variance living only in
     * `cash_counts[]`.
     *
     * @return array<string, mixed>
     */
    private function deviceShapedPayload(): array
    {
        return [
            'id' => Str::uuid()->toString(),
            'terminal_id' => $this->terminal->id,
            'shift_id' => $this->shift->id,
            'z_number' => 1,
            'formatted_z_number' => 'Z0001',
            'generated_at' => now()->toIso8601String(),
            'fiscal_hash' => str_repeat('a', 64),
            'previous_hash' => 'GENESIS',
            'hash_sequence' => 1,
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
                    'variance_amount' => '-5.000',
                    'variance_direction' => 'under',
                    'transaction_count' => 1,
                ],
            ],
            // The REAL LocalZReportShiftFields: no variance_amount, no actual_cash.
            'shift_fields' => [
                'blind_count_used' => false,
                'variance_severity' => 'medium',
                'variance_reason' => 'Till short at close.',
            ],
        ];
    }

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

    private function seedReceipt(string $amount, string $toleranceWriteoff, bool $withPaymentRow): Receipt
    {
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

        if ($withPaymentRow) {
            ReceiptPayment::create([
                'receipt_id' => $receipt->id,
                'payment_method_id' => $this->cashMethod->id,
                'payment_type' => 'Cash',
                'amount' => bcsub($amount, $toleranceWriteoff, 3),
            ]);
        }

        return $receipt;
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

    private function accountFor(SystemAccountPurpose $purpose): Account
    {
        return Account::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('company_id', $this->company->id)
            ->where('system_purpose', $purpose->value)
            ->firstOrFail();
    }
}
