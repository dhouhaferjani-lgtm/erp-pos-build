<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Bundle\Application\Queries;

final readonly class ListBundlesQuery
{
    public function __construct(
        public string $tenant_id,
        public string $company_id,
        public ?bool $active,
        public ?string $search,
        public int $per_page,
    ) {}
}
