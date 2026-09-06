# Codex slice-plan gate r4 — W-CASH-1 rev 4 (gpt-5.6-sol, high, read-only, 2026-09-06)

Input: rev 4 at 59e8c6c82. Verbatim.

---
# WCASH-1 adversarial slice-plan gate

Audited local `dev` at `db8fb1471393c93ab27758498c45761bd05c4440`. The reviewed plan is revision 4 from commit `59e8c6c82c50cbc39dc5cc32bacbe015dd13a977`; later documentation commits added a binding precision ruling without changing production code.

Result: **1 blocker, 1 major, 1 minor.**

No files were edited, no tests were run, and no Git state was changed.

## Blocking finding

### B1 — P0 still encodes the superseded `decimal(15,3)` storage contract

The plan specifies:

- The four P0 target columns as `numeric(15,3)` at [plan:112](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-wcash-1-custody-config-transfer-doc.md:112>).
- A `_scale3` migration at [plan:121](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-wcash-1-custody-config-transfer-doc.md:121>).
- A `1.005` round trip and scale-three test names at [plan:123](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-wcash-1-custody-config-transfer-doc.md:123>).
- `repository_transfer_documents.amount` as `decimal(15,3)` at [plan:231](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-wcash-1-custody-config-transfer-doc.md:231>).
- Request validation capped at three decimal places at [plan:511](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-wcash-1-custody-config-transfer-doc.md:511>).
- The scale-three migration in deployment instructions at [plan:637](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-wcash-1-custody-config-transfer-doc.md:637>).

That now contradicts the later binding owner ruling:

- [Consolidated owner ruling A1:82](</Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:82>) requires a `decimal(N,4)` storage floor, widening the four named columns to `decimal(15,4)`, a census of every money column below scale four, and a `1.0005` round trip.
- [Precision-debt ticket:17](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/tickets/2026-09-06-gl-and-repository-balance-columns-scale-2-precision-debt.md:17>) explicitly says WCASH-1 P0 must use `(15,4)`.
- [Parallel-session handover:12](</Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/HANDOVER-parallel-hardening-session-2026-09-07.md:12>) records the same implementation consequence.

The current code confirms that this is real work rather than an already-satisfied contract:

- Repository casts are still `decimal:3` at [PaymentRepository.php:214](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Models/PaymentRepository.php:214>).
- Journal casts are still `decimal:3` at [JournalLine.php:54](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Models/JournalLine.php:54>).
- The existing widening migration targets scale three at [2026_03_04_100000_widen_monetary_columns_to_scale_three.php:27](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/2026_03_04_100000_widen_monetary_columns_to_scale_three.php:27>).

Failure scenario: dispatching the plan would install a scale-three ratchet, create a new scale-three transfer-document amount, and reject legal four-decimal input. This would directly violate the ruled storage floor and require another schema migration immediately afterward.

Minimum correction:

- Change the four named targets to `decimal(15,4)`:
  - `payment_repositories.balance`: not null, default `0`
  - `payment_repositories.last_reconciled_balance`: nullable, no default
  - `journal_lines.debit`: not null, default `0`
  - `journal_lines.credit`: not null, default `0`
- Rename the migration, tests, ratchet language, postchecks, and deployment references from scale three to scale four.
- Accept current legacy shapes at scales two and three, plus already-compliant scale-four shapes.
- Use the correct `numeric(15,4)` fit bound: absolute value below `100000000000`.
- Census every money column below scale four, but widen only the four ruled existing columns; ticket the remainder.
- Update the four corresponding model casts to `decimal:4`. Country-preset business rounding remains unchanged.
- Use `decimal(15,4)` for the new transfer-document amount and permit up to four input decimals; the service must still enforce the company country’s configured operational precision.
- Replace the round-trip fixture with raw `1.0005`.

This is a documentation/specification correction, not a new owner decision.

## Major finding

### M1 — The pre-ALTER census cannot be deployed as the single P0 push currently described

The plan correctly says the census command and DTOs must ship before the migration at [plan:132](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-wcash-1-custody-config-transfer-doc.md:132>). However, it also describes P0 as one separate non-additive push at [plan:127](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-wcash-1-custody-config-transfer-doc.md:127>) and [plan:644](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-wcash-1-custody-config-transfer-doc.md:644>).

The shared manifest says each push runs the migration entrypoint and distinguishes a tooling-only first deployment from a subsequent schema deployment at [manifest:29](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:29>) and [manifest:74](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:74>). The plan’s deployment command list at [plan:639](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-wcash-1-custody-config-transfer-doc.md:639>) also omits `treasury:census-repository-precision`.

Failure scenario: an implementer packages the new census command with the ALTER migration. The deployment entrypoint changes the schema before the command can collect the required pre-ALTER evidence.

Minimum correction:

- Split P0 explicitly into two ordered deployment packages:
  1. Census tooling only, with no schema migration.
  2. The reviewed non-additive scale-four migration.
- Supply the full shared-manifest variable matrix for both packages, including exact command, migration marker, schema census, flags, web/API behavior, device behavior, queues, collapsed pushes, environment changes, and rollback.
- Add `treasury:census-repository-precision` to the deployment command inventory.
- State unambiguously that only the second package contains the ALTER migration.

## Minor finding

### m1 — One source-identity citation is off by two lines

[Plan:136](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-wcash-1-custody-config-transfer-doc.md:136>) cites `TreasuryMovementService.php:590` for both `balance_after` and source identity.

At HEAD:

- `balance_after` is at [TreasuryMovementService.php:590](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Domain/Treasury/Services/TreasuryMovementService.php:590>).
- `source_type` and `source_id` are at [TreasuryMovementService.php:592](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Domain/Treasury/Services/TreasuryMovementService.php:592>).

Minimum correction: cite `:590, :592–593`.

## Round-3 closure table

| R3 item | Status in revision 4 | Evidence / disposition |
|---|---|---|
| M1 — exact P0 shapes/defaults | Closed against the old scale-three request, but **reopened by A1** | Plan 112–130 has complete tuples, defaults and nullability; B1 supersedes the scale. |
| M2 — pre-widen drift census | Content closed; deployment packaging remains open as M1 above | Plan 132–140 defines the census, classifications, reviewers and no-data-fabrication rule. |
| M3 — compatibility-mode migrate command | Closed | Plan 142–178 provides the full signature, connection handling, markers, exits and SQL behavior. |
| M4 — round-trip fixture | Closed for scale three, but **reopened by A1** | Plan 140 and common fixture 182 provide the old `1.005` case; must become `1.0005`. |
| M5 — wrong-shape liveness | Closed | Plan 197 includes the required failure/liveness case. |
| M6 — W1 scoped authorization prerequisite | Closed | Plan 460–467 requires authentication, scoped repository resolution and indistinguishable 404 behavior. |
| M7 — Push 3 inertness | Closed | Plan 387–389 retains the scalar path and controller; 449 tests compatibility; 638/644/649 defer and atomically gate cutover. |
| M8 — typed contract registry | Closed | Plan 296–312 plus task-specific sections give signatures, enums, DTOs and ownership. |
| m1 — translated surprise assertion | Closed | Plan 509 and 580 assert `errors.surprise.0`. |
| m2 — HEAD pin | Closed when authored; later HEAD movement is substantively handled by B1 | Production anchors did not change, but the new owner precision ruling did. |
| m3 — migration-marker grammar | Closed | Plan 640 uses `DRIFT(` and checklist 659–661 checks the exact markers. |

## Contract and state-machine verification

The principal implementation claims otherwise match HEAD:

- The current controller invokes the scalar transfer service and returns the legacy envelope.
- The service freezes repositories, optionally creates a draft journal entry, calls the treasury movement service, and removes the unused draft.
- Replay detection precedes policy rejection.
- Transfer group keys are deterministic.
- Current legs are not frozen and do not carry reversal IDs.
- GL posting remains inside the movement service.
- The movement-recorded event and compliance listener exist.
- The production tenant migration path is not the ordinary default path.
- `LocationScopeResolver` is currently HTTP-bound.
- No existing drawer-session model or historical alignment mechanism was found.

The plan’s desired state machine remains coherent: one transfer document, two legs, zero-or-one journal entry, explicit reversal linkage, event/audit emission, and deterministic replay.

## Ruled-default verification

Q11–Q13 are reflected verbatim at [plan:89](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-wcash-1-custody-config-transfer-doc.md:89>) against [owner rulings:152](</Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:152>).

The plan correctly ships none of the deferred items:

- No new device reason-code enum or mapping.
- No drawer-session model.
- No historical alignment work.

The proposed kind, outcome and provenance enums describe the transfer-document contract; they are not the deferred Q12 device reason-code enum.

## Convention and scope checks

- Convention 09 coverage is mapped through P0 and T1–T6, including a real second company, a second location and rerun behavior. The combined T1/T2 gate is explicitly explained.
- The Convention 10 matrix uses the required eight-column format and covers all mandatory rows plus the document-per-action invariant.
- Convention 11 adds only the two required glossary rows, each with one table, writer and existing surface.
- Tasks remain within the six-task ceiling, with P0 identified separately.
- Each implementation task supplies files, test file, class/method, red assertion, command/lane, reviewer gate and rollback.
- PostgreSQL-only schema/concurrency behavior is placed in the PostgreSQL lane.
- The shared manifest is referenced correctly and the main WCASH pushes provide its required variables. Only the P0 split needs completion.
- No reason-code, session, alignment, queue-worker or device behavior leaks into this slice.

## Rejected false positives

- **“Push 3 performs a partial cutover.”** Rejected. The scalar body, controller and result remain authoritative until the atomic Push 5 cutover; permanent fail-closed behavior applies after typed usage exists.
- **“The compatibility migrate command lacks operational detail.”** Rejected. Its full signature, connection rules, exit codes, markers and SQL checks are present.
- **“The plan omits defaults or nullability.”** Rejected for revision 4’s old scale-three contract. The tuples are complete; the remaining problem is that the ruled target is now scale four.
- **“The plan ships the Q12 reason-code enum.”** Rejected. Existing movement classifications and the proposed document kind/outcome/provenance contracts are not the deferred device reason-code model.
- **“System actor support introduces device behavior.”** Rejected. It models actor authority/provenance only and wires no device consumer.
- **“Current fleets are necessarily scale two.”** Rejected. The March migration already targets scale three; this is why the pre-ALTER census must accept scales two, three and four rather than assume one fleet shape.
- **“The HEAD change alone invalidates every code citation.”** Rejected. Production source anchors remain valid; the material drift is the new binding precision ruling.

## Preserve during correction

Do not regress:

- The exact Q11–Q13 wording and all three deferrals.
- The pre-ALTER census, unknown-drift classification, treasury plus stock/GL acceptance, and prohibition on fabricating or recalculating ledger data.
- Exact nullability/default preservation while updating the target scale.
- Both topology-aware migration paths and postchecks.
- Push 3 inertness and atomic Push 5 cutover.
- Human/system authority and provenance.
- One document, two legs, zero-or-one journal entry, reversal linkage and event/audit contract.
- Operation lock followed by numbering, company and repository locks.
- W1 authentication and scoped 404 behavior.
- Generated DTOs and ambient POS typing without runtime imports.
- One existing product surface and the two glossary additions.
- Convention 09 isolation/rerun coverage and PostgreSQL concurrency lane.
- Shared-manifest backup, verification and rollback requirements.

Owner decisions required: **None.** A1 and Q10–Q13 are already ruled. The remaining work is a bounded plan correction, but B1 prevents safe dispatch in its current form.

VERDICT: CHANGES-REQUIRED