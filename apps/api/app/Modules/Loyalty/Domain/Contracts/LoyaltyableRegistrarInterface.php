<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Contracts;

use App\Modules\Loyalty\Domain\Services\LoyaltyRegistry;

/**
 * Interface for vertical-specific registrars of loyaltyable entities.
 *
 * Each vertical (automotive service, restaurant, retail) must implement
 * this interface to register their domain entities that can participate
 * in loyalty programs.
 *
 * Implementation Pattern:
 * ```php
 * class AutomotiveServiceRegistrar implements LoyaltyableRegistrarInterface {
 *     public function register(LoyaltyRegistry $registry): void {
 *         $registry->registerLoyaltyableType('service', ServiceEntity::class);
 *         $registry->registerLoyaltyableType('product', ProductEntity::class);
 *     }
 * }
 * ```
 *
 * The registrar is called during service provider boot to establish
 * the polymorphic mapping needed for loyalty calculations and reports.
 */
interface LoyaltyableRegistrarInterface
{
    /**
     * Register all loyaltyable entities for this vertical.
     *
     * Called during service provider bootstrap to register the entities
     * from this vertical that can participate in loyalty programs.
     *
     * @param  LoyaltyRegistry  $registry  The loyalty registry to register entities with
     */
    public function register(LoyaltyRegistry $registry): void;
}
