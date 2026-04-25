<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Models\Country;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\ReceiptPaymentService;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\CountryPaymentSettings;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 2 / Task 9 — A1 POS short-pay tolerance.
 *
 * The previous reject path at ReceiptPaymentService:79 is replaced by a
 * three-way branch (overpay / exact / short-pay-within-tolerance), with the
 * outside-tolerance case still rejecting.
 *
 * When tolerance applies, inside the same DB transaction we:
 *  - Persist pos_receipts.tolerance_writeoff
 *  - lockForUpdate on the shift, then call Shift::applyToleranceWriteoff
 *  - Post a partner-less Dr 658 / Cr Revenue journal entry via
 *    GeneralLedgerService::createPOSPaymentToleranceEntry
 *
 * change_due is guaranteed ≥ 0 post-A1.
 */
final class ReceiptPaymentServiceToleranceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private Terminal $terminal;

    private Shift $shift;

    private User $cashier;

    private PaymentMethod $cashMethod;

    private PaymentRepository $cashRepo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        Country::create([
            'code' => 'FR',
            'name' => 'France',
            'currency_code' => 'EUR',
            'currency_symbol' => '€',
        ]);
        CountryPaymentSettings::create([
            'country_code' => 'FR',
            'payment_tolerance_enabled' => true,
            'payment_tolerance_percentage' => '0.0050',
            'max_payment_tolerance_amount' => '0.500',
        ]);

        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'FR',
            'currency' => 'EUR',
        ]);
        $this->location = Location::factory()->create(['company_id' => $this->company->id]);
        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);

        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->shift = Shift::create([
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->cashier->id,
            'shift_number' => 1,
            'status' => ShiftStatus::Open,
            'opened_at' => now()->subHour(),
            'opening_cash' => '100.000',
        ]);

        $cashAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '530',
            'name' => 'Cash',
            'type' => 'asset',
            'system_purpose' => SystemAccountPurpose::Cash,
            'is_active' => true,
        ]);
        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '707',
            'name' => 'Product Revenue',
            'type' => 'revenue',
            'system_purpose' => SystemAccountPurpose::ProductRevenue,
            'is_active' => true,
        ]);
        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '658',
            'name' => 'Payment Tolerance Expense',
            'type' => 'expense',
            'system_purpose' => SystemAccountPurpose::PaymentToleranceExpense,
            'is_active' => true,
        ]);

        $this->cashMethod = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Cash',
            'code' => 'CASH',
        ]);
        $this->cashRepo = PaymentRepository::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Main register',
            'code' => 'CASH-01',
            'type' => RepositoryType::CashRegister->value,
            'gl_account_id' => $cashAccount->id,
            'currency' => 'EUR',
        ]);

        $this->actingAs($this->cashier);
        $this->app->make(CompanyContext::class)
            ->setCompanyId($this->company->id);
    }

    public function test_short_pay_within_tolerance_persists_writeoff_and_posts_gl_658(): void
    {
        // FR settings: max 0.5% AND max €0.50. On €100, percentage threshold = €0.50.
        // Shortfall €0.30 fits under both bounds.
        $receipt = $this->seedReceipt('100.00');

        /** @var ReceiptPaymentService $service */
        $service = $this->app->make(ReceiptPaymentService::class);

        $result = $service->processReceiptPayments(
            receiptId: $receipt->id,
            payments: [[
                'amount' => '99.700',
                'payment_method_id' => $this->cashMethod->id,
                'repository_id' => $this->cashRepo->id,
            ]],
        );

        $this->assertSame('0.000', $result['change_due']);
        $this->assertSame('0.300', $result['tolerance_writeoff']);

        $receipt->refresh();
        $this->assertSame('0.300', $receipt->tolerance_writeoff);
        $this->assertSame('0.000', $receipt->change_due);

        $this->shift->refresh();
        $this->assertSame('0.300', $this->shift->tolerance_writeoff_total);
        $this->assertSame(1, $this->shift->tolerance_writeoff_count);

        $toleranceEntries = JournalEntry::where('source_type', 'pos_payment_tolerance')
            ->where('source_id', $receipt->id)
            ->get();
        $this->assertCount(1, $toleranceEntries);
    }

    public function test_short_pay_outside_tolerance_throws_and_persists_nothing(): void
    {
        $receipt = $this->seedReceipt('10.00');

        /** @var ReceiptPaymentService $service */
        $service = $this->app->make(ReceiptPaymentService::class);

        try {
            $service->processReceiptPayments(
                receiptId: $receipt->id,
                payments: [[
                    'amount' => '9.000',
                    'payment_method_id' => $this->cashMethod->id,
                    'repository_id' => $this->cashRepo->id,
                ]],
            );
            $this->fail('Expected InvalidArgumentException for over-tolerance short-pay');
        } catch (\InvalidArgumentException) {
            // expected
        }

        $receipt->refresh();
        $this->shift->refresh();
        $this->assertNull($receipt->tolerance_writeoff);
        $this->assertSame('0.000', $this->shift->tolerance_writeoff_total);
        $this->assertSame(0, $this->shift->tolerance_writeoff_count);

        $this->assertSame(
            0,
            JournalEntry::where('source_id', $receipt->id)->count(),
            'A failed short-pay must not leak any journal entries.',
        );
    }

    public function test_exact_tender_unchanged_no_tolerance_recorded(): void
    {
        $receipt = $this->seedReceipt('10.00');

        /** @var ReceiptPaymentService $service */
        $service = $this->app->make(ReceiptPaymentService::class);

        $result = $service->processReceiptPayments(
            receiptId: $receipt->id,
            payments: [[
                'amount' => '10.000',
                'payment_method_id' => $this->cashMethod->id,
                'repository_id' => $this->cashRepo->id,
            ]],
        );

        $this->assertSame('0.000', $result['change_due']);
        $this->assertSame('0.000', $result['tolerance_writeoff']);

        $receipt->refresh();
        $this->shift->refresh();
        $this->assertNull($receipt->tolerance_writeoff);
        $this->assertSame('0.000', $this->shift->tolerance_writeoff_total);

        $this->assertSame(
            0,
            JournalEntry::where('source_type', 'pos_payment_tolerance')
                ->where('source_id', $receipt->id)
                ->count(),
        );
    }

    public function test_overpay_unchanged_change_due_nonzero_no_tolerance(): void
    {
        $receipt = $this->seedReceipt('10.00');

        /** @var ReceiptPaymentService $service */
        $service = $this->app->make(ReceiptPaymentService::class);

        $result = $service->processReceiptPayments(
            receiptId: $receipt->id,
            payments: [[
                'amount' => '12.000',
                'payment_method_id' => $this->cashMethod->id,
                'repository_id' => $this->cashRepo->id,
            ]],
        );

        $this->assertSame('2.000', $result['change_due']);
        $this->assertSame('0.000', $result['tolerance_writeoff']);

        $receipt->refresh();
        $this->shift->refresh();
        $this->assertNull($receipt->tolerance_writeoff);
        $this->assertSame('0.000', $this->shift->tolerance_writeoff_total);
    }

    public function test_atomicity_gl_failure_rolls_back_receipt_and_shift_writes(): void
    {
        $receipt = $this->seedReceipt('100.00');

        // Drop the tolerance-expense account so the second GL post fails after the
        // first POS payment entry is created. The whole transaction must roll back —
        // no payment row, no shift increment, no receipt mutation.
        Account::where('company_id', $this->company->id)
            ->where('system_purpose', SystemAccountPurpose::PaymentToleranceExpense->value)
            ->delete();

        /** @var ReceiptPaymentService $service */
        $service = $this->app->make(ReceiptPaymentService::class);

        try {
            $service->processReceiptPayments(
                receiptId: $receipt->id,
                payments: [[
                    'amount' => '99.700',
                    'payment_method_id' => $this->cashMethod->id,
                    'repository_id' => $this->cashRepo->id,
                ]],
            );
            $this->fail('Expected exception when tolerance GL account is missing');
        } catch (\Throwable) {
            // expected
        }

        $receipt->refresh();
        $this->shift->refresh();

        $this->assertNull($receipt->tolerance_writeoff, 'Receipt tolerance write-off must roll back.');
        $this->assertSame('0.000', $this->shift->tolerance_writeoff_total, 'Shift aggregate must roll back.');
        $this->assertSame(0, $this->shift->tolerance_writeoff_count, 'Shift counter must roll back.');
        $this->assertSame(0, DB::table('payments')->where('reference', 'like', "POS Receipt {$receipt->receipt_number}%")->count());
        $this->assertSame(
            0,
            JournalEntry::where('source_id', $receipt->id)->count(),
            'No journal entries (POS payment or tolerance) should leak after rollback.',
        );
    }

    private function seedReceipt(string $total): Receipt
    {
        return Receipt::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'receipt_number' => sprintf('T001-C001-L01-POS01-2026-%08d', mt_rand(1, 99999999)),
            'chain_sequence' => 1,
            'receipt_year' => 2026,
            'fiscal_hash' => hash('sha256', 'fiscal-'.uniqid()),
            'previous_hash' => null,
            'vat_breakdown_hash' => hash('sha256', 'vat-'.uniqid()),
            'payment_methods_hash' => hash('sha256', 'pay-'.uniqid()),
            'posted_at' => Carbon::now(),
            'cashier_id' => $this->cashier->id,
            'cashier_name' => $this->cashier->name,
            'subtotal' => $total,
            'tax_amount' => '0.000',
            'total' => $total,
            'currency' => 'EUR',
            'is_voided' => false,
            'is_training' => false,
        ]);
    }
}
