<?php

declare(strict_types=1);

namespace App\Modules\Vehicle\Application\Commands;

use App\Modules\Vehicle\Domain\Enums\MileageSource;

final readonly class LogVehicleMileageCommand
{
    public function __construct(
        public string $vehicle_id,
        public int $mileage,
        public \DateTimeImmutable $recorded_at,
        public MileageSource $source,
        public ?string $context_document_id,
        public ?string $context_work_order_id,
        public ?string $recorded_by_user_id,
        public ?string $notes,
    ) {}
}
