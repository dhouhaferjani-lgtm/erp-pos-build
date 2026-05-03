<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Sweep\Domain\InventoryDocument;
use App\Application\Sweep\InventoryService;
use DateTime;
use InvalidArgumentException;
use Throwable;

/**
 * `php artisan sweep:inventory:defer`
 *
 * Transitions one callsite or an entire cluster from `pending` or `claimed`
 * to `deferred`, recording the reason and revisit date in the history event
 * note. Refuses any status outside pending|claimed (in_progress and beyond
 * should be blocked rather than deferred).
 *
 * Master plan reference:
 *   Section 4 — workflow state machine (defer: pending|claimed → deferred).
 *
 * Modes:
 *   --callsite-id  Defer a single callsite.
 *   --cluster      Defer the cluster AND every pending|claimed callsite within
 *                  it. Refuses when no eligible callsites exist (audit-trail
 *                  invariant: a cluster-status flip without callsite history
 *                  events is invisible to verify-history).
 *
 * Required:
 *   --reason          non-empty string.
 *   --revisit-date    strict YYYY-MM-DD format, must parse to a valid calendar date.
 *
 * Schema note: there is no revisit_date field on Callsite or Cluster.
 * The revisit date is encoded in the history event's note field as:
 *   "<reason>; revisit by <YYYY-MM-DD>"
 * Do NOT add a new field to the typed shapes — that would be scope creep.
 *
 * @phpstan-import-type Callsite from InventoryDocument
 * @phpstan-import-type Cluster from InventoryDocument
 * @phpstan-import-type HistoryEvent from InventoryDocument
 */
final class SweepInventoryDeferCommand extends AbstractSweepInventoryCommand
{
    /** @var string */
    protected $signature = 'sweep:inventory:defer
        {--inventory-path= : path to YAML (overrides default for tests)}
        {--schema-path= : path to JSON Schema (overrides default for tests)}
        {--callsite-id= : defer a single callsite}
        {--cluster= : defer every pending|claimed callsite in the cluster + the cluster itself}
        {--reason= : human-readable reason for the deferral (required)}
        {--revisit-date= : YYYY-MM-DD date to revisit (required)}
        {--actor=human : claude|codex|ci|human (NOT generator — reserved)}';

    /** @var string */
    protected $description = 'Defer a callsite or cluster (pending|claimed → deferred).';

    protected function actionVerb(): string
    {
        return 'defer';
    }

    protected function commandName(): string
    {
        return 'sweep:inventory:defer';
    }

    public function handle(): int
    {
        // ── 1. Validate mutual-exclusion of --callsite-id / --cluster ──────────
        $callsiteId = $this->stringOption('callsite-id');
        $clusterId = $this->stringOption('cluster');

        if ($callsiteId === null && $clusterId === null) {
            $this->error('You must supply either --callsite-id or --cluster (not neither).');

            return self::FAILURE;
        }
        if ($callsiteId !== null && $clusterId !== null) {
            $this->error('You must supply --callsite-id OR --cluster, not both.');

            return self::FAILURE;
        }

        // ── 2. Resolve actor ────────────────────────────────────────────────
        try {
            $actor = $this->resolveActor();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // ── 3. Validate --reason ────────────────────────────────────────────
        $reason = $this->stringOption('reason');
        if ($reason === null || $reason === '') {
            $this->error('--reason is required and must be non-empty.');

            return self::FAILURE;
        }

        // ── 4. Validate --revisit-date (strict YYYY-MM-DD) ──────────────────
        $revisitDate = $this->stringOption('revisit-date');
        if ($revisitDate === null || $revisitDate === '') {
            $this->error('--revisit-date is required and must be a valid date in YYYY-MM-DD format.');

            return self::FAILURE;
        }

        if (! $this->isValidYmdDate($revisitDate)) {
            $this->error(
                "--revisit-date '{$revisitDate}' is not a valid date. ".
                'Use strict YYYY-MM-DD format (e.g. 2026-06-15).',
            );

            return self::FAILURE;
        }

        // Defer means "revisit later"; a date in the past defeats the purpose
        // and is almost always a typo (e.g. wrong year). Refuse with a clear
        // message; operators who genuinely want to record an already-elapsed
        // revisit window should use sweep:inventory:block instead.
        if ($revisitDate < gmdate('Y-m-d')) {
            $this->error(
                "--revisit-date '{$revisitDate}' is in the past. ".
                'Defer requires a future revisit date; pick today (UTC) or later, '.
                'or use sweep:inventory:block if the work is no longer eligible to revisit.',
            );

            return self::FAILURE;
        }

        // ── 5. Load the document ────────────────────────────────────────────
        $service = $this->makeInventoryService();

        try {
            $doc = $service->load();
        } catch (Throwable $t) {
            $this->error('Failed to load inventory: '.$t->getMessage());

            return self::FAILURE;
        }

        // ── 6. Dispatch ─────────────────────────────────────────────────────
        $note = "{$reason}; revisit by {$revisitDate}";

        if ($callsiteId !== null) {
            return $this->handleCallsiteDefer($doc, $callsiteId, $note, $actor, $service);
        }

        /** @var string $clusterId */
        return $this->handleClusterDefer($doc, $clusterId, $note, $actor, $service);
    }

    /**
     * Defer a single callsite (pending|claimed → deferred). The cluster's
     * status is NOT touched in single-callsite mode.
     *
     * @param  'claude'|'codex'|'ci'|'human'  $actor
     */
    private function handleCallsiteDefer(
        InventoryDocument $doc,
        string $callsiteId,
        string $note,
        string $actor,
        InventoryService $service,
    ): int {
        $callsite = $this->findCallsite($doc, $callsiteId);
        if ($callsite === null) {
            $this->error("Callsite not found: {$callsiteId}");

            return self::FAILURE;
        }

        $currentStatus = $callsite['status'];

        // State precondition: only pending or claimed may be deferred.
        if ($currentStatus !== 'pending' && $currentStatus !== 'claimed') {
            $this->error(
                "Callsite {$callsiteId} has status '{$currentStatus}'; ".
                'only pending or claimed callsites can be deferred. '.
                'Use sweep:inventory:block for in_progress or later stages.',
            );

            return self::FAILURE;
        }

        $context = $this->makeMutationContext($actor);

        try {
            $service->mutate(
                function (InventoryDocument $live) use ($callsiteId, $currentStatus, $note): InventoryDocument {
                    return $live->withCallsiteUpdate(
                        $callsiteId,
                        function (array $cs) use ($callsiteId, $currentStatus, $note): array {
                            $cs['status'] = 'deferred';
                            $cs['history'][] = $this->makeHistoryEvent(
                                action: 'defer',
                                fromStatus: $currentStatus,
                                toStatus: 'deferred',
                                targetIds: [$callsiteId],
                                note: $note,
                            );

                            return $cs;
                        },
                    );
                },
                $context,
            );
        } catch (Throwable $t) {
            $this->error('Failed to persist defer: '.$t->getMessage());

            return self::FAILURE;
        }

        $this->line("Deferred callsite {$callsiteId} (actor={$actor}).");

        return self::SUCCESS;
    }

    /**
     * Defer the cluster and every pending|claimed callsite within it.
     * Refuses when there are no eligible callsites (audit-trail invariant).
     *
     * @param  'claude'|'codex'|'ci'|'human'  $actor
     */
    private function handleClusterDefer(
        InventoryDocument $doc,
        string $clusterId,
        string $note,
        string $actor,
        InventoryService $service,
    ): int {
        $cluster = $this->findCluster($doc, $clusterId);
        if ($cluster === null) {
            $this->error("Cluster not found: {$clusterId}");

            return self::FAILURE;
        }

        // Collect eligible callsites: those that are pending or claimed.
        $eligibleCallsites = array_values(array_filter(
            $this->callsitesInCluster($doc, $clusterId),
            static fn (array $cs): bool => $cs['status'] === 'pending' || $cs['status'] === 'claimed',
        ));

        // Audit-trail invariant: every cluster-status mutation must be anchored
        // in at least one callsite history event so verify-history can trace it.
        if ($eligibleCallsites === []) {
            $this->error(
                "Cluster {$clusterId} has no pending or claimed callsites to defer. ".
                'Use --callsite-id on individual callsites, or use sweep:inventory:block '.
                'if callsites are in a later stage (in_progress, under_review).',
            );

            return self::FAILURE;
        }

        /** @var list<array{id: string, status: string}> $eligibleData */
        $eligibleData = array_map(
            static fn (array $cs): array => ['id' => $cs['id'], 'status' => $cs['status']],
            $eligibleCallsites,
        );

        $context = $this->makeMutationContext($actor);

        try {
            $service->mutate(
                function (InventoryDocument $live) use ($clusterId, $note, $eligibleData): InventoryDocument {
                    // Update cluster status.
                    $live = $live->withClusterUpdate(
                        $clusterId,
                        static function (array $c): array {
                            $c['status'] = 'deferred';

                            return $c;
                        },
                    );

                    // Update each eligible callsite.
                    foreach ($eligibleData as $entry) {
                        $csId = $entry['id'];
                        $prevStatus = $entry['status'];
                        $live = $live->withCallsiteUpdate(
                            $csId,
                            function (array $cs) use ($csId, $prevStatus, $note): array {
                                $cs['status'] = 'deferred';
                                $cs['history'][] = $this->makeHistoryEvent(
                                    action: 'defer',
                                    fromStatus: $prevStatus,
                                    toStatus: 'deferred',
                                    targetIds: [$csId],
                                    note: $note,
                                );

                                return $cs;
                            },
                        );
                    }

                    return $live;
                },
                $context,
            );
        } catch (Throwable $t) {
            $this->error('Failed to persist cluster defer: '.$t->getMessage());

            return self::FAILURE;
        }

        $this->line(
            "Deferred cluster {$clusterId} (actor={$actor}); ".
            count($eligibleData).' eligible callsite(s) transitioned.',
        );

        return self::SUCCESS;
    }

    /**
     * Strict YYYY-MM-DD date validation.
     *
     * Uses DateTime::createFromFormat with the 'Y-m-d' mask and verifies:
     *   1. The parse succeeded (createFromFormat did not return false).
     *   2. The formatted output equals the original input — this catches
     *      inputs like '2026-13-01' that PHP normalises to a different date
     *      (e.g. '2027-01-01') rather than returning false.
     *
     * Both checks together reject freeform strings ("tomorrow"), wrong
     * separators ("2026/05/01"), out-of-range components ("2026-13-99"),
     * and empty strings (handled by the caller before this method is reached).
     */
    private function isValidYmdDate(string $value): bool
    {
        $parsed = DateTime::createFromFormat('Y-m-d', $value);
        if ($parsed === false) {
            return false;
        }

        return $parsed->format('Y-m-d') === $value;
    }
}
