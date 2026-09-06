# Codex slice-plan gate r5 — W-LOT-A-1 rev 5 (gpt-5.6-sol, high, read-only, 2026-09-06)

Input: rev 5 at c91cbd151. Verbatim.

---
# Gate result: CHANGES-REQUIRED

Reviewed read-only at HEAD `8a0b29894de705526aba46699ce424367e8f438f`. No files were edited, no tests were run, and no Git state was changed.

All 66 plan links—62 unique `path:line` targets—exist and are within bounds at HEAD. Several resolve to adjacent lines that do not prove the stated claim; those are listed under MINOR.

## BLOCKER — blocks dispatch

### B1 — Rev 5 expands Task 4 beyond the slice scope

- Plan: [rev5:2612](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-5.md:2612), [rev5:2650](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-5.md:2650), [rev5:2686](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-5.md:2686), [rev5:3040](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-5.md:3040), [rev5:5570](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-5.md:5570)
- Source contract: [authoring prompt:9](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/CODEX-PROMPT-slice-plan-WLOTA-1-permissions-hold-2026-09-06.md:9), especially [prompt:13](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/CODEX-PROMPT-slice-plan-WLOTA-1-permissions-hold-2026-09-06.md:13)
- Failure scenario: the scope requires hold enforcement for POS sale, direct batch transfer, and StockTransfer allocation. Rev 5 additionally changes delivery-note confirmation, write-off, stock-count correction, return scrap, POS suggestions, and introduces a W-LOT-B source-revision protocol. This dispatches fiscal delivery and disposition behavior that the slice did not authorize.
- Minimum correction: restrict behavioral changes and tests to POS sale/projection, direct transfer, and StockTransfer explicit/automatic allocation. Remove delivery behavior, suggestion 422 behavior, disposition policy, and W-LOT-B revision design, or assign them to named later slices. Low-level compile-only call-site adaptations may be listed explicitly but must preserve behavior.

This also means r4 M1 was a false-positive requirement generated from rev 4’s self-expanded “every issue consumer” claim. Adopting it does not satisfy scope discipline.

### B2 — The per-task packets were restored structurally, but remain non-dispatchable

- Plan: [rev5:697](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-5.md:697), [rev5:2618](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-5.md:2618), [rev5:2666](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-5.md:2666), [rev5:3420](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-5.md:3420), [rev5:3923](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-5.md:3923)
- Source contract: [authoring prompt:20](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/CODEX-PROMPT-slice-plan-WLOTA-1-permissions-hold-2026-09-06.md:20)
- Failures:
  - Task 4 says “The Product module service provider” rather than the exact [ProductServiceProvider.php](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Product/ProductServiceProvider.php:22).
  - Task 6 permits unspecified “new hooks/tests” under a directory.
  - Task 5 uses `CompanyWideBatchRecallConcurrencyTest.php` at [rev5:3805](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-5.md:3805) but omits it from its new-test file list.
  - `ActorIdentityScopeData.php`, introduced at [rev5:262](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-5.md:262), is not assigned to a task production-file list.
  - Task 4 lists controllers, StockTransfer, Delivery, POS, bypass services, and a provider as modified, but supplies signatures only for the eligibility service, FEFO, BatchStock, and lookup interface at [rev5:2801](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-5.md:2801) and [rev5:2907](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-5.md:2907). Tasks 1 and 2 similarly omit changed controller/provider/migration signatures.
- Failure scenario: different implementers must invent filenames and changed APIs; Task 5’s concurrency test can be forgotten entirely.
- Minimum correction: for each task, enumerate every added/modified file and every changed/new symbol signature. Remove all placeholders and directory wildcards, and reconcile every test used later with the task’s exact test-file inventory.

### B3 — Push 5 cannot activate the planned backend artifact set

- Plan: Task 4 files at [rev5:2624](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-5.md:2624) and [rev5:2658](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-5.md:2658); Push 3 at [rev5:4664](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-5.md:4664); Push 5 at [rev5:4736](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-5.md:4736)
- Source: Product bindings live in [ProductServiceProvider.php:24](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Product/ProductServiceProvider.php:24); existing issue callers depend on [BatchStockService.php:457](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php:457).
- Missing from both applicable push sets:
  - `BatchIssueEligibilityQueryData.php`
  - `BatchIssueEligibilityDecisionData.php`
  - `BatchIssueEligibilityDecisionSetData.php`
  - `ProductCompanyLookupAdapter.php`
  - `ProductServiceProvider.php`
  - `BatchWriteOffService.php`
  - `GroupedWriteOffService.php`
  - `StockAdjustmentService.php`
  - `ReturnScrapWriteOffService.php`
- Failure scenario: activation references absent DTO classes and an unbound product lookup; unchanged disposition callers invoke the newly required `BatchStockIssueIntent` signature and fail at runtime.
- Minimum correction: derive each push list mechanically from the reconciled task file inventories. Every added or changed runtime file must appear in exactly one pre-activation push.

The required manifest reference sentence and all ten variable rows are present at [rev5:4523](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-5.md:4523) and [rev5:4531](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-5.md:4531), matching [manifest:267](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:267) and [manifest:273](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:273). The defect is the supplied artifact content.

### B4 — The global lock protocol leaves class-0 locks unordered

- Plan: [rev5:470](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-5.md:470), particularly class 0 at [rev5:475](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-5.md:475) and stable-order rules beginning at [rev5:489](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-5.md:489)
- Source: StockTransfer currently locks multiple aggregate rows without `ORDER BY` at [StockTransferService.php:266](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:266).
- Failure scenario: two multi-product transfers with opposing input order can acquire `stock_levels` rows differently and deadlock before reaching the otherwise ordered batch locks. The listed recall-versus-issue tests do not exercise opposing multi-aggregate acquisition.
- Minimum correction: define a stable class-0 key—at minimum ascending `stock_levels.id`, with query ordering guaranteed before `FOR UPDATE`—for every multi-row path. Add a barrier-driven PostgreSQL test with opposing product order and bounded completion.

## MAJOR — fix before the named task

### M1 — Task 5 cannot produce its promised replay response

- Plan: service returns only `Batch` at [rev5:3545](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-5.md:3545), while the response requires `meta.replayed` at [rev5:3623](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-5.md:3623).
- Source: current controller owns the entire response at [BatchController.php:193](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:193).
- Failure scenario: after `BatchRecallService::recall()` returns a `Batch`, the controller cannot safely distinguish a fresh write from a matching replay. A controller pre-query would race and is not specified.
- Minimum correction: return an explicit result DTO containing the batch, operation UUID, evidence, and `replayed`, and use it in the controller response.

### M2 — The projection-conflict contract requires information absent from the exception

- Plan: `BatchHeldException` carries only tenant/company/batch/location/shortfall at [rev5:3070](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-5.md:3070); projection evidence additionally requires eligibility reasons, source revision, and capture time at [rev5:3168](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-5.md:3168).
- Source: POS lot work is contained inside [PosCoreReceiptProjection.php:2169](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:2169).
- Failure scenario: the catch block cannot emit the promised snapshot evidence. Re-querying after rollback can observe a different state and produce a false source revision.
- Minimum correction: carry the complete locked decision snapshot in the exception or return a typed refusal result consumed by the projection.

### M3 — The seeded `general_manager` identity remains renameable and deletable

- Plan: Task 2 modifies `RoleController` at [rev5:1179](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-5.md:1179), but only specifies assignment guards.
- Source: system-role rename protection covers only `super-admin`, `admin`, and `owner` at [RoleController.php:227](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:227); deletion uses the same incomplete set at [RoleController.php:276](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:276).
- Failure scenario: an unassigned provisioned `general_manager` can be deleted or renamed. The next delta then encounters missing/colliding identity state, and role-name authorization checks no longer address the canonical role.
- Minimum correction: protect marked `general_manager` roles from rename/delete using `provisioning_source`, and add exact tests for both endpoints and delta rerun afterward.

### M4 — Convention 09 evidence remains incomplete for Tasks 1 and 4

- Plan: Task 1 treats repeated reads as its rerun at [rev5:1093](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-5.md:1093); Task 4 lists repeated rejection and source-hash reads without a named mutation test at [rev5:3316](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-5.md:3316).
- Source: convention 09 requires executing the same mutation twice and an explicit `skipped`/`already_exists` result at [convention 09:37](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/09-SECOND-OF-EVERYTHING.md:37) and [convention 09:45](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/09-SECOND-OF-EVERYTHING.md:45).
- Failure scenario: the mappings cannot point a reviewer to the required `file:class::method` evidence, despite both tasks touching lot catalogue behavior.
- Minimum correction: add exact test rows for the second-company registration path, second location, and repeated mutation with explicit outcome, or reorganize task ownership so the catalogue-touching mutation and its convention-09 evidence land in the same task.

### M5 — Convention 11 lacks the mandatory Vocabulary line

- Plan: proposed glossary rows are good at [rev5:190](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-5.md:190), but there is no literal `Concepts:` line.
- Source: convention 11 mandates that exact Vocabulary-line form at [convention 11:63](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md:63); the existing glossary schema starts at [glossary:5](/Users/houssamr/Projects/syneriva/apps/erp/docs/glossary.md:5).
- Failure scenario: round-0’s grep-based vocabulary check cannot verify the introduced nouns.
- Minimum correction: add `Concepts: Recall request (NEW …), Branch hold (NEW …), Recall transition (NEW …), Global recall evidence (NEW …), General manager (NEW …)` and reconcile the proposed six-column rows with the existing glossary table structure.

### M6 — The required reseed marker has no producer

- Plan: deployment requires `WLOTA1-RESEED ...` at [rev5:4889](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-5.md:4889) and gates on its cardinality at [rev5:5780](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-5.md:5780).
- Source: the current seeder emits only `Created role: …` at [RolesAndPermissionsSeeder.php:543](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/RolesAndPermissionsSeeder.php:543) and [RolesAndPermissionsSeeder.php:548](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/RolesAndPermissionsSeeder.php:548). No task specifies a replacement marker.
- Failure scenario: a successful reseed still fails the deployment grep/cardinality gate.
- Minimum correction: define the exact seeder or wrapper-command signature that emits one tenant-qualified marker, list its file in Task 2 and the correct push, and add an assertion for APPLIED/ALREADY_APPLIED output.

## MINOR

### N1 — Planning baseline is stale again

- Plan: baseline `22baed…` at [rev5:6](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-5.md:6); reread instruction at [rev5:46](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-5.md:46).
- Source: reviewed HEAD is `8a0b29894de705526aba46699ce424367e8f438f`; since the plan baseline, `apps/api/tests/Feature/Treasury/PaymentRefundRefusalTest.php` changed in addition to documentation.
- Failure scenario: r4 N1’s “current SHA” closure is no longer true, although none of the production seams reviewed here changed.
- Minimum correction: repin to current HEAD after reconciling the findings and retain the mandatory reread-on-drift rule.

### N2 — Several valid links do not identify the supporting line

- Plan examples: [rev5:749](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-5.md:749), [rev5:751](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-5.md:751), [rev5:1203](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-5.md:1203), [rev5:3937](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-5.md:3937)
- Sources:
  - Unvalidated `location_id` is read at [BatchController.php:219](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:219), not line 215.
  - All-location stock loading occurs at [BatchController.php:271](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:271), not line 264.
  - `syncPermissions()` is at [RolesAndPermissionsSeeder.php:547](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/RolesAndPermissionsSeeder.php:547), not line 543.
  - `batches.view` is at [permissionsMap.generated.ts:18](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/hooks/permissionsMap.generated.ts:18), not line 13.
- Failure scenario: a line-verifying implementer lands on a declaration or a different permission and cannot validate the claim.
- Minimum correction: repoint citations to the operative statements. Use a range or explicit census for absence claims such as viewer omissions.

## Rev-4 closure table

| r4 item | Gate result | Rev-5 evidence/disposition |
|---|---|---|
| B1 lock protocol | **NOT CLOSED** | Batch-before-stock and recall barriers were added, but class-0 aggregate locks remain unordered: Blocker B4. |
| B2 direct recall evidence | **CLOSED** | Dedicated append-only `global_recall` kind, actor/reason/time/operation UUID, separate namespace, and legacy handling at [rev5:631](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-5.md:631). |
| B3 dispatch packets | **NOT CLOSED** | Per-task sections were restored, but exact-file, complete-signature, and test-inventory omissions remain: Blockers B2–B3. |
| M1 delivery bypass | **REJECTED as an in-slice requirement** | Delivery is absent from the exact scope at [prompt:13](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/CODEX-PROMPT-slice-plan-WLOTA-1-permissions-hold-2026-09-06.md:13). Rev 5’s attempted closure is scope expansion: Blocker B1. |
| M2 refusal/capability | **NOT CLOSED** | Capability and envelopes were restored, but the exception cannot supply the promised projection evidence: Major M2. POS-suggestion 422 is also outside the scope contract. |
| M3 evidence correlation | **CLOSED** | Direct FKs plus tenant/company/batch/location/actor/stock-tuple insert validation are specified in the Task 3 schema packet. |
| M4 zero-stock lots | **CLOSED** | Unrestricted zero-stock visibility and restricted trace/stock visibility are explicitly separated and tested at [rev5:1082](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-5.md:1082). |
| M5 module boundary | **CLOSED** | Shared trace-reader contracts and Document/POS adapters are exact at [rev5:703](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-5.md:703). |
| M6 conventions 09/11 | **NOT CLOSED** | Glossary rows were restored, but convention-09 exact mutation evidence and the literal convention-11 Vocabulary line remain missing: Majors M4–M5. |
| M7 deployment | **NOT CLOSED** | U-6 and persistent census handling are present, but exact push artifacts and reseed marker are invalid: Blocker B3 and Major M6. |
| N1 current SHA | **NOT CLOSED** | HEAD advanced to `8a0b298…`: Minor N1. |
| N2 benchmark provenance | **CLOSED** | Exact-format nine-row matrix and direct sources appear at [rev5:152](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-5.md:152). The cited [OCA module](https://github.com/OCA/stock-logistics-workflow/tree/18.0/stock_lock_lot), [ERPNext Batch](https://docs.frappe.io/erpnext/batch), [ERPNext User Permissions](https://docs.frappe.io/erpnext/user-permissions), and [21 CFR 211.22](https://www.ecfr.gov/current/title-21/chapter-I/subchapter-C/part-211/subpart-B/section-211.22) are direct sources; Dolibarr is correctly `NV`. |

## Rejected false positives

- Q10 is not open. Rev 5 copies the ruled text verbatim at [rev5:224](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-5.md:224).
- This slice correctly implements only `requested → recalled` at [rev5:230](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-5.md:230).
- Release and reject are correctly deferred—with routes, permissions, UI, replay, and race tests—to named slice W-LOT-A-1b at [rev5:236](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-5.md:236).
- The schema is forward-compatible with the ruled terminal states because the one-terminal constraint is status-independent at [rev5:254](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-5.md:254).
- Separate request-root, transition, and global-recall UUID namespaces are intentional and valid.
- The deterministic UUIDv5 fixture at [rev5:2011](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-5.md:2011) independently evaluates to the stated `463d9ce3-793e-53c0-bdc5-10e2fd6e90c9`.
- Direct global recall correctly uses `global_recall` evidence rather than fabricating a branch request.
- No device-immediate knowledge guarantee is needed in this slice.
- The shared-manifest reference and all ten variable rows are present; the finding concerns their executable contents, not their absence.

## Preserve

- Exact Q10 ruling, `requested → recalled` slice boundary, and named W-LOT-A-1b deferral.
- Status-independent single-terminal invariant and separate idempotency namespaces.
- Append-only `global_recall` evidence kind, correlation triggers, legacy-recall handling, and atomic projection/evidence intent.
- PHP enums for `status`, evidence `kind`, provisioning source, eligibility reasons, and issue intents.
- Zero-stock unrestricted visibility and restricted location/trace visibility.
- Shared Document/POS trace-reader adapters instead of BatchExpiry model imports.
- Manager loses company-wide recall; marked `general_manager` gains it only with unrestricted memberships.
- U-6 false gate, host-persisted census artifacts, dual reviewer gates, and task-local rollback sections.
- Convention-10 nine-row matrix, direct sources, and Dolibarr `NV` treatment.
- Physical quantity, reservation, valuation, movement, and GL remain unchanged when a branch request creates a hold.

Owner decisions required: none. Every required correction follows the existing scope contract and ruled Q10 boundary.

VERDICT: CHANGES-REQUIRED