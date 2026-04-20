<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Services;

use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Events\ShiftClosed;
use App\Modules\POS\Domain\Events\ShiftOpened;
use App\Modules\POS\Domain\Exceptions\ShiftAlreadyOpenException;
use App\Modules\POS\Domain\Exceptions\ShiftNotOpenException;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Service for managing POS shift lifecycle.
 *
 * Handles opening and closing of cashier work sessions with cash drawer tracking.
 * Enforces business rules: only one open shift per terminal at a time.
 */
final class ShiftManagementService
{
    public function __construct(
        private readonly CashDrawerService $cashDrawerService,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    private function scale(): int
    {
        return $this->scaleResolver->getScale();
    }

    /**
     * Open a new shift with opening balance
     *
     * Business Rules:
     * - Only one open shift per terminal at a time (enforced by database unique index)
     * - Shift number is sequential and never resets
     * - Creates OPENING cash drawer operation
     *
     * @param  Terminal  $terminal  The terminal to open shift on
     * @param  User  $cashier  The cashier opening the shift
     * @param  string  $openingCash  Opening cash amount (decimal string)
     *
     * @throws ShiftAlreadyOpenException If terminal already has an open shift
     */
    public function openShift(
        Terminal $terminal,
        User $cashier,
        string $openingCash
    ): Shift {
        // Check for existing open shift
        $existingOpenShift = Shift::where('terminal_id', $terminal->id)
            ->where('status', ShiftStatus::Open)
            ->first();

        if ($existingOpenShift) {
            throw ShiftAlreadyOpenException::forTerminal($terminal->id);
        }

        return DB::transaction(function () use ($terminal, $cashier, $openingCash) {
            // Get next shift number
            $lastShift = Shift::where('terminal_id', $terminal->id)
                ->orderByDesc('shift_number')
                ->first();

            $shiftNumber = $lastShift ? $lastShift->shift_number + 1 : 1;

            // Create shift
            $shift = Shift::create([
                'terminal_id' => $terminal->id,
                'cashier_id' => $cashier->id,
                'shift_number' => $shiftNumber,
                'opening_cash' => $openingCash,
                'status' => ShiftStatus::Open,
                'opened_at' => now(),
            ]);

            // Record opening cash drawer operation
            $this->cashDrawerService->recordOpening($shift, $openingCash, $cashier);

            /** @var Shift $freshShift */
            $freshShift = $shift->fresh();

            DB::afterCommit(function () use ($freshShift, $terminal, $cashier, $openingCash): void {
                event(new ShiftOpened(
                    shiftId: $freshShift->id,
                    companyId: $terminal->company_id,
                    terminalId: $terminal->id,
                    cashierId: $cashier->id,
                    openingBalance: $openingCash,
                    openedAt: $freshShift->opened_at->toIso8601String(),
                ));
            });

            return $freshShift;
        });
    }

    /**
     * Close current shift with cash count
     *
     * Business Rules:
     * - Shift must be in OPEN status
     * - Calculates expected cash from opening + sales - payouts + deposits
     * - Calculates variance (actual - expected)
     * - Creates CLOSING cash drawer operation
     *
     * @param  Shift  $shift  The shift to close
     * @param  string  $actualCash  Counted cash amount (decimal string)
     * @param  User  $closedBy  User closing the shift
     * @param  Carbon|null  $closedAt  Optional offline close timestamp (defaults to now)
     *
     * @throws ShiftNotOpenException If shift is not in OPEN status
     */
    public function closeShift(
        Shift $shift,
        string $actualCash,
        User $closedBy,
        ?Carbon $closedAt = null,
    ): Shift {
        if (! $shift->isOpen()) {
            throw ShiftNotOpenException::forShift($shift->id);
        }

        return DB::transaction(function () use ($shift, $actualCash, $closedBy, $closedAt) {
            // Calculate expected cash
            /** @var numeric-string $expectedCash */
            $expectedCash = $this->cashDrawerService->calculateExpectedCash($shift);

            // Calculate variance
            /** @var numeric-string $actualCash */
            $variance = bcsub($actualCash, $expectedCash, $this->scale());

            // Update shift
            $shift->update([
                'status' => ShiftStatus::Closed,
                'expected_cash' => $expectedCash,
                'actual_cash' => $actualCash,
                'variance' => $variance,
                'closed_at' => $closedAt ?? now(),
                'closed_by' => $closedBy->id,
            ]);

            // Record closing cash drawer operation
            $this->cashDrawerService->recordClosing($shift, $actualCash, $closedBy);

            /** @var Shift $freshShift */
            $freshShift = $shift->fresh();

            /** @var Terminal $shiftTerminal */
            $shiftTerminal = $freshShift->terminal;

            /** @var Carbon $closedAtTimestamp */
            $closedAtTimestamp = $freshShift->closed_at;

            DB::afterCommit(function () use ($freshShift, $shiftTerminal, $closedAtTimestamp): void {
                event(new ShiftClosed(
                    shiftId: $freshShift->id,
                    companyId: $shiftTerminal->company_id,
                    terminalId: $freshShift->terminal_id,
                    cashierId: $freshShift->cashier_id,
                    expectedCash: (string) $freshShift->expected_cash,
                    actualCash: (string) $freshShift->actual_cash,
                    variance: (string) $freshShift->variance,
                    closedAt: $closedAtTimestamp->toIso8601String(),
                ));
            });

            return $freshShift;
        });
    }

    /**
     * Get current open shift for terminal
     *
     * @param  Terminal  $terminal  The terminal to check
     * @return Shift|null The open shift or null if none
     */
    public function getCurrentShift(Terminal $terminal): ?Shift
    {
        return Shift::where('terminal_id', $terminal->id)
            ->where('status', ShiftStatus::Open)
            ->first();
    }

    /**
     * Check if terminal has an open shift
     *
     * @param  Terminal  $terminal  The terminal to check
     * @return bool True if terminal has an open shift
     */
    public function hasOpenShift(Terminal $terminal): bool
    {
        return $this->getCurrentShift($terminal) !== null;
    }

    /**
     * Get shift by ID with validation
     *
     * @param  string  $shiftId  The shift ID
     *
     * @throws ModelNotFoundException
     */
    public function getShiftById(string $shiftId): Shift
    {
        return Shift::findOrFail($shiftId);
    }
}
