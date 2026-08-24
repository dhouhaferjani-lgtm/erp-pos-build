<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Exceptions;

use InvalidArgumentException;

/**
 * Typed refusal: the enrollment is not `EnrollmentStatus::Active` (suspended or
 * opted out) and may not move points.
 *
 * Mirrors the guard PointAdjustmentService::adjust has carried since it shipped
 * (PointAdjustmentService.php:34). Extends InvalidArgumentException so the
 * existing presentation-layer catches keep mapping it to 422 unchanged.
 */
final class EnrollmentNotActiveException extends InvalidArgumentException {}
