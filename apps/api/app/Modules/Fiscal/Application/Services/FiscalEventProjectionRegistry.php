<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Services;

use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Shared\Contracts\Fiscal\FiscalEventProjector;
use App\Shared\Contracts\Fiscal\ModuleActivationResolver;

/**
 * Pluggable-bridge seam — spec v7 §7.3 + SoT §13.6/D16.
 *
 * Holds every registered `FiscalEventProjector` and exposes the active
 * subset for a given fiscal event. The two gates that decide activeness
 * are documented on the `FiscalEventProjector` interface:
 *   1. `handlesEventType($event->event_type)`
 *   2. `requiresModule()` — `null` always passes; otherwise the registry
 *      calls `ModuleActivationResolver::isActive($module,
 *      $event->tenant_id, $event->company_id)`.
 *
 * **Determinism.** Projectors are returned in **registration order** —
 * the order the constructor's `iterable` yielded them. Task 19's
 * `OutboxIngestor` creates `fiscal_event_projections` rows and enqueues
 * jobs in this order, so a stable ordering keeps the audit trail
 * predictable.
 *
 * **Boundary-clean.** Depends only on `FiscalEventProjector` +
 * `ModuleActivationResolver`. The registry never imports Treasury /
 * accounting / sales operational code — that's exactly the
 * bounded-modules asymmetric seam (SoT §13.6/D16) that this seam
 * exists to enforce.
 *
 * **Phase 1 reality.** Until Tasks 21/22 tag real projectors, the
 * container-resolved registry is constructed over an empty tagged set.
 * An empty registry is a valid Phase 1 state and `activeProjectorsFor()`
 * returns `[]` cleanly.
 */
final class FiscalEventProjectionRegistry
{
    /** @var list<FiscalEventProjector> */
    private readonly array $projectors;

    /**
     * @param  iterable<FiscalEventProjector>  $projectors  Tagged via `app->tagged(FiscalEventProjector::class)` in
     *                                                      `FiscalServiceProvider`; the iterable is captured once at
     *                                                      construction (singleton lifetime) — tasks 21/22 will tag the
     *                                                      real POS-core + Treasury projectors.
     */
    public function __construct(
        iterable $projectors,
        private readonly ModuleActivationResolver $resolver,
    ) {
        // Materialize the iterable once — singletons keep this list for the
        // process lifetime; we cannot re-iterate a Generator twice.
        $this->projectors = is_array($projectors) ? array_values($projectors) : iterator_to_array($projectors, false);
    }

    /**
     * @return list<FiscalEventProjector> active projectors in registration order
     */
    public function activeProjectorsFor(FiscalEvent $event): array
    {
        $active = [];
        foreach ($this->projectors as $projector) {
            if (! $projector->handlesEventType($event->event_type)) {
                continue;
            }
            $module = $projector->requiresModule();
            if ($module !== null
                && ! $this->resolver->isActive($module, $event->tenant_id, $event->company_id)) {
                continue;
            }
            $active[] = $projector;
        }

        return $active;
    }
}
