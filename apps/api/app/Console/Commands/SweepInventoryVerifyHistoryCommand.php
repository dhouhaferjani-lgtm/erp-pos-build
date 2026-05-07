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
 *    previous_yaml_sha256 MUST be non-null AND MUST be a canonical 64-char
 *    lowercase hex hash AND MUST appear as some event's new_yaml_sha256
 *    somewhere in the document (the GLOBAL-ANCHOR check).
 *
 *    The chain is enforced GLOBALLY, not per-callsite. The naive per-callsite
 *    invariant ("event N's previous on callsite C must equal event N-1's new
 *    on callsite C") doesn't hold when interleaved single-callsite mutations
 *    advance the file hash between same-callsite events. Concrete failure case:
 *    after a cluster-mode `start` stamps every callsite with the SAME finalHash
 *    H_Y, sequentially submitting each callsite via single-callsite `submit`
 *    calls produces events with previous_yaml_sha256 = (file hash AT submit
 *    time) — which advances with every preceding submit. So callsite #2's
 *    submit.previous != callsite #2's start.new, even though the YAML evolved
 *    through legitimate mutate() calls. The Treasury sweep's 48 sequential
 *    submits exposed this concretely.
 *
 *    The global-anchor check tolerates the legitimate interleave AND still
 *    rejects forgeries: any forged event whose previous_yaml_sha256 doesn't
 *    appear elsewhere as a new_yaml_sha256 is referencing a YAML state that
 *    was never written, so it must be hand-edited. The chain integrity is
 *    enforced via the GRAPH of recorded states, not via per-callsite linear
 *    ordering.
 *
 *    Every non-seed event's new_yaml_sha256 MUST also be a canonical
 *    64-char lowercase hex hash (closes round-2 finding #6: a forged
 *    terminal event with valid-format-but-fake new_yaml_sha256 is caught
 *    by the document-level anchor check below).
 *
 * 5. The file-level metadata.yaml_sha256 MUST equal the recomputed canonical
 *    hash of the document content (zero-out self-referential hash fields,
 *    serialise YAML, sha256). This is the defense against a hand-edit that
 *    only adjusts metadata.yaml_sha256 to its post-edit value: the chain
 *    check could otherwise be defeated when paired with a metadata bump.
 *
 * 6. The current metadata.yaml_sha256 MUST appear as the new_yaml_sha256 of
 *    at least one history event (document-level anchor). This catches the
 *    case where an attacker forges events without bumping metadata. Only
 *    enforced once at least one mutate() has stamped a non-null new hash
 *    (a fresh seed-only document is exempt).
 *
 * Cross-callsite chain ordering is intentionally NOT enforced — the
 * graph-based check #4, the file-level recompute #5, and the document-level
 * anchor #6 together cover every cheap forgery surface without requiring a
 * total ordering across mutations.
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
 *
 * @cross-tenant-by-design Tenant-isolation sweep tooling; verifies the chain integrity of the inventory YAML history events, not tenant data.
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

        // First pass: collect every non-null new_yaml_sha256 seen across the
        // document plus every non-null previous_yaml_sha256. The chain check
        // below uses the union of (a) all events' new hashes (the standard
        // anchor: a previous must reference some prior recorded state) and
        // (b) the SINGLE BOOTSTRAP hash (the unique "orphan" previous —
        // the first mutate after bootstrap stamps every new event's previous
        // with the pre-bootstrap file hash, which never appears as any
        // event's new because no mutate produced it).
        //
        // The bootstrap is recoverable as the unique orphan because every
        // legitimate non-null previous must EITHER reference an earlier
        // mutate's finalHash (= some other event's new) OR be the single
        // bootstrap hash. Multiple orphans = forged events referencing
        // mutually-self-supporting fabricated states.
        //
        // The chain check is GLOBAL, not per-callsite — see the docblock
        // note about why per-callsite chain continuity does not hold once
        // interleaved single-callsite mutations (e.g. sequential `submit`
        // runs across a cluster's 48 callsites) advance the file hash
        // between same-callsite events.
        $observedNewHashes = [];
        $observedPreviousHashes = [];
        foreach ($callsites as $callsite) {
            foreach ($callsite['history'] as $event) {
                if ($event['new_yaml_sha256'] !== null) {
                    $observedNewHashes[$event['new_yaml_sha256']] = true;
                }
                if ($event['previous_yaml_sha256'] !== null) {
                    $observedPreviousHashes[$event['previous_yaml_sha256']] = true;
                }
            }
        }
        $orphanPreviousHashes = array_diff_key($observedPreviousHashes, $observedNewHashes);
        if (count($orphanPreviousHashes) > 1) {
            $this->error(sprintf(
                '[bootstrap-orphan] %d distinct previous_yaml_sha256 values reference YAML states that were never written (no event has them as new_yaml_sha256). Exactly one such value is legitimate (the bootstrap hash before the first mutate). Multiples indicate forged events: %s',
                count($orphanPreviousHashes),
                implode(', ', array_map(static fn (string $h): string => substr($h, 0, 16).'…', array_keys($orphanPreviousHashes))),
            ));
            $problems++;
        }
        // Accept the orphan(s) as legitimate anchors for the chain check
        // (multiples already flagged above; we still let the per-event check
        // run so the report surfaces every event affected by the forgery).
        foreach ($orphanPreviousHashes as $hash => $_) {
            $observedNewHashes[$hash] = true;
        }

        // Second pass: per-event invariants + global-anchor chain check.
        foreach ($callsites as $callsite) {
            $callsiteId = $callsite['id'];
            $history = $callsite['history'];

            foreach ($history as $eventIndex => $event) {
                $totalEvents++;

                $eventProblems = $this->verifyEvent(
                    callsiteId: $callsiteId,
                    eventIndex: $eventIndex,
                    event: $event,
                    knownIds: $knownIds,
                    observedNewHashes: $observedNewHashes,
                );

                foreach ($eventProblems as $message) {
                    $this->error($message);
                    $problems++;
                }
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
     * @param  array<string, true>  $observedNewHashes  set of every non-null new_yaml_sha256 in the document, plus accepted bootstrap orphans
     * @return list<string>
     */
    private function verifyEvent(
        string $callsiteId,
        int $eventIndex,
        array $event,
        array $knownIds,
        array $observedNewHashes,
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
            } elseif (! isset($observedNewHashes[$event['previous_yaml_sha256']])) {
                // Global-anchor chain check (replaces the prior per-callsite
                // check). InventoryService::mutate() stamps each new event's
                // previous_yaml_sha256 with the file's pre-mutation hash —
                // which is the most recent prior mutate's finalHash, recorded
                // somewhere in the document as another event's
                // new_yaml_sha256. So every legitimate previous_yaml_sha256
                // must appear as some event's new_yaml_sha256. A previous
                // hash that doesn't appear anywhere is a forged reference to
                // a state that was never actually written.
                //
                // Per-callsite continuity (event N's previous == event N-1's
                // new on the SAME callsite) does NOT hold under sequential
                // single-callsite mutations: cluster-mode start stamps every
                // callsite with the same new_yaml_sha256; subsequent
                // submit-per-callsite calls each advance the file hash, so
                // callsite #2's submit.previous (the file hash AT submit time,
                // already advanced by callsite #1's submit) doesn't equal
                // callsite #2's start.new (the cluster-start finalHash). The
                // global-anchor check tolerates this legitimate interleave
                // while still rejecting forgeries.
                $problems[] = sprintf(
                    '[%s history[%d]] chain is broken: previous_yaml_sha256 %s does not appear as any event\'s new_yaml_sha256 in the document — forged reference to a YAML state that was never written.',
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
