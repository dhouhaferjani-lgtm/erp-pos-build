<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\Services\PaymentRefundService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * DPA `DPA-REV2-A` / task A1 — the red tests that pin every wrong GL shape the
 * customer-advance reversal lane exists to fix.
 *
 * Every assertion in this file targets **posted journal LINES** — the account a
 * line hits (resolved by `SystemAccountPurpose`, never by `line_order`), the side
 * it hits it on, the amount, and the `partner_id` tag. Asserting that a call did
 * not throw proves nothing here: several of these defects are *silently* wrong
 * money, and one of them (A1c) is green-for-the-wrong-reason today because an
 * unrelated gate masks it.
 *
 * @see plan-advance-reversal.md §1.2 (C-2), §1.3 (shape X), §1.4 (shape Y),
 *      §1.5 (shape Z), A-D2 (the ledger partition is the sole shape selector)
 */
final class AdvanceReversalGlShapeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $partner;

    private PaymentMethod $cashMethod;

    private PaymentRepository $repository;

    private PaymentRefundService $refundService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::query()->create([
            'name' => 'Advance Reversal Tenant',
            'slug' => 'advrev-'.Str::random(6),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        // TN/TND deliberately: the Tunisia chart seeds `CustomerAdvance` (419)
        // with its `system_purpose`, so this file tests the GL SHAPE rather than
        // the France chart gap (§1.6), which A2/A3 own.
        $this->company = Company::query()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Advance Reversal Co',
            'legal_name' => 'Advance Reversal Co SARL',
            'tax_id' => 'TAX-ADVREV',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::query()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Advance Reversal User',
            'email' => 'advrev-'.Str::random(6).'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['payments.create', 'payments.view', 'payments.reverse']);

        UserCompanyMembership::query()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $this->cashMethod = PaymentMethod::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Cash',
            'code' => 'CASH-'.Str::random(4),
            'is_active' => true,
            'is_physical' => true,
            'has_maturity' => false,
            'requires_third_party' => false,
            'is_push' => false,
            'has_deducted_fees' => false,
            'is_restricted' => false,
        ]);

        $this->repository = PaymentRepository::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'TILL-'.Str::random(6),
            'name' => 'Main Till',
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => $this->accountId(SystemAccountPurpose::Bank),
            'account_id' => null,
            'is_active' => true,
        ]);

        $this->partner = Partner::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Advance Customer',
            'type' => PartnerType::Customer,
        ]);

        $this->refundService = app(PaymentRefundService::class);
    }

    /**
     * A1a — **C-2, the live V4 mixed-payment defect** (§1.2).
     *
     * 1000 paid / 700 allocated to an invoice / 300 excess. `store()` posts TWO
     * entries against the payment — `Cr CustomerReceivable 700`
     * (`source_type='customer_payment'`) and `Cr CustomerAdvance 300`
     * (`source_type='advance'`) — and deliberately KEEPS the type as
     * `DocumentPayment` (`PaymentController.php:1186-1188`), so the D-6 gate
     * passes and the cash branch hands the FULL 1000 to the AR builder.
     *
     * Today: one entry, `Dr CustomerReceivable 1000` — AR overstated by 300 and a
     * 300 `CustomerAdvance` liability left standing.
     * Required: `Dr CustomerReceivable 700` + `Dr CustomerAdvance 300`, both
     * partner-tagged, against a single `Cr` of 1000 on the till's GL account.
     */
    public function test_a1a_a_mixed_payment_reversal_splits_ar_and_customer_advance(): void
    {
        $invoice = $this->postedInvoice('700.000');

        $payment = $this->payViaApi('1000.000', [
            ['document_id' => $invoice->id, 'amount' => '700.000'],
        ]);

        // Pin the ORIGINAL footprint first: without this the test could go green
        // on a fixture that never produced a mixed payment at all.
        self::assertSame(
            '700.000',
            $this->creditByPurpose($payment->id, 'customer_payment', SystemAccountPurpose::CustomerReceivable),
            'fixture: the original must credit AR for the allocated portion',
        );
        self::assertSame(
            '300.000',
            $this->creditByPurpose($payment->id, 'advance', SystemAccountPurpose::CustomerAdvance),
            'fixture: the original must credit CustomerAdvance for the excess',
        );

        $reversal = $this->refundService->reversePayment($payment, 'A1a mixed reversal', $this->user->id);
        self::assertInstanceOf(Payment::class, $reversal);

        $debits = $this->postedDebitsByPurpose($reversal->id);

        self::assertSame(
            '700.000',
            $debits[SystemAccountPurpose::CustomerReceivable->value] ?? '0.000',
            'the receivable may only be restored for the ALLOCATED portion — '
            .'debiting the full 1000 overstates AR by the 300 that was never a receivable',
        );
        self::assertSame(
            '300.000',
            $debits[SystemAccountPurpose::CustomerAdvance->value] ?? '0.000',
            'the excess portion must unwind the CustomerAdvance liability it created; '
            .'leaving it standing is the other half of C-2',
        );

        // The partner subledger must carry both debits — an untagged restoration
        // reconciles at the control account and silently breaks the subledger.
        foreach ([SystemAccountPurpose::CustomerReceivable, SystemAccountPurpose::CustomerAdvance] as $purpose) {
            self::assertSame(
                $this->partner->id,
                $this->soleDebitLine($reversal->id, $purpose)->partner_id,
                "the {$purpose->value} debit must be partner-tagged",
            );
        }

        // Exactly one cash credit, for the whole net — two entries, one movement.
        self::assertSame(
            '1000.000',
            $this->postedCreditsByPurpose($reversal->id)[SystemAccountPurpose::Bank->value] ?? '0.000',
            'the cash side stays a single credit for the full net unreversed amount',
        );
    }

    // ---------------------------------------------------------------- helpers

    private function accountId(SystemAccountPurpose $purpose): string
    {
        return Account::findByPurposeOrFail($this->company->id, $purpose)->id;
    }

    private function postedInvoice(string $total): Document
    {
        return Document::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'document_number' => 'INV-'.Str::random(8),
            'document_date' => now()->toDateString(),
            'status' => DocumentStatus::Posted,
            'subtotal' => $total,
            'tax_amount' => '0.000',
            'total' => $total,
            'balance_due' => $total,
            'currency' => 'TND',
        ]);
    }

    /**
     * @param  list<array{document_id: string, amount: string}>  $allocations
     */
    private function payViaApi(string $amount, array $allocations): Payment
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->partner->id,
            'payment_method_id' => $this->cashMethod->id,
            'repository_id' => $this->repository->id,
            'amount' => $amount,
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'reference' => 'PAY-'.Str::random(8),
            'allocations' => $allocations,
        ]);

        $response->assertCreated();

        /** @var string $paymentId */
        $paymentId = $response->json('data.id');

        return Payment::query()->findOrFail($paymentId);
    }

    /**
     * Total DEBIT per `SystemAccountPurpose` across every POSTED journal entry
     * whose `source_id` is the given payment — the shape assertion of record.
     *
     * @return array<string, string>
     */
    private function postedDebitsByPurpose(string $paymentId): array
    {
        return $this->postedSidesByPurpose($paymentId, 'debit');
    }

    /**
     * @return array<string, string>
     */
    private function postedCreditsByPurpose(string $paymentId): array
    {
        return $this->postedSidesByPurpose($paymentId, 'credit');
    }

    /**
     * @return array<string, string>
     */
    private function postedSidesByPurpose(string $paymentId, string $side): array
    {
        $rows = JournalLine::query()
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->join('accounts', 'journal_lines.account_id', '=', 'accounts.id')
            ->where('journal_entries.company_id', $this->company->id)
            ->where('journal_entries.source_id', $paymentId)
            ->where('journal_entries.status', JournalEntryStatus::Posted->value)
            ->whereNotNull('accounts.system_purpose')
            ->groupBy('accounts.system_purpose')
            ->selectRaw("accounts.system_purpose as purpose, CAST(SUM(journal_lines.{$side}) AS TEXT) as total")
            ->get();

        $totals = [];
        foreach ($rows as $row) {
            /** @var string $purpose */
            $purpose = $row->getAttribute('purpose');
            /** @var string $total */
            $total = (string) $row->getAttribute('total');
            $totals[$purpose] = $total;
        }

        return $totals;
    }

    private function creditByPurpose(string $paymentId, string $sourceType, SystemAccountPurpose $purpose): string
    {
        /** @var object{total: string|null}|null $row */
        $row = JournalLine::query()
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.company_id', $this->company->id)
            ->where('journal_entries.source_id', $paymentId)
            ->where('journal_entries.source_type', $sourceType)
            ->where('journal_entries.status', JournalEntryStatus::Posted->value)
            ->where('journal_lines.account_id', $this->accountId($purpose))
            ->selectRaw('CAST(COALESCE(SUM(journal_lines.credit), 0) AS TEXT) as total')
            ->first();

        return (string) ($row->total ?? '0');
    }

    private function soleDebitLine(string $paymentId, SystemAccountPurpose $purpose): JournalLine
    {
        /** @var list<JournalLine> $lines */
        $lines = JournalLine::query()
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.company_id', $this->company->id)
            ->where('journal_entries.source_id', $paymentId)
            ->where('journal_entries.status', JournalEntryStatus::Posted->value)
            ->where('journal_lines.account_id', $this->accountId($purpose))
            ->where('journal_lines.debit', '>', 0)
            ->select('journal_lines.*')
            ->get()
            ->all();

        self::assertCount(1, $lines, "expected exactly one {$purpose->value} debit line");

        return $lines[0];
    }
}
