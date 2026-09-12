<?php

declare(strict_types=1);

namespace Tests\Architecture\Support;

/**
 * One ratchet verdict. Four methods and nothing else: every assertion in this
 * wave and in wave 0b is expressible with them, and a fifth would invite a
 * caller to reason about the checker's internals instead of its verdict.
 */
final class RouteCoverageRatchetResult
{
    /**
     * @param  list<string>  $newViolations  uncovered routes absent from the baseline
     * @param  list<string>  $staleEntries  baseline entries whose route is now covered
     */
    public function __construct(
        private readonly array $newViolations,
        private readonly array $staleEntries,
        private readonly string $message,
    ) {}

    /**
     * @return list<string>
     */
    public function newViolations(): array
    {
        return $this->newViolations;
    }

    /**
     * @return list<string>
     */
    public function staleEntries(): array
    {
        return $this->staleEntries;
    }

    public function isClean(): bool
    {
        return $this->newViolations === [] && $this->staleEntries === [];
    }

    public function message(): string
    {
        return $this->message;
    }
}
