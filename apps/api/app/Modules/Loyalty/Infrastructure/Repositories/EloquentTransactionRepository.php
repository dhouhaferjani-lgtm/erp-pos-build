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
     * Find transaction by source document (for idempotency checking)
     */
    public function findBySourceDocument(string $sourceType, string $sourceId): ?Transaction
    {
        return Transaction::whereJsonContains('metadata->source_type', $sourceType)
            ->whereJsonContains('metadata->source_id', $sourceId)
            ->first();
    }

    /**
     * Save transaction
     */
    public function save(Transaction $transaction): Transaction
    {
        $transaction->save();

        return $transaction->fresh();
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
