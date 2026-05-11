<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Sweep\Domain\InventoryDocument;
use App\Application\Sweep\Scanners\CallsiteRow;
use App\Application\Sweep\Scanners\ClusterResolver;
use App\Application\Sweep\Scanners\ManualScanner;
use App\Application\Sweep\Scanners\PhpAstFindScanner;
use App\Application\Sweep\Scanners\PhpPresentationExistsScanner;
use App\Application\Sweep\Scanners\Scanner;
use Throwable;

/**
 * `php artisan sweep:inventory:status` (READ-ONLY).
 *
 * Master plan reference: 2026-05-02-tenant-isolation-master-plan.md
 *   Section 4 — workflow state machine + `progress` block schema.
 *
 * Three modes (mutually exclusive — default is taken when no flag is set):
 *
 * - DEFAULT (no flags) — print a human-friendly summary derived from the
 *   YAML's `progress` block (totals, by_status, by_surface, by_owner, drift).
 *   Always exit 0.
 *
 * - --unmapped — enumerate every callsite whose `cluster_id ===
 *   ClusterResolver::DEFAULT_FALLBACK_CLUSTER_ID` ("api.unmapped"). Always
 *   exit 0; this surface is informational and is not a security regression.
 *
 * - --drift — re-run the same three scanners SweepInventoryGenerateCommand
 *   uses (Php{Presentation,Ast}…+Manual) and compare the emitted stable_keys
 *   to what the YAML records. Three counts are surfaced:
 *     * `yaml_says_fixed_code_unsafe`: YAML rows with status=fixed whose
 *       stable_key still appears in the scanner output. This is a security
 *       regression (a fix that didn't actually remove the unsafe pattern)
 *       and triggers a non-zero exit.
 *     * `code_safe_yaml_pending`: YAML rows in any pre-fixed state whose
 *       stable_key is absent from the scanner output. Likely fixed in code
 *       but the YAML was never updated; informational.
 *     * `unmapped_in_scanner_output`: scanner rows whose cluster_id is the
 *       fallback. Informational.
 *
 * The command is READ-ONLY: it calls InventoryService::load() exclusively
 * and never invokes mutate(). The class extends AbstractSweepInventoryCommand
 * solely to reuse path-resolution + the Scanner-construction helpers; the
 * inherited makeMutationContext() / makeHistoryEvent() helpers are not used.
 *
 * @phpstan-import-type Callsite from InventoryDocument
 * @phpstan-import-type Cluster from InventoryDocument
 * @phpstan-import-type ProgressBlock from InventoryDocument
 *
 * @cross-tenant-by-design Tenant-isolation sweep tooling; reads the inventory YAML metadata to report cluster + callsite progress.
 */
final class SweepInventoryStatusCommand extends AbstractSweepInventoryCommand
{
    /** @var string */
    protected $signature = 'sweep:inventory:status
        {--inventory-path= : path to YAML (overrides default for tests)}
        {--schema-path= : path to JSON Schema (overrides default for tests)}
        {--actor=human : declared for AbstractSweepInventoryCommand::resolveActor() static-analysis compatibility; not used by this read-only command}
        {--scan-root= : Override the scan root directory (defaults to app/Modules) — only used with --drift}
        {--repo-root= : Override the repo root used to relativize scanner paths (defaults to walking up from base_path) — only used with --drift, primarily for tests}
        {--manual-stub= : Override the manual-callsites stub path (defaults to docs/superpowers/plans/tenant-isolation-sweep-manual-callsites.yml) — only used with --drift}
        {--drift : Re-run scanners and compare to YAML; exits non-zero if yaml_says_fixed_code_unsafe > 0}
        {--unmapped : Enumerate callsites whose cluster_id is the api.unmapped fallback}';

    /** @var string */
    protected $description = 'Read-only summary of the tenant-isolation sweep inventory (default | --unmapped | --drift).';

    /**
     * Read-only command. AbstractSweepInventoryCommand's docblock pins the
     * contract: read-only commands return null here so makeMutationContext()
     * refuses to be called (would throw if invoked).
     */
    protected function actionVerb(): ?string
    {
        return null;
    }

    /**
     * The command name is recorded in error messages and is required by the
     * abstract base class even though no history events are written.
     */
    protected function commandName(): string
    {
        return 'sweep:inventory:status';
    }

    public function handle(): int
    {
        $service = $this->makeInventoryService();

        try {
            $doc = $service->load();
        } catch (Throwable $t) {
            $this->error('Failed to load inventory: '.$t->getMessage());

            return self::FAILURE;
        }

        if ($this->boolFlag('drift')) {
            return $this->handleDrift($doc);
        }
        if ($this->boolFlag('unmapped')) {
            return $this->handleUnmapped($doc);
        }

        return $this->handleDefault($doc);
    }

    /**
     * Print the summary derived from the YAML's `progress` block + metadata.
     */
    private function handleDefault(InventoryDocument $doc): int
    {
        $data = $doc->toArray();
        $metadata = $data['metadata'];
        $progress = $data['progress'];

        $shortHash = substr($metadata['yaml_sha256'], 0, 12).'...';

        $this->line('Tenant-isolation sweep status');
        $this->line('=============================');
        $this->line(sprintf('Generated:     %s by %s', $metadata['generated_at'], $metadata['generated_by']));
        $this->line(sprintf('YAML SHA-256:  %s', $shortHash));
        $this->line(sprintf('Branch:        %s', $metadata['branch']));
        $this->line(sprintf('Schema:        v%s', $metadata['schema_version']));
        $this->line('');

        $this->line('Totals');
        $this->line('------');
        $this->line(sprintf('Callsites:  %d', $progress['total_callsites']));
        $this->line(sprintf('Clusters:   %d', $progress['total_clusters']));
        $this->line('');

        $this->line('By status');
        $this->line('---------');
        foreach ($progress['by_status'] as $status => $count) {
            $this->line(sprintf('%-15s %d', $status.':', $count));
        }
        $this->line('');

        $this->line('By surface');
        $this->line('----------');
        foreach ($progress['by_surface'] as $surface => $stats) {
            $this->line(sprintf('%s: total=%d fixed=%d', $surface, $stats['total'], $stats['fixed']));
        }
        $this->line('');

        $this->line('By owner');
        $this->line('--------');
        foreach ($progress['by_owner'] as $owner => $entry) {
            if (is_array($entry)) {
                $this->line(sprintf('%-10s claimed=%d fixed=%d', $owner.':', $entry['claimed'], $entry['fixed']));

                continue;
            }
            $this->line(sprintf('%-10s %d', $owner.':', $entry));
        }
        $this->line('');

        $this->line('Drift (last computed)');
        $this->line('---------------------');
        $this->line(sprintf('yaml_says_fixed_code_unsafe: %d', $progress['drift']['yaml_says_fixed_code_unsafe']));
        $this->line(sprintf('code_safe_yaml_pending:      %d', $progress['drift']['code_safe_yaml_pending']));

        return self::SUCCESS;
    }

    /**
     * Enumerate callsites in the api.unmapped fallback cluster.
     */
    private function handleUnmapped(InventoryDocument $doc): int
    {
        $fallback = ClusterResolver::DEFAULT_FALLBACK_CLUSTER_ID;
        $matches = array_values(array_filter(
            $doc->callsites(),
            static fn (array $cs): bool => $cs['cluster_id'] === $fallback,
        ));

        $this->line(sprintf('Unmapped callsites (cluster_id=%s)', $fallback));
        $this->line('-----------------------------------------------------');

        if ($matches === []) {
            $this->line(sprintf('No callsites in %s cluster.', $fallback));

            return self::SUCCESS;
        }

        foreach ($matches as $cs) {
            $line = $cs['line'] !== null ? (string) $cs['line'] : '?';
            $resource = $cs['resource'] ?? '<no-resource>';
            $this->line(sprintf('%s  %s:%s  resource=%s', $cs['id'], $cs['file'], $line, $resource));
        }
        $this->line('');
        $this->line(sprintf('%d callsite(s) need re-classification under a real cluster.', count($matches)));

        return self::SUCCESS;
    }

    /**
     * Re-run scanners and compare emitted stable_keys to YAML state.
     * Exit non-zero when at least one fixed YAML row still appears in the
     * scanner output (security regression).
     */
    private function handleDrift(InventoryDocument $doc): int
    {
        $repoRoot = $this->stringOption('repo-root') ?? $this->resolveRepoRoot();
        $scanRoot = $this->stringOption('scan-root') ?? base_path('app/Modules');
        $manualStub = $this->stringOption('manual-stub')
            ?? $repoRoot.'/docs/superpowers/plans/tenant-isolation-sweep-manual-callsites.yml';

        $resolver = new ClusterResolver(
            ClusterResolver::defaultModuleToClusterMap(),
            ClusterResolver::DEFAULT_FALLBACK_CLUSTER_ID,
        );
        /** @var list<Scanner> $scanners */
        $scanners = [
            new PhpPresentationExistsScanner($scanRoot, $repoRoot, $resolver),
            new PhpAstFindScanner($scanRoot, $repoRoot, $resolver),
            new ManualScanner($manualStub),
        ];

        /** @var list<CallsiteRow> $rows */
        $rows = [];
        foreach ($scanners as $scanner) {
            try {
                $rows = array_merge($rows, $scanner->scan());
            } catch (Throwable $t) {
                $this->warn(sprintf('Scanner %s failed: %s', $scanner->name(), $t->getMessage()));
            }
        }

        $scannerKeys = [];
        foreach ($rows as $row) {
            $scannerKeys[$row->stableKey] = true;
        }

        $yamlSaysFixedCodeUnsafe = 0;
        $codeSafeYamlPending = 0;
        $preFixedStatuses = ['pending', 'claimed', 'in_progress', 'under_review'];

        foreach ($doc->callsites() as $cs) {
            $isInScanner = isset($scannerKeys[$cs['stable_key']]);
            if ($cs['status'] === 'fixed' && $isInScanner) {
                $yamlSaysFixedCodeUnsafe++;

                continue;
            }
            if (in_array($cs['status'], $preFixedStatuses, true) && ! $isInScanner) {
                $codeSafeYamlPending++;
            }
        }

        $unmappedInScannerOutput = 0;
        foreach ($rows as $row) {
            if ($row->clusterId === ClusterResolver::DEFAULT_FALLBACK_CLUSTER_ID) {
                $unmappedInScannerOutput++;
            }
        }

        $this->line('Drift report (re-scan vs YAML)');
        $this->line('------------------------------');
        $this->line(sprintf('yaml_says_fixed_code_unsafe: %d', $yamlSaysFixedCodeUnsafe));
        $this->line(sprintf('code_safe_yaml_pending:      %d', $codeSafeYamlPending));
        $this->line(sprintf('unmapped_in_scanner_output:  %d', $unmappedInScannerOutput));

        if ($yamlSaysFixedCodeUnsafe > 0) {
            $this->error(sprintf(
                '%d callsite(s) marked fixed in YAML are still detected by the scanner. '.
                'This is a security regression — investigate and re-fix or revert the status.',
                $yamlSaysFixedCodeUnsafe,
            ));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
