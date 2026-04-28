<?php

declare(strict_types=1);

namespace Tests\Feature\Workshop\Technician\Fixtures;

final readonly class StubWorkOrderPaused
{
    public function __construct(
        public string $work_order_id,
        public string $reason_code,
        public \DateTimeImmutable $paused_at,
    ) {}
}
