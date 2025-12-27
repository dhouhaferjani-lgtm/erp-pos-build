<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Inventory\Domain\Enums\ReleaseReason;
use App\Modules\Inventory\Domain\Enums\ReservationSource;
use App\Modules\Inventory\Domain\Events\ReservationCreated;
use App\Modules\Inventory\Domain\Events\ReservationExpired;
use App\Modules\Inventory\Domain\Events\ReservationReleased;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockReservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * StockReservationService - Manages stock reservations for sales orders, carts, etc.
 *
 * CRITICAL: All methods use database transactions with pessimistic locking
 * to prevent race conditions and ensure stock reservations are atomic.
 *
 * Stock Reservation Lifecycle:
 * 1. Reserve stock when sales order is confirmed
 * 2. Release stock when order is delivered (fulfilled)
 * 3. Release stock when order is cancelled
 * 4. Expire stock when reservation timeout is reached
 *
 * Fraud Detection:
 * - All reservation events are logged for fraud detection
 * - High-value reservations trigger alerts
 * - Suspicious release patterns (expired, manual) are flagged
 */
class StockReservationService
{
    /**
     * Reserve stock for a source (sales order, cart, etc.).
     *
     * This method:
     * - Validates sufficient available stock
     * - Creates the reservation record
     * - Updates the stock level reserved field
     * - Dispatches ReservationCreated event
     *
     * @throws \RuntimeException If insufficient stock available
     */
    public function reserve(
        Company $company,
        string $productId,
        string $locationId,
        string $quantity,
        ReservationSource $sourceType,
        string $sourceId,
        ?string $sourceLineId = null,
        ?int $priority = 0,
        ?string $notes = null,
    ): StockReservation {
        return DB::transaction(function () use (
            $company,
            $productId,
            $locationId,
            $quantity,
            $sourceType,
            $sourceId,
            $sourceLineId,
            $priority,
            $notes
        ): StockReservation {
            // Lock stock level to prevent concurrent reservations
            $stockLevel = StockLevel::where('product_id', $productId)
                ->where('location_id', $locationId)
                ->where('company_id', $company->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Validate sufficient available stock
            $available = bcsub((string) $stockLevel->quantity, (string) $stockLevel->reserved, 4);
            if (bccomp($available, $quantity, 4) < 0) {
                throw new \RuntimeException(
                    "Insufficient stock. Available: {$available}, Requested: {$quantity}"
                );
            }

            // Get reservation settings from company
            $settings = $company->getReservationSettings();

            // Calculate expiry based on source type and settings
            $expiresAt = $sourceType->getDefaultExpiry($settings);

            // Create reservation record
            $reservation = StockReservation::create([
                'id' => Str::uuid()->toString(),
                'company_id' => $company->id,
                'product_id' => $productId,
                'location_id' => $locationId,
                'quantity' => $quantity,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'source_line_id' => $sourceLineId,
                'expires_at' => $expiresAt,
                'priority' => $priority ?? 0,
                'notes' => $notes,
                'created_by' => auth()->id(),
            ]);

            // Update stock level reserved field
            $stockLevel->increment('reserved', $quantity);

            // Dispatch event after transaction commits
            DB::afterCommit(function () use ($reservation, $company): void {
                event(new ReservationCreated(
                    reservationId: $reservation->id,
                    companyId: $company->id,
                    productId: $reservation->product_id,
                    locationId: $reservation->location_id,
                    quantity: (string) $reservation->quantity,
                    sourceType: $reservation->source_type->value,
                    sourceId: $reservation->source_id,
                    sourceLineId: $reservation->source_line_id,
                    expiresAt: $reservation->expires_at?->toIso8601String(),
                    priority: $reservation->priority,
                    createdBy: (string) $reservation->created_by,
                    createdAt: $reservation->created_at->toIso8601String(),
                ));
            });

            return $reservation;
        });
    }

    /**
     * Release a specific reservation.
     *
     * This method:
     * - Marks the reservation as released
     * - Updates the stock level reserved field
     * - Dispatches ReservationReleased event
     */
    public function release(
        StockReservation $reservation,
        ReleaseReason $reason,
        ?string $releasedBy = null,
    ): void {
        if (! $reservation->isActive()) {
            return; // Already released
        }

        DB::transaction(function () use ($reservation, $reason, $releasedBy): void {
            // Lock stock level
            $stockLevel = StockLevel::where('product_id', $reservation->product_id)
                ->where('location_id', $reservation->location_id)
                ->lockForUpdate()
                ->firstOrFail();

            // Update reservation
            $reservation->update([
                'released_at' => now(),
                'released_by' => $releasedBy ?? auth()->id(),
                'release_reason' => $reason,
            ]);

            // Update stock level
            $stockLevel->decrement('reserved', $reservation->quantity);

            // Dispatch event after transaction commits
            DB::afterCommit(function () use ($reservation, $reason, $releasedBy): void {
                event(new ReservationReleased(
                    reservationId: $reservation->id,
                    companyId: $reservation->company_id,
                    productId: $reservation->product_id,
                    locationId: $reservation->location_id,
                    quantity: (string) $reservation->quantity,
                    sourceType: $reservation->source_type->value,
                    sourceId: $reservation->source_id,
                    releaseReason: $reason->value,
                    releasedBy: $releasedBy ?? (string) auth()->id(),
                    releasedAt: $reservation->released_at->toIso8601String(),
                ));
            });
        });
    }

    /**
     * Release all active reservations for a source (e.g., sales order).
     *
     * This is called when a sales order is cancelled or delivered.
     */
    public function releaseBySource(
        ReservationSource $sourceType,
        string $sourceId,
        ReleaseReason $reason,
        ?string $releasedBy = null,
    ): int {
        $reservations = StockReservation::where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->active()
            ->get();

        $count = 0;
        foreach ($reservations as $reservation) {
            $this->release($reservation, $reason, $releasedBy);
            $count++;
        }

        return $count;
    }

    /**
     * Mark expired reservations.
     *
     * This method:
     * - Finds all active reservations that have expired
     * - Marks them as released with ReleaseReason::Expired
     * - Dispatches ReservationExpired events
     *
     * Called by scheduled job ExpireReservationsJob.
     */
    public function expireReservations(): int
    {
        $expiredReservations = StockReservation::expired()->get();

        $count = 0;
        foreach ($expiredReservations as $reservation) {
            DB::transaction(function () use ($reservation): void {
                // Lock stock level
                $stockLevel = StockLevel::where('product_id', $reservation->product_id)
                    ->where('location_id', $reservation->location_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                // Update reservation
                $reservation->update([
                    'expired_at' => now(),
                    'released_at' => now(),
                    'release_reason' => ReleaseReason::Expired,
                ]);

                // Update stock level
                $stockLevel->decrement('reserved', $reservation->quantity);

                // Dispatch event after transaction commits
                DB::afterCommit(function () use ($reservation): void {
                    event(new ReservationExpired(
                        reservationId: $reservation->id,
                        companyId: $reservation->company_id,
                        productId: $reservation->product_id,
                        locationId: $reservation->location_id,
                        quantity: (string) $reservation->quantity,
                        sourceType: $reservation->source_type->value,
                        sourceId: $reservation->source_id,
                        originalExpiresAt: $reservation->expires_at->toIso8601String(),
                        expiredAt: $reservation->expired_at->toIso8601String(),
                    ));
                });
            });
            $count++;
        }

        return $count;
    }

    /**
     * Recalculate reserved quantities for all stock levels.
     *
     * Useful for fixing drift between reserved field and actual reservations.
     * This should be run as a maintenance task, not during normal operations.
     */
    public function recalculateAllReserved(Company $company): int
    {
        $stockLevels = StockLevel::where('company_id', $company->id)
            ->where('reserved', '>', 0)
            ->get();

        $count = 0;
        foreach ($stockLevels as $stockLevel) {
            $stockLevel->recalculateReserved();
            $count++;
        }

        return $count;
    }
}
