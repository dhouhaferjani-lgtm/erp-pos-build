<?php

declare(strict_types=1);

namespace App\Application\Sweep\Domain;

/**
 * Inputs to a single inventory mutation. The service uses this to populate
 * the chain-event metadata appended to every mutated callsite's history[]
 * array (master plan Section 4 — command-event audit trail).
 *
 * @phpstan-type ActorString "claude"|"codex"|"ci"|"human"|"generator"
 */
final class MutationContext
{
    /**
     * @param  ActorString  $actor  Who ran the mutation.
     * @param  string  $command  Artisan command name (e.g. "sweep:inventory:claim"); empty allowed for ad-hoc.
     * @param  string  $action  Workflow verb from the schema's history.action enum (e.g. "claim", "submit").
     * @param  string|null  $gitCommit  Optional source-tree git HEAD at event time; null when running outside a repo.
     */
    public function __construct(
        public readonly string $actor,
        public readonly string $command,
        public readonly string $action,
        public readonly ?string $gitCommit,
    ) {}
}
