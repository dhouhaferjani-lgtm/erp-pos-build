<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Application\Services\PartnerBalanceService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
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
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Throwable;

/**
 * DPA `DPA-REV2-A` / **A10(i)** — the `Partner` row lock this lane ADDS to a
 * money path.
 *
 * Code-gate ruling 5.3: A10(i) is **demanded pre-merge** and is not deferrable
 * with Path B. This lane introduces a lock (`PaymentRefundService`, the A-D3
 * arm) and a read-then-write against a balance another transaction can consume
 * (`GeneralLedgerService::clearCustomerAdvanceToReceivable()`, which takes the
 * same lock). The lock is the only thing standing between A-D3's ceiling READ
 * and a concurrent order→invoice conversion. Shipping new concurrency control
 * with no concurrency test is missing coverage, not characterisation debt.
 *
 * **A10(ii)** (the pre-existing Payment↔Instrument AB-BA characterisation repro)
 * and **A10(iii)** (the lock order of record) travel with Path B per the same
 * ruling — that hazard ships in V4 already, is accepted and ticketed by A-D8,
 * and its outcome is a 500 rather than corruption.
 *
 * Two REAL processes over two independent PDO connections, following the
 * established `OutboundInstrumentConcurrencyTest` pattern:
 * `connectionsToTransact()` returns `[]` on PostgreSQL so fixtures COMMIT before
 * the fork and both connections can see them. `lockForUpdate()` is a no-op story
 * on SQLite, so the proof is PostgreSQL-only.
 *
 * ⚠️ **HONEST SCOPE — this test does NOT bind to the `Partner` lock alone.**
 * A bind probe was run: the `Partner::…->lockForUpdate()` at
 * `PaymentRefundService` (the A-D3 arm) was removed and this test was re-run
 * twice on real PostgreSQL. **It still passed.** The Partner lock is therefore
 * *defence in depth*, not the sole serialiser of these two paths.
 *
 * What actually orders them as well is
 * `GeneralLedgerService::generateEntryNumber()`, which takes a per-company
 * `pg_advisory_xact_lock` (transaction-scoped, released at commit). The child
 * holds it from the moment it creates the clearing entry until it commits, so
 * the parent's reversal cannot post its own entry until the child is done and
 * its effect is visible.
 *
 * This test is therefore a genuine **serialisation-outcome** test — it proves on
 * two real processes that the two paths cannot both consume the same pool and
 * that the `CustomerAdvance` liability is never over-drawn — but it is NOT proof
 * that the Partner lock is load-bearing. Reported to the code gate as a finding
 * rather than claimed as binding coverage: A-D3's read-then-write is protected,
 * and the Partner lock makes that protection explicit and local rather than
 * incidental to entry-number allocation, which is a reason to keep it.
 */
final class AdvanceReversalConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $partner;

    private PaymentRepository $repository;

    private PaymentMethod $cashMethod;

    /**
     * PostgreSQL fixtures must commit before forking so both independent PDOs
     * can see them. SQLite stays on the normal transaction-backed test path.
     *
     * @return list<string|null>
     */
    protected function connectionsToTransact(): array
    {
        return DB::getDriverName() === 'pgsql' ? [] : [config('database.default')];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::query()->create([
            'name' => 'AdvRev Concurrency Tenant',
            'slug' => 'advrev-conc-'.Str::random(6),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::query()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'AdvRev Concurrency Co',
            'legal_name' => 'AdvRev Concurrency Co SARL',
            'tax_id' => 'TAX-ADVCONC',
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
            'name' => 'AdvRev Concurrency User',
            'email' => 'advrev-conc-'.Str::random(6).'@example.com',
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
            'name' => 'Concurrency Customer',
            'type' => PartnerType::Customer,
        ]);
    }

    /**
     * **A10(i).** One advance of 1000. Two processes race:
     *   P1 reverses the advance (takes the `Partner` lock on the A-D3 arm, reads
     *      the ceiling, then posts);
     *   P2 applies 700 of the same pool to an invoice
     *      (`clearCustomerAdvanceToReceivable()`, which takes the SAME lock).
     *
     * They must SERIALISE. Whichever runs second sees the other's committed
     * effect, so the outcomes are mutually exclusive: either the reversal wins
     * (and the application then refuses — the pool is gone), or the application
     * wins (and the reversal then refuses A-D3, naming the reduced available).
     *
     * **The invariant that matters in every interleaving: the `CustomerAdvance`
     * liability is NEVER driven negative.** That is exactly what the lock buys —
     * without it both could read `available = 1000` and both post, over-drawing
     * the pool by 700.
     */
    public function test_a10i_the_reversal_and_a_concurrent_prepayment_application_serialise(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('lockForUpdate() is a no-op story on SQLite; the proof requires PostgreSQL.');
        }
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the two-process lock proof.');
        }

        $payment = $this->pureAdvancePayment('1000.000');
        $invoice = $this->postedInvoice('700.000');

        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($sockets);
        [$parentSocket, $childSocket] = $sockets;
        $resultFile = tempnam(sys_get_temp_dir(), 'advrev-lock-');
        self::assertIsString($resultFile);

        $pid = pcntl_fork();
        self::assertGreaterThanOrEqual(0, $pid);

        if ($pid === 0) {
            // CHILD — the concurrent order->invoice conversion.
            //
            // It opens its own transaction, applies 700 of the pool (which takes
            // the SAME `Partner` row lock inside
            // `clearCustomerAdvanceToReceivable()`), and only THEN signals the
            // parent — so the parent's reversal starts while this transaction is
            // still open and holding the lock. It holds for a beat before
            // committing, which is what forces the parent to either block on the
            // lock (correct) or read a stale pool and over-draw it (the bug).
            fclose($parentSocket);
            DB::disconnect();

            $outcome = ['outcome' => 'applied'];

            try {
                DB::beginTransaction();
                app(GeneralLedgerService::class)->clearCustomerAdvanceToReceivable(
                    companyId: $this->company->id,
                    partnerId: $this->partner->id,
                    invoiceId: $invoice->id,
                    amount: '700.000',
                    date: now(),
                    description: 'Concurrent prepayment application',
                    postedByUserId: $this->user->id,
                    currencyCode: 'TND',
                );

                // Lock held. Release the parent and hold a moment longer.
                fwrite($childSocket, '1');
                fclose($childSocket);
                usleep(1_500_000);

                DB::commit();
            } catch (Throwable $exception) {
                DB::rollBack();
                if (is_resource($childSocket)) {
                    fwrite($childSocket, '1');
                    fclose($childSocket);
                }
                $outcome = [
                    'outcome' => 'refused',
                    'error' => $exception::class,
                    'message' => $exception->getMessage(),
                ];
            }

            file_put_contents($resultFile, json_encode($outcome, JSON_THROW_ON_ERROR));
            exit(0);
        }

        // PARENT — the reversal. Starts only once the child holds the lock.
        fclose($childSocket);
        $parentOutcome = 'reversed';
        $parentMessage = '';

        try {
            fread($parentSocket, 1);
            fclose($parentSocket);

            try {
                app(PaymentRefundService::class)->reversePayment($payment, 'A10(i) race', $this->user->id);
            } catch (\DomainException $exception) {
                $parentOutcome = 'refused';
                $parentMessage = $exception->getMessage();
            }

            $status = 0;
            pcntl_waitpid($pid, $status);
            self::assertTrue(pcntl_wifexited($status), 'the child must exit cleanly, never deadlock-abort');

            /** @var array{outcome: string, message?: string} $childPayload */
            $childPayload = json_decode((string) file_get_contents($resultFile), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($childPayload);

            self::assertSame(
                'applied',
                $childPayload['outcome'],
                'fixture: the child must win the pool — it took the lock first',
            );

            // ---- THE INVARIANT THE LOCK BUYS -----------------------------
            // The child consumed 700 of a 1000 pool. The reversal wants the full
            // 1000. Serialised, the parent blocks, then reads available = 300 and
            // A-D3 REFUSES. Unserialised, the parent reads the stale 1000, posts,
            // and the two together over-draw the pool by 700.
            self::assertSame(
                'refused',
                $parentOutcome,
                'the reversal must BLOCK on the Partner lock and then refuse against the REDUCED pool. '
                .'Succeeding here means it read a stale ceiling while the child held 700 uncommitted — '
                .'the CustomerAdvance liability is over-drawn by 700.',
            );
            self::assertStringContainsString(
                'unconsumed',
                strtolower($parentMessage),
                'A-D3 must be what refuses, naming the remaining pool',
            );
            self::assertStringContainsString(
                '300.000',
                $parentMessage,
                'and it must name the POST-conversion figure, proving it read AFTER the child committed',
            );

            // The liability is never driven past zero in any interleaving.
            $advanceBalance = app(PartnerBalanceService::class)
                ->getCustomerAdvanceBalance($this->company->id, $this->partner->id);
            self::assertSame(
                0,
                bccomp('-300.000', $advanceBalance, 3),
                "1000 received, 700 applied, nothing reversed => -300 liability; got {$advanceBalance}",
            );
        } finally {
            if (is_resource($parentSocket)) {
                fclose($parentSocket);
            }
            if (is_file($resultFile)) {
                unlink($resultFile);
            }
        }
    }

    // ------------------------------------------------------------- helpers

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

    private function pureAdvancePayment(string $amount): Payment
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

        $entry = DB::transaction(fn () => app(GeneralLedgerService::class)->createCustomerAdvanceJournalEntry(
            companyId: $this->company->id,
            partnerId: $this->partner->id,
            advanceId: $payment->id,
            amount: $amount,
            paymentMethodAccountId: (string) $this->repository->gl_account_id,
            date: now(),
            user: $this->user,
            description: 'Advance received',
            currencyCode: 'TND',
            mode: PostingMode::SynchronousInTransaction,
        ));

        $payment->journal_entry_id = $entry->id;
        $payment->save();

        DB::transaction(fn () => app(TreasuryMovementServiceInterface::class)->record(new MovementIntent(
            repositoryId: $this->repository->id,
            tenantId: $this->repository->tenant_id,
            companyId: $this->repository->company_id,
            direction: MovementDirection::In,
            amount: $amount,
            currency: $this->repository->currency,
            sourceType: MovementSourceType::OpeningBalance,
            sourceId: $this->repository->id,
            idempotencyLeg: 'opening-'.Str::random(6),
            journalEntryId: null,
            occurredAt: null,
            reasonCode: null,
            reversesMovementId: null,
            createdBy: null,
            notes: 'A10(i) fixture opening balance',
            allowWhileFrozen: false,
        )));

        // `createCustomerAdvanceJournalEntry` posted synchronously; make sure no
        // Draft is left that would skew the pool the race reads.
        self::assertSame(
            JournalEntryStatus::Posted,
            JournalEntry::query()->findOrFail($entry->id)->status,
        );

        return $payment;
    }
}
