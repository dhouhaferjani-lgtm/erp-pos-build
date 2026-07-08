<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Exceptions;

use DomainException;

/**
 * Thrown when an interactive movement (allowWhileFrozen = false) targets a
 * frozen repository. Offline-device-replay legs set allowWhileFrozen = true
 * and are recorded with recorded_while_frozen = true instead of throwing.
 *
 * Extends \DomainException so bootstrap/app.php maps it to HTTP 422
 * BUSINESS_ERROR rather than a 500.
 */
final class RepositoryFrozenException extends DomainException
{
    public function __construct(
        public readonly string $repositoryId,
        public readonly string $frozenReason,
    ) {
        parent::__construct(
            "Repository {$repositoryId} is frozen ({$frozenReason}); interactive movements are rejected.",
        );
    }
}
