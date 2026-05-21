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
 * **Determinism (Task 22 round-2 — Codex T22-B1 / Opus F3 BLOCKER).**
 * Projectors are returned in **priority-then-name order** — the
 * constructor sorts the materialized tagged set once by
 * `(priority() ASC, name() ASC)`. This replaces the pre-round-2
 * registration-order semantics, which silently inherited Laravel's
 * provider-registration order (Treasury was tagged BEFORE POS in
 * `bootstrap/providers.php`, causing `TreasuryReceiptBridge` —
 * `priority=150`, depends on `pos_receipts` rows — to run BEFORE
 * `PosCoreReceiptProjection` — `priority=50`). Task 19's
 * `OutboxIngestor` creates `fiscal_event_projections` rows + enqueues
 * jobs in this sorted order, so dispatch order is now self-described by
 * the projector layer rather than accidentally inherited from provider
 * boot order.
 *
 * **Boundary-clean.** Depends only on `FiscalEventProjector` +
 * `ModuleActivationResolver`. The registry never imports Treasury /
 * accounting / sales operational code — that's exactly the
 * bounded-modules asymmetric seam (SoT §13.6/D16) that this seam
 * exists to enforce.
 *
 * **Phase 1 reality.** Both production projectors are now tagged:
 *   - Task 21 — `POSServiceProvider::register()` tags
 *     `PosCoreReceiptProjection` (priority=50, always-active SALE_RECEIPT
 *     projector).
 *   - Task 22 — `TreasuryServiceProvider::register()` tags
 *     `TreasuryReceiptBridge` (priority=150, gated on the `Treasury`
 *     module via `requiresModule() === 'Treasury'`).
 * Tests that need a deterministic projector set rebind the registry
 * singleton with an explicit list (e.g. `OutboxIngestorTest::setUp()`
 * overrides the tagged set so it sees exactly one fake projector). An
 * empty registry stays a valid runtime state when no module owns a
 * tagged projector.
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
 *
 * **Task 22 round-2 hardening (Codex T22-B1 / Opus F3 — convergent BLOCKER):**
 *   - **Deterministic priority-based ordering.** Every projector
 *     declares an integer `priority()`; the registry sorts the
 *     materialized tagged set once at construction by
 *     `(priority ASC, name ASC)`. POS-core projectors that own
 *     canonical projection rows use `priority=50`; module bridges
 *     that depend on those rows being visible (e.g.
 *     `TreasuryReceiptBridge` reads `pos_receipts`) use
 *     `priority=150`. Replaces the pre-round-2 registration-order
 *     semantics that accidentally let provider-registration order
 *     control dispatch order — a real silent-applied bug in production.
 *     Negative priorities are rejected at construction with a
 *     `LogicException`.
 */
final class FiscalEventProjectionRegistry
{
    /** @var list<FiscalEventProjector> */
    private readonly array $projectors;

    /**
     * @param  iterable<FiscalEventProjector>  $projectors  Tagged via `app->tagged(FiscalEventProjector::class)` in
     *                                                      `FiscalServiceProvider`; the iterable is captured once at
     *                                                      construction (singleton lifetime). Task 21 tags
     *                                                      `PosCoreReceiptProjection`; Task 22 tags
     *                                                      `TreasuryReceiptBridge`.
     *
     * @throws LogicException when two projectors share a `name()` (Codex F2 round-2), when a non-null
     *                        `requiresModule()` is empty / whitespace-only (Codex F3 round-2), or when
     *                        a `priority()` is negative (Task 22 round-2 — Codex T22-B1 / Opus F3).
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

            // Task 22 round-2 — priority() must be non-negative. Standing
            // pattern from Task 18 — boot-time invariants are constructor-
            // asserted. A LogicException at boot is cheaper to diagnose than
            // an out-of-order dispatch at runtime that silently flips the
            // bridge's deferred-bail-out into the steady-state path.
            $priority = $projector->priority();
            if ($priority < 0) {
                throw new LogicException(sprintf(
                    'FiscalEventProjector "%s" returned a negative priority (%d) — must be >= 0. '.
                    'Convention: POS-core projectors that own canonical rows use 50; module bridges '.
                    'that depend on them use 150. See FiscalEventProjector::priority() docblock.',
                    $name,
                    $priority,
                ));
            }
        }

        // Task 22 round-2 — sort by (priority ASC, name ASC). Stable
        // deterministic order means dispatch order is self-described by the
        // projector layer, not accidentally inherited from
        // `bootstrap/providers.php` registration order. usort is not stable
        // but the secondary `name()` tiebreaker makes the result fully
        // deterministic anyway.
        usort($materialized, static function (FiscalEventProjector $a, FiscalEventProjector $b): int {
            $cmp = $a->priority() <=> $b->priority();
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp($a->name(), $b->name());
        });

        $this->projectors = $materialized;
    }

    /**
     * Resolve a projector by its `name()` identifier — used by the projection
     * job at run time (Task 23). Unlike `activeProjectorsFor()`, this lookup
     * IGNORES current module-activation status: at job-run time the
     * `fiscal_event_projections` row already exists (activation gating happened
     * at ingest time), so a module deactivated between ingest and run must
     * still be able to resolve its projector — otherwise the row would be
     * orphaned indefinitely. The uniqueness invariant enforced by the
     * constructor (Codex F2 round-2) guarantees a single match.
     *
     * Returns `null` when no projector with the given name is registered.
     * The caller (`ApplyFiscalEventProjectionJob`) treats this as a hard
     * misconfiguration: log critical, dead-letter the projection row, and
     * throw — the same fail-closed discipline applied to a missing
     * `FiscalEvent` row.
     */
    public function byName(string $name): ?FiscalEventProjector
    {
        foreach ($this->projectors as $projector) {
            if ($projector->name() === $name) {
                return $projector;
            }
        }

        return null;
    }

    /**
     * Iterate every registered projector in priority-then-name order
     * (Task 22 round-2 sort), **ignoring** the per-event handlesEventType
     * and per-tenant module-activation gates.
     *
     * Use this for boot-time / wiring-verification checks ("is the
     * TreasuryReceiptBridge tag wired through the provider?") and for
     * operator tooling that needs to enumerate the full projector set.
     * Production ingest + projection-run paths use
     * {@see activeProjectorsFor()} / {@see byName()} respectively.
     *
     * Returns a Generator so callers can `iterator_to_array()` it without
     * exposing the registry's private list as a writable array.
     *
     * @return \Generator<int, FiscalEventProjector>
     */
    public function all(): \Generator
    {
        foreach ($this->projectors as $projector) {
            yield $projector;
        }
    }

    /**
     * @return list<FiscalEventProjector> active projectors in priority-then-name order
     *                                    (Task 22 round-2 — replaces pre-round-2
     *                                    registration-order semantics)
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
