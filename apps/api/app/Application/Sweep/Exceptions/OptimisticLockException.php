<?php

declare(strict_types=1);

namespace App\Application\Sweep\Exceptions;

use App\Application\Sweep\InventoryService;
use RuntimeException;

/**
 * Thrown by {@see InventoryService::mutate()} when the
 * inventory YAML on disk does not match the in-memory document the caller
 * loaded — i.e. another writer (or a hand-edit) modified the file outside the
 * service's flock window. The mutation is aborted and the file is left
 * untouched.
 *
 * Recovery: re-read via {@see InventoryService::load()}
 * and retry. The service has no built-in retry — callers (the artisan
 * commands) decide whether to retry, prompt the user, or abort.
 */
final class OptimisticLockException extends RuntimeException {}
