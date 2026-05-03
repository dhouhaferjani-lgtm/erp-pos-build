<?php

declare(strict_types=1);

namespace App\Application\Sweep\Exceptions;

use App\Application\Sweep\Scanners\ManualScanner;
use RuntimeException;

/**
 * Thrown by {@see ManualScanner::scan()} when two manual_callsites entries
 * share the same `cluster_id` + `slug` pair. Such a collision would produce
 * identical stable_keys (`manual:<cluster>:<slug>`) and silently corrupt
 * the inventory's per-callsite identity. Caught at scan time so the
 * malformed stub never reaches InventoryService schema validation.
 *
 * Codex Phase 1 review #4 acceptance criterion.
 */
final class ManualScannerDuplicateKeyException extends RuntimeException {}
