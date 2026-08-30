<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\DTOs;

use App\Modules\Treasury\Domain\Enums\RepositoryCensusCode;
use App\Modules\Treasury\Domain\Enums\RepositoryLocationAttributionVerdict;

final readonly class RepositoryCensusFinding
{
    public function __construct(
        public RepositoryCensusCode $code,
        public string $companyId,
        public ?string $repositoryId = null,
        public ?string $repositoryCode = null,
        public ?string $repositoryType = null,
        public ?string $locationId = null,
        public ?RepositoryLocationAttributionVerdict $attributionVerdict = null,
        public ?string $canonicalRepositoryId = null,
        public string $hint = '',
    ) {}

    /**
     * @return array{
     *     code: string,
     *     company_id: string,
     *     repository_id: string|null,
     *     repository_code: string|null,
     *     repository_type: string|null,
     *     location_id: string|null,
     *     attribution_verdict: string|null,
     *     canonical_repository_id: string|null,
     *     hint: string
     * }
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code->value,
            'company_id' => $this->companyId,
            'repository_id' => $this->repositoryId,
            'repository_code' => $this->repositoryCode,
            'repository_type' => $this->repositoryType,
            'location_id' => $this->locationId,
            'attribution_verdict' => $this->attributionVerdict?->value,
            'canonical_repository_id' => $this->canonicalRepositoryId,
            'hint' => $this->hint,
        ];
    }
}
