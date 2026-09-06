# Codex slice-plan gate r4 — W-LOT-A-1 rev 4 (gpt-5.6-sol, high, read-only, 2026-09-06)

Input: rev 4 at df7708e65. Verbatim.

---
Rev 4 is not dispatch-ready. I found three blockers, seven majors, and two minors. Review was read-only; no tests, edits, or git writes were performed.

Reviewed HEAD: `5a241c7604e8ec5d050560916a29c29935e56d60` on `dev`. The plan cites `3641298ad798df6b9244471594933aae4147eb7b`; only documentation changed in relevant history, so its production-code citations still resolve at HEAD.

## BLOCKER

### B1 — Recall, request, and issue locks do not establish the promised safety ordering

- Plan: [rev4:398](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-4.md:398), [rev4:575](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-4.md:575), [rev4:742](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-4.md:742)
- Source: [FEFOInventoryService.php:260](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:260), [BatchStockService.php:510](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php:510), [StockTransferService.php:914](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:914)
- Failure scenario:
  - Request creation reads the batch, then locks only its branch stock tuple. Company recall locks the batch and roots, but no stock tuples.
  - A recall can therefore commit after a consumer’s eligibility read but before that consumer writes its movement, producing a post-recall issue.
  - Conversely, recall can scan roots and commit while request creation holds only stock; the request can then insert an unresolved root after global recall.
  - Both contradict the promised “transition or reject” request race and post-commit issue exclusion.
- Minimum correction: define one explicit lock protocol. Request must lock and re-read the batch before its stock tuple. Recall must lock the batch and all company batch-stock rows in stable order before changing global recall state or scanning roots. Add barrier-driven PostgreSQL tests for request-versus-recall and recall-versus each issue path, asserting bounded completion, no late root, and no movement committed after recall.

### B2 — Direct company-wide recall has no append-only evidence representation

- Plan: [rev4:308](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-4.md:308), [rev4:730](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-4.md:730), especially [rev4:752](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-4.md:752)
- Source: [Batch.php:134](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php:134)
- Failure scenario: roots can only be `requested` and transitions require a parent. Yet direct GM recall remains valid and “does not invent” a root. It therefore stores only actorless mutable fields on `product_batches`; the public `recall()` signature also has no operation UUID. This contradicts baseline rows B3/B9 claiming immutable actor, reason, time, and operation evidence for every safety action.
- Minimum correction: either require every recall to disposition an existing request, or add an explicit append-only global-recall evidence kind with its own idempotency namespace, actor, reason, time, and operation UUID. Preserve the separate root/transition namespaces and single-terminal invariant.

### B3 — The mandatory per-task dispatch contract is missing across most tasks

- Scope contract: [authoring prompt:20](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/CODEX-PROMPT-slice-plan-WLOTA-1-permissions-hold-2026-09-06.md:20)
- Plan: [Task 1 tests:170](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-4.md:170), [Task 2:203](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-4.md:203), [Task 3:274](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-4.md:274), [Task 4:493](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-4.md:493), [Task 6:821](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-4.md:821)
- Failure scenario:
  - Task 1 omits test file paths and several changed method signatures.
  - Task 2 says “relevant create/update user requests”; its tests have no files, first assertions, individual commands, or lanes.
  - Task 3 uses a DTO-directory wildcard and supplies only one schema test without an exact file or assertion that can fail cleanly at HEAD.
  - Task 4 has no complete production-files section; its command column says only “PHPUnit filter”.
  - Task 5 omits verification rows for several listed modified/new tests.
  - Task 6 uses vague locale filenames and test descriptions instead of file, test name, first assertion, command, and lane.
  - No task carries its required task-local rollback.
- Minimum correction: restore, for every task, exact added/modified files, complete signatures, implementation sequence, at least one genuine assertion-red test with exact file/class::method/assertion/command/lane, convention-09 mapping, both reviewer gates, and task-local rollback. Missing classes/tables causing setup errors do not count as assertion-red.

## MAJOR

### M1 — An explicit delivery-note lot can bypass the canonical eligibility decision

- Plan: [rev4:482](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-4.md:482), [rev4:630](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-4.md:630)
- Source: [DeliveryNoteService.php:380](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Document/Domain/Services/DeliveryNoteService.php:380), [BatchStockService.php:465](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php:465)
- Failure scenario: the unallocated delivery arm reaches canonical FEFO, but a line naming `batch_id` calls `issueBatchStock()`, which checks quantity only. A locally held or globally recalled explicitly selected lot can therefore ship.
- Minimum correction: route the explicit delivery-note issue through the same post-lock decision. Explicitly classify write-off/count-correction callers as disposition paths that intentionally bypass sale eligibility.

### M2 — The held-lot refusal and capability API contracts were dropped

- Plan: [rev4:279](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-4.md:279), [rev4:412](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-4.md:412), [rev4:632](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-4.md:632)
- Source: [BatchController.php:385](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:385), [routes.php:22](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/routes.php:22)
- Failure scenario: direct transfer currently maps any `DomainException` to `TRANSFER_FAILED`; rev 4 no longer defines a named `BatchHeldException` or endpoint envelopes. The capability endpoint likewise has no DTO fields, controller signature, exact JSON, permission behavior, or assertion-red test. Implementers must invent client-visible contracts.
- Minimum correction: restore the named held exception and exact 422 mappings for suggestion/direct transfer/StockTransfer, plus the projection conflict mapping. Define `BatchRecallCapabilitiesData(bool $enabled)`, `capabilities(): JsonResponse`, exact `{data:{enabled:boolean}}`, `batches.view`, dormant 200/false behavior, and exact backend/frontend tests.

### M3 — New evidence rows are not database-correlated to their company/batch/location tuple

- Plan: [rev4:288](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-4.md:288), [rev4:353](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-4.md:353)
- Source: [product-batch schema:18](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_01_05_150000_create_product_batches_table.php:18), [location schema:25](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2025_11_30_105000_create_locations_table.php:25)
- Failure scenario: independent FKs allow a root to carry company A with company B’s batch or location. The described parent trigger validates transitions against roots but does not validate a root’s tenant/company/batch/location correlation.
- Minimum correction: make the insert trigger validate batch tenant/company, location company, actor tenant, and the actual batch-stock tuple before accepting a root. Spell out every column default/no-default, all trigger SQL behavior, exact self-guarding migration checks, and Task 2’s role-column CHECK/default/down contract.

### M4 — Task 1 hides valid company lots that currently have no stock/history

- Plan: [rev4:165](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-4.md:165)
- Source: [BatchController.php:122](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:122), [BatchController.php:105](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:105)
- Failure scenario: a batch can be created before stock exists. The plan then returns 404 from detail when neither scoped stock nor trace history exists—even for an unrestricted administrator—making the newly created batch unreachable for edit/deactivate.
- Minimum correction: distinguish unrestricted company-level metadata visibility from restricted-location stock visibility. Specify the result for zero-stock lots and nullable-location historical traces, with tests.

### M5 — Traceability hardening continues prohibited cross-module model queries

- Plan: [rev4:109](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-4.md:109), [rev4:166](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-4.md:166)
- Source: [CLAUDE.md:30](/Users/houssamr/Projects/syneriva/apps/erp/CLAUDE.md:30), [BatchTraceabilityController.php:11](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchTraceabilityController.php:11)
- Failure scenario: BatchExpiry directly imports and queries Document and POS domain models. Adding location predicates there deepens the existing module-boundary violation.
- Minimum correction: add narrow Shared trace-reader contracts implemented by Document/POS adapters, and list their exact files and tests.

### M6 — Convention 09 and convention 11 are asserted, not fully planned

- Plan: [rev4:43](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-4.md:43), [rev4:48](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-4.md:48), [rev4:821](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-4.md:821)
- Source: [convention 09:30](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/09-SECOND-OF-EVERYTHING.md:30), [convention 11:32](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md:32), [glossary:41](/Users/houssamr/Projects/syneriva/apps/erp/docs/glossary.md:41)
- Failure scenario: the plan declares only Tasks 1–5 convention-09 users even though Task 6 modifies lot UI. Several tasks lack exact second-company, second-location, and rerun evidence. The glossary currently contains none of Recall request, Branch hold, or General manager, while the plan provides definitions but not complete table/module, writer, operator-surface, and synonym rows.
- Minimum correction: map all six tasks explicitly to convention-09 tests or a justified non-applicability statement. Supply complete glossary rows and name the single `BatchRecallService` writer and existing batch-detail operator surface.

### M7 — Push 3 inertness and the census handoff are not operationally valid

- Plan: [rev4:183](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-4.md:183), [rev4:888](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-4.md:888), [rev4:928](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-4.md:928)
- Source: [manifest:32](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:32), [manifest:37](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:37), [manifest:273](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:273)
- Failure scenario:
  - If unverified `SYNC_PERMISSIONS_ON_BOOT=true`, shipping the modified seeder in Push 3 changes live grants before Push 4.
  - P1 census baselines are stored only in container `/tmp`; intervening deploys rebuild the container, so P4 `cmp` cannot find them.
  - The Commands variable lacks exact invocations, `tenants:run` status, and grep markers.
- Minimum correction: require U-6 readback/false before Push 3 and list exact files per push. Copy P1 baselines to verified host/artifact storage and restore them for P4. Expand the Commands row with the complete permission-delta, reseed, cache-reset, and generation commands plus markers and exit rules. Retain U-1/U-2/U-6 as explicit promotion preconditions.

## MINOR

### N1 — Planning SHA is stale

- Plan: [rev4:4](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-4.md:4)
- Failure scenario: dispatch evidence names `3641298ad…`, while current HEAD is `5a241c760…`.
- Minimum correction: repin to current HEAD and state that the intervening diff is documentation-only for all cited seams.

### N2 — Benchmark sources are named but not linked or labelled “from memory”

- Plan: [rev4:29](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-4.md:29)
- Source: [convention 10:42](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/10-BENCHMARK-FIRST-SPECS.md:42)
- Failure scenario: the matrix cannot be independently challenged against its comparator evidence.
- Minimum correction: restore direct OCA/ERPNext documentation links and label any unsupported comparator statement as memory/NV.

## Prior r3 closure table

| r3 item | Status | Rev 4 evidence/disposition |
|---|---|---|
| B1 idempotency namespaces | CLOSED for the cited collision | Separate partial indexes and adversarial scenario at [rev4:314](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-4.md:314). Test dispatch detail remains covered by new blocker B3. |
| B2 recall-writer census | CLOSED | All four cited omissions and the explicit fixture allowlist appear at [rev4:681](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-4.md:681) and [rev4:711](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-4.md:711). |
| B3 canonical eligibility interface | CLOSED for the four interfaces r3 explicitly required | Typed dimensions, five reasons, company-aware FEFO signature, three named server paths, shim, and W-LOT-B generator contract appear at [rev4:490](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-4.md:490). Delivery-note bypass is new M1. |
| M1 Push 3 inert | OPEN | [rev4:183](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-4.md:183) preserves post-activation read safety, but does not neutralize U-6 boot reseeding. |
| M2 convention-09 registration | CLOSED | `product_batches` catalogue/pinned registration and evidence-table classification at [rev4:457](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-4.md:457). |
| M3 Identity boundary | CLOSED | Shared actor DTO and public-service-only signatures at [rev4:76](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-4.md:76). |
| M4 convention-10 decisions | CLOSED | Implemented guarantees are MATCH/DIVERGE; only release/reject is DEFER at [rev4:31](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-4.md:31). |
| M5 executable census gate | OPEN | Exact markers exist, but P1 comparison inputs are ephemeral container files and cannot survive to P4. |
| N1 current baseline | OPEN | HEAD advanced after the cited planning SHA. |
| N2 Task 5 exact files | OPEN | The file census is improved, but several listed modified/new tests have no corresponding method/assertion/command/lane row at [rev4:779](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-4.md:779). |

## Rejected false positives

- Q10 is correctly reflected: only `requested → recalled` is implemented, and release/reject is assigned to W-LOT-A-1b at [rev4:59](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-4.md:59).
- The schema is forward-compatible with `released`: transitions are parented, and terminal uniqueness is status-independent.
- Root and transition operation UUIDs genuinely occupy separate partial-index namespaces; the adversarial collision scenario is correctly shaped.
- The recall-writer census includes the r3 omissions and retains a strict production ratchet plus an explicit test-fixture allowlist.
- `product_batches` and the evidence table are correctly classified for convention 09; no waiver or ceiling increase is needed.
- The public recall methods no longer require an Identity model.
- The plan makes no device-immediate or mid-session push guarantee; D4/W-LOT-B remains the device boundary.
- The shared-manifest reference sentence and all ten variable rows are present. M7 concerns their executable contents.
- Every existing path:line citation resolves at HEAD and remains within bounds.

## Preserve

- Strict `requested → recalled` scope and named W-LOT-A-1b release/reject deferral.
- Separate root/transition idempotency namespaces and status-independent terminal uniqueness.
- Complete production/test recall-writer census with liveness fixtures.
- Typed eligibility query/decision/reason contract and explicit company-aware FEFO signature.
- Unconditional post-activation read scoping and issue safety under both flag states.
- Viewer/operator view-only grants, manager recall removal, and marked general-manager provisioning.
- No physical quantity, reservation, valuation, movement, or GL mutation on request creation.
- Generated PHP-to-TypeScript flow, tenant-scoped capability key, shared-manifest sequencing, and dual reviewer gates.

Owner decisions required: none.

VERDICT: CHANGES-REQUIRED