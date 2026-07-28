<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use Tests\TestCase;

final class SyncReadRouteRetirementTest extends TestCase
{
    public function test_pull_route_is_retired(): void
    {
        $this->getJson('/api/v1/pos/sync/pull')->assertNotFound();
    }

    public function test_menu_route_is_retired(): void
    {
        $this->getJson('/api/v1/pos/sync/menu')->assertNotFound();
    }
}
