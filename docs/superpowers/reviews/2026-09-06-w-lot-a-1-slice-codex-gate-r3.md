# Codex slice-plan gate r3 — W-LOT-A-1 rev 3 (gpt-5.6-sol, high, read-only, 2026-09-06)

Input: rev 3 at 5a33e1ae1. Verbatim.

---
# Gate result

Rev 3 is not dispatch-ready. I found three blockers, four majors, and two minors. Review was read-only; no tests or writes were performed. Static inspection covered every claimed red-first case.

Reviewed HEAD: `3d5176bc4`. The plan’s baseline is stale, but relevant W-LOT-A production paths have not changed since its cited baseline. Existing worktree changes are unrelated.

## BLOCKER

### B1 — Root and transition idempotency share a collision namespace

- Plan: [rev3:119](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-3.md:119), [rev3:502](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-3.md:502), [rev3:513](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-3.md:513), [rev3:635](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-3.md:635)
- Source contract: [owner ruling:151](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:151)
- Failure scenario: the table uses one company-wide unique constraint for both client-supplied root operation UUIDs and predictable UUIDv5 transition UUIDs. After receiving a request ID, a requester can compute its future `recalled` transition UUID and use it as the operation UUID of another root request. The later GM recall then fails `brq_company_operation_uq`, preventing the ruled safety transition.
- Minimum correction: give roots and transitions distinct idempotency namespaces—such as entry-kind-qualified partial unique constraints or separate columns—and define replay lookups accordingly. Add an adversarial collision test while preserving the status-independent single-terminal constraint.

### B2 — The recall-writer census remains incomplete

- Plan: [rev3:927](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-3.md:927), especially the claimed test-call-site list at [rev3:946](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-3.md:946)
- Omitted writers/setups:
  - [CountingVarianceAppliedTest.php:606](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Feature/Inventory/CountingVarianceAppliedTest.php:606)
  - [CountingVarianceAppliedTest.php:628](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Feature/Inventory/CountingVarianceAppliedTest.php:628)
  - [StockAdjustmentBatchDispositionTest.php:845](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Feature/Inventory/StockAdjustmentBatchDispositionTest.php:845)
  - [BatchEntityTest.php:116](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Unit/BatchExpiry/BatchEntityTest.php:116)
- Failure scenario: Task 5 removes recall fields from `$fillable`. These unlisted `update()` and constructor fixtures will stop establishing recalled state or fail their behavior assertions. The proposed production AST ratchet does not discover these test call sites.
- Minimum correction: extend the census and Task 5 exact-file list to every direct test setup, prescribe the replacement mechanism for each, and add a test-source census/explicit allowlist so future direct historical-state setups are classified. The production ratchet should remain strict.

### B3 — W-LOT-B’s required canonical eligibility interface is not supplied

- Plan: [rev3:763](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-3.md:763), [rev3:768](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-3.md:768), [rev3:788](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-3.md:788)
- Downstream contract: [W-LOT-B:111](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-b-pos-lot-display-capture-consumption-execution-plan.md:111), particularly [W-LOT-B:119](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-b-pos-lot-display-capture-consumption-execution-plan.md:119)
- Current seam: [FEFOInventoryService.php:234](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:234), [FEFOInventoryService.php:262](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:262)
- Failure scenario: rev3 exposes only `isHeld()`/`assertCanIssue()`. W-LOT-B explicitly requires one canonical predicate covering company, product/variant, location, active, global recall, expiry, reservations, and local hold. Its payload would therefore need to duplicate eligibility logic. In addition, the preserved atomic FEFO signature has no `companyId`, and its current SQL has no company predicate.
- Minimum correction: specify a public typed eligibility decision contract containing all required dimensions and output fields, including source revision/age needed by D4. Route all three server issue paths and W-LOT-B snapshot generation through it. Define company derivation or add company identity to FEFO’s contract and SQL.

## MAJOR

### M1 — Push 3 is not inert and rollback restores unsafe reads

- Plan: [rev3:282](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-3.md:282), especially rows [284](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-3.md:284) and [287](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-3.md:287)
- Manifest: [staging manifest:114](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:114), which requires all behavior inert
- Current behavior: existing recall executes at [BatchController.php:193](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:193)
- Failure scenario: Push 3 with the flag OFF changes existing recall/deactivate routes from their HEAD behavior to 404. That is not inert. Later rollback also restores legacy unscoped reads, reopening the cross-location disclosure fixed at activation.
- Minimum correction: distinguish pre-activation dormant compatibility from post-activation safety rollback. Push 3 OFF must preserve existing behavior while new endpoints remain unavailable; after activation, turning authoring OFF must retain permission and location read enforcement plus hold-aware issue safety.

### M2 — Required `product_batches` convention-09 registration is absent

- Plan scope: Tasks 1, 4, 5, and 6 touch the lot catalogue entity, but no task lists the catalogue ratchet file.
- Convention: [09:30](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/09-SECOND-OF-EVERYTHING.md:30), [09:79](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/09-SECOND-OF-EVERYTHING.md:79)
- Explicit spec requirement: [spec v4:436](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:436)
- Current ratchet: [TenantOnlyUniqueOnCatalogueTablesRatchetTest.php:30](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Architecture/TenantOnlyUniqueOnCatalogueTablesRatchetTest.php:30) omits `product_batches`.
- Failure scenario: the slice can ship while the canonical catalogue manifest remains incomplete, contrary to the specific W8 remediation instruction.
- Minimum correction: add the architecture test file to an exact task file list, register `product_batches`, preserve its company-scoped uniques, and name the required PostgreSQL second-company, second-location, and rerun evidence.

### M3 — Public recall contracts import an Identity model across a module boundary

- Plan: [rev3:665](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-3.md:665), [rev3:954](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-3.md:954)
- Repository rule: [CLAUDE.md:31](/Users/houssamr/Projects/syneriva/apps/erp/CLAUDE.md:31)
- Failure scenario: `BatchRecallService::request(User $actor, …)` and `recall(User $actor, …)` couple BatchExpiry directly to the Identity model, bypassing the mandated shared/public service boundary.
- Minimum correction: accept scalar actor identity plus a typed authorization/scope decision, or use a Shared contract/public Identity service. Update controller, service, and exact test signatures.

### M4 — Convention-10 decisions contradict work required in this slice

- Plan: [rev3:34](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-3.md:34), especially `duplicate`, `edit`, `rerun`, and `audit` at lines 37–44
- Convention: [10:45](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/10-BENCHMARK-FIRST-SPECS.md:45), decision semantics at [10:51](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/10-BENCHMARK-FIRST-SPECS.md:51)
- Failure scenario: the matrix labels idempotency, immutable evidence, replay, and auditing `DEFER`, while the plan makes each mandatory in W-LOT-A-1. Under convention 10, `DEFER` means parking the actual gap with a ticket, not merely postponing comparator research.
- Minimum correction: use the exact convention-10 header and mark implemented guarantees `MATCH — W-LOT-A-1`. Keep only release/reject as `DEFER — W-LOT-A-1b`.

### M5 — Deployment census variable lacks required executable pass/fail criteria

- Plan: [rev3:1165](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-3.md:1165)
- Manifest contract: [manifest:273](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:273), specifically [manifest:281](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:281)
- Failure scenario: VAT and phantom-default results are only described as “compared to baseline”; no exact pass/fail grep, verdict marker, or allowed-delta rule is supplied. Operators cannot deterministically gate promotion.
- Minimum correction: provide the exact command, stdout marker/grep, expected tenant cardinality, and accepted/failing comparison rule for every named census.

## MINOR

### N1 — The claimed planning baseline is not current

- Plan: [rev3:3](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-3.md:3)
- Failure scenario: dispatch evidence says `d64675d9e` is current HEAD, while reviewed HEAD is `3d5176bc4`; rev3 itself landed at `5a33e1ae1`.
- Minimum correction: repin the baseline and state that relevant production paths were diff-checked and unchanged.

### N2 — Task 5’s “exact files” claim is therefore false

- Plan: [rev3:946](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-3.md:946)
- Failure scenario: even apart from the incomplete writer census, implementers cannot execute Task 5 solely from its stated file list.
- Minimum correction: after closing B2, regenerate the exact added/modified-file list and bind each newly listed test to a named class, method, assertion, command, and lane.

## Prior r2 closure table

| Prior item | Status | Rev3 evidence / disposition |
|---|---|---|
| B-R2-1 server/device enforcement | **CLOSED as to the original defect** | Immediate server enforcement is assigned to POS atomic consumption, direct batch transfer, and stock-transfer allocation in Task 4; device-immediate enforcement is explicitly excluded and delegated to D4 session-refresh caching. The incomplete shared eligibility interface is new B3. |
| B-R2-2 sole writer/census | **OPEN — B2** | Census and AST ratchet added at [rev3:927](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-3.md:927), but existing test writers remain omitted. |
| M-R2-1 read leaks | **CLOSED** | Task 1 scopes detail stock, totals, stock views, suggestions, and both trace directions at [rev3:272](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-3.md:272). |
| M-R2-2 viewer/operator grants | **CLOSED** | Viewer/operator view-only grants and manager recall removal are explicitly planned and tested. |
| M-R2-3 capability endpoint | **CLOSED** | Literal capability route, typed response, dormant availability, frontend cache, and authorization tests are specified at [rev3:139](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-3.md:139). |
| M-R2-4 dormant permission contradiction | **OPEN — M1** | Existing recall/deactivate behavior is still changed during Push 3 OFF. |
| M-R2-5 false red-first cases | **CLOSED** | Every claimed red-first target is contrary at HEAD. No case depends on a missing production dependency merely returning a convenient failure. |
| N-R2-1 stale baseline | **OPEN — N1** | Baseline was not repinned. |
| N-R2-2 empty Push 1 | **CLOSED** | Push 1 is explicitly collapsed into pre-Push-2 evidence at [rev3:1169](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-3.md:1169). |
| N-R2-3 cache marker | **CLOSED** | Exact installed marker and provenance are stated at [rev3:1172](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-3.md:1172). |
| N-R2-4 UUID algorithm | **CLOSED** | Exact UUIDv5 algorithm and fixture are specified at [rev3:123](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-3.md:123); the fixture value is correct. |
| N-R2-5 role-ruling prose | **CLOSED** | The plan now anchors the GM role to the ruling and does not reopen qualification policy. |

## Rejected false positives

- Q10 is correctly reflected: this slice implements only `requested → recalled`; `requested → released` and reject/release authorization are named for W-LOT-A-1b at [rev3:1149](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-3.md:1149).
- The terminal-child uniqueness is status-independent and therefore compatible with the ruled future `released` state. B1 concerns operation-UUID namespaces, not the state-machine branch.
- No online receipt resurrection or device-immediate claim remains. D4 session-open refresh, cached-session behavior, and visible cache age remain W-LOT-B work.
- All absolute repository path:line citations resolve at HEAD and are within file bounds.
- Every declared red-first assertion is statically red at HEAD: unscoped detail data, live dormant recall, absent role deltas/capability route/schema/hold predicate, current alternate writers, and absent frontend grants/UI.
- The exact manifest reference sentence and all ten variable rows are present. M5 concerns incomplete contents of one variable, not a missing manifest reference.
- The plan stays at six tasks, uses PHP enums, introduces no JSONB, and includes reviewer and rollback sections.

## Preserve

- Append-only request/transition evidence and the status-independent single-terminal constraint.
- The strict `requested → recalled` scope and named W-LOT-A-1b deferral.
- Immediate hold enforcement on all three server issue paths.
- D4 device-refresh boundary and explicit “device build: no.”
- Hold-aware issue safety after flag rollback.
- Viewer/operator view-only grants, manager recall removal, and marked GM provisioning.
- Typed capability DTO and generated frontend type flow.
- Production writer AST ratchet, extended to cover the omissions above.
- Shared-manifest reference, five-push sequencing, exact cache marker, host backups, and canonical checklist.

Owner decisions required: none.

VERDICT: CHANGES-REQUIRED