<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Services;

use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\CashDrawerOperation;
use App\Modules\POS\Domain\Events\CashDrawerOperationRecorded;
use App\Modules\POS\Domain\Services\Fiscal\V3\CanonicalJsonEncoder;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Service for managing cash drawer operations.
 *
 * Handles all cash movements during a shift including:
 * - Opening balance (OPENING)
 * - Sales receipts (SALE)
 * - Refunds (REFUND)
 * - Safe deposits (DEPOSIT)
 * - Payouts for refunds/petty cash (PAYOUT)
 * - Closing balance (CLOSING)
 *
 * All operations are immutable once created (audit trail).
 *
 * @phpstan-type CashDrawerApprovalEvidence array{
 *     approval_id?: string|null,
 *     approval_fiscal_event_id?: string|null,
 *     approval_scope?: string|null,
 *     approval_supervisor_user_id?: string|null,
 *     approval_target_hash?: string|null
 * }
 */
final class CashDrawerService
{
    public function __construct(
        private readonly CurrencyScaleResolverInterface $scaleResolver,
        private readonly CanonicalJsonEncoder $canonicalJsonEncoder,
    ) {}

    private function scale(): int
    {
        return $this->scaleResolver->getScale();
    }

    /**
     * @param  CashDrawerApprovalEvidence|null  $approvalEvidence
     * @return array<string, string|null>
     */
    private function approvalAttributes(?array $approvalEvidence): array
    {
        if ($approvalEvidence === null) {
            return [
                'approval_id' => null,
                'approval_fiscal_event_id' => null,
                'approval_scope' => null,
                'approval_supervisor_user_id' => null,
                'approval_target_hash' => null,
            ];
        }

        return [
            'approval_id' => $approvalEvidence['approval_id'] ?? null,
            'approval_fiscal_event_id' => $approvalEvidence['approval_fiscal_event_id'] ?? null,
            'approval_scope' => $approvalEvidence['approval_scope'] ?? null,
            'approval_supervisor_user_id' => $approvalEvidence['approval_supervisor_user_id'] ?? null,
            'approval_target_hash' => $approvalEvidence['approval_target_hash'] ?? null,
        ];
    }

    /**
     * @param  CashDrawerApprovalEvidence|null  $approvalEvidence
     *
     * @throws ValidationException
     */
    private function assertCashDrawerApproval(
        Shift $shift,
        string $operationType,
        string $amount,
        User $user,
        string $reason,
        ?array $approvalEvidence,
        ?string $idempotencyKey,
    ): void {
        $terminal = $shift->terminal;

        if ($approvalEvidence === null) {
            throw ValidationException::withMessages([
                'approval_id' => ['Manager approval evidence is required for cash drawer operations.'],
            ]);
        }

        $approvalId = $approvalEvidence['approval_id'] ?? null;
        $approvalFiscalEventId = $approvalEvidence['approval_fiscal_event_id'] ?? null;
        $approvalScope = $approvalEvidence['approval_scope'] ?? null;
        $supervisorUserId = $approvalEvidence['approval_supervisor_user_id'] ?? null;
        $targetHash = $approvalEvidence['approval_target_hash'] ?? null;

        if (
            ! is_string($approvalId)
            || ! is_string($approvalFiscalEventId)
            || ! is_string($approvalScope)
            || ! is_string($supervisorUserId)
            || ! is_string($targetHash)
            || $approvalScope !== 'cash_drawer_control'
        ) {
            throw ValidationException::withMessages([
                'approval_id' => ['Invalid cash drawer approval evidence.'],
            ]);
        }

        $approvalEvent = FiscalEvent::query()
            ->where('id', $approvalFiscalEventId)
            ->where('tenant_id', $terminal->tenant_id)
            ->where('company_id', $terminal->company_id)
            ->where('terminal_id', $terminal->id)
            ->where('event_type', FiscalEventType::OPERATOR_APPROVAL_GRANTED->value)
            ->first();

        if ($approvalEvent === null) {
            throw ValidationException::withMessages([
                'approval_fiscal_event_id' => ['Cash drawer approval fiscal event was not found.'],
            ]);
        }

        $payload = $this->approvalPayload($approvalEvent);
        $target = $payload['target'] ?? null;
        if (! is_array($target)) {
            throw ValidationException::withMessages([
                'approval_fiscal_event_id' => ['Cash drawer approval target is missing.'],
            ]);
        }

        $expectedTargetReferenceId = $idempotencyKey ?? (string) ($target['target_reference_id'] ?? '');
        $expectedTarget = [
            'amount' => $amount,
            'operation_type' => $operationType,
            'reason' => $reason,
            'shift_id' => $shift->id,
            'target_reference_id' => $expectedTargetReferenceId,
        ];
        $expectedTargetHash = hash('sha256', $this->canonicalJsonEncoder->encode($expectedTarget));

        if (
            ($payload['approval_id'] ?? null) !== $approvalId
            || ($payload['approval_scope'] ?? null) !== 'cash_drawer_control'
            || ($payload['cashier_user_id'] ?? null) !== $user->id
            || ($payload['supervisor_user_id'] ?? null) !== $supervisorUserId
            || ($payload['tenant_id'] ?? null) !== $terminal->tenant_id
            || ($payload['company_id'] ?? null) !== $terminal->company_id
            || ($payload['terminal_id'] ?? null) !== $terminal->id
            || ($target['target_hash'] ?? null) !== $targetHash
            || $targetHash !== $expectedTargetHash
            || ($target['operation_type'] ?? null) !== $operationType
            || ($target['amount'] ?? null) !== $amount
            || ($target['reason'] ?? null) !== $reason
            || ($target['shift_id'] ?? null) !== $shift->id
            || ($target['target_reference_id'] ?? null) !== $expectedTargetReferenceId
        ) {
            throw ValidationException::withMessages([
                'approval_fiscal_event_id' => ['Cash drawer approval evidence does not match this operation.'],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function approvalPayload(FiscalEvent $approvalEvent): array
    {
        if (is_array($approvalEvent->payload)) {
            return $approvalEvent->payload;
        }

        $decoded = json_decode($approvalEvent->canonical_bytes, true);
        if (! is_array($decoded)) {
            return [];
        }

        $payload = $decoded['payload'] ?? null;

        return is_array($payload) ? $payload : [];
    }

    /**
     * Record opening cash drawer operation
     *
     * Called when a shift is opened with the cashier's declared starting balance.
     *
     * @param  Shift  $shift  The shift being opened
     * @param  string  $amount  Opening cash amount (decimal string)
     * @param  User  $user  User opening the shift
     */
    public function recordOpening(
        Shift $shift,
        string $amount,
        User $user
    ): CashDrawerOperation {
        return CashDrawerOperation::create([
            'shift_id' => $shift->id,
            'operation_type' => 'OPENING',
            'amount' => $amount,
            'user_id' => $user->id,
            'reason' => 'Shift opened with declared starting balance',
            'receipt_id' => null,
        ]);
    }

    /**
     * Record closing cash drawer operation
     *
     * Called when a shift is closed with the counted cash amount.
     *
     * @param  Shift  $shift  The shift being closed
     * @param  string  $amount  Actual cash counted (decimal string)
     * @param  User  $user  User closing the shift
     */
    public function recordClosing(
        Shift $shift,
        string $amount,
        User $user
    ): CashDrawerOperation {
        return CashDrawerOperation::create([
            'shift_id' => $shift->id,
            'operation_type' => 'CLOSING',
            'amount' => $amount,
            'user_id' => $user->id,
            'reason' => 'Shift closed with cash count',
            'receipt_id' => null,
        ]);
    }

    /**
     * Record cash deposit to safe (safe drop)
     *
     * Used when cashier moves large bills from register to safe during shift.
     * This is a removal from the drawer (negative to expected balance).
     *
     * @param  Shift  $shift  The shift to record deposit for
     * @param  string  $amount  Amount deposited to safe (positive decimal)
     * @param  User  $user  User performing the deposit
     * @param  string  $reason  Reason for deposit
     * @param  CashDrawerApprovalEvidence|null  $approvalEvidence
     */
    public function recordDeposit(
        Shift $shift,
        string $amount,
        User $user,
        string $reason,
        ?array $approvalEvidence = null,
        ?string $idempotencyKey = null,
    ): CashDrawerOperation {
        $this->assertCashDrawerApproval($shift, 'DEPOSIT', $amount, $user, $reason, $approvalEvidence, $idempotencyKey);

        $operation = CashDrawerOperation::create([
            'shift_id' => $shift->id,
            'operation_type' => 'DEPOSIT',
            'amount' => $amount,
            'user_id' => $user->id,
            'reason' => $reason,
            'receipt_id' => null,
            'idempotency_key' => $idempotencyKey,
        ] + $this->approvalAttributes($approvalEvidence));

        $this->dispatchOperationEvent($operation, $shift);

        return $operation;
    }

    /**
     * Record cash payout (refund, petty cash)
     *
     * Used when cashier pays out cash from register (not related to a sale).
     * This is a removal from the drawer (negative to expected balance).
     *
     * @param  Shift  $shift  The shift to record payout for
     * @param  string  $amount  Amount paid out (positive decimal)
     * @param  User  $user  User performing the payout
     * @param  string  $reason  Reason for payout
     * @param  CashDrawerApprovalEvidence|null  $approvalEvidence
     */
    public function recordPayout(
        Shift $shift,
        string $amount,
        User $user,
        string $reason,
        ?array $approvalEvidence = null,
        ?string $idempotencyKey = null,
    ): CashDrawerOperation {
        $this->assertCashDrawerApproval($shift, 'PAYOUT', $amount, $user, $reason, $approvalEvidence, $idempotencyKey);

        $operation = CashDrawerOperation::create([
            'shift_id' => $shift->id,
            'operation_type' => 'PAYOUT',
            'amount' => $amount,
            'user_id' => $user->id,
            'reason' => $reason,
            'receipt_id' => null,
            'idempotency_key' => $idempotencyKey,
        ] + $this->approvalAttributes($approvalEvidence));

        $this->dispatchOperationEvent($operation, $shift);

        return $operation;
    }

    /**
     * Record cash sale from receipt
     *
     * Called when a receipt with cash payment is completed.
     *
     * @param  Shift  $shift  The shift to record sale for
     * @param  string  $amount  Cash amount from sale (positive decimal)
     * @param  User  $user  User completing the sale
     * @param  string  $receiptId  Receipt UUID reference
     */
    public function recordSale(
        Shift $shift,
        string $amount,
        User $user,
        string $receiptId
    ): CashDrawerOperation {
        return CashDrawerOperation::create([
            'shift_id' => $shift->id,
            'operation_type' => 'SALE',
            'amount' => $amount,
            'user_id' => $user->id,
            'reason' => 'Cash sale',
            'receipt_id' => $receiptId,
        ]);
    }

    /**
     * Record cash refund from receipt
     *
     * Called when a cash refund is issued (return/void).
     *
     * @param  Shift  $shift  The shift to record refund for
     * @param  string  $amount  Cash refund amount (positive decimal)
     * @param  User  $user  User processing the refund
     * @param  string  $receiptId  Receipt UUID reference
     */
    public function recordRefund(
        Shift $shift,
        string $amount,
        User $user,
        string $receiptId
    ): CashDrawerOperation {
        $operation = CashDrawerOperation::create([
            'shift_id' => $shift->id,
            'operation_type' => 'REFUND',
            'amount' => $amount,
            'user_id' => $user->id,
            'reason' => 'Cash refund',
            'receipt_id' => $receiptId,
        ]);

        $this->dispatchOperationEvent($operation, $shift);

        return $operation;
    }

    /**
     * Calculate expected cash in drawer
     *
     * Formula:
     * Expected = Opening + Sales - Refunds - Deposits - Payouts
     *
     * Business Rules:
     * - OPENING: Adds to balance (initial amount)
     * - SALE: Adds to balance (cash in)
     * - REFUND: Subtracts from balance (cash out)
     * - DEPOSIT: Subtracts from balance (moved to safe)
     * - PAYOUT: Subtracts from balance (cash out for refunds/petty cash)
     * - CLOSING: Not included in calculation (it's the actual count)
     *
     * @param  Shift  $shift  The shift to calculate for
     * @return string Expected cash amount (decimal string)
     */
    public function calculateExpectedCash(Shift $shift): string
    {
        $operations = CashDrawerOperation::where('shift_id', $shift->id)
            ->where('operation_type', '!=', 'CLOSING')  // Exclude closing operation
            ->get();

        $expected = '0.00';

        foreach ($operations as $operation) {
            switch ($operation->operation_type) {
                case 'OPENING':
                case 'SALE':
                    // Add to balance
                    $expected = bcadd($expected, $operation->amount, $this->scale());
                    break;

                case 'REFUND':
                case 'DEPOSIT':
                case 'PAYOUT':
                    // Subtract from balance
                    $expected = bcsub($expected, $operation->amount, $this->scale());
                    break;
            }
        }

        return $expected;
    }

    /**
     * Get all operations for a shift
     *
     * @param  Shift  $shift  The shift to get operations for
     * @return Collection<int, CashDrawerOperation>
     */
    public function getShiftOperations(Shift $shift)
    {
        return CashDrawerOperation::where('shift_id', $shift->id)
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Get operations by type for a shift
     *
     * @param  Shift  $shift  The shift to get operations for
     * @param  string  $operationType  Operation type filter
     * @return Collection<int, CashDrawerOperation>
     */
    public function getOperationsByType(Shift $shift, string $operationType)
    {
        return CashDrawerOperation::where('shift_id', $shift->id)
            ->where('operation_type', $operationType)
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Get total amount by operation type
     *
     * @param  Shift  $shift  The shift to calculate for
     * @param  string  $operationType  Operation type to sum
     * @return string Total amount (decimal string)
     */
    public function getTotalByType(Shift $shift, string $operationType): string
    {
        $total = CashDrawerOperation::where('shift_id', $shift->id)
            ->where('operation_type', $operationType)
            ->sum('amount');

        return CurrencyScale::bcformat($total, $this->scale());
    }

    /**
     * Dispatch CashDrawerOperationRecorded event for audit trail.
     *
     * Only dispatches for DEPOSIT, PAYOUT, and REFUND operations.
     * OPENING, CLOSING, and SALE are covered by their own domain events.
     */
    private function dispatchOperationEvent(CashDrawerOperation $operation, Shift $shift): void
    {
        $shift->loadMissing('terminal');

        /** @var Terminal $terminal */
        $terminal = $shift->terminal;

        event(new CashDrawerOperationRecorded(
            operationId: $operation->id,
            companyId: $terminal->company_id,
            shiftId: $shift->id,
            terminalId: $terminal->id,
            operationType: $operation->operation_type,
            amount: (string) $operation->amount,
            userId: $operation->user_id,
            recordedAt: $operation->created_at->toIso8601String(),
        ));
    }
}
