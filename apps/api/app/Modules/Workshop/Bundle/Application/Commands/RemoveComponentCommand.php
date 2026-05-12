<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Bundle\Application\Commands;

final readonly class RemoveComponentCommand
{
    public function __construct(
        public string $bundle_id,
        public string $component_id,
        public string $tenant_id,
        public string $company_id,
    ) {}
}
