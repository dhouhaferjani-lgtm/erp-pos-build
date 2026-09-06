# Codex plan gate r6 — W-CASH rev 5 (gpt-5.6-sol, high, read-only, 2026-09-06)

Input: rev 5 at local dev 0e3e63f12. Verbatim.

---
## Gate result

Revision 5 is not dispatch-ready. Five prior findings remain open, and the audit found five new blockers plus four new majors.

Audited at HEAD `0e3e63f12a96491d1d8d82ee449d22b2dc3dbaab` on `dev`. The implementation baseline has not changed materially since the plan’s recorded SHA. No files were changed and no tests were run.

## Previous-gate closure ledger

| Prior item | Status | Evidence and disposition |
|---|---|---|
| B1 — Q12 policy branch encoded | **CLOSED** | The plan now treats source evidence as opaque, keeps manual Treasury transfers independent, and withholds financial activation: [plan 98](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:98), [plan 1516](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1516). Q12 itself remains OPEN. |
| B2 — Composite FKs cannot be created | **CLOSED** | Parent supporting unique keys are now added before the composite FKs at [plan 228](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:228). The referenced parent columns exist at HEAD. This closes the original DDL-order defect, although the new reversal state-machine defect below remains. |
| B3 — Five-push sequence circular/non-executable | **NOT CLOSED — BLOCKER** | The pushes are nominally acyclic, but deployment variables, application IDs, staging membership, and candidate SHA sequencing remain incomplete. Push 3 also omits task-owned files. See NB3. |
| B4 — Cross-rail fingerprint dedup | **NOT CLOSED — BLOCKER** | The plan still fingerprints v2 with the server operation UUID while v3 carries the client movement/idempotency UUID. See “Fingerprint blocker” below. |
| M1 — Manifest member derivation | **NOT CLOSED — MAJOR** | Receipt sequence and fiscal-chain sequence still share one ambiguous bound pair; the canonical fields and duplicate-membership rule are not enumerated. See NM1. |
| M2 — W7 canonical service/schema | **NOT CLOSED — MAJOR** | The service and reconciliation identity are named, but the writer census required to invalidate snapshots is incomplete. See NM2. |
| M3 — A→B→A historical fingerprint | **CLOSED** | The plan now appends a new occurrence and advances the head instead of enforcing permanent fingerprint uniqueness: [plan 744](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:744). |
| M4 — Exact task ownership | **NOT CLOSED — MAJOR** | Several task files are missing, one dispatcher path is wrong, some DTO/type owners are unnamed, and Push 3 does not stage all declared outputs. See the task audit. |
| M5 — Convention 09 per task | **NOT CLOSED — MAJOR** | T3 claims rerun safety but only repeats reads; it does not rerun the mutation and assert the required no-op/update outcome. See NM3. |
| Open B4 — v2/v3 fingerprint and cutover | **NOT CLOSED — BLOCKER** | Same unresolved identity mismatch as prior B4. |
| Open B6 — W7 reconciliation | **NOT CLOSED — MAJOR** | Dependency version invalidation does not cover every dependency writer. |
| Open B7 — Deployment safety | **NOT CLOSED — BLOCKER** | Separate API/worker/scheduler/web applications are not fully captured, configured, redeployed, polled, and fingerprinted. Backups are also lost across the deployment boundary. |
| Open M4 — Convention 09 | **NOT CLOSED — MAJOR** | T3 still lacks mutation-rerun proof. |
| Open M9 — Generated/local type ownership | **CLOSED** | The generated target is correctly identified as `generated.d.ts`, consistent with [TypeScript config 45](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/config/typescript-transformer.php:45). |

### Fingerprint blocker: decisive HEAD evidence

The semantic identity at [plan 689](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:689) cannot deduplicate the two rails:

- The POS creates a client idempotency/movement UUID and, on a fiscal terminal, sends v3 directly without first creating a v2 server row: [cashDrawerApi.ts 28](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/api/cashDrawerApi.ts:28), [cashDrawerApi.ts 160](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/api/cashDrawerApi.ts:160).
- The v3 payload puts that client UUID in `cash_drawer_operation_id`: [zSessionAuthoring.ts 338](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/services/fiscal/zSessionAuthoring.ts:338).
- The v2 API creates a separate server-side operation UUID and stores the client UUID only as `idempotency_key`: [CashDrawerService.php 252](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Domain/POS/Services/CashDrawerService.php:252), [CashDrawerController.php 72](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Http/Controllers/Api/V1/POS/CashDrawerController.php:72).
- Offline synchronization transmits the idempotency key, not the later server UUID: [syncService.ts 594](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/services/syncService.ts:594).

Failure: a pure-v3 movement has no v2 row to resolve; a dual-observed movement hashes differently across rails. It either blocks forever or posts twice.

Minimum correction: define `authored_link_id` as the authenticated client idempotency/movement UUID on both rails, specify current one-ID session canonicalization, and add a cross-rail vector that executes the actual v2 request plus v3 authoring path.

## New blockers

### NB1 — The transfer reversal state machine has no valid reversal-document state

- Plan: [repository transfer schema 319](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:319), especially constraints at [353](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:353).
- HEAD model: append-only reversal links belong to the reversing movement: [repository movement migration 28](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_07_08_100100_create_repository_movements_table.php:28), [TreasuryMovementService.php 133](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Domain/Treasury/Services/TreasuryMovementService.php:133).

The plan requires:

- `origin = reversal` iff `reverses_document_id` is present;
- `posted` documents to have no reversal fields;
- `reversed` documents to have a reversal target.

A new reversal document therefore cannot be both posted and linked to the original. Marking the original reversed would incorrectly require the original itself to have `origin = reversal`.

Failure: no row combination represents “posted reversal B reverses original A, and A is now reversed.”

Minimum correction: use two distinct relationships:

- reversal B: `origin=reversal`, `status=posted`, `reverses_document_id=A`;
- original A: `status=reversed`, `reversed_by_document_id=B`.

Specify both FKs, uniqueness/cardinality, delete behavior, checks, and red-first reversal/re-reversal tests.

### NB2 — T5’s asynchronous claim protocol has no persistent claim state

- Evidence schema: [plan 377](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:377).
- Processor signature and crash-recovery promise: [plan 1191](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1191), [plan 1235](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1235).

The schema has `Pending`, `Recorded`, `Blocked`, and `Conflict`, but no running state, claim token, attempt count, lease expiry, or last error. Yet `process(sourceEvidenceId, claimToken)` and crash-at-each-claim recovery are required.

Failure: concurrent jobs can process the same evidence; a crash cannot distinguish an active claim from an abandoned one; the supplied token cannot be validated against durable state.

Minimum correction: either process atomically without an asynchronous claim or add the complete `Running`/token/lease/attempt/error model, claim and reclaim transitions, scheduler/CLI ownership, and crash-before/after-posting red tests.

### NB3 — The five-push deployment manifest is not executable against the actual staging topology

Relevant plan sections: [PL-510 1598](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1598), [PL-511 1611](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1611), [Push 3 1913](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1913).

Defects:

- T12 does not own an API config file for `W_CASH_*`. Current feature flags are read through `config()`: [treasury.php 16](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/config/treasury.php:16), [PostShiftCashVarianceAdjustment.php 291](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Domain/Treasury/Services/PostShiftCashVarianceAdjustment.php:291). Shell validation alone does not expose flags under Laravel config caching.
- Staging uses separate deployable applications. The ledger records separate API and worker IDs: [handoff ledger 45](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/LEDGER.md:45). The plan captures only API and web IDs.
- The existing workflow says API deploys automatically on push while web requires a manual deployment: [workflow 206](/Users/houssamr/Projects/syneriva/apps/erp/docs/factory/WORKFLOW.md:206). PL-511 updates candidate environment after the push but explicitly redeploys only web. The API can therefore build against the preceding `APP_BUILD_SHA`.
- `READ_UI_ENABLED` is consumed without initialization.
- Push 4 consumes `CANDIDATE_SHA` before the current push’s PL-510 step refreshes it.
- Push 3 omits declared Data/DTO outputs and T5’s `CashDrawerService.php` change.
- T2 names nonexistent `FiscalProjectionDispatcher.php`; HEAD has [FiscalEventProjectionDispatcher.php 64](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Domain/Fiscal/Services/FiscalEventProjectionDispatcher.php:64).
- Push 2 stages broad directories, allowing unrelated files to enter a supposedly deterministic push.
- Worker, scheduler, and websocket environment/deployment state is neither captured nor proven.

Failure: backend and web fingerprints can refer to different commits; workers can run old code or default flags; Push 3 can omit code required by its own tests; first deployment can fail or publish a false green gate.

Minimum correction: add exact Laravel config ownership; capture every application ID; set environment/build arguments before deployment; explicitly redeploy and poll every changed runtime; derive `CANDIDATE_SHA` immediately after every commit; initialize all shell variables; use exact file manifests; fix the dispatcher path; and assert API, worker, scheduler, websocket, and web deployment/fingerprint IDs.

### NB4 — The rollback backups disappear during the deployment they are meant to protect

- Backup implementation: [TenantBackupService.php 18](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Domain/Tenancy/Services/TenantBackupService.php:18).
- Plan backup capture: [plan 1734](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1734).
- Staging volumes: [docker-compose.staging.yml 281](/Users/houssamr/Projects/syneriva/apps/erp/docker-compose.staging.yml:281).
- Existing deployment design explicitly treats staging application storage as ephemeral: [production environment design 313](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:313).

`TenantBackupService` writes dumps under local `storage/app/tenant-backups`. The plan takes backups before Push 2, then redeploys. No durable application-storage volume or off-container copy is specified. The TSV records only tenant ID, backup ID, and hash, not a restorable storage locator.

Failure: deployment replaces the container and deletes the dumps before controlled migration or rollback. A database backup record and checksum cannot restore a missing file.

Minimum correction: export dumps to durable off-container storage, record their paths/object keys, verify checksums after redeployment, and run a restore-readiness probe before any tenant migration.

### NB5 — Convention 10’s exact gate format is still violated

- Required skeleton: [Convention 10 line 37](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/10-industry-baseline-benchmark-first.md:37).
- Plan table: [plan 111](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:111), [header 115](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:115).

The header uses `ID`, `Guarantee`, `Dolibarr/NV`, and `AutoERP today path:line`, rather than the convention’s exact required header. Rows B12 and B16 also lack a real `AutoERP today` path:line citation: [B12 128](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:128), [B16 132](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:132).

Failure: the plan fails a mandatory gate-ready convention and leaves two “today” claims unverifiable.

Minimum correction: reproduce the required header verbatim, mark NV within the relevant product cell, and add real HEAD path:line evidence for B12 and B16.

## New majors

### NM1 — Manifest bounds and canonical membership remain underdefined

The schema provides only `lower_sequence` and `upper_sequence`: [plan 464](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:464). The manifest later requires both receipt and fiscal sequence coverage: [plan 737](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:737).

HEAD maintains distinct namespaces:

- receipt/Z anchoring: [zReportService.ts 165](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/services/zReportService.ts:165);
- fiscal-event chain sequence: [migrations.ts 920](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/db/migrations.ts:920).

Failure: one bound pair cannot state which namespace it covers. Implementations can hash different member sets while both conforming to the prose. Session-close/Z events can also be counted through overlapping source categories.

Minimum correction: define separate bounds for every sequence namespace, enumerate the exact canonical fields for every member type, define ordering and duplicate-membership rules, and add shared PHP/TypeScript vectors.

### NM2 — W7 dependency invalidation does not cover every writer

The plan requires every dependency writer to call `touch`: [plan 746](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:746). T9’s file list begins at [plan 1365](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1365), but omits the new Z-close manifest ingestor and existing writers for tender classification/binding and lot/evidence dependencies.

Failure: a late manifest, tender reclassification, or lot/evidence update can change reconciliation inputs without incrementing the dependency version. A stale reconciliation remains marked current.

Minimum correction: provide a complete writer census with exact paths, define how each writer finds every affected reconciliation head, call `touch` atomically with the dependency change, and add one red-first invalidation test for each writer class.

### NM3 — T3 does not satisfy Convention 09 and its generation command is self-defeating

- T3: [plan 1093](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1093).
- Convention 09 rerun requirement: [Convention 09 line 37](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/09-rerunnable-mutation-safety.md:37).

The tests repeat reads, not the manifest-building mutation. The command `typescript:transform` followed by `git diff --exit-code generated.d.ts` also fails when generation is intentionally supposed to create the task’s DTO delta.

Failure: mutation idempotence is unproven, while a correct generated-file change is interpreted as command failure.

Minimum correction: rerun the actual mutation and assert the declared no-op/exact-update outcome; compare generation against a task-owned expected artifact or run the diff only after the generated output has been established as the intended baseline.

### NM4 — Convention 11 vocabulary ownership is not implementation-grade

The plan introduces multiple new nouns at [plan 13](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:13) but merely assigns a generic glossary modification to T1. Convention 11 requires precise definitions and canonical ownership: [Convention 11 line 32](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/11-domain-language-glossary.md:32). The current glossary has only the existing Repository and Session reconciliation concepts: [glossary 60](/Users/houssamr/Projects/syneriva/apps/erp/docs/domain/glossary.md:60).

Failure: implementers can give “source evidence,” “semantic occurrence,” “manifest,” “reconciliation head,” and “mutation outcome” inconsistent meanings and writers.

Minimum correction: enumerate the exact glossary rows in T1, including definition, owning table/module, canonical surface, synonyms, and write path. Mark evidence-only concepts as non-operator-facing where applicable.

## Per-task dispatch audit

“Fail” means at least one requested dispatch dimension—exact files, schema/contract, signature, red-first test, command/rail, Convention 09, or reviewer gate—is incomplete.

| Task | Result | Missing or invalid gate |
|---|---|---|
| T0 | Pass | Scope/ruling guard is explicit and reviewable. |
| T12 | **Fail** | Missing Laravel config ownership, incomplete application IDs/env propagation, undefined shell variables, and no full-runtime deployment proof. |
| T1 | **Fail** | Reversal checks are contradictory; cutover immutability is not backed by an exact DB enforcement contract; glossary rows and ratchet-file ownership are not exact. |
| T2 | **Fail** | Wrong dispatcher filename and no red-first claim/lease/crash-recovery case. |
| T3 | **Fail** | Ambiguous manifest bounds/member fields, Convention 09 mutation rerun absent, generated-file command invalid. |
| T4 | **Fail** | Reversal state impossible; request class conflicts with HEAD’s `TransferRepositoryRequest`; controller/routes and `useTransferCash.ts` wiring are absent; no web red-first test. |
| T5 | **Fail** | Cross-rail identity is wrong; claim-state schema absent; actual v2/v3 rail test absent; Push 3 omits a declared service modification. |
| T7 | **Fail** | `ZCloseManifestBuildInput`, `ZCloseManifestData`, and `MutationResult` type-owning files are not named; canonical membership contract remains incomplete. |
| T8 | Pass | Exact service/controller/request/test ownership and prerequisite-stop behavior are materially specified. |
| T9 | **Fail** | Dependency-writer census and writer-specific invalidation tests are incomplete. |
| T10 | **Fail** | DTO and response field contract is not enumerated, preventing an independent implementation review. |
| T11 | Pass as withheld | Correctly contains no implementation while Q10–Q13 remain OPEN. Push 5 must remain prohibited. |

There is no separate T6; that is not itself a defect because its intended work is folded into T3.

## Rejected false positives

- **No Q10–Q13 policy branch is currently encoded.** Raw labels remain evidence, not semantic accounting policy. Manual transfers are independently authored. T11 and Push 5 are withheld.
- **The repaired composite FKs are creatable.** Revision 5 now adds supporting unique keys before child FKs.
- **The migration command exists and accepts the required form.** `tenants:migrate-rolling --force` matches [RollingTenantMigrationCommand.php 48](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Console/Commands/RollingTenantMigrationCommand.php:48), emits per-tenant results, and returns failure on migration errors.
- **Environment forwarding inside the remote migration shell is materially correct.** The nested shell preserves the database and force settings, with `pipefail` and captured output. The deployment defects are outside that command.
- **A web redeploy and asset/fingerprint check are present.** The defect is that API and other runtime deployments are not sequenced and proven equivalently.
- **The Dokploy application endpoints themselves are real.** The official API supports application retrieval, environment saving, and redeployment; the failure is incomplete topology and sequencing, not an invented endpoint. [Dokploy Application API](https://docs.dokploy.com/docs/api/application)
- **Generated type ownership is now correct.** The output is `generated.d.ts`, not a hand-maintained local mirror.
- **A→B→A recurrence no longer collides.** The non-unique historical occurrence plus head-pointer design fixes that prior defect.
- **W7 already incorporates opening float through the existing expected-cash service.** A second calculation should not be introduced.
- **The plan’s recorded inspection SHA differing from HEAD is not source drift.** The intervening repository change is documentation-only for another workstream.

## Preserve unchanged

The next revision must preserve:

- Q10–Q13 verbatim as OPEN.
- T11 and Push 5 prohibited until owner rulings exist.
- No inferred custody, branch-join, refusal, rejection, release, tender mapping, alignment, or variance policy.
- Half-open, serialized event-time cutovers.
- Raw evidence identity separated from semantic occurrence identity.
- Lock order: numbering → GL → document → sorted repositories.
- One immutable transfer document producing exactly two repository movements, zero or one JE, and reversal-only correction—after fixing its reversal schema.
- Failure-contained tenant provisioning.
- v70 before v71 and after W-LOT v68/v69.
- Close/Z identifiers allocated before manifest creation in the same SQLite transaction.
- Three durable count consumers covering both producers.
- Separate client manifest membership from server dependency snapshots.
- All activation flags default false.
- W7 prerequisite stop with `owner_ruling_required`.
- Immutable evidence retained across forward-only rollback.
- Two-company/location/terminal, offline, crash, reconnect, and replay scenarios.
- Explicit backend and web fingerprint verification.

## Owner decisions still required

All remain **OPEN**, verbatim from [owner rulings Q10–Q13](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-reconciliation-2026-09-05.md:140):

- **Q10:** “May the general manager **reject** a branch recall request and **release** the branch hold? Who may release, with what evidence?”
- **Q11:** “When two terminals open on one physical drawer, how is the float attributed and the drawer reconciled?”
- **Q12:** “What do `CASH_IN`, `CASH_OUT`, v2 `DEPOSIT`, `PAYOUT` mean in money terms: safe transfer, bank deposit, petty-cash expense, other counterparty?”
- **Q13:** “How do we set the opening Treasury balance of a drawer/safe that has traded for months without float/drop booking, and what happens to the disabled variance window?”

Recommended defaults, benchmarking text, or implementation convenience do not constitute authority to resolve these questions.

VERDICT: CHANGES-REQUIRED