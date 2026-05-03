<?php

declare(strict_types=1);

namespace App\Application\Sweep\Exceptions;

use App\Application\Sweep\InventoryService;
use RuntimeException;

/**
 * Thrown by {@see InventoryService::mutate()} when the
 * post-mutator document fails JSON Schema validation. The mutation is aborted
 * BEFORE any disk write happens, so the on-disk inventory is unchanged.
 */
final class InventorySchemaViolationException extends RuntimeException {}
