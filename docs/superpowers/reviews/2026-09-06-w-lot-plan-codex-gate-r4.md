# Codex plan gate r4 — W-LOT rev 3 (gpt-5.6-sol, high, read-only, 2026-09-06)

Input: rev 3 at local dev 3f32ffdd8. Verbatim.

---
Reviewed rev 3 read-only at local `dev` HEAD `3f32ffdd8fbdd06d815ebdcf6e3c4fe83380eea2`. No files were edited, no tests were run, and no Git state was modified.

Rev 3 is not dispatch-ready. It closes several narrow gate-r3 defects, but leaves the mechanical dispatch gate open and introduces eight additional blockers. Most seriously, it encodes an answer to OPEN Q10, defines an impossible signed movement invariant, relies on a nonexistent cross-database entitlement fence, and provides a staging manifest that cannot execute as written.

References below use:

- `P` — [rev 3 execution plan](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-batch-management-execution-plan.md:1)
- `R3` — [gate r3](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/reviews/2026-09-06-w-lot-plan-codex-gate-r3.md:1)
- `S4` — [spec v4](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:1)
- `OR` — [owner rulings](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:1)
- `C09`, `C10`, `C11` — conventions [09](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/09-SECOND-OF-EVERYTHING.md:30), [10](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/10-BENCHMARK-FIRST-SPECS.md:37), [11](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md:30)

## Gate-r3 closure table

### Carried closure rows

| ID | Status at r4 | Evidence |
|---|---:|---|
| R1-1 Mechanical dispatch gate | **OPEN** | Tasks still use directory/class placeholders (`P:1352,1381,1410,1430,1523,1637,1657,1684,1704,1770,1835,1868,1895,1915,1957`), backend commands often say only “PostgreSQL,” and reviewer gates name disciplines rather than an exact reviewer/invocation. |
| R1-2 Recall escalation and roles | **OPEN** | Exact permission and old-route removal are now specified (`P:1137,1155-1169`), but Q10 is encoded and local holds are absent from the live FEFO eligibility/lock contract. |
| R1-3 POS core versus lot arm | **PARTIAL** | N child effects and receipt-atomic authoring are specified (`P:637-665,1807-1815`), but their signed reconciliation is impossible and server delivery does not tolerate either arrival order. |
| R1-4 Lock census | **PARTIAL** | GL ownership and the obligation lease inversion are addressed (`P:1194-1216,1286-1291`); recall mutation and central-entitlement mutation are absent from the lock census. |
| R1-5 Deployment order | **OPEN** | The manifest remains non-executable; see NEW-B7. |
| R1-6 Freeze before replacement | **CLOSED** | Replacement-before-freeze sequencing remains explicit (`P:2394-2397`). |
| R1-7 Float retirement | **PARTIAL** | Surfaces are named more broadly (`P:1460`), but “transitive reservation consumers,” DTOs, generated output, and POS cart types are not exact paths; there is no executable per-surface test packet. |
| R1-8 L9 identity correction | **PARTIAL** | Used-lot update owners appear in Task 8 (`P:1494`), but Task 9 still uses service/controller/resource placeholders and supplies no dedicated correction red test. |
| R1-9 Flat-row count model | **CLOSED** | Parent count plus explicit per-lot observations remains intact (`P:458-488`). The invalid watermark is a separate blocker. |
| R1-10 Provenance and health | **PARTIAL** | Producers and live-PG census class are named (`P:1635-1696`), but producer files and durable backfill progress schema are not. |
| R1-11 Refresh before POS open | **CLOSED** | Task 17 now names `terminalStore.ts`, active cart owners, both shift branches, refusal semantics, test case, assertion, and command (`P:1731-1764`). |
| R1-12 Durable outbox | **CLOSED** | Receipt, fiscal event, evidence, and outbox are placed in one SQLite transaction with injected boundary failures (`P:1784-1829`). Server arrival-order handling remains a separate blocker. |
| R1-13 L8/L9 completion | **OPEN** | The completion claim remains false while dispatch packets, evidence delivery, count ordering, entitlement fencing, recall holds, and CI remain unresolved. |
| R1-14 Citation hygiene | **PARTIAL** | Source-code citations remain stable across a documentation-only descendant, but the HEAD claim is stale and G-OVERSELL points to the wrong spec lines. |
| R1-15 Task size | **CLOSED** | Work remains split into 24 bounded tasks. Completeness, not size, is the problem. |
| R2-B1 Latest owner register | **OPEN** | Q10–Q13 are copied verbatim (`P:97-106`), but `P:111-113,335,365,374,861-864` selects a two-state Q10 lifecycle. |
| R2-B2 Aggregate movement link | **PARTIAL** | N effects are present (`P:637-665`), but their signed sum cannot equal HEAD’s positive aggregate sale magnitude. |
| R2-B3 Canonical line key | **CLOSED** | `INTEGER` and `0..999999` are consistent (`P:610,1774-1778`). |
| R2-B4 Writer/lock census | **PARTIAL** | Inventory/GL coverage is materially improved (`P:1226-1291`), but recall and cross-database entitlement mutation are missing. |
| R2-B5 Deployment preflight | **OPEN** | Push 2 runs after automatic boot migrations; Push 4/5 have missing files/variables and verification tooling; see NEW-B7. |
| R2-B6 Dispatch packets | **OPEN** | Exact files, executable red commands, Convention-09 cases, and concrete reviewer gates remain incomplete across most tasks. |
| R2-M1 L9 seed scope | **CLOSED** | Identity correction and identification remain admin-only by default (`P:1142`). |
| R2-M2 Reservation consumers | **CLOSED** | The narrow reservation-consumer census is present (`P:1237-1258,1293-1307`). |
| R2-M3 Provenance producer seams | **CLOSED** | The narrow producer census covers converter, receipt, return, transfer, reservation, and POS paths (`P:1309-1319`). Exact file packets still need correction under R2-B6. |
| R2-M4 POS renderer census | **PARTIAL** | Active cart and shift owner are exact; ProductGrid/ProductCard/ProductListRow/ProductTable/ProductDetailDrawer/BarcodeChooser/NearExpirySlot remain owner names rather than exact files (`P:1733`). |
| R2-M5 Health schedule | **CLOSED** | 03:20, 180-minute overlap, notification, and stale recovery are preserved (`P:1684-1696`). |
| R2-M6 Vocabulary ambiguity | **PARTIAL** | Plan-local meanings are distinct (`P:120-132`), but most new nouns have no complete glossary row as required by C11. |
| R2-m1 Reproducible baseline | **OPEN** | `P:8` records `11e13…`; reviewed HEAD is `3f32ff…`. The intervening source code is unchanged, so this remains MINOR rather than a schema invalidation. |

### Gate-r3 BLOCKER findings

| Gate-r3 finding | Status at r4 | Evidence |
|---|---:|---|
| Multi-lot cardinality | **PARTIAL** | N child rows solve cardinality (`P:637-665`), but NEW-B2 makes application impossible. |
| Canonical index `SMALLINT` | **CLOSED** | Replaced by `INTEGER`; boundary test specified (`P:610,1774`). |
| Recall authority/bypass | **PARTIAL** | Exact ruled permission and route replacement are specified, but Q10, GM permission expansion, local eligibility, and recall locking remain defective. |
| Recall retry after transition | **CLOSED** | Stable operation UUID, fingerprint, uniqueness, exact/conflicting retry, and concurrent duplicate cases are present (`P:333-374,1408-1422`). |
| Receipt evidence atomicity | **CLOSED** | Same SQLite transaction and all failure boundaries are explicit (`P:1807-1827`). |
| Company entitlement boundary | **PARTIAL** | Concrete adapter/binding/consumers now exist (`P:835-857`), but the promised cross-database transactional fence is not implementable as specified. |
| Five-push manifest | **OPEN** | See NEW-B7. |
| Dispatch and CI | **OPEN** | Task 23 names the live job and classes, but other tasks still lack exact dispatch packets; Convention 10 also fails round zero. |
| GL ownership/order inversion | **PARTIAL** | Lease-first inversion and GL census are fixed (`P:1210-1216,1286-1291`); recall and entitlement locks remain unproved. |

### Gate-r3 MAJOR/MINOR findings

| Gate-r3 finding | Status at r4 | Evidence |
|---|---:|---|
| Float retirement surfaces | **PARTIAL** | Broadly named at `P:1460`; not exact or independently testable. |
| Count as-of marker | **OPEN** | A marker was added, but it assumes all movement IDs are sortable UUIDv7; HEAD writers explicitly create UUIDv4. |
| Used-lot freeze | **PARTIAL** | Ordinary update paths now appear in Task 8, but correction-specific executable coverage is absent. |
| L1 authorization census | **PARTIAL** | Route matrix is complete on paper (`P:1146-1163`), but Task 5’s files/tests remain placeholders. |
| Active cart/pre-open owners | **CLOSED** | Exact active owners and both shift branches are named and tested (`P:1731-1764`). |
| Generated enums/types | **PARTIAL** | Task 23 owns generation, but individual enum/DTO file paths are missing and Task 12 still names a local `types.ts`. |
| Stale verified HEAD | **OPEN** | Recurred at `P:8`; actual HEAD is `3f32ff…`. |

No gate-r3 finding qualifies for rejection. Narrow false-positive candidates are listed separately below.

## NEW BLOCKERS

### NEW-B1 — Rev 3 encodes OPEN Q10

- Plan: [binding consequence](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-batch-management-execution-plan.md:110), [recall schema](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-batch-management-execution-plan.md:335), [transition constraint](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-batch-management-execution-plan.md:374), [state machine](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-batch-management-execution-plan.md:861)
- Authority: [owner Q10](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:140)
- Failure: The plan chooses `requested → recalled` only, makes a request hold permanent until recall, and says release requires a later additive migration. That is an implementation branch answering Q10, despite claiming no Q10 branch exists.
- Minimum correction: Remove recall schema/workflow/tasks/activation from dispatch until Q10 is ruled, or revise the plan after the ruling. “Two states for now” is not neutral.

### NEW-B2 — Lot-effect signed reconciliation contradicts HEAD

- Plan: [signed invariants](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-batch-management-execution-plan.md:661)
- HEAD: POS sale stores positive `quantity` while subtracting it from stock at [PosCoreReceiptProjection.php:2340](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:2340) and [PosCoreReceiptProjection.php:2363](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:2363); FEFO consume stores a negative leg at [FEFOInventoryService.php:309](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:309).
- Failure: For a sale of `Q`, effect sum is `-Q` while aggregate `stock_movements.quantity` is `+Q`. The deferred validation trigger can never permit `applied`.
- Minimum correction: Reconcile effects against `quantity_after - quantity_before`, or introduce a canonical signed delta and migrate every writer. Add live-PG sale, refund, scrap, multi-lot, and historical-writer tests.

### NEW-B3 — The entitlement revision fence is not transactional

- Plan: [entitlement algorithm](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-batch-management-execution-plan.md:845), especially the unsupported guarantee at `P:855`.
- HEAD architecture: central and tenant records are in separate databases, [CLAUDE.md:149](/Users/houssamr/Projects/syneriva/apps/erp/CLAUDE.md:149).
- Failure: A central revocation can commit after the tenant transaction’s final revision read but before the tenant lot write commits. Re-reading and cache revisioning cannot make two databases atomic.
- Minimum correction: Define a real shared serialization mechanism used by both entitlement mutation and lot writes—such as a central advisory/row lock held across the tenant write—or a durable authorization lease/cutover protocol. Pin both race directions in PostgreSQL tests.

### NEW-B4 — A branch recall request does not reliably block sale and transfer

- Plan eligibility exposes all active/non-recalled lots at [plan:1717](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-batch-management-execution-plan.md:1717); the recall writer is absent from the census beginning at [plan:1226](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-batch-management-execution-plan.md:1226).
- Owner: immediate local sale and transfer hold, [owner rulings:114](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:114).
- HEAD: FEFO checks only active/global recall/expiry at [FEFOInventoryService.php:267](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:267).
- Failure: A POS projection or transfer can select/consume the lot after the branch request, or race the hold insertion and commit.
- Minimum correction: Add outstanding branch hold to the one eligibility predicate and every sale/issue/transfer writer. Put request/escalation into the lock census under the same product advisory order and add both-direction concurrency tests.

### NEW-B5 — The lot-count movement watermark is not ordered

- Plan: [count marker](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-batch-management-execution-plan.md:1553).
- HEAD: the model merely uses `HasUuids`, [StockMovement.php:56](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Domain/StockMovement.php:56), while writers explicitly supply UUIDv4, e.g. [WeightedAverageCostService.php:263](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:263) and [PosCoreReceiptProjection.php:2356](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:2356). Existing code’s UUIDv7 assumption is visible at [MovementReplayService.php:65](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Domain/Services/MovementReplayService.php:65) and [InventoryCountingItem.php:329](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Domain/InventoryCountingItem.php:329).
- Failure: Lexicographic `ORDER BY id` can select an arbitrary historical movement; same-second movements may be included or excluded incorrectly, corrupting reconciliation.
- Minimum correction: Add a monotonic insertion sequence, capture its maximum for the exact grain under the compatible lock, and replay by `(occurred_at, sequence)`. Test UUIDv4 history and both same-second race directions.
- Additional contract defect: `P:484` cites an “existing SubmitCountRequest drift envelope,” but HEAD only validates ISO format at [SubmitCountRequest.php:41](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Presentation/Requests/SubmitCountRequest.php:41).

### NEW-B6 — Evidence delivery violates the required either-arrival-order contract

- Plan schema/service: hard event dependency at `P:555`; ingress takes an existing fiscal event at `P:969-975`; Task 20 dead-letters 4xx at `P:1860`.
- Spec: sidecar must tolerate either arrival order, [spec v4:404](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:404).
- Failure: Evidence can arrive before fiscal-event sync, receive a dependency 4xx/FK failure, and be permanently dead-lettered. The accepted receipt then retains an unresolved lot obligation.
- Minimum correction: Either make device delivery depend on fiscal-event acknowledgement or add a server staging inbox keyed by canonical event identity. Missing fiscal dependency must be retryable, and both arrival orders need integration tests.

### NEW-B7 — The staging manifest still cannot execute

The following are independently fatal:

- Push 2 invokes `rg` inside the API image (`P:2067-2070`), but [Dockerfile:38](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/Dockerfile:38) installs no ripgrep.
- Push 4 passes `/run/wlot/flags-false.json` (`P:2156`) without creating/copying/mounting it; compose declares no such volume at [docker-compose.staging.yml:281](/Users/houssamr/Projects/syneriva/apps/erp/docker-compose.staging.yml:281).
- Push 5 says “exactly as Push 4” rather than providing commands and never defines/captures `ACTIVE_REVISION_ID` or `ACTIVATE_OPERATION` before using them (`P:2252,2277,2290`).
- One final all-true revision is checked after each partial flag checkpoint (`P:2252-2283`); the first seven runtime flag snapshots cannot equal that revision.
- `docker compose restart` (`P:2268-2272`) does not recreate containers with changed environment values.
- Push 1 and Push 3 leave the shell in `apps/api` before later root-relative compose commands (`P:2021-2032,2106-2125`).
- The web redeploy response is neither saved nor polled before the new asset is fetched (`P:1981-2002`). The fingerprint probes only the first JS asset and the plan does not define an explicit deployed-build fingerprint source.
- New migrations run automatically before the explicit Push 2 capture because [entrypoint.sh:131](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/docker/entrypoint.sh:131) runs central migrations and [entrypoint.sh:149](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/docker/entrypoint.sh:149) runs rolling tenant migrations. Both failure branches log and continue rather than failing deployment.

Minimum correction: publish literal, self-contained commands per push; stage files into mounted paths; capture every generated identifier; create one immutable revision/cutover per checkpoint or switch all flags atomically; recreate/redeploy all four Laravel services; capture and poll the Dokploy deployment ID; expose a deterministic build fingerprint; use installed POSIX tools; and make boot migration ownership/failure semantics agree with the manifest.

### NEW-B8 — Convention 10 fails the mandatory round-zero shape

- Plan table starts at `P:151` with a different heading and columns, and is not directly after the summary.
- Required verbatim skeleton, reference versions/sources, decision vocabulary, lane/ticket suffixes, and embedded Convention-09 declaration are at [Convention 10:37](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/10-BENCHMARK-FIRST-SPECS.md:37).
- Round-zero failure rule is at [Convention 10:73](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/10-BENCHMARK-FIRST-SPECS.md:73).
- Failure: `MATCH`, `DEFER`, and `ALREADY` rows lack the required lane/ticket/citation qualification, and external systems have no pinned version/source line.
- Minimum correction: Copy the skeleton verbatim directly after the summary, include the `Flow:` source/version declaration and Convention-09 line, and make every decision `MATCH — lane …`, `DEFER — ticket …`, `DIVERGE — owner ruling …`, or `ALREADY — path:line`.

## NEW MAJOR findings

| Finding | Plan/source | Failure scenario | Minimum correction |
|---|---|---|---|
| Batch deactivation lacks the promised disposition guard | `P:158`; [BatchController.php:176](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:176); [BatchRepository.php:138](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Infrastructure/Persistence/BatchRepository.php:138) | A batch with positive or reserved stock can still be hidden by setting `is_active=false`, stranding stock outside FEFO. No task owns the zero-stock/reservation/disposition rule. | Assign exact controller/repository/service files, use canonical inventory locks, require zero quantity/reservation or explicit disposition, and add positive-stock, reservation, retry, company, and location tests. |
| GM role exceeds the owner ruling | `P:1138`; [owner ruling:113](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:113) | The ruled delta is `batches.recall` and `treasury.manage_all_locations`; the plan additionally grants `batches.health`. | Keep health admin-only or obtain an explicit owner amendment. |
| Convention-09 is not satisfied per task | Tasks 2, 5–8, 10, 12–20, and 22; [C09:37](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/09-SECOND-OF-EVERYTHING.md:37) | Applicable task packets omit one or more of second-company, second-location, and exact rerun assertions. Task 23’s umbrella journey cannot replace tests “in the same lane.” | Add the three data-meaning tests to every applicable task, with exact file, case, first assertion, command, and live lane. Missing any one is a MAJOR under [C09:79](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/09-SECOND-OF-EVERYTHING.md:79). |
| Convention-11/glossary contract is incomplete | `P:120-132,1325`; [glossary:41](/Users/houssamr/Projects/syneriva/apps/erp/docs/glossary.md:41); [C11:32](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md:32) | Branch lot hold, company recall, identity correction, count observation, eligibility snapshot, census run, rollout revision, and company cutover lack complete glossary rows naming table, module, canonical operator surface, synonyms, and all justified writers. | Spell out exact glossary rows in Task 1 and add the required spec `Concepts:` line. |
| Entitlement state contract diverges from spec v4 | `P:616` and enum/DTO section; [spec:280](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:280) | Plan uses `enabled|disabled|unresolved`; spec v4 requires `entitled|not_entitled|entitlement_unresolved`. Generated clients, DB checks, and health output can disagree. | Use spec-v4 names everywhere or record an explicit spec amendment and regenerate all DTOs/types. |
| Schema contracts delegate material checks to prose | `P:484,540,635,757` | Count drift, census states, obligation/outbox lease fields, timestamps, block reasons, and transition-specific nullability are described by references rather than complete CHECK predicates. Implementers can produce incompatible PostgreSQL and SQLite schemas. | State every enum, nullability implication, timestamp relation, uniqueness, FK action, lease invariant, and transition CHECK explicitly in the owning migration task. |
| Task packets remain mechanically undispatchable | `P:1352-1969`; [CLAUDE.md:18](/Users/houssamr/Projects/syneriva/apps/erp/CLAUDE.md:18) | Placeholder production files, prose-only first assertions, “Command/lane: PostgreSQL,” and reviewer discipline labels do not let an independent implementer run a red test or constrain scope. | Give every task exact existing/new paths, full service/CLI signatures, exact test file/class/case/assertion, shell command, confirmed live lane, C09 trio, and exact reviewer gate. |

## NEW MINOR findings

| Finding | Evidence | Correction |
|---|---|---|
| G-OVERSELL cites the wrong spec lines | `P:168` points to spec `404-408`; the oversell owner issue is at [spec:376](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:376). | Cite the actual oversell row. |
| HEAD label is stale again | `P:8` records `11e13…`; reviewed HEAD is `3f32ff…`. The intervening tracked source diff is documentation-only. | Record the reviewed HEAD separately from the implementation-source base. |

## Rejected false positives

- `tenants:migrate-rolling --force` is a valid command. Its signature is confirmed at [RollingTenantMigrationCommand.php:48](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Application/Commands/RollingTenantMigrationCommand.php:48), and its success output matches the plan at [RollingTenantMigrationCommand.php:162](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Application/Commands/RollingTenantMigrationCommand.php:162). The defect is the use of unavailable `rg` and boot-order ownership.
- `tenants:seed --force --class=...` is valid; the former central-only seeding defect is corrected.
- Planned rollout variables are added to the shared compose environment, which is inherited by API, worker, scheduler, and websocket at [docker-compose.staging.yml:17](/Users/houssamr/Projects/syneriva/apps/erp/docker-compose.staging.yml:17), `:184`, `:212`, `:233`, and `:248`. Environment propagation in Task 2 is therefore conceptually correct.
- Multi-lot cardinality itself is fixed by the parent-plus-N-effects schema. The remaining blocker is the sign invariant, not child cardinality.
- The `SMALLINT` maximum defect is fixed by `INTEGER`.
- Local receipt/evidence/outbox atomicity is fixed.
- The obligation recovery lock inversion and missing GL census are fixed.
- The actual pre-open owner and active cart components are now included.
- Q11–Q13 are not encoded elsewhere in W-LOT.
- The 03:20/180-minute census schedule remains suitable.
- Central/tenant timestamp reuse, BIGINT batch IDs, SQLite v68/v69 sequencing, and additive rollback remain valid.
- Source-code path:line citations remain materially stable because the reviewed HEAD movement is documentation-only; only the stated HEAD label and oversell citation are wrong.

## Preserve in the next revision

- The verbatim Q10–Q13 register and explicit OPEN labeling.
- Canonical line-key grammar, shared vectors, `INTEGER` maximum, and server recomputation.
- Parent obligation plus N immutable effect rows.
- Receipt/fiscal/evidence/outbox single SQLite transaction and injected failure boundaries.
- Tri-state entitlement intent, immutable rollout revisions, per-company cutovers, and captured-ID principle—after vocabulary and fencing corrections.
- Exact ruled permission `batches.recall.request`, removal of the legacy direct recall route, and admin-only identity permissions.
- Advisory-first inventory order, short committed obligation lease, and explicit GL lock census.
- Parent count plus explicit per-lot observations and explicit-zero semantics.
- Provenance values `operator_captured|system_fefo_estimate|unknown`; no inferred captured evidence.
- Blocking refresh before both shift-open branches and the active cart renderer census.
- Live PostgreSQL CI allowlist intent and dedicated census/multi-lot/deadlock classes.
- 03:20 durable non-repairing census, failure notification, and stale recovery.
- Additive migrations, retained evidence/history, flag-first rollback, and no automatic stock repair.
- Oversell override remains separately deferred.

## Owner decisions still required — verbatim OPEN register

The following remains verbatim from [owner rulings:138](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:138). All four are **OPEN**.

| Q | Question | Odoo | ERPNext / domain norm | AutoERP today | Benchmark-derived default (owner rules) |
|---|---|---|---|---|---|
| **Q10** Recall request lifecycle | May the general manager **reject** a branch recall request and **release** the branch hold? Who may release, with what evidence? | Lot hold (`quality_hold` / OCA lock) is a status that QC lifts; no built-in approval chain | GMP norm: quarantined → **released** or **rejected** by the quality authority only, with a recorded disposition and signature; a hold is never lifted by the requester | No hold exists; `is_recalled` is a global boolean | Hold lifecycle `requested → recalled (company-wide)` **or** `requested → released` where **only the general manager** may release, with mandatory reason + append-only evidence; the requesting branch cannot lift its own hold. Recommend this, in scope for W-LOT S1. |
| **Q11** Shared drawer, two terminals | When two terminals open on one physical drawer, how is the float attributed and the drawer reconciled? | **One open session per POS config (register)**; two registers sharing one cash journal is not supported natively and mis-states the opening balance (OCA "Correct Opening Balance" exists to patch it); guidance is one cash payment method **per register** | POS Opening Entry is per user per POS Profile; each profile is its own cash custody | Device floats per terminal session; Treasury has no drawer session | **One cash-bearing shift per drawer at a time**: a second terminal on the same drawer joins the open drawer session (no second float) or is refused; drawer-level session is the custody unit, terminal sessions attribute sales. Recommend this; the aggregation alternative is what Odoo needs a patch module for. |
| **Q12** Typed meaning of cash in/out | What do `CASH_IN`, `CASH_OUT`, v2 `DEPOSIT`, `PAYOUT` mean in money terms: safe transfer, bank deposit, petty-cash expense, other counterparty? | Cash in/out carries a **reason**; each reason maps to an account (OCA `pos_cash_move_reason`): bank-deposit moves go to a "cash awaiting bank deposit" intermediate account, small expenses to an expense account | Petty cash via Journal Entry to expense or transfer accounts; safe drop = transfer between cash accounts | Free-text reason on the device; no typed counterparty; only v3 `SAFE_DROP` is unambiguous | **Typed reason codes** on the device (`SAFE_DROP`, `BANK_DEPOSIT`, `PETTY_EXPENSE`, `FLOAT_TOP_UP`, `OTHER`), each mapped in configuration to a destination: transfer to safe/bank for the first three, expense document for petty cash, blocked for `OTHER` until classified. Recommend; v2 `DEPOSIT`/`PAYOUT` are mapped by a cutover table. |
| **Q13** Historical alignment | How do we set the opening Treasury balance of a drawer/safe that has traded for months without float/drop booking, and what happens to the disabled variance window? | Cash journal "Opening with last closing balance"; discrepancies booked as cash-difference gain/loss at the next open/close; no retroactive rebooking | Opening balances via an Opening Entry / Journal Entry dated at cutover; prior history left as is | No alignment mechanism; variance GL disabled since 2026-08-08 | **One dated alignment per repository at cutover**: count the physical cash, book a single opening-balance adjustment (document + movement + JE to cash-difference gain/loss), no retroactive rebooking of past shifts; the disabled variance window is closed by that alignment and documented per tenant. Recommend. |

Q10 blocks recall schema, state-machine, task, and rollout dispatch. Q11–Q13 remain unencoded and do not otherwise block W-LOT.

VERDICT: CHANGES-REQUIRED