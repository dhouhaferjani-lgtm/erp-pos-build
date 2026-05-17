<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Services;

use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Shared\Contracts\Fiscal\FiscalEventProjector;
use App\Shared\Contracts\Fiscal\ModuleActivationResolver;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

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
 * **Phase 1 reality.** Task 21 (`POSServiceProvider::register()`) tags
 * `PosCoreReceiptProjection` into the production set as the always-active
 * `SALE_RECEIPT` projector; Task 22 will add the gated
 * `TreasuryReceiptBridge` (`requiresModule() === 'Treasury'`). Tests that
 * need a deterministic projector set rebind the registry singleton with
 * an explicit list (e.g. `OutboxIngestorTest::setUp()` overrides the
 * tagged set so it sees exactly one fake projector). An empty registry
 * stays a valid runtime state when no module owns a tagged projector.
 *
 * **Round-2 hardening (Codex):**
 *   - **F1 fail-closed on resolver exception.** §7.2 requires the
 *     fiscal event row to always be persisted and the device never
 *     blocked. §7.5 defines projection as downstream / retryable. A
 *     resolver throw (DB outage, cache backend down, etc.) must NOT
 *     crash `activeProjectorsFor()` — the registry catches every
 *     Throwable, excludes the gated projector, and logs. POS-core
 *     projectors (`requiresModule() === null`) are unaffected and
 *     proceed normally.
 *   - **F2 unique projector names.** §7.5 defines a UNIQUE constraint
 *     on `fiscal_event_projections (fiscal_event_id, projector_name)`.
 *     A duplicate `name()` at the registry level would surface as a
 *     DB constraint violation at Task 24 row-insert. The constructor
 *     fast-fails at boot time with a `LogicException` naming the
 *     collision instead — boot-fail is acceptable for "broken config";
 *     a runtime DB error in projection would be much harder to
 *     diagnose.
 *   - **F3 reject empty `requiresModule()` token.** §7.3 mandates a
 *     canonical PascalCase token OR null. An empty/whitespace-only
 *     non-null token is a programming error in the projector — fail
 *     fast at constructor time rather than silently routing `''` to
 *     `CompanyConfig::hasModule()` (which strict-compares and always
 *     reports false, masking the bug).
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
     *
     * @throws LogicException when two projectors share a `name()` (Codex F2 round-2) or when a non-null
     *                        `requiresModule()` is empty / whitespace-only (Codex F3 round-2).
     */
    public function __construct(
        iterable $projectors,
        private readonly ModuleActivationResolver $resolver,
    ) {
        // Materialize the iterable once — singletons keep this list for the
        // process lifetime; we cannot re-iterate a Generator twice.
        $materialized = is_array($projectors) ? array_values($projectors) : iterator_to_array($projectors, false);

        $seen = [];
        foreach ($materialized as $projector) {
            // F2 round-2 — unique projector names (UNIQUE constraint at Task 24).
            $name = $projector->name();
            if (isset($seen[$name])) {
                throw new LogicException(sprintf(
                    'Duplicate FiscalEventProjector name "%s" — registry projector names must be unique '.
                    '(UNIQUE constraint on fiscal_event_projections(fiscal_event_id, projector_name) per spec §7.5).',
                    $name,
                ));
            }
            $seen[$name] = true;

            // F3 round-2 — reject empty / whitespace-only requiresModule().
            $module = $projector->requiresModule();
            if ($module !== null && trim($module) === '') {
                throw new LogicException(sprintf(
                    'FiscalEventProjector "%s" returned an empty `requiresModule()` token — must be either `null` '.
                    '(always-active) or a non-empty canonical PascalCase module token per spec §7.3.',
                    $name,
                ));
            }
        }

        $this->projectors = $materialized;
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
            if ($module !== null) {
                // F1 round-2 — fail-closed on resolver exception. §7.2 demands
                // the fiscal event row always be persisted and the device never
                // blocked; §7.5 defines projection as retryable. A resolver
                // outage excludes the gated projector and logs — never crashes
                // the ingest path. POS-core (always-active) projectors are
                // unaffected.
                try {
                    $isActive = $this->resolver->isActive($module, $event->tenant_id, $event->company_id);
                } catch (Throwable $e) {
                    Log::error(
                        'FiscalEventProjectionRegistry: ModuleActivationResolver threw — '.
                        'failing closed and excluding projector.',
                        [
                            'projector' => $projector->name(),
                            'module' => $module,
                            'tenant_id' => $event->tenant_id,
                            'company_id' => $event->company_id,
                            'event_id' => $event->id,
                            'event_type' => $event->event_type->value,
                            'exception' => $e::class,
                            'message' => $e->getMessage(),
                        ],
                    );

                    continue;
                }
                if (! $isActive) {
                    continue;
                }
            }
            $active[] = $projector;
        }

        return $active;
    }
}
