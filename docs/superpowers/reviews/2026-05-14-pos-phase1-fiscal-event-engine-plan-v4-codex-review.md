# Focused adversarial review — POS Phase 1 Fiscal Event Engine implementation plan v4

**Reviewer:** Codex  
**Review date:** 2026-05-14  
**Plan reviewed:** `apps/erp/docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md`  
**Verdict:** APPROVE-WITH-MINOR-EDITS  
**Total findings:** 1 P3

## Executive summary

Plan v4 closes the v3 P1. Task 19 now keeps production projector tags in Tasks 21/22, while using a test-local `FakeSaleReceiptProjector` tagged before `OutboxIngestor` resolves to prove the "one projection row per active projector" behavior. It also has a companion empty-registry test proving event storage still succeeds with zero projection rows when no projectors are active. That makes Task 19 executable with only Tasks <=19 wiring.

Plan v4 also closes the v3 P3 in Task 26: the provider note now explicitly says Task 31 has not run yet and that `fiscal:verify-event-chain` is wired later in Task 31.

The only remaining issue is stale Task 1 wording that still says Task 26 expands the provider with routes/commands. The convention and per-task bodies are correct, so this is a P3 clarity edit, not a blocker.

## Focused checks

| Check | Result | Evidence |
|---|---|---|
| v3 P1: Task 19 can pass before real projectors exist. | Closed. | Task 18 still binds the registry over `app->tagged(FiscalEventProjector::class)` and permits an empty tag set. Task 19 now defines a test-local fake projector and tags it in `setUp()` before resolving `OutboxIngestor` (`docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md:1353-1359`). The first projection test asserts exactly one row (`:1361-1372`); the companion empty-registry test rebinds the registry empty and asserts zero rows (`:1375-1382`). |
| Fake projector tagging is buildable. | Closed. | Laravel's container has public `tag()` and `tagged()` APIs at `apps/api/vendor/laravel/framework/src/Illuminate/Container/Container.php:649-680`. A concrete fake class in the test file is container-makeable once tagged. The plan's empty-registry helper does not require a nonexistent public untag API; it says to rebind `FiscalEventProjectionRegistry` with an empty projector set (`docs/...plan...md:1378`). |
| Real projector tags stay in owning modules. | Closed. | Task 21 tags `PosCoreReceiptProjection` in `POSServiceProvider`; Task 22 tags `TreasuryReceiptBridge` in `TreasuryServiceProvider`. Those are the real provider extension points in this codebase (`apps/api/app/Modules/POS/Providers/POSServiceProvider.php:26-34`; `apps/api/app/Modules/Treasury/Providers/TreasuryServiceProvider.php:14-24`). This preserves SoT D16's asymmetric module seam. |
| v3 P3: Task 26 no longer claims Task 31 already ran. | Closed. | Task 26's provider note now says Tasks 1/6/17/18/20/24 are wired, Tasks 21/22 have tagged projectors, and "Task 31 has not run yet" (`docs/...plan...md:1932`). Its provider test scope checks seam bindings/projector tags, not `fiscal:verify-event-chain` (`:1957-1968`). |
| v1/v2 fixes remain intact. | Closed. | Task 8/24 parse-failure resume coherence remains per spec v7 §7.5 (`docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md:455-456`). Task 11 still creates `pos_receipts.fiscal_event_id` before Task 21 uses it; the current base `pos_receipts` table has no such column in `apps/api/database/migrations/2026_01_08_190637_create_pos_receipts_table.php:20-99`, so the planned additive migration is still necessary. Existing POS route middleware still includes `EnforceTokenTenantClaim` at `apps/api/app/Modules/POS/routes.php:30` and `apps/api/app/Modules/POS/routes_orders.php:15`, matching Task 20. |

## Findings

### [P3] Task 1 still describes Task 26 as the provider expansion task

**Dimension:** clarity / ordering hygiene

**Plan location:** Task 1 file list and provider explanation.

**Codebase evidence:** This repo exposes module commands and routes through service providers, not auto-discovery: module providers are listed in `apps/api/bootstrap/providers.php:50-97`; POS registers commands, routes, and migrations in `apps/api/app/Modules/POS/Providers/POSServiceProvider.php:41-52`; Treasury registers its route/command wiring in `apps/api/app/Modules/Treasury/Providers/TreasuryServiceProvider.php:26-34`.

**Issue:** The v4 convention is correct: every task wires the binding/route/command it introduces, and Task 26 is no longer the provider expansion task. But Task 1 still says the minimal provider is created there and "Task 26 expands it with routes/migrations/jobs/the remaining commands." That stale text contradicts the v4 convention and the later task bodies, even though the executable per-task wiring is now correct.

**Impact:** Minor implementer confusion. It could lead someone to defer or duplicate provider wiring despite the correct convention.

**Concrete fix:** Update Task 1 wording to say the provider starts minimal in Task 1 and grows incrementally in the task that introduces each binding/route/command. Remove the claim that Task 26 expands routes/commands.

## Decision

Proceed after applying the P3 wording edit. No unresolved BLOCKER/P1 remains.
