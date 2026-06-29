<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown when an opening balance reset is blocked because either:
 *   - no active (non-reversed) opening movement exists for the product, or
 *   - downstream (non-opening) inventory movements exist, making a reset unsafe.
 *
 * Maps to HTTP 409 in the controller.
 */
final class OpeningLockedException extends RuntimeException {}
