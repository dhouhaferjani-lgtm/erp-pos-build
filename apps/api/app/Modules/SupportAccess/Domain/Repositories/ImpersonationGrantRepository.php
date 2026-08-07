<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Domain\Repositories;

use App\Modules\SupportAccess\Domain\Entities\ImpersonationGrant;
use Closure;

interface ImpersonationGrantRepository
{
    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): ImpersonationGrant;

    /** @param Closure(ImpersonationGrant): void $mutation */
    public function mutateLocked(string $id, Closure $mutation): ImpersonationGrant;
}
