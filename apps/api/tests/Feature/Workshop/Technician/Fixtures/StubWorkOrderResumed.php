<?php

declare(strict_types=1);

namespace Tests\Feature\Workshop\Technician\Fixtures;

final readonly class StubWorkOrderResumed
{
    public function __construct(
        public string $work_order_id,
        public \DateTimeImmutable $resumed_at,
    ) {}
}
