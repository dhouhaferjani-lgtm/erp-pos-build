<?php

declare(strict_types=1);

namespace App\Application\Sweep\Domain;

use App\Application\Sweep\InventoryService;
use InvalidArgumentException;

/**
 * Immutable typed wrapper around the parsed sweep-inventory YAML.
 *
 * Mutations always go through {@see InventoryService::mutate()}
 * which exposes a callable mutator that receives THIS document and must
 * return a NEW one. Direct mutation of the internal array is prevented by the
 * `with*()` builders that always return a fresh instance.
 *
 * The document is intentionally kept thin (a typed array view) — it does not
 * understand the schema beyond the locator helpers below. JSON Schema
 * validation is the InventoryService's responsibility.
 *
 * Codex Phase 1 review (cross-cutting #3): the `array<string, mixed>` shapes
 * that previously dominated this file have been replaced with `@phpstan-type`
 * aliases that pin the well-known YAML keys. Keys whose value is a nested
 * map (review block, history event payload …) carry their own typed
 * sub-shapes. Production callers thereby get PHPStan visibility into typos
 * and missing-field bugs.
 *
 * @phpstan-type HistoryEvent array{
 *     at: string,
 *     actor: string|null,
 *     action: string|null,
 *     command: string|null,
 *     previous_yaml_sha256: string|null,
 *     new_yaml_sha256: string|null,
 *     target_ids: list<string>,
 *     from_status: string|null,
 *     to_status: string|null,
 *     commit: string|null,
 *     test: string|null,
 *     review_file: string|null,
 *     review_commit: string|null,
 *     note: string|null,
 * }
 * @phpstan-type ReviewBlock array{
 *     reviewer: string|null,
 *     verdict: string|null,
 *     reviewed_at: string|null,
 *     review_file: string|null,
 *     review_commit: string|null,
 * }
 * @phpstan-type Callsite array{
 *     id: string,
 *     stable_key: string,
 *     surface: string,
 *     cluster_id: string,
 *     scanner: string,
 *     file: string,
 *     line: int|null,
 *     symbol: string,
 *     pattern_type: string,
 *     resource: string|null,
 *     expected_scope: string,
 *     expected_fix: string|null,
 *     severity: string,
 *     fiscal_path: bool,
 *     cross_module: bool,
 *     stale_state: string,
 *     status: string,
 *     owner: string|null,
 *     claimed_at: string|null,
 *     review: ReviewBlock,
 *     fix_commit: string|null,
 *     regression_test: string|null,
 *     blocked_reason: string|null,
 *     history: list<HistoryEvent>,
 * }
 * @phpstan-type ReviewGate array{
 *     required: bool,
 *     reviewer_must_differ_from_owner: bool,
 *     review_file: string,
 *     accepted_verdicts: list<string>,
 *     verify_review_commit_linkage: bool,
 * }
 * @phpstan-type Cluster array{
 *     id: string,
 *     display_name: string,
 *     surface: string,
 *     owner: string|null,
 *     required_owner: string|null,
 *     status: string,
 *     blocked_by: list<string>,
 *     blocked_by_external: string|null,
 *     blocked_reason: string|null,
 *     blocks: list<string>,
 *     is_reference: bool,
 *     expected_callsite_count: int|null,
 *     test_file: string,
 *     review_gate: ReviewGate,
 * }
 * @phpstan-type Metadata array{
 *     schema_version: string,
 *     spec_version: string,
 *     spec_path: string,
 *     branch: string,
 *     generated_at: string,
 *     generated_by: string,
 *     schema_sha256: string,
 *     yaml_sha256: string,
 * }
 * @phpstan-type AgentEntry array{
 *     role: string,
 *     can_claim: list<string>,
 *     can_review: list<string>,
 * }
 * @phpstan-type ProgressBlock array{
 *     total_callsites: int,
 *     total_clusters: int,
 *     by_status: array<string, int>,
 *     by_surface: array<string, array{total: int, fixed: int}>,
 *     by_owner: array<string, array{claimed: int, fixed: int}|int>,
 *     drift: array{yaml_says_fixed_code_unsafe: int, code_safe_yaml_pending: int},
 * }
 * @phpstan-type DocumentData array{
 *     metadata: Metadata,
 *     agents: array<string, AgentEntry>,
 *     statuses_enum: list<string>,
 *     clusters: list<Cluster>,
 *     callsites: list<Callsite>,
 *     progress: ProgressBlock,
 * }
 */
final class InventoryDocument
{
    /** @var DocumentData */
    private array $data;

    /**
     * @param  DocumentData  $data
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * @return DocumentData
     */
    public function toArray(): array
    {
        return $this->data;
    }

    public function schemaVersion(): string
    {
        return $this->data['metadata']['schema_version'];
    }

    public function yamlSha256(): string
    {
        return $this->data['metadata']['yaml_sha256'];
    }

    /**
     * @return list<Cluster>
     */
    public function clusters(): array
    {
        return $this->data['clusters'];
    }

    /**
     * @return list<Callsite>
     */
    public function callsites(): array
    {
        return $this->data['callsites'];
    }

    /**
     * Returns a fresh document where the callsite identified by $callsiteId
     * has been transformed by $mutator. The mutator receives the callsite's
     * raw associative array and must return the updated array (it MUST keep
     * the `id` field; modifying `id` is rejected).
     *
     * @param  callable(Callsite): Callsite  $mutator
     */
    public function withCallsiteUpdate(string $callsiteId, callable $mutator): self
    {
        $found = false;
        $next = $this->data;
        $callsites = $next['callsites'];
        foreach ($callsites as $index => $callsite) {
            if ($callsite['id'] !== $callsiteId) {
                continue;
            }
            $found = true;
            $updated = $mutator($callsite);
            if ($updated['id'] !== $callsiteId) {
                throw new InvalidArgumentException(
                    "Mutator changed callsite id for {$callsiteId} (found '{$updated['id']}'); ".
                    'callsite ids are immutable.',
                );
            }
            $callsites[$index] = $updated;
        }

        if (! $found) {
            throw new InvalidArgumentException("Callsite id not found in document: {$callsiteId}");
        }
        $next['callsites'] = $callsites;

        return new self($next);
    }

    /**
     * Returns a fresh document with the entire callsites list replaced.
     * Used by SweepInventoryGenerateCommand when merging scanner output.
     *
     * @param  list<Callsite>  $callsites
     */
    public function withCallsites(array $callsites): self
    {
        $next = $this->data;
        $next['callsites'] = $callsites;

        return new self($next);
    }

    /**
     * Returns a fresh document with `metadata.yaml_sha256` set. The service
     * uses this internally; consumers should not call it directly.
     */
    public function withYamlSha256(string $sha256): self
    {
        $next = $this->data;
        $next['metadata']['yaml_sha256'] = $sha256;

        return new self($next);
    }

    /**
     * Returns a fresh document with `metadata.generated_at` set. Useful for
     * regenerate runs.
     */
    public function withGeneratedAt(string $isoTimestamp): self
    {
        $next = $this->data;
        $next['metadata']['generated_at'] = $isoTimestamp;

        return new self($next);
    }
}
