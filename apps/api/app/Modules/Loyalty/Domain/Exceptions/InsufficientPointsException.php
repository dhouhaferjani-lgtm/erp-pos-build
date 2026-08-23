<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Exceptions;

use InvalidArgumentException;

/**
 * Typed refusal: the enrollment's committed balance does not cover the
 * redemption cost.
 *
 * Extends InvalidArgumentException so the existing presentation-layer catches
 * (LoyaltyPOSController::redeem) keep mapping it to 422 unchanged, while the
 * conditional-decrement refusal is distinguishable from a generic argument
 * error at the call site.
 */
final class InsufficientPointsException extends InvalidArgumentException {}
