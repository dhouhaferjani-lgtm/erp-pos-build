<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Application\Services\Reports\CashMovementsReportService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalCode;
use App\Modules\Accounting\Domain\Enums\PostingMode;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
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
use App\Modules\Treasury\Application\DTOs\MovementIntent;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\Services\PaymentRefundService;
use App\Shared\Contracts\Treasury\TreasuryMovementServiceInterface;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * DPA `DPA-REV2-A` / tasks A11 (reporting) and A12 (API surface).
 *
 * **A11 / A-D11 — the 1:N is DELIBERATE and asserted per shape.** A reversal is
 * reported through its GL TWIN, never through the payments leg (`Reversal` is
 * deliberately absent from `OUTGOING_PAYMENT_TYPES`). The twin emits one row per
 * journal LINE on a repository GL account, so a MIXED reversal — which posts two
 * entries, both crediting the same till — shows **two `out` rows against one
 * `repository_movements` row**. The total is right; the 1:1 correspondence is
 * not, and that is accepted rather than papered over: collapsing the two entries
 * into one would put two different account credits in a single entry, which is
 * strictly worse.
 *
 * The consequence is fed to the expert as OQ-4 input, not endorsed: a single
 * mixed reversal now splits one economic act across **two FEC journals**,
 * `customer_payment_refund → BQ` and `customer_advance_refund → OD`. This file
 * PINS that split as today's behaviour so the expert has a fact to rule on.
 */
final class AdvanceReversalReportingAndApiTest extends TestCase
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
            'name' => 'AdvRev Reporting Tenant',
            'slug' => 'advrev-rep-'.Str::random(6),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::query()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'AdvRev Reporting Co',
            'legal_name' => 'AdvRev Reporting Co SARL',
            'tax_id' => 'TAX-ADVREP',
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
            'name' => 'AdvRev User',
            'email' => 'advrev-rep-'.Str::random(6).'@example.com',
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
            'gl_account_id' => Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::Bank)->id,
            'account_id' => null,
            'is_active' => true,
        ]);

        $this->partner = Partner::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Reporting Customer',
            'type' => PartnerType::Customer,
        ]);

        $this->refundService = app(PaymentRefundService::class);
    }

    // ------------------------------------------------------------------ A11

    /** A PURE reversal posts one entry, so the twin emits exactly ONE `out` row. */
    public function test_a11_a_pure_reversal_reports_one_out_row(): void
    {
        $invoice = $this->postedInvoice('500.000');
        $payment = $this->payViaApi('500.000', [['document_id' => $invoice->id, 'amount' => '500.000']]);

        $reversal = $this->refundService->reversePayment($payment, 'pure reversal', $this->user->id);
        self::assertInstanceOf(Payment::class, $reversal);

        $outRows = $this->outRowsForReversal($reversal->id);

        self::assertCount(1, $outRows, 'a pure reversal posts ONE entry, so the twin emits ONE row');
        self::assertSame(0, bccomp('500.000', (string) $outRows[0]['amount'], 3));
        self::assertSame(
            1,
            $this->movementCountFor($payment->id, $reversal->id),
            'and exactly one repository_movements row',
        );
    }

    /**
     * A MIXED reversal posts TWO entries against the same till, so the twin
     * emits TWO `out` rows summing to the single movement — A-D11's accepted 1:N.
     */
    public function test_a11_a_mixed_reversal_reports_two_out_rows_summing_to_one_movement(): void
    {
        $invoice = $this->postedInvoice('700.000');
        $payment = $this->payViaApi('1000.000', [['document_id' => $invoice->id, 'amount' => '700.000']]);

        $reversal = $this->refundService->reversePayment($payment, 'mixed reversal', $this->user->id);
        self::assertInstanceOf(Payment::class, $reversal);

        $outRows = $this->outRowsForReversal($reversal->id);

        self::assertCount(
            2,
            $outRows,
            'A-D11: the twin emits one row per journal LINE on a repository account, and a mixed '
            .'reversal credits that account twice. Accepted, not a bug.',
        );

        $total = '0.000';
        foreach ($outRows as $row) {
            $total = bcadd($total, (string) $row['amount'], 3);
        }
        self::assertSame(0, bccomp('1000.000', $total, 3), 'the two rows sum to the whole net');

        self::assertSame(
            1,
            $this->movementCountFor($payment->id, $reversal->id),
            'against exactly ONE repository_movements row — this is the 1:N A-D11 accepts',
        );
    }

    /**
     * OQ-4 input, pinned rather than endorsed: one economic act, two FEC
     * journals. `customer_payment_refund` maps to `BQ` and
     * `customer_advance_refund` falls through to `OD`.
     *
     * **Do not "fix" this without the expert ruling** — reclassifying the advance
     * family would move EXISTING `'advance'` entries between journals.
     */
    public function test_a11_a_mixed_reversal_splits_across_bq_and_od_journals(): void
    {
        $invoice = $this->postedInvoice('700.000');
        $payment = $this->payViaApi('1000.000', [['document_id' => $invoice->id, 'amount' => '700.000']]);

        $reversal = $this->refundService->reversePayment($payment, 'mixed reversal', $this->user->id);
        self::assertInstanceOf(Payment::class, $reversal);

        $codes = JournalEntry::query()
            ->where('company_id', $this->company->id)
            ->where('source_id', $reversal->id)
            ->pluck('journal_code', 'source_type')
            ->all();

        self::assertSame(JournalCode::Bank, $codes['customer_payment_refund'] ?? null);
        self::assertSame(
            JournalCode::Misc,
            $codes['customer_advance_refund'] ?? null,
            'OQ-4: the advance family is OD. Pinned as the input to the expert question, not endorsed.',
        );
    }

    /**
     * The two registration rules A-D11 forbids changing. `customer_advance_refund`
     * must NOT join `PAYMENT_BACKED_SOURCE_TYPES` — that list de-duplicates GL
     * twins against payment-leg rows, and a reversal has no payment-leg row to
     * de-duplicate against, so adding it would DELETE the advance half of every
     * mixed reversal from the report.
     */
    public function test_a11_the_reporting_registration_rules_are_unchanged(): void
    {
        $reflection = new \ReflectionClass(CashMovementsReportService::class);

        /** @var list<string> $paymentBacked */
        $paymentBacked = $reflection->getConstant('PAYMENT_BACKED_SOURCE_TYPES');
        /** @var list<string> $outgoing */
        $outgoing = $reflection->getConstant('OUTGOING_PAYMENT_TYPES');

        self::assertNotContains(
            'customer_advance_refund',
            $paymentBacked,
            'A-D11: registering it would de-duplicate the advance twin out of existence',
        );
        self::assertContains('advance', $paymentBacked, 'the ORIGINAL advance leg stays registered');
        self::assertNotContains(
            PaymentType::Reversal->value,
            $outgoing,
            'D-12: a reversal row is negative by design and is reported through its GL twin only',
        );
    }

    // ------------------------------------------------------------------ A12

    /** The success path over HTTP, after A7 made an advance reversible. */
    public function test_a12_reversing_an_advance_succeeds_over_the_http_api(): void
    {
        $payment = $this->pureAdvancePayment('400.000');

        $this->actingAs($this->user)
            ->postJson("/api/v1/payments/{$payment->id}/reverse", ['reason' => 'http advance reversal'])
            ->assertOk();

        self::assertSame(PaymentStatus::Reversed, $payment->fresh()?->status);
    }

    /**
     * A12's actual claim: the NEW refusal classes land on the controller's
     * existing `\Exception` → 422 catch rather than surfacing as 500s.
     * `\DomainException` extends `\LogicException` extends `\Exception`, so they
     * do — but "it should work" is not evidence, and a 500 here would be an
     * outage-grade regression on a money path.
     */
    public function test_a12_the_new_advance_refusals_surface_as_422_not_500(): void
    {
        // A-D3's ceiling: an advance whose liability was never posted has zero
        // available, so reversing it refuses.
        $payment = $this->pureAdvancePayment('400.000', postGl: false);

        // Give it a journal entry with an unclassifiable footprint so belt 1 fires.
        $entry = DB::transaction(fn () => app(GeneralLedgerService::class)->createPaymentReceivedJournalEntry(
            companyId: $this->company->id,
            partnerId: $this->partner->id,
            paymentId: Str::uuid()->toString(),
            amount: '400.000',
            paymentMethodAccountId: (string) $this->repository->gl_account_id,
            date: now(),
            description: 'unrelated footprint',
            user: $this->user,
            currencyCode: 'TND',
            mode: PostingMode::SynchronousInTransaction,
        ));
        $payment->journal_entry_id = $entry->id;
        $payment->save();

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/payments/{$payment->id}/reverse", ['reason' => 'must refuse'])
            ->assertStatus(422);

        self::assertIsString($response->json('error'), 'the refusal surfaces in the error envelope');
        self::assertSame(
            PaymentStatus::Completed,
            $payment->fresh()?->status,
            'a refusal writes nothing — the original stays Completed',
        );
        $this->assertDatabaseCount('repository_movements', 0);
    }

    // -------------------------------------------------------------- helpers

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

    private function pureAdvancePayment(string $amount, bool $postGl = true): Payment
    {
        $payment = Payment::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'payment_method_id' => $this->cashMethod->id,
            'repository_id' => $this->repository->id,
            'amount' => $amount,
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::Advance,
            'reference' => 'ADV-'.Str::random(8),
            'created_by' => $this->user->id,
        ]);

        if ($postGl) {
            $entry = DB::transaction(fn () => app(GeneralLedgerService::class)->createCustomerAdvanceJournalEntry(
                companyId: $this->company->id,
                partnerId: $this->partner->id,
                advanceId: $payment->id,
                amount: $amount,
                paymentMethodAccountId: (string) $this->repository->gl_account_id,
                date: now(),
                user: $this->user,
                description: 'Pure advance',
                currencyCode: 'TND',
                mode: PostingMode::SynchronousInTransaction,
            ));
            $payment->journal_entry_id = $entry->id;
            $payment->save();
            $this->fundRepository($amount);
        }

        return $payment;
    }

    private function fundRepository(string $amount): void
    {
        DB::transaction(fn () => app(TreasuryMovementServiceInterface::class)
            ->record(new MovementIntent(
                repositoryId: $this->repository->id,
                tenantId: $this->repository->tenant_id,
                companyId: $this->repository->company_id,
                direction: MovementDirection::In,
                amount: $amount,
                currency: $this->repository->currency,
                sourceType: MovementSourceType::OpeningBalance,
                sourceId: $this->repository->id,
                idempotencyLeg: 'opening',
                journalEntryId: null,
                occurredAt: null,
                reasonCode: null,
                reversesMovementId: null,
                createdBy: null,
                notes: 'A11/A12 fixture opening balance',
                allowWhileFrozen: false,
            )));
        $this->repository->refresh();
    }

    /**
     * The `out` rows the cash-movements report attributes to a reversal, via its
     * GL twin (the reversal's own entries, keyed `source_id = reversal id`).
     *
     * @return list<array<string, mixed>>
     */
    private function outRowsForReversal(string $reversalPaymentId): array
    {
        $report = app(CashMovementsReportService::class)->generate(
            companyId: $this->company->id,
            companyCurrency: 'TND',
            from: CarbonImmutable::now()->subDay(),
            to: CarbonImmutable::now()->addDay(),
            repositoryId: null,
            direction: 'out',
            locationIds: [],
            page: 1,
            perPage: 100,
        );

        /** @var iterable<int, mixed> $rows */
        $rows = $report['data'] ?? [];

        $matched = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $sourceType = (string) ($row['source_type'] ?? '');
            $sourceId = (string) ($row['source_id'] ?? '');

            // The reversal's GL twins: both reversing entries key on the REVERSAL
            // payment id (D-9), with different source types.
            if ($sourceId === $reversalPaymentId
                && in_array($sourceType, ['customer_payment_refund', 'customer_advance_refund'], true)) {
                $matched[] = $row;
            }
        }

        return $matched;
    }

    private function movementCountFor(string $originalPaymentId, string $reversalPaymentId): int
    {
        return DB::table('repository_movements')
            ->where('idempotency_key', "refund:{$originalPaymentId}:reversal:{$reversalPaymentId}")
            ->count();
    }
}
