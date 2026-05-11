<?php

declare(strict_types=1);

namespace App\Application\Sweep\Scanners;

use App\Console\Commands\SweepInventoryGenerateCommand;

/**
 * Common contract for the tenant-isolation sweep scanners (master plan
 * Section 5). Each scanner walks a slice of the codebase (Presentation tier,
 * Application tier, Tauri SQLite, etc.) and emits one {@see CallsiteRow}
 * per detected violation.
 *
 * Scanners are idempotent: re-running over unchanged code produces an
 * identical row set with identical {@see CallsiteRow::$stableKey} values.
 * The {@see SweepInventoryGenerateCommand} merges
 * scanner output with the existing inventory by stable key.
 */
interface Scanner
{
    /**
     * @return list<CallsiteRow>
     */
    public function scan(): array;

    /**
     * The scanner's canonical name as it appears in the YAML schema's
     * `callsite.scanner` enum (php_presentation_exists, php_ast_find,
     * ts_query_key, pos_sqlite_cache, manual).
     */
    public function name(): string;
}
