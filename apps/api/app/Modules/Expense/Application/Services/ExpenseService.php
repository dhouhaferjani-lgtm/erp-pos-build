<?php

declare(strict_types=1);

namespace App\Modules\Expense\Application\Services;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\PostingMode;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentAdditionalCost;
use App\Modules\Document\Domain\Enums\AdditionalCostType;
use App\Modules\Document\Domain\Enums\CostApplicationPath;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\LandedCostSplitMethod;
use App\Modules\Expense\Application\DTOs\PayExpenseRequestData;
use App\Modules\Expense\Application\Exceptions\LinkedCostException;
use App\Modules\Expense\Domain\Enums\ExpenseKind;
use App\Modules\Expense\Domain\ExpenseMetadata;
use App\Modules\Identity\Domain\User;
use App\Modules\Taxation\Domain\Entities\DocumentTaxDetail;
use App\Modules\Taxation\Domain\Enums\TaxType;
use App\Modules\Treasury\Application\DTOs\InstrumentEventPayload;
use App\Modules\Treasury\Application\DTOs\MovementIntent;
use App\Modules\Treasury\Application\DTOs\ReceiveInstrumentData;
use App\Modules\Treasury\Application\Services\InstrumentAccountResolver;
use App\Modules\Treasury\Application\Services\InstrumentLifecycleService;
use App\Modules\Treasury\Application\Services\OutboundRepositoryValidator;
use App\Modules\Treasury\Domain\Enums\InstrumentAccountPurpose;
use App\Modules\Treasury\Domain\Enums\InstrumentDirection;
use App\Modules\Treasury\Domain\Enums\InstrumentEventType;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\Enums\InstrumentOrigin;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\InstrumentEvent;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\RepositoryMovement;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Contracts\Document\OperationResolverInterface;
use App\Shared\Contracts\Inventory\LinkedCostApplicatorInterface;
use App\Shared\Contracts\Treasury\TreasuryMovementServiceInterface;
use App\Shared\Domain\CurrencyScale;
use App\Shared\Domain\ExpenseVatSplit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Service for managing expenses.
 */
final class ExpenseService
{
    public function __construct(
        private readonly GeneralLedgerService $glService,
        private readonly OperationResolverInterface $operationResolver,
        private readonly LinkedCostApplicatorInterface $linkedCostApplicator,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
        private readonly TreasuryMovementServiceInterface $movementService,
        private readonly InstrumentLifecycleService $instrumentLifecycleService,
        private readonly InstrumentAccountResolver $instrumentAccountResolver,
        private readonly OutboundRepositoryValidator $outboundRepositoryValidator,
    ) {}

    /**
     * Create a new expense.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $user): Document
    {
        $idempotencyKey = $data['idempotency_key'] ?? null;
        if ($idempotencyKey !== null) {
            $existing = ExpenseMetadata::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                /** @var Document $doc */
                $doc = Document::query()->whereKey($existing->document_id)->firstOrFail();

                return $doc->load('expenseMetadata');
            }
        }

        $company = Company::query()->whereKey($data['company_id'])->firstOrFail();
        $companyCurrency = (string) $company->currency;
        $scale = $this->scaleResolver->getScale($companyCurrency);
        $total = (string) ($data['total'] ?? '0.00');
        $vatAmount = isset($data['vat_amount']) ? (string) $data['vat_amount'] : null;
        $vatRate = isset($data['vat_rate']) ? (string) $data['vat_rate'] : null;
        $vatDeductiblePercent = isset($data['vat_deductible_percent']) ? (string) $data['vat_deductible_percent'] : null;
        if (! is_numeric($total) || ($vatAmount !== null && ! is_numeric($vatAmount))) {
            throw new \InvalidArgumentException('Expense amounts must be numeric strings.');
        }

        $linked = $this->prepareLinkedCost($data, $user->tenant_id, $data['company_id'], $companyCurrency);

        $this->assertVatInvariants(
            [
                'total' => $total,
                'vat_amount' => $vatAmount,
                'vat_rate' => $vatRate,
                'vat_deductible_percent' => $vatDeductiblePercent,
            ],
            $linked === null ? ExpenseKind::Generic : ExpenseKind::LinkedCost,
            $scale,
        );

        if ($vatAmount !== null && bccomp($vatAmount, '0', $scale) === 0) {
            $vatAmount = null;
        }
        $vatAmount = $vatAmount !== null ? CurrencyScale::bcformatStrict($vatAmount, $scale) : null;
        $vatRate = $vatAmount !== null ? $vatRate : null;
        $vatDeductiblePercent = $vatAmount !== null ? ($vatDeductiblePercent ?? '100.00') : null;
        $subtotal = $vatAmount !== null ? bcsub($total, $vatAmount, $scale) : $total;

        return DB::transaction(function () use ($data, $user, $idempotencyKey, $companyCurrency, $linked, $subtotal, $total, $vatAmount, $vatRate, $vatDeductiblePercent): Document {
            // Create the expense document
            $expense = Document::create([
                'tenant_id' => $user->tenant_id,
                'company_id' => $data['company_id'],
                'partner_id' => $data['partner_id'] ?? null,
                'type' => DocumentType::Expense,
                'status' => DocumentStatus::Draft,
                'currency' => $companyCurrency,
                'document_date' => $data['document_date'] ?? $data['payment_date'] ?? now()->toDateString(),
                'total' => $total,
                'subtotal' => $subtotal,
                'tax_amount' => $vatAmount,
                'notes' => $data['notes'] ?? null,
            ]);

            // Create expense metadata
            ExpenseMetadata::create([
                'document_id' => $expense->id,
                'expense_category_id' => $data['expense_category_id'] ?? null,
                'payment_method_id' => $data['payment_method_id'] ?? null,
                'payment_repository_id' => $data['payment_repository_id'] ?? null,
                'payment_date' => $data['payment_date'] ?? null,
                'is_paid' => $data['is_paid'] ?? true,
                'receipt_number' => $data['receipt_number'] ?? null,
                'vendor_name' => $data['vendor_name'] ?? null,
                'vat_rate' => $vatRate,
                'vat_deductible_percent' => $vatDeductiblePercent,
                'expense_kind' => $linked === null ? ExpenseKind::Generic : ExpenseKind::LinkedCost,
                'idempotency_key' => $idempotencyKey,
            ]);

            if ($linked !== null) {
                DocumentAdditionalCost::create([
                    'id' => Str::uuid()->toString(),
                    'document_id' => $linked['operation']->id,
                    'cost_type' => $this->costType($data['cost_type'] ?? null),
                    'description' => $data['notes'] ?? null,
                    'amount' => $data['total'],
                    'expense_document_id' => $expense->id,
                    'application_path' => CostApplicationPath::WacAdjustment,
                    'split_method' => $this->splitMethod($data['split_method'] ?? null),
                ]);
            }

            return $expense->load('expenseMetadata');
        });
    }

    /**
     * Update an existing expense.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Document $expense, array $data): Document
    {
        if ($expense->status !== DocumentStatus::Draft) {
            throw new \RuntimeException('Only draft expenses can be updated');
        }

        $company = Company::query()->whereKey($expense->company_id)->firstOrFail();
        $scale = $this->scaleResolver->getScale((string) $company->currency);
        $total = (string) ($data['total'] ?? $expense->total);
        $vatAmount = array_key_exists('vat_amount', $data)
            ? ($data['vat_amount'] !== null ? (string) $data['vat_amount'] : null)
            : ($expense->tax_amount !== null ? (string) $expense->tax_amount : null);
        if (! is_numeric($total) || ($vatAmount !== null && ! is_numeric($vatAmount))) {
            throw new \InvalidArgumentException('Expense amounts must be numeric strings.');
        }

        $metadata = $expense->expenseMetadata;
        if ($metadata === null) {
            throw new \RuntimeException('Expense metadata is required for expense updates.');
        }
        $vatRate = array_key_exists('vat_rate', $data)
            ? ($data['vat_rate'] !== null ? (string) $data['vat_rate'] : null)
            : $metadata->vat_rate;
        $vatDeductiblePercent = array_key_exists('vat_deductible_percent', $data)
            ? ($data['vat_deductible_percent'] !== null ? (string) $data['vat_deductible_percent'] : null)
            : $metadata->vat_deductible_percent;
        $kind = $metadata->expense_kind;
        $this->assertVatInvariants(
            [
                'total' => $total,
                'vat_amount' => $vatAmount,
                'vat_rate' => $vatRate,
                'vat_deductible_percent' => $vatDeductiblePercent,
            ],
            $kind,
            $scale,
        );

        if ($vatAmount !== null && bccomp($vatAmount, '0', $scale) === 0) {
            $vatAmount = null;
        }
        $vatAmount = $vatAmount !== null ? CurrencyScale::bcformatStrict($vatAmount, $scale) : null;
        $vatRate = $vatAmount !== null ? $vatRate : null;
        $vatDeductiblePercent = $vatAmount !== null ? ($vatDeductiblePercent ?? '100.00') : null;
        $subtotal = $vatAmount !== null ? bcsub($total, $vatAmount, $scale) : $total;

        return DB::transaction(function () use ($expense, $data, $metadata, $subtotal, $total, $vatAmount, $vatRate, $vatDeductiblePercent): Document {
            // Update document
            $expense->update([
                'partner_id' => array_key_exists('partner_id', $data)
                    ? $data['partner_id']
                    : $expense->partner_id,
                'document_date' => $data['document_date'] ?? $data['payment_date'] ?? $expense->document_date,
                'total' => $total,
                'subtotal' => $subtotal,
                'tax_amount' => $vatAmount,
                'notes' => $data['notes'] ?? $expense->notes,
            ]);

            // Update metadata
            $metadata->update([
                'expense_category_id' => $data['expense_category_id'] ?? $metadata->expense_category_id,
                'payment_method_id' => $data['payment_method_id'] ?? $metadata->payment_method_id,
                'payment_repository_id' => $data['payment_repository_id'] ?? $metadata->payment_repository_id,
                'payment_date' => $data['payment_date'] ?? $metadata->payment_date,
                'is_paid' => $data['is_paid'] ?? $metadata->is_paid,
                'receipt_number' => $data['receipt_number'] ?? $metadata->receipt_number,
                'vendor_name' => $data['vendor_name'] ?? $metadata->vendor_name,
                'vat_rate' => $vatRate,
                'vat_deductible_percent' => $vatDeductiblePercent,
            ]);

            $freshExpense = $expense->fresh(['expenseMetadata']);
            if ($freshExpense === null) {
                throw new \RuntimeException('Failed to refresh expense after update');
            }

            return $freshExpense;
        });
    }

    /**
     * @param  array{total: numeric-string, vat_amount: numeric-string|null, vat_rate: string|null, vat_deductible_percent: string|null}  $effective
     */
    private function assertVatInvariants(array $effective, ExpenseKind $kind, int $scale): void
    {
        $vat = $effective['vat_amount'];
        if (
            $kind === ExpenseKind::LinkedCost
            && ($vat !== null || $effective['vat_rate'] !== null || $effective['vat_deductible_percent'] !== null)
        ) {
            throw new \DomainException('VAT fields are not supported on linked-cost expenses; landed-cost capitalization consumes the full amount. Record VAT-bearing costs as generic expenses.');
        }

        if ($vat === null) {
            return;
        }

        if ($this->amountExceedsCurrencyScale($vat, $scale)) {
            throw new \DomainException('Amount precision exceeds the currency scale.');
        }

        if (bccomp($vat, '0', $scale) === 0) {
            return;
        }

        if ($this->amountExceedsCurrencyScale($effective['total'], $scale)) {
            throw new \DomainException('Amount precision exceeds the currency scale.');
        }

        $total = $effective['total'];
        if (bccomp($vat, $total, $scale + 1) >= 0) {
            throw new \DomainException('VAT amount must be less than the expense total.');
        }
    }

    private function amountExceedsCurrencyScale(string $amount, int $scale): bool
    {
        $decimalPosition = strpos($amount, '.');
        if ($decimalPosition === false) {
            return false;
        }

        $fraction = substr($amount, $decimalPosition + 1);
        $excess = substr($fraction, $scale);

        return trim($excess, '0') !== '';
    }

    /**
     * Post an expense and create GL entries.
     */
    public function post(Document $expense, User $user): Document
    {
        if ($expense->status !== DocumentStatus::Draft) {
            throw new \RuntimeException('Only draft expenses can be posted');
        }

        return DB::transaction(function () use ($expense, $user): Document {
            // Generate document number
            $expense->document_number = $this->generateExpenseNumber($expense->company_id);
            $expense->status = DocumentStatus::Posted;
            $expense->save();

            $metadata = $expense->expenseMetadata;
            if ($metadata?->expense_kind === ExpenseKind::LinkedCost) {
                $cost = DocumentAdditionalCost::query()
                    ->where('expense_document_id', $expense->id)
                    ->whereNull('reversed_at')
                    ->firstOrFail();
                $application = $this->linkedCostApplicator->apply($expense, $cost, $user);
                $expense->payload = array_merge($expense->payload ?? [], ['linked_cost_application' => $application]);
                $expense->save();

                // Post the capitalization entry SYNCHRONOUSLY, in-transaction, so it
                // returns the posted entry and — per the global lock order (spine
                // BLOCKER-1) — takes the GL company advisory lock BEFORE the movement
                // port takes the repository row lock below.
                $entry = $this->glService->createLinkedCostCapitalizationEntry(
                    $expense->loadMissing('expenseMetadata.paymentRepository'),
                    $cost,
                    $this->ledgerApplication($application, (string) $expense->currency),
                    $user,
                    PostingMode::SynchronousInTransaction,
                );

                // The capitalization entry above already CREDITS Cash in the GL, so
                // this movement records ONLY the treasury balance decrement + links to
                // that same capitalization JE — it must NOT re-post cash. This REPLACES
                // the legacy outflow: the write port is the single writer of the repo
                // balance + append-only movement row, atomically with the GL post. The
                // 'linked_cost' idempotency leg is distinct from the standard-expense
                // 'main' leg so the two post() branches can never collide. Amount and
                // currency are passed as strings so the port owns all bcmath/scale work.
                if ($metadata->is_paid === true && $metadata->payment_repository_id !== null && $expense->total !== null) {
                    $this->movementService->record(new MovementIntent(
                        repositoryId: $metadata->payment_repository_id,
                        tenantId: $expense->tenant_id,
                        companyId: $expense->company_id,
                        direction: MovementDirection::Out,
                        amount: $expense->total,
                        currency: (string) $expense->currency,
                        sourceType: MovementSourceType::Expense,
                        sourceId: $expense->id,
                        idempotencyLeg: 'linked_cost',
                        journalEntryId: $entry->id,
                        occurredAt: null,
                        reasonCode: null,
                        reversesMovementId: null,
                        createdBy: $user->id,
                        notes: null,
                        allowWhileFrozen: false,
                    ));
                }
            } else {
                // Post the expense GL entry SYNCHRONOUSLY, in-transaction, so it
                // returns the posted entry and — per the global lock order
                // (BLOCKER-1) — takes the GL company advisory lock BEFORE the
                // movement port takes the repository row lock below.
                $entry = $this->glService->createFromExpense($expense, $user, PostingMode::SynchronousInTransaction);

                $vatAmount = $expense->tax_amount !== null ? (string) $expense->tax_amount : null;
                $scale = $this->scaleResolver->getScale((string) $expense->currency);
                if ($vatAmount !== null && bccomp($vatAmount, '0', $scale) === 1) {
                    $deductiblePercent = (string) ($metadata->vat_deductible_percent ?? '100.00');
                    $deductibleVat = ExpenseVatSplit::deductible($vatAmount, $deductiblePercent, $scale);
                    $vatRate = $metadata?->vat_rate;
                    $taxName = $vatRate !== null ? "TVA {$vatRate}%" : 'TVA';
                    $taxBase = (string) ($expense->subtotal ?? '0');

                    DocumentTaxDetail::query()->firstOrCreate(
                        [
                            'document_id' => $expense->id,
                            'tax_type' => TaxType::Percentage->value,
                            'tax_name' => $taxName,
                            'tax_rate' => $vatRate,
                            'tax_base' => $taxBase,
                            'tax_amount' => $deductibleVat,
                        ],
                        [
                            'sequence_order' => 1,
                            'tax_code' => null,
                            'tax_fixed_amount' => null,
                            'is_stamp_duty' => false,
                        ],
                    );
                }

                // Move treasury cash ONLY for a PAID expense linked to a payment
                // repository. This REPLACES the old inline outflow: the write port
                // is the single writer of the repository balance + append-only
                // movement row, atomically with the GL post above. An UNPAID
                // expense moved no money — it booked an AP liability in the GL and
                // records no movement (Wave D bug fix). Amount/currency are passed
                // as strings so the port owns all bcmath/scale operations (Rule 19).
                if ($metadata?->is_paid === true && $metadata->payment_repository_id !== null && $expense->total !== null) {
                    $this->movementService->record(new MovementIntent(
                        repositoryId: $metadata->payment_repository_id,
                        tenantId: $expense->tenant_id,
                        companyId: $expense->company_id,
                        direction: MovementDirection::Out,
                        amount: $expense->total,
                        currency: (string) $expense->currency,
                        sourceType: MovementSourceType::Expense,
                        sourceId: $expense->id,
                        idempotencyLeg: 'main',
                        journalEntryId: $entry->id,
                        occurredAt: null,
                        reasonCode: null,
                        reversesMovementId: null,
                        createdBy: $user->id,
                        notes: null,
                        allowWhileFrozen: false,
                    ));
                }
            }

            $freshExpense = $expense->fresh(['expenseMetadata']);
            if ($freshExpense === null) {
                throw new \RuntimeException('Failed to refresh expense after posting');
            }

            return $freshExpense;
        });
    }

    /**
     * Settle a posted, unpaid expense: pay down the AP liability booked by
     * {@see post()} — POST /expenses/{id}/pay (Wave D, Task 15).
     *
     * Posts `Dr AP-liability (SupplierPayable) / Cr Cash` (via the dedicated
     * `createExpenseSettlementJournalEntry` helper, which mirrors Task 14's AP
     * booking with the SAME nullable partner, `SynchronousInTransaction`)
     * and writes an `out` movement through the treasury write port, keyed on
     * idempotency leg `settlement` — distinct from `post()`'s `main` leg, so
     * settlement is idempotent independently of the original post.
     *
     * Retry-safety: the movement port's idempotency guard does NOT protect
     * the GL post (a replayed intent returns the OLD movement without
     * checking `journal_entry_id` — see {@see TreasuryMovementServiceInterface}).
     * A caller that posted a fresh GL entry and then replayed the same
     * intent would orphan that entry. So this method checks for an existing
     * settlement movement BEFORE creating any GL entry and short-circuits as
     * a no-op retry when one is found.
     */
    public function settle(Document $expense, PayExpenseRequestData $data, User $user): Document
    {
        if ($expense->status !== DocumentStatus::Posted) {
            throw new \DomainException('Only a posted expense can be settled.');
        }

        return DB::transaction(function () use ($expense, $data, $user): Document {
            // Defense-in-depth tenant/company scoping (Fix 4). expense_metadata
            // has no tenant/company columns of its own, so pin the row through
            // its document to refuse a document_id smuggled from another tenant.
            $metadata = ExpenseMetadata::query()
                ->where('document_id', $expense->id)
                ->whereHas('document', function (Builder $query) use ($expense): void {
                    $query->whereRaw('tenant_id = ?', [$expense->tenant_id])
                        ->whereRaw('company_id = ?', [$expense->company_id]);
                })
                ->lockForUpdate()
                ->first();

            if ($metadata === null) {
                throw new \DomainException('This expense has no payment metadata to settle.');
            }

            // Only the AP-booking kind is settleable via /pay. post() branches on
            // ExpenseKind::LinkedCost → createLinkedCostCapitalizationEntry, which
            // CREDITS Cash (never SupplierPayable) regardless of is_paid; every
            // other kind (Generic/default) reaches createFromExpense and, when
            // unpaid, books Cr SupplierPayable. Reversing an AP that was never
            // credited would fabricate a phantom liability reversal and
            // double-decrement cash — so reject linked-cost settlement outright.
            // This condition matches post()'s own branch exactly.
            if ($metadata->expense_kind === ExpenseKind::LinkedCost) {
                throw new \DomainException('Only standard expenses booking an accounts-payable liability can be settled via /pay; linked-cost expenses are settled at capitalization.');
            }

            if ($metadata->payment_instrument_id !== null) {
                $linkedInstrument = PaymentInstrument::query()
                    ->where('tenant_id', $expense->tenant_id)
                    ->where('company_id', $expense->company_id)
                    ->whereKey($metadata->payment_instrument_id)
                    ->first();
                if (! $linkedInstrument instanceof PaymentInstrument) {
                    throw new \DomainException('The linked payment instrument could not be resolved for this company.');
                }
                if (! in_array($linkedInstrument->status, [
                    InstrumentStatus::Cancelled,
                    InstrumentStatus::Expired,
                ], true)) {
                    throw new \DomainException('This expense already has an outstanding or cleared payment instrument.');
                }
            }

            $settlementKey = MovementSourceType::Expense->value.':'.$expense->id.':settlement';
            $alreadySettled = RepositoryMovement::query()
                ->where('tenant_id', $expense->tenant_id)
                ->where('company_id', $expense->company_id)
                ->where('idempotency_key', $settlementKey)
                ->exists();

            if ($alreadySettled) {
                // Idempotent retry: the settlement was already recorded by a
                // prior call. Do NOT create a second GL entry — return the
                // current state as-is.
                $fresh = $expense->fresh(['expenseMetadata']);
                if ($fresh === null) {
                    throw new \RuntimeException('Failed to refresh expense after settlement retry.');
                }

                return $fresh;
            }

            if ($metadata->is_paid === true) {
                throw new \DomainException('This expense has already been paid.');
            }

            if ($data->isInstrument()) {
                return $this->settleByInstrument($expense, $metadata, $data, $user, $settlementKey);
            }

            // Business-rule failure (unknown/foreign repository) → 422, matching
            // the other settlement guards, rather than the 404 firstOrFail would
            // raise (Fix 3). The lookup is already tenant/company-scoped.
            $repository = PaymentRepository::query()
                ->where('tenant_id', $expense->tenant_id)
                ->where('company_id', $expense->company_id)
                ->whereKey($data->paymentRepositoryId)
                ->first();

            if ($repository === null) {
                throw new \DomainException('The selected payment repository was not found for this company.');
            }

            $creditAccount = match ($repository->type) {
                RepositoryType::BankAccount => Account::findByPurposeOrFail($expense->company_id, SystemAccountPurpose::Bank),
                default => Account::findByPurposeOrFail($expense->company_id, SystemAccountPurpose::Cash),
            };

            $vendorName = $metadata->vendor_name ?? 'General Expense';

            // Dr AP-liability (SupplierPayable) / Cr Cash-or-Bank, posted
            // SYNCHRONOUSLY in-transaction so it takes the GL company advisory
            // lock BEFORE the movement port's repository row lock below — the
            // same global lock order as post() (spine BLOCKER-1).
            //
            // Use the dedicated expense-settlement helper (NOT
            // createSupplierPaymentJournalEntry, whose $partnerId is non-nullable):
            // documents.partner_id is nullable, so a petty-cash / anonymous-vendor
            // expense booked its AP line with a NULL partner in post(). The
            // settlement must mirror that AP booking with the SAME (nullable)
            // partner — passing null into the non-null helper would TypeError → 500
            // (Fix 1).
            $entry = $this->glService->createExpenseSettlementJournalEntry(
                companyId: $expense->company_id,
                partnerId: $expense->partner_id,
                expenseId: $expense->id,
                amount: (string) ($expense->total ?? '0'),
                paymentMethodAccountId: $creditAccount->id,
                date: Carbon::parse($data->paymentDate),
                user: $user,
                description: "Expense settlement: {$expense->document_number} - {$vendorName}",
                currencyCode: (string) $expense->currency,
                mode: PostingMode::SynchronousInTransaction,
            );

            $this->movementService->record(new MovementIntent(
                repositoryId: $repository->id,
                tenantId: $expense->tenant_id,
                companyId: $expense->company_id,
                direction: MovementDirection::Out,
                amount: (string) ($expense->total ?? '0'),
                currency: (string) $expense->currency,
                sourceType: MovementSourceType::Expense,
                sourceId: $expense->id,
                idempotencyLeg: 'settlement',
                journalEntryId: $entry->id,
                occurredAt: null,
                reasonCode: null,
                reversesMovementId: null,
                createdBy: $user->id,
                notes: null,
                allowWhileFrozen: false,
            ));

            $metadata->update([
                'is_paid' => true,
                'paid_at' => now(),
                'payment_repository_id' => $repository->id,
                'payment_method_id' => $data->paymentMethodId ?? $metadata->payment_method_id,
                'payment_date' => $data->paymentDate,
            ]);

            $fresh = $expense->fresh(['expenseMetadata']);
            if ($fresh === null) {
                throw new \RuntimeException('Failed to refresh expense after settlement.');
            }

            return $fresh;
        });
    }

    private function settleByInstrument(
        Document $expense,
        ExpenseMetadata $metadata,
        PayExpenseRequestData $data,
        User $user,
        string $settlementKey,
    ): Document {
        if ($data->paymentMethodId === null
            || $data->instrumentKind === null
            || $data->instrumentReference === null) {
            throw new \DomainException('Instrument settlement details are incomplete.');
        }
        if (! in_array($data->instrumentKind, [InstrumentKind::Cheque, InstrumentKind::Effet], true)) {
            throw new \DomainException('Expense settlement supports cheque or effet instruments only.');
        }
        if ($data->instrumentKind === InstrumentKind::Effet && $data->instrumentMaturityDate === null) {
            throw new \DomainException('An effet settlement requires a maturity date.');
        }

        $method = PaymentMethod::query()
            ->where('tenant_id', $expense->tenant_id)
            ->where('company_id', $expense->company_id)
            ->whereKey($data->paymentMethodId)
            ->first();
        if (! $method instanceof PaymentMethod || ! $method->is_active) {
            throw new \DomainException('The selected payment method is not active for this company.');
        }
        if ($method->instrument_kind !== $data->instrumentKind) {
            throw new \DomainException('The selected payment method does not match the instrument kind.');
        }

        $repository = $this->outboundRepositoryValidator->validate(
            repositoryId: $data->paymentRepositoryId,
            tenantId: $expense->tenant_id,
            companyId: $expense->company_id,
            currency: (string) $expense->currency,
            instrumentBankId: $data->instrumentBankId,
        );
        $amount = CurrencyScale::bcformatStrict(
            (string) ($expense->total ?? '0'),
            $this->scaleResolver->getScale((string) $expense->currency),
        );
        // The base settlement key remains the expense-level financial anchor.
        // Each issued paper receives its own deterministic cycle key so a
        // cancelled cheque/effet can be replaced without colliding with the
        // retained portfolio row. The metadata row lock in settle() serializes
        // this count and the subsequent link update for the expense.
        $issueCycle = PaymentInstrument::query()
            ->where('tenant_id', $expense->tenant_id)
            ->where('company_id', $expense->company_id)
            ->where('idempotency_key', 'like', $settlementKey.':instrument:%')
            ->count() + 1;
        $instrumentIssueKey = "{$settlementKey}:instrument:{$issueCycle}";
        $instrument = $this->instrumentLifecycleService->receive(new ReceiveInstrumentData(
            tenantId: $expense->tenant_id,
            companyId: $expense->company_id,
            paymentMethodId: $method->id,
            kind: $data->instrumentKind,
            direction: InstrumentDirection::Outbound,
            origin: InstrumentOrigin::Web,
            reference: $data->instrumentReference,
            amount: $amount,
            currency: (string) $expense->currency,
            repositoryId: $repository->id,
            partnerId: $expense->partner_id,
            drawerName: $data->instrumentDrawerName,
            maturityDate: $data->instrumentMaturityDate,
            receivedDate: $data->paymentDate,
            bankId: $data->instrumentBankId,
            idempotencyKey: $instrumentIssueKey,
            createdBy: $user->id,
        ));

        $issueDate = Carbon::parse($data->paymentDate);
        $actionKey = "instrument:{$instrument->id}:issue";
        $canonical = json_encode([
            'action' => 'issue',
            'instrumentId' => $instrument->id,
            'amount' => $instrument->amount,
            'currency' => $instrument->currency,
            'repositoryId' => $instrument->repository_id,
            'occurredAt' => $issueDate->format('Y-m-d'),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $digest = hash('sha256', $canonical);

        $purpose = $data->instrumentKind === InstrumentKind::Cheque
            ? InstrumentAccountPurpose::ChecksToPay
            : InstrumentAccountPurpose::EffetsPayable;
        $entry = $this->glService->createOutboundInstrumentIssueEntry(
            companyId: $expense->company_id,
            tenantId: $expense->tenant_id,
            instrumentId: $instrument->id,
            partnerId: $expense->partner_id,
            payableAccountId: $this->instrumentAccountResolver->resolveOrFail($purpose, $expense->company_id),
            amount: $amount,
            date: $issueDate,
        );
        $this->glService->postEntryNow($entry, $user, (string) $expense->currency);
        InstrumentEvent::query()->create([
            'tenant_id' => $expense->tenant_id,
            'company_id' => $expense->company_id,
            'instrument_id' => $instrument->id,
            'event_type' => InstrumentEventType::Issued,
            'action_key' => $actionKey,
            'semantic_digest' => $digest,
            'from_status' => null,
            'to_status' => InstrumentStatus::Received->value,
            'to_repository_id' => $repository->id,
            'journal_entry_id' => $entry->id,
            'movement_id' => null,
            'payload' => (new InstrumentEventPayload)->toArray(),
            'occurred_at' => $issueDate,
            'created_by' => $user->id,
        ]);

        $metadata->update([
            'payment_instrument_id' => $instrument->id,
            'is_paid' => false,
            'paid_at' => null,
        ]);

        $fresh = $expense->fresh(['expenseMetadata']);
        if ($fresh === null) {
            throw new \RuntimeException('Failed to refresh expense after instrument settlement.');
        }

        return $fresh;
    }

    /**
     * @return array{reversal_expense_id: string, gl_entry_id: string, wac_contras: list<array<string, mixed>>, cash_reversed: bool}
     */
    public function reverse(Document $expense, User $user): array
    {
        if ($expense->status !== DocumentStatus::Posted) {
            throw new LinkedCostException('NOT_POSTED', 'Only posted linked-cost expenses can be reversed.');
        }

        $expense->loadMissing('expenseMetadata.paymentRepository');
        if ($expense->expenseMetadata?->expense_kind !== ExpenseKind::LinkedCost) {
            throw new LinkedCostException('NOT_LINKED_COST', 'Only linked-cost expenses can be reversed.');
        }

        return DB::transaction(function () use ($expense, $user): array {
            $metadata = $expense->expenseMetadata;
            $originalCost = DocumentAdditionalCost::query()
                ->where('expense_document_id', $expense->id)
                ->whereNull('reverses_cost_id')
                ->lockForUpdate()
                ->firstOrFail();

            if ($originalCost->reversed_at !== null) {
                throw new LinkedCostException('ALREADY_REVERSED', 'This linked cost has already been reversed.');
            }

            $reversalExpense = Document::create([
                'tenant_id' => $expense->tenant_id,
                'company_id' => $expense->company_id,
                'partner_id' => $expense->partner_id,
                'type' => DocumentType::Expense,
                'status' => DocumentStatus::Posted,
                'document_number' => $this->generateExpenseNumber($expense->company_id),
                'document_date' => now()->toDateString(),
                'currency' => $expense->currency,
                'total' => $expense->total,
                'subtotal' => $expense->subtotal,
                'notes' => "Reversal of {$expense->document_number}",
            ]);

            ExpenseMetadata::create([
                'document_id' => $reversalExpense->id,
                'expense_category_id' => $metadata->expense_category_id,
                'payment_method_id' => $metadata->payment_method_id,
                'payment_repository_id' => $metadata->payment_repository_id,
                'payment_date' => now()->toDateString(),
                'is_paid' => $metadata->is_paid,
                'vendor_name' => $metadata->vendor_name,
                'expense_kind' => ExpenseKind::LinkedCost,
            ]);

            $scale = $this->scaleResolver->getScale((string) $expense->currency);
            $reversalCost = DocumentAdditionalCost::create([
                'id' => Str::uuid()->toString(),
                'document_id' => $originalCost->document_id,
                'cost_type' => $originalCost->cost_type,
                'description' => "Reversal of {$originalCost->description}",
                'amount' => bcmul($originalCost->amount, '-1', $scale),
                'expense_document_id' => $reversalExpense->id,
                'application_path' => $originalCost->application_path,
                'split_method' => $originalCost->split_method,
                'reverses_cost_id' => $originalCost->id,
                'applied_at' => now(),
            ]);

            $application = $this->linkedCostApplicator->reverse($expense, $originalCost, $reversalCost, $user);

            // Post the reversal entry SYNCHRONOUSLY, in-transaction, so it returns the
            // posted entry and takes the GL company advisory lock BEFORE the movement
            // port takes the repository row lock below (spine BLOCKER-1 lock order).
            // This reversal DEBITS the payment (cash) account — money returns to the
            // books — so the inflow movement below links to a real reversing JE.
            $entry = $this->glService->createLinkedCostCapitalizationReversalEntry(
                $expense->loadMissing('expenseMetadata.paymentRepository'),
                $reversalCost,
                $this->ledgerApplication($application, (string) $expense->currency),
                $user,
                PostingMode::SynchronousInTransaction,
            );

            $originalCost->reversed_at = now();
            $originalCost->save();

            $cashReversed = false;
            if ($metadata->is_paid === true && $metadata->payment_repository_id !== null && $expense->total !== null) {
                // REPLACES the legacy inflow: the write port is the single writer of
                // the repo balance + append-only movement row, atomically with the
                // reversal GL post above. Keyed on the ORIGINAL expense id + the
                // 'reversal' idempotency leg (distinct from post()'s 'linked_cost'),
                // so a given expense's cash reversal is recorded exactly once. Amount
                // and currency pass as strings so the port owns all bcmath/scale work.
                $this->movementService->record(new MovementIntent(
                    repositoryId: $metadata->payment_repository_id,
                    tenantId: $expense->tenant_id,
                    companyId: $expense->company_id,
                    direction: MovementDirection::In,
                    amount: $expense->total,
                    currency: (string) $expense->currency,
                    sourceType: MovementSourceType::Expense,
                    sourceId: $expense->id,
                    idempotencyLeg: 'reversal',
                    journalEntryId: $entry->id,
                    occurredAt: null,
                    reasonCode: null,
                    reversesMovementId: null,
                    createdBy: $user->id,
                    notes: null,
                    allowWhileFrozen: false,
                ));
                $cashReversed = true;
            }

            $reversalExpense->payload = array_merge($reversalExpense->payload ?? [], ['linked_cost_reversal' => $application]);
            $reversalExpense->save();

            return [
                'reversal_expense_id' => $reversalExpense->id,
                'gl_entry_id' => $entry->id,
                'wac_contras' => $application['adjustments'] ?? [],
                'cash_reversed' => $cashReversed,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{operation: Document}|null
     */
    private function prepareLinkedCost(array $data, string $tenantId, string $companyId, string $expenseCurrency): ?array
    {
        if (($data['expense_kind'] ?? ExpenseKind::Generic->value) !== ExpenseKind::LinkedCost->value) {
            return null;
        }

        $invoice = Document::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->whereKey($data['linked_invoice_id'] ?? null)
            ->firstOrFail();

        $resolution = $this->operationResolver->resolve($invoice);
        $operationId = $data['linked_operation_id'] ?? $resolution['auto_selected_id'];
        if ($operationId === null) {
            throw new LinkedCostException('LINKED_OPERATION_REQUIRED', 'Choose the operation for this linked cost.');
        }

        $operationIds = array_column($resolution['operations'], 'document_id');
        if (! in_array($operationId, $operationIds, true)) {
            throw new LinkedCostException('LINKED_OPERATION_INVALID', 'The operation is not linked to the selected invoice.');
        }

        /** @var Document $operation */
        $operation = Document::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->whereKey($operationId)
            ->with('lines')
            ->firstOrFail();

        if ((string) $operation->currency !== $expenseCurrency) {
            throw new LinkedCostException('LINKED_COST_CURRENCY_MISMATCH', 'Linked cost and operation currencies must match.');
        }

        $this->assertPhaseOneReceived($operation);

        return ['operation' => $operation];
    }

    private function assertPhaseOneReceived(Document $operation): void
    {
        $scale = $this->scaleResolver->getScale((string) $operation->currency);
        $lines = $operation->lines
            ->filter(fn ($line): bool => $line->product_id !== null && bccomp((string) $line->line_total, '0', $scale) > 0);

        if ($lines->isEmpty()) {
            throw new LinkedCostException('OPERATION_NOT_RECEIVED', 'The linked purchase operation has no product lines.');
        }

        $received = $lines->filter(fn ($line): bool => $line->accrual_unit_cost !== null)->count();
        if ($received === 0) {
            throw new LinkedCostException('OPERATION_NOT_RECEIVED', 'The linked purchase operation has not been received.');
        }
        if ($received !== $lines->count()) {
            throw new LinkedCostException('OPERATION_PARTIALLY_RECEIVED', 'Partially received purchase operations are not supported in Phase 1.');
        }
    }

    /**
     * Generate expense document number.
     */
    private function generateExpenseNumber(string $companyId): string
    {
        $year = date('Y');
        $lastExpense = Document::query()
            ->where('company_id', $companyId)
            ->where('type', DocumentType::Expense)
            ->where('document_number', 'like', "EXP-{$year}-%")
            ->orderByDesc('document_number')
            ->first();

        if ($lastExpense !== null) {
            $lastNumber = (int) substr($lastExpense->document_number, -6);
            $nextNumber = $lastNumber + 1;
        } else {
            $nextNumber = 1;
        }

        return sprintf('EXP-%s-%06d', $year, $nextNumber);
    }

    private function costType(mixed $value): AdditionalCostType
    {
        if ($value instanceof AdditionalCostType) {
            return $value;
        }

        if (is_string($value)) {
            return AdditionalCostType::tryFrom($value) ?? AdditionalCostType::Other;
        }

        return AdditionalCostType::Other;
    }

    private function splitMethod(mixed $value): LandedCostSplitMethod
    {
        if ($value instanceof LandedCostSplitMethod) {
            return $value;
        }

        if (is_string($value)) {
            return LandedCostSplitMethod::tryFrom($value) ?? LandedCostSplitMethod::ByValue;
        }

        return LandedCostSplitMethod::ByValue;
    }

    /**
     * @param  array<string, mixed>  $application
     * @return array{inventory_total: numeric-string, cogs_total: numeric-string}
     */
    private function ledgerApplication(array $application, string $currency): array
    {
        $scale = $this->scaleResolver->getScale($currency);

        return [
            'inventory_total' => CurrencyScale::bcformatStrict((string) ($application['inventory_total'] ?? '0'), $scale),
            'cogs_total' => CurrencyScale::bcformatStrict((string) ($application['cogs_total'] ?? '0'), $scale),
        ];
    }
}
