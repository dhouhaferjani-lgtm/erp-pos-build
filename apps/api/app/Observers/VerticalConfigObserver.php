<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\Vertical;
use App\Models\VerticalConfig;
use App\Services\VerticalConfigService;

/**
 * Observer for VerticalConfig model — self-enforcing cache invalidation.
 *
 * The VerticalConfigService caches the per-vertical DB override (including
 * the "no row" sentinel) for 24 hours. Any write to a vertical_configs row
 * must bust that cache, otherwise tenants keep seeing stale module lists
 * until the TTL expires. Hooking saved/deleted here means future writers
 * (controllers, seeders, tinker) cannot forget to invalidate.
 */
class VerticalConfigObserver
{
    public function __construct(
        private readonly VerticalConfigService $verticalConfigService
    ) {}

    /**
     * Handle the VerticalConfig "saved" event (fires on create and update).
     */
    public function saved(VerticalConfig $config): void
    {
        $this->invalidate($config);
    }

    /**
     * Handle the VerticalConfig "deleted" event.
     */
    public function deleted(VerticalConfig $config): void
    {
        $this->invalidate($config);
    }

    private function invalidate(VerticalConfig $config): void
    {
        $vertical = Vertical::tryFrom($config->vertical);

        if ($vertical !== null) {
            $this->verticalConfigService->invalidateVertical($vertical);
        }
    }
}
