<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Application\Services\FiscalEventProjectionRegistry;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Shared\Contracts\Fiscal\FiscalEventProjector;
use App\Shared\Contracts\Fiscal\ModuleActivationResolver;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/**
 * Task 18 — `FiscalEventProjector` interface + `FiscalEventProjectionRegistry`.
 *
 * The pluggable-bridge seam per spec v7 §5.0 + §7.3. After `OutboxIngestor`
 * stores a `fiscal_events` row it dispatches the event to every **active**
 * projector for that event type. The registry resolves activeness via two
 * gates:
 *   1. `handlesEventType(FiscalEventType)` — the projector says it knows
 *      this event class (POS-core handles SALE_RECEIPT; a future
 *      Z_REPORT projector would not).
 *   2. `requiresModule()` — null means always-active (POS-core projectors);
 *      a canonical PascalCase token (`'Treasury'`, etc.) means
 *      `ModuleActivationResolver::isActive($module, $tenant, $company)`
 *      must report true for the event's `(tenant_id, company_id)` —
 *      otherwise the projector is excluded for this particular event.
 *
 * The registry depends **only** on the `FiscalEventProjector` and
 * `ModuleActivationResolver` interfaces — never on Treasury / accounting /
 * sales operational code (SoT §13.6/D16 bounded-modules asymmetric seam).
 */
final class FiscalEventProjectionRegistryTest extends TestCase
{
    // -----------------------------------------------------------------
    // Happy path — plan §1284 test-locks
    // -----------------------------------------------------------------

    public function test_pos_core_always_included_treasury_gated_when_active(): void
    {
        $registry = new FiscalEventProjectionRegistry(
            [new FakePosCore, new FakeTreasury],
            $this->resolverReporting(['Treasury' => true]),
        );

        $active = $registry->activeProjectorsFor($this->saleReceiptEvent());

        $this->assertEqualsCanonicalizing(
            ['pos_core_receipt', 'treasury_receipt_bridge'],
            array_map(static fn (FiscalEventProjector $p): string => $p->name(), $active),
        );
    }

    public function test_treasury_bridge_excluded_when_resolver_reports_inactive(): void
    {
        $registry = new FiscalEventProjectionRegistry(
            [new FakePosCore, new FakeTreasury],
            $this->resolverReporting(['Treasury' => false]),
        );

        $active = $registry->activeProjectorsFor($this->saleReceiptEvent());

        $this->assertSame(
            ['pos_core_receipt'],
            array_map(static fn (FiscalEventProjector $p): string => $p->name(), $active),
        );
    }

    public function test_only_projectors_handling_the_event_type_are_returned(): void
    {
        $registry = new FiscalEventProjectionRegistry(
            [new FakePosCore],
            $this->resolverReporting([]),
        );

        // FakePosCore handles SALE_RECEIPT only — a CHAIN_RESTART event must yield no projectors.
        $this->assertSame([], $registry->activeProjectorsFor($this->chainRestartEvent()));
    }

    // -----------------------------------------------------------------
    // Strictness locks
    // -----------------------------------------------------------------

    public function test_canonical_pascalcase_token_required(): void
    {
        // A projector whose `requiresModule()` returns lowercase `'treasury'`
        // is unreachable in production because `CompanyConfig::hasModule()`
        // strict-compares against PascalCase. The registry honors the
        // resolver's verdict — it does not normalize tokens. With the
        // resolver reporting `Treasury` (PascalCase) active but `treasury`
        // (lowercase) inactive, the lowercase-requiring projector is
        // excluded.
        $registry = new FiscalEventProjectionRegistry(
            [new FakeLowercaseTreasury],
            $this->resolverReporting(['Treasury' => true]),
        );

        $this->assertSame([], $registry->activeProjectorsFor($this->saleReceiptEvent()));
    }

    public function test_empty_projector_set_yields_empty_active_list(): void
    {
        // Task 21 tags `PosCoreReceiptProjection` into the production set,
        // but this constructor-injection test uses an explicit empty list
        // to validate the registry's behavior in the no-projector edge
        // case. An empty registry is a valid runtime state and must not
        // throw.
        $registry = new FiscalEventProjectionRegistry([], $this->resolverReporting([]));

        $this->assertSame([], $registry->activeProjectorsFor($this->saleReceiptEvent()));
    }

    public function test_resolver_called_with_event_tenant_and_company(): void
    {
        // The resolver MUST be invoked with the event's tenant_id and
        // company_id — not request context, not user context. This is the
        // ingest-path invariant: each event carries its own tenant/company
        // and the gating decision is per-event.
        $calls = [];
        $resolver = new class($calls) implements ModuleActivationResolver
        {
            /** @param  array<int, array{string, string, string}>  $calls */
            public function __construct(public array &$calls) {}

            public function isActive(string $module, string $tenantId, string $companyId): bool
            {
                $this->calls[] = [$module, $tenantId, $companyId];

                return true;
            }
        };

        $registry = new FiscalEventProjectionRegistry([new FakeTreasury], $resolver);
        $event = $this->saleReceiptEvent('tn-xyz', 'co-xyz');

        $registry->activeProjectorsFor($event);

        $this->assertCount(1, $calls);
        $this->assertSame(['Treasury', 'tn-xyz', 'co-xyz'], $calls[0]);
    }

    public function test_resolver_not_called_for_always_active_projectors(): void
    {
        // POS-core's `requiresModule()` is null; no resolver call should
        // happen for those. The resolver is exclusively the gate for
        // module-bound bridges.
        $resolver = new class implements ModuleActivationResolver
        {
            public int $callCount = 0;

            public function isActive(string $module, string $tenantId, string $companyId): bool
            {
                $this->callCount++;

                return true;
            }
        };

        $registry = new FiscalEventProjectionRegistry([new FakePosCore], $resolver);
        $registry->activeProjectorsFor($this->saleReceiptEvent());

        $this->assertSame(0, $resolver->callCount);
    }

    public function test_active_projectors_returned_in_registration_order(): void
    {
        // Determinism: the registry preserves the order projectors were
        // registered in. This matters because `OutboxIngestor` (Task 19)
        // creates projection rows + enqueues jobs in this order; a stable
        // ordering keeps the audit trail predictable.
        $registry = new FiscalEventProjectionRegistry(
            [new FakeTreasury, new FakePosCore],
            $this->resolverReporting(['Treasury' => true]),
        );

        $names = array_map(
            static fn (FiscalEventProjector $p): string => $p->name(),
            $registry->activeProjectorsFor($this->saleReceiptEvent()),
        );

        $this->assertSame(['treasury_receipt_bridge', 'pos_core_receipt'], $names);
    }

    public function test_resolves_from_container_singleton(): void
    {
        // The FiscalServiceProvider binding makes the registry container-
        // resolvable so Task 19 (`OutboxIngestor`) can constructor-inject
        // it. After Task 21 (`PosCoreReceiptProjection` tagged in
        // `POSServiceProvider::register()`), the production tagged set
        // contains at least the POS-core projector — assert it resolves
        // cleanly and the projector is active for a SALE_RECEIPT event.
        $registry = $this->app->make(FiscalEventProjectionRegistry::class);

        $this->assertInstanceOf(FiscalEventProjectionRegistry::class, $registry);
        $names = array_map(
            static fn (FiscalEventProjector $p): string => $p->name(),
            $registry->activeProjectorsFor($this->saleReceiptEvent()),
        );
        $this->assertContains('pos_core_receipt', $names);
    }

    // -----------------------------------------------------------------
    // Codex round-2 — F1 fail-closed / F2 unique names / F3 empty token
    // -----------------------------------------------------------------

    public function test_resolver_exception_fails_closed_and_excludes_gated_projector(): void
    {
        // Codex F1 round-2 — §7.2 requires the fiscal event row to always be
        // persisted and the device never blocked. A resolver throw (DB
        // outage, cache backend down) must NOT crash the registry call —
        // POS-core projectors proceed; the gated projector is excluded.
        $resolver = new class implements ModuleActivationResolver
        {
            public function isActive(string $module, string $tenantId, string $companyId): bool
            {
                throw new RuntimeException('simulated DB outage from resolver');
            }
        };

        $registry = new FiscalEventProjectionRegistry(
            [new FakePosCore, new FakeTreasury],
            $resolver,
        );

        // Silence the warning log so the assertion output is clean — also
        // confirms the log call site fires (no exception bubbles up).
        Log::shouldReceive('error')->once()->withArgs(
            static function (string $message, array $context): bool {
                return str_contains($message, 'failing closed')
                    && $context['projector'] === 'treasury_receipt_bridge'
                    && $context['module'] === 'Treasury'
                    && $context['exception'] === RuntimeException::class;
            },
        );

        $active = $registry->activeProjectorsFor($this->saleReceiptEvent());

        $this->assertSame(
            ['pos_core_receipt'],
            array_map(static fn (FiscalEventProjector $p): string => $p->name(), $active),
            'POS-core (always-active) survives a resolver outage; Treasury bridge fails closed.',
        );
    }

    public function test_constructor_rejects_duplicate_projector_names(): void
    {
        // Codex F2 round-2 — UNIQUE constraint on `fiscal_event_projections
        // (fiscal_event_id, projector_name)` per spec §7.5 makes duplicate
        // names a downstream constraint violation. Fast-fail at boot is
        // cheaper than a runtime DB error in projection.
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/Duplicate FiscalEventProjector name "pos_core_receipt"/');

        new FiscalEventProjectionRegistry(
            [new FakePosCore, new FakePosCore],
            $this->resolverReporting([]),
        );
    }

    public function test_constructor_rejects_empty_requires_module_token(): void
    {
        // Codex F3 round-2 — §7.3 mandates `null` (always-active) OR a
        // canonical PascalCase token. An empty/whitespace-only non-null
        // token is a programming error in the projector that would
        // silently always-deactivate via strict-compare.
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/returned an empty `requiresModule\(\)` token/');

        new FiscalEventProjectionRegistry(
            [new FakeEmptyTokenProjector],
            $this->resolverReporting([]),
        );
    }

    public function test_constructor_rejects_whitespace_only_requires_module_token(): void
    {
        // Same class of bug, whitespace-only token.
        $this->expectException(LogicException::class);

        new FiscalEventProjectionRegistry(
            [new FakeWhitespaceTokenProjector],
            $this->resolverReporting([]),
        );
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Build a fake `ModuleActivationResolver` whose `isActive()` returns
     * true for modules in `$activeMap` and false otherwise.
     *
     * @param  array<string, bool>  $activeMap
     */
    private function resolverReporting(array $activeMap): ModuleActivationResolver
    {
        return new class($activeMap) implements ModuleActivationResolver
        {
            /** @param  array<string, bool>  $activeMap */
            public function __construct(private array $activeMap) {}

            public function isActive(string $module, string $tenantId, string $companyId): bool
            {
                unset($tenantId, $companyId);

                return $this->activeMap[$module] ?? false;
            }
        };
    }

    private function saleReceiptEvent(string $tenantId = 'tn-1', string $companyId = 'co-1'): FiscalEvent
    {
        return $this->makeEvent(FiscalEventType::SALE_RECEIPT, $tenantId, $companyId);
    }

    private function chainRestartEvent(): FiscalEvent
    {
        return $this->makeEvent(FiscalEventType::CHAIN_RESTART, 'tn-1', 'co-1');
    }

    private function makeEvent(FiscalEventType $type, string $tenantId, string $companyId): FiscalEvent
    {
        $event = new FiscalEvent;
        $event->id = Str::uuid()->toString();
        $event->tenant_id = $tenantId;
        $event->company_id = $companyId;
        $event->event_type = $type;

        return $event;
    }
}

// =====================================================================
// Fake projectors used by this test file. They live in the same file
// (test-only helpers) so the Test class self-contains its fixtures —
// Task 21 / Task 22 add the production projectors
// (`PosCoreReceiptProjection`, `TreasuryReceiptBridge`); the registry's
// shape contract is exercised here against deterministic test doubles.
// =====================================================================

final class FakePosCore implements FiscalEventProjector
{
    public function name(): string
    {
        return 'pos_core_receipt';
    }

    public function handlesEventType(FiscalEventType $type): bool
    {
        return $type === FiscalEventType::SALE_RECEIPT;
    }

    public function requiresModule(): ?string
    {
        return null;
    }

    public function apply(FiscalEvent $event): void
    {
        // no-op fake
        unset($event);
    }
}

final class FakeTreasury implements FiscalEventProjector
{
    public function name(): string
    {
        return 'treasury_receipt_bridge';
    }

    public function handlesEventType(FiscalEventType $type): bool
    {
        return $type === FiscalEventType::SALE_RECEIPT;
    }

    // PHP 8+ covariance — narrows the interface's `?string` to `string`
    // for this always-Treasury-bound fake. Keeps phpstan happy on
    // return.unusedType while honoring the interface contract.
    public function requiresModule(): string
    {
        return 'Treasury';
    }

    public function apply(FiscalEvent $event): void
    {
        unset($event);
    }
}

final class FakeLowercaseTreasury implements FiscalEventProjector
{
    public function name(): string
    {
        return 'lowercase_treasury_bug';
    }

    public function handlesEventType(FiscalEventType $type): bool
    {
        return $type === FiscalEventType::SALE_RECEIPT;
    }

    // Intentionally lowercase — the canonical token is 'Treasury' per
    // spec §7.3. A resolver that strict-compares (Phase 1 default)
    // will report this as inactive. Covariant `string` return per the
    // FakeTreasury rationale above.
    public function requiresModule(): string
    {
        return 'treasury';
    }

    public function apply(FiscalEvent $event): void
    {
        unset($event);
    }
}

// Empty-token + whitespace-token fakes used by the Codex F3 round-2 tests.

final class FakeEmptyTokenProjector implements FiscalEventProjector
{
    public function name(): string
    {
        return 'empty_token_bug';
    }

    public function handlesEventType(FiscalEventType $type): bool
    {
        unset($type);

        return true;
    }

    public function requiresModule(): string
    {
        return '';
    }

    public function apply(FiscalEvent $event): void
    {
        unset($event);
    }
}

final class FakeWhitespaceTokenProjector implements FiscalEventProjector
{
    public function name(): string
    {
        return 'whitespace_token_bug';
    }

    public function handlesEventType(FiscalEventType $type): bool
    {
        unset($type);

        return true;
    }

    public function requiresModule(): string
    {
        return "  \t  ";
    }

    public function apply(FiscalEvent $event): void
    {
        unset($event);
    }
}
