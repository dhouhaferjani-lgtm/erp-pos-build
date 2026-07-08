<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\DTOs;

use App\Modules\Product\Application\Services\EnrichmentImagePersister;

/**
 * Result tally for a single {@see EnrichmentImagePersister::persist()} run.
 *
 *  - attached: brand-new assets downloaded, stored and linked to the product.
 *  - reused:   descriptors whose bytes were already present (same source_ref) and
 *              were (idempotently) attached without re-downloading.
 *  - skipped:  freshly downloaded assets discarded by the checksum backstop
 *              because identical bytes were already attached under another URL.
 *  - failed:   descriptors that raised an error (bad URL, fetch failure, …) and
 *              were isolated so the rest of the run continued.
 */
final class EnrichmentImagePersistOutcome
{
    public function __construct(
        public int $attached = 0,
        public int $reused = 0,
        public int $skipped = 0,
        public int $failed = 0,
    ) {}
}
