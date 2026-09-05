# Codex plan gate r1 — W-CASH brief (gpt-5.6-sol, high effort, read-only, 2026-09-06)

Input: docs/handoff/HANDOVER-W-CASH-float-drops-treasury-2026-09-05.md at local dev cd8188d82. Verbatim Codex output below.

---
Reviewed at HEAD `cd8188d82d11`. Required authorities and cited implementation seams were read. No files were modified and no tests were run.

## Findings

### BLOCKER 1 — The brief fails the mandatory mechanical plan gate

Brief [lines 7–17](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/HANDOVER-W-CASH-float-drops-treasury-2026-09-05.md:7) omits Dolibarr, provides no per-row benchmark citations, and gives real AutoERP `path:line` evidence for only one row. Even that citation, `ShiftExpectedCashService:27-59`, points to explanatory comments, not the implementation at [lines 252–304](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Services/ShiftExpectedCashService.php:252), [484–520](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Services/ShiftExpectedCashService.php:484), and [635–715](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Services/ShiftExpectedCashService.php:635). There is also no required Vocabulary line.

Convention 10 explicitly blocks a user-facing brief without Odoo/ERPNext/Dolibarr guarantees, actual AutoERP `path:line`, and a decision for each applicable create, permissions, audit, replay, second-company, and second-location guarantee ([convention 10 lines 29–33, 37–69](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/10-BENCHMARK-FIRST-SPECS.md:29)). Convention 11 requires the Vocabulary line ([lines 63–66](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md:63)).

Failure scenario: round-0 cannot verify whether cash-in/out, transfer cancellation, configuration changes, permissions, or audit evidence deliberately match or diverge from industry behavior.

Minimum correction: replace §0 with the full convention-10 matrix, including sourced Dolibarr dispositions or explicit `NV`; cite every AutoERP row precisely; add the Vocabulary line covering at least Cash custody configuration, Shift cash booking, Repository transfer document, Coverage interval, and Opening-balance alignment.

### BLOCKER 2 — C1 is unresolved design discovery, so C2–C6 are not dispatchable yet

[C1 at line 33](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/HANDOVER-W-CASH-float-drops-treasury-2026-09-05.md:33) asks the implementer to discover “every” producer, payload, writer, and staging balance, but the later slices already assume the result. No enumerated file map, schema delta, DTO contract, command signature, or test map follows.

This matters because HEAD already contains materially different rails:

- v3 uses `OPENING_FLOAT`, `CASH_IN`, `CASH_OUT`, and `SAFE_DROP` fiscal events ([ZSessionLifecycleProjection lines 31–42](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Projections/ZSessionLifecycleProjection.php:31)).
- v2 uses `OPENING`, `DEPOSIT`, and `PAYOUT` rows with different documented meanings ([migration lines 63–74](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_01_08_190642_create_pos_cash_drawer_operations_table.php:63)).
- The device authors both `SESSION_OPEN` and a separate `OPENING_FLOAT` ([zSessionAuthoring lines 507–519](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/lib/fiscal/zSessionAuthoring.ts:507)).

Failure scenario: C3 is implemented against whichever source an implementer notices first, then either misses a rail or double-books another.

Minimum correction: complete C1 before dispatch and fold its exact producer/consumer/schema matrix into this brief, or make C1 the only dispatchable slice and require a new gate before C2. Replace the placeholder audit date with `2026-09-05`.

### BLOCKER 3 — The stated artifact cardinality contradicts Treasury’s transfer contract and document-per-action

[C3 line 35](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/HANDOVER-W-CASH-float-drops-treasury-2026-09-05.md:35) and acceptance (a) at [line 42](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/HANDOVER-W-CASH-float-drops-treasury-2026-09-05.md:42) require “one document + one movement” while changing both safe and drawer balances.

`TreasuryMovementService::transfer()` necessarily writes two movement rows, one out and one in ([lines 355–396](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:355)); its public contract says exactly that ([interface lines 59–85](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Shared/Contracts/Treasury/TreasuryMovementServiceInterface.php:59)). `RepositoryTransferService` creates a JE and paired legs but no justifying document ([lines 64–115](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Application/Services/RepositoryTransferService.php:64)).

Failure scenario: honoring “one movement” either changes only the drawer and leaves the safe wrong, or bypasses the single transfer port. Reusing the current service verbatim leaves the promised document nonexistent.

Minimum correction: define one stable transfer document per action, one transfer group, exactly two cross-linked movement legs, and zero or one posted JE according to whether the repositories use the same GL account. Name the document table/model/migration/service, unique source key, reversal policy, and links to both movement IDs and the JE. External payouts must use their own one-document/one-leg accounting shape rather than masquerading as transfers.

### BLOCKER 4 — C3 cannot consume both v2 and v3 through the proposed projector

The new Fiscal projector in [C3](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/HANDOVER-W-CASH-float-drops-treasury-2026-09-05.md:35) can consume v3 fiscal events, but v2 facts are ordinary domain rows/events:

- `recordOpening()` creates a row but emits no `CashDrawerOperationRecorded`; only `ShiftOpened` is emitted ([ShiftManagementService lines 101–125](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Domain/Services/ShiftManagementService.php:101)).
- `CashDrawerOperationRecorded` covers deposit/payout/refund, not opening, and lacks tenant, currency, and reason text ([event lines 15–33](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Domain/Events/CashDrawerOperationRecorded.php:15)).
- Existing event classes are immutable under rule 8.
- v3 `OPENING_FLOAT` must be the money source; booking from both it and `SESSION_OPEN.opening_float_amount` would double the float.

Failure scenario: v3 works while v2 acceptance (i) never books; or a new listener catches `ShiftOpened` without the stable `pos_cash_drawer_operations.id`, producing a second identity and a double-book during recovery.

Minimum correction: name two adapters converging on one `ShiftCashBookingService`: a Treasury fiscal projector for verified, non-training v3 events and a durable v2 source adapter/catch-up mechanism keyed on the operation row. Add versioned events rather than altering existing ones if new event payload is required. Define the cross-rail deduplication key, training behavior, terminal/shift/company provenance, and all three projection-recovery scenarios required by spec §3.2/G6.

### BLOCKER 5 — Shared-drawer and opening-float policy remains undecided

Brief [lines 22 and 25](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/HANDOVER-W-CASH-float-drops-treasury-2026-09-05.md:22) says to reconcile an existing drawer balance and avoid two floats, but gives no algorithm. Acceptance (e) merely repeats the requirement.

Failure scenario: terminals A and B share the location drawer, both open with `200.000`, and both produce valid `OPENING_FLOAT` events. Booking both transfers makes the physical drawer and Treasury differ by 200; suppressing one leaves terminal B’s shift expected cash incompatible with a per-shift repository check. Concurrent closes can then count the same physical drawer twice.

The accepted spec explicitly requires attribution across shared terminals and avoidance of duplicate floats ([spec line 426](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:426)); it does not choose the policy.

Minimum correction: select and specify one model before implementation: one open cash-bearing shift per drawer, a drawer-level session shared by terminals, or coordinated aggregation of overlapping terminal sessions. Then define the interval-start balance algorithm, zero/partial/mismatch handling, out-of-order arrival behavior, and exact tests.

### BLOCKER 6 — C5 is impossible at HEAD and silently absorbs unimplemented W7 work

[C5 line 37](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/HANDOVER-W-CASH-float-drops-treasury-2026-09-05.md:37) says to enable variance posting and add the W7 repository-comparison state. Three missing prerequisites are not owned:

1. v3 `ZReportProjection` creates a Z row but emits no `CashCountRecorded` ([lines 54–100](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Projections/ZReportProjection.php:54)). The architecture ratchet explicitly records that v3 has no cash-count producer ([ProjectorEmissionRatchetTest lines 160–187](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Architecture/ProjectorEmissionRatchetTest.php:160)). Therefore acceptance (d)/(i) cannot post a v3 variance.
2. `PosSessionReconciliationService` and `pos_session_reconciliations` do not exist at HEAD. The spec describes them as proposed ([spec lines 412–416](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:412)).
3. No algorithm defines the comparable repository interval, stable input fingerprint, late-member invalidation, inclusion of manual drawer movements exactly once, or shared-drawer aggregation. These are required by [spec lines 420–430](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:420).

Failure scenario: the global flag is enabled, legacy closes may post, v3 closes do nothing, and a current drawer balance is incorrectly compared with one terminal’s historical shift.

Minimum correction: either make W7’s result store, close manifest, and fiscal reconciliation an explicit prerequisite, or bring their exact migrations/services/API/UI/tests into C5. Explicitly assign the v3 `CashCountRecorded` producer, with idempotent dispatch and training exclusion. Define repository comparison over a stable source-membership snapshot, never over the current cached balance.

### BLOCKER 7 — The deploy sequence is unsafe under push-to-staging auto-deploy

The projector is described as mandatory whenever Treasury is enabled ([line 35](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/HANDOVER-W-CASH-float-drops-treasury-2026-09-05.md:35)), while [line 46](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/HANDOVER-W-CASH-float-drops-treasury-2026-09-05.md:46) says repositories must be configured before enabling it. No projector/capability flag exists, and Treasury is a default module for every vertical. A push must deploy the C2 schema before operators can populate it.

The accepted deployment contract requires migrations, compatible readers, configuration, and only then explicit enablement ([spec lines 432–441](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:432)). Known variance pre-enable requirements also include per-company enablement, disposition of the disabled historical window, repository mapping, API/worker flag parity, Redis/Horizon reachability, and worker-first activation ([deploy notes lines 49–154](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/tickets/2026-08-08-g3-shift-variance-gl-deploy-notes.md:49)).

Failure scenario: the auto-deploy starts booking or dead-lettering live shift events before safe mappings and opening alignment exist; later configuration does not automatically recreate skipped projection obligations.

Minimum correction: specify a disabled-by-default W-CASH capability/cutover independent of module entitlement, plus per-company/location readiness. Required order: additive schema/readers → deploy inert → configure and align → census zero unready targets → enable booking/catch-up → land W7 dependency → enable v3 count producer → enable worker-side variance policy first, API side second → verify durable outcomes. Include backup/restore, rollback that disables new authoring but preserves readers/recovery, and the exact historical-window disposition.

## Major findings

1. **Frozen replay contradicts the cited policy.** Brief [lines 26 and 42(h)](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/HANDOVER-W-CASH-float-drops-treasury-2026-09-05.md:26) applies `TreasuryMovementService:91-96` to transfers. Those lines govern only `record()` ([source](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:86)). `RepositoryTransferService` rejects either frozen repository ([lines 43–49](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Application/Services/RepositoryTransferService.php:43)), while transfer legs hardcode `recordedWhileFrozen: false`. Minimum correction: extend the paired-transfer intent/port with explicit offline replay and checkpoint policy, or change acceptance (h) to blocked. Pin atomic two-leg behavior and the alert/audit result.

2. **“Blocked” projection status does not exist.** Acceptance (f) promises a blocked projection, but [ProjectionStatus](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Fiscal/Domain/Enums/ProjectionStatus.php:7) has only pending/running/applied/dead-lettered. Gate r4 assigns this addition to W4 ([synthesis line 36](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/reviews/2026-09-05-parapharmacy-remediation-gate-r4-synthesis.md:36)). Minimum correction: declare W4 a prerequisite or include its enum/schema/job/API/retry changes, including how configuration resolution re-drives blocked rows.

3. **Cash-in/out counter-custody is semantically unresolved.** C2/C3 route all drawer operations through safe custody, but v2 `DEPOSIT` is documented as drawer→safe, while the v3 device maps its `deposit` UI to `CASH_IN`, increasing expected drawer cash ([cashDrawerApi lines 168–183](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/api/cashDrawerApi.ts:168); [ShiftExpectedCashService lines 657–714](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Services/ShiftExpectedCashService.php:657)). `PAYOUT` may be petty cash, not a safe transfer. Minimum correction: define a typed operation/reason matrix with direction, custody destination, document kind, GL counter-account, and unsupported/blocked cases. Do not infer destination from free text.

4. **Second-of-everything and red-first obligations are not named.** “Two companies, two locations…” is a fixture sentence, not the three named tests and data-meaning assertions required by convention 09 ([lines 37–50, 79–89](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/09-SECOND-OF-EVERYTHING.md:37)). Minimum correction: name test classes/methods per slice for real second-company provisioning, selected second location, and exact rerun outcome; add source-conflict, two-terminal, module-off, recovery, and PG concurrency tests.

5. **Precision and worker-context acceptance is incomplete.** Only TND happy-path values appear. There is no EUR/zero/sub-minor/overprecision/currency-conflict test or assertion that document, both legs, and JE share one normalized string. Minimum correction: require `CompanyContext::clear()`, explicit event/repository currency, `getScaleSafe(currency, 3)` at the worker boundary, strings/BCMath only, normalize once, and TND/EUR discriminating tests. This is required by [CLAUDE rule 19–20](/Users/houssamr/Projects/syneriva/apps/erp/CLAUDE.md:71).

6. **Rule-12 and W1 dependencies are incomplete.** The projector’s `requiresModule() === 'Treasury'` and module-off acceptance are good, but C2’s new write fields/endpoints have no named backend `module:Treasury` gate or inline FE gate. C4 explicitly depends on “W1 rules” without making W2→W1 a prerequisite. Minimum correction: name the gated config endpoints/fields and wrong-module 403/hidden tests; state C4 waits for W2-owned terminal authority and W1 location-scope rollout.

7. **C6’s alignment write path is undefined.** Existing `RepositoryOpeningBalanceService` rejects repositories that have already moved money ([lines 139–149](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Application/Services/RepositoryOpeningBalanceService.php:139)); the normal adjustment path books 658/758, which is not an opening-balance substitute. Minimum correction: name the alignment document, GL account treatment, permissions, evidence, idempotency, effective date, and review trail. State separately whether historical variance GL is written off or manually compensated.

8. **Acceptance order produces an intentional failure.** At line 42, the drawer receives 200, then drops 1,000, then records 3,500 of sales. In that order, the transfer port refuses the drop because cash-register outflows cannot go negative ([TreasuryMovementService lines 305–318](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:305)). Minimum correction: put sales before the drop, seed legitimate prior drawer custody, or make the expected refusal the assertion.

9. **One-surface/type flow is not executable.** C2/C4 should reuse the repository editor/detail and existing `TransferCashModal`, but the brief does not say so, and repository shapes are currently repeated as local FE interfaces, including [usePaymentRepositories](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/treasury/hooks/usePaymentRepositories.ts:7) and [RepositoryDetailPage](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/treasury/RepositoryDetailPage.tsx:27). Minimum correction: introduce/use one backend Data DTO, regenerate shared TS types, and name the existing canonical surfaces; no separate “bank deposit” catalogue or modal.

## Minor findings

- [Line 46](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/HANDOVER-W-CASH-float-drops-treasury-2026-09-05.md:46) mandates a “new queue,” but a registered Fiscal projector already runs through the existing `fiscal-projections` queue. A new queue is over-scoped unless a distinct job is justified. Reuse existing queues and keep `HorizonQueueCoverageTest`; name any genuinely new queue and its supervisor explicitly.
- Replace `2026-09-xx` and `<date>` output placeholders with exact paths for a non-interactive dispatch.
- The handback needs explicit per-slice red/green evidence, migration identifiers, rollback/cutover evidence, and candidate revision—not merely “per-slice evidence.”

## Citation verification

- Base `e3ca1ba67` exists and is an ancestor of reviewed HEAD.
- Baseline `b9a5565aa` exists; the cited operational source files are unchanged between it and HEAD.
- `ShiftExpectedCashService:27-59` exists but is only a descriptive comment and does not prove the claimed calculation by itself.
- `TreasuryMovementService:91-96` exists and accurately describes frozen policy for `record()`, but the brief incorrectly extends it to paired transfers.
- The spec §3.2 and W7 authority references are accurate.
- The owner’s RD4 ruling is accurately quoted as launch-critical, but it does not resolve the newly exposed shared-drawer or cash-operation counterparty choices.

## Rejected false positives

- No fiscal payload or sealed receipt version is required for this lane; preserving existing sealed bytes is correct.
- `ShiftExpectedCashService` already avoids double-counting `OPENING_FLOAT` in expected cash and correctly selects v2 versus v3 sources.
- Existing transfer idempotency is sound when supplied a deterministic group ID; replay validates material leg semantics and returns the original pair.
- Keeping bank settlement as a separate interval/check is correct.
- Refusing automatic historical back-booking is consistent with the spec; the defect is the missing explicit alignment and write-off mechanism.
- Reusing the existing repository and transfer surfaces is the right direction; the problem is that the brief does not bind C2/C4 to those exact surfaces and DTOs.

## What to preserve

Preserve the C1→C6 conceptual decomposition, pre-code census, stable source identity with visible conflicts, float-as-transfer rather than new money, configuration-selected custody, explicit terminal/location provenance, no `CompanyContext` in workers, module-off no-op behavior, unchanged fiscal facts, variance kill switch, shared-drawer test fixture, no automatic historical booking, and separation of cash custody from bank settlement.

## Owner decisions still required

The original ruling register is closed, but this brief exposes three additional policy decisions that materially affect implementation:

1. Concurrent shared-drawer model: prohibit concurrent cash-bearing shifts, introduce a drawer-level shared session, or aggregate coordinated terminal shifts.
2. Typed meaning of `CASH_IN`, `CASH_OUT`, v2 `DEPOSIT`, and `PAYOUT`: safe transfer versus petty-cash/expense/other counterparty and the associated GL/document shape.
3. Accounting treatment for one-time alignment of already-traded repositories and explicit disposition of historical variance-GL gaps.

VERDICT: CHANGES-REQUIRED