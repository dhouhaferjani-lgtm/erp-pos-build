<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Services;

use App\Modules\Accounting\Domain\Enums\PostingMode;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Services\DocumentStatusService;
use App\Modules\Identity\Domain\User;
use App\Modules\Treasury\Application\DTOs\MovementIntent;
use App\Modules\Treasury\Domain\Enums\AllocationTreatment;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\Enums\PaymentOrigin;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Contracts\Treasury\TreasuryMovementServiceInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MultiPaymentService
{
    public function __construct(
        private readonly CurrencyScaleResolverInterface $scaleResolver,
        private readonly GeneralLedgerService $glService,
        private readonly TreasuryMovementServiceInterface $movementService,
        private readonly DocumentAllocationClassifier $allocationClassifier,
        private readonly DocumentStatusService $documentStatus,
    ) {}

    /**
     * Create split payment across multiple payment methods.
     *
     * @param  array<int, array{payment_method_id: string, amount: numeric-string, repository_id?: string|null, instrument_id?: string|null, reference?: string|null}>  $paymentSplits
     * @return array<int, Payment>
     */
    public function createSplitPayment(
        Document $document,
        array $paymentSplits,
        ?string $userId = null,
        ?string $idempotencyKey = null
    ): array {
        // Validate total matches document balance
        /** @var numeric-string $totalSplit */
        $totalSplit = '0.00';
        foreach ($paymentSplits as $split) {
            /** @var numeric-string $splitAmount */
            $splitAmount = $split['amount'];
            $totalSplit = bcadd($totalSplit, $splitAmount, $this->scale());
        }

        // Treasury gate CRITICAL 1 (W-6 D2): this used to be
        // `$document->balance_due ?? $document->total`. `balance_due` is a
        // PostgreSQL trigger cache fired by allocation DML only, so it stays
        // NULL on a document that already carries an allocation the trigger
        // never observed — and the `?? total` fallback then required the split
        // to sum to the document's FULL total, ignoring what was already paid.
        /** @var numeric-string $docBalance */
        $docBalance = $document->outstandingBalance($this->documentScale($document));
        if (bccomp($totalSplit, $docBalance, $this->scale()) !== 0) {
            throw new \InvalidArgumentException(
                'Split payment total must equal document balance'
            );
        }

        if (count($paymentSplits) < 2) {
            throw new \InvalidArgumentException(
                'Split payment requires at least 2 payment methods'
            );
        }

        return DB::transaction(function () use ($document, $paymentSplits, $userId, $idempotencyKey): array {
            $payments = [];

            // N-6 — one classification for the whole split: every line settles
            // the SAME document, so posted-ness cannot differ between them.
            // Refuses a draft / cancelled / credit-note target with 422
            // DOCUMENT_NOT_ALLOCATABLE before any payment row is written.
            // C-0a0 — receivable side only (this path posts the customer GL
            // direction and increments a repository). It is also the path that
            // had NO type rejection of its own, so a posted supplier invoice
            // could reach it the moment the classifier learned to admit one.
            $treatment = $this->allocationClassifier->classifyReceivableSide($document);

            // Resolve the acting user once — synchronous in-transaction GL posting
            // (Task 19) requires an actor. Only the ledgered cash-line branch below
            // needs it; unledgered/nocash lines never touch it.
            $actingUser = $userId !== null ? User::query()->find($userId) : null;

            foreach ($paymentSplits as $index => $split) {
                /** @var numeric-string $lineAmount */
                $lineAmount = $split['amount'];

                // Spec §13 writer-inventory row 4 — `MultiPaymentService::createSplitPayment()`
                // → `web_admin`. Non-fiscal admin-side split-payment authoring.
                $payment = Payment::create([
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => $document->tenant_id,
                    'company_id' => $document->company_id,
                    'partner_id' => $document->partner_id,
                    'payment_method_id' => $split['payment_method_id'],
                    'instrument_id' => $split['instrument_id'] ?? null,
                    'repository_id' => $split['repository_id'] ?? null,
                    'location_id' => $document->location_id,
                    'amount' => $lineAmount,
                    'currency' => $document->currency,
                    'payment_date' => now(),
                    'status' => PaymentStatus::Completed,
                    'origin' => PaymentOrigin::WebAdmin,
                    'reference' => $split['reference'] ?? 'Split payment '.($index + 1)." for {$document->document_number}",
                    'notes' => "Split payment {$split['amount']} (part ".($index + 1).' of '.count($paymentSplits).')',
                    'created_by' => $userId,
                    // Task 19 idempotency: each line gets its own composed key (zero-padded
                    // index) so the partial unique index (company_id, idempotency_key) allows
                    // every line of one batch while still rejecting a duplicate batch replay.
                    'idempotency_key' => $idempotencyKey !== null
                        ? sprintf('%s:multi:%04d', $idempotencyKey, $index)
                        : null,
                ]);

                // Create allocation. N-6 — the marker records what the LEDGER
                // did with this line: 419 while the document has no posted
                // receivable, 411 once it has one.
                $allocationRow = PaymentAllocation::create([
                    'id' => Str::uuid()->toString(),
                    'payment_id' => $payment->id,
                    'document_id' => $document->id,
                    'amount' => $lineAmount,
                    'booked_as_advance' => $treatment === AllocationTreatment::Prepayment,
                ]);

                // Task 19: a split line that carries a ledgered repository is REAL cash
                // received against the document (customer AR payment, cash IN). Post the
                // GL entry SYNCHRONOUSLY (it takes the company advisory lock FIRST), then
                // record the movement through the single write port (which locks the repo
                // row). Global lock order: advisory -> repo (BLOCKER-1). A line with a null
                // repository_id — or a non-ledgered repository — is SKIPPED: the Payment +
                // allocation still stand, but no cash moves and no GL posts.
                $this->recordCashLine(
                    document: $document,
                    payment: $payment,
                    repositoryId: $split['repository_id'] ?? null,
                    amount: $lineAmount,
                    idempotencyLeg: "line:{$index}",
                    actingUser: $actingUser,
                    treatment: $treatment,
                    allocation: $allocationRow,
                );

                $payments[] = $payment;
            }

            // Update document balance. N-6 — a PREPAYMENT clears the balance
            // but moves NO lifecycle status: the document still owes its
            // posting, and `confirmed → paid` is refused by the status machine.
            if ($treatment === AllocationTreatment::ReceivableClearing && $document->type->canTransitionToPaid()) {
                $this->documentStatus->markPaid($document, ['balance_due' => '0.00']);
            } else {
                $document->update(['balance_due' => '0.00']);
            }

            return $payments;
        });
    }

    /**
     * Record deposit/advance payment (not allocated to specific document)
     */
    public function recordDeposit(
        string $tenantId,
        string $companyId,
        string $partnerId,
        string $paymentMethodId,
        string $amount,
        string $currency,
        ?string $repositoryId = null,
        ?string $instrumentId = null,
        ?string $reference = null,
        ?string $notes = null,
        ?string $userId = null,
        ?string $idempotencyKey = null
    ): Payment {
        /** @var numeric-string $amount */
        if (bccomp($amount, '0', $this->scale()) <= 0) {
            throw new \InvalidArgumentException('Deposit amount must be greater than zero');
        }

        return DB::transaction(function () use (
            $tenantId,
            $companyId,
            $partnerId,
            $paymentMethodId,
            $amount,
            $currency,
            $repositoryId,
            $instrumentId,
            $reference,
            $notes,
            $userId,
            $idempotencyKey
        ): Payment {
            // Spec §13 writer-inventory row 5 — `MultiPaymentService::recordDeposit()`
            // → `web_admin`. Unallocated deposits / advances authored from the web admin.
            $payment = Payment::create([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'partner_id' => $partnerId,
                'payment_method_id' => $paymentMethodId,
                'instrument_id' => $instrumentId,
                'repository_id' => $repositoryId,
                'amount' => $amount,
                'currency' => $currency,
                'payment_date' => now(),
                'status' => PaymentStatus::Completed,
                'origin' => PaymentOrigin::WebAdmin,
                'reference' => $reference ?? 'Deposit payment',
                'notes' => ($notes ?? 'Advance payment/deposit').' [UNALLOCATED]',
                'created_by' => $userId,
                'idempotency_key' => $idempotencyKey,
            ]);

            // Task 19: a deposit received into a ledgered repository is REAL cash IN.
            // Book it as a customer advance (Dr Bank / Cr Customer Advance) posted
            // SYNCHRONOUSLY (advisory lock first), then record the movement through the
            // single write port (repo row lock second) — global order advisory -> repo.
            // A deposit with a null repository_id — or a non-ledgered repository — is
            // SKIPPED: the Payment stands, but no cash moves and no GL posts.
            if ($repositoryId !== null) {
                $repository = PaymentRepository::query()
                    ->where('tenant_id', $tenantId)
                    ->where('company_id', $companyId)
                    ->find($repositoryId);

                if ($repository instanceof PaymentRepository && $repository->gl_account_id !== null) {
                    $actingUser = $userId !== null ? User::query()->find($userId) : null;

                    $journalEntry = $this->glService->createCustomerAdvanceJournalEntry(
                        companyId: $companyId,
                        partnerId: $partnerId,
                        advanceId: $payment->id,
                        amount: $amount,
                        paymentMethodAccountId: $repository->gl_account_id,
                        date: new \DateTimeImmutable,
                        user: $this->requireActor($actingUser),
                        description: "Deposit received - {$payment->reference}",
                        currencyCode: $payment->currency,
                        mode: PostingMode::SynchronousInTransaction,
                    );

                    $payment->journal_entry_id = $journalEntry->id;
                    $payment->save();

                    $this->recordInMovement(
                        repository: $repository,
                        payment: $payment,
                        amount: $amount,
                        idempotencyLeg: 'main',
                        journalEntryId: $journalEntry->id,
                        actingUserId: $actingUser?->id,
                    );
                }
            }

            return $payment;
        });
    }

    /**
     * Apply deposit to document (allocate previously unallocated payment)
     */
    public function applyDepositToDocument(
        Payment $deposit,
        Document $document,
        string $amount
    ): PaymentAllocation {
        if ($deposit->status !== PaymentStatus::Completed) {
            throw new \RuntimeException('Only completed deposits can be applied');
        }

        // Check unallocated amount
        /** @var numeric-string $depositAmount */
        $depositAmount = $deposit->amount;
        /** @var numeric-string $allocatedTotal */
        $allocatedTotal = (string) $deposit->allocations()->sum('amount');
        $unallocated = bcsub($depositAmount, $allocatedTotal, $this->scale());

        /** @var numeric-string $amount */
        if (bccomp($amount, $unallocated, $this->scale()) > 0) {
            throw new \InvalidArgumentException(
                "Amount exceeds unallocated deposit balance ({$unallocated})"
            );
        }

        return DB::transaction(function () use ($deposit, $document, $amount): PaymentAllocation {
            // N-6 — refuses a draft / cancelled / credit-note target (422
            // DOCUMENT_NOT_ALLOCATABLE) and decides whether the deposit settles
            // a receivable or stays an advance against an unposted document.
            // C-0a0 — receivable side only; see `createSplitPayment()`.
            $treatment = $this->allocationClassifier->classifyReceivableSide($document);

            $allocation = PaymentAllocation::create([
                'id' => Str::uuid()->toString(),
                'payment_id' => $deposit->id,
                'document_id' => $document->id,
                'amount' => $amount,
                // The deposit's own 419 entry was posted by
                // `RecordCustomerDepositService` when the money came in; there
                // is no per-allocation entry to link. The marker still records
                // that this allocation is sitting in 419, which is what the
                // posting path needs in order to clear it.
                'booked_as_advance' => $treatment === AllocationTreatment::Prepayment,
            ]);

            // Update document balance
            /** @var numeric-string $docBal */
            $docBal = $document->balance_due ?? $document->total;
            $newBalance = bcsub($docBal, $amount, $this->scale());
            $isSettled = bccomp($newBalance, '0', $this->scale()) === 0;

            if (
                $isSettled
                && $treatment === AllocationTreatment::ReceivableClearing
                && $document->type->canTransitionToPaid()
            ) {
                $this->documentStatus->markPaid($document, ['balance_due' => $newBalance]);
            } else {
                $document->update(['balance_due' => $newBalance]);
            }

            return $allocation;
        });
    }

    /**
     * Get unallocated deposit balance for a partner.
     *
     * Codex round-3 Finding 14 — defense-in-depth tenant/company scoping.
     * The Payment::where('partner_id', ...) query previously had NO tenant
     * predicate, so any caller with a partner_id could read foreign-tenant
     * payments. The controller now resolves the Partner under the current
     * CompanyContext before invoking, and this service guard re-applies
     * the same scope to prevent future internal callers from bypassing it.
     */
    public function getUnallocatedDepositBalance(
        string $tenantId,
        string $companyId,
        string $partnerId,
        string $currency
    ): string {
        $payments = Payment::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('partner_id', $partnerId)
            ->where('currency', $currency)
            ->where('status', PaymentStatus::Completed)
            ->with('allocations')
            ->get();

        $totalUnallocated = '0.00';

        foreach ($payments as $payment) {
            /** @var numeric-string $paymentAmount */
            $paymentAmount = $payment->amount;
            /** @var numeric-string $allocatedAmount */
            $allocatedAmount = (string) $payment->allocations->sum('amount');
            $unallocated = bcsub($paymentAmount, $allocatedAmount, $this->scale());

            if (bccomp($unallocated, '0', $this->scale()) > 0) {
                $totalUnallocated = bcadd($totalUnallocated, $unallocated, $this->scale());
            }
        }

        return $totalUnallocated;
    }

    /**
     * Record payment on account (credit balance for partner).
     *
     * @return array{payment: Payment, account_balance: string}
     */
    public function recordPaymentOnAccount(
        string $tenantId,
        string $companyId,
        string $partnerId,
        string $amount,
        string $currency,
        ?string $reference = null,
        ?string $notes = null,
        ?string $userId = null
    ): array {
        /** @var numeric-string $amount */
        if (bccomp($amount, '0', $this->scale()) <= 0) {
            throw new \InvalidArgumentException('Payment amount must be greater than zero');
        }

        $payment = DB::transaction(function () use (
            $tenantId,
            $companyId,
            $partnerId,
            $amount,
            $currency,
            $reference,
            $notes,
            $userId
        ): Payment {
            // Spec §13 writer-inventory row 6 — `MultiPaymentService::recordPaymentOnAccount()`
            // → `web_admin`. Customer-account credit-balance payment authored from the web admin.
            return Payment::create([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'partner_id' => $partnerId,
                'payment_method_id' => null, // On account doesn't require payment method
                'amount' => $amount,
                'currency' => $currency,
                'payment_date' => now(),
                'status' => PaymentStatus::Completed,
                'origin' => PaymentOrigin::WebAdmin,
                'reference' => $reference ?? 'Payment on account',
                'notes' => ($notes ?? 'Payment on account - credit balance').' [ON_ACCOUNT]',
                'created_by' => $userId,
            ]);
        });

        $accountBalance = $this->getUnallocatedDepositBalance($tenantId, $companyId, $partnerId, $currency);

        return [
            'payment' => $payment,
            'account_balance' => $accountBalance,
        ];
    }

    /**
     * Get partner account balance (unallocated payments).
     *
     * Codex round-3 Finding 14 — defense-in-depth tenant/company scoping.
     * The Payment::where('partner_id', ...) query previously had NO tenant
     * predicate, so any caller with a partner_id could read foreign-tenant
     * payments + the matching Payment collection. The controller now
     * resolves the Partner under the current CompanyContext before invoking,
     * and this service guard re-applies the same scope to prevent future
     * internal callers from bypassing it.
     *
     * @return array{partner_id: string, currency: string, unallocated_balance: string, deposit_count: int, deposits: Collection<int, Payment>}
     */
    public function getPartnerAccountBalance(
        string $tenantId,
        string $companyId,
        string $partnerId,
        string $currency
    ): array {
        $unallocatedBalance = $this->getUnallocatedDepositBalance($tenantId, $companyId, $partnerId, $currency);

        $deposits = Payment::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('partner_id', $partnerId)
            ->where('currency', $currency)
            ->where('status', PaymentStatus::Completed)
            ->whereRaw('amount > (SELECT COALESCE(SUM(amount), 0) FROM payment_allocations WHERE payment_id = payments.id)')
            ->get();

        return [
            'partner_id' => $partnerId,
            'currency' => $currency,
            'unallocated_balance' => $unallocatedBalance,
            'deposit_count' => $deposits->count(),
            'deposits' => $deposits,
        ];
    }

    /**
     * Validate split payment amounts.
     *
     * @param  array<int, array{amount?: numeric-string}>  $splits
     */
    public function validateSplitAmounts(array $splits, string $totalRequired): bool
    {
        /** @var numeric-string $totalSplit */
        $totalSplit = '0.00';

        foreach ($splits as $split) {
            /** @var numeric-string $splitAmt */
            $splitAmt = $split['amount'] ?? '0';
            if (! isset($split['amount']) || bccomp($splitAmt, '0', $this->scale()) <= 0) {
                return false;
            }

            $totalSplit = bcadd($totalSplit, $splitAmt, $this->scale());
        }

        /** @var numeric-string $totalRequired */
        return bccomp($totalSplit, $totalRequired, $this->scale()) === 0;
    }

    /**
     * Task 19: post the customer-payment GL entry and record the cash-in movement for
     * one split line that carries a ledgered repository. A null repository_id — or a
     * repository without a gl_account_id — is a non-cash line and is SKIPPED (no GL,
     * no movement); the Payment + allocation created by the caller still stand.
     *
     * The GL post is SYNCHRONOUS (it takes the company advisory lock first); the port's
     * record() then takes the repository row lock (global order advisory -> repo,
     * BLOCKER-1). Both run inside the caller's DB::transaction — the single movement per
     * line is keyed on the (stable, Task 16b) payment id + line index.
     *
     * @param  numeric-string  $amount
     */
    private function recordCashLine(
        Document $document,
        Payment $payment,
        ?string $repositoryId,
        string $amount,
        string $idempotencyLeg,
        ?User $actingUser,
        AllocationTreatment $treatment,
        PaymentAllocation $allocation,
    ): void {
        if ($repositoryId === null) {
            return;
        }

        $repository = PaymentRepository::query()
            ->where('tenant_id', $document->tenant_id)
            ->where('company_id', $document->company_id)
            ->find($repositoryId);

        if (! $repository instanceof PaymentRepository || $repository->gl_account_id === null) {
            return;
        }

        // N-6 — Dr Bank / Cr AR only when a RECEIVABLE exists. On a confirmed
        // (unposted) document there is no 411 debit to settle, so the money is
        // a customer advance: Dr Bank / Cr 419, cleared to 411 when the invoice
        // is posted.
        if ($treatment === AllocationTreatment::Prepayment) {
            $journalEntry = $this->glService->createCustomerAdvanceJournalEntry(
                companyId: $document->company_id,
                partnerId: $document->partner_id,
                advanceId: $payment->id,
                amount: $amount,
                paymentMethodAccountId: $repository->gl_account_id,
                date: new \DateTimeImmutable,
                user: $this->requireActor($actingUser),
                description: "Split prepayment (customer advance) - {$payment->reference}",
                currencyCode: $payment->currency,
                mode: PostingMode::SynchronousInTransaction,
            );

            $allocation->update(['advance_journal_entry_id' => $journalEntry->id]);
        } else {
            $journalEntry = $this->glService->createPaymentReceivedJournalEntry(
                companyId: $document->company_id,
                partnerId: $document->partner_id,
                paymentId: $payment->id,
                amount: $amount,
                paymentMethodAccountId: $repository->gl_account_id,
                date: new \DateTimeImmutable,
                description: "Split payment - {$payment->reference}",
                user: $this->requireActor($actingUser),
                currencyCode: $payment->currency,
                mode: PostingMode::SynchronousInTransaction,
            );
        }

        $payment->journal_entry_id = $journalEntry->id;
        $payment->save();

        $this->recordInMovement(
            repository: $repository,
            payment: $payment,
            amount: $amount,
            idempotencyLeg: $idempotencyLeg,
            journalEntryId: $journalEntry->id,
            actingUserId: $actingUser?->id,
        );
    }

    /**
     * Record exactly one cash-IN movement leg for a payment through the single write
     * port. The movement fact is stated in the repository's own currency (the port's
     * currency invariant), and keyed on the payment id + leg discriminator so a
     * deduped/retried payment can never mint a second movement.
     *
     * @param  numeric-string  $amount
     */
    private function recordInMovement(
        PaymentRepository $repository,
        Payment $payment,
        string $amount,
        string $idempotencyLeg,
        ?string $journalEntryId,
        ?string $actingUserId,
    ): void {
        $this->movementService->record(new MovementIntent(
            repositoryId: $repository->id,
            tenantId: $payment->tenant_id,
            companyId: $payment->company_id,
            direction: MovementDirection::In,
            amount: $amount,
            currency: $repository->currency,
            sourceType: MovementSourceType::Payment,
            sourceId: $payment->id,
            idempotencyLeg: $idempotencyLeg,
            journalEntryId: $journalEntryId,
            occurredAt: null,
            reasonCode: null,
            reversesMovementId: null,
            createdBy: $actingUserId,
            notes: null,
            allowWhileFrozen: false,
        ));
    }

    /**
     * Synchronous in-transaction GL posting requires an actor. When a cash line resolves
     * to a ledgered repository but no acting user is available, fail loudly rather than
     * moving cash with an unposted GL Draft.
     */
    private function requireActor(?User $actingUser): User
    {
        if (! $actingUser instanceof User) {
            throw new \DomainException('A cash-moving payment line requires an acting user to post its journal entry.');
        }

        return $actingUser;
    }

    private function scale(): int
    {
        return $this->scaleResolver->getScale();
    }

    /**
     * The scale to read a DOCUMENT's own outstanding balance at.
     *
     * CLAUDE.md rule 19: pass the ENTITY's currency, never a bare no-arg
     * `getScale()` — mirrors `AccountingService::documentScale()`.
     */
    private function documentScale(Document $document): int
    {
        return $this->scaleResolver->getScaleSafe($document->currency, 3);
    }
}
