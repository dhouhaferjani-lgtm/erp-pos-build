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

---

## r2 scoped re-review

**Range:** `56f34a464..3136c89bc` (4 commits, 19 files, +1720/−214) · **Date:** 2026-08-26 · read-only

### VERDICT: spec ❌ + quality CHANGES-REQUESTED — every r1 item is ADDRESSED, but the new derivation introduces one Critical of its own

### r1 disposition

| r1 finding | Status | Evidence |
|---|---|---|
| **[CRITICAL] expected cash read `pos_cash_drawer_operations`** | **ADDRESSED** | `ShiftExpectedCashService.php:189-228` — v3 arm = `opening_cash` + `cashTenderedNetOfChange()` (`:157-171`) + `zSessionMovements()` (`:302-336`); v2 arm keeps the CDO sum (`:343-363`). Source chosen by `fiscal_schema_version` (`:204`), typed by `ShiftCashMovementSource`. Command no longer computes anything (`CloseOrphanedShiftCommand.php:301`). Pinned by `CloseOrphanedShiftCommandTest` v3 case (asserts 120 **and** `≠ 100`) and the v3-ignores-CDO case. |
| **[CRITICAL] negative expected cash → uncaught 23514** | **ADDRESSED** | `EXIT_EXPECTED_CASH_NEGATIVE = 6` (`:161`), checked before any write (`:311-324`) via `ShiftExpectedCashBreakdown::isNegative()` (`bccomp` at scale). Pinned with real data (float 100, `CASH_OUT` 300): exit 6, shift still OPEN, zero audit rows. |
| **[IMPORTANT] late device `SESSION_CLOSE` does not win** | **ADDRESSED** | `ZSessionLifecycleProjection.php:256-274` now calls `OrphanedShiftDeviceCloseReconciler::reconcile()`; the reconciler swaps expected/counted/variance/severity, appends a notes marker, emits `OrphanedShiftDeviceCloseApplied` with both sides. Device-closed shifts still no-op (`:73-79`). Three tests. Field-for-field faithful to `projectPosShiftClose()`'s own path (same `generated_at_device` fallback, same `VarianceSeverity::tryFrom`, same `bcsub(...,4)` at column scale). |
| **[IMPORTANT] stale `release()` docblock** | **ADDRESSED** | `TerminalController.php:541-563` — names all three lock sites, the shared `pos_terminals → pos_shifts` order, an explicit "do not remove either of those locks as redundant", and the surviving late-sync residual. |
| **[IMPORTANT] audit provenance best-effort, exit 0** | **ADDRESSED** | Event dispatched **inside** the close transaction and the row read back (`CloseOrphanedShiftCommand.php:496-520`); absent row → `OrphanCloseProvenanceLostException` → rollback → exit 7. `AuditEvent` declares no `$connection`, so it shares the tenant transaction — the rollback is real. Pinned by `Event::fake`: exit 7, status OPEN, `closed_at` NULL, no audit row. |
| **[IMPORTANT] archived terminal → TypeError** | **ADDRESSED** | `CloseOrphanedShiftCommand.php:250-261` (`->terminal()->withTrashed()->first()`, typed exit 4 on a genuinely absent row) and `ZSessionLifecycleProjection.php:183` (`withTrashed()` on the C-17(viii) lock probe). Pinned. |
| [MINOR] `audit_events.user_id` NULL | ADDRESSED | Runbook §4 block. |
| [MINOR] `--closed-by` existence-only | ADDRESSED as ruled | Left deliberate, stated in the runbook, owner row. |
| [MINOR] §1b uuid cast | ADDRESSED | `s.id::text = a.payload->>'open_shift_id'`; §1c caveat added. |

### Is the server mirror faithful to the device Z? — term by term

Device formula: `openingCash + cashSales + drawerNet + cashAccountCollections − cashRefundImpact` (`apps/pos/src/lib/offline/zReportService.ts:277-289`).

| Term | Server | Faithful? |
|---|---|---|
| `openingCash` | `pos_shifts.opening_cash`, `OPENING_FLOAT` excluded (`:325-329`) | ✅ |
| `cashSales` = Σ cash tendered − `change_due` per receipt, refunds subtracted | `:157-171` + `:262-286` (`MAX(change_due)` per receipt, non-return only, CASH-line-joined); returns as `-ABS` | ✅ — and `pos_receipt_payments.amount` really is the **tendered** amount (`PosCoreReceiptProjection.php:1481-1488`), `posted_at = event_time_device` (`:306`, `:391`), `fiscal_status = Fiscalized` (`:406`), so the fixture shape matches production even though the test hand-writes the rows |
| `drawerNet` (`deposit +`, `payout −`) | `CASH_IN '+'`, `CASH_OUT '-'` (`:80-84`), matching `cashDrawerApi.ts:177` | ✅ |
| `cashAccountCollections` | **absent** | ❌ see N-2 |
| `cashRefundImpact` (`local_refund_records`) | **absent** (device-local table) | ⚠️ legacy-only, disjoint from v4 returns; acceptable |
| — | `SAFE_DROP '-'` (server-only) | ⚠️ see N-5 |
| shift window | **terminal + time, unbounded to `now()`** | ❌ see N-1 |

Scale handling is rule-19 clean throughout: `getScale($currencyCode)` with the currency passed explicitly (`:195`, required by the signature docblock), intermediates at `scale + 1` (`:200`), rounded once through `bcformatStrict` (`:219-222`). No float. Placement in `POS/Application/Services` is right — it is consumed by a POS console command and `ReportGenerationService`, not by a projector; the `ReportGenerationService` extraction is byte-faithful to the query it replaced.

### New findings in the fix diff

#### [CRITICAL] `ShiftExpectedCashService.php:245` + `:196` + `CloseOrphanedShiftCommand.php:301` — the receipt window runs to `now()`, so the REPLACEMENT till's cash sales are added to the orphan's expected cash

`shiftReceiptPaymentsQuery()` bounds receipts by `terminal_id` + `posted_at BETWEEN $shift->opened_at AND $until`, and the command calls `breakdown()` with no `$until`, so `$until = Carbon::now()` (`:196`). `pos_receipts` carries **no `shift_id`** (confirmed: no shift column in any `pos_receipts` migration) and `PosCoreReceiptProjection` has **zero** references to `pos_shifts`/`shift_id`/`ShiftStatus` — so a replacement device's `SALE_RECEIPT` events project normally even while its `SESSION_OPEN` is being dropped. That is precisely this lane's own incident narrative (`CloseOrphanedShiftCommand.php:34-39`): the replacement till sells while the orphan blocks its shift.

Timeline: orphan opens Mon 08:00 → device stolen 12:00 → forced release 13:00 → replacement claims the terminal and sells all week → operator runs the command at month-end (the cadence the runbook itself prescribes, §4). Every replacement receipt from Mon 13:00 onward falls inside the orphan's window and inflates `expected_cash` — which `Nf525DataProvider::mapShift()` exports as `EspecesAttendues` with `Ecart = 0` against the **original** cashier. r1's defect understated the figure; this one overstates it by another till's takings, in the same certified export.

The fix is cheap and the data is already in hand: the command holds `$release['occurred_at']` (`:263`, `:410-414`) — pass it as `$until`. After a forced release the orphan's device no longer holds the terminal, and `posted_at` is device time (`PosCoreReceiptProjection.php:306`), so the dead device's late-synced receipts still fall inside. Pin it: a receipt posted after the release must not enter the orphan's figure. (`ReportGenerationService`'s own `now()` is correct there — it closes a live shift.)

#### [IMPORTANT] `RUNBOOK-orphaned-shift.md` residual + `ShiftExpectedCashService.php:189-228` — the account-collections gap is stated as impossible, but the data is present; and the same commit fails closed on a movement that cannot happen while under-reporting one that can

The runbook says `pos_account_payment_receipts` "carries no `shift_id` and no tender breakdown, so there is no reliable per-shift cash term to add". The **column** has none; the **row** does. `AccountPaymentReceiptProjection.php:71` stores `payload_snapshot => $payload->toArray()`, and `AccountPaymentPayload::toArray()` emits `'shift_id'` (`:111`) and `'payment'` (`:106`, carrying `method_code` / `amount`) — written by the device at `accountPaymentService.ts:245,258` and integrity-joinable through the row's `fiscal_event_id`. So the term is derivable today with one query.

Severity for tenant #1 (account charges in use): a cash collection against a customer account physically enters the drawer and the device folds it in (`zReportService.ts:272-275`); the server figure is short by exactly that amount, and the shortfall is exported as a balanced count. It is smaller than r1's Critical (zero on shifts that took no cash collections) but it is the same defect class.

What makes it merge-blocking rather than ledgerable is the **inconsistency inside this one commit**: `CASH_CORRECTION` gets a hard refusal (exit 8) for a movement with no authoring path, while account collections — which have a live, shipped authoring path — are silently omitted with a runbook sentence. Pick one standard. Either add the term (preferred; the data is there) or refuse the close with a typed exit when the shift has any cash `ACCOUNT_PAYMENT` row. Correct the runbook sentence either way.

#### [IMPORTANT] `OrphanedShiftDeviceCloseReconciler.php:34-50` — the placement is defensible, but it lands in a blind spot `ProjectorEmissionRatchetTest` had already named, and the baseline is left asserting something now false

Keeping the emission out of the projector to avoid a false ES-03/ES-02/ES-05 closure is the right call — I agree with the reasoning. But the ratchet's own docblock pre-declares this exact shape: *"**Over-report (reads as non-emitting when it emits).** A projector that emits by delegating to a collaborator service reads as silent here … widen the detection deliberately rather than silently dropping the entry."* Applying a `SESSION_CLOSE` now **does** emit a domain event; the file-scan says otherwise, so the baseline line for `ZSessionLifecycleProjection` is true only about ES-03/02/05 and false about the projector as a whole — and this lane establishes a citable precedent for moving `event(new …)` one call deep to keep the ratchet green.

Minimum fix: annotate the baseline entry (or the ratchet docblock) with "emits `OrphanedShiftDeviceCloseApplied` via `OrphanedShiftDeviceCloseReconciler` — O-30; the ES rows this line names are still open". Do not delete the line.

#### [IMPORTANT] `PosShiftProjectionTest::test_a_late_device_session_close_is_replay_safe` — asserts the projector's guard, not the reconciler's, while its docblock claims the opposite

The test applies **the same** `$closeEvent` twice. `ZSessionLifecycleProjection::apply()` short-circuits at `:65` (`ZSessionEvent::where('fiscal_event_id', $event->id)->exists()`) before reaching `projectPosShiftClose()`, so `OrphanedShiftDeviceCloseReconciler::deviceCloseAlreadyApplied()` (`:192-200`) is never reached on the second call. The docblock says "Replay-safe on its own guard, not merely on the projector's" — it is not established. Use a **second, distinct** fiscal event id for the same shift (the realistic shape: a re-authored close after a device restore), or call the reconciler directly.

#### [MINOR] `ShiftExpectedCashService.php:83` — `SAFE_DROP` is signed `'-'` server-side but the device Z never sees it

The device folds only `local_cash_drawer_ops`, written exclusively as `deposit`/`payout` → `CASH_IN`/`CASH_OUT` (`cashDrawerApi.ts:177`). `SAFE_DROP` has no device authoring path today, so the sign is unexercised; the day one appears, the device's expected cash and the server mirror will differ by its full value. Worth one line in the class docblock (which currently justifies the sign as "matching the v2 side", not as matching the device).

#### [MINOR] `ShiftExpectedCashService.php:308-309` — magic strings where enums exist (rule 9)

`'verified'` (vs `IntegrityStatus::Verified->value`), `'z_session'`, and the four event-type literals. They are correct today (`IntegrityStatus.php:9`) but drift silently.

#### [MINOR] `OrphanedShiftDeviceCloseReconciler.php:159-172` — the swap's own audit row is not read back

The reconciler's replay guard is the `shift.orphan_device_close_applied` row, but `persistEvent()` still swallows every `Throwable` — the very asymmetry r1 raised and the command now closes. Impact is small (a second swap rewrites the same device figures and duplicates a notes marker), so this is a note, not a blocker.

#### [MINOR] `ShiftExpectedCashService.php:41-42` — "one derivation, one place" overclaims

Only `expectedPerPaymentMethod()` is genuinely shared. `ReportGenerationService:232` still takes `expected_cash` from `CashDrawerService::calculateExpectedCash()`. That is harmless **only** because `generateZReport()` refuses v3 at `:196` (`assertServerReportAuthoringAllowed`) — i.e. the v3 arm of the new service has no second consumer to agree with. Say so, so nobody later removes that refusal believing the figures are unified.

### Rulings requested

**`CASH_CORRECTION` → exit 8: ACCEPT as fail-closed; do NOT resolve the convention now.** Verified unreachable: the only device writer of a Z cash movement is `cashDrawerApi.ts:177`, which emits `CASH_IN`/`CASH_OUT` only; `CASH_CORRECTION` exists solely in type unions, payload registries and validators (`FiscalEventEngine.ts:3884`, `FiscalPayloadConstraintValidator.php:835`, `FiscalEventCoveragePolicy.php:57`) with no authoring call path in either app. So exit 8 is unreachable in production today and costs nothing operationally, while the refusal is exactly right if the type ever ships — its direction genuinely lives in the amount's sign, and guessing would put a short figure into a JET as a balanced count. Ledger the convention against whatever lane introduces the authoring path; blocking O-30 on it would be inventing a semantic for an event nobody has written.

**Account-collection exclusion: Important, and must be CLOSED before merge — not ledgered.** Not because the magnitude is large, but because (a) the stated justification is factually wrong (`payload_snapshot` carries `shift_id` and `payment.method_code`), (b) tenant #1 takes account charges, so the collection leg is live, and (c) the same commit refuses a movement that cannot occur while silently under-reporting one that does. Add the term or refuse the close; either is a small change.

### One line to fix before merge

Bound the receipt window at the authorising release's `occurred_at` (or the orphan's expected cash absorbs the replacement till's takings into the JET), and give account collections the same fail-closed treatment `CASH_CORRECTION` already gets.

## r3 scoped re-review

**Range:** `3136c89bc..a5c62ead4` (3 commits, 8 files, +639/−78) · **Date:** 2026-08-26 · read-only

### VERDICT: spec ✅ + quality APPROVED — all five r2 open items ADDRESSED, no new Critical/Important found

### r2 disposition

| r2 open item | Status | Evidence |
|---|---|---|
| **[CRITICAL] receipt window ran to `now()`** | **ADDRESSED** | `CloseOrphanedShiftCommand.php:323` — `$window = Carbon::parse($release['occurred_at'])`, passed to `$this->expectedCashService->breakdown($shift, $terminal, $currency, $window)` (`:325`). `ShiftExpectedCashService::breakdown()` (`:233-250`) threads the same `$until` into **both** `cashTenderedNetOfChange($shift, $until, $working)` (receipts, `:251`) and the new `cashAccountCollections($shift, $terminal, $until, $working)` (`:253`), which itself bounds `fiscal_events.event_time_device BETWEEN [$shift->opened_at, $until]` (`ShiftExpectedCashService.php:441`). Window is genuinely `[opened_at, release.occurred_at]` on both terms, not just receipts. Test `test_a_receipt_posted_after_the_release_is_not_swept_into_the_orphans_expected_cash` (`CloseOrphanedShiftCommandTest.php:337-361`) posts a 50 sale before the release and a 400 sale six hours after it on the **same terminal**, asserts `expected_cash == 150` **and** explicitly `bccomp(…, '550', 4) === -1` — the not-550 assertion the gate asked for is real, not decorative. |
| **[IMPORTANT] account collections: CASH-only, training-skipped, foreign-shift-skipped, missing shift_id → exit 9** | **ADDRESSED** | `cashAccountCollections()` (`ShiftExpectedCashService.php:409-460`): training rows skipped (`$snapshot['training_flag'] === true → continue`, `:426-428`); rows with no/blank `shift_id` throw `UnattributableAccountCollectionException` (`:430-433`); rows for a **different** shift are ignored (`:435-437`); non-CASH `method_code` ignored (`:449-451`); only CASH added (`:453`). Wired to a typed exit: `CloseOrphanedShiftCommand.php:305-308` catches the exception → `EXIT_COLLECTION_UNATTRIBUTABLE = 9` (`:176-181`), returned **before** any write (the `breakdown()` call happens before the transaction, `:301-311`). Tests: cash-raises (`:379-397`, 100→175), CARD-and-foreign-shift-both-ignored (`:403-421`, stays 100), no-shift_id refuses with exit 9 / shift still OPEN / zero audit rows (`:426-441`). The one gap: **no dedicated test exercises the training-skip branch** for account collections (only the movement-type helper hard-codes `training_flag: false`, `CloseOrphanedShiftCommandTest.php:925,999`) — the code path is correct and symmetric with the already-tested `zSessionMovements` training exclusion, so this is a coverage note, not a defect: **[MINOR]** `CloseOrphanedShiftCommandTest.php` — no test for a training-flagged account collection being excluded from `expected_cash`. |
| **[IMPORTANT] ratchet baseline annotated, not deleted** | **ADDRESSED** | `ProjectorEmissionRatchetTest.php:170-183` — the `ZSessionLifecycleProjection` baseline **array entry is still present** (`'App\Modules\POS\Application\Projections\ZSessionLifecycleProjection'`, unchanged at the closing line), with a new block stating it is true about ES-03/ES-02/ES-05 and "NO LONGER TRUE ABOUT THE PROJECTOR AS A WHOLE", naming `OrphanedShiftDeviceCloseApplied`/`OrphanedShiftDeviceCloseReconciler` as the emission the file-scan can't see, and an explicit "DO NOT DELETE THIS LINE" with the correct removal condition (A1 ships the three named events). Nothing was deleted from `REGISTERED_PROJECTORS` or the no-emit baseline array — confirmed by diff hunk context (`git show 3136c89bc..a5c62ead4 -- apps/api/tests/Architecture/ProjectorEmissionRatchetTest.php`, +16/−0, pure comment insertion). |
| **[IMPORTANT] replay test with a distinct second event id, exercising the reconciler's own guard** | **ADDRESSED** | `PosShiftProjectionTest.php` (renamed `test_a_late_device_session_close_is_replay_safe_on_the_reconcilers_own_guard`) now mints `$first` (seq 3) and `$second` (seq 4) via `makeSessionCloseEvent()`, asserts `assertNotSame($first->id, $second->id)`, applies both. Verified against `OrphanedShiftDeviceCloseReconciler::deviceCloseAlreadyApplied()` (`OrphanedShiftDeviceCloseReconciler.php:192-200`): the guard is keyed on `(company_id, aggregate_id=shift_id)` via the `shift.orphan_device_close_applied` audit row — **shift-level, not event-level** — so with the projector's own `z_session_events` fiscal-event-id short-circuit (`ZSessionLifecycleProjection.php:65`) unable to fire on a *different* event id, the second `apply()` call genuinely reaches `reconcile()` and is turned away by `deviceCloseAlreadyApplied()` alone. Test asserts the **first** close's figures stand (`actual_cash == 140`, not the second event's `999`), exactly one audit row, and reads it back to confirm `payload['fiscal_event_id'] === $first->id` (`PosShiftProjectionTest.php:454-483`) — matching the actual persisted keys at `DomainEventSubscriber.php:838,844`. This closes the gap the r2 finding named precisely. |
| **[MINOR ×4] enums for magic strings, `SAFE_DROP` sign honesty, "one derivation" claim softened, reconciler audit row read back in test** | **ADDRESSED** | `FiscalEventType`/`IntegrityStatus` enum cases replace string literals in `zSessionMovements()` (`ShiftExpectedCashService.php:362-368`) and `movementDirection()` now `match`es on `FiscalEventType::tryFrom()` (`:568-575`) rather than a string-keyed const array. `chain_context` genuinely has **no enum anywhere in the codebase** — confirmed by a full-repo grep for `enum ChainContext` (zero hits) and by reading every `chain_context`-touching file in `app/Modules/Fiscal` and `app/Modules/POS/**` (all compare it as a bare string literal, e.g. `ZSessionLifecycleProjection.php:61`, `StrictCanonicalParser.php:684`, `OutboxIngestor.php` ×6). Naming it `LIVE_Z_SESSION_CHAIN` as a documented private const (`ShiftExpectedCashService.php:99-107`) rather than inventing a new cross-cutting enum for one file is a reasonable, honestly-flagged call — acceptable. `SAFE_DROP`'s sign docblock now explicitly disclaims it as an observed device behaviour (`ShiftExpectedCashService.php:523-531`). The "one derivation, one place" class docblock is corrected to name exactly what's shared (`:39-52`). The reconciler's audit row is read back in the rewritten replay test (`PosShiftProjectionTest.php:478-483`). |

### New findings in the fix diff (293d256ad, 2c2ae2133, a5c62ead4 only)

No new Critical or Important found. Specifically checked and cleared:

- **No double-counting between the new collections term and the receipts term.** `AccountPaymentReceiptProjection::apply()` (`AccountPaymentReceiptProjection.php:46-68`) writes only to `pos_account_payment_receipts`; it never touches `pos_receipts`/`pos_receipt_payments`. So `cashTenderedNetOfChange()` (reads `pos_receipt_payments` joined to `pos_receipts`) and `cashAccountCollections()` (reads `pos_account_payment_receipts` joined to `fiscal_events`) are reading disjoint tables — additive, not overlapping.
- **The fixture `sequence_number` counter fix is fixture-only and does not mask a real uniqueness defect.** The unique constraint is `(tenant_id, company_id, terminal_id, chain_context, sequence_number)` (`2026_05_14_100001_create_fiscal_events_table.php:93`). The account-collection fixture writes `chain_context = 'operational'` (disjoint from the z-session movement fixture's `'z_session'`), and the new `$accountCollectionSequence` counter (private property, starts at 900, `++`-incremented, `CloseOrphanedShiftCommandTest.php:74-79,1035`) is scoped to one test-method instance — nothing here is standing in for a production sequencing guarantee; production event authoring does not use `microtime()`.
- **Scale/currency handling on the new term is rule-19 clean.** `cashAccountCollections()` receives `$working = $scale + 1` from the same `breakdown()` caller that resolves scale via `getScale($currencyCode)` (no bare no-arg call); amounts are rounded once via `CurrencyScale::bcformatStrict()` before entering the running `bcadd` total (`ShiftExpectedCashService.php:453`).
- **Exit 9 fires before any write.** `breakdown()` is called at `CloseOrphanedShiftCommand.php:325`, outside and before the `DB::transaction` that performs the close write — confirmed by the test asserting the shift is still `OPEN` and zero audit rows after exit 9.

One coverage-only note carried forward from the table above (not a merge blocker): no test exercises the training-flag exclusion branch of `cashAccountCollections()`.

### One line to fix before merge

Nothing blocking. Optional: add one test asserting a training-flagged `ACCOUNT_PAYMENT` collection does not move `expected_cash` (parity with the existing z-session-movement training test).

**VERDICT: mergeable**

> Provenance: this r3 section was produced by the scoped fiscal re-reviewer against range 3136c89bc..a5c62ead4 and originally appended to the lane worktree's report; restored here by the orchestrator (final review I-3).
