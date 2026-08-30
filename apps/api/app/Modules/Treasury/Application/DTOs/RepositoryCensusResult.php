<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\DTOs;

use App\Modules\Treasury\Domain\Enums\RepositoryCensusCode;

final readonly class RepositoryCensusResult
{
    /**
     * @param  list<RepositoryCensusFinding>  $findings
     */
    public function __construct(
        public string $tenantId,
        public string $companyId,
        public ?string $canonicalSafeId,
        public array $findings,
    ) {}

    public function hasCode(RepositoryCensusCode $code): bool
    {
        foreach ($this->findings as $finding) {
            if ($finding->code === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<RepositoryCensusFinding>
     */
    public function findingsFor(RepositoryCensusCode $code): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn (RepositoryCensusFinding $finding): bool => $finding->code === $code,
        ));
    }
}
