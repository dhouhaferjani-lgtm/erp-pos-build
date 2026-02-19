<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Modules\Loyalty\Application\DTOs\EnrollmentData;
use App\Modules\Loyalty\Application\DTOs\LoyaltyMemberData;
use App\Modules\Loyalty\Application\DTOs\RewardData;
use App\Modules\Loyalty\Application\DTOs\TransactionData;

/**
 * Main interface for Loyalty Module
 *
 * This interface defines the contract for interacting with the loyalty system
 * from other modules. It provides methods for member lookup, point earning,
 * reward redemption, and balance queries.
 *
 * Usage from other modules:
 * - POS: Lookup member, preview earning, award points, redeem rewards
 * - Document Module: Award points on invoice posting
 * - Customer Portal: Display balance, transaction history, available rewards
 */
interface LoyaltyServiceInterface
{
    /**
     * Look up a loyalty member by phone number
     *
     * Returns member data including enrollments, current balance, and tier
     *
     * @param  string  $phone  Normalized phone number
     */
    public function lookupMemberByPhone(string $tenantId, string $phone): ?LoyaltyMemberData;

    /**
     * Look up a loyalty member by customer ID
     *
     * @param  string  $customerId  Partner ID from partners table
     */
    public function lookupMemberByCustomerId(string $customerId): ?LoyaltyMemberData;

    /**
     * Preview points that would be earned for a transaction
     *
     * Calculates points without creating any records, useful for
     * displaying earning preview in POS before completing sale
     *
     * @param  array<string, mixed>  $transactionData  Order/invoice data
     * @return array{points: float, breakdown: array<string, mixed>}
     */
    public function previewEarning(string $memberId, array $transactionData): array;

    /**
     * Award points for a completed transaction
     *
     * Creates transaction records and updates member balance
     * Should be called when invoice is posted
     *
     * @param  string  $sourceId  Document ID (invoice, order, etc)
     * @param  string  $sourceType  Type of source document
     * @param  array<string, mixed>  $transactionData
     * @return TransactionData Created transaction
     */
    public function awardPoints(
        string $memberId,
        string $sourceId,
        string $sourceType,
        array $transactionData
    ): TransactionData;

    /**
     * Get available rewards for a member
     *
     * Returns only rewards the member is eligible to redeem based on:
     * - Sufficient points balance
     * - Tier restrictions
     * - Active status and date range
     * - Quantity limits
     *
     * @return array<int, RewardData>
     */
    public function getAvailableRewards(string $memberId): array;

    /**
     * Redeem a reward
     *
     * Validates eligibility, deducts points, creates redemption transaction
     * Returns data needed to apply the reward (discount code, free item, etc)
     *
     * @param  array<string, mixed>  $context  Optional context (cart data for validation)
     * @return array{transaction: TransactionData, redemption_data: array<string, mixed>}
     */
    public function redeemReward(string $memberId, string $rewardId, array $context = []): array;

    /**
     * Get current enrollment for member in a program
     */
    public function getEnrollment(string $memberId, string $programId): ?EnrollmentData;

    /**
     * Get member's transaction history
     *
     * @return array{transactions: array<int, TransactionData>, total: int}
     */
    public function getTransactionHistory(string $memberId, int $limit = 20, int $offset = 0): array;

    /**
     * Get member's current balance across all programs
     *
     * @return array<string, array{program_name: string, balance: float, tier: string|null}>
     */
    public function getMemberBalances(string $memberId): array;

    /**
     * Manually adjust member's points balance
     *
     * For administrative corrections or adjustments
     *
     * @param  float  $amount  Positive for addition, negative for subtraction
     * @param  string  $reason  Audit trail description
     * @param  string  $adjustedBy  User ID who made the adjustment
     */
    public function adjustBalance(
        string $memberId,
        string $programId,
        float $amount,
        string $reason,
        string $adjustedBy
    ): TransactionData;

    /**
     * Clawback points (used when invoice is refunded)
     *
     * Reverses points awarded for a specific transaction
     *
     * @param  string  $sourceTransactionId  Original transaction ID that awarded points
     * @param  string  $reason  Reason for clawback
     */
    public function clawbackPoints(string $sourceTransactionId, string $reason): TransactionData;

    /**
     * Enroll a member in a program
     */
    public function enrollInProgram(string $memberId, string $programId): EnrollmentData;
}
