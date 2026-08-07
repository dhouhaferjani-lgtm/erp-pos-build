<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Infrastructure\Repositories;

use App\Modules\SupportAccess\Domain\Entities\ImpersonationGrant;
use App\Modules\SupportAccess\Domain\Repositories\ImpersonationGrantRepository;
use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;

final class EloquentImpersonationGrantRepository implements ImpersonationGrantRepository
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly Repository $config,
    ) {}

    public function create(array $attributes): ImpersonationGrant
    {
        return ImpersonationGrant::query()->create($attributes);
    }

    public function mutateLocked(string $id, Closure $mutation): ImpersonationGrant
    {
        $connection = $this->database->connection(
            $this->config->get('tenancy.database.central_connection'),
        );

        return $connection->transaction(function () use ($id, $mutation): ImpersonationGrant {
            $grant = ImpersonationGrant::query()->lockForUpdate()->findOrFail($id);
            $mutation($grant);
            $grant->save();

            return $grant->refresh();
        });
    }
}
