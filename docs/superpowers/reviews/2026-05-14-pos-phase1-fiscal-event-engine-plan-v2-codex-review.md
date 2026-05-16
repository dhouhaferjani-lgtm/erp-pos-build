# Adversarial review — POS Phase 1 Fiscal Event Engine implementation plan v2

**Reviewer:** Codex  
**Review date:** 2026-05-14  
**Plan reviewed:** `apps/erp/docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md`  
**Verdict:** BLOCK  
**Total findings:** 1 P1

## Executive summary

Plan v2 genuinely resolves the 8 findings from the v1 review, including the Task 8 ↔ Task 24 parse-failure resume contradiction and the missing `pos_receipts.fiscal_event_id` linkage.

However, the fresh v2 pass found a new task-ordering defect in the same class as the original provider-registration issue: the plan creates a minimal `FiscalServiceProvider` in Task 1, but defers route loading, interface bindings, and the remaining command registration until Task 26. Several earlier tasks require those provider responsibilities to make their own red/green tests pass. This blocks execution before Task 26.

## v1 finding closure check

| v1 finding | v2 status | Evidence |
|---|---|---|
| BLOCKER: Task 8 trigger forbids Task 24 `failed -> parsed` resume. | Closed. | Task 8 now has explicit tests for allowed and rejected `failed -> parsed` transitions and requires `OLD.payload IS NULL`, `NEW.payload IS NOT NULL`, canonical-parse-failure quarantine, `NEW.integrity_status='verified'`, and resolution stamps in the same update. Task 24's `ParseFailureResolutionService::resolve()` performs that same atomic update plus projection-row inserts. This matches the spec's atomic resume contract at `docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md:455-456`. |
| P1: `PosCoreReceiptProjection` relies on missing `pos_receipts.fiscal_event_id`. | Closed. | Current schema has no such column (`apps/api/database/migrations/2026_01_08_190637_create_pos_receipts_table.php:20-99`; `rg fiscal_event_id apps/api/database/migrations apps/api/app/Modules/POS` returns no POS receipt column). Task 11 now creates nullable unique FK `pos_receipts.fiscal_event_id` before Task 21 uses it as the projector guard. |
| P1: ingestion route dropped `EnforceTokenTenantClaim`. | Closed. | Existing POS route surfaces use `['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class]` at `apps/api/app/Modules/POS/routes.php:30` and `apps/api/app/Modules/POS/routes_orders.php:15`. Task 20 now specifies that same tuple and adds a mismatched-tenant-claim test. |
| P1: Task 1 command cannot pass before provider registration. | Closed for Task 1. | Module commands are registered via providers (`apps/api/app/Modules/POS/Providers/POSServiceProvider.php:39-45`), and module providers are listed in `apps/api/bootstrap/providers.php:50-97`. Task 1 now creates/registers a minimal `FiscalServiceProvider` in `bootstrap/providers.php`. |
| P2: sequence-gap detection only checked hash linkage. | Closed. | Task 19 now requires numeric continuity and hash linkage, and adds a numeric-gap-with-valid-hash test. This matches SoT anomaly handling for `sequence_gap` at `docs/superpowers/research/2026-05-14-offline-first-fiscal-source-of-truth-v3.md:131-133` and D13 at `:266`. |
| P2: Treasury `Payment` model path wrong. | Closed. | Actual model is `apps/api/app/Modules/Treasury/Domain/Payment.php:61-108`; Task 12 now edits that path and adds a writer-import verification step. Current writers import that class, e.g. `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:20`, `MultiPaymentService.php:10`, `PaymentRefundService.php:15`, and POS `ReceiptPaymentService.php:22`. |
| P2: Task 27 online-checkout deletion test tautological. | Closed. | Current Tauri code imports server-authoring methods at `apps/pos/src/lib/offline/offlineCheckoutService.ts:3`, defines `onlineCheckout()` at `:83-133`, and branches to it at `:184-189`; `receiptApi.ts` posts to `/pos/receipts` and `/pos/receipts/{id}/payments` at `apps/pos/src/api/receiptApi.ts:14-27`. Task 27 now uses behavior spies plus a source-level guard for these imports/helper names. |
| P3: Task 30 chokepoint grep brittle. | Closed. | Current grep hits are injected-service calls, not class-qualified: `ReceiptController.php:298`, `OrderToReceiptService.php:68`, `ExchangeService.php:220`, and finalize hits including unrelated `InventoryCountingController.php:282`. Task 30 now requires manifest reconciliation with receiver type and an explicit unrelated allowlist. |

## Findings

### [P1] Fiscal provider expansion is deferred past tasks that require it

**Dimension:** task ordering / TDD soundness / buildability

**Plan locations:** Task 17, Task 20, Task 24, Task 26. Task 26 says the provider expansion adds `loadRoutesFrom`, interface bindings, and the remaining `fiscal:*` commands; but Tasks 17, 20, and 24 each need one of those responsibilities earlier.

**Codebase evidence:** Module wiring is not automatic in this codebase. `bootstrap/providers.php` is the module provider list (`apps/api/bootstrap/providers.php:50-97`). The POS module demonstrates the pattern: command registration happens in the provider at `apps/api/app/Modules/POS/Providers/POSServiceProvider.php:39-45`, route loading at `:48-49`, and migration loading at `:51-52`. Existing POS routes are exposed only because `POSServiceProvider` is registered in `bootstrap/providers.php:74`.

**Issue:** v2 fixed Task 1 by creating a minimal provider, but then repeats the same ordering problem for later provider responsibilities:

- Task 17's test resolves `app(ModuleActivationResolver::class)`, but Task 17 only creates the interface and default resolver. The plan says the interface binding is added in `FiscalServiceProvider` in Task 26, so the Task 17 test cannot pass through the container.
- Task 20 creates `app/Modules/Fiscal/routes.php` and expects `POST /api/v1/pos/sync/fiscal-events` to pass, but the plan says `FiscalServiceProvider::loadRoutesFrom()` is added in Task 26. The route is therefore still undiscoverable in Task 20.
- Task 24 creates `EnqueueResolvedEventProjectionsCommand` and tests `$this->artisan('fiscal:enqueue-resolved-event-projections')`, but the plan registers the "remaining `fiscal:*` commands" in Task 26. The command is not discoverable when Task 24 runs.

This is the same mechanism that v1 already caught for `fiscal:preflight-gate`: a file under `app/Modules/Fiscal/...` does not become routable, bindable, or an artisan command just because it exists.

**Impact:** The plan cannot be executed task-by-task with the required red/green discipline. A worker following the plan will hit false failures before Task 26 or will need unplanned provider edits in earlier tasks, breaking the one-task/one-commit structure and the requested review gate.

**Concrete fix:** Move each provider responsibility into the first task that needs it:

- Task 17: modify `FiscalServiceProvider::register()` to bind `ModuleActivationResolver::class => DefaultModuleActivationResolver::class`, and include the provider file in the task's `git add`.
- Task 20: modify `FiscalServiceProvider::boot()` to `loadRoutesFrom(__DIR__.'/../routes.php')`, and include the provider file in the task's `git add`.
- Task 24: register `EnqueueResolvedEventProjectionsCommand` in the provider when the command is created, and include the provider file in the task's `git add`.
- Task 26 should only add wiring for artifacts first introduced in Task 26 or later. It must not register `VerifyEventChainCommand` before Task 31 creates it.

## Notes

No implementation was started because this review has an unresolved P1 and the requested decision gate requires stopping before Phase 1.
