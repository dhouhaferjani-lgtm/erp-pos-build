<?php

declare(strict_types=1);

namespace App\Application\Sweep\Scanners;

use App\Application\Sweep\Domain\InventoryDocument;

/**
 * One callsite row produced by a {@see Scanner}.
 *
 * Mirrors the YAML schema's `callsite` shape (master plan Section 4) but
 * with only the fields a scanner can determine. Fields that emerge from
 * workflow state — `status`, `owner`, `claimed_at`, `review`, `fix_commit`,
 * `regression_test`, `blocked_reason` — are populated by the artisan
 * commands when a callsite is claimed/submitted/etc., not by the scanner.
 *
 * `stableKey` is the content-addressed identity built by
 * {@see StableKey::fromScannerOutput()} (or "manual:<cluster>:<slug>" for
 * the {@see ManualScanner}). It survives renames/moves per the rename
 * semantics in master plan Section 4.
 */
final class CallsiteRow
{
    /**
     * @param  string  $stableKey  sha256:<64hex> or manual:<cluster>:<slug>
     * @param  string  $surface  "api" | "web" | "tauri"
     * @param  string  $clusterId  canonical cluster id (e.g. "api.treasury")
     * @param  string  $scanner  scanner name (one of the schema's scanner enum)
     * @param  string  $relativePath  path from repo root (e.g. "apps/api/app/Modules/Treasury/...")
     * @param  int|null  $line  display-only metadata
     * @param  string  $symbol  fully-qualified PHP/TS symbol identifier
     * @param  string  $patternType  free-form scanner-specific pattern label
     * @param  string|null  $resource  table or model short name
     * @param  string  $expectedScope  schema's expected_scope enum
     * @param  string|null  $expectedFix  developer-facing remediation guidance
     * @param  string  $severity  "high" | "medium" | "low"
     * @param  bool  $fiscalPath  flagged when the call is on a fiscal/finance code path
     * @param  bool  $crossModule  flagged when fixing requires cross-module coordination
     */
    public function __construct(
        public readonly string $stableKey,
        public readonly string $surface,
        public readonly string $clusterId,
        public readonly string $scanner,
        public readonly string $relativePath,
        public readonly ?int $line,
        public readonly string $symbol,
        public readonly string $patternType,
        public readonly ?string $resource,
        public readonly string $expectedScope,
        public readonly ?string $expectedFix,
        public readonly string $severity,
        public readonly bool $fiscalPath,
        public readonly bool $crossModule,
    ) {}

    /**
     * Render this row as the YAML-shaped associative array expected by
     * {@see InventoryDocument} and the JSON
     * Schema. The schema's required workflow-state fields are filled with
     * sensible defaults (`status: pending`, `owner: null`, etc.) so the
     * generate command can write the row directly.
     *
     * @param  string  $callsiteId  full callsite id e.g. "api.treasury.001"
     * @param  string  $generatedAt  ISO 8601 timestamp for the initial history event
     * @param  string  $note  description for the initial history event
     * @return array<string, mixed>
     */
    public function toInventoryRow(string $callsiteId, string $generatedAt, string $note = 'initial detection'): array
    {
        return [
            'id' => $callsiteId,
            'stable_key' => $this->stableKey,
            'surface' => $this->surface,
            'cluster_id' => $this->clusterId,
            'scanner' => $this->scanner,
            'file' => $this->relativePath,
            'line' => $this->line,
            'symbol' => $this->symbol,
            'pattern_type' => $this->patternType,
            'resource' => $this->resource,
            'expected_scope' => $this->expectedScope,
            'expected_fix' => $this->expectedFix,
            'severity' => $this->severity,
            'fiscal_path' => $this->fiscalPath,
            'cross_module' => $this->crossModule,
            'stale_state' => 'active',
            'status' => 'pending',
            'owner' => null,
            'claimed_at' => null,
            'review' => [
                'reviewer' => null,
                'verdict' => null,
                'reviewed_at' => null,
                'review_file' => null,
                'review_commit' => null,
            ],
            'fix_commit' => null,
            'regression_test' => null,
            'blocked_reason' => null,
            'history' => [
                [
                    'at' => $generatedAt,
                    'actor' => 'generator',
                    'action' => 'generate',
                    'command' => 'sweep:inventory:generate',
                    'previous_yaml_sha256' => null,
                    'new_yaml_sha256' => null,
                    'target_ids' => [$callsiteId],
                    'from_status' => null,
                    'to_status' => 'pending',
                    'commit' => null,
                    'test' => null,
                    'review_file' => null,
                    'review_commit' => null,
                    'note' => $note,
                ],
            ],
        ];
    }
}
