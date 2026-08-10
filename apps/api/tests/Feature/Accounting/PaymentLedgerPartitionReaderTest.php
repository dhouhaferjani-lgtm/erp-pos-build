<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalCode;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\PostingMode;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\Accounting\PaymentLedgerPartitionReaderInterface;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * DPA `DPA-REV2-A` / task A5 — `PaymentLedgerPartitionReader`, the lane's sole
 * GL-shape selector (A-D2), and its predicate (A-D4).
 *
 * One case per shape the lane has to survive. The footprints are built directly
 * rather than through the payment writers on purpose: `AdvanceReversalGlShapeTest`
 * already proves each shape is REACHABLE through its real writer, so this file's
 * job is the PREDICATE — which `source_type`s count, which accounts, which entry
 * statuses.
 */
final class PaymentLedgerPartitionReaderTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $partner;

    private PaymentLedgerPartitionReaderInterface $reader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::query()->create([
            'name' => 'Partition Tenant',
            'slug' => 'partition-'.Str::random(6),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::query()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Partition Co',
            'legal_name' => 'Partition Co SARL',
            'tax_id' => 'TAX-PART',
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
            'name' => 'Partition User',
            'email' => 'partition-'.Str::random(6).'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        UserCompanyMembership::query()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $this->partner = Partner::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Partition Customer',
            'type' => PartnerType::Customer,
        ]);

        $this->reader = app(PaymentLedgerPartitionReaderInterface::class);
    }

    public function test_a_pure_receivable_payment_partitions_to_ar_only(): void
    {
        $paymentId = $this->postAr('500.000');

        $partition = $this->reader->read($this->company->id, $paymentId, 'TND');

        self::assertSame('500.000', $partition->arBacked);
        self::assertSame('0.000', $partition->advanceBacked);
        self::assertFalse($partition->isEmpty());
        self::assertSame('500.000', $partition->total());
    }

    public function test_a_pure_advance_partitions_to_the_advance_bucket_only(): void
    {
        $paymentId = $this->postAdvance('400.000');

        $partition = $this->reader->read($this->company->id, $paymentId, 'TND');

        self::assertSame('0.000', $partition->arBacked);
        self::assertSame('400.000', $partition->advanceBacked);
        self::assertFalse($partition->isEmpty());
    }

    /** C-2's shape: both buckets non-zero on ONE payment. */
    public function test_a_mixed_payment_partitions_into_both_buckets(): void
    {
        $paymentId = Str::uuid()->toString();
        $this->postAr('700.000', $paymentId);
        $this->postAdvance('300.000', $paymentId);

        $partition = $this->reader->read($this->company->id, $paymentId, 'TND');

        self::assertSame('700.000', $partition->arBacked);
        self::assertSame('300.000', $partition->advanceBacked);
        self::assertSame('1000.000', $partition->total(), 'the two buckets must account for the whole payment');
    }

    /**
     * Shape X — `payment_type = Advance` carrying AR-backed GL. The reader must
     * report AR, because the LEDGER is what it reads. `payment_type` is not an
     * input to this class at all, which is the entire point of A-D2.
     */
    public function test_shape_x_reports_ar_regardless_of_payment_type(): void
    {
        $paymentId = $this->postAr('200.000');

        $partition = $this->reader->read($this->company->id, $paymentId, 'TND');

        self::assertSame('200.000', $partition->arBacked);
        self::assertSame('0.000', $partition->advanceBacked);
    }

    /** Shape Y — `payment_type = DocumentPayment` carrying advance-only GL. */
    public function test_shape_y_reports_the_advance_bucket_regardless_of_payment_type(): void
    {
        $paymentId = $this->postAdvance('1200.000');

        $partition = $this->reader->read($this->company->id, $paymentId, 'TND');

        self::assertSame('0.000', $partition->arBacked);
        self::assertSame('1200.000', $partition->advanceBacked);
    }

    /**
     * Shape Z — `origin = Pos` carrying AR-backed GL. `PaymentOrigin` is likewise
     * not an input here, which is what lets A9 stop consulting it.
     */
    public function test_shape_z_reports_ar_regardless_of_origin(): void
    {
        $paymentId = $this->postAr('250.000');

        $partition = $this->reader->read($this->company->id, $paymentId, 'TND');

        self::assertSame('250.000', $partition->arBacked);
        self::assertFalse($partition->isEmpty(), 'a POS ACCOUNT PAYMENT is on account — never an empty partition');
    }

    /**
     * A pure POS **sale receipt** books revenue directly under
     * `source_type='pos_receipt'`, keyed on the RECEIPT rather than the payment.
     * Nothing matches the predicate, so the partition is EMPTY — the one case
     * `CancellationShape::PosRevenue` legitimately owns.
     */
    public function test_a_pos_sale_receipt_yields_an_empty_partition(): void
    {
        $paymentId = Str::uuid()->toString();
        $this->postRevenueReceipt('80.000');

        $partition = $this->reader->read($this->company->id, $paymentId, 'TND');

        self::assertTrue($partition->isEmpty());
        self::assertSame('0.000', $partition->arBacked);
        self::assertSame('0.000', $partition->advanceBacked);
    }

    /**
     * A-D4 ruling 3 — a DRAFT entry does not count. `createPostedExcessAllocationJournalEntry`
     * posts `AfterCommit`, so a failed post leaves a Draft. Counting only `posted`
     * makes the partition UNDER-count, which drives the caller's coverage belt to
     * REFUSE — the fail-closed direction, because a Draft is money we cannot prove
     * was recognised.
     */
    public function test_a_draft_entry_is_excluded(): void
    {
        $paymentId = Str::uuid()->toString();
        $this->postAr('700.000', $paymentId);
        $this->draftAdvance('300.000', $paymentId);

        $partition = $this->reader->read($this->company->id, $paymentId, 'TND');

        self::assertSame('700.000', $partition->arBacked);
        self::assertSame('0.000', $partition->advanceBacked, 'a Draft is not a recognised footprint');
        self::assertSame(
            '700.000',
            $partition->total(),
            'the total UNDER-counts the 1000 paid — which is exactly what makes the coverage belt refuse',
        );
    }

    /**
     * An unmodelled `source_type` is excluded. The reader reports what it can
     * classify; refusing is the caller's job (A-D4b's coverage belt), which is
     * why this is a zero rather than an exception.
     */
    public function test_an_unknown_source_type_is_excluded(): void
    {
        $paymentId = Str::uuid()->toString();
        $this->postAr('700.000', $paymentId);
        $this->postManualEntryAgainstAr('300.000', $paymentId, 'manual_adjustment');

        $partition = $this->reader->read($this->company->id, $paymentId, 'TND');

        self::assertSame('700.000', $partition->arBacked, "only 'customer_payment' and 'advance' are classified");
        self::assertSame('700.000', $partition->total());
    }

    /**
     * A-D4 ruling 2 — the reader keys on the purpose-resolved `account_id`, never
     * on `line_order`. Both builders put their partner leg at `line_order 1`, so a
     * positional predicate could not tell an AR credit from an advance credit.
     */
    public function test_it_keys_on_the_account_purpose_not_line_order(): void
    {
        $paymentId = Str::uuid()->toString();
        $this->postAr('700.000', $paymentId);
        $this->postAdvance('300.000', $paymentId);

        $arLine = JournalLine::query()
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.source_id', $paymentId)
            ->where('journal_entries.source_type', 'customer_payment')
            ->where('journal_lines.account_id', $this->accountId(SystemAccountPurpose::CustomerReceivable))
            ->value('journal_lines.line_order');
        $advanceLine = JournalLine::query()
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.source_id', $paymentId)
            ->where('journal_entries.source_type', 'advance')
            ->where('journal_lines.account_id', $this->accountId(SystemAccountPurpose::CustomerAdvance))
            ->value('journal_lines.line_order');

        self::assertSame(
            $arLine,
            $advanceLine,
            'precondition: both credits sit at the SAME line_order, so only the account can distinguish them',
        );

        $partition = $this->reader->read($this->company->id, $paymentId, 'TND');
        self::assertSame('700.000', $partition->arBacked);
        self::assertSame('300.000', $partition->advanceBacked);
    }

    /**
     * Rule 19 — the scale comes from the ENTITY currency passed in, never from a
     * no-arg `getScale()`. Queued jobs and console commands run with no
     * `CompanyContext` bound, where a bare `getScale()` throws.
     */
    public function test_it_resolves_scale_from_the_passed_currency_with_no_company_context(): void
    {
        $paymentId = $this->postAr('500.000');

        app(CompanyContext::class)->clear();

        $partition = $this->reader->read($this->company->id, $paymentId, 'TND');

        self::assertSame('500.000', $partition->arBacked);
        self::assertSame(3, $partition->scale);
    }

    /**
     * A chart that never mapped `CustomerAdvance` — every French company before
     * A2/A3 — yields a zero bucket rather than a 500. The caller's coverage belt
     * turns that into a refusal.
     */
    public function test_an_unmapped_purpose_yields_zero_rather_than_throwing(): void
    {
        $paymentId = $this->postAr('500.000');

        DB::table('accounts')
            ->where('company_id', $this->company->id)
            ->where('system_purpose', SystemAccountPurpose::CustomerAdvance->value)
            ->update(['system_purpose' => null]);

        $partition = $this->reader->read($this->company->id, $paymentId, 'TND');

        self::assertSame('500.000', $partition->arBacked);
        self::assertSame('0.000', $partition->advanceBacked);
    }

    /** A payment from another company must never leak into this one's partition. */
    public function test_it_is_scoped_to_the_company(): void
    {
        $paymentId = $this->postAr('500.000');

        $partition = $this->reader->read(Str::uuid()->toString(), $paymentId, 'TND');

        self::assertTrue($partition->isEmpty());
    }

    // ---------------------------------------------------------------- helpers

    private function accountId(SystemAccountPurpose $purpose): string
    {
        return Account::findByPurposeOrFail($this->company->id, $purpose)->id;
    }

    private function postAr(string $amount, ?string $paymentId = null): string
    {
        $paymentId ??= Str::uuid()->toString();

        DB::transaction(fn () => app(GeneralLedgerService::class)->createPaymentReceivedJournalEntry(
            companyId: $this->company->id,
            partnerId: $this->partner->id,
            paymentId: $paymentId,
            amount: $amount,
            paymentMethodAccountId: $this->accountId(SystemAccountPurpose::Bank),
            date: now(),
            description: 'AR footprint',
            user: $this->user,
            currencyCode: 'TND',
            mode: PostingMode::SynchronousInTransaction,
        ));

        return $paymentId;
    }

    private function postAdvance(string $amount, ?string $paymentId = null): string
    {
        $paymentId ??= Str::uuid()->toString();

        DB::transaction(fn () => app(GeneralLedgerService::class)->createCustomerAdvanceJournalEntry(
            companyId: $this->company->id,
            partnerId: $this->partner->id,
            advanceId: $paymentId,
            amount: $amount,
            paymentMethodAccountId: $this->accountId(SystemAccountPurpose::Bank),
            date: now(),
            user: $this->user,
            description: 'Advance footprint',
            currencyCode: 'TND',
            mode: PostingMode::SynchronousInTransaction,
        ));

        return $paymentId;
    }

    /** A POS sale receipt: Dr cash / Cr ProductRevenue, keyed on the RECEIPT. */
    private function postRevenueReceipt(string $amount): void
    {
        $this->postRawEntry(
            sourceType: 'pos_receipt',
            sourceId: Str::uuid()->toString(),
            debitAccountId: $this->accountId(SystemAccountPurpose::Bank),
            creditAccountId: $this->accountId(SystemAccountPurpose::ProductRevenue),
            amount: $amount,
            status: JournalEntryStatus::Posted,
        );
    }

    private function draftAdvance(string $amount, string $paymentId): void
    {
        $this->postRawEntry(
            sourceType: 'advance',
            sourceId: $paymentId,
            debitAccountId: $this->accountId(SystemAccountPurpose::Bank),
            creditAccountId: $this->accountId(SystemAccountPurpose::CustomerAdvance),
            amount: $amount,
            status: JournalEntryStatus::Draft,
        );
    }

    private function postManualEntryAgainstAr(string $amount, string $paymentId, string $sourceType): void
    {
        $this->postRawEntry(
            sourceType: $sourceType,
            sourceId: $paymentId,
            debitAccountId: $this->accountId(SystemAccountPurpose::Bank),
            creditAccountId: $this->accountId(SystemAccountPurpose::CustomerReceivable),
            amount: $amount,
            status: JournalEntryStatus::Posted,
        );
    }

    private function postRawEntry(
        string $sourceType,
        string $sourceId,
        string $debitAccountId,
        string $creditAccountId,
        string $amount,
        JournalEntryStatus $status,
    ): void {
        DB::transaction(function () use ($sourceType, $sourceId, $debitAccountId, $creditAccountId, $amount, $status): void {
            $entry = JournalEntry::query()->create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'entry_number' => 'RAW-'.Str::random(8),
                'entry_date' => now(),
                'description' => 'Partition fixture: '.$sourceType,
                'status' => JournalEntryStatus::Draft,
                'source_type' => $sourceType,
                'journal_code' => JournalCode::fromSourceType($sourceType),
                'source_id' => $sourceId,
            ]);
            JournalLine::query()->create([
                'journal_entry_id' => $entry->id,
                'account_id' => $debitAccountId,
                'partner_id' => null,
                'debit' => $amount,
                'credit' => '0',
                'description' => 'fixture debit',
                'line_order' => 0,
            ]);
            JournalLine::query()->create([
                'journal_entry_id' => $entry->id,
                'account_id' => $creditAccountId,
                'partner_id' => $this->partner->id,
                'debit' => '0',
                'credit' => $amount,
                'description' => 'fixture credit',
                'line_order' => 1,
            ]);

            if ($status === JournalEntryStatus::Posted) {
                app(GeneralLedgerService::class)->postEntryNow($entry, $this->user, 'TND');
            }
        });
    }
}
