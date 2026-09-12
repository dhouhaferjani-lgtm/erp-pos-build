<?php

declare(strict_types=1);

namespace Tests\Architecture\Support;

/**
 * What one scan found. Immutable, so a test can hold two scans and compare.
 */
final class RouteCoverageReport
{
    /**
     * @param  list<string>  $uncoveredKeys
     * @param  array<string, int>  $countsByClassification  every RouteCoverage
     *                                                      value as a key, zero-filled — never a partial map, so a caller can
     *                                                      assert an exact five-way distribution without null coalescing.
     */
    public function __construct(
        private readonly array $uncoveredKeys,
        private readonly int $writeCount,
        private readonly int $readCount,
        private readonly array $countsByClassification,
    ) {}

    /**
     * @return list<string>
     */
    public function uncoveredKeys(): array
    {
        return $this->uncoveredKeys;
    }

    public function writeCount(): int
    {
        return $this->writeCount;
    }

    public function readCount(): int
    {
        return $this->readCount;
    }

    /**
     * The exact five-way distribution of the scanned universe. Promised by this
     * wave's file table since rev 2 and never implemented until gate r2 B-2;
     * RoutePermissionCoverageRatchetLivenessTest asserts on it directly.
     *
     * @return array<string, int>
     */
    public function countsByClassification(): array
    {
        return $this->countsByClassification;
    }
}
