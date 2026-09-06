# Codex slice-plan gate r3 — W-CASH-1 rev 3 (gpt-5.6-sol, high, read-only, 2026-09-06)

Input: rev 3 at a5191dafd. Verbatim.

---
# W-CASH-1 rev 3 adversarial gate

Audit baseline: current local `dev` HEAD is `a5191dafdf7506da6b513eb5a49bff0b0d2e75df`, not the plan’s declared `d64675d…`. Governed production sources are unchanged between those revisions; only the plan differs. Every explicit repository-relative citation resolves to an existing file and in-bounds line at current HEAD. No files were edited and no tests were run.

Result: 0 BLOCKERs, 8 MAJORs, 3 MINORs.

## BLOCKER

None.

## MAJOR

### M1 — P0 misstates the physical default and does not ratchet the complete column shape

- Plan: [line 94](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:94)
- Source: [create migration line 29](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2025_11_30_120000_create_treasury_tables.php:29)
- Failure scenario: the plan says `payment_repositories.balance` has “no default,” but it is `DEFAULT 0`. The proposed ratchet checks only precision and scale. A migration that drops the default or changes nullability could pass while breaking repository provisioning or raw inserts.
- Minimum correction: state `balance numeric(15,3) NOT NULL DEFAULT 0`; retain nullable/no-default for `last_reconciled_balance`; specify the corresponding defaults for journal debit/credit. Extend both P0 tests and the architecture ratchet to assert precision, scale, nullability, and normalized `column_default` before, after, and on rerun.

### M2 — P0 omits the precision ticket’s required pre-widen drift census and reviewer

- Plan: [line 103](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:103)
- Source: [precision-debt ticket line 13](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/tickets/2026-09-06-gl-and-repository-balance-columns-scale-2-precision-debt.md:13)
- Failure scenario: P0 records schema shape and synthetic `1.005` behavior but supplies no staging census of measurable existing document/movement/repository/JE discrepancies before widening. It also substitutes `tenancy-authz-reviewer` for the ticket-mandated `stock-gl-interaction-reviewer`.
- Minimum correction: add an exact read-only census command or host-side SQL procedure, stable marker, test class/methods, pass/fail criteria, and pre-ALTER evidence capture. Label historically unrecoverable third decimals as unknown rather than clean. Require `treasury-reviewer` and `stock-gl-interaction-reviewer`—retaining tenancy review if desired.

### M3 — Compatibility-mode migration execution remains unspecified

- Plan: [line 107](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:107)
- Sources: [RollingTenantMigrationCommand line 57](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Application/Commands/RollingTenantMigrationCommand.php:57), [AppServiceProvider line 283](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Providers/AppServiceProvider.php:283)
- Failure scenario: with `TENANCY_DB_PER_TENANT=false`, `tenants:migrate-rolling` returns success without applying anything. Outside testing, the default migrator deliberately does not load `database/migrations/tenant`. The plan merely requests an “explicit compatibility migration target/log” without giving the command, exit rule, or evidence. P0 and T1 tables could therefore remain absent while deployment appears green.
- Minimum correction: give the exact compatibility command, connection target, output marker, exit check, and post-command information-schema queries for P0 and both WCASH migrations—for example a verified `migrate --force --path=database/migrations/tenant/...` path on the shared physical database. Keep the per-tenant rolling path for DB-per-tenant mode.

### M4 — P0 does not satisfy convention 09 for its own catalogue-touching task

- Plan: [line 103](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:103)
- Convention: [convention 09 line 30](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/09-SECOND-OF-EVERYTHING.md:30)
- Failure scenario: P0 alters `payment_repositories`, a named catalogue table. Its second-company/location test only proves drawer provisioning; its explicit rerun is the migration itself. It never repeats a repository money mutation and asserts an explicit idempotent outcome, so doubled-balance or selected-location leakage can survive P0.
- Minimum correction: add an exact PG test using the real company/location fixture and current movement port: record `1.005` against company B’s selected second-location drawer, repeat the same stable source identity, assert the existing/idempotent outcome, unchanged balance/ordinal/count, and no company-A effect.

### M5 — T1’s claimed self-guarding migrations have no wrong-shape liveness test

- Plan: [line 124](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:124)
- Manifest: [line 89](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:89)
- Failure scenario: T1 promises existence guards plus fail-loud verification, but its rerun test only observes Laravel’s “Nothing to migrate.” T6 tests empty down/up. Neither proves that a pending migration rejects a pre-existing partial table, wrong FK, missing trigger, wrong CHECK, or wrong unique before further DDL.
- Minimum correction: add named PG tests that construct malformed pre-existing versions of each table/owned parent constraint, invoke each migration directly, assert a specific refusal, and prove schema/data remain unchanged. Include the exact class, methods, command, lane, and first failing assertions.

### M6 — T4 leaves reversal linkage out of immutable movement audit events

- Plan: [line 365](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:365)
- Sources: [current builder line 639](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:639), [event payload line 75](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Domain/Events/RepositoryMovementRecorded.php:75)
- Failure scenario: T4 extends the builder only for `recordedWhileFrozen`; the current builder hardcodes `reversesMovementId: null`. The rows could correctly link their reversed movements while Compliance’s immutable audit event records both reversal links as null.
- Minimum correction: extend the builder contract to receive the direction-specific reversal movement ID, populate the existing immutable event field, and add red tests asserting both emitted event payloads and resulting audit rows reference the original opposite legs.

### M7 — Push 3 is described as dormant although it disables the live endpoint

- Plan: [lines 295 and 542](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:295)
- Manifest: [line 114](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:114)
- Failure scenario: today the scalar controller performs a transfer. T3 changes that caller to return 503 under both flag states, while deployment calls Push 3 “dormant replacement writer/default-false.” That is an immediate behavior change and violates the manifest’s requirement that all Push-3 behavior remain inert.
- Minimum correction: define a packaging transition that leaves the current endpoint behavior unchanged during Push 3 and migrates/removes the deprecated adapter atomically at activation, before any document exists. After first documented use, false-flag rollback must remain fail-closed and must never restore the documentless writer.

### M8 — Several typed task contracts still lack exact production files and signatures

- Plan: [line 295](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:295)
- Scope contract: [authoring prompt line 19](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/CODEX-PROMPT-slice-plan-WCASH-1-custody-config-transfer-doc-2026-09-06.md:19)
- Failure scenario: T3 requires a “typed” write-disabled refusal and transfer conflict; T2 requires a revision conflict; T4 requires a correction-required error and structured warning. Their class names, files, constructors, and mapping seams are not specified. Implementers can produce incompatible 500/409/422/503 handling while appearing to follow the plan.
- Minimum correction: enumerate every new exception/result/alert class with exact path and constructor, name the controller/handler mapping, and identify the concrete alert sink. Add its files to the task’s production set and its exact envelope/payload assertion to the named test register.

## MINOR

### m1 — Unknown-field red assertions contradict the stated Laravel mechanism

- Plan: [line 478](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:478)
- Mechanism: [plan line 407](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:407)
- Failure scenario: the callback adds an error under the actual unknown key, but the tests assert `errors.unexpected_field`. Laravel will return `errors.<submitted-key>`.
- Minimum correction: assert the exact forged key, such as `errors.surprise.0`, and compare its translated value to `messages.validation.unexpected_field`.

### m2 — The reviewed HEAD declaration is stale again

- Plan: [line 3](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:3)
- Current HEAD: `a5191dafdf7506da6b513eb5a49bff0b0d2e75df`
- Failure scenario: dispatch evidence names `d64675d…`, making the reviewed plan revision and current local-dev baseline indistinguishable.
- Minimum correction: record the current HEAD and note that governed production sources are unchanged; re-anchor if source changes before dispatch.

### m3 — The deployment census prose contains a malformed failure token

- Plan: [line 538](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:538)
- Manifest: [line 247](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:247)
- Failure scenario: `zero DRIFT(;` is not the canonical `DRIFT(` token and is unsafe to copy into operational grep criteria.
- Minimum correction: restore the exact `DRIFT(` literal and retain the manifest’s verdict-line inspection.

## Prior gate r2 closure table

| r2 item | Disposition | Rev-3 evidence |
|---|---|---|
| B1 actor union | CLOSED | Human/system discriminator, nullable human FK, provenance fields and tests at [126–190](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:126) and [342–353](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:342). |
| B2 precision storage | CLOSED as the original blocker; P0 still has new execution defects M1–M4 | Conditional P0, raw `1.005`, ratchet, host backup and rolling verification at [88–107](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:88). |
| M1 signature transition | CLOSED | Deprecated scalar adapter, named final caller and same-task removal at [293–295](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:293). Deployment packaging still needs M7. |
| M2 convention 09 per T1–T6 | CLOSED for the original six tasks | Common real fixture and explicit per-task outcomes at [109–113](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:109). New P0 gap is M4. |
| M3 durable history link | CLOSED | Exact backend/web projection files and authorization behavior at [428–430](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:428), tests at [480–482](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:480). |
| M4 live shadows/POS generated globals | CLOSED | POS triple-slash wiring, fully qualified namespace and `StatementUploadWizard` at [432–438](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:432). |
| M5 document-per-action baseline | CLOSED | Baseline shrink and both ratchet methods at [357](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:357). |
| m1 notes normalization | CLOSED | Exact trim/NFC/whitespace/case-preserving contract and tests at [302](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:302). |
| m2 unknown fields | NOT CLOSED | Mechanism exists at line 407, but the named assertion is incompatible; see current m1. |
| m3 enum/CHECK parity | CLOSED | Invalid SQL cases plus existing parity ratchet at [218](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:218). |
| m4 evidence baseline/anchor | REGRESSED at current HEAD | Repository predicate anchor is corrected, but the declared HEAD is stale; see current m2. |

## Rejected false positives

- Q11–Q13 are copied verbatim from the ruled rows and are explicitly deferred at [lines 63–73](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:63). No drawer-session model, Q12 reason-code enum/mapping, historical alignment, shift booking, v2/v3 adapter, W7, or variance activation ships.
- P0 does not correctly support a claim that a fully migrated HEAD schema is currently scale 2. The March migration already lists and alters all four columns at [lines 27–29](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_03_11_200000_widen_monetary_columns_to_scale_3.php:27) and [113–115](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_03_11_200000_widen_monetary_columns_to_scale_3.php:113). Rev 3 correctly makes P0 conditional fleet verification/repair.
- `RepositoryTransferDocumentKind`, initiator/provenance enums, outcomes, and existing `MovementReasonCode` are not Q12 device cash-reason codes.
- The actor union covers both required branches without manufacturing a system user and does not require this slice to ship a queue or projector.
- The deprecated signature remains until the only production caller migrates; the finding is deployment behavior, not compile continuity.
- The convention-10 matrix has the required eight columns, all nine mandatory flow rows, and the added document-per-action row.
- Convention 11 is correctly modeled as two new glossary rows with one table, writer, and existing repository surface each.
- The POS generated-global mechanism is correct: ambient `App.*` declarations plus the same triple-slash path used by web.
- Same-GL zero-JE and cross-GL one-posted-JE behavior match the existing movement port and proposed document invariant.
- No unresolved owner decision remains.

## Preserve list

- Six WCASH tasks plus the separately gated P0 prerequisite.
- Conditional P0 repair rather than falsely asserting every deployment is scale 2.
- Host-side backup, forward-only financial rollback, raw `1.005` proof, and per-tenant shape verification.
- Human/system authority union with stable system principal, source and terminal provenance.
- Company-scoped operation UUID, server-generated transfer group, semantic conflict detection, and exact retry outcome.
- One immutable document, exactly two cross-linked legs, zero/one JE, immutable original, and one linked full reversal.
- Operation → numbering → company → sorted repository lock order.
- HTTP-only location resolution; no CompanyContext/user fabrication in workers or commands.
- Default-false cutover and permanent prohibition on returning to a documentless writer after first documented use.
- Durable history links with destination-only suppression and scoped direct reads.
- Generated DTO direction, POS ambient wiring, tenant-scoped query keys, numeric-string money, and one repository surface.
- Exact Q11–Q13 deferrals and all stated slice exclusions.
- Canonical manifest sentence, five-push ordering, web fingerprint, U-1/U-2/U-5 gates, and forward-only rollback.
- The three unrelated untracked files remain untouched.

## Owner decisions required

None. All remaining findings are engineering, verification, or deployment-contract corrections.

VERDICT: CHANGES-REQUIRED