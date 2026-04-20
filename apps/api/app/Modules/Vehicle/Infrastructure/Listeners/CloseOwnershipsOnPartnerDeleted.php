<?php

declare(strict_types=1);

namespace App\Modules\Vehicle\Infrastructure\Listeners;

use App\Modules\Partner\Domain\Events\PartnerDeleted;
use App\Modules\Vehicle\Application\Services\VehicleOwnershipService;

final readonly class CloseOwnershipsOnPartnerDeleted
{
    public function __construct(
        private VehicleOwnershipService $service,
    ) {}

    public function handle(PartnerDeleted $event): void
    {
        $this->service->handlePartnerDeleted($event->partnerId);
    }
}
