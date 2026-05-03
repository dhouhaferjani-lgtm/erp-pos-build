<?php

declare(strict_types=1);

namespace App\Application\Sweep\Scanners;

use App\Application\Sweep\Exceptions\ManualScannerDuplicateKeyException;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads manually-declared callsites from a YAML stub at
 * `docs/superpowers/plans/tenant-isolation-sweep-manual-callsites.yml`.
 *
 * Manual rows cover clusters mechanical scanners can't reach (master plan
 * Section 5.5):
 *   - api.platform-integration outbound payloads
 *   - api.broadcast-channels authorization callbacks
 *   - api.scheduled-jobs / api.console-commands cross-tenant iteration
 *   - api.webhooks-incoming tenant-binding via signature/tracking-id
 *
 * Rows have stable_key = `manual:<cluster>:<slug>` — never reproducible by
 * any sha256-based scanner, so they survive `sweep:inventory:generate`
 * regenerations. They're only removed via `sweep:inventory:defer` /
 * `sweep:inventory:resolve` workflow.
 *
 * Stub shape (validated at scan time):
 * ```yaml
 * manual_callsites:
 *   - cluster_id: "api.platform-integration"
 *     slug: "platform-http-client-tenant-binding"
 *     file: "apps/api/app/Modules/Platform/.../PlatformHttpClient.php"
 *     symbol: "App\\Modules\\...\\PlatformHttpClient::request"
 *     pattern_type: "outbound_tenant_identity"
 *     resource: null              # optional
 *     expected_scope: "tenant_only"
 *     severity: "high"
 *     expected_fix: "..."
 *     fiscal_path: false          # optional, defaults false
 *     cross_module: false         # optional, defaults false
 *     surface: "api"              # optional, defaults "api"
 *     line: null                  # optional, defaults null
 * ```
 *
 * If the stub file does not exist, the scanner returns an empty result
 * (the inventory generator always invokes all five scanners).
 */
final class ManualScanner implements Scanner
{
    /**
     * Required keys in every manual_callsites entry. `resource`,
     * `fiscal_path`, `cross_module`, `surface`, `line` are optional with
     * documented defaults.
     */
    private const REQUIRED_FIELDS = [
        'cluster_id',
        'slug',
        'file',
        'symbol',
        'pattern_type',
        'expected_scope',
        'severity',
        'expected_fix',
    ];

    public function __construct(
        private readonly string $stubPath,
    ) {}

    public function name(): string
    {
        return 'manual';
    }

    /**
     * @return list<CallsiteRow>
     */
    public function scan(): array
    {
        if (! is_file($this->stubPath)) {
            return [];
        }

        $parsed = Yaml::parseFile($this->stubPath);
        if ($parsed === null) {
            return [];
        }
        if (! is_array($parsed)) {
            throw new RuntimeException(
                "Manual callsites stub at {$this->stubPath} must be a YAML mapping.",
            );
        }

        /** @var array<string, mixed> $parsed */
        $entries = $parsed['manual_callsites'] ?? [];
        if (! is_array($entries)) {
            throw new RuntimeException(
                "Manual callsites stub at {$this->stubPath}: 'manual_callsites' must be a list.",
            );
        }

        $rows = [];
        // Tracks which (cluster_id + slug) pairs we've already seen and at
        // which index, so duplicates can be reported with both offending
        // indexes (Codex Phase 1 review #4). Must be checked BEFORE any
        // CallsiteRow is constructed so the malformed stub never reaches
        // InventoryService schema validation.
        $seenAtIndex = [];
        $i = 0;
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                throw new RuntimeException(
                    "Manual callsites stub at {$this->stubPath}: entry at index {$i} must be a mapping.",
                );
            }
            $key = $this->compositeKey($entry, $i);
            if (isset($seenAtIndex[$key])) {
                throw new ManualScannerDuplicateKeyException(sprintf(
                    "Manual callsites stub at %s: duplicate cluster_id+slug '%s' at indexes %d and %d. ".
                    'Both entries would produce stable_key=manual:%s, silently colliding the inventory.',
                    $this->stubPath,
                    $key,
                    $seenAtIndex[$key],
                    $i,
                    $key,
                ));
            }
            $seenAtIndex[$key] = $i;
            $rows[] = $this->buildRow($entry, $i);
            $i++;
        }

        return $rows;
    }

    /**
     * Build the manual stable_key body (cluster_id + slug) for an entry,
     * honoring the same required-field rules as buildRow() for those two
     * specific fields. This runs BEFORE buildRow() so the duplicate check
     * fires before any other malformed-entry exception.
     *
     * @param  array<string, mixed>  $entry
     */
    private function compositeKey(array $entry, int $index): string
    {
        if (! array_key_exists('cluster_id', $entry)) {
            throw new RuntimeException(
                "Manual callsites stub at {$this->stubPath}: entry at index {$index} ".
                "missing required field 'cluster_id'.",
            );
        }
        if (! array_key_exists('slug', $entry)) {
            throw new RuntimeException(
                "Manual callsites stub at {$this->stubPath}: entry at index {$index} ".
                "missing required field 'slug'.",
            );
        }

        return ((string) $entry['cluster_id']).':'.((string) $entry['slug']);
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function buildRow(array $entry, int $index): CallsiteRow
    {
        foreach (self::REQUIRED_FIELDS as $field) {
            if (! array_key_exists($field, $entry)) {
                throw new RuntimeException(
                    "Manual callsites stub at {$this->stubPath}: entry at index {$index} ".
                    "missing required field '{$field}'.",
                );
            }
        }

        $clusterId = (string) $entry['cluster_id'];
        $slug = (string) $entry['slug'];
        $surface = (string) ($entry['surface'] ?? 'api');
        $line = $entry['line'] ?? null;
        if ($line !== null && ! is_int($line)) {
            throw new RuntimeException(
                "Manual callsites stub at {$this->stubPath}: entry at index {$index} ".
                "field 'line' must be an integer or null.",
            );
        }

        $resource = $entry['resource'] ?? null;
        if ($resource !== null && ! is_string($resource)) {
            throw new RuntimeException(
                "Manual callsites stub at {$this->stubPath}: entry at index {$index} ".
                "field 'resource' must be a string or null.",
            );
        }

        return new CallsiteRow(
            stableKey: 'manual:'.$clusterId.':'.$slug,
            surface: $surface,
            clusterId: $clusterId,
            scanner: $this->name(),
            relativePath: (string) $entry['file'],
            line: $line,
            symbol: (string) $entry['symbol'],
            patternType: (string) $entry['pattern_type'],
            resource: $resource,
            expectedScope: (string) $entry['expected_scope'],
            expectedFix: (string) $entry['expected_fix'],
            severity: (string) $entry['severity'],
            fiscalPath: (bool) ($entry['fiscal_path'] ?? false),
            crossModule: (bool) ($entry['cross_module'] ?? false),
        );
    }
}
