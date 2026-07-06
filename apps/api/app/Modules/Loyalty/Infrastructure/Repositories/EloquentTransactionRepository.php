<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Infrastructure\Repositories;

use App\Modules\Loyalty\Domain\Entities\Transaction;
use App\Modules\Loyalty\Domain\Enums\TransactionType;
use App\Modules\Loyalty\Domain\Repositories\TransactionRepositoryInterface;
use Illuminate\Support\Collection;

/**
 * Eloquent implementation of Transaction repository
 */
final readonly class EloquentTransactionRepository implements TransactionRepositoryInterface
{
    /**
     * Find transaction by ID
     */
    public function findById(string $id): ?Transaction
    {
        return Transaction::find($id);
    }

    /**
     * Get all transactions for an enrollment
     *
     * @return Collection<int, Transaction>
     */
    public function findByEnrollment(string $enrollmentId): Collection
    {
        return Transaction::where('enrollment_id', $enrollmentId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get transactions by type for an enrollment
     *
     * @return Collection<int, Transaction>
     */
    public function findByEnrollmentAndType(string $enrollmentId, TransactionType $type): Collection
    {
        return Transaction::where('enrollment_id', $enrollmentId)
            ->where('transaction_type', $type)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get transactions for an order
     *
     * @return Collection<int, Transaction>
     */
    public function findByOrder(string $orderId): Collection
    {
        return Transaction::where('order_id', $orderId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Find transaction by source document (for idempotency checking).
     *
     * Reads the dedicated source_type/source_id columns (backfilled from
     * metadata) so the pre-check aligns with the loyalty_txn_earn_source_unique
     * index. Kept GLOBAL (not per-enrollment) — the multi-enrollment idempotency
     * change is explicitly out of scope.
     */
    public function findBySourceDocument(string $sourceType, string $sourceId): ?Transaction
    {
        return Transaction::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->first();
    }

    /**
     * Save transaction
     */
    public function save(Transaction $transaction): Transaction
    {
        $transaction->save();

        /** @var Transaction $freshTransaction */
        $freshTransaction = $transaction->fresh();

        return $freshTransaction;
    }

    /**
     * Delete transaction
     */
    public function delete(string $id): bool
    {
        $transaction = $this->findById($id);

        if ($transaction === null) {
            return false;
        }

        return (bool) $transaction->delete();
    }
}
