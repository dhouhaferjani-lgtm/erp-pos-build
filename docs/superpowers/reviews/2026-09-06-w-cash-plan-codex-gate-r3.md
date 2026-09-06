# Codex plan gate r3 — W-CASH execution plan rev 2 (gpt-5.6-sol, high, read-only, 2026-09-06)

Input: rev 2 at local dev 2d5268890. Verbatim Codex output.

---
Reviewed read-only at HEAD `2d52688909a650d78a56228af285cfb21a76972c`. The implementation code is unchanged from the plan’s inspection base; only planning documents changed. No files were modified and no tests were run.

Rev 2 is not dispatchable. Four blockers remain, including a convention-10 round-zero failure, premature encoding of the unresolved Q11 branch, incomplete task/schema contracts, and a staging manifest that cannot execute as written.

## Gate-r2 closure audit

### Earlier PARTIAL/OPEN findings carried by gate r2

| Gate-r2 tracked item | Status | Rev-2 plan | HEAD evidence |
|---|---|---|---|
| B2 — C1 census incomplete | CLOSED | [plan:206](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:206), [plan:247](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:247), [plan:635](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:635) | Device/server sources, payload fields, direct movement callers, optional-table guards, and the staging census stop are now enumerated. The direct-call list agrees with the current `TreasuryMovementService::record/transfer` call sites. |
| B4 — v2/v3 fingerprint and cutover incomplete | PARTIAL | [plan:336](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:336), [plan:368](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:368) | Raw evidence is correctly separated from semantic identity and cutovers are bounded. The fingerprint omits `shift_id`, `session_id`, or a drawer-interval identity, although current cash facts are shift-scoped ([CashDrawerOperation.php:21](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Domain/CashDrawerOperation.php:21)). |
| B5 — shared drawer/opening semantics | PARTIAL | [plan:417](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:417), [plan:535](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:535) | A conditional retained-balance algorithm now exists, but its per-shift interval key and predecessor rule do not model the recommended drawer-level session or intervening drawer transfers. |
| B6 — W7 reconciliation incomplete | PARTIAL | [plan:1203](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1203) | The prerequisite stop and spec-v4 matrix are substantially complete. The run/supersession schema and CLI contract remain underspecified. |
| B7 — migration/deployment unsafe | OPEN | [plan:1473](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1473) | v68-before-v69 is fixed, but Pushes 2–4 are not executable/safe as written; see Blocker 4. |
| M1 — frozen replay alert incomplete | CLOSED | [plan:538](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:538), [plan:866](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:866) | Durable table/model/service, company-scoped uniqueness, transaction ownership, and retry test are named. |
| M3 — owner-controlled semantics incomplete | CLOSED | [plan:111](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:111) | Plan rows 117–120 are byte-for-byte equal to owner-ruling rows 140–143 and all four are explicitly OPEN. |
| M4 — task-specific convention-09 tests | PARTIAL | [plan:660](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:660) onward | Tests are now attached to tasks, but several rerun assertions prove only counts/hashes and not the required explicit `skipped`/`already_exists` outcome. Some lanes/commands/file paths remain abbreviated. |
| M6 — module/prerequisite gaps | CLOSED | [plan:915](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:915), [plan:1205](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1205) | T4 gates the reused Treasury route; T9 is stopped on W2, W4, T7, T8, W-LOT, and Q10. |
| M7 — alignment workflow generic | CLOSED | [plan:1354](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1354) | Exact conditional migration, model, DTO, service, request, controller, command, tests, and reviewer gate are named. |
| M9 — local type census incomplete | PARTIAL | [plan:176](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:176) | Web aliases are enumerated, but the POS `PaymentRepository` entity and its local row projection are omitted. |

### Gate-r2 BLOCKER/MAJOR/MINOR findings

| Gate-r2 finding | Status | Rev-2 plan | Evidence |
|---|---|---|---|
| BLOCKER — Q10 omitted; Q11/Q12 partly decided | CLOSED | [plan:111](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:111) | Q10–Q13 are verbatim, OPEN, and dependent activation is prohibited. The separate premature Q11 schema issue is a new blocker below. |
| BLOCKER — no opening interval algorithm | PARTIAL | [plan:417](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:417) | Zero/partial/mismatch/late-predecessor cases are present, but the boundary model fails shared-drawer and inter-interval-transfer cases. |
| BLOCKER — recovery uses current activation | CLOSED | [plan:383](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:383) | Recovery is restricted to immutable policy records or a bounded persisted cutover; current activation and registry state are explicitly forbidden. |
| BLOCKER — no durable cutover/config revision | PARTIAL | [plan:394](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:394), [plan:528](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:528) | The conceptual model exists, but full schema/FKs/state constraints are missing and Push 4 cannot capture the created revision/cutover IDs. |
| BLOCKER — tasks lack dispatch contracts | OPEN | [plan:635](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:635) onward | Table names and selected uniques are not an exact migration contract; several commands and tests lack exact signatures, files, or executable lanes. |
| MAJOR — v69/W7 ordered before prerequisites | CLOSED | [plan:682](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:682), [plan:1205](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1205) | T1 exclusively owns v68→v69; T9 has an explicit prerequisite stop. |
| MAJOR — W7 matrix incomplete | CLOSED | [plan:1260](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1260) | Tax/refund/tender/account/manifest/legacy/W-LOT/coverage/scope cases are named with assertions in one PostgreSQL test file. |
| MAJOR — supersession mutates history | CLOSED | [plan:619](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:619), [plan:1279](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1279) | Append-only supersession and the current-result anti-join are explicit. |
| MAJOR — CashCountDispatcher not durable | PARTIAL | [plan:1138](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1138) | Per-consumer obligations and retry outcomes are added, but replacement of Laravel dispatch fails to preserve the current stored-domain-event consumer. |
| MAJOR — manifest membership/atomicity undefined | PARTIAL | [plan:455](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:455) | Membership is now correctly device-only and one SQLite transaction is required, but the stated write order cannot include close/Z identities that do not yet exist. |
| MAJOR — cross-rail fingerprint noncanonical | PARTIAL | [plan:336](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:336) | Canonical normalization exists, but shift/session/custody identity is absent. |
| MAJOR — manual movement cannot link shift/session | CLOSED | [plan:891](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:891) | Optional append-only `shift_id`/`session_id`, permission, and company/location validation are defined with tests. |
| MAJOR — transfer route lacks module gate | CLOSED | [plan:874](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:874), [plan:911](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:911) | Exact Treasury route and module-off regression are named. |
| MAJOR — JSONB DTOs unnamed | CLOSED | [plan:572](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:572) | Every proposed JSONB field maps to a PHP DTO with strict round-trip requirements. |
| MAJOR — local type census incomplete | PARTIAL | [plan:176](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:176) | POS repository types remain outside the census. |
| MAJOR — provisioning hooks unnamed | PARTIAL | [plan:781](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:781) | Hooks are named, but the asserted transaction ownership contradicts current failure-contained provisioning behavior. |
| MAJOR — promotion evidence incomplete | PARTIAL | [plan:1423](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1423) | Campaign, census, two branches/terminals and device smoke exist, but the staging manifest omits mandatory web deployment/freshness and contains broken ID/migration commands. |
| MAJOR — frozen alert nondurable | CLOSED | [plan:866](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:866) | Durable alert table/service/unique and replay test are explicit. |
| MAJOR — tenant-only uniqueness ratchet | PARTIAL | [plan:551](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:551) | Proposed business keys include `company_id`, but the cited scanner path does not exist, making the task’s ratchet hook non-executable. |
| MINOR — T0 assumes future tables | CLOSED | [plan:656](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:656), [plan:664](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:664) | Capability-aware probes and an absent-table test are explicit. |
| MINOR — `training_flag` vague | CLOSED | [plan:322](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:322) | Exact payload path, strict boolean behavior, and missing/string failure cases are specified. |
| MINOR — coverage writer ambiguous | CLOSED | [plan:298](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:298), [plan:1244](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1244) | Coverage service is pure; reconciliation service is the sole writer. |

## New findings

### BLOCKER — blocks dispatch

1. **Convention 10 is mechanically unsatisfied.**

   - Plan: [132–146](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:132)
   - Source: [convention 10:37–66](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/10-BENCHMARK-FIRST-SPECS.md:37)
   - Failure: the plan claims compliance at line 134, but its table combines Odoo/ERPNext, omits Dolibarr entirely, lacks separate `Gap` and `Decision` columns, and uses prose dispositions rather than `MATCH`, `DEFER`, `DIVERGE`, or `ALREADY`. Round-zero therefore cannot dispatch the brief.
   - Minimum correction: replace §4 with the required Odoo/ERPNext/Dolibarr/AutoERP/Gap/Decision matrix, include applicable create/duplicate/edit/cancel/rerun/company/location/permission/audit guarantees, and use the exact decision vocabulary.

2. **Q11 remains OPEN textually but its recommended branch is already encoded in Push 2.**

   - Plan: [419–451](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:419), [535](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:535), [559](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:559)
   - Source: [owner Q11:141](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:141), [POS shift schema:25–36](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_01_08_190641_create_pos_shifts_table.php:25)
   - Failure: `treasury_drawer_custody_intervals` is keyed by `(company_id, drawer_repository_id, shift_id)`, while the recommended branch says a drawer-level session is the custody unit and multiple terminal shifts may join it. The predecessor rule also takes the prior closing unchanged: if shift A closes with 2,700, a manager drops 2,500 to the safe, and shift B opens with 200, the plan incorrectly sees retained `R=2,700` and blocks B. It has no parent drawer interval, terminal-shift membership, single count owner, or boundary adjustment for intervening immutable movements.
   - Minimum correction: withhold the interval table from T1 while Q11 is OPEN, or make it policy-neutral with a drawer custody session plus membership table. After the ruling, define join/refusal, count/close ownership, predecessor ordering, and intervening-transfer calculation with named PostgreSQL tests.

3. **The mandatory task/schema dispatch contract remains incomplete.**

   - Plan: [514–590](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:514), [608–629](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:608), [691–710](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:691)
   - Source: [CLAUDE.md:18–22](/Users/houssamr/Projects/syneriva/apps/erp/CLAUDE.md:18), [convention 09:37–50](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/09-SECOND-OF-EVERYTHING.md:37)
   - Failure: §9 gives table names, selected uniqueness keys, and JSONB DTOs, but not complete columns, types, nullability, FKs/delete behavior, checks, indexes, or transition-enforcing fields. For example, `claimed → pending` on expired lease has no named lease/claim-token fields. T5, T8 and T9 introduce commands whose full CLI signatures are absent, although Push 4 assumes options. T10 uses “web Vitest” rather than exact commands; T12 omits full API test paths and treats manual device smoke as a red-first test.
   - Minimum correction: provide an exact schema contract for every migration and complete command signatures; give every task exact production files, test file/method/assertion, executable command and lane, state transitions, rollback behavior, and reviewer stop.

4. **The five-push staging manifest cannot execute safely as written.**

   - Plan: [1473–1606](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1473)
   - Source: [factory workflow:206–229](/Users/houssamr/Projects/syneriva/apps/erp/docs/factory/WORKFLOW.md:206), [API entrypoint:131–154](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/docker/entrypoint.sh:131), [rolling command:48–53](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Application/Commands/RollingTenantMigrationCommand.php:48)
   - Failure:
     - Push 4 checks `WCASH_CUTOVER_ID` at line 1570 before creating the cutover at 1582, and never captures either the configuration revision or newly created cutover ID.
     - Push 2 runs `tenants:run migrate`, whose aggregate exit status cannot be trusted; the repository’s supported command is `tenants:migrate-rolling --force`.
     - Push 3 contains web changes, but none of the five pushes triggers the mandatory explicit Dokploy web deployment or verifies asset hash plus feature fingerprint. Push 4 can therefore campaign-test a stale bundle.
     - The entrypoint reports per-tenant migration failure but continues serving, so “after staging auto-migration” is not proof that every tenant reached the schema.
   - Minimum correction: capture command outputs into revision/cutover variables after creation; use and verify `tenants:migrate-rolling --force` output per tenant; explicitly deploy web after every promotion and require asset-hash, feature-fingerprint, and critical Playwright smoke before proceeding.

### MAJOR — fix before the affected task

1. **T4’s global financial lock order is disproved by the ledger implementation.**

   - Plan: [294](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:294), [302–314](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:302)
   - Source: [GeneralLedgerService.php:3741–3766](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3741), [GeneralLedgerService.php:5698–5714](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:5698), [RepositoryTransferService.php:64–101](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Application/Services/RepositoryTransferService.php:64)
   - Failure: the plan says document → company GL lock → repositories → JE. Every mint/seal must instead take tenant numbering before company chain. The existing transfer path is safe specifically because it mints the draft JE before `TreasuryMovementService::transfer()` takes company/repository locks. The proposed order permits company→tenant inversion and PostgreSQL `40P01`.
   - Minimum correction: define tenant-numbering → company-chain → sorted repositories as load-bearing; mint the draft before transfer and seal without acquiring a new earlier lock. Add opposite-transfer plus concurrent unrelated-JE PostgreSQL tests.

2. **T7’s manifest write order is impossible.**

   - Plan: [288](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:288), [455–481](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:455)
   - Source: [zReportService.ts:541–576](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/lib/offline/zReportService.ts:541), [zSessionAuthoring.ts:675–722](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/lib/fiscal/zSessionAuthoring.ts:675)
   - Failure: the manifest is required to include `SESSION_CLOSE` and `Z_REPORT` IDs/hashes, yet the plan orders manifest creation before those events are appended. Those identities do not exist until `appendZSessionCloseAndZReport()` returns.
   - Minimum correction: append close and Z first inside the same SQLite transaction, then construct/persist the manifest and outbox before commit, or formally preallocate and prove the exact hashes.

3. **T8 would drop the existing stored-event consumer.**

   - Plan: [1174–1195](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1174)
   - Source: [CashCountDispatcher.php:56–78](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Services/CashCountDispatcher.php:56), [CashCountRecorded.php:11–13](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Domain/Events/CashCountRecorded.php:11)
   - Failure: current `Event::dispatch()` reaches Treasury, Compliance, and Spatie’s wildcard stored-event subscriber. T8 registers only the two named business consumers behind a custom interface. Direct consumer calls would preserve GL/fraud obligations while silently removing `pos.cash_count_recorded` from stored domain-event history. Flag-false behavior is also not defined sufficiently to prove that existing Compliance/storage behavior remains live.
   - Minimum correction: retain compatible domain-event storage as an independently durable consumer or dispatch the stored event after obligation creation; define false-flag behavior and test all three effects.

4. **T3’s stated provisioning atomicity contradicts current failure containment.**

   - Plan: [826](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:826)
   - Source: [PaymentRepositoryProvisioningService.php:32–81](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Application/Services/PaymentRepositoryProvisioningService.php:32), [CompanyController.php:83–199](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php:83), [LocationController.php:195–216](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Company/Presentation/Controllers/LocationController.php:195)
   - Failure: the Treasury provisioner catches and swallows failures so the company commits. Location creation currently occurs outside a surrounding transaction. Adding disabled configuration inside the swallowed savepoint can leave a company/location with repositories or configuration missing while tests claim atomic day-one provisioning.
   - Minimum correction: explicitly change or preserve the failure-containment contract; name the outer transaction boundary for company, location, repository and configuration, plus fault-injection tests for each failed write and retry.

5. **The semantic fingerprint lacks the custody boundary required by its consumer.**

   - Plan: [340–366](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:340)
   - Source: [ShiftExpectedCashService.php:252–272](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Services/ShiftExpectedCashService.php:252), [CashDrawerOperation.php:21–49](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Domain/CashDrawerOperation.php:21)
   - Failure: a copied/reused operation identity with identical amount/time/policy but attributed to another shift can collapse into the original obligation, and reconciliation cannot prove which shift/interval owns the money.
   - Minimum correction: include normalized `shift_id` plus `session_id` or the approved drawer-interval identity; add cross-shift replay/conflict tests for both rails.

6. **The convention-11 type census excludes the POS client.**

   - Plan: [176–203](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:176)
   - Source: [payment.ts:27–38](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/types/payment.ts:27), [paymentRepository.ts:24–35](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/lib/db/repositories/paymentRepository.ts:24), [convention 11:44–47](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md:44)
   - Failure: the web migrates to generated `PaymentRepositoryData` while POS retains a separate canonical entity shape and unchecked cast, allowing divergent fields/nullability.
   - Minimum correction: extend §5.2 and T6/T10 to POS. Replace the entity with the generated DTO or explicitly classify only the SQLite row as a persistence projection with a typed adapter.

7. **Convention-09 rerun tests still omit the mandated explicit outcome.**

   - Plan examples: [702](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:702), [850](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:850), [910](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:910), [1186](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1186), [1296](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1296)
   - Source: [convention 09:45–50](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/09-SECOND-OF-EVERYTHING.md:45)
   - Failure: counts, equality and stable hashes can pass while the second call silently performs work and compensates it. The convention explicitly requires a visible `skipped`/`already_exists` result.
   - Minimum correction: add typed replay outcomes and assert both the outcome and unchanged data meaning for every mutating catalogue task.

8. **Three implementation citations are nonexistent at HEAD.**

   - Plan: [641](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:641), [1093](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1093), [1147](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1147)
   - Actual sources: [scanner:9](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Architecture/Support/TenantOnlyUniqueIndexScanner.php:9), [POS routes:131](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/routes.php:131), [Compliance listener:14](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Compliance/Listeners/OpenFraudAlertForShiftVariance.php:14)
   - Failure: T0, T7, or T8 implementers follow paths that do not exist; T0 cannot wire the stated ratchet class.
   - Minimum correction: replace:
     - `apps/api/app/Support/Tenancy/TenantOnlyUniqueIndexScanner.php`
     - `apps/api/app/Modules/POS/Presentation/routes.php`
     - `apps/api/app/Modules/Compliance/Application/Listeners/OpenFraudAlertForShiftVariance.php`
     
     with the actual paths above and re-run the citation census.

### MINOR

No independent new minor finding. The remaining defects are either dispatch-blocking or task-affecting.

## Rejected false positives

- The plan’s inspection SHA differs from current HEAD, but implementation code did not change; only planning documents changed. This is not implementation drift.
- Q10–Q13 themselves are not mistranscribed. Plan rows 117–120 exactly match owner rows 140–143 and retain OPEN status.
- Two repository movements plus zero-or-one JE remains the correct transfer cardinality; no third repository leg is required.
- `ShiftExpectedCashService` already includes opening cash, so W-CASH must not add another expected-cash component.
- An immutable sidecar can satisfy close membership without changing sealed fiscal payload versions, once its construction order is corrected.
- Existing `default` and `fiscal-projections` queues are sufficient.
- The campaign command’s `--web`, `--api`, and `--country` options are valid ([campaign-onboarding.sh:9–28](/Users/houssamr/Projects/syneriva/apps/erp/scripts/campaign-onboarding.sh:9)).
- `pnpm --filter @autoerp/pos tauri build` is valid because `tauri` is an existing package script ([apps/pos/package.json:10](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/package.json:10)).
- `tenant:backup "$WCASH_TENANT_SLUG"` matches the current command signature ([BackupTenantCommand.php:18–20](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Application/Commands/BackupTenantCommand.php:18)).
- T3 does reach the fresh-registration path through existing provisioning. The defect is transaction/failure semantics, not absence of a hook.
- Historical per-shift backbooking remains correctly prohibited.
- Keeping bank settlement confirmation separate from repository transfer creation remains correct.

## Preserve in the next revision

- Q10–Q13 verbatim, explicitly OPEN, with no financial activation before rulings.
- Event-time policy records, bounded cutovers, immutable configuration revisions, and prohibition on current-state recovery.
- Stable semantic identity separated from raw-rail evidence.
- One transfer document/group, exactly two movement legs, zero-or-one JE, reversal lineage, and explicit currency.
- The complete direct movement-writer and X/Z payload census.
- T0’s pre-migration capability-aware census and reviewer stop.
- v68-before-v69 under one migration owner.
- Default-off flags, strict training no-money behavior, and existing queues.
- Complete W7 prerequisite stop and acceptance matrix.
- Append-only reconciliation supersession with a deterministic current reader.
- Device-only manifest membership and separate server dependency snapshot.
- Frozen/checkpoint durable alerts.
- Existing Treasury repository and shift/Z operator surfaces.
- Generated DTO direction, extended to the omitted POS types.
- Two-company/two-location/two-terminal campaign, day-one census, physical-device crash/reconnect smoke, and backup/restore rehearsal.
- Non-destructive rollback, immutable evidence retention, and correction by reversal/compensation.

## Owner decisions still required

Plan rows 117–120 are verbatim and must remain OPEN. The unchanged recommended defaults are:

- **Q10 — OPEN:** Hold lifecycle `requested → recalled (company-wide)` **or** `requested → released` where **only the general manager** may release, with mandatory reason + append-only evidence; the requesting branch cannot lift its own hold. Recommend this, in scope for W-LOT S1.
- **Q11 — OPEN:** **One cash-bearing shift per drawer at a time**: a second terminal on the same drawer joins the open drawer session (no second float) or is refused; drawer-level session is the custody unit, terminal sessions attribute sales. Recommend this; the aggregation alternative is what Odoo needs a patch module for.
- **Q12 — OPEN:** **Typed reason codes** on the device (`SAFE_DROP`, `BANK_DEPOSIT`, `PETTY_EXPENSE`, `FLOAT_TOP_UP`, `OTHER`), each mapped in configuration to a destination: transfer to safe/bank for the first three, expense document for petty cash, blocked for `OTHER` until classified. Recommend; v2 `DEPOSIT`/`PAYOUT` are mapped by a cutover table.
- **Q13 — OPEN:** **One dated alignment per repository at cutover**: count the physical cash, book a single opening-balance adjustment (document + movement + JE to cash-difference gain/loss), no retroactive rebooking of past shifts; the disabled variance window is closed by that alignment and documented per tenant. Recommend.

VERDICT: CHANGES-REQUIRED