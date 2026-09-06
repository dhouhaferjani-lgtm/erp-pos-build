# Codex slice-plan gate r5 — W-CASH-1 rev 5 (gpt-5.6-sol, high, read-only, 2026-09-06)

Input: rev 5 at a1e8450f5. Verbatim.

---
# W-CASH-1 adversarial slice-plan gate — rev 5

Audited current HEAD `a1e8450f59ab271fde69668faf45a5312cf66f5f`. Read-only: no edits, tests, or Git writes.

Result: **1 BLOCKER, 0 MAJOR, 0 MINOR.**

## BLOCKER

### B1 — Four-decimal transfer tests exceed the unchanged movement-ledger scale

**Plan lines:** [138](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:138>), [176](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:176>), [483](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:483>).

Rev 5 requires `1.0005` to round-trip through `TreasuryMovementService::record()` and documented transfers, including both movement legs and `balance_after`. But P0 deliberately widens only the four owner-ruled columns; [plan line 168](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:168>) sends every other sub-scale-four money column to a follow-up ticket.

At HEAD:

- `repository_movements.amount` is `decimal(15,3)` at [migration:20](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_07_08_100100_create_repository_movements_table.php:20>).
- `repository_movements.balance_after` is `decimal(15,3)` at [migration:22](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_07_08_100100_create_repository_movements_table.php:22>).
- Their Eloquent casts remain scale three at [RepositoryMovement.php:53](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Domain/RepositoryMovement.php:53>).
- The write port inserts the amount and computed balance directly into those columns at [TreasuryMovementService.php:588](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:588>) and [590](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:590>).
- Replay compares the rounded stored amount against the four-decimal intent at operational scale at [TreasuryMovementService.php:682](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:682>) and [763](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:763>).

**Failure scenario:** a synthetic scale-four company submits `1.0005`. PostgreSQL stores the movement amount/balance as scale three while the P0-widened repository balance, journal lines, new document and in-memory event can retain four decimals. The movement ledger and repository/GL/document evidence disagree. An identical retry can then raise `IdempotencyConflictException` rather than return `AlreadyRecorded`. The literal red-to-green assertions in P0 and T3 cannot pass under the declared migrations.

**Minimum correction:** retain A1’s four-column P0 unchanged, but replace the operational `1.0005` movement/transfer fixtures at lines 138, 176 and 483 with a real country-valid value of at most three decimals, such as TND `1.005`. Keep `1.0005` only for raw round-trip proof of the four P0 target columns. Explicitly defer four-decimal operational transfers until a separately accepted follow-up widens `repository_movements.amount`, `balance_after`, their casts and associated guards. If four-decimal transfers must ship now, that widening must become a named prerequisite with its own census, immutable-ledger-safe migration, tests, deployment and rollback contract—not an undeclared addition to the owner-bounded four-column P0.

This is an engineering correction; no new owner ruling is required.

## MAJOR

None.

## MINOR

None.

## R4 closure and regression audit

- R4 B1 is otherwise closed: the four ruled P0 columns and new document amount are `(15,4)`; migration names, bounds, request ceiling and raw fixture use scale four/`1.0005`.
- R4 M1 is closed: P0-a is census-only and must complete before P0-b’s ALTER; both have manifest variables, commands, evidence, backup and rollback.
- R4 m1 is closed: `balance_after` cites line 590 and source identity cites lines 592–593.
- Q11–Q13 at plan lines 100–102 match owner-ruling lines 152–154 verbatim.
- No Q12 reason-code enum/mapping, drawer-session model, historical alignment, shift booking, v2/v3 adapter, W7 or variance activation ships.
- Convention 10 has the required eight columns and all mandatory rows.
- Convention 11 reconciles both new nouns with the existing Repository surface and assigns one table/writer/surface to each.
- Convention 09 is mapped per task to real company creation, a second `pos_enabled` location and explicit rerun outcomes.
- P0 and T1–T6 structurally provide exact files, signatures, PHP enums, test class/method/assertion/command/lane, reviewer gates and rollback.
- The shared-manifest reference, required variable table, verbatim checklist, web fingerprint, topology gates and per-push rollback are present.
- All 105 explicit full `path:line` references resolve to existing, in-range HEAD lines. Production and governed contract files have no changes between the plan’s `93e9ab1` evidence baseline and current HEAD; the newer commits are documentation-only. The blocker is an omitted interaction with an unchanged scale-three table, not path drift.

## Rejected false positives

- **“P0 still uses scale three.”** Rejected. Rev 5 consistently applies A1 to the four ruled columns and new document storage.
- **“P0 census and ALTER still share one deployment.”** Rejected. P0-a and P0-b are explicitly separate and ordered.
- **“All money columns must be silently added to P0.”** Rejected. A1 limits P0 widening to four existing columns; other findings belong in the census-owned follow-up.
- **“Document/provenance enums implement Q12.”** Rejected. They classify document state and actor provenance, not device cash-movement reasons.
- **“The stale evidence SHA invalidates all citations.”** Rejected. Governed source files did not change between that SHA and HEAD.
- **“Push 3 activates a partial replacement.”** Rejected. The scalar path remains authoritative until the drained atomic Push-5 cutover.

## Preserve during correction

- Exact Q11–Q13 wording and all deferrals.
- The four-column `(15,4)` P0 contract, `1.0005` raw storage proof and country-resolved operational precision.
- P0-a/P0-b separation, per-tenant census, unknown-history classification and no fabricated financial reconstruction.
- Push-3 inertness and atomic Push-5 removal of the documentless writer.
- One document, two legs, zero-or-one JE, append-only reversal and audit linkage.
- Human/system authority union and scoped replay ordering.
- W1 hard prerequisite and indistinguishable scoped 404 behavior.
- Generated DTO migration, one existing repository surface and Convention 09 coverage.
- Shared-manifest topology, backup, verification and forward-only rollback requirements.

Owner decisions required: **None.**

VERDICT: CHANGES-REQUIRED