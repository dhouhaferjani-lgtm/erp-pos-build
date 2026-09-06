# Codex plan gate r5 — W-CASH rev 4 (gpt-5.6-sol, high, read-only, 2026-09-06)

Input: rev 4 at local dev a40449249. Verbatim.

---
Reviewed read-only at HEAD `e5242a4d7`. Implementation code is unchanged from the plan’s inspected SHA `7e1400618`; only later documentation commits moved HEAD. No files were edited, no tests were run, and no git writes were made.

Revision 4 is not dispatch-ready. It restores much of the missing detail, but the migration contract still contains non-executable composite FKs, the promotion sequence depends on tooling that is not deployed yet, the fingerprint cannot deduplicate the same fact across rails, and the plan prematurely encodes a Q12 accounting branch.

## Previous-gate closure audit

### Gate-r3 tracked items

| # | Previous item | Status at rev 4 | Evidence |
|---:|---|---|---|
| 1 | B4 — v2/v3 fingerprint and cutover | **NOT CLOSED — BLOCKER** | Ordered cutover ranges are fixed at [plan:296](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:296), but the fingerprint includes rail and source ID at [plan:716](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:716), contradicting cross-rail deduplication at [plan:1044](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1044). |
| 2 | B5 — shared drawer/opening semantics | **CLOSED by withholding** | No join/refusal or drawer-custody state is permitted at [plan:109](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:109), and T11 remains withheld at [plan:1352](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1352). |
| 3 | B6 — W7 reconciliation | **NOT CLOSED — MAJOR** | The matrix is restored, but the schema omits the required Z identity and the task does not reuse/extend the canonical expected-cash service; compare [plan:560](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:560), [plan:1185](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1185), and [spec:414](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:414). |
| 4 | B7 — migration/deployment safety | **NOT CLOSED — BLOCKER** | Pushes 1–2 invoke T12-only backup/fingerprint tooling; Push 2 deploys through the current failure-swallowing entrypoint. See [plan:1431](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1431), [plan:1517](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1517), and [entrypoint.sh:131](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/docker/entrypoint.sh:131). |
| 5 | M4 — convention-09 tests | **NOT CLOSED — MAJOR** | T6 changes payment-repository types/consumers but is omitted from the convention-09 declaration and lacks a real-path rerun journey; T10 lacks the selected-second-location journey. See [plan:141](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:141), [plan:1060](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1060), and [convention 09:30](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/09-SECOND-OF-EVERYTHING.md:30). |
| 6 | M9 — local type census | **NOT CLOSED — MAJOR** | Consumer census is corrected, but the plan writes `generated.ts`; the configured output is `generated.d.ts` at [typescript-transformer.php:45](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/config/typescript-transformer.php:45). |
| 7 | No opening-interval algorithm | **REJECTED correctly** | Such an algorithm would decide OPEN Q11; prohibition remains at [plan:111](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:111). |
| 8 | Durable cutover/config revisions | **NOT CLOSED — BLOCKER** | Several promised composite FKs lack corresponding parent unique keys; see [plan:367](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:367), [plan:468](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:468), and [plan:616](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:616). |
| 9 | Tasks lack dispatch contracts | **NOT CLOSED — MAJOR** | Wrong existing paths, generic enum/DTO ownership, an unregistered command, a missing CLI signature, and unnamed red-first cases remain; see task audit below. |
| 10 | CashCountDispatcher durability | **CLOSED** | Both actual producers are named and obligations are inserted before commit at [plan:1146](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1146). |
| 11 | Manifest membership/atomicity | **CLOSED only at abstract ordering level** | Required write order is correct at [plan:696](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:696). The implementation task remains invalid because it names the wrong producer and underspecifies member derivation. |
| 12 | Cross-rail fingerprint | **REOPENED — BLOCKER** | Rail/source identity inside the supposedly semantic fingerprint prevents v2/v3 collision; [plan:716](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:716). |
| 13 | POS/local type census | **NOT CLOSED — MAJOR** | Correct shadows are found, but generation and promotion target the nonexistent `packages/shared/types/generated.ts`; [plan:1067](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1067). |
| 14 | Provisioning hooks/atomicity | **CLOSED** | Existing best-effort interfaces and callers are explicitly preserved at [plan:687](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:687). |
| 15 | Promotion evidence | **NOT CLOSED — BLOCKER** | Status polling remains prose, build-SHA injection is absent, and early pushes call unavailable fingerprint surfaces; [plan:1449](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1449). |
| 16 | Tenant-only uniqueness ratchet | **CLOSED** | Correct scanner and ratchet ownership are named at [plan:861](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:861). |
| 17 | Convention 10 | **NOT CLOSED — BLOCKER** | Header is still not the verbatim skeleton, and B3/B6/B13 lack real `path:line` reality citations; compare [plan:124](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:124) with [convention 10:37](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/10-BENCHMARK-FIRST-SPECS.md:37). |
| 18 | Premature Q11 branch | **CLOSED** | No shared-drawer join/refusal branch exists; [plan:109](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:109). |
| 19 | Task/schema contract | **NOT CLOSED — BLOCKER** | Composite FK targets are incomplete and task file lists remain inaccurate; [plan:227](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:227). |
| 20 | Staging manifest | **NOT CLOSED — BLOCKER** | See new Blocker 3 below. |
| 21 | Financial lock inversion | **CLOSED** | Required order is explicit at [plan:213](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:213) and matches the existing sorted repository locks at [TreasuryMovementService.php:237](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:237). |
| 22 | Impossible manifest construction order | **CLOSED at design level** | Close/Z identities precede manifest construction at [plan:696](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:696). |
| 23 | Stored-event consumer | **CLOSED** | It is an explicit durable consumer with transactional storage at [plan:698](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:698). |
| 24 | Provisioning contradiction | **CLOSED** | Failure containment and unchanged interface signatures are explicit at [plan:687](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:687). |
| 25 | Fingerprint custody boundary | **CLOSED narrowly** | Shift/session identity or explicit absence is mandatory at [plan:716](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:716). |
| 26 | POS generated type ownership | **NOT CLOSED — MAJOR** | Backend DTO ownership is present, but the generated output filename is wrong; [plan:1064](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1064), [typescript-transformer.php:53](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/config/typescript-transformer.php:53). |
| 27 | Explicit rerun outcomes | **NOT CLOSED — MAJOR** | Several are restored, but T0 requires `already_exists` from a read-only audit with no state contract, and T6 has none; [plan:829](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:829). |
| 28 | Nonexistent paths | **NOT CLOSED — MAJOR** | T7 names nonexistent `apps/pos/src/services/zReportService.ts`; T9 places `ZReportProjection` in Fiscal instead of POS; generated output is also wrong. Actual files are [zReportService.ts:1](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/lib/offline/zReportService.ts:1) and [ZReportProjection.php:1](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Projections/ZReportProjection.php:1). |

### Previous task-dispatch audit

| Task | Closure status | Current result |
|---|---|---|
| T0 | Prior path defect closed | **FAIL:** read-only `audit()` has no contract capable of returning mutation outcome `already_exists`; [plan:841](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:841), [plan:854](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:854). |
| T1 | **NOT CLOSED** | Generic “every enum and DTO” is not an exact file list, and the DDL has impossible composite FKs; [plan:863](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:863). |
| T2 | **NOT CLOSED** | `RecoverFiscalProjectionLeasesCommand` is created but its full CLI signature is absent; input/result DTO files used by the signatures are also absent; [plan:906](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:906). |
| T3 | **NOT CLOSED** | `CashRepositoryConfigurationInputData` appears in the signature but has no exact owning file; [plan:955](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:955). |
| T4 | **NOT CLOSED** | Creates `ReplayRepositoryTransferCommand` but does not modify `TreasuryServiceProvider` to register it; [plan:979](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:979). |
| T5 | **NOT CLOSED** | Treasury signatures accept POS/Fiscal models directly, violating the cross-module rule at [CLAUDE.md:30](/Users/houssamr/Projects/syneriva/apps/erp/CLAUDE.md:30); [plan:1033](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1033). |
| T6 | **NOT CLOSED** | Wrong generated output and no convention-09 rerun journey; [plan:1067](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1067). |
| T7 | **NOT CLOSED** | Wrong producer path and no executable mapping from current payment/count rows to required manifest fields; [plan:1099](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1099). |
| T8 | **CLOSED** | Both producers, dispatcher, command, exact tests, PG lane, and reviewer are present at [plan:1146](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1146). |
| T9 | **NOT CLOSED** | Wrong projector path and omission of `ShiftExpectedCashService`; [plan:1187](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1187). |
| T10 | **NOT CLOSED** | No generated API DTO contract, permission decision, or i18n files for the new operator UI; [plan:1261](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1261). |
| T11 | **CLOSED by withholding** | Correctly non-dispatchable at [plan:1352](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1352). |
| T12 | **NOT CLOSED** | Missing Dockerfile build-arg ownership, unnamed container/Playwright cases, and its tooling arrives after Pushes 1–2 need it; [plan:1303](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1303). |

### Gate-r4 findings

| Previous finding | Status |
|---|---|
| Blocker 1 — migration cannot execute | **NOT CLOSED.** Original projection/count mistakes are fixed, but new composite FK targets remain invalid; [plan:367](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:367). |
| Blocker 2 — lost cash-count obligation | **CLOSED.** Both producer transactions are owned by T8; [plan:1150](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1150). |
| Blocker 3 — staging cannot prove candidate | **NOT CLOSED.** See new Blocker 3. |
| Blocker 4 — multiple current reconciliation runs | **CLOSED narrowly.** Locked transition plus partial unique index prevents two current rows; [plan:718](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:718). Historical-fingerprint reactivation is a separate new major. |
| Major 1 — cutover ranges | **CLOSED.** Ordered half-open coordinates and serialized overlap rejection are exact; [plan:296](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:296). |
| Major 2 — money/enums | **CLOSED at contract level.** New money is scale 3 and enum/check ownership is stated; [plan:229](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:229). |
| Major 3 — convention 11/generated types | **NOT CLOSED.** Wrong service name and generated filename remain; [glossary:76](/Users/houssamr/Projects/syneriva/apps/erp/docs/glossary.md:76), [plan:681](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:681). |
| Major 4 — W7 matrix | **CLOSED narrowly.** Required cases now have exact methods and assertions at [plan:1226](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1226). |
| Major 5 — provisioning regression | **CLOSED.** Best-effort behavior and failure tests are retained; [plan:940](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:940). |
| Major 6 — entrypoint ownership | **CLOSED narrowly.** All four entrypoints are named at [plan:1307](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1307). Promotion ordering and build propagation remain blockers. |
| Prior independent minors | **REJECTED.** Gate r4 explicitly reported none at [previous review:143](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/reviews/2026-09-06-w-cash-plan-codex-gate-r4.md:143). |

## NEW BLOCKER findings

### 1. A Q12 branch is encoded before the owner ruling

- Plan: [plan:377](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:377), [plan:387](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:387), [plan:1575](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1575)
- Source: [owner rulings:142](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:142), [CashDrawerOperation.php:112](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Domain/CashDrawerOperation.php:112)
- Failure scenario: `RepositoryTransferSourceFactType::safe_drop` places a raw `SAFE_DROP` fact on an immutable financial transfer document, while Push 4 preselects a drawer/safe topology. That encodes the Q12 “safe transfer” branch before the owner has ruled typed meanings and destination mappings. False flags do not make prohibited schema vocabulary permissible.
- Minimum correction: remove `safe_drop` and every typed cash-operation-to-financial-document association from T1/T4/Push 4. Retain only immutable raw evidence and `owner_ruling_required`. Add the selected transfer source types and destination mapping only in the post-ruling T11 supplement.

### 2. The new composite FK contract cannot be created as written

- Plan: [plan:367](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:367), [plan:372](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:372), [plan:468](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:468), [plan:616](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:616)
- Source: the plan’s own universal ownership requirement at [plan:233](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:233); current PostgreSQL migrations demonstrate that referenced identities are otherwise single-column PKs, for example [pos_z_reports migration:21](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_01_08_190644_create_pos_z_reports_table.php:21).
- Failure scenario:
  - `(configuration_id, company_id)` references `cash_repository_configurations(id, company_id)`, but the parent has no unique `(id, company_id)`.
  - `current_revision_id` cannot be constrained to the same configuration without a revision unique over `(id, configuration_id, company_id)`.
  - The promised `(cutover_id, company_id)` FK has no `fiscal_projection_cutovers(id, company_id)` unique.
  - Same-parent run FKs and `current_run_id` need a unique `(id, reconciliation_id, company_id)` on runs; none is specified.
  - Cutover policy and transfer journal-entry references remain single-column and permit cross-company attachment.
  PostgreSQL rejects the unsupported composites with `SQLSTATE 42830`, or the implementer weakens them and violates tenant/company ownership.
- Minimum correction: enumerate every supporting unique before its FK; use triple identity where same-parent ownership is promised; add composite policy and journal-entry ownership; include each exact target column order in the schema test.

### 3. The five-push manifest is circular and non-executable

- Plan: [plan:1431](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1431), [plan:1449](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1449), [plan:1517](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1517), [plan:1529](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1529)
- Source: current backup options are only `slug` and `--all` at [BackupTenantCommand.php:18](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Application/Commands/BackupTenantCommand.php:18); boot migrations swallow failures at [entrypoint.sh:131](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/docker/entrypoint.sh:131); web build accepts only three existing args at [Dockerfile:50](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/Dockerfile:50); explicit web deployment/freshness is mandatory at [WORKFLOW.md:206](/Users/houssamr/Projects/syneriva/apps/erp/docs/factory/WORKFLOW.md:206).
- Failure scenario:
  - Push 1 invokes PL-123, but `/api/feature-fingerprint`, `build-meta.json`, and the Playwright test do not exist until T12/Push 3.
  - Push 2 invokes `tenant:backup --format=json`, but that option also arrives only in Push 3.
  - The PL-122 `jq -e ... | (! read -r)` pipeline fails under `pipefail` both when bad rows exist and when `jq -e` emits no rows.
  - Push 2 deployment auto-runs the new migrations through the existing entrypoint before PL-124, while that entrypoint logs and continues on central or tenant migration failure.
  - Deployment status polling remains prose, not an exact endpoint/JSON path/timeout command.
  - The manifest never updates Dokploy’s candidate-specific `APP_BUILD_SHA` or `VITE_APP_BUILD_SHA`.
  - T12 does not modify `apps/web/Dockerfile`, so Vite never receives the proposed build SHA/read-UI build args.
  - Push 3 checks nonexistent `packages/shared/types/generated.ts`; the real output is `generated.d.ts`.
- Minimum correction: ship backup JSON, fingerprint endpoints, build metadata, Docker build args, fail-closed entrypoints, and exact Dokploy polling in Push 1 before any consumer push. Replace the `jq` pipeline with one boolean `all(...)` assertion. Set candidate SHA through executable Dokploy environment/build-arg commands. Run controlled migration before serving the schema-dependent candidate. Use `generated.d.ts` and compare it against a captured post-generation baseline rather than requiring all intended generated changes to disappear.

### 4. The semantic fingerprint cannot perform its required cross-rail deduplication

- Plan: [plan:470](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:470), [plan:716](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:716), [plan:1044](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1044)
- Source: v2 has its own UUID and operation timestamp at [CashDrawerOperation.php:20](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Domain/CashDrawerOperation.php:20); v3 has a distinct fiscal-event ID/time at [FiscalEvent.php:25](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Fiscal/Domain/Models/FiscalEvent.php:25).
- Failure scenario: the same economic fact arriving once as v2 and once as v3 necessarily hashes differently because `source_rail` and `source_id` are fingerprint inputs. Both uniqueness constraints therefore accept it. After activation it can post twice, contradicting T5’s required `applied`/`already_exists` result.
- Minimum correction: separate raw-source identity from an economic semantic key. Store rail/source ID in raw evidence, but define the cross-rail key from normalized company/location/terminal/shift/session/time/kind/amount/currency and an explicit authored linkage or collision-resolution rule. Test identical, near-collision, and conflicting cross-rail facts.

## NEW MAJOR findings

### 1. The manifest member contract cannot be derived from current device records

- Plan: [plan:502](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:502), [plan:1099](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1099)
- Source: receipt rows at [migrations.ts:101](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/lib/db/migrations.ts:101), cash operations at [migrations.ts:270](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/lib/db/migrations.ts:270), Z counts at [migrations.ts:492](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/lib/db/migrations.ts:492), payment JSON construction at [receiptService.ts:515](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/lib/offline/receiptService.ts:515).
- Failure scenario: payment JSON elements have no payment UUID, occurred-at, sequence, or event hash; cash-operation and Z-count rows have no event hash or economic-payload hash. The task nevertheless requires every namespace member to contain all of those fields and gives no deterministic synthetic-identity/hash mapping. Two implementers can produce incompatible manifests or simply omit streams.
- Minimum correction: specify a source query and deterministic identity, occurred-at, sequence sentinel, event hash, and economic hash algorithm per namespace. Correct T7’s producer path to `apps/pos/src/lib/offline/zReportService.ts`.

### 2. W7 diverges from the governing canonical service and schema

- Plan: [plan:560](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:560), [plan:1187](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1187)
- Source: [spec:414](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:414), [spec:416](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:416), [ShiftExpectedCashService.php:27](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Services/ShiftExpectedCashService.php:27), [glossary:76](/Users/houssamr/Projects/syneriva/apps/erp/docs/glossary.md:76)
- Failure scenario: the head is keyed only by company/shift rather than tenant/company/terminal/session/Z; T9 does not modify `ShiftExpectedCashService` for membership-aware selection; and the plan names `SessionCashReconciliationService` while the glossary names `PosSessionReconciliationService`. This creates a second expected-cash orchestration and violates convention 11.
- Minimum correction: use the glossary service name, add the full session/Z identity and ownership constraints, explicitly modify/reuse `ShiftExpectedCashService`, and prohibit duplicate cash arithmetic in the new service.

### 3. Reusing a historical input fingerprint can leave the wrong run current

- Plan: [plan:600](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:600), [plan:726](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:726)
- Source: stable-snapshot invalidation requirement at [spec:422](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:422).
- Failure scenario: if dependencies change A→B→A, the A fingerprint already exists as a historical non-current run. Step 7 returns `already_exists` and does not repoint the head, so B remains current despite the live snapshot being A.
- Minimum correction: distinguish “fingerprint equals current” from “fingerprint exists historically.” For the latter, atomically reactivate the existing run or append a new occurrence with a separate snapshot-occurrence identity and supersession record.

### 4. Exact task ownership is still incomplete

- Plan: T1 [plan:863](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:863), T2 [plan:906](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:906), T4 [plan:979](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:979), T10 [plan:1263](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1263)
- Source: test-first and one-task scope rules at [CLAUDE.md:18](/Users/houssamr/Projects/syneriva/apps/erp/CLAUDE.md:18), [CLAUDE.md:24](/Users/houssamr/Projects/syneriva/apps/erp/CLAUDE.md:24).
- Failure scenario: implementers must invent enum/DTO paths, the recovery CLI signature, Treasury command registration, W7 response DTO/type generation, permissions, and translation resources. T12’s shell/Playwright tests have no exact case names.
- Minimum correction: enumerate every created enum/DTO/FormRequest/i18n/provider file; give the recovery command its complete signature; register every command in its task; name every red-first case and its first assertion.

### 5. Convention 09 remains incomplete for repository-facing tasks

- Plan: [plan:141](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:141), [plan:1060](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1060), [plan:1261](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:1261)
- Source: FE forms/lists and payment repositories are explicitly in scope at [convention 09:30](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/09-SECOND-OF-EVERYTHING.md:30), with required journeys at [convention 09:37](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/09-SECOND-OF-EVERYTHING.md:37).
- Failure scenario: T6 and T10 can pass decoder/404 checks while a company switch, selected second location, or repeated retry still exposes the first company/location’s repository data.
- Minimum correction: add a real company-B creation, real second `pos_enabled` location selection, B-only list/detail assertion, and repeated mutation/retry with unchanged IDs/balances and explicit outcome in each applicable lane.

## NEW MINOR findings

No independent minor finding. Remaining defects affect mandatory gates, executable rollout, tenant ownership, or financial idempotency.

## Rejected false positives

- The plan’s inspected SHA differs from HEAD, but implementation paths have no diff between `7e1400618` and `e5242a4d7`.
- Q10–Q13 are reproduced verbatim and marked OPEN at [plan:98](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md:98).
- No Q11 terminal join/refusal or float-owner branch is present.
- `tenants:migrate-rolling --force` is valid and returns nonzero for collected tenant failures at [RollingTenantMigrationCommand.php:48](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Application/Commands/RollingTenantMigrationCommand.php:48) and [RollingTenantMigrationCommand.php:158](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Application/Commands/RollingTenantMigrationCommand.php:158).
- Push 4 does capture configuration, revision, policy, and cutover IDs. Its defect is premature policy encoding and reliance on an otherwise broken rollout.
- The financial lock order, two-movement/zero-or-one-JE cardinality, close/Z-before-manifest ordering, and three durable cash-count consumers are correct.
- `ShiftExpectedCashService` already includes opening cash at [ShiftExpectedCashService.php:265](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Services/ShiftExpectedCashService.php:265); no second opening component should be added.
- v68-before-v69, the campaign command, the Tauri command, and use of existing queues are valid.
- Historical backbooking and automatic variance activation remain prohibited.

## Preserve

- Q10–Q13 verbatim and OPEN; T11 and Push 5 prohibited.
- No custody-session, join/refusal, typed-operation mapping, alignment, disabled-window closure, or recall-release implementation before rulings.
- Event-time cutovers with ordered half-open coordinates and overlap serialization.
- Raw evidence separate from semantic identity.
- Tenant numbering → company GL chain → transfer document → sorted repositories.
- One immutable transfer document, exactly two repository movements, zero-or-one JE, reversal-only correction.
- Failure-contained company/location provisioning.
- v68 before v69.
- Close/Z identities before manifest construction in one SQLite transaction.
- Three independently durable cash-count consumers seeded in both producer transactions.
- Device manifest membership separate from server dependency snapshots.
- Default-false literal flags.
- W7 prerequisite stop and `owner_ruling_required` cells.
- Immutable evidence and forward/non-destructive rollback.
- Two-company, two-location, two-terminal, offline, crash, reconnect, and replay evidence.

## Owner decisions still required

All remain **OPEN**:

- **Q10 — Recall request lifecycle:** “May the general manager **reject** a branch recall request and **release** the branch hold? Who may release, with what evidence?” Recommended branch remains `requested → recalled (company-wide)` or `requested → released`, with only the general manager able to release and mandatory append-only reason/evidence.
- **Q11 — Shared drawer, two terminals:** “When two terminals open on one physical drawer, how is the float attributed and the drawer reconciled?” The join-versus-refusal branch remains unruled.
- **Q12 — Typed meaning of cash in/out:** “What do `CASH_IN`, `CASH_OUT`, v2 `DEPOSIT`, `PAYOUT` mean in money terms: safe transfer, bank deposit, petty-cash expense, other counterparty?” No typed financial mapping may be encoded yet.
- **Q13 — Historical alignment:** “How do we set the opening Treasury balance of a drawer/safe that has traded for months without float/drop booking, and what happens to the disabled variance window?” No alignment or disabled-window closure may be implemented yet.

VERDICT: CHANGES-REQUIRED