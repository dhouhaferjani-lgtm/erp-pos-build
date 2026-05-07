<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Sweep\Domain\InventoryDocument;
use InvalidArgumentException;
use Throwable;

/**
 * `php artisan sweep:inventory:submit`
 *
 * Transitions a single callsite from `in_progress` → `under_review`, recording
 * the fix commit SHA + the regression test path that will be re-checked on
 * review. Appends one `submit` history event with commit/test fields populated.
 *
 * Master plan reference:
 *   Section 4 — workflow state machine (submit: in_progress → under_review).
 *
 * Surface: callsite-scoped only. Per-cluster submit is intentionally NOT
 * supported — every callsite has its own commit/test pair, so submit is
 * inherently per-callsite. --cluster is refused with a clear message.
 *
 * Required options: --callsite-id, --commit, --test (all non-empty).
 *
 * Cross-agent enforcement: --actor MUST equal the callsite's current `owner`.
 * NO --force override — submitting on someone else's callsite would corrupt
 * commit/test attribution.
 *
 * @phpstan-import-type Callsite from InventoryDocument
 *
 * @cross-tenant-by-design Tenant-isolation sweep tooling; operates on the inventory YAML metadata, not on tenant data.
 */
final class SweepInventorySubmitCommand extends AbstractSweepInventoryCommand
{
    /** @var string */
    protected $signature = 'sweep:inventory:submit
        {--inventory-path= : path to YAML (overrides default for tests)}
        {--schema-path= : path to JSON Schema (overrides default for tests)}
        {--callsite-id= : callsite to submit for review}
        {--cluster= : NOT supported — submit is callsite-scoped}
        {--actor=human : claude|codex|ci|human (NOT generator — reserved)}
        {--commit= : git SHA of the fix commit (required, non-empty)}
        {--test= : regression test selector "path::name" (required, non-empty)}';

    /** @var string */
    protected $description = 'Submit a callsite for review, transitioning status in_progress → under_review.';

    protected function actionVerb(): string
    {
        return 'submit';
    }

    protected function commandName(): string
    {
        return 'sweep:inventory:submit';
    }

    public function handle(): int
    {
        // ── 1. Refuse --cluster mode (callsite-scoped only) ─────────────────
        if ($this->stringOption('cluster') !== null) {
            $this->error('submit is callsite-scoped; supply --callsite-id, not --cluster.');

            return self::FAILURE;
        }

        // ── 2. Required options ─────────────────────────────────────────────
        $callsiteId = $this->stringOption('callsite-id');
        if ($callsiteId === null) {
            $this->error('You must supply --callsite-id.');

            return self::FAILURE;
        }

        $fixCommit = $this->stringOption('commit');
        if ($fixCommit === null) {
            $this->error('You must supply --commit (git SHA of the fix commit), non-empty.');

            return self::FAILURE;
        }

        $regressionTest = $this->stringOption('test');
        if ($regressionTest === null) {
            $this->error('You must supply --test (regression test selector "path::name"), non-empty.');

            return self::FAILURE;
        }

        // ── 3. Resolve actor ────────────────────────────────────────────────
        try {
            $actor = $this->resolveActor();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // ── 4. Load + look up callsite ──────────────────────────────────────
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

        // State-transition precondition.
        if ($callsite['status'] !== 'in_progress') {
            $this->error(
                "Callsite {$callsiteId} has status '{$callsite['status']}'; ".
                'only in_progress callsites can be submitted for review.',
            );

            return self::FAILURE;
        }

        // Cross-agent enforcement: --actor MUST equal callsite owner.
        $owner = $callsite['owner'];
        if ($owner === null) {
            $this->error(
                "Callsite {$callsiteId} has no owner set; cannot submit. ".
                'Run sweep:inventory:claim then sweep:inventory:start first.',
            );

            return self::FAILURE;
        }
        if ($owner !== $actor) {
            $this->error(
                "Callsite {$callsiteId} is owned by '{$owner}' but --actor is '{$actor}'. ".
                'Submit must be invoked by the callsite owner; submitting on someone '.
                "else's callsite would corrupt commit/test attribution.",
            );

            return self::FAILURE;
        }

        // ── 5. Mutation ─────────────────────────────────────────────────────
        $note = "submitted by {$actor} via sweep:inventory:submit";
        $context = $this->makeMutationContext($actor);

        try {
            $service->mutate(
                function (InventoryDocument $live) use ($callsiteId, $fixCommit, $regressionTest, $note): InventoryDocument {
                    return $live->withCallsiteUpdate(
                        $callsiteId,
                        function (array $cs) use ($callsiteId, $fixCommit, $regressionTest, $note): array {
                            $cs['status'] = 'under_review';
                            $cs['fix_commit'] = $fixCommit;
                            $cs['regression_test'] = $regressionTest;
                            $cs['history'][] = $this->makeHistoryEvent(
                                action: 'submit',
                                fromStatus: 'in_progress',
                                toStatus: 'under_review',
                                targetIds: [$callsiteId],
                                note: $note,
                                fixCommit: $fixCommit,
                                regressionTest: $regressionTest,
                            );

                            return $cs;
                        },
                    );
                },
                $context,
            );
        } catch (Throwable $t) {
            $this->error('Failed to persist submit: '.$t->getMessage());

            return self::FAILURE;
        }

        $this->line("Submitted callsite {$callsiteId} for review (actor={$actor}, commit={$fixCommit}).");

        return self::SUCCESS;
    }
}
