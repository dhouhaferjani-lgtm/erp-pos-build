<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Repositories;

use App\Modules\Loyalty\Domain\Entities\Transaction;
use App\Modules\Loyalty\Domain\Enums\TransactionType;
use Illuminate\Support\Collection;

/**
 * Repository interface for Transaction
 */
interface TransactionRepositoryInterface
{
    /**
     * Find transaction by ID
     */
    public function findById(string $id): ?Transaction;

    /**
     * Get all transactions for an enrollment
     *
     * @return Collection<int, Transaction>
     */
    public function findByEnrollment(string $enrollmentId): Collection;

    /**
     * Get transactions by type for an enrollment
     *
     * @return Collection<int, Transaction>
     */
    public function findByEnrollmentAndType(string $enrollmentId, TransactionType $type): Collection;

    /**
     * Get transactions for an order
     *
     * @return Collection<int, Transaction>
     */
    public function findByOrder(string $orderId): Collection;

    /**
     * Find transaction by source document (for idempotency checking)
     */
    public function findBySourceDocument(string $sourceType, string $sourceId): ?Transaction;

    /**
     * Find the redeem transaction carrying an idempotency key.
     *
     * Per-enrollment, mirroring the shape of the earn-side index
     * `loyalty_txn_earn_source_unique (enrollment_id, source_type, source_id)`
     * and of `loyalty_txn_redeem_key_unique (enrollment_id, redemption_key)`.
     */
    public function findRedeemByIdempotencyKey(string $enrollmentId, string $redemptionKey): ?Transaction;

    /**
     * Save transaction
     */
    public function save(Transaction $transaction): Transaction;

    /**
     * Delete transaction
     */
    public function delete(string $id): bool;
}
