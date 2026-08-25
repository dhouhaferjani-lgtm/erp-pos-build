<?php

declare(strict_types=1);

namespace App\Modules\Income\Application\Services;

use App\Modules\Accounting\Domain\Enums\PostingMode;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Services\DocumentStatusService;
use App\Modules\Identity\Domain\User;
use App\Modules\Income\Domain\IncomeMetadata;
use App\Modules\Treasury\Application\DTOs\MovementIntent;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Shared\Contracts\Treasury\TreasuryMovementServiceInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Service for managing income records — the mirror of ExpenseService.
 *
 * Posting an income posts a GL entry (Dr cash/bank, Cr class-7 revenue),
 * synchronously and in-transaction, and INCREASES the receiving repository
 * balance via the treasury write port (Wave D, Task 17) — atomically with
 * the GL post, honouring the global lock order (GL advisory lock BEFORE the
 * port's repository row lock, spine BLOCKER-1).
 */
final class IncomeService
{
    public function __construct(
        private readonly GeneralLedgerService $glService,
        private readonly TreasuryMovementServiceInterface $movementService,
        private readonly DocumentStatusService $documentStatus,
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

        $company = Company::query()
            ->where('tenant_id', $user->tenant_id)
            ->whereKey($data['company_id'])
            ->firstOrFail();
        $locationId = $data['location_id'] ?? null;
        if ($locationId !== null && (! is_string($locationId) || ! Location::query()
            ->where('company_id', $company->id)
            ->whereKey($locationId)
            ->exists())) {
            throw new \DomainException('The income location does not belong to the active company.');
        }
        $total = $data['total'] ?? '0.00';
        if (! is_string($total) || ! is_numeric($total)) {
            throw new \InvalidArgumentException('Income amount must be a numeric string.');
        }
        $companyCurrency = $company->currency;

        return DB::transaction(function () use ($data, $user, $idempotencyKey, $companyCurrency, $total): Document {
            $income = Document::create([
                'tenant_id' => $user->tenant_id,
                'company_id' => $data['company_id'],
                'location_id' => $data['location_id'] ?? null,
                'type' => DocumentType::Income,
                'status' => DocumentStatus::Draft,
                'currency' => $companyCurrency,
                'document_date' => $data['payment_date'] ?? $data['document_date'] ?? now()->toDateString(),
                'total' => $total,
                'subtotal' => $total,
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
            // N-6 fix round r1 / fiscal gate F-6 — single write path.
            $this->documentStatus->transition($income, DocumentStatus::Posted, [
                'document_number' => $this->generateIncomeNumber($income->tenant_id),
            ]);

            $metadata = $income->incomeMetadata;

            // Post the GL entry (Dr cash/bank, Cr class-7 revenue) SYNCHRONOUSLY,
            // in-transaction, so it returns the posted entry and — per the global
            // lock order (BLOCKER-1) — takes the GL company advisory lock BEFORE
            // the movement port takes the repository row lock below.
            $entry = $this->glService->createFromIncome($income->loadMissing('incomeMetadata.paymentRepository'), $user, PostingMode::SynchronousInTransaction);

            // Move treasury cash ONLY when the income is received and linked to a
            // payment repository. This REPLACES the old inline inflow: the write
            // port is the single writer of the repository balance + append-only
            // movement row, atomically with the GL post above. Amount/currency
            // are passed as strings so the port owns all bcmath/scale operations
            // (Rule 19).
            if ($metadata?->is_received === true && $metadata->payment_repository_id !== null && $income->total !== null) {
                $this->movementService->record(new MovementIntent(
                    repositoryId: $metadata->payment_repository_id,
                    tenantId: $income->tenant_id,
                    companyId: $income->company_id,
                    direction: MovementDirection::In,
                    amount: $income->total,
                    currency: (string) $income->currency,
                    sourceType: MovementSourceType::Income,
                    sourceId: $income->id,
                    idempotencyLeg: 'main',
                    journalEntryId: $entry->id,
                    occurredAt: CarbonImmutable::parse($metadata->payment_date ?? $income->document_date),
                    reasonCode: null,
                    reversesMovementId: null,
                    createdBy: $user->id,
                    notes: null,
                    allowWhileFrozen: false,
                ));
            }

            $fresh = $income->fresh(['incomeMetadata']);
            if ($fresh === null) {
                throw new \RuntimeException('Failed to refresh income after posting');
            }

            return $fresh;
        });
    }

    /**
     * Allocate the next income document number for the tenant.
     *
     * LEDGER C-27 (Session B2, 2026-08-25) — the Income twin of the
     * journal-entry defect, fixed in the same shape Q-11 applied to
     * `ExpenseService::generateExpenseNumber()`:
     *
     * 1. SCOPE. The scan is TENANT-scoped, not company-scoped, because the only
     *    unique index on the column is
     *    `documents_tenant_id_type_document_number_unique` on
     *    `(tenant_id, type, document_number)`. A company-scoped max+1 is NARROWER
     *    than the constraint it must satisfy: in a tenant with two companies, the
     *    second company's first income post minted `INC-YYYY-000001`, which the
     *    first company already held — an unconditional unique violation that rolled
     *    back the whole post transaction (GL entry + treasury movement).
     *    Consequence of the widening: sequential income numbers now interleave
     *    across the companies of a tenant. The index is deliberately untouched.
     *
     * 2. RACE. There was NO lock at all. The max+1 read is now serialised by a
     *    transaction-scoped advisory lock keyed on the same (tenant) scope as the
     *    scan. The single call site (`post()`) runs inside `DB::transaction`, so
     *    the lock is held until that transaction commits.
     */
    private function generateIncomeNumber(string $tenantId): string
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
                ["income_number:{$tenantId}"],
            );
        }

        $year = date('Y');
        $lastNumber = Document::query()
            ->where('tenant_id', $tenantId)
            ->where('type', DocumentType::Income)
            ->where('document_number', 'like', "INC-{$year}-%")
            ->orderByDesc('document_number')
            ->value('document_number');

        $nextNumber = is_string($lastNumber) ? ((int) substr($lastNumber, -6)) + 1 : 1;

        return sprintf('INC-%s-%06d', $year, $nextNumber);
    }
}
