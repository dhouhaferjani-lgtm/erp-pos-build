<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Infrastructure\Persistence;

use App\Modules\Workshop\Technician\Domain\Contracts\TechnicianProfileRepositoryInterface;
use App\Modules\Workshop\Technician\Domain\Enums\SpecialtyCode;
use App\Modules\Workshop\Technician\Domain\TechnicianProfile;
use Illuminate\Support\Collection;

final readonly class EloquentTechnicianProfileRepository implements TechnicianProfileRepositoryInterface
{
    public function findById(string $id): ?TechnicianProfile
    {
        return TechnicianProfile::query()->find($id);
    }

    /**
     * @param  array{active_only?: bool, specialty?: SpecialtyCode}  $filters
     * @return Collection<int, TechnicianProfile>
     */
    public function listForCompany(string $companyId, array $filters = []): Collection
    {
        $q = TechnicianProfile::query()->where('company_id', $companyId);

        if (($filters['active_only'] ?? false) === true) {
            $q->where('is_active', true);
        }

        if (isset($filters['specialty'])) {
            /** @var SpecialtyCode $specialty */
            $specialty = $filters['specialty'];
            // PostgreSQL jsonb @> containment on a single-element array. SQLite tests use
            // the availability service directly; this filter is a pgsql-only optimization.
            $q->whereJsonContains('specialties', $specialty->value);
        }

        /** @var Collection<int, TechnicianProfile> $result */
        $result = $q->orderBy('created_at', 'desc')->get();

        return $result;
    }
}
