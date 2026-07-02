<?php

declare(strict_types=1);

namespace App\Modules\Expense\Application\Services;

use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentAdditionalCost;
use App\Modules\Document\Domain\Enums\AdditionalCostType;
use App\Modules\Document\Domain\Enums\CostApplicationPath;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\LandedCostSplitMethod;
use App\Modules\Expense\Application\Exceptions\LinkedCostException;
use App\Modules\Expense\Domain\Enums\ExpenseKind;
use App\Modules\Expense\Domain\ExpenseMetadata;
use App\Modules\Identity\Domain\User;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Contracts\Document\OperationResolverInterface;
use App\Shared\Contracts\Inventory\LinkedCostApplicatorInterface;
use App\Shared\Contracts\Treasury\RepositoryInflowInterface;
use App\Shared\Contracts\Treasury\RepositoryOutflowInterface;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Service for managing expenses.
 */
final class ExpenseService
{
    public function __construct(
        private readonly GeneralLedgerService $glService,
        private readonly RepositoryOutflowInterface $outflow,
        private readonly RepositoryInflowInterface $inflow,
        private readonly CompanyContext $companyContext,
        private readonly OperationResolverInterface $operationResolver,
        private readonly LinkedCostApplicatorInterface $linkedCostApplicator,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
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

        $companyCurrency = $this->companyContext->requireCompany()->currency;

        $linked = $this->prepareLinkedCost($data, $user->tenant_id, $data['company_id'], $companyCurrency);

        return DB::transaction(function () use ($data, $user, $idempotencyKey, $companyCurrency, $linked): Document {
            // Create the expense document
            $expense = Document::create([
                'tenant_id' => $user->tenant_id,
                'company_id' => $data['company_id'],
                'type' => DocumentType::Expense,
                'status' => DocumentStatus::Draft,
                'currency' => $companyCurrency,
                'document_date' => $data['payment_date'] ?? now()->toDateString(),
                'total' => $data['total'] ?? '0.00',
                'subtotal' => $data['total'] ?? '0.00',
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

        return DB::transaction(function () use ($expense, $data): Document {
            // Update document
            $expense->update([
                'document_date' => $data['payment_date'] ?? $expense->document_date,
                'total' => $data['total'] ?? $expense->total,
                'subtotal' => $data['total'] ?? $expense->subtotal,
                'notes' => $data['notes'] ?? $expense->notes,
            ]);

            // Update metadata
            $expense->expenseMetadata?->update([
                'expense_category_id' => $data['expense_category_id'] ?? $expense->expenseMetadata->expense_category_id,
                'payment_method_id' => $data['payment_method_id'] ?? $expense->expenseMetadata->payment_method_id,
                'payment_repository_id' => $data['payment_repository_id'] ?? $expense->expenseMetadata->payment_repository_id,
                'payment_date' => $data['payment_date'] ?? $expense->expenseMetadata->payment_date,
                'is_paid' => $data['is_paid'] ?? $expense->expenseMetadata->is_paid,
                'receipt_number' => $data['receipt_number'] ?? $expense->expenseMetadata->receipt_number,
                'vendor_name' => $data['vendor_name'] ?? $expense->expenseMetadata->vendor_name,
            ]);

            $freshExpense = $expense->fresh(['expenseMetadata']);
            if ($freshExpense === null) {
                throw new \RuntimeException('Failed to refresh expense after update');
            }

            return $freshExpense;
        });
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
                $this->glService->createLinkedCostCapitalizationEntry(
                    $expense->loadMissing('expenseMetadata.paymentRepository'),
                    $cost,
                    $this->ledgerApplication($application, (string) $expense->currency),
                    $user,
                );
            } else {
                // Create GL entry
                $this->glService->createFromExpense($expense, $user);
            }

            // Decrement treasury cash balance when the expense is paid and linked
            // to a payment repository. Amount and currency are passed as strings
            // so the port handles all bcmath/scale operations (Rule 19).
            if ($metadata?->is_paid === true && $metadata->payment_repository_id !== null && $expense->total !== null) {
                $this->outflow->applyOutflow(
                    $metadata->payment_repository_id,
                    $expense->tenant_id,
                    $expense->company_id,
                    $expense->total,
                    (string) $expense->currency,
                );
            }

            $freshExpense = $expense->fresh(['expenseMetadata']);
            if ($freshExpense === null) {
                throw new \RuntimeException('Failed to refresh expense after posting');
            }

            return $freshExpense;
        });
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
            $entry = $this->glService->createLinkedCostCapitalizationReversalEntry(
                $expense->loadMissing('expenseMetadata.paymentRepository'),
                $reversalCost,
                $this->ledgerApplication($application, (string) $expense->currency),
                $user,
            );

            $originalCost->reversed_at = now();
            $originalCost->save();

            $cashReversed = false;
            if ($metadata->is_paid === true && $metadata->payment_repository_id !== null && $expense->total !== null) {
                $this->inflow->applyInflow(
                    $metadata->payment_repository_id,
                    $expense->tenant_id,
                    $expense->company_id,
                    $expense->total,
                    (string) $expense->currency,
                );
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
