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
use App\Application\Sweep\Scanners\TanstackKeysScanner;
use InvalidArgumentException;
use Throwable;

/**
 * `php artisan sweep:inventory:recheck-clear`
 *
 * Closes the narrow `needs_recheck` state produced by inventory regeneration
 * when a row's relevant scanner no longer emits the row's stable key.
 *
 * @phpstan-import-type Callsite from InventoryDocument
 * @phpstan-import-type Cluster from InventoryDocument
 *
 * @cross-tenant-by-design Tenant-isolation sweep tooling; operates on the inventory YAML metadata, not on tenant data.
 */
final class SweepInventoryRecheckClearCommand extends AbstractSweepInventoryCommand
{
    /**
     * @var list<string>
     */
    private const ALLOWED_VERDICTS = [
        'APPROVE',
        'APPROVE-WITH-MINOR-EDITS-APPLIED',
    ];

    /** @var string */
    protected $signature = 'sweep:inventory:recheck-clear
        {--inventory-path= : path to YAML (overrides default for tests)}
        {--schema-path= : path to JSON Schema (overrides default for tests)}
        {--scan-root= : Override the scan root directory (defaults to app/Modules)}
        {--repo-root= : Override the repo root used to relativize scanner paths (defaults to walking up from base_path)}
        {--manual-stub= : Override the manual-callsites stub path (defaults to docs/superpowers/plans/tenant-isolation-sweep-manual-callsites.yml)}
        {--callsite-id= : needs_recheck callsite to clear}
        {--actor= : claude|codex|ci|human. MUST differ from callsite owner.}
        {--verdict= : APPROVE|APPROVE-WITH-MINOR-EDITS-APPLIED}
        {--review-file= : path to the review markdown (must exist on disk)}
        {--review-commit= : git SHA the verdict pins to; must match callsite fix_commit}
        {--reason= : why scanner silence is sufficient to close this needs_recheck row}';

    /** @var string */
    protected $description = 'Clear a needs_recheck inventory row once its relevant scanner no longer emits the stable key.';

    protected function actionVerb(): string
    {
        return 'review';
    }

    protected function commandName(): string
    {
        return 'sweep:inventory:recheck-clear';
    }

    public function handle(): int
    {
        $callsiteId = $this->stringOption('callsite-id');
        if ($callsiteId === null) {
            $this->error('You must supply --callsite-id.');

            return self::FAILURE;
        }

        if ($this->stringOption('actor') === null) {
            $this->error('You must supply --actor, one of: '.implode(', ', self::ALLOWED_ACTORS).'.');

            return self::FAILURE;
        }

        $verdict = $this->stringOption('verdict');
        if ($verdict === null) {
            $this->error('You must supply --verdict, one of: '.implode(', ', self::ALLOWED_VERDICTS).'.');

            return self::FAILURE;
        }
        if (! in_array($verdict, self::ALLOWED_VERDICTS, true)) {
            $this->error(
                "Invalid --verdict '{$verdict}'. Allowed values: ".implode(', ', self::ALLOWED_VERDICTS).'.',
            );

            return self::FAILURE;
        }

        $reviewFile = $this->stringOption('review-file');
        if ($reviewFile === null) {
            $this->error('You must supply --review-file (path to the review markdown).');

            return self::FAILURE;
        }

        $reviewCommit = $this->stringOption('review-commit');
        if ($reviewCommit === null) {
            $this->error('You must supply --review-commit; it must identify the callsite fix_commit.');

            return self::FAILURE;
        }

        $reason = $this->stringOption('reason');
        if ($reason === null) {
            $this->error('You must supply --reason explaining why scanner silence is sufficient.');

            return self::FAILURE;
        }

        try {
            $actor = $this->resolveActor();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($actor !== 'claude' && $actor !== 'codex') {
            $this->error(
                "Review refused: --actor '{$actor}' is not a recognized review agent. ".
                'Only claude or codex may write a review (schema review.reviewer enum).',
            );

            return self::FAILURE;
        }

        $service = $this->makeInventoryService();
        try {
            $doc = $service->load();
        } catch (Throwable $t) {
            $this->error('Failed to load inventory: '.$t->getMessage());

            return self::FAILURE;
        }

        $callsite = $this->findCallsite($doc, $callsiteId);
        if ($callsite === null) {
            $this->error("Callsite not found: {$callsiteId}");

            return self::FAILURE;
        }

        if ($callsite['status'] !== 'needs_recheck') {
            $this->error(
                "Callsite {$callsiteId} has status '{$callsite['status']}'; ".
                'only needs_recheck callsites can be cleared.',
            );

            return self::FAILURE;
        }

        $scannerError = $this->assertRelevantScannerIsSilent($callsite);
        if ($scannerError !== null) {
            $this->error($scannerError);

            return self::FAILURE;
        }

        if (! is_file($reviewFile)) {
            $this->error("Review file does not exist on disk: {$reviewFile}");

            return self::FAILURE;
        }

        $callsiteFixCommit = $callsite['fix_commit'];
        if ($callsiteFixCommit === null) {
            $this->error("Callsite {$callsiteId} has no fix_commit; cannot pin --review-commit.");

            return self::FAILURE;
        }
        if (! $this->commitIdentifierMatchesStored($reviewCommit, [$callsiteFixCommit])) {
            $this->error(
                "Commit linkage check failed — --review-commit '{$reviewCommit}' does not identify ".
                "callsite fix_commit '{$callsiteFixCommit}'.",
            );

            return self::FAILURE;
        }

        $cluster = $this->findCluster($doc, $callsite['cluster_id']);
        if ($cluster === null) {
            $this->error("Cluster not found for callsite: {$callsite['cluster_id']}");

            return self::FAILURE;
        }

        $owner = $callsite['owner'];
        if ($owner !== null && $owner === $actor) {
            $this->error(
                "Review refused: --actor '{$actor}' equals callsite owner '{$owner}'. ".
                "Cluster '{$cluster['id']}' has review_gate.reviewer_must_differ_from_owner=true; ".
                'a different agent must run the review. There is NO --force override for this check.',
            );

            return self::FAILURE;
        }

        $reviewedAt = gmdate('Y-m-d\TH:i:s\Z');
        $clusterId = $cluster['id'];
        $context = $this->makeMutationContext($actor);

        try {
            $service->mutate(
                function (InventoryDocument $live) use (
                    $callsiteId, $clusterId, $actor, $verdict, $reviewedAt, $reviewFile, $reviewCommit, $reason
                ): InventoryDocument {
                    $live = $live->withCallsiteUpdate(
                        $callsiteId,
                        function (array $cs) use (
                            $callsiteId, $actor, $verdict, $reviewedAt, $reviewFile, $reviewCommit, $reason
                        ): array {
                            $cs['status'] = 'fixed';
                            $cs['review'] = [
                                'reviewer' => $actor,
                                'verdict' => $verdict,
                                'reviewed_at' => $reviewedAt,
                                'review_file' => $reviewFile,
                                'review_commit' => $reviewCommit,
                            ];
                            $cs['history'][] = $this->makeHistoryEvent(
                                action: 'review',
                                fromStatus: 'needs_recheck',
                                toStatus: 'fixed',
                                targetIds: [$callsiteId],
                                note: $reason,
                                reviewFile: $reviewFile,
                                reviewCommit: $reviewCommit,
                            );

                            return $cs;
                        },
                    );

                    foreach ($live->callsites() as $sibling) {
                        if ($sibling['cluster_id'] === $clusterId && $sibling['status'] !== 'fixed') {
                            return $live;
                        }
                    }

                    return $live->withClusterUpdate(
                        $clusterId,
                        static function (array $cluster): array {
                            $cluster['status'] = 'fixed';

                            return $cluster;
                        },
                    );
                },
                $context,
            );
        } catch (Throwable $t) {
            $this->error('Failed to persist recheck-clear: '.$t->getMessage());

            return self::FAILURE;
        }

        $this->line("Cleared needs_recheck callsite {$callsiteId} (verdict={$verdict}, actor={$actor}, status→fixed).");

        return self::SUCCESS;
    }

    /**
     * @param  Callsite  $callsite
     */
    private function assertRelevantScannerIsSilent(array $callsite): ?string
    {
        try {
            $scanner = $this->scannerForCallsite($callsite);
            /** @var list<CallsiteRow> $rows */
            $rows = $scanner->scan();
        } catch (Throwable $t) {
            return sprintf(
                'Relevant scanner %s failed while rechecking stable_key %s: %s',
                (string) $callsite['scanner'],
                $callsite['stable_key'],
                $t->getMessage(),
            );
        }

        foreach ($rows as $row) {
            if ($row->stableKey === $callsite['stable_key']) {
                return sprintf(
                    'Relevant scanner %s still emits stable_key %s; fix the code or enhance the scanner before clearing needs_recheck.',
                    $scanner->name(),
                    $callsite['stable_key'],
                );
            }
        }

        return null;
    }

    /**
     * @param  Callsite  $callsite
     */
    private function scannerForCallsite(array $callsite): Scanner
    {
        $repoRoot = $this->stringOption('repo-root') ?? $this->resolveRepoRoot();
        $scanRoot = $this->stringOption('scan-root') ?? base_path('app/Modules');
        $manualStub = $this->stringOption('manual-stub')
            ?? $repoRoot.'/docs/superpowers/plans/tenant-isolation-sweep-manual-callsites.yml';
        $resolver = new ClusterResolver(
            ClusterResolver::defaultModuleToClusterMap(),
            ClusterResolver::DEFAULT_FALLBACK_CLUSTER_ID,
        );

        return match ($callsite['scanner']) {
            'php_presentation_exists' => new PhpPresentationExistsScanner($scanRoot, $repoRoot, $resolver),
            'php_ast_find' => new PhpAstFindScanner($scanRoot, $repoRoot, $resolver),
            'ts_query_key' => new TanstackKeysScanner($repoRoot),
            'manual' => new ManualScanner($manualStub),
            default => throw new InvalidArgumentException(
                "No recheck scanner is registered for scanner '{$callsite['scanner']}'.",
            ),
        };
    }
}
