<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Fiscal;

use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;

/**
 * Pluggable fiscal-event projector — spec v7 §7.3 + SoT §13.6/D16.
 *
 * After `OutboxIngestor` (Task 19) verifies and stores a `fiscal_events`
 * row, it dispatches the event to every **active** projector registered
 * for that event type via the `FiscalEventProjectionRegistry`. A
 * projector translates the canonical fiscal event into its own module's
 * business-projection effects (POS-core receipt rows; Treasury Payment
 * rows + GL; etc.).
 *
 * **Activation gating.** Activeness for a given event is decided by:
 *   1. `handlesEventType()` — does the projector subscribe to this
 *      event class?
 *   2. `requiresModule()` — `null` for POS-core projectors (always run);
 *      a canonical PascalCase module token (`'Treasury'`, etc.) for
 *      module-bound bridges. When non-null, the registry queries
 *      `ModuleActivationResolver::isActive($module, $tenantId, $companyId)`
 *      against the EVENT's tenant/company — not request/user context.
 *
 * **Token contract.** `requiresModule()` returns the **exact** PascalCase
 * identifier used by `Vertical::defaultModules()`; `CompanyConfig::hasModule()`
 * strict-compares (`in_array(..., true)`), so a lowercase `'treasury'`
 * would be unreachable. Spec §7.3 P1 lock — tested by
 * `ModuleActivationResolverTest` (Task 17) + this registry's
 * `test_canonical_pascalcase_token_required`.
 *
 * **Idempotency.** `apply()` is idempotent, keyed on
 * `(fiscal_event_id, projector_name)` via the `fiscal_event_projections`
 * table (Task 9). A re-delivery (retry, restart) must produce the same
 * business effect — duplicate calls are not errors.
 *
 * **Bounded-modules guardrail (SoT §13.6/D16).** The registry depends
 * only on this interface + `ModuleActivationResolver` — never on
 * Treasury / accounting / sales code directly. Each projector is the
 * one bridge that knows BOTH the fiscal event shape AND its own
 * module's write surface; everything outside that boundary stays
 * dependency-free.
 */
interface FiscalEventProjector
{
    /**
     * Stable identifier — keyed on in `fiscal_event_projections.projector_name`
     * for idempotency. Convention: `snake_case`, prefix with the module
     * (`pos_core_receipt`, `treasury_receipt_bridge`).
     */
    public function name(): string;

    /**
     * Does this projector subscribe to the given event class?
     * The registry uses this as the first gate before consulting the
     * resolver — projectors that don't handle the event don't even
     * trigger an activation check.
     */
    public function handlesEventType(FiscalEventType $type): bool;

    /**
     * Canonical PascalCase module token, or `null` for always-active
     * projectors (POS-core). When non-null the registry calls
     * `ModuleActivationResolver::isActive($token, $event->tenant_id,
     * $event->company_id)` and excludes this projector if it reports
     * inactive.
     */
    public function requiresModule(): ?string;

    /**
     * Translate the fiscal event into module-side business effects.
     * Must be idempotent on `(event->id, $this->name())` per the
     * `fiscal_event_projections` idempotency contract.
     */
    public function apply(FiscalEvent $event): void;
}
