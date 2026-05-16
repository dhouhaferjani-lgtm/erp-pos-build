# Focused adversarial review — POS Phase 1 Fiscal Event Engine implementation plan v3

**Reviewer:** Codex  
**Review date:** 2026-05-14  
**Plan reviewed:** `apps/erp/docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md`  
**Verdict:** BLOCK  
**Total findings:** 1 P1, 1 P3

## Executive summary

Plan v3 closes the v2 P1 for the originally cited tasks: bindings/routes/commands introduced in Tasks 6, 17, 18, 20, 24, and 31 are now wired in the same task, and the route/command provider edits are included in each task's `git add`. The v1 findings remain closed on the targeted spot checks: Task 8 still permits the exact Task 24 `failed -> parsed` parse-failure resume update, and Task 11 still creates `pos_receipts.fiscal_event_id` before Task 21 uses it.

The focused pass found a new unresolved ordering failure in Task 19. v3 correctly says the registry's tagged projector set is empty until Tasks 21/22, but Task 19's own green test requires at least one pending projection row. With only Tasks <=19 applied, no module provider has tagged any `FiscalEventProjector`, so the test cannot pass unless the plan adds an explicit test-local fake projector or changes the assertion.

## Focused checks

| Check | Result | Evidence |
|---|---|---|
| v2 P1: provider wiring for Task 17 binding. | Closed. | Task 17 now edits `FiscalServiceProvider::register()` and `git add`s the provider. Current module wiring is provider-driven in this repo: module providers are listed in `apps/api/bootstrap/providers.php:50-97`, and `POSServiceProvider` binds services in `register()` at `apps/api/app/Modules/POS/Providers/POSServiceProvider.php:26-34`. |
| v2 P1: provider wiring for Task 20 route loading. | Closed. | Task 20 now edits `FiscalServiceProvider::boot()` with `loadRoutesFrom()` and includes the provider in `git add`. Existing POS routes are provider-loaded at `apps/api/app/Modules/POS/Providers/POSServiceProvider.php:48-49`; current POS routes use the expected tenant middleware at `apps/api/app/Modules/POS/routes.php:30` and `apps/api/app/Modules/POS/routes_orders.php:15`. |
| v2 P1: provider wiring for Task 24 command registration. | Closed. | Task 24 now edits the provider command array and includes the provider in `git add`. Existing module command registration is provider-owned, e.g. `POSServiceProvider.php:41-45` and `TreasuryServiceProvider.php:30-33`. |
| Projector tags are owned by the consuming modules. | Closed. | Task 21 tags `PosCoreReceiptProjection` in `POSServiceProvider`; Task 22 tags `TreasuryReceiptBridge` in `TreasuryServiceProvider`. This matches the asymmetric seam in SoT D16: the fiscal engine publishes/registry-consumes contracts while POS/Treasury own their projectors. Current provider files are the correct extension points: `POSServiceProvider.php:26-34`, `TreasuryServiceProvider.php:14-24`. |
| v1 BLOCKER remains resolved. | Closed. | Task 8 still defines and tests `failed -> parsed` only for quarantined `canonical_parse_failure` with payload write + `quarantined -> verified` + resolution stamps. Task 24 performs the same atomic resolver update. This matches spec v7's parse-failure resume transaction at `docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md:455-456`. |
| v1 `pos_receipts.fiscal_event_id` regression check. | Closed. | Current schema has no fiscal linkage (`apps/api/database/migrations/2026_01_08_190637_create_pos_receipts_table.php:20-99`), and Task 11 adds nullable unique FK `pos_receipts.fiscal_event_id` before Task 21 uses it as the idempotency guard. |

## Findings

### [P1] Task 19 requires projection rows before any projectors are tagged

**Dimension:** task ordering / TDD soundness / buildability

**Plan locations:** Task 18 lines 1327; Task 19 lines 1355-1366 and 1449; Task 21/22 provider-wiring notes.

**Codebase evidence:** The repository does not auto-discover tagged services. Service registration happens in module providers listed in `apps/api/bootstrap/providers.php:50-97`. The current POS and Treasury providers have `register()` methods where the new tags would be added (`apps/api/app/Modules/POS/Providers/POSServiceProvider.php:26-34`; `apps/api/app/Modules/Treasury/Providers/TreasuryServiceProvider.php:14-24`). Existing providers show command/route loading must be explicit (`POSServiceProvider.php:41-52`; `TreasuryServiceProvider.php:26-34`). There are no current fiscal projector tags in the codebase before this plan is implemented.

**Issue:** v3's convention says Task 18 binds `FiscalEventProjectionRegistry` over `app->tagged(FiscalEventProjector::class)` and explicitly states "the tagged set is empty until Tasks 21/22 tag the real projectors; an empty registry is valid here." That is fine for container resolution.

But Task 19's first green test requires:

```php
$this->assertGreaterThan(0, DB::table('fiscal_event_projections')
    ->where('fiscal_event_id', $result->fiscalEventId)
    ->where('projection_status', 'pending')
    ->count());
```

With only Tasks <=19 applied, the registry has no tagged projectors, so `activeProjectorsFor()` returns none and Task 19 correctly inserts zero projection rows. The real projectors are introduced and tagged later in Tasks 21 and 22. A worker following the plan cannot make Task 19 green without either prematurely implementing/tagging a future projector or weakening the registry semantics.

**Impact:** This violates the task-by-task TDD contract. Task 19 cannot pass with only its own wiring plus earlier task wiring, which is the exact class of ordering issue v3 set out to eliminate. It also pressures an implementer to cross task boundaries by adding POS/Treasury projector tags before those projectors exist.

**Concrete fix:** Keep the empty real registry valid through Task 19, but make the Task 19 projection-row test explicitly provide an active test projector. For example:

- In `OutboxIngestorTest`, define a `FakeFiscalEventProjector` handling `SALE_RECEIPT`.
- In test setup, register/tag that fake with the container before resolving `OutboxIngestor`, or construct the registry with the fake and bind it into the container for the test.
- State in Task 19 that production wiring still has an empty tagged set until Tasks 21/22; the test-local fake exists only to prove `OutboxIngestor` inserts one row per active projector.
- Optionally add a separate Task 19 assertion that, with no tagged projectors, a verified event stores successfully and creates zero projection rows.

Do not move the real POS/Treasury projector tags earlier; Task 21/22 ownership is correct.

### [P3] Task 26 provider note incorrectly says Task 31 has already wired its command

**Dimension:** clarity / ordering hygiene

**Plan location:** Task 26 provider note.

**Codebase evidence:** Provider order is static in `apps/api/bootstrap/providers.php:50-97`, but task order is defined by the implementation plan. Task 31 creates `VerifyEventChainCommand`; before that task, there is no command class or provider registration to load. Existing module commands become available only when explicitly registered in provider `boot()` methods, e.g. `POSServiceProvider.php:41-45`.

**Issue:** Task 26 says that by the time Task 26 runs, "Tasks 1/6/17/18/20/24/31 each added their own binding/route/command." Task 31 cannot have run before Task 26. The actual Task 26 test only checks cumulative seam bindings/projector tags and does not require `fiscal:verify-event-chain`, so this is not a build blocker, but the note contradicts the incremental-wiring rule.

**Impact:** Minor confusion for implementers and reviewers. It could lead someone to register the Task 31 command prematurely in Task 26, reintroducing deferred/early provider drift.

**Concrete fix:** In Task 26's provider note and implementation text, remove Task 31 from the "already wired" list. Say Task 31 will add `VerifyEventChainCommand` later in its own task, per the convention. The Task 26 provider verification should remain limited to bindings and projectors available by Task 26.

## Decision

Stop before Phase 1. The unresolved P1 means implementation should not begin until Task 19's test/wiring plan is corrected and re-reviewed.
