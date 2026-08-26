# O-30 orphaned-shift close command — adversarial gate r1 (fiscal/POS/projection lens)

**Lane:** O-30 (orphaned OPEN shift after a forced terminal release) + C-17(viii) lock
**Branch:** `fix/o30-orphaned-shift-close-command` · worktree `.worktrees/o30-orphaned-shift`
**Range:** `4ae7c68a8..56f34a464` (10 files, +1689/−0, no migrations)
**Reviewer:** fiscal-pos-reviewer (read-only) · **Date:** 2026-08-26

## VERDICT: spec ❌ (money derivation misses the ruling's intent for the target population) + quality CHANGES-REQUESTED

Everything structural in this lane is right — the evidence-based eligibility predicate, the typed exit
codes, dry-run-by-default, the idempotency-before-proof ordering, the new (not modified) event class,
the projection lock and its ordering, and the tests. The blocker is the **money**: the one number this
command invents is derived from a table that carries no rows for the only population the command can
act on, and that number is exported into a certified NF525 JET as a balanced cash count.

---

## What I verified as CORRECT (code-grounded)

- **Eligibility predicate.** `CloseOrphanedShiftCommand.php:296-326` matches on three predicates —
  `aggregate_id` = the shift's terminal, `payload->open_shift_id` = the shift, `payload.forced === true`
  (compared in PHP after `json_decode`, :312). The payload keys are exactly what production writes
  (`DomainEventSubscriber.php:929-936`). `audit_events.payload` is `jsonb`
  (`2025_11_30_140000_create_audit_events_table.php:20`), so the `->` predicate is valid on PG.
  Refusals: `INVALID` (:150-164), `EXIT_SHIFT_NOT_FOUND` (:168-172), `EXIT_NOT_ORPHANED` (:194-206),
  `EXIT_CLOSED_BY_UNKNOWN` (:208-216).
- **Idempotency.** The already-CLOSED probe (:179-188) runs BEFORE the orphan proof — correct, because
  the authorising audit row survives the close. The write re-checks status under
  `Shift … lockForUpdate()` inside `DB::transaction` (:398-404).
- **Dry-run default + the `tenants:run` string-flag trap.** `applyRequested()` (:483-496) reads raw
  input and uses `FILTER_VALIDATE_BOOLEAN` only for the string branch; both directions pinned
  (`CloseOrphanedShiftCommandTest.php:372-400`).
- **Rule 19 (console context).** Scale from the entity currency: `getScale((string) $terminal->company->currency)`
  (:222-223); intermediates at `scale + 1`, rounded once through `CurrencyScale::bcformatStrict`
  (:352-370). No float anywhere. `CashDrawerService::calculateExpectedCash()` correctly NOT reused —
  its `scale()` is a bare no-arg `getScale()` (`CashDrawerService.php:48-51`) and would throw here.
- **Rule 8.** `OrphanedShiftClosedByOperator` is a NEW class; no existing Event class is renamed,
  restructured or deleted anywhere in the diff. It carries `releaseAuditEventId` (the source
  `terminal.released` row) and is wired at `DomainEventSubscriber.php:1233`. `ShiftClosed` is
  deliberately not forged, pinned by `CloseOrphanedShiftCommandTest.php:345-364`.
- **Sign convention** matches the canonical service (SALE adds; REFUND/DEPOSIT/PAYOUT subtract) —
  `CloseOrphanedShiftCommand.php:363-367` vs `CashDrawerService.php:396-409`. Excluding `OPENING`
  while using `opening_cash` avoids the legacy double-count (pinned :289-305).
- **Constraints.** `expected == counted`, `variance = '0.000'` satisfies `pos_shifts_variance_calc`
  and `closed_at`/`closed_by` satisfy `pos_shifts_closed_logic`
  (`2026_01_08_190641_create_pos_shifts_table.php:77-87`); columns are `DECIMAL(16,4)` after
  `2026_04_25_000002_widen_pos_shifts_monetary_columns_to_scale_4.php:17-23`.
- **The projection change is safe.** `ZSessionLifecycleProjection.php:174` is a pure
  `SELECT … FOR UPDATE` with no write, inside the caller's existing transaction (`:89-121`), before
  the one-open decision (`:176-182`). It is replay-safe and idempotent (the two pre-checks at
  `:168-170` and `:176-182` are unchanged), it needs no `CompanyContext` (neither `Terminal` nor
  `Shift` declares a global scope), and it touches no fiscal payload, canonical bytes or hash —
  `apply()` writes only `z_session_events` + `pos_shifts`.
- **Deadlock analysis: clean.** Lock order is `pos_terminals` → `pos_shifts` at all three sites —
  `TerminalController::release()` :553-579, `ZSessionLifecycleProjection` :174 → :202-212,
  `ShiftManagementService::openShift()` :83 → insert. `ReceiptCreationService.php:145-160` takes the
  same order; `ZReportProjection.php:77-80` locks `pos_z_reports` only;
  `VirtualAdminFiscalEventService.php:63-69` locks `pos_terminals` only; no surveyed site locks
  `pos_shifts` before `pos_terminals`. The command itself locks only `pos_shifts` and never
  `pos_terminals`, so it cannot form a cycle with `release()` (whose shift probe is an unlocked read).
- **Replacement-device proof is a real test.** `PosShiftProjectionTest` (diff :241-405) pins the
  defect and the remedy end-to-end, clears `CompanyContext` before every `apply()` (rule 20), and the
  lock-ordering pins are PG-only with an honest SQLite skip. `CloseOrphanedShiftCommandTest`
  force-releases through the real endpoint (`:468-488`).
- **No new named queues**, no `onQueue(...)` in the diff — horizon coverage unaffected.

---

## Findings

### [CRITICAL] `apps/api/app/Modules/POS/Commands/CloseOrphanedShiftCommand.php:348-371` — `expectedCash()` reads the wrong table for the v3 orphan population, and the wrong number is exported into the NF525 JET as a balanced cash count

`expectedCash()` sums `pos_cash_drawer_operations`. For a **v3 device-authored shift — the only
population this command can act on** — that table is essentially empty of the shift's real cash
movements:

- device cash movements land in `z_session_events`, not in `pos_cash_drawer_operations`. That is why
  `Nf525DataProvider.php:263-272` reads `ZSessionEvent` (`OPENING_FLOAT`, `CASH_IN`, `CASH_OUT`,
  `SAFE_DROP`, `CASH_CORRECTION`) as the *canonical* cash-drawer operations, mapping
  `payload.amount` / `payload.movement_type` (`:1343-1357`), alongside the legacy CDO rows
  (`:255-261`, restricted to `DEPOSIT|PAYOUT|REFUND`);
- there is **no production writer of a `SALE` cash-drawer operation at all**:
  `CashDrawerService::recordSale()` (`:324-337`) has zero callers in `app/`; only `recordRefund()` is
  called (`ReceiptReturnService.php:979`, `ExchangeService.php:290`).

So the value written is `opening_cash` minus whatever server-side outflows happen to exist — it can
never include the day's cash takings or the device's own drops. That number is not private to the
row: `Nf525DataProvider::mapShift()` (`:1359-1372`) feeds it to
`Nf525XmlBuilder::addTechnicalEvents()` (`:433-443`), which emits a `FERMETURE_CAISSE` carrying
`EspecesAttendues` = this figure, `EspecesReelles` = the same, `Ecart` = 0, and `CaissierId` = the
**original cashier**. The JET therefore asserts to a French auditor that the cashier counted the
drawer and it balanced exactly — at a figure that omits the shift's cash sales. It is also read into
an accounting report (`CashRegisterReportService.php:49`, `:80`).

The lane's tests cannot catch this: the only movement test
(`CloseOrphanedShiftCommandTest.php:260-282`) fabricates CDO rows production never writes for v3, and
is arranged to net back to exactly the float.

**Fix before merge:** derive the movement set from `z_session_events` for v3 shifts (union the legacy
CDO rows for v2), or — if the owner's "float + booked cash movements" was meant literally as
float-only — take that back to the owner as part of O-30(c) and make the column semantics explicit,
because the JET consequence is not a labelling problem, it is a **numbers** problem. Pin whichever
answer with a v3 fixture (`z_session_events` CASH_IN/SAFE_DROP), not with hand-written CDO rows.

### [CRITICAL] `CloseOrphanedShiftCommand.php:348-371` + `2026_01_08_190641_create_pos_shifts_table.php:72-76` — a negative expected cash violates `pos_shifts_positive_amounts` and dies as an uncaught 23514

Because `SALE` is never booked (above) while `REFUND`/`DEPOSIT`/`PAYOUT` are
(`CashDrawerController.php:84`, `:153`, `ReceiptReturnService.php:979`), `expectedCash()` is
systematically ≤ the float and goes negative on a perfectly ordinary shift — float 100, a mid-shift
safe drop of 300 out of the day's cash sales. `pos_shifts_positive_amounts` requires
`expected_cash >= 0 AND actual_cash >= 0`, so the `write()` UPDATE (`:422-430`) raises SQLSTATE 23514
with no catch: stack trace, exit 1, no typed code, the orphan stays OPEN and the terminal stays
unusable — i.e. the sole remedy fails for exactly the incident it exists for. No test covers a
net-negative movement set.

**Fix:** handle the case explicitly (typed exit + a message naming the movements), and pin it.

### [IMPORTANT] `ZSessionLifecycleProjection.php:247-249` vs the report/docblock claim — a later device `SESSION_CLOSE` does NOT win; the device's counted drawer is silently dropped from `pos_shifts`

The command docblock (`:373-388`) and the implementer report assert that a merely-offline device
which reconnects "closes this row through the projection with a real counted drawer, and that close
must win". It does not. `projectPosShiftClose()` returns early when the shift is already CLOSED
(`:247-249`), so after an administrative close the device's authoritative `expected_cash`,
`actual_cash` and `variance` are discarded from the projection, leaving the synthetic zero-variance
pair in place — and the JET closure above is built from that pair. The `write()` re-check
(`:400-404`) only covers the microseconds inside the command's own transaction. The fiscal chain is
intact (`z_session_events` / `fiscal_events` keep the device close), so this is projection divergence,
not chain loss — but the claim in the docblock is false and there is no alert or reconciliation path.

**Fix:** correct the docblock and the runbook, and either warn on a post-close `SESSION_CLOSE` or file
the reconciliation as a LEDGER residual.

### [IMPORTANT] `apps/api/app/Modules/POS/Presentation/Controllers/TerminalController.php:541-551` — stale comment now asserts a race this lane just closed is still open

The `release()` docblock still reads: *"Neither shift-open path locks the terminal row —
`ShiftManagementService::openShift()` opens a transaction but touches only `pos_shifts`, and the
device-authoritative path (`ZSessionLifecycleProjection::projectPosShiftOpen()`) is a projection …
Filed as a residual, not taken here."* Both statements are now false
(`ShiftManagementService.php:83`, `ZSessionLifecycleProjection.php:174`). This is the first file an
incident responder reads for C-17(viii); leaving it will cause someone to re-open a closed race or,
worse, to remove one of the new locks as redundant.

### [IMPORTANT] `CloseOrphanedShiftCommand.php:246-259` + `DomainEventSubscriber.php:1146-1158` — the audit provenance is best-effort and can be lost silently, with exit 0

`persistEvent()` catches **every** `Throwable` and only logs (outside impersonation). The command
dispatches the event AFTER the transaction commits and never checks that the row landed, then prints
success and returns `SUCCESS`. A re-run cannot repair it — the idempotency probe (`:179-188`) returns
before the dispatch. Since the design states the provenance survives in exactly two places, and one of
them is best-effort, the command should verify the `shift.orphan_closed` row exists after dispatch and
warn / exit non-zero if not. (`pos_shifts.notes` still carries the reason and the release id, which is
what keeps this Important rather than Critical.)

### [IMPORTANT] `CloseOrphanedShiftCommand.php:190-193` — a soft-deleted (archived) terminal crashes the command instead of producing a typed exit

`Terminal` uses `SoftDeletes`; `archive()` (`TerminalController.php:192-211`) only refuses while an
OPEN shift exists, so the §1c late-sync population (a `SESSION_OPEN` synced after the release) can
project an OPEN shift onto an already-archived terminal. `$shift->terminal` is then `null`, and
`authorisingRelease(Shift, Terminal)` gets `null` → TypeError, exit 1, stack trace — instead of the
designed exit 4. Same nuance in the projection: `Terminal::query()->whereKey(...)` excludes trashed
rows, so the C-17(viii) lock silently degrades to no lock on an archived terminal.

**Fix:** null-guard `$shift->terminal` with a typed exit; consider `withTrashed()` for the lock probe.

### [MINOR] `DomainEventSubscriber.php:1125-1126` — `audit_events.user_id` is NULL for an orphan close

There is no `Auth::id()` in a console run, so the register's `user_id` column is empty and the
accountable human lives only in `payload.closed_by`. Worth one line in runbook §4 so a future auditor
querying by `user_id` does not conclude the close was anonymous.

### [MINOR] `CloseOrphanedShiftCommand.php:208-216` — `--closed-by` is existence-checked only

Not checked for `pos.manage_terminals`, nor for membership of the terminal's company
(`UserCompanyMembership`). Deliberate per the report; a candidate for the owner sheet alongside
O-30(b).

### [MINOR] `docs/handoff/RUNBOOK-orphaned-shift.md:69` — `(a.payload->>'open_shift_id')::uuid` will raise 22P02 on any non-uuid payload value

Query §1a (the LEDGER O-30(a) text) is correct as written. §1b's cast is the operator's day-to-day
query; a malformed or legacy payload value aborts the whole result set. Prefer a text-side join or a
`~ '^[0-9a-f-]{8}-'` guard. §1c is correct but also lists every OPEN shift on any never-claimed
terminal — say so.

---

## Answers to the gate's explicit questions

- **Eligibility predicate / typed exits / idempotency / dry-run:** verified correct (see above). The
  refusal set is genuinely narrow and the "evidence, not permission" framing holds.
- **Variance/expected pair vs `pos_shifts_variance_calc` on PG:** satisfied by construction. But
  `pos_shifts_positive_amounts` is NOT satisfied for a realistic input (finding 2), and the expected
  derivation is wrong for the target population (finding 1). Scale/currency handling itself is
  rule-19 clean.
- **`OrphanedShiftClosedByOperator` is NEW, carries the source release id:** confirmed; rule 8 intact.
- **Projection change:** replay-safe, idempotent, no `CompanyContext` dependency, no fiscal
  payload/canonical-bytes/hash impact, and the lock order matches `release()` — no deadlock cycle
  found across the surveyed POS/Fiscal lock sites.
- **Replacement device after the close:** the SESSION_OPEN projection is proven by a real test with
  `CompanyContext` cleared; SESSION_CLOSE / Z for the replacement are not separately exercised in this
  lane (the FK path is unblocked once the shift row exists, but that is inference, not a test).
- **NF525 JET consequence:** the missing "closed administratively" marker is acceptable pending
  O-30(c) *as a labelling gap*. What is **not** acceptable as it stands is that the same export
  asserts `Ecart = 0` against `EspecesAttendues` that omit the shift's cash sales, attributed to the
  original cashier (finding 1). That escalation belongs on the O-30(c) owner row, and the
  certification / JET provider should be told **before** the first export containing an
  administratively closed shift — not after.
- **Runbook:** §1a matches the LEDGER O-30(a) SQL verbatim and is correct; §1b/§1c carry the minor
  caveats above; the accountable-role split and cadence are sound.

## One line to fix before merge

Fix the expected-cash derivation (read the v3 shift's `z_session_events` movements, handle the
negative case with a typed exit) and correct the two false claims in prose — `release()`'s stale
"residual not taken" docblock and the "a later device close must win" assertion.
