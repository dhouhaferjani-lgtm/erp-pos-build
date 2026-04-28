<?php

declare(strict_types=1);

namespace Tests\Feature\Workshop\Technician\Fixtures;

/**
 * Stub for Plan B's `WorkOrderStarted` event. The signature mirrors the canonical one
 * declared in Plan B's coordination note; once Plan B lands, this fixture goes away
 * and listeners consume the real class via the EventServiceProvider::$listen wiring.
 */
final readonly class StubWorkOrderStarted
{
    public function __construct(
        public string $work_order_id,
        public string $primary_technician_profile_id,
        public \DateTimeImmutable $started_at,
    ) {}
}
