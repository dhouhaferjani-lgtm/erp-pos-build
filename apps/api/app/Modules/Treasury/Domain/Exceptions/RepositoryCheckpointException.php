<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Exceptions;

use Carbon\CarbonInterface;
use DomainException;

final class RepositoryCheckpointException extends DomainException
{
    public function __construct(string $repositoryId, CarbonInterface $occurredAt, CarbonInterface $checkpoint)
    {
        parent::__construct(sprintf(
            'Repository %s is reconciled through %s; movement occurrence %s is inside that closed period. Reopen the latest statement before recording a backdated movement.',
            $repositoryId,
            $checkpoint->toIso8601String(),
            $occurredAt->toIso8601String(),
        ));
    }
}
