<?php

declare(strict_types=1);

namespace App\Modules\Income\Application\Services;

use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\User;
use App\Modules\Income\Domain\IncomeMetadata;
use App\Shared\Contracts\Treasury\RepositoryInflowInterface;
use Illuminate\Support\Facades\DB;

/**
 * Service for managing income records — the mirror of ExpenseService.
 *
 * Posting an income posts a GL entry (Dr cash/bank, Cr class-7 revenue) and
 * INCREASES the receiving repository balance via the RepositoryInflow port.
 */
final class IncomeService
{
    public function __construct(
        private readonly GeneralLedgerService $glService,
        private readonly RepositoryInflowInterface $inflow,
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * Create a new income record.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $user): Document
    {
        $idempotencyKey = $data['idempotency_key'] ?? null;
        if ($idempotencyKey !== null) {
            $existing = IncomeMetadata::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                /** @var Document $doc */
                $doc = Document::query()->whereKey($existing->document_id)->firstOrFail();

                return $doc->load('incomeMetadata');
            }
        }

        $companyCurrency = $this->companyContext->requireCompany()->currency;

        return DB::transaction(function () use ($data, $user, $idempotencyKey, $companyCurrency): Document {
            $income = Document::create([
                'tenant_id' => $user->tenant_id,
                'company_id' => $data['company_id'],
                'type' => DocumentType::Income,
                'status' => DocumentStatus::Draft,
                'currency' => $companyCurrency,
                'document_date' => $data['payment_date'] ?? $data['document_date'] ?? now()->toDateString(),
                'total' => $data['total'] ?? '0.00',
                'subtotal' => $data['total'] ?? '0.00',
                'notes' => $data['notes'] ?? null,
            ]);

            IncomeMetadata::create([
                'document_id' => $income->id,
                'income_account_id' => $data['income_account_id'] ?? null,
                'payment_method_id' => $data['payment_method_id'] ?? null,
                'payment_repository_id' => $data['payment_repository_id'] ?? null,
                'payment_date' => $data['payment_date'] ?? null,
                'is_received' => $data['is_received'] ?? true,
                'reference_number' => $data['reference_number'] ?? null,
                'source_name' => $data['source_name'] ?? null,
                'idempotency_key' => $idempotencyKey,
            ]);

            return $income->load('incomeMetadata');
        });
    }

    /**
     * Update an existing draft income record.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Document $income, array $data): Document
    {
        if ($income->status !== DocumentStatus::Draft) {
            throw new \RuntimeException('Only draft income records can be updated');
        }

        return DB::transaction(function () use ($income, $data): Document {
            $income->update([
                'document_date' => $data['payment_date'] ?? $data['document_date'] ?? $income->document_date,
                'total' => $data['total'] ?? $income->total,
                'subtotal' => $data['total'] ?? $income->subtotal,
                'notes' => $data['notes'] ?? $income->notes,
            ]);

            $income->incomeMetadata?->update([
                'income_account_id' => $data['income_account_id'] ?? $income->incomeMetadata->income_account_id,
                'payment_method_id' => $data['payment_method_id'] ?? $income->incomeMetadata->payment_method_id,
                'payment_repository_id' => $data['payment_repository_id'] ?? $income->incomeMetadata->payment_repository_id,
                'payment_date' => $data['payment_date'] ?? $income->incomeMetadata->payment_date,
                'is_received' => $data['is_received'] ?? $income->incomeMetadata->is_received,
                'reference_number' => $data['reference_number'] ?? $income->incomeMetadata->reference_number,
                'source_name' => $data['source_name'] ?? $income->incomeMetadata->source_name,
            ]);

            $fresh = $income->fresh(['incomeMetadata']);
            if ($fresh === null) {
                throw new \RuntimeException('Failed to refresh income after update');
            }

            return $fresh;
        });
    }

    /**
     * Post an income and create GL entries + repository inflow.
     */
    public function post(Document $income, User $user): Document
    {
        if ($income->status !== DocumentStatus::Draft) {
            throw new \RuntimeException('Only draft income records can be posted');
        }

        return DB::transaction(function () use ($income, $user): Document {
            $income->document_number = $this->generateIncomeNumber($income->company_id);
            $income->status = DocumentStatus::Posted;
            $income->save();

            $metadata = $income->incomeMetadata;

            // Post the GL entry (Dr cash/bank, Cr class-7 revenue).
            $this->glService->createFromIncome($income->loadMissing('incomeMetadata.paymentRepository'), $user);

            // Increment treasury cash balance when the income is received and
            // linked to a payment repository. Amount and currency are passed as
            // strings so the port owns all bcmath/scale operations (Rule 19).
            if ($metadata?->is_received === true && $metadata->payment_repository_id !== null && $income->total !== null) {
                $this->inflow->applyInflow(
                    $metadata->payment_repository_id,
                    $income->tenant_id,
                    $income->company_id,
                    $income->total,
                    (string) $income->currency,
                );
            }

            $fresh = $income->fresh(['incomeMetadata']);
            if ($fresh === null) {
                throw new \RuntimeException('Failed to refresh income after posting');
            }

            return $fresh;
        });
    }

    /**
     * Generate income document number.
     */
    private function generateIncomeNumber(string $companyId): string
    {
        $year = date('Y');
        $lastIncome = Document::query()
            ->where('company_id', $companyId)
            ->where('type', DocumentType::Income)
            ->where('document_number', 'like', "INC-{$year}-%")
            ->orderByDesc('document_number')
            ->first();

        if ($lastIncome !== null) {
            $lastNumber = (int) substr($lastIncome->document_number, -6);
            $nextNumber = $lastNumber + 1;
        } else {
            $nextNumber = 1;
        }

        return sprintf('INC-%s-%06d', $year, $nextNumber);
    }
}
