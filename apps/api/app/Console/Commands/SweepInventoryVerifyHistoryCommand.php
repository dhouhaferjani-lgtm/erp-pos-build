<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Sweep\Domain\InventoryDocument;
use Throwable;

/**
 * `php artisan sweep:inventory:verify-history` (READ-ONLY).
 *
 * Master plan reference: 2026-05-02-tenant-isolation-master-plan.md
 *   Section 4 lines 362-366 — CI hand-edit detection algorithm.
 *
 * The command walks every callsite's history array and checks four
 * invariants per event:
 *
 * 1. command MUST be non-null. The schema declares `command: string|null`
 *    so a hand-edit that bypassed the artisan CLI passes JSON-Schema
 *    validation; this gate is the only thing that catches it.
 *
 * 2. actor MUST be non-null. Same argument as command.
 *
 * 3. target_ids MUST be non-empty AND every id must exist in the
 *    inventory's current callsites or clusters list. Stale references
 *    indicate the YAML was edited without re-running generate / claim.
 *
 * 4. For events with index > 0 (i.e. not the seed/generate event):
 *    previous_yaml_sha256 MUST be non-null AND MUST equal the previous
 *    event's new_yaml_sha256. The seed event itself may have
 *    previous_yaml_sha256 = null because there is no preceding state. As a
 *    bounded relaxation, the very first event AFTER the seed (eventIndex==1)
 *    may declare an arbitrary non-null previous_yaml_sha256 even when the
 *    seed's new_yaml_sha256 is null — this accommodates the seed inventory
 *    pattern (live YAML created with zero callsites; the first generate run
 *    appends events with their own real chain hashes). The relaxation is
 *    intentionally NOT keyed on the prior event's `action`: an action-based
 *    predicate would let an attacker splice two events (a fake `regenerate`
 *    with null new hash + a malicious workflow event with any previous
 *    hash). Slot-based gating (eventIndex==1 only) closes that splice.
 *
 * 5. The file-level metadata.yaml_sha256 MUST equal the recomputed canonical
 *    hash of the document content (zero-out self-referential hash fields,
 *    serialise YAML, sha256). This is the defense against a hand-edit that
 *    only adjusts metadata.yaml_sha256 to its post-edit value: the chain
 *    check could otherwise be defeated when paired with a metadata bump.
 *
 * Cross-callsite chain ordering is intentionally NOT enforced — the
 * file-level metadata.yaml_sha256 (now verified by check #5) plus
 * InventoryService's optimistic-lock check already protect the inventory's
 * overall integrity.
 *
 * Behavior:
 *   - Print one line per failure citing callsite id + history index +
 *     which invariant failed.
 *   - Print a final summary: "verified N event(s) across M callsite(s);
 *     K problem(s)."
 *   - Exit 0 when K === 0; non-zero otherwise.
 *
 * The command is READ-ONLY: it calls InventoryService::load() exclusively
 * and never invokes mutate(). Subclasses AbstractSweepInventoryCommand
 * solely to reuse path-resolution; actionVerb() returns null so
 * makeMutationContext() refuses to be called.
 *
 * @phpstan-import-type Callsite from InventoryDocument
 * @phpstan-import-type Cluster from InventoryDocument
 * @phpstan-import-type HistoryEvent from InventoryDocument
 */
final class SweepInventoryVerifyHistoryCommand extends AbstractSweepInventoryCommand
{
    /** @var string */
    protected $signature = 'sweep:inventory:verify-history
        {--inventory-path= : path to YAML (overrides default for tests)}
        {--schema-path= : path to JSON Schema (overrides default for tests)}
        {--actor=human : declared for AbstractSweepInventoryCommand::resolveActor() static-analysis compatibility; not used by this read-only command}';

    /** @var string */
    protected $description = 'Walk every callsite history and verify the append-only command-event chain (CI gate).';

    /**
     * Read-only command. AbstractSweepInventoryCommand's docblock pins the
     * contract: read-only commands return null here so makeMutationContext()
     * refuses to be called.
     */
    protected function actionVerb(): ?string
    {
        return null;
    }

    /**
     * Required by the abstract base class even though no history events are
     * written. Only used in error-message context.
     */
    protected function commandName(): string
    {
        return 'sweep:inventory:verify-history';
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

        $knownIds = $this->collectKnownIds($doc);
        $callsites = $doc->callsites();

        $totalEvents = 0;
        $problems = 0;

        // File-level hand-edit defence: recompute the canonical content hash
        // and compare to metadata.yaml_sha256. A hand-edit that bumped both
        // the content AND metadata.yaml_sha256 (so InventoryService::mutate()
        // would still accept it on the next write) is otherwise invisible to
        // chain checks. See InventoryService::canonicalHashOf().
        $storedFileHash = $doc->yamlSha256();
        $recomputedFileHash = $service->canonicalHashOf($doc);
        if ($storedFileHash !== $recomputedFileHash) {
            $this->error(sprintf(
                '[metadata.yaml_sha256] file-level hash mismatch: stored=%s recomputed=%s. '.
                'The YAML appears to have been hand-edited; metadata.yaml_sha256 was '.
                'updated separately from the canonical content hash.',
                $storedFileHash,
                $recomputedFileHash,
            ));
            $problems++;
        }

        // Track every non-null new_yaml_sha256 seen across the document so we
        // can run the document-level anchor check after walking history. The
        // anchor check (Codex round-2 finding #6) catches a forged terminal
        // event whose new_yaml_sha256 is a valid-looking hex string but doesn't
        // correspond to any real mutate() — at least one event in the document
        // MUST have new_yaml_sha256 == metadata.yaml_sha256 because that's
        // what the most recent mutate() stamped.
        $observedNewHashes = [];

        foreach ($callsites as $callsite) {
            $callsiteId = $callsite['id'];
            $history = $callsite['history'];
            $previousNewHash = null;

            foreach ($history as $eventIndex => $event) {
                $totalEvents++;

                $eventProblems = $this->verifyEvent(
                    callsiteId: $callsiteId,
                    eventIndex: $eventIndex,
                    event: $event,
                    knownIds: $knownIds,
                    previousNewHash: $previousNewHash,
                );

                foreach ($eventProblems as $message) {
                    $this->error($message);
                    $problems++;
                }

                if ($event['new_yaml_sha256'] !== null) {
                    $observedNewHashes[$event['new_yaml_sha256']] = true;
                }

                $previousNewHash = $event['new_yaml_sha256'];
            }
        }

        // Document-level anchor check: the current metadata.yaml_sha256 MUST
        // appear as the new_yaml_sha256 of at least one history event. After
        // every legitimate mutate(), the freshly-stamped events carry exactly
        // that value. A forged event without a matching metadata bump (or
        // vice-versa) breaks the invariant. Combined with the file-level hash
        // recompute above (which catches metadata-only edits) and the per-event
        // chain + format checks, this closes the cheap forgery surfaces.
        // RESIDUAL GAP: a fully self-consistent forgery (where the attacker
        // recomputes canonicalForHashing, sets chain hashes correctly, and
        // updates metadata.yaml_sha256 to match) is NOT catchable by this
        // command alone. The master plan's Section 4 line 362 envisions a CI
        // gate that runs verify-history alongside a git-diff against the PR
        // base; the diff-based check is the layer that catches self-consistent
        // forgeries by requiring every new event to have been added by an
        // artisan-CLI invocation in the CI pipeline. Tracked separately.
        // The anchor check only applies once at least one mutate() has run
        // (i.e. at least one event has non-null new_yaml_sha256). On a fresh
        // seed-only document — every history is just the generator event with
        // null hashes — no anchor is expected; the file-level recompute above
        // is the only integrity check that fires.
        if ($observedNewHashes !== [] && ! isset($observedNewHashes[$storedFileHash])) {
            $this->error(sprintf(
                '[document-level anchor] metadata.yaml_sha256 (%s) does not appear as the new_yaml_sha256 of any history event. '.
                'Every legitimate mutate() stamps the freshly-computed file hash on the events it appends; '.
                'a missing anchor indicates a hand-edit (Codex round-2 finding #6).',
                $storedFileHash,
            ));
            $problems++;
        }

        $this->line(sprintf(
            'verified %d event(s) across %d callsite(s); %d problem(s).',
            $totalEvents,
            count($callsites),
            $problems,
        ));

        return $problems === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Run the four per-event invariants. Returns a list of human-readable
     * problem messages (one per failed check); an empty list means the
     * event is clean.
     *
     * @param  HistoryEvent  $event
     * @param  array<string, true>  $knownIds  callsite + cluster ids in the doc
     * @return list<string>
     */
    private function verifyEvent(
        string $callsiteId,
        int $eventIndex,
        array $event,
        array $knownIds,
        ?string $previousNewHash,
    ): array {
        $problems = [];

        if ($event['command'] === null) {
            $problems[] = sprintf(
                '[%s history[%d]] command is null — hand-edit detected (every history event must come from an artisan CLI).',
                $callsiteId,
                $eventIndex,
            );
        }

        if ($event['actor'] === null) {
            $problems[] = sprintf(
                '[%s history[%d]] actor is null — every history event must be attributed to a known actor.',
                $callsiteId,
                $eventIndex,
            );
        }

        $targetIds = $event['target_ids'];
        if ($targetIds === []) {
            $problems[] = sprintf(
                '[%s history[%d]] target_ids is empty — every history event must declare at least one affected id.',
                $callsiteId,
                $eventIndex,
            );
        }
        foreach ($targetIds as $targetId) {
            if (! isset($knownIds[$targetId])) {
                $problems[] = sprintf(
                    '[%s history[%d]] target_ids references unknown id %s — neither a callsite nor a cluster in the current document.',
                    $callsiteId,
                    $eventIndex,
                    $targetId,
                );
            }
        }

        if ($eventIndex > 0) {
            if ($event['previous_yaml_sha256'] === null) {
                $problems[] = sprintf(
                    '[%s history[%d]] previous_yaml_sha256 is null on a non-first event — chain is broken (only the seed event may have a null previous hash).',
                    $callsiteId,
                    $eventIndex,
                );
            } elseif (! $this->isCanonicalHashFormat($event['previous_yaml_sha256'])) {
                $problems[] = sprintf(
                    '[%s history[%d]] previous_yaml_sha256 %s is not a canonical 64-char lowercase hex hash — hand-edit indicator.',
                    $callsiteId,
                    $eventIndex,
                    $event['previous_yaml_sha256'],
                );
            } elseif ($previousNewHash !== null && $event['previous_yaml_sha256'] !== $previousNewHash) {
                $problems[] = sprintf(
                    '[%s history[%d]] chain is broken: previous_yaml_sha256 %s does not match the prior event new_yaml_sha256 %s.',
                    $callsiteId,
                    $eventIndex,
                    $event['previous_yaml_sha256'],
                    $previousNewHash,
                );
            } elseif ($previousNewHash === null && $eventIndex !== 1) {
                // The prior event had a null new_yaml_sha256, which is only
                // legitimate when it is the seed event at history[0]. Any
                // null new_yaml_sha256 on a later event (eventIndex > 1's
                // predecessor) is itself a hand-edit indicator. The narrower
                // predicate (gate on `eventIndex !== 1` rather than on the
                // prior action) closes the multi-event splice path.
                $problems[] = sprintf(
                    '[%s history[%d]] chain is broken: previous_yaml_sha256 %s declared but the prior event new_yaml_sha256 was null on a non-seed slot.',
                    $callsiteId,
                    $eventIndex,
                    $event['previous_yaml_sha256'],
                );
            }

            // Every non-seed event MUST have a canonical 64-char lowercase hex
            // new_yaml_sha256. Without this, a forged terminal event can survive
            // by either:
            //   - leaving new_yaml_sha256 = null (round-1 finding #2, fixed),
            //   - or supplying a malformed/short value that no successor checks
            //     because the forged event is terminal (round-2 finding #6).
            // Format validation against the schema's regex closes both surfaces;
            // the document-level anchor check below catches a fully-valid hex
            // value that doesn't correspond to any real mutate().
            if ($event['new_yaml_sha256'] === null) {
                $problems[] = sprintf(
                    '[%s history[%d]] new_yaml_sha256 is null on a non-seed event — every workflow / regenerate event must be stamped with the post-mutation hash by InventoryService::mutate(). A null hash here indicates a hand-edit.',
                    $callsiteId,
                    $eventIndex,
                );
            } elseif (! $this->isCanonicalHashFormat($event['new_yaml_sha256'])) {
                $problems[] = sprintf(
                    '[%s history[%d]] new_yaml_sha256 %s is not a canonical 64-char lowercase hex hash — hand-edit indicator (Codex round-2 finding #6).',
                    $callsiteId,
                    $eventIndex,
                    $event['new_yaml_sha256'],
                );
            }
        }

        return $problems;
    }

    /**
     * The schema declares chain hashes as `^[0-9a-f]{64}$|null`. Replicate the
     * pattern check here so verify-history rejects malformed hashes without
     * paying the cost of full JSON-Schema validation on every load.
     */
    private function isCanonicalHashFormat(?string $candidate): bool
    {
        if ($candidate === null) {
            return false;
        }

        return preg_match('/^[0-9a-f]{64}$/', $candidate) === 1;
    }

    /**
     * Build the set of valid target_id values: every callsite id plus every
     * cluster id present in the current document.
     *
     * @return array<string, true>
     */
    private function collectKnownIds(InventoryDocument $doc): array
    {
        $ids = [];
        foreach ($doc->callsites() as $callsite) {
            $ids[$callsite['id']] = true;
        }
        foreach ($doc->clusters() as $cluster) {
            $ids[$cluster['id']] = true;
        }

        return $ids;
    }
}
