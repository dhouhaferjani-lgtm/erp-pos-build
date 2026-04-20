<?php

declare(strict_types=1);

namespace App\Modules\Vehicle\Domain\Events;

final readonly class MileageAnomalyDetected
{
    public function __construct(
        public string $vehicle_id,
        public int $new_reading,
        public int $previous_reading,
        public float $decrease_percent,
        public \DateTimeImmutable $detected_at,
    ) {}
}
