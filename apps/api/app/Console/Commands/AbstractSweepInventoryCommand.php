<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Sweep\Domain\InventoryDocument;
use App\Application\Sweep\Domain\MutationContext;
use App\Application\Sweep\InventoryService;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Shared infrastructure for the `sweep:inventory:*` workflow commands
 * (claim/start/submit/review/block/unblock/defer/status/verify-history).
 *
 * Master plan reference: 2026-05-02-tenant-isolation-master-plan.md
 *   Section 4 — workflow state machine + command-event audit trail.
 *   Section 5 — one artisan command per state-machine verb.
 *   Section 7 — Treasury hard gate enforced inside the claim command.
 *
 * Subclasses are thin shells: they declare their `signature` + `description`,
 * override {@see self::performMutation()} for write commands, and call back
 * into {@see self::executeMutation()} which handles
 *   - actor validation (claude|codex|ci|human, never "generator"),
 *   - InventoryService construction (no `app()` — paths flow from options or
 *     base_path() / repo-root resolution),
 *   - MutationContext stamping (actor / command / git HEAD),
 *   - error surface (stderr + non-zero exit).
 *
 * Read-only commands (status, verify-history) skip {@see self::executeMutation()}
 * and call {@see self::loadDocument()} directly.
 *
 * @phpstan-import-type Callsite from InventoryDocument
 * @phpstan-import-type Cluster from InventoryDocument
 * @phpstan-import-type HistoryEvent from InventoryDocument
 */
abstract class AbstractSweepInventoryCommand extends Command
{
    /**
     * Allowed values for the --actor flag.
     *
     * Note: the schema's history.actor enum also permits "generator", but that
     * value is reserved for SweepInventoryGenerateCommand. Workflow commands
     * MUST be invoked by a real actor — claude, codex, ci, or human.
     */
    protected const ALLOWED_ACTORS = ['claude', 'codex', 'ci', 'human'];

    /**
     * The action enum value this command writes to history events. Subclasses
     * MUST override (e.g. "claim", "submit"). Read-only commands return null.
     */
    protected function actionVerb(): ?string
    {
        return null;
    }

    /**
     * The artisan command string written to history.command. Subclasses MUST
     * override. Used both for MutationContext + cluster claim invariants.
     */
    abstract protected function commandName(): string;

    /**
     * Resolve the actor flag, defaulting to "human" per the master plan.
     *
     * @return 'claude'|'codex'|'ci'|'human'
     */
    protected function resolveActor(): string
    {
        $raw = $this->option('actor');
        $value = is_string($raw) && $raw !== '' ? $raw : 'human';

        return match ($value) {
            'claude', 'codex', 'ci', 'human' => $value,
            default => throw new InvalidArgumentException(
                "Invalid --actor '{$value}'. Allowed values: ".implode(', ', self::ALLOWED_ACTORS).'.',
            ),
        };
    }

    /**
     * Build the InventoryService against caller-supplied paths or defaults.
     */
    protected function makeInventoryService(): InventoryService
    {
        $repoRoot = $this->resolveRepoRoot();
        $inventoryPath = $this->stringOption('inventory-path')
            ?? $repoRoot.'/docs/superpowers/plans/tenant-isolation-sweep-inventory.yml';
        $schemaPath = $this->stringOption('schema-path')
            ?? base_path('app/Application/Sweep/InventoryYamlSchema.json');

        return new InventoryService($inventoryPath, $schemaPath);
    }

    /**
     * Build a MutationContext using the resolved actor + the subclass action
     * verb / command name + the current git HEAD (best-effort).
     *
     * @param  'claude'|'codex'|'ci'|'human'  $actor  Validated by {@see self::resolveActor()}.
     */
    protected function makeMutationContext(string $actor): MutationContext
    {
        $action = $this->actionVerb();
        if ($action === null) {
            throw new InvalidArgumentException(
                'Command '.static::class.' is read-only; do not call makeMutationContext().',
            );
        }

        return new MutationContext(
            actor: $actor,
            command: $this->commandName(),
            action: $action,
            gitCommit: $this->resolveGitHead($this->resolveRepoRoot()),
        );
    }

    /**
     * Build the canonical history-event stub the workflow commands append.
     * `previous_yaml_sha256` / `new_yaml_sha256` are stamped by InventoryService
     * during the mutate cycle; everything else flows from the call site.
     *
     * @param  list<string>  $targetIds
     * @return HistoryEvent
     */
    protected function makeHistoryEvent(
        string $action,
        ?string $fromStatus,
        string $toStatus,
        array $targetIds,
        string $note,
        ?string $reviewFile = null,
        ?string $reviewCommit = null,
        ?string $fixCommit = null,
        ?string $regressionTest = null,
    ): array {
        return [
            'at' => gmdate('Y-m-d\TH:i:s\Z'),
            // actor + command stamped by InventoryService from MutationContext.
            'actor' => null,
            'action' => $action,
            'command' => null,
            'previous_yaml_sha256' => null,
            'new_yaml_sha256' => null,
            'target_ids' => $targetIds,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'commit' => $fixCommit,
            'test' => $regressionTest,
            'review_file' => $reviewFile,
            'review_commit' => $reviewCommit,
            'note' => $note,
        ];
    }

    /**
     * Lookup a cluster by its canonical id. Returns null if missing.
     *
     * @return Cluster|null
     */
    protected function findCluster(InventoryDocument $doc, string $clusterId): ?array
    {
        foreach ($doc->clusters() as $cluster) {
            if ($cluster['id'] === $clusterId) {
                return $cluster;
            }
        }

        return null;
    }

    /**
     * Lookup a callsite by its canonical id. Returns null if missing.
     *
     * @return Callsite|null
     */
    protected function findCallsite(InventoryDocument $doc, string $callsiteId): ?array
    {
        foreach ($doc->callsites() as $callsite) {
            if ($callsite['id'] === $callsiteId) {
                return $callsite;
            }
        }

        return null;
    }

    /**
     * Return all callsites whose cluster_id matches.
     *
     * @return list<Callsite>
     */
    protected function callsitesInCluster(InventoryDocument $doc, string $clusterId): array
    {
        $out = [];
        foreach ($doc->callsites() as $callsite) {
            if ($callsite['cluster_id'] === $clusterId) {
                $out[] = $callsite;
            }
        }

        return $out;
    }

    /**
     * Resolves the repo root by walking up from base_path() until we find
     * the docs/superpowers/ directory. Mirrors SweepInventoryGenerateCommand's
     * logic — pulled into the base so all workflow commands share it.
     */
    protected function resolveRepoRoot(): string
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

    protected function resolveGitHead(string $repoRoot): ?string
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

    /**
     * Read a string option, returning null if absent or empty.
     */
    protected function stringOption(string $name): ?string
    {
        $value = $this->option($name);
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    /**
     * Read a boolean flag; Laravel exposes flags as bool-like strings.
     */
    protected function boolFlag(string $name): bool
    {
        return (bool) $this->option($name);
    }

    /**
     * Match a reviewer-supplied commit identifier against a list of stored
     * fix_commit SHAs. Exact match wins. Otherwise, if the supplied value is a
     * git-style short SHA (>=7 chars, all-lowercase hex), accept it when it
     * uniquely prefix-matches exactly one stored commit (an ambiguous prefix
     * is treated as no match — the reviewer should pin the full SHA).
     *
     * Shared by SweepInventoryClaimCommand (Treasury hard-gate linkage check)
     * and SweepInventoryReviewCommand (per-callsite review-commit check) so
     * both surfaces honor the same SHA-matching contract.
     *
     * @param  list<string>  $storedFullShas
     */
    protected function commitIdentifierMatchesStored(string $candidate, array $storedFullShas): bool
    {
        if (in_array($candidate, $storedFullShas, true)) {
            return true;
        }

        $isShortSha = strlen($candidate) >= 7
            && strlen($candidate) < 40
            && preg_match('/^[0-9a-f]+$/', $candidate) === 1;
        if (! $isShortSha) {
            return false;
        }

        $matches = 0;
        foreach ($storedFullShas as $full) {
            if (str_starts_with($full, $candidate)) {
                $matches++;
            }
        }

        return $matches === 1;
    }
}
