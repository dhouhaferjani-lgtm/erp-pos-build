# Codex plan gate r1 — W-LOT-A rev 4 (gpt-5.6-sol, high, read-only, 2026-09-06)

Input: W-LOT-A at local dev e5242a4d7. Verbatim.

---
# W-LOT-A adversarial plan gate

Reviewed read-only at local `dev` HEAD `b3623823d19de289d45678e75a1f88e6884500e4`. No files were edited, no tests were run, and no Git state was changed.

W-LOT-A is not dispatch-ready. Six blockers remain: it encodes OPEN Q10, most task packets are still mechanically incomplete, the staging manifest operates on the wrong deployment context and loses identifiers, the A/B split drops a scale-4 persistence migration, the lock census has no task ownership for multiple writers, and Convention 10 still fails its exact round-zero format.

References:

- [P — W-LOT-A plan](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1)
- [R4 — previous gate](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/reviews/2026-09-06-w-lot-plan-codex-gate-r4.md:1)
- [S4 — specification v4](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:274)
- [OR — owner rulings](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:136)
- [C09](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/09-SECOND-OF-EVERYTHING.md:30), [C10](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/10-BENCHMARK-FIRST-SPECS.md:37), [C11](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md:30)

## Previous-gate closure table

“NOT CLOSED” means the plan’s claimed closure is rejected and the baseline requirement remains open.

### Carried closure rows

| ID | Gate result | Evidence |
|---|---|---|
| R1-1 Mechanical dispatch gate | **NOT CLOSED** | Tasks 3–10 still contain “exact file/filter,” “live PostgreSQL by path,” or reviewer-discipline placeholders ([P:1016](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1016), [P:1113](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1113), [P:1313](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1313), [P:1558](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1558)). |
| R1-2 Recall escalation and roles | **NOT CLOSED** | The plan encodes Q10 and does not provide complete authorization tests; see BLOCKER-1 and MAJOR-2. |
| R1-3 POS core versus lot obligation | **REJECTED — W-LOT-B** | B owns obligation/effect delivery; A leaves the entitlement, eligibility, mutation, exact-decimal and lock interfaces at [B:111](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-b-pos-lot-display-capture-consumption-execution-plan.md:111). |
| R1-4 Lock census | **NOT CLOSED** | The census lists writers not assigned to any implementation task; see BLOCKER-5. |
| R1-5 Deployment order | **NOT CLOSED** | The five-push manifest is not executable; see BLOCKER-3. |
| R1-6 Freeze before replacement | **CLOSED** | Identification precedes freeze activation at [P:1208](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1208). |
| R1-7 Float retirement | **NOT CLOSED** | Exact-string intent exists, but old scale-3 storage, hand-written FE types and incomplete writer packets remain; see BLOCKER-4 and MAJOR-5. |
| R1-8 L9 correction/identification | **NOT CLOSED** | Schema and signatures exist, but commands, C09 cases and reviewer invocation remain placeholders at [P:1216](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1216). |
| R1-9 Flat-row count model | **CLOSED narrowly** | Parent observations, explicit zero and immutable effects are defined at [P:459](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:459). Late-sync finality remains a separate MAJOR. |
| R1-10 Provenance and health | **NOT CLOSED** | Provenance resume linkage and retention rules are incomplete; see MAJOR-4. |
| R1-11 Refresh before POS open | **REJECTED — W-LOT-B** | Device/session refresh is expressly B scope at [B:90](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-b-pos-lot-display-capture-consumption-execution-plan.md:90). |
| R1-12 Durable outbox | **REJECTED — W-LOT-B** | Receipt/evidence outbox is B scope at [B:94](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-b-pos-lot-display-capture-consumption-execution-plan.md:94). |
| R1-13 L8/L9 completion | **NOT CLOSED for A** | L8 is properly excluded, but L9’s own dispatch packet is incomplete. |
| R1-14 Citation hygiene | **NOT CLOSED** | Oversell citation is fixed, but the declared reviewed HEAD remains stale; see MINOR-2. |
| R1-15 Task size | **CLOSED narrowly** | Ten bounded tasks exist. Their claimed independent dispatchability is false under R1-1. |
| R2-B1 Latest owner register | **NOT CLOSED** | Q10–Q13 are copied, but Q10 is then implemented as two states; see BLOCKER-1. |
| R2-B2 Aggregate movement link | **REJECTED — W-LOT-B** | A correctly uses signed `quantity_after - quantity_before` for its own reconciliation at [P:1214](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1214); B owns POS obligations. |
| R2-B3 Canonical line key | **REJECTED — W-LOT-B** | Evidence identity is B scope. |
| R2-B4 Writer/lock census | **NOT CLOSED** | See BLOCKER-5. |
| R2-B5 Deployment preflight | **NOT CLOSED** | See BLOCKER-3. |
| R2-B6 Dispatch packets | **NOT CLOSED** | See BLOCKER-2 and the task matrix below. |
| R2-M1 L9 permission scope | **CLOSED** | Correction and identification remain admin-only at [P:1104](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1104). |
| R2-M2 Reservation consumers | **CLOSED narrowly** | Exact reservation consumers are listed at [P:942](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:942). Broader writer ownership remains open. |
| R2-M3 Provenance producer seams | **CLOSED narrowly** | Exact producer/reader paths are assigned at [P:1387](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1387). |
| R2-M4 POS renderer census | **REJECTED — W-LOT-B** | Device/cart/display renderers are B scope. |
| R2-M5 Health schedule | **CLOSED** | 03:20, 180-minute overlap and stale recovery remain specified at [P:1496](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1496). |
| R2-M6 Vocabulary ambiguity | **NOT CLOSED** | Proposed rows do not match the glossary schema and generated/local type ownership is unresolved; see MAJOR-5. |
| R2-m1 Reproducible baseline | **NOT CLOSED** | Plan says `7e140…` at [P:81](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:81); reviewed HEAD is `b362382…`. |

### Gate-r3 findings

| Finding | Gate result | Evidence |
|---|---|---|
| Multi-lot cardinality | **REJECTED — W-LOT-B** | Parent-plus-N POS effect rows are B scope. |
| Canonical index `SMALLINT` | **REJECTED — W-LOT-B** | Evidence line identity is B scope. |
| Recall authority/bypass | **NOT CLOSED** | Q10 and authorization-test defects remain. |
| Recall retry after transition | **NOT CLOSED** | Retry constraints are described, but the entire two-state transition schema is prohibited while Q10 remains OPEN. |
| Receipt evidence atomicity | **REJECTED — W-LOT-B** | Device receipt/evidence transaction belongs to B. |
| Company entitlement boundary | **NOT CLOSED** | The central-lock design is now plausible, but only the revocation direction has an exact red test at [P:911](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:911), while the plan claims both directions. Task 2 also names two nonexistent production paths. |
| Five-push manifest | **NOT CLOSED** | See BLOCKER-3. |
| Dispatch and CI | **NOT CLOSED** | The CI list exists, but its constituent task proofs are incomplete. |
| GL ownership/order inversion | **NOT CLOSED** | The declared order is good, but unassigned writers prevent enforcement; see BLOCKER-5. |
| Float retirement surfaces | **NOT CLOSED** | See BLOCKER-4 and MAJOR-5. |
| Count as-of marker | **CLOSED narrowly** | Numeric `movement_sequence` replaces UUID ordering at [P:441](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:441). |
| Used-lot freeze | **NOT CLOSED** | Required implementation proof remains mechanically incomplete. |
| L1 authorization census | **NOT CLOSED** | Paper route matrix lacks route/role regression cases; see MAJOR-2. |
| Active cart/pre-open owners | **REJECTED — W-LOT-B** | B owns device/cart behavior. |
| Generated enums/types | **NOT CLOSED** | Generation occurs after earlier pushes consume changed contracts; local shadow types remain. |
| Stale verified HEAD | **NOT CLOSED** | See MINOR-2. |

### Findings introduced by r4

| Finding | Gate result | Evidence |
|---|---|---|
| NEW-B1 Q10 encoded | **NOT CLOSED** | See BLOCKER-1. |
| NEW-B2 Signed reconciliation | **REJECTED for A POS obligations** | A uses the correct signed delta at [P:1214](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1214); obligation effects remain B. |
| NEW-B3 Entitlement fence non-transactional | **NOT CLOSED** | The design now holds a central advisory lock across tenant commit at [P:896](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:896), but the second race-direction proof is absent and Task 2 contains invalid paths. |
| NEW-B4 Branch hold bypass | **NOT CLOSED** | Shared eligibility is stated, but live writer remediation is not assigned; see BLOCKER-5. |
| NEW-B5 Unordered watermark | **CLOSED** | Numeric sequence and same-second replay are defined at [P:443](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:443). |
| NEW-B6 Evidence delivery order | **REJECTED — W-LOT-B** | B owns ingress and arrival-order reconciliation. |
| NEW-B7 Staging manifest | **NOT CLOSED** | See BLOCKER-3. |
| NEW-B8 Convention 10 | **NOT CLOSED** | See BLOCKER-6. |
| Major: deactivation disposition | **NOT CLOSED** | Stock blocking is described, but durable idempotency/audit cannot be implemented from the schema; see MAJOR-1. |
| Major: GM role exceeded ruling | **CLOSED** | Health remains admin-only and GM’s added permissions match Q4 at [P:1102](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1102). |
| Major: Convention 09 per task | **NOT CLOSED** | See BLOCKER-2. |
| Major: Convention 11 glossary | **NOT CLOSED** | See MAJOR-5. |
| Major: entitlement vocabulary | **CLOSED** | Exact tri-state values appear at [P:849](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:849). |
| Major: delegated schema checks | **NOT CLOSED** | Q10, deactivation audit, provenance resume linkage and retention constraints remain incomplete. |
| Major: undispatchable tasks | **NOT CLOSED** | See BLOCKER-2. |
| Minor: wrong oversell citation | **CLOSED** | Correct spec line is cited at [P:118](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:118). |
| Minor: stale HEAD label | **NOT CLOSED** | See MINOR-2. |

## NEW findings

### BLOCKER-1 — OPEN Q10 is still encoded

- Plan: [P:25](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:25), [P:52](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:52), [P:156](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:156), [P:265](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:265), [P:650](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:650), [P:1026](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1026).
- Authority: [OR:140](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:140); prior gate explicitly rejected “two states for now” at [R4:83](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/reviews/2026-09-06-w-lot-plan-codex-gate-r4.md:83).
- Failure: `status IN ('requested','recalled')`, the exact `requested→recalled` transition, service signatures, Task 4 and the rollout flag make a branch hold permanent unless it escalates globally. That selects a Q10 branch even though the plan calls it neutral.
- Minimum correction: remove recall lifecycle schema, transition state machine, Task 4 recall implementation and `wlot_a_recall_holds` activation from dispatch until Q10 is ruled. Permission preparation that does not create a lifecycle may remain separate.

### BLOCKER-2 — Tasks 3–10 are not independently dispatchable

The repository requires red-first proof and exact commands ([CLAUDE.md:18](/Users/houssamr/Projects/syneriva/apps/erp/CLAUDE.md:18)); C09 requires same-lane second-company, second-location and rerun tests ([C09:37](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/09-SECOND-OF-EVERYTHING.md:37)).

| Task | Result | Missing or invalid contract |
|---|---|---|
| T1 | **FAIL** | Claims four services validate rollout values, but assigns only `docker/entrypoint.sh`; worker, scheduler and websocket have separate entrypoints. Static fingerprint lacks artifact identity. |
| T2 | **FAIL** | Names nonexistent `Modules/BatchExpiry/Providers/BatchExpiryServiceProvider.php` and `Modules/Tenant/Presentation/Controllers/VerticalConfigController.php`; actual files are [BatchExpiryServiceProvider.php:12](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/BatchExpiryServiceProvider.php:12) and [VerticalConfigController.php:27](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Http/Controllers/Api/Admin/VerticalConfigController.php:27). Only one entitlement race direction has an exact case. |
| T3 | **FAIL** | C09 command is “live PostgreSQL command by path” and reviewers are discipline labels, not exact files ([P:1016](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1016)). Writer files required by its own census are absent. |
| T4 | **FAIL** | Encodes Q10. Its only red case is recall concurrency; no route-role matrix or deactivation case. Commands and reviewer files are placeholders ([P:1113](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1113)). |
| T5 | **FAIL** | Commands say “correction on SQLite; identification on live PostgreSQL, exact file/filter”; C09 supplies prose rather than exact cases/assertions/commands ([P:1216](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1216)). |
| T6 | **FAIL** | Red cases lack test paths and literal commands; C09 lacks exact case names/assertions/command. Late-sync ownership is omitted. |
| T7 | **FAIL** | Primary and C09 commands remain “exact file/filter”/“live PostgreSQL”; late-sync/provisional-finality scenarios are absent. |
| T8 | **FAIL** | Red and C09 commands are placeholders; schema cannot implement `--resume-run` linkage. |
| T9 | **FAIL** | Red command is “live PostgreSQL exact file/filter” and reviewer paths are implicit. |
| T10 | **FAIL** | Red test is written as a class method rather than an exact test file packet, command is a placeholder, and “all five reviewers” is not an exact reviewer invocation ([P:1558](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1558)). |

- Failure: independent implementers cannot execute the promised first-red state, know the complete permitted file set, or obtain a reproducible reviewer decision.
- Minimum correction: for every task, supply exact existing/new paths, complete public and CLI signatures, exact test file/class/case/literal first assertion, literal shell command and lane, three C09 cases in that task, exact `.claude/agents/*-reviewer.md` paths and the concrete invocation.

### BLOCKER-3 — The staging manifest is not executable against staging

Independent fatal defects:

1. **Wrong execution host.** The common preamble fixes `ERP_ROOT` to a local macOS path at [P:1574](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1574), then Pushes 2–5 run `docker compose ... exec/up` locally ([P:1755](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1755), [P:1861](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1861), [P:1976](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1976)). Repository workflow says API staging is auto-deployed remotely and post-deploy verification runs on the VPS/laptop boundary ([WORKFLOW.md:206](/Users/houssamr/Projects/syneriva/apps/erp/docs/factory/WORKFLOW.md:206)). No SSH, Dokploy compose-exec or other remote execution path is defined.

2. **Shell state is lost.** The plan says every block runs in a fresh shell, but `P3_SHA`, `P4_SHA`, `P5_SHA`, revision IDs, fingerprints, cutover IDs and census IDs are assigned in one block and referenced in later blocks ([P:1574](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1574), [P:1812](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1812), [P:1823](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1823), [P:1971](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1971), [P:2039](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:2039)). `set -u` makes those blocks terminate immediately.

3. **Environment validation cannot cover four services.** T1 owns only [entrypoint.sh](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/docker/entrypoint.sh:1), while Docker targets use three different entrypoints at [Dockerfile:107](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/Dockerfile:107). The current [worker](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/docker/entrypoint-worker.sh:13), [scheduler](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/docker/entrypoint-scheduler.sh:13) and [websocket](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/docker/entrypoint-websocket.sh:13) entrypoints neither print nor validate rollout values.

4. **Deployment-ID correlation is unsafe.** The manifest chooses the newest deployment whose ID differs from only the previously newest ID ([P:1623](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1623)). A concurrent deployment can be selected. Current official Dokploy documentation shows `application.redeploy` accepts a `title`, but returns `{}` rather than a deployment ID; correlation must therefore filter a unique invocation marker or otherwise prove the selected deployment belongs to this request. See [Dokploy Application API](https://docs.dokploy.com/docs/api/application).

5. **Fingerprint does not identify a build.** `/build-fingerprint.json` contains only constant revision/name/features at [P:1670](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1670). The wrong commit can satisfy it. Hashing only the first JS asset does not bind API and web to `P*_SHA`.

6. **Tenant/permission evidence is not captured completely.** Migration IDs are merely printed/grepped, and permission seeding only scans output for error words; neither produces a durable exact tenant-ID/result manifest.

7. **Provenance dry-run/execute reuses one operation UUID** at [P:1987](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1987), but schema defines that operation as unique. Dry-run persistence semantics are unspecified.

`tenants:migrate-rolling --force` itself is valid at [RollingTenantMigrationCommand.php:48](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Application/Commands/RollingTenantMigrationCommand.php:48), and its output matches the parser at [RollingTenantMigrationCommand.php:73](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Application/Commands/RollingTenantMigrationCommand.php:73).

Minimum correction: provide self-contained remote staging commands; persist and reload all IDs from evidence files; update/validate all four entrypoints or one shared sourced validator; correlate Dokploy by a unique title/request marker; fingerprint API/web artifact SHA and asset manifest; capture exact per-tenant migration and permission results; define separate dry-run/execute operation semantics.

### BLOCKER-4 — The A/B split drops an existing scale-3 quantity

- A promises every lot quantity is a scale-4 string at [P:1000](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1000) and adds provenance to existing POS allocations at [P:548](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:548).
- HEAD stores `pos_receipt_line_batch_allocations.quantity` as `DECIMAL(10,3)` at [2026_02_19 migration:18](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_02_19_000001_create_pos_receipt_line_batch_allocations_table.php:18), despite casting it as four decimals at [ReceiptLineBatchAllocation.php:49](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Domain/ReceiptLineBatchAllocation.php:49).
- B requires exact decimals from A at [B:111](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-b-pos-lot-display-capture-consumption-execution-plan.md:111), but only defines new evidence quantities as `DECIMAL(15,4)` at [B:358](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-b-pos-lot-display-capture-consumption-execution-plan.md:358); it never widens the existing allocation table.
- Failure: `0.0001` allocations are rounded or rejected in the legacy estimated-allocation path, corrupting provenance, trace, return and B fallback consumption.
- Minimum correction: A must widen the existing column to at least `DECIMAL(15,4)`, define preflight/backfill validation and exact boundary tests. B must name that migration as a hard prerequisite.

### BLOCKER-5 — The lock census has unowned live writers

The census requires remediation for `WeightedAverageCostService`, goods receipts, openings/reset, adjustment documents, supplier returns, write-offs/reversals, maintenance repair and return scrap at [P:685](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:685). T3’s file list at [P:930](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:930) omits, among others:

- [WeightedAverageCostService.php:35](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:35)
- [GoodsReceiptService.php:41](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php:41)
- [OpeningBalancePostingService.php:51](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Application/Services/OpeningBalancePostingService.php:51)
- [StockAdjustmentDocumentService.php:54](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Application/Services/StockAdjustmentDocumentService.php:54)
- [BatchWriteOffService.php:27](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Services/BatchWriteOffService.php:27)
- [RepairPhantomDefaultBatchesCommand.php:90](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Console/Commands/RepairPhantomDefaultBatchesCommand.php:90)

HEAD still contains direct lot writes and float decrements at [StockReservationService.php:218](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Application/Services/StockReservationService.php:218), [StockReservationService.php:507](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Application/Services/StockReservationService.php:507), [StockAdjustmentService.php:2135](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:2135) and [FEFOInventoryService.php:260](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:260).

- Failure: an unassigned writer bypasses entitlement, shared eligibility, exact arithmetic or the canonical advisory/row-lock order.
- Minimum correction: assign every census row to an exact task/file and give every mutating disposition an exact test. The architecture manifest must enumerate real paths and fail on any new or unclassified direct mutation.

### BLOCKER-6 — Convention 10 is still not verbatim

- Plan heading and table begin at [P:97](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:97).
- Required exact heading, columns and decision syntax are at [C10:37](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/10-BENCHMARK-FIRST-SPECS.md:37); round-zero enforcement is at [C10:73](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/10-BENCHMARK-FIRST-SPECS.md:73).
- Failure: the heading adds `— PL-BENCH`; columns use `ID`, `Guarantee`, `Dolibarr/NV`, and `AutoERP today path:line` rather than the exact skeleton; `ALREADY — cited migration` at [P:104](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:104) is not a `path:line`; Dolibarr has no pinned version/source.
- Minimum correction: copy the C10 skeleton verbatim directly after the summary, including exact column names and embedded C09 line. Qualify every decision exactly and provide a pinned Dolibarr source/version or an explicitly challengeable “from memory” declaration.

### MAJOR-1 — Deactivation retry and audit claims have no persistence contract

- Plan: [P:1071](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1071), [P:1111](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1111).
- HEAD merely flips `is_active` at [BatchRepository.php:138](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Infrastructure/Persistence/BatchRepository.php:138).
- Failure: no schema stores deactivation operation UUID/fingerprint, actor, reason or before/after state. The service cannot distinguish an exact retry from a conflicting reuse, nor satisfy the plan’s append-only audit guarantee.
- Minimum correction: add an immutable deactivation-transition schema and request contract containing operation identity/fingerprint, actor, reason and before/after state, plus positive stock, reservation, retry, conflict, company and location tests.

### MAJOR-2 — The L1 authorization matrix lacks regression proof and omits operator

- Plan matrix: [P:1084](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1084).
- HEAD routes largely lack explicit permissions at [routes.php:12](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/routes.php:12).
- Spec requires viewer and operator read grants at [S4:319](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:319). HEAD viewer and operator omit `batches.view` at [RolesAndPermissionsSeeder.php:704](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/RolesAndPermissionsSeeder.php:704) and [RolesAndPermissionsSeeder.php:769](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/RolesAndPermissionsSeeder.php:769). The plan names cashier/viewer only.
- Failure: operator loses required read access, or a route remains reachable under the wrong role because Task 4’s only red test covers concurrency.
- Minimum correction: enumerate exact role deltas including operator, and add API/web tests for every list/show/create/update/deactivate/trace/stock/expiring/expired/recall/correction/count/health route, fresh and existing tenants, forbidden roles and malformed identifiers.

### MAJOR-3 — L4 loses the existing late-sync reconciliation guard

- Spec: [S4:348](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:348), [S4:399](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:399).
- Existing detector: [LateSyncResidualDetector.php:16](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Application/Services/LateSyncResidualDetector.php:16).
- T6/T7 files and rules omit that service and provisional/reopen semantics at [P:1235](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1235) and [P:1331](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1331).
- Failure: a movement with pre-count event time but post-marker arrival is excluded by the new predicate, while the count can be presented as final rather than reopening an exception.
- Minimum correction: assign `LateSyncResidualDetector`, define provisional/final/reopened behavior, and add late receipt/sale, legacy active count, 10+10→10+5, +5 identified surplus, explicit-zero/missing, sale/transfer concurrency and rerun tests.

### MAJOR-4 — Provenance retention and resume state are internally inconsistent

- Plan globally claims audit/history FKs use RESTRICT at [P:191](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:191).
- Existing POS receipt, transfer and document parents cascade-delete at [POS allocation migration:23](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_02_19_000001_create_pos_receipt_line_batch_allocations_table.php:23), [transfer allocation migration:16](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_06_05_121000_create_stock_transfer_line_batch_allocations_table.php:16), and [document lines migration:15](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2025_11_30_080001_create_document_lines_table.php:15).
- Plan says failed runs resume as a new linked operation at [P:652](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:652), but S5 has no `resumes_run_id` or equivalent at [P:559](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:559).
- Failure: provenance evidence can disappear with its parent, and resumed operations cannot be linked as promised.
- Minimum correction: define retention against existing parent lifecycle, add explicit resume linkage and FKs/checks, and define whether dry-run persists a run.

### MAJOR-5 — Convention 11 and generated-type ownership are not executable

- Proposed glossary rows at [P:128](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:128) omit the glossary’s required Definition column and do not reconcile existing Lot evidence/Lot identification rows at [glossary:41](/Users/houssamr/Projects/syneriva/apps/erp/docs/glossary.md:41).
- C11 forbids hand-written shadows at [C11:44](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md:44).
- T3 and T6 explicitly edit hand-written [batch types](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/batches/types.ts:1), [transfer types](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/stock-transfers/types/index.ts:1) and [counting types](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/inventory-counting/types.ts:1). The transfer file itself says it is a temporary hand-written shadow.
- Push 3 compiles and commits changed DTO consumers before `typescript:transform`, which first appears in Push 4 at [P:1842](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1842).
- Failure: Push 3 either consumes stale contracts or preserves local DTO shadows; glossary rows cannot be pasted into the canonical table.
- Minimum correction: define glossary-compatible rows with definitions and reconciled existing terms; generate DTOs in the same task/push that introduces them; replace—not extend—local shadows; add no-shadow checks for batch, transfer and counting types.

### MINOR-1 — UUID version contract contradicts the manifest

The plan says application-generated identifiers are UUIDv7 at [P:191](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:191), but deployment commands repeatedly use `Str::uuid()` at [P:1900](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1900). Laravel documents that method as UUIDv4 at [Str.php:1919](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/laravel/framework/src/Illuminate/Support/Str.php:1919); `Str::uuid7()` exists immediately below.

Minimum correction: use `Str::uuid7()` consistently or narrow the v7 requirement.

### MINOR-2 — Reviewed HEAD is stale

The plan declares `7e14006182a1d7e281bbdc4660af85a80a2ee9fd` at [P:81](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:81). Current HEAD is `b3623823d19de289d45678e75a1f88e6884500e4`. The recorded SHA is an ancestor and the intervening tracked diff is documentation-only, so source citations remain materially stable.

Minimum correction: state current reviewed HEAD separately from the implementation-source base.

## W-LOT-B out-of-scope items and split boundary

| Previous item | B ownership | Interface A must leave |
|---|---|---|
| R1-3, R2-B2, multi-lot cardinality | POS lot obligations and N effects | Signed-delta authority, canonical mutation and product-first lock order |
| R2-B3, canonical `SMALLINT` | Canonical evidence line key | No competing A line-key schema |
| R1-11, active cart/pre-open owners | Eligibility snapshot, open-session acknowledgement, device renderers | Company entitlement decision/revision and shared batch eligibility |
| R1-12, receipt evidence atomicity | SQLite receipt/evidence/outbox transaction | Provenance enum and no inferred `operator_captured` |
| R2-M4 | Product/cart/drawer/near-expiry renderer census | Generated server DTOs and exact decimal strings |
| NEW-B6 | Either-arrival-order server ingress/recovery | Durable entitlement and canonical lot mutation |

The intended interface is documented at [B:111](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-b-pos-lot-display-capture-consumption-execution-plan.md:111). The split is not complete because:

- the existing POS allocation scale-4 migration falls into neither A nor B;
- B’s T3 prerequisite cannot become true while A’s Q10 lifecycle and writer coverage are blocked;
- A’s static build fingerprint does not give B a verifiable immutable prerequisite SHA.

## Rejected false positives

- `tenants:migrate-rolling --force` is valid and its expected success text is real ([RollingTenantMigrationCommand.php:48](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Application/Commands/RollingTenantMigrationCommand.php:48), [RollingTenantMigrationCommand.php:163](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Application/Commands/RollingTenantMigrationCommand.php:163)).
- `tenants:seed --force --class=...` remains a valid command shape.
- Shared Compose inheritance is conceptually valid at [docker-compose.staging.yml:17](/Users/houssamr/Projects/syneriva/apps/erp/docker-compose.staging.yml:17); the finding concerns missing validation in distinct entrypoints and the wrong execution host.
- Multi-lot cardinality is not an A schema defect; it remains B-owned.
- Canonical evidence-line `SMALLINT` is not an A concern.
- Device receipt/evidence/outbox atomicity remains B-owned and should not be reimplemented in A.
- Obligation recovery and POS-effect GL ordering remain B-owned.
- Active-cart/pre-open rendering remains B-owned.
- Q11–Q13 are not encoded by W-LOT-A.
- The 03:20/180-minute census schedule remains acceptable.
- Central/tenant timestamp reuse, BIGINT batch IDs and additive rollback remain acceptable.
- Source-code citations are stable across the documentation-only movement from `7e140…` to current HEAD; only the declared HEAD is stale.

## Preserve in the next revision

- Q10–Q13 verbatim and explicitly OPEN.
- `entitled|not_entitled|entitlement_unresolved`.
- A central advisory lock held across tenant commit, with both mutation race directions tested.
- One canonical `BatchStockMutationService` and one `BatchEligibilityService`.
- Product-first advisory ordering, ordered stock/lot rows and GL only through `InventoryGlPostingBuffer`.
- Numeric movement sequence; no UUID ordering.
- Parent count plus explicit per-lot observations and explicit-zero semantics.
- Signed effects based on `quantity_after - quantity_before`.
- Identification before used-lot freeze; no automatic DEFAULT reconstruction on rollback.
- Provenance values `operator_captured|system_fefo_estimate|unknown`; A creates no captured evidence.
- Durable, entitlement-aware, non-repairing census at 03:20 with stale recovery.
- Five additive pushes, immutable revisions, per-company cutovers, captured-ID principle and flag-first rollback—after making the commands executable.
- B ownership of device display/capture/evidence/obligation/consumption.
- Oversell remains separately deferred.

## Owner decisions still required — VERBATIM OPEN register

All four remain **OPEN**.

| Q | Question | Odoo | ERPNext / domain norm | AutoERP today | Benchmark-derived default (owner rules) |
|---|---|---|---|---|---|
| **Q10** Recall request lifecycle | May the general manager **reject** a branch recall request and **release** the branch hold? Who may release, with what evidence? | Lot hold (`quality_hold` / OCA lock) is a status that QC lifts; no built-in approval chain | GMP norm: quarantined → **released** or **rejected** by the quality authority only, with a recorded disposition and signature; a hold is never lifted by the requester | No hold exists; `is_recalled` is a global boolean | Hold lifecycle `requested → recalled (company-wide)` **or** `requested → released` where **only the general manager** may release, with mandatory reason + append-only evidence; the requesting branch cannot lift its own hold. Recommend this, in scope for W-LOT S1. |
| **Q11** Shared drawer, two terminals | When two terminals open on one physical drawer, how is the float attributed and the drawer reconciled? | **One open session per POS config (register)**; two registers sharing one cash journal is not supported natively and mis-states the opening balance (OCA "Correct Opening Balance" exists to patch it); guidance is one cash payment method **per register** | POS Opening Entry is per user per POS Profile; each profile is its own cash custody | Device floats per terminal session; Treasury has no drawer session | **One cash-bearing shift per drawer at a time**: a second terminal on the same drawer joins the open drawer session (no second float) or is refused; drawer-level session is the custody unit, terminal sessions attribute sales. Recommend this; the aggregation alternative is what Odoo needs a patch module for. |
| **Q12** Typed meaning of cash in/out | What do `CASH_IN`, `CASH_OUT`, v2 `DEPOSIT`, `PAYOUT` mean in money terms: safe transfer, bank deposit, petty-cash expense, other counterparty? | Cash in/out carries a **reason**; each reason maps to an account (OCA `pos_cash_move_reason`): bank-deposit moves go to a "cash awaiting bank deposit" intermediate account, small expenses to an expense account | Petty cash via Journal Entry to expense or transfer accounts; safe drop = transfer between cash accounts | Free-text reason on the device; no typed counterparty; only v3 `SAFE_DROP` is unambiguous | **Typed reason codes** on the device (`SAFE_DROP`, `BANK_DEPOSIT`, `PETTY_EXPENSE`, `FLOAT_TOP_UP`, `OTHER`), each mapped in configuration to a destination: transfer to safe/bank for the first three, expense document for petty cash, blocked for `OTHER` until classified. Recommend; v2 `DEPOSIT`/`PAYOUT` are mapped by a cutover table. |
| **Q13** Historical alignment | How do we set the opening Treasury balance of a drawer/safe that has traded for months without float/drop booking, and what happens to the disabled variance window? | Cash journal "Opening with last closing balance"; discrepancies booked as cash-difference gain/loss at the next open/close; no retroactive rebooking | Opening balances via an Opening Entry / Journal Entry dated at cutover; prior history left as is | No alignment mechanism; variance GL disabled since 2026-08-08 | **One dated alignment per repository at cutover**: count the physical cash, book a single opening-balance adjustment (document + movement + JE to cash-difference gain/loss), no retroactive rebooking of past shifts; the disabled variance window is closed by that alignment and documented per tenant. Recommend. |

Q10 blocks W-LOT-A recall schema, state-machine, task and rollout dispatch. Q11–Q13 remain unencoded in A and do not independently block its non-recall server work.

VERDICT: CHANGES-REQUIRED