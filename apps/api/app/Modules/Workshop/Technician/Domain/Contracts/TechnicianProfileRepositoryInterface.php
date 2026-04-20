<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Domain\Contracts;

use App\Modules\Workshop\Technician\Domain\Enums\SpecialtyCode;
use App\Modules\Workshop\Technician\Domain\TechnicianProfile;
use Illuminate\Support\Collection;

interface TechnicianProfileRepositoryInterface
{
    public function findById(string $id): ?TechnicianProfile;

    /**
     * @param  array{active_only?: bool, specialty?: SpecialtyCode}  $filters
     * @return Collection<int, TechnicianProfile>
     */
    public function listForCompany(string $companyId, array $filters = []): Collection;
}
