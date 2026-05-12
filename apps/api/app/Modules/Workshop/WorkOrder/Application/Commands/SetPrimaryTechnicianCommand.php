<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Application\Commands;

final readonly class SetPrimaryTechnicianCommand
{
    public function __construct(
        public string $work_order_id,
        public string $technician_profile_id,
        public string $tenant_id,
        public string $company_id,
    ) {}
}
