<?php

declare(strict_types=1);

namespace Tests\Feature\Workshop\Technician\Fixtures;

final readonly class StubWorkOrderCompleted
{
    public function __construct(
        public string $work_order_id,
        public ?int $completion_mileage,
        public \DateTimeImmutable $completed_at,
    ) {}
}
