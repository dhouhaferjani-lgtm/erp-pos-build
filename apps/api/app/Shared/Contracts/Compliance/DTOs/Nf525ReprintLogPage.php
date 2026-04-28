<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Compliance\DTOs;

/**
 * One page of the reprint-log query. Compliance returns this shape directly
 * to the controller so the JSON envelope stays exactly as it was before the
 * H3 refactor.
 *
 * `$rows` is intentionally a list of associative arrays: it preserves the
 * pre-refactor controller behaviour of dumping `$paginated->items()` to JSON.
 * Refund flow can swap this to a typed DTO list later if desired; for the
 * H3 byte-stable refactor we preserve the existing wire shape.
 */
final readonly class Nf525ReprintLogPage
{
    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function __construct(
        public array $rows,
        public int $currentPage,
        public int $lastPage,
        public int $perPage,
        public int $total,
    ) {}
}
