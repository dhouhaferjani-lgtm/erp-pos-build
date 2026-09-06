# Codex plan gate r4 — W-CASH rev 3 (gpt-5.6-sol, high, read-only, 2026-09-06)

Input: rev 3 at local dev 3f32ffdd8. Verbatim.

---
Reviewed read-only at HEAD `3f32ffdd8fbdd06d815ebdcf6e3c4fe83380eea2`. Only planning documents changed from the plan’s inspection base; implementation code is unchanged. No files were edited, no tests were run, and no git writes were made.

Rev 3 is not dispatchable. Its lock order, manifest construction order, fingerprint, stored-event consumer, and Q10–Q13 textual handling improved, but fatal schema errors, incomplete dispatch contracts, non-atomic cash-count fan-out, reconciliation races, and a non-executable promotion manifest remain.

## Gate-r3 closure audit

| # | Gate-r3 tracked item | Status | Evidence |
|---:|---|---|---|
| 1 | B4 — v2/v3 fingerprint and cutover | **NOT CLOSED** | Fingerprint now includes shift/session ([plan:168–199](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:168)), but cutovers use unordered UUID-like string ranges and permit overlap ([plan:330–359](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:330)); v2 identity is a UUID ([CashDrawerOperation.php:20–43](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Domain/CashDrawerOperation.php:20)). |
| 2 | B5 — shared drawer/opening semantics | **CLOSED by scope removal** | Custody-session/membership and opening-policy migrations are expressly withheld ([plan:843–850](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:843)); T11 remains non-dispatchable ([plan:1660–1669](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1660)). |
| 3 | B6 — W7 reconciliation incomplete | **NOT CLOSED** | Rev 3 drops multiple mandatory spec-v4 cases and omits stable-snapshot invalidation/concurrency mechanics; compare [plan:1571–1603](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1571) with [spec:414–430](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:414). |
| 4 | B7 — migration/deployment safety | **NOT CLOSED** | Push 2 masks pipeline failures, uses an undefined host variable, backs up one tenant before fleet migration, and cannot record backup IDs; Push 3 has no executable Dokploy invocation ([plan:1781–1835](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1781)). |
| 5 | M4 — per-task convention-09 tests | **NOT CLOSED** | Several tasks still omit same-lane real-path second-company, second-location, and explicit rerun-outcome tests required by [convention 09:37–50](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/09-SECOND-OF-EVERYTHING.md:37). |
| 6 | M9 — local type census | **NOT CLOSED** | POS is mentioned, but `PaymentRepositoryData` does not exist and six listed web paths are wrong; current shadows remain in [usePaymentRepositories.ts:7](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/treasury/hooks/usePaymentRepositories.ts:7), [paymentRepositoryApi.ts:9](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/pos/api/paymentRepositoryApi.ts:9), and [payment.ts:27](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/types/payment.ts:27). |
| 7 | No opening-interval algorithm | **CLOSED by rejection** | Such an algorithm would encode Q11. Rev 3 correctly withholds it pending T11/owner ruling. |
| 8 | Durable cutover/config revisions | **NOT CLOSED** | Tables are enumerated, but cutover overlap/order is undefined and configuration/reconciliation head FKs do not enforce same-company/same-parent ownership. |
| 9 | Tasks lack dispatch contracts | **NOT CLOSED** | T0/T2/T3/T9 cite nonexistent `app/Console/Kernel.php`; multiple tasks say only “under existing tiers” or “existing consumers” ([plan:1141–1233](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1141), [plan:1319–1474](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1319)). |
| 10 | CashCountDispatcher durability | **NOT CLOSED** | The stored-event consumer is restored, but obligations are still not placed inside both Z/count-producing transactions; see new Blocker 2. |
| 11 | Manifest membership/atomicity | **CLOSED narrowly** | Close/Z are now appended before manifest construction within one SQLite transaction ([plan:1476–1486](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1476)). Member element/schema completeness remains a separate major. |
| 12 | Cross-rail fingerprint | **CLOSED** | Shift/session, canonical time, amount, currency, policy/config revisions, and separate raw evidence are explicit ([plan:168–199](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:168)). |
| 13 | POS/local type census | **NOT CLOSED** | The plan identifies POS shadows but assumes a nonexistent generated DTO and misses backend generation ownership. |
| 14 | Provisioning hooks/atomicity | **NOT CLOSED** | Rev 3 deliberately removes the shipped failure-contained contract ([plan:1287–1296](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1287)) without an authorized behavior change. |
| 15 | Promotion evidence | **NOT CLOSED** | Deployment/backup IDs and executable freshness checks remain incomplete; T12 changes have no unambiguous promotion push. |
| 16 | Tenant-only uniqueness ratchet | **CLOSED** | The actual scanner path is now used at [plan:1149](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1149) and T1 assigns it ownership. |
| 17 | R3 Blocker 1 — convention 10 | **NOT CLOSED** | Table headers are not the verbatim prescribed skeleton, several AutoERP cells lack complete `path:line`, and G10–G13 use `DEFER` without ticket IDs ([plan:131–149](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:131), [convention 10:37–66](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/10-BENCHMARK-FIRST-SPECS.md:37)). G03 also says a gap exists while deciding `ALREADY`. |
| 18 | R3 Blocker 2 — premature Q11 branch | **CLOSED** | The drawer-custody interval/session schema was removed. Disabled repository topology does not decide terminal join/refusal, float ownership, or close ownership. |
| 19 | R3 Blocker 3 — task/schema contract | **NOT CLOSED** | Fatal table/column references and generic task file lists remain; see Blocker 1 and task audit. |
| 20 | R3 Blocker 4 — staging manifest | **NOT CLOSED** | See Blocker 3. |
| 21 | R3 Major 1 — financial lock inversion | **CLOSED** | Rev 3 specifies tenant numbering → company chain → document → sorted repositories ([plan:234–292](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:234)), consistent with [GeneralLedgerService.php:3741](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3741). |
| 22 | R3 Major 2 — impossible manifest order | **CLOSED** | Corrected at [plan:1476–1486](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1476). |
| 23 | R3 Major 3 — stored-event consumer dropped | **CLOSED narrowly** | `stored_event` is now one of three obligations and is explicitly tested ([plan:1013–1022](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1013), [plan:1524–1540](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1524)). |
| 24 | R3 Major 4 — provisioning contradiction | **NOT CLOSED** | Existing contract says provisioning failures are logged/swallowed so company creation never fails ([CompanyPaymentRepositoryProvisionerInterface.php:45–49](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Shared/Contracts/Treasury/CompanyPaymentRepositoryProvisionerInterface.php:45)); rev 3 makes them fatal. |
| 25 | R3 Major 5 — fingerprint lacks custody boundary | **CLOSED** | Shift/session are now identity inputs. |
| 26 | R3 Major 6 — POS absent from type census | **NOT CLOSED** | POS is listed, but the proposed canonical generated type is absent and generation work is not assigned. |
| 27 | R3 Major 7 — rerun outcomes omitted | **NOT CLOSED** | T1’s migration rerun checks only schema hashes; T12 has no rerun outcome; other catalogue-touching tasks lack the three convention-09 journeys. |
| 28 | R3 Major 8 — nonexistent paths | **NOT CLOSED** | Earlier three paths were corrected, but new nonexistent paths remain: `apps/api/app/Console/Kernel.php`, `apps/api/app/Modules/Fiscal/Domain/FiscalEvent.php`, and six web paths under nonexistent `pages/`, `payments/components/`, or `treasury/components/` directories. |

## Task dispatch audit

All listed tasks have a reviewer heading and a nominal lane, but that does not cure missing production ownership or incomplete red-first contracts.

| Task | Result | Dispatch defect |
|---|---|---|
| T0 | **FAIL** | Two nonexistent HEAD paths; the command-registration owner is wrong. |
| T1 | **FAIL** | Fatal DDL references; schema test asserts only company-scoped uniqueness, not exact columns/FKs/checks/indexes/enums. |
| T2 | **FAIL** | Generic DTO/service paths; nonexistent Kernel; red assertion reads `status`, while the shipped field is `projection_status` ([FiscalEventProjectionRow.php:26–30](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Fiscal/Domain/Models/FiscalEventProjectionRow.php:26)). Recovery command/schedule ownership is omitted. |
| T3 | **FAIL** | No exact new-file list or exact test-file path; behavior deliberately regresses failure containment; convention-09 coverage incomplete. |
| T4 | **FAIL** | Financial/cardinality contract and PG lane are sound, but new classes have no exact paths and second-company/location/audit/edit/cancel coverage is incomplete. |
| T5 | **FAIL** | “Under existing tiers” is not an exact file contract; no explicit second-company/location journey or crash-stage test case/assertion. |
| T6 | **FAIL** | “Consumers discovered by T0” is unresolved scope; generated DTO does not exist; no backend DTO or `typescript:transform` ownership. |
| T7 | **FAIL** | Builder/repository/model/ingestor paths are generic; manifest member element fields, ordering, canonical hash algorithm, and version compatibility are unspecified. |
| T8 | **FAIL** | Omits both Z/count producer files; references nonexistent `cash_counts`; recovery command/job/consumer files remain generic. |
| T9 | **FAIL** | Nonexistent Kernel; generic files; incomplete spec-v4 matrix; no stable-snapshot or concurrent supersession test. |
| T10 | **FAIL** | Refers to nonexistent §5.3 and several nonexistent files; exact API/read-model files and generated-type production step are absent. |
| T11 | **WITHHELD — correct** | Must remain non-dispatchable until Q10–Q13 rulings. |
| T12 | **FAIL** | Conditional file ownership is not a dispatch contract; worker/scheduler/websocket entrypoints are absent; manual Tauri step lacks an exact command; no explicit rerun outcome. |

## New BLOCKER findings

### 1. The migration contract cannot execute against HEAD

- Plan: [363–391](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:363), [706–737](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:706)
- Source: [projection migration:30–63](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_05_14_100003_create_fiscal_event_projections_table.php:30), [projection model:26–41](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Fiscal/Domain/Models/FiscalEventProjectionRow.php:26), [Z-count migration:14–27](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_04_25_000001_create_pos_z_report_counts_table.php:14)
- Failure scenario: the proposed index `(company_id, status, lease_expires_at)` references two columns that do not exist. HEAD uses `projection_status`, has no `company_id`, and its enum contains only `pending/running/applied/dead_lettered`. Separately, `cash_count_delivery_obligations.cash_count_id` references nonexistent `cash_counts.id`; cash-count rows are `pos_z_report_counts` and the event identity is `zReportId` ([CashCountRecorded.php:19–37](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Domain/Events/CashCountRecorded.php:19)).
- Minimum correction: rewrite §7.1 against the actual projection table/model/enum; name every added enum and transition. Key delivery obligations to the actual durable Z/count aggregate identity, with an executable FK or documented composite identity. Add exact PostgreSQL schema assertions for every column, FK, check, index, enum value, and delete rule.

### 2. Flag-true cash-count fan-out still has a lost-obligation crash window

- Plan: [1013–1022](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1013), [1508–1531](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1508)
- Source: [ReportGenerationService.php:198–205](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Services/ReportGenerationService.php:198), [ReportGenerationService.php:412–459](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Services/ReportGenerationService.php:412), [ZReportSyncController.php:222–278](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Presentation/Controllers/ZReportSyncController.php:222), [CashCountDispatcher.php:100–106](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Services/CashCountDispatcher.php:100)
- Failure scenario: server-authored Z close dispatches only in `afterCommit`; device sync dispatches after its transaction. T8 does not modify either producer. A process death after the Z/count commit but before dispatcher invocation leaves no Treasury, Compliance, or stored-event obligation.
- Minimum correction: include both producer files in T8 and insert the three obligations inside their respective Z/count transactions. Only queue delivery after commit. Add crash-boundary tests for both server generation and device sync.

### 3. The staging manifest remains non-executable and cannot prove the promoted candidate

- Plan: [1745–1835](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1745), [1839–1915](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1839)
- Source: [entrypoint.sh:103–117](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/docker/entrypoint.sh:103), [BackupTenantCommand.php:18–74](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Application/Commands/BackupTenantCommand.php:18), [WORKFLOW.md:206–229](/Users/houssamr/Projects/syneriva/apps/erp/docs/factory/WORKFLOW.md:206)
- Failure scenario:
  - `...migrate-rolling --force | tee ...` lacks `pipefail`; migration failure can appear successful.
  - `$DIRECT_DB_HOST` is never established by the manifest. The repository variable is `DB_DIRECT_HOST`, converted locally by the entrypoint.
  - One tenant is backed up before a fleet migration, and `tenant:backup` prints no backup record ID.
  - Dokploy deployment and freshness are prose, not executable commands that capture deployment ID/status and served asset hashes.
  - Workflow requires explicit web deployment after every `origin/dev` promotion, not only web-touching pushes.
  - T12 follows T10, but Push 3 contains only T2–T10 and Push 4 never explicitly promotes T12 code.
- Minimum correction: provide a fail-fast shell manifest with `set -euo pipefail`; use `${DB_DIRECT_HOST:-postgres}`; run and verify `tenant:backup --all`; add a JSON/ID query for every backup; include the official Dokploy API invocation, deployment ID/status polling, served asset resolution/hash/fingerprint commands, and Playwright `BASE_URL`; identify the push containing T12.

### 4. Reconciliation can expose multiple “current” runs from concurrent corrected inputs

- Plan: [739–841](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:739), [1024–1041](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1024)
- Source: [spec v4:420–422](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:420)
- Failure scenario: two workers can read the same current run, append different replacements, and race to supersede the same predecessor. The anti-join can expose two unsuperseded runs because no obligation lock, atomic head transition, or one-current-run constraint is defined. It can also publish a clean result assembled from mixed dependency versions, directly violating the stable-snapshot requirement.
- Minimum correction: lock the obligation/head row; capture a versioned immutable dependency snapshot; append run, supersession, and head update in one transaction; enforce parent/company identity with composite constraints; add PostgreSQL races for concurrent corrections and late dependency invalidation.

## New MAJOR findings

### 1. Cutover bounds are not a durable range contract

- Plan: [330–359](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:330), [1091–1102](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1091)
- Source: [CashDrawerOperation.php:20–43](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Domain/CashDrawerOperation.php:20)
- Failure: v2 source IDs are UUIDs, but the plan provides only string `lower_bound`/`upper_bound` and unspecified “rail-specific ordering.” Its unique constraint does not prevent overlapping cutovers, so one fact may match contradictory decisions.
- Minimum correction: define an ordered boundary such as `(occurred_at,id)` or a stable rail sequence, prohibit overlaps under a lock/exclusion constraint, specify deterministic lookup, and test concurrent overlapping creation.

### 2. Money precision and enum rules are violated

- Plan: [460–495](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:460), [552–596](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:552)
- Source: [CLAUDE.md:39–40](/Users/houssamr/Projects/syneriva/apps/erp/CLAUDE.md:39), [CLAUDE.md:71–76](/Users/houssamr/Projects/syneriva/apps/erp/CLAUDE.md:71)
- Failure: `amount numeric(20,6)` violates the mandatory money-at-rest `decimal(N,3)` floor. Numerous `status`, `state`, `decision`, `consumer`, `source_rail`, `source_kind`, `alert_kind`, and reason-code columns have no named PHP enums.
- Minimum correction: use the repository money contract and name the PHP enum/cast for every status/type/code column in the owning task.

### 3. Convention 11 and the generated-type contract are unsatisfied

- Plan: [201–230](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:201), [296–841](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:296)
- Source: [convention 11:32–51](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md:32), [convention 11:63–66](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md:63), [glossary:76](/Users/houssamr/Projects/syneriva/apps/erp/docs/glossary.md:76)
- Failure: there is no mandatory `Vocabulary` line. New nouns such as Cash custody configuration, Projection policy record, Repository transfer document, Shift cash booking obligation, Z close manifest, Cash-count delivery obligation, reconciliation run/supersession, and Mutation outcome are absent from the glossary. “Session reconciliation” is registered with `pos_session_reconciliations`, while the plan introduces three differently named stores without updating ownership/write-path documentation. `PaymentRepositoryData` is absent from both backend DTOs and generated shared types.
- Minimum correction: add the exact Vocabulary line and glossary rows; reconcile the W7 canonical store model; create the backend DTO, assign `php artisan typescript:transform`, and remove every web/POS shadow through real file paths.

### 4. The W7 acceptance matrix regressed below spec v4

- Plan: [1571–1603](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1571)
- Source: [spec v4:418–430](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:418)
- Failure: missing explicit cases include multiple rates, rounded cash/card, opening float and fiscal drops, missing projection, late valid member, out-of-manifest receipt, refund-only negative VAT, duplicate Z, sealed hashes with wrong economic totals, and unknown/reclassified tender exceptions. Three red-first tests cannot prove the stated matrix.
- Minimum correction: restore every spec-v4 acceptance case with exact method, first failing assertion, PostgreSQL command/lane, and independent expected values.

### 5. Provisioning becomes an active regression while flags are false

- Plan: [1287–1317](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1287)
- Source: [CompanyPaymentRepositoryProvisionerInterface.php:45–49](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Shared/Contracts/Treasury/CompanyPaymentRepositoryProvisionerInterface.php:45), [LocationController.php:227–238](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Company/Presentation/Controllers/LocationController.php:227)
- Failure: an unavailable cash-purpose account or disabled-topology write now aborts otherwise valid company/location creation. False W-CASH flags do not make that behavior dormant.
- Minimum correction: preserve failure containment while configuration is disabled, or obtain an explicit owner-approved availability-contract change with recovery UX, staged compatibility proof, and failure-path regression tests.

### 6. Entrypoint validation ownership is incomplete

- Plan: [1728–1739](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1728), [T12:1673–1685](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1673)
- Source: [worker entrypoint:48–53](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/docker/entrypoint-worker.sh:48), [scheduler entrypoint:38–43](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/docker/entrypoint-scheduler.sh:38), [websocket entrypoint:37–42](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/docker/entrypoint-websocket.sh:37)
- Failure: each process independently caches configuration, but T12 names only the API entrypoint. Invalid values can therefore reach worker/scheduler/websocket without the promised identical fail-closed validation.
- Minimum correction: name all three entrypoints or an exact shared validation script invoked before `config:cache` by all four services, with container-level invalid-value tests.

## New MINOR findings

No independent minor finding. The remaining defects affect execution correctness, financial durability, or mandatory review gates.

## Rejected false positives

- The differing inspection SHA is not implementation drift; only planning documents changed.
- Q10–Q13 are reproduced verbatim at [plan:120–125](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:120) from [owner rulings:138–143](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:138).
- Rev 3 no longer encodes the Q11 join/refusal branch. Disabled drawer/safe/bank topology records do not themselves decide custody-session ownership.
- `tenants:migrate-rolling --force` is the correct command and returns nonzero when any tenant fails; the defect is the surrounding pipeline and evidence capture.
- Push 4 correctly captures configuration revision and cutover IDs before use.
- The financial lock order is corrected.
- The close/Z-before-manifest construction order is corrected.
- The stored-event consumer is no longer omitted.
- Two repository movements plus zero-or-one JE remains correct; no third repository leg is required.
- `ShiftExpectedCashService` already includes opening cash; a second expected-cash component must not be added.
- v68-before-v69 is explicit and under one task.
- `tenant:backup "$WCASH_TENANT_SLUG"` is syntactically valid; it is insufficient only because Push 2 migrates the fleet and the command does not emit record IDs.
- The campaign script options and POS Tauri package script are valid.
- Existing `default` and `fiscal-projections` queues remain sufficient.
- Historical backbooking and automatic variance activation remain correctly prohibited.

## Preserve

- Q10–Q13 verbatim and OPEN, with T11/Push 5 prohibited.
- No custody session/membership, typed-operation mapping, alignment, or recall-release schema before rulings.
- Event-time evidence and prohibition on current-state reinterpretation.
- Fingerprint shift/session identity and separation from raw evidence.
- Tenant numbering → company chain → document → sorted repository lock order.
- One transfer document/group, exactly two movements, zero-or-one JE, explicit currency, reversal-only correction.
- v68 before v69.
- Close/Z identities before manifest construction in one SQLite transaction.
- Three independently durable cash-count consumers, after moving seeding into producer transactions.
- Device-only manifest membership and separate server dependency snapshots.
- Default-false flags and literal-boolean training behavior.
- W7 prerequisite stop and `owner_ruling_required` result cells.
- Non-destructive rollback and immutable evidence retention.
- Configuration/cutover ID capture in Push 4.
- Two-company/two-location/two-terminal campaign and physical-device crash/reconnect evidence.

## Owner decisions still required

The following remain verbatim OPEN:

- **Q10:** Hold lifecycle `requested → recalled (company-wide)` or `requested → released`, with only the general manager able to release and mandatory reason plus append-only evidence.
- **Q11:** One cash-bearing shift per drawer at a time; a second terminal joins the drawer session or is refused.
- **Q12:** Typed device reason codes and approved destination/accounting mappings, including treatment of legacy `DEPOSIT`/`PAYOUT`.
- **Q13:** One dated repository alignment at cutover, no retroactive shift rebooking, and documented closure of the disabled variance window.

VERDICT: CHANGES-REQUIRED