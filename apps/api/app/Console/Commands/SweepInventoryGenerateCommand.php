<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Sweep\Domain\InventoryDocument;
use App\Application\Sweep\Domain\MutationContext;
use App\Application\Sweep\InventoryService;
use App\Application\Sweep\Scanners\CallsiteRow;
use App\Application\Sweep\Scanners\ClusterResolver;
use App\Application\Sweep\Scanners\ManualScanner;
use App\Application\Sweep\Scanners\PhpAstFindScanner;
use App\Application\Sweep\Scanners\PhpPresentationExistsScanner;
use App\Application\Sweep\Scanners\Scanner;
use Illuminate\Console\Command;
use Throwable;

/**
 * `php artisan sweep:inventory:generate`
 *
 * Runs the Phase 1 scanners (PhpPresentationExistsScanner, PhpAstFindScanner,
 * ManualScanner) and merges their output into the live inventory at
 * docs/superpowers/plans/tenant-isolation-sweep-inventory.yml. Web (TS) and
 * POS (Tauri SQLite) scanners are Phase 2 and are deliberately not invoked
 * here.
 *
 * Merge semantics (master plan Section 4 — rename/move):
 *   - stable_key matches → preserve status/owner/review state on the row.
 *   - relative_path + symbol + resource match but stable_key differs →
 *     mark `status: needs_recheck`. Human revisits before clearing.
 *   - relative_path matches but symbol differs → old row gets
 *     `stale_state: stale_orphan`; new row created `pending`.
 *   - both relative_path and symbol differ → no merge attempt; old row
 *     stays as-is, new row created `pending`.
 *
 * --dry-run prints the diff to stderr without persisting.
 */
final class SweepInventoryGenerateCommand extends Command
{
    /** @var string */
    protected $signature = 'sweep:inventory:generate
        {--inventory-path= : Override the live inventory YAML path (defaults to docs/superpowers/plans/tenant-isolation-sweep-inventory.yml)}
        {--schema-path= : Override the JSON Schema path (defaults to app/Application/Sweep/InventoryYamlSchema.json)}
        {--scan-root= : Override the scan root directory (defaults to app/Modules)}
        {--manual-stub= : Override the manual-callsites stub path (defaults to docs/superpowers/plans/tenant-isolation-sweep-manual-callsites.yml)}
        {--dry-run : Print summary to stderr without writing the YAML}';

    /** @var string */
    protected $description = 'Regenerate the tenant-isolation sweep inventory by running scanners and merging output by stable key.';

    public function handle(): int
    {
        $repoRoot = $this->resolveRepoRoot();
        $inventoryPath = $this->stringOption('inventory-path')
            ?? $repoRoot.'/docs/superpowers/plans/tenant-isolation-sweep-inventory.yml';
        $schemaPath = $this->stringOption('schema-path')
            ?? base_path('app/Application/Sweep/InventoryYamlSchema.json');
        $scanRoot = $this->stringOption('scan-root')
            ?? base_path('app/Modules');
        $manualStub = $this->stringOption('manual-stub')
            ?? $repoRoot.'/docs/superpowers/plans/tenant-isolation-sweep-manual-callsites.yml';
        $isDryRun = (bool) $this->option('dry-run');

        $service = new InventoryService($inventoryPath, $schemaPath);

        try {
            $beforeDoc = $service->load();
        } catch (Throwable $t) {
            $this->error('Failed to load inventory: '.$t->getMessage());

            return self::FAILURE;
        }

        $resolver = new ClusterResolver(
            ClusterResolver::defaultModuleToClusterMap(),
            'api.identity-company',
        );
        $scanners = [
            new PhpPresentationExistsScanner($scanRoot, $repoRoot, $resolver),
            new PhpAstFindScanner($scanRoot, $repoRoot, $resolver),
            new ManualScanner($manualStub),
        ];

        /** @var list<CallsiteRow> $rows */
        $rows = [];
        foreach ($scanners as $scanner) {
            $rows = array_merge($rows, $this->runScanner($scanner));
        }

        $stats = $this->computeMergeStats($beforeDoc, $rows);

        $this->writeSummary($stats, isDryRun: $isDryRun);

        if ($isDryRun) {
            return self::SUCCESS;
        }

        try {
            $service->mutate(
                fn (InventoryDocument $doc): InventoryDocument => $this->applyMerge($doc, $rows),
                new MutationContext(
                    actor: 'claude',
                    command: 'sweep:inventory:generate',
                    action: 'regenerate',
                    gitCommit: $this->resolveGitHead($repoRoot),
                ),
            );
        } catch (Throwable $t) {
            $this->error('Failed to persist inventory mutation: '.$t->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return list<CallsiteRow>
     */
    private function runScanner(Scanner $scanner): array
    {
        try {
            return $scanner->scan();
        } catch (Throwable $t) {
            $this->warn(sprintf('Scanner %s failed: %s', $scanner->name(), $t->getMessage()));

            return [];
        }
    }

    /**
     * Compute counts for the summary line. Pure function over before-doc
     * + scanner output; does not mutate anything.
     *
     * @param  list<CallsiteRow>  $rows
     * @return array{new: int, unchanged: int, needs_recheck: int, stale_orphan: int}
     */
    private function computeMergeStats(InventoryDocument $beforeDoc, array $rows): array
    {
        $existingByKey = [];
        foreach ($beforeDoc->callsites() as $callsite) {
            $existingByKey[(string) ($callsite['stable_key'] ?? '')] = $callsite;
        }
        $existingByPathSymbol = [];
        foreach ($beforeDoc->callsites() as $callsite) {
            $key = $this->pathSymbolKey($callsite);
            $existingByPathSymbol[$key] = $callsite;
        }
        $existingByPath = [];
        foreach ($beforeDoc->callsites() as $callsite) {
            $key = (string) ($callsite['file'] ?? '');
            $existingByPath[$key][] = $callsite;
        }

        $new = 0;
        $unchanged = 0;
        $needsRecheck = 0;
        $staleOrphan = 0;
        $matchedExistingKeys = [];

        foreach ($rows as $row) {
            if (isset($existingByKey[$row->stableKey])) {
                $unchanged++;
                $matchedExistingKeys[$row->stableKey] = true;

                continue;
            }
            // Path + symbol + resource match → needs_recheck.
            $candidateKey = $row->relativePath.'::'.$row->symbol.'::'.($row->resource ?? '');
            if (isset($existingByPathSymbol[$candidateKey])) {
                $needsRecheck++;
                $matchedExistingKeys[(string) ($existingByPathSymbol[$candidateKey]['stable_key'] ?? '')] = true;

                continue;
            }
            // Path matches but symbol differs → existing row at that path
            // becomes stale_orphan, new row is pending. Count both.
            if (isset($existingByPath[$row->relativePath])) {
                foreach ($existingByPath[$row->relativePath] as $existing) {
                    $existingKey = (string) ($existing['stable_key'] ?? '');
                    if (! isset($matchedExistingKeys[$existingKey])) {
                        $matchedExistingKeys[$existingKey] = 'stale_orphan';
                        $staleOrphan++;
                    }
                }
                $new++;

                continue;
            }
            $new++;
        }

        return [
            'new' => $new,
            'unchanged' => $unchanged,
            'needs_recheck' => $needsRecheck,
            'stale_orphan' => $staleOrphan,
        ];
    }

    /**
     * Build the merged InventoryDocument from before-doc + scanner output.
     *
     * @param  list<CallsiteRow>  $rows
     */
    private function applyMerge(InventoryDocument $beforeDoc, array $rows): InventoryDocument
    {
        $existingByKey = [];
        foreach ($beforeDoc->callsites() as $callsite) {
            $existingByKey[(string) ($callsite['stable_key'] ?? '')] = $callsite;
        }
        $existingByPathSymbol = [];
        foreach ($beforeDoc->callsites() as $callsite) {
            $key = $this->pathSymbolKey($callsite);
            $existingByPathSymbol[$key] = $callsite;
        }
        $existingByPath = [];
        foreach ($beforeDoc->callsites() as $callsite) {
            $existingByPath[(string) ($callsite['file'] ?? '')][] = $callsite;
        }

        $generatedAt = gmdate('Y-m-d\TH:i:s\Z');
        $mergedById = [];
        foreach ($beforeDoc->callsites() as $callsite) {
            $mergedById[(string) ($callsite['id'] ?? '')] = $callsite;
        }

        // Per-cluster id counter, seeded from existing rows.
        $clusterCounters = $this->initClusterCounters($beforeDoc->callsites());
        $touchedExistingKeys = [];

        foreach ($rows as $row) {
            // Case 1: stable_key match → preserve everything except possibly
            // line metadata. The InventoryService stamping doesn't run on the
            // generate path because we don't append history events to
            // already-active rows; we only append for new/needs_recheck/stale.
            if (isset($existingByKey[$row->stableKey])) {
                $existing = $existingByKey[$row->stableKey];
                $existing['line'] = $row->line;
                $existing['stale_state'] = 'active';
                $mergedById[(string) ($existing['id'] ?? '')] = $existing;
                $touchedExistingKeys[$row->stableKey] = true;

                continue;
            }

            // Case 2: path + symbol + resource match → existing row goes
            // needs_recheck, stable_key updated to the new value.
            $candidateKey = $row->relativePath.'::'.$row->symbol.'::'.($row->resource ?? '');
            if (isset($existingByPathSymbol[$candidateKey])) {
                $existing = $existingByPathSymbol[$candidateKey];
                $existing['stable_key'] = $row->stableKey;
                $existing['status'] = 'needs_recheck';
                $existing['line'] = $row->line;
                $existing['history'][] = [
                    'at' => $generatedAt,
                    'actor' => null,
                    'action' => 'stale_mark',
                    'command' => null,
                    'previous_yaml_sha256' => null,
                    'new_yaml_sha256' => null,
                    'target_ids' => [(string) ($existing['id'] ?? '')],
                    'from_status' => (string) $existing['status'],
                    'to_status' => 'needs_recheck',
                    'commit' => null,
                    'test' => null,
                    'review_file' => null,
                    'review_commit' => null,
                    'note' => 'Stable key changed but path/symbol/resource unchanged — needs_recheck per master plan Section 4.',
                ];
                $mergedById[(string) ($existing['id'] ?? '')] = $existing;
                $touchedExistingKeys[(string) $existing['stable_key']] = true;

                continue;
            }

            // Case 3: path matches but symbol differs → existing row(s) at
            // that path go stale_orphan; new row is created pending.
            if (isset($existingByPath[$row->relativePath])) {
                foreach ($existingByPath[$row->relativePath] as $existing) {
                    $eKey = (string) ($existing['stable_key'] ?? '');
                    if (isset($touchedExistingKeys[$eKey])) {
                        continue;
                    }
                    $existing['stale_state'] = 'stale_orphan';
                    $existing['history'][] = [
                        'at' => $generatedAt,
                        'actor' => null,
                        'action' => 'stale_mark',
                        'command' => null,
                        'previous_yaml_sha256' => null,
                        'new_yaml_sha256' => null,
                        'target_ids' => [(string) ($existing['id'] ?? '')],
                        'from_status' => (string) $existing['status'],
                        'to_status' => (string) $existing['status'],
                        'commit' => null,
                        'test' => null,
                        'review_file' => null,
                        'review_commit' => null,
                        'note' => 'Symbol moved or renamed; new row created. See sibling pending row at same path.',
                    ];
                    $mergedById[(string) ($existing['id'] ?? '')] = $existing;
                    $touchedExistingKeys[$eKey] = true;
                }
            }

            // Create the new row.
            $callsiteId = $this->nextCallsiteId($row->clusterId, $clusterCounters);
            $newRow = $row->toInventoryRow($callsiteId, $generatedAt);
            $mergedById[$callsiteId] = $newRow;
        }

        // Re-flatten back into a list, preserving the existing order for
        // unchanged rows and appending newly-created ones at the end.
        $merged = [];
        $seenIds = [];
        foreach ($beforeDoc->callsites() as $callsite) {
            $id = (string) ($callsite['id'] ?? '');
            if (! isset($mergedById[$id])) {
                continue;
            }
            $merged[] = $mergedById[$id];
            $seenIds[$id] = true;
        }
        foreach ($mergedById as $id => $callsite) {
            if (isset($seenIds[$id])) {
                continue;
            }
            $merged[] = $callsite;
        }

        return $beforeDoc
            ->withGeneratedAt($generatedAt)
            ->withCallsites($merged);
    }

    /**
     * @param  list<array<string, mixed>>  $existing
     * @return array<string, int>
     */
    private function initClusterCounters(array $existing): array
    {
        $counters = [];
        foreach ($existing as $callsite) {
            $id = (string) ($callsite['id'] ?? '');
            if (preg_match('/^([a-z0-9.-]+)\.(\d+)$/', $id, $match) !== 1) {
                continue;
            }
            $cluster = $match[1];
            $n = (int) $match[2];
            if (! isset($counters[$cluster]) || $counters[$cluster] < $n) {
                $counters[$cluster] = $n;
            }
        }

        return $counters;
    }

    /**
     * @param  array<string, int>  $counters  Reference; mutated.
     */
    private function nextCallsiteId(string $clusterId, array &$counters): string
    {
        $counters[$clusterId] = ($counters[$clusterId] ?? 0) + 1;

        return sprintf('%s.%03d', $clusterId, $counters[$clusterId]);
    }

    /**
     * @param  array<string, mixed>  $callsite
     */
    private function pathSymbolKey(array $callsite): string
    {
        return (string) ($callsite['file'] ?? '').'::'
            .(string) ($callsite['symbol'] ?? '').'::'
            .(string) ($callsite['resource'] ?? '');
    }

    /**
     * @param  array{new: int, unchanged: int, needs_recheck: int, stale_orphan: int}  $stats
     */
    private function writeSummary(array $stats, bool $isDryRun): void
    {
        $prefix = $isDryRun ? '[dry-run] ' : '';
        fwrite(
            STDERR,
            sprintf(
                "%ssweep:inventory:generate — %d new, %d unchanged, %d needs_recheck, %d stale_orphan\n",
                $prefix,
                $stats['new'],
                $stats['unchanged'],
                $stats['needs_recheck'],
                $stats['stale_orphan'],
            ),
        );
    }

    /**
     * Resolves the repo root by walking up from base_path() until we find
     * the docs/superpowers/ directory. The Laravel base_path() points at
     * apps/api; the inventory file lives two levels above. If we can't
     * find docs/superpowers/, fall back to base_path()'s parent's parent.
     */
    private function resolveRepoRoot(): string
    {
        $cursor = base_path();
        for ($i = 0; $i < 5; $i++) {
            if (is_dir($cursor.'/docs/superpowers')) {
                return $cursor;
            }
            $cursor = dirname($cursor);
        }

        return dirname(base_path(), 2);
    }

    private function resolveGitHead(string $repoRoot): ?string
    {
        $headFile = $repoRoot.'/.git/HEAD';
        if (! is_file($headFile)) {
            return null;
        }
        $head = trim((string) file_get_contents($headFile));
        if (str_starts_with($head, 'ref: ')) {
            $ref = substr($head, 5);
            $refFile = $repoRoot.'/.git/'.$ref;
            if (is_file($refFile)) {
                return trim((string) file_get_contents($refFile));
            }

            return null;
        }

        return $head;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
