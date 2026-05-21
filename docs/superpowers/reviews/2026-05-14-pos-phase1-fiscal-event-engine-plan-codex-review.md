# Adversarial review — POS Phase 1 Fiscal Event Engine implementation plan
**Reviewer:** Codex
**Review date:** 2026-05-14
**Plan reviewed:** `apps/erp/docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md`
**Verdict:** BLOCK
**Total findings:** 1 BLOCKER, 3 P1, 3 P2, 1 P3

## Executive summary
- The plan is faithful to the locked device-authority / typed fiscal-event direction, but it has one build-stopping internal contradiction: Task 8's immutability trigger forbids the exact `failed -> parsed` parse-failure resume path Task 24 is required to implement.
- The plan also assumes a `pos_receipts.fiscal_event_id` idempotency key that no task creates and no current POS schema contains.
- The new fiscal ingestion endpoint omits the existing tenant-claim middleware used by POS/Treasury routes, which is a tenant-boundary regression.
- The first task cannot pass in the stated order unless minimal Fiscal module provider registration is moved forward.
- Several smaller execution hazards remain: wrong Treasury `Payment` model path, incomplete sequence-gap test/algorithm, and a tautological Tauri online-branch deletion test.

## Codebase-claim verification
| Claim | Verdict | Evidence |
|---|---|---|
| Existing POS routes use the API prefix with Sanctum auth and tenant/team context. | Confirmed, but plan omits one middleware. | `apps/api/app/Modules/POS/routes.php:30` and `apps/api/app/Modules/POS/routes_orders.php:15` use `['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class]`; plan Task 20 specifies only `['api', 'auth:sanctum', SetPermissionsTeam::class]` at lines 1378 and 1424. |
| Treasury `Payment` model exists and needs origin fields. | Confirmed with path correction. | Actual model is `apps/api/app/Modules/Treasury/Domain/Payment.php:70-94`; `$fillable` has no `origin` / `fiscal_event_id`. Plan line 872 stages non-existent `Domain/Models/Payment.php`. |
| `pos_receipts` currently has receipt chain fields but no fiscal-event linkage. | Confirmed. | `apps/api/database/migrations/2026_01_08_190637_create_pos_receipts_table.php:20-99` defines the table and fiscal hash/sequence columns; `rg fiscal_event_id apps/api/database/migrations apps/api/app/Modules/POS` returns no POS receipt column. |
| Current immutability trigger pattern enforces explicit transitions by raising in PostgreSQL trigger code. | Confirmed. | `apps/api/database/migrations/2026_05_01_000003_update_pos_receipts_immutability_trigger_for_pending_seal.php:30-65` allows only named transitions and raises otherwise. |
| Tauri online checkout is a real server-authoring branch today. | Confirmed. | `apps/pos/src/lib/offline/offlineCheckoutService.ts:83-115` calls `createReceipt()` and `processReceiptPayments()`; `executeCheckout()` selects it when online at `apps/pos/src/lib/offline/offlineCheckoutService.ts:178-199`. |
| Server-side order close reaches `ReceiptCreationService::createReceipt()`. | Confirmed and covered by Task 30's chokepoint gate. | `apps/api/app/Modules/POS/routes_orders.php:28`, `apps/api/app/Modules/POS/Presentation/Controllers/OrderController.php:353-358`, `apps/api/app/Modules/POS/Application/Services/OrderToReceiptService.php:68-74`. |
| Provider registration is how module commands/routes are exposed. | Confirmed. | `apps/api/app/Modules/POS/Providers/POSServiceProvider.php:39-52` registers commands/routes/migrations; module providers are listed in `apps/api/bootstrap/providers.php:51-96`. |
| Owner strategy requires immutable fiscal events and POS local ledger source-of-truth. | Confirmed. | `~/Downloads/fiscal_chain_architecture_strategy.md:40-44` and `:530-538`; printable document strategy separates fiscal events from receipts/payments at `~/Downloads/pos_printable_documents_architecture.md:18-28` and centers immutable fiscal events at `:644-657`. |

## Findings

### [BLOCKER] Parse-failure resume is impossible under the planned immutability trigger
**Dimension:** buildability / completeness

**Plan location:** Task 8 lines 670-672; Task 24 lines 1653-1713.

**Grounding citation:** Spec v7 §7.5 requires parse-failure resolution to write `payload`, flip `payload_parse_status -> parsed`, and insert projection rows in one transaction at `docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md:455-456`. SoT D3 requires canonical bytes be stored verbatim and structured payload derived by strict parser at `docs/superpowers/research/2026-05-14-offline-first-fiscal-source-of-truth-v3.md:256`; SoT D13 requires per-class anomaly handling at `:266`.

**Codebase evidence:** The existing trigger pattern is transition-explicit and raises outside the allowed transitions at `apps/api/database/migrations/2026_05_01_000003_update_pos_receipts_immutability_trigger_for_pending_seal.php:30-65`.

**Issue:** Task 8 tells the trigger to allow `payload_parse_status` only `pending -> parsed` or `pending -> failed`, with payload write only when status goes `-> parsed` (`lines 670-672`). Task 24 starts from a stored parse-failed event with `payload_parse_status='failed'`, `payload NULL`, and `integrity_status='quarantined'`, then requires the resolver to write `payload` and flip the row to `parsed` (`lines 1667-1673`, `1713`). Under Task 8's own trigger rules, Task 24's update is rejected.

**Impact:** The canonical parse-failure recovery path required by the spec cannot be implemented or tested. Any parse-failed fiscal event becomes permanently unprojectable, contradicting the Phase 1 exception path and leaving operator recovery dead on arrival.

**Suggested fix:** Change Task 8 before implementation: add a named allowed transition `payload_parse_status failed -> parsed` only when all of these hold in the same update: `OLD.payload IS NULL`, `NEW.payload IS NOT NULL`, `OLD.integrity_exception_class='canonical_parse_failure'`, `OLD.integrity_status='quarantined'`, `NEW.integrity_status='verified'`, and `integrity_resolved_at` / `integrity_resolved_by` are set. Add this as a failing test in Task 8, not only in Task 24, so the trigger is built to support the required resume contract from the start.

### [P1] `PosCoreReceiptProjection` relies on a `pos_receipts.fiscal_event_id` column that no task creates
**Dimension:** buildability / completeness

**Plan location:** Task 21 lines 1440-1504, especially line 1492.

**Grounding citation:** Spec v7 requires projection idempotency keyed on `(fiscal_event_id, projector_name)` in `fiscal_event_projections` at `docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md:432-448` and says the POS-core projection is idempotent at `:415-420`. SoT D8 requires one fiscal pattern, not two coexisting receipt-chain models, at `docs/superpowers/research/2026-05-14-offline-first-fiscal-source-of-truth-v3.md:261`.

**Codebase evidence:** Current `pos_receipts` schema has no `fiscal_event_id` column at `apps/api/database/migrations/2026_01_08_190637_create_pos_receipts_table.php:20-99`, and `rg fiscal_event_id apps/api/database/migrations apps/api/app/Modules/POS` returns no POS receipt linkage.

**Issue:** Task 21 says `apply()` is idempotent by skipping when a `pos_receipts` row already references `this fiscal_event_id` (`line 1492`), but no earlier task adds that column to `pos_receipts`. Task 11 adds `canonical_bytes` and mirror chain fields, not a fiscal-event foreign key. The test also does not assert the linkage column exists.

**Impact:** The POS-core projector cannot be implemented as specified. A developer will either add an unplanned schema change mid-task or fall back to weaker idempotency, risking duplicate receipt/line/payment projections on replay.

**Suggested fix:** Add `pos_receipts.fiscal_event_id UUID NULL UNIQUE REFERENCES fiscal_events(id)` in the server projection-table task, update the POS receipt model/fillable if needed, and add a Task 21 assertion that a duplicate projector run does not create a second receipt because the existing receipt references the fiscal event. If the intended idempotency source is only `fiscal_event_projections`, remove the `pos_receipts.fiscal_event_id` guard from Task 21 and define the exact projection-row locking/update protocol instead.

### [P1] New fiscal ingestion route drops the existing tenant-claim middleware
**Dimension:** architecture boundaries / buildability

**Plan location:** Task 20 lines 1376-1424.

**Grounding citation:** SoT §13.6/D16 requires the engine to be standalone, but not tenant-context-light; bounded modules compose through the server mirror and projectors at `docs/superpowers/research/2026-05-14-offline-first-fiscal-source-of-truth-v3.md:242-246` and `:269`.

**Codebase evidence:** Existing POS routes include `EnforceTokenTenantClaim::class` at `apps/api/app/Modules/POS/routes.php:30`; POS order routes include it at `apps/api/app/Modules/POS/routes_orders.php:15`. Task 20 requires only `['api', 'auth:sanctum', SetPermissionsTeam::class]` at lines 1378 and 1424.

**Issue:** The ingestion endpoint is under the POS sync prefix and writes fiscal chain truth, but the plan's route middleware is weaker than the existing POS route surface. It keeps auth/team middleware but drops the tenant-claim enforcement used by current POS APIs.

**Impact:** The new fiscal-event ingestion route can be built with a different tenant boundary from the rest of POS. That is not just a security concern; it can corrupt the chain by accepting envelopes under the wrong tenant context.

**Suggested fix:** Change Task 20 to use the same middleware tuple as existing POS routes: `['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class]`. Add a failing test that a token with a mismatched tenant claim cannot ingest a fiscal envelope.

### [P1] Task 1's command cannot pass before provider registration, but provider registration is deferred to Task 26
**Dimension:** task ordering / TDD soundness

**Plan location:** Task 1 lines 71-148; Task 26 referenced by Task 20 at line 1424.

**Grounding citation:** The approved spec makes the preflight gate a prerequisite before destructive rebuild work; the plan repeats that it blocks Tasks 7-13 and 28-30 at `docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md:71-73`.

**Codebase evidence:** Existing module commands are registered in module service providers: `apps/api/app/Modules/POS/Providers/POSServiceProvider.php:39-45`. Module providers are listed in `apps/api/bootstrap/providers.php:51-96`.

**Issue:** Task 1 creates `PreflightFiscalGateCommand` and expects `php artisan fiscal:preflight-gate` to pass by the end of the task (`lines 93-100`, `134-137`), but the Fiscal provider and route/command binding work is deferred until Task 26. A command under `app/Modules/Fiscal/...` will not be automatically discoverable by the current module pattern.

**Impact:** The first task fails in execution, before the schema-destructive gate can be signed off. Developers may be tempted to bypass the preflight command or move forward without the intended blocking artifact.

**Suggested fix:** Split out a minimal `FiscalServiceProvider` in Task 1 and register it in `apps/api/bootstrap/providers.php`, with only the preflight command bound initially. Task 26 can then expand the same provider with routes, migrations, jobs, and the remaining commands.

### [P2] Sequence-gap detection is under-specified and can miss numeric gaps with valid hash linkage
**Dimension:** buildability / TDD soundness

**Plan location:** Task 19 lines 1338-1359.

**Grounding citation:** SoT D13 requires `sequence_gap` to be an accept-and-incident anomaly class at `docs/superpowers/research/2026-05-14-offline-first-fiscal-source-of-truth-v3.md:266`. Spec v7 distinguishes occupied-slot `sequence_conflict` handling at `docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md:360-380`.

**Codebase evidence:** The existing receipt finalizer takes the terminal's current sequence and increments it as chain state at `apps/api/app/Modules/POS/Application/Services/ReceiptFinalizationService.php:65-81`.

**Issue:** Task 19 defines `linkage_ok` only as `previous_hash == prior event's current_hash for terminal` (`line 1356`). The test for a gap uses sequence 5 and says "previous_hash links nothing" (`lines 1338-1344`), so it tests broken hash linkage, not a numeric sequence gap. A device could send sequence 5 with `previous_hash` equal to sequence 1's current hash; the plan's stated check would pass even though sequence numbers 2-4 are missing.

**Impact:** The server mirror can mark a numerically gapped chain as verified instead of `sequence_gap`, undermining the exception totals and recovery workflow.

**Suggested fix:** Task 19 must validate both hash linkage and numeric continuity: for a non-first event, `sequence_number == prior.sequence_number + 1` and `previous_hash == prior.current_hash`; for a first event, `sequence_number == 1` and the configured genesis previous-hash rule holds. Add a test where sequence 5 references sequence 1's current hash correctly and still becomes `sequence_gap`.

### [P2] The Treasury `Payment` model path in Task 12 is wrong
**Dimension:** codebase-claim / execution

**Plan location:** Task 12 lines 827-874.

**Grounding citation:** SoT D16 permits Treasury as a consuming bridge but forbids the fiscal engine from depending on Treasury operations at `docs/superpowers/research/2026-05-14-offline-first-fiscal-source-of-truth-v3.md:269`; Task 12 is the additive Treasury-side linkage for that bridge.

**Codebase evidence:** Actual model path is `apps/api/app/Modules/Treasury/Domain/Payment.php:70-94`; there is no `apps/api/app/Modules/Treasury/Domain/Models/Payment.php`.

**Issue:** Task 12 correctly says to locate the model via grep at line 862, but the concrete commit command stages `apps/api/app/Modules/Treasury/Domain/Models/Payment.php` at line 872. That path is false.

**Impact:** The task will fail at the staging step and may cause a developer to create a duplicate model namespace or miss the real `$fillable` / casts edit.

**Suggested fix:** Replace the path everywhere with `apps/api/app/Modules/Treasury/Domain/Payment.php`, and add a short verification step that `App\Modules\Treasury\Domain\Payment` is the model imported by the payment writers.

### [P2] The plan's online-checkout deletion test is tautological
**Dimension:** TDD soundness

**Plan location:** Task 27 lines 1879-1904.

**Grounding citation:** Spec v7 §17.5 requires an integration test that online `executeCheckout()` authors via `FiscalEventEngine.append()` and does not call old server receipt routes at `docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md:758-765`.

**Codebase evidence:** `onlineCheckout()` is currently a non-exported local function at `apps/pos/src/lib/offline/offlineCheckoutService.ts:83-115`; only `executeCheckout()` is exported at `:178-199`.

**Issue:** The test `expect((offlineCheckoutService as Record<string, unknown>).onlineCheckout).toBeUndefined()` passes before any implementation because `onlineCheckout` is not exported today. The behavioral spy test at lines 1880-1885 is useful; this deletion test is not.

**Impact:** The plan creates a false sense that the online server-authoring branch was removed. A developer can leave the private `onlineCheckout()` helper in place and still satisfy that assertion.

**Suggested fix:** Replace the namespace-property assertion with a behavior or static-source test that fails today: online `executeCheckout()` must not invoke `createReceipt` or `processReceiptPayments`, and the source file must not import those methods from `receiptApi.ts`. The first test already covers `createReceipt`; extend it to `processReceiptPayments` and/or add a lint/grep check.

### [P3] Task 30's chokepoint test has a brittle method-name grep shape
**Dimension:** TDD soundness / completeness

**Plan location:** Task 30 lines 2060-2109.

**Grounding citation:** Spec v7 §14.3 makes the two-chokepoint completeness rule a CI gate, not a one-time review artifact, at `docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md:655-678`; §17.5 repeats the CI-grep requirement at `:764`.

**Codebase evidence:** `OrderToReceiptService` injects `ReceiptCreationService` and calls `->createReceipt()` without a class-qualified method name at `apps/api/app/Modules/POS/Application/Services/OrderToReceiptService.php:68-74`; the route/controller entrypoint is `apps/api/app/Modules/POS/routes_orders.php:28` and `apps/api/app/Modules/POS/Presentation/Controllers/OrderController.php:353-358`.

**Issue:** The test pseudocode asks `grepProductionCallers('ReceiptCreationService::createReceipt', '->createReceipt(')`. If implemented naively, the class-qualified search will miss normal injected-service calls, while the broad method-name search will include unrelated `createReceipt` methods unless it resolves receiver type. The plan's shell gate at line 2109 is the load-bearing protection; it needs to define the exact matching strategy.

**Impact:** The anti-whack-a-mole CI gate can pass while missing a server-authoring caller, or fail on unrelated methods and become noisy enough that developers bypass it.

**Suggested fix:** Make the gate type-aware enough for this codebase: use `rg` to collect `->createReceipt(` and `->finalize(` callers, then require each hit to be mapped in a checked-in manifest with file path, class, method, receiver type/rationale, and disposition. Keep an explicit allowlist for unrelated `finalize` methods such as inventory counting.

## Out-of-scope notes
- The approved spec review carried forward the training-mode server-authoring exception as an out-of-scope concern. This plan addresses it through Task 28's `/pos/receipts/sync` retirement and Task 30's chokepoint gate; I did not re-block on the locked device-authority architecture.
- The current CompanyConfig/module activation model makes every current vertical include Treasury by default. That was accepted in the spec as a seam-first Phase 1 implementation; I did not reopen it here.
