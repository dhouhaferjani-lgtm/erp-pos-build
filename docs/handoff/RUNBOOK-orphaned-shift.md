# RUNBOOK — a POS shift orphaned by a forced terminal release

**Ledger row:** O-30 (Q7-OWES-1). Source gate:
`docs/superpowers/reviews/2026-08-24-sb-q7-terminal-gate-r2-fiscal.md` §Q7-OWES-1.
**Status of this document:** it discharges O-30 **(a)** — the detection query, the remedy and the
named accountable role. O-30 **(b)** and **(c)** are owner rulings and remain **OPEN**; see
[§5](#5-what-the-owner-still-owes-o-30b-and-o-30c).
**Audience:** whoever runs `POST /pos/terminals/{id}/release` with `force=true`, and whoever has
shell access to the API container.

---

## 0. What went wrong, in one paragraph

`POST /api/v1/pos/terminals/{id}/release` is refused by default while the terminal has an OPEN
shift (409 `TERMINAL_HAS_OPEN_SHIFT`). The override — `force=true` plus a written `reason` — exists
because the usual reason to release is that the device is **gone** (lost, stolen, bricked), and a
`fiscal_schema_version >= 3` terminal's shift **cannot be closed without its authoring device**:
`ShiftController::open()`/`close()` and `SyncController::syncCloseShift()` all answer
`SHIFT_DEVICE_AUTHORITY_REQUIRED`. Forcing therefore **orphans** the OPEN `pos_shifts` row.

Until the orphan is resolved, the **replacement** device is invisible server-side:

- its `SESSION_OPEN` is silently dropped by `ZSessionLifecycleProjection::projectPosShiftOpen()`
  (a different shift is already OPEN on the terminal) — no dead-letter, no warning;
- its `SESSION_CLOSE` then throws and retries to exhaustion;
- its Z report cannot land at all: `pos_z_reports.shift_id` is FK-RESTRICTed to a `pos_shifts` row
  that will never exist.

The **fiscal chain in `fiscal_events` stays intact throughout.** It is the *projections* that are
broken, and only for the terminal in question.

---

## 1. Detect — find the orphans

Run against the **tenant** database (per-tenant DB since 2026-05-28; a bare run on `central` raises
42P01).

### 1a. Every forced release, newest first (the O-30 register query)

```sql
SELECT payload->>'open_shift_id' AS open_shift_id,
       payload->>'reason'        AS reason,
       occurred_at,
       user_id
FROM audit_events
WHERE event_type = 'terminal.released'
  AND (payload->>'forced')::boolean IS TRUE
ORDER BY occurred_at DESC;
```

### 1b. The ones that are still UNRESOLVED (use this one day to day)

1a lists every forced release ever, including the ones already cleaned up. This narrows it to the
shifts that are still OPEN, and names the terminal:

```sql
SELECT s.id            AS shift_id,
       s.shift_number,
       s.opened_at,
       t.code          AS terminal_code,
       t.id            AS terminal_id,
       t.hardware_identifier,
       a.id            AS release_audit_event_id,
       a.occurred_at   AS released_at,
       a.payload->>'reason' AS release_reason
FROM audit_events a
JOIN pos_shifts s     ON s.id::text = a.payload->>'open_shift_id'
JOIN pos_terminals t  ON t.id = s.terminal_id
WHERE a.event_type = 'terminal.released'
  AND (a.payload->>'forced')::boolean IS TRUE
  AND s.status = 'OPEN'
ORDER BY a.occurred_at DESC;
```

> The join is `s.id::text = a.payload->>'open_shift_id'`, deliberately, and NOT
> `(a.payload->>'open_shift_id')::uuid`. A cast on the payload side aborts the WHOLE result
> set with `22P02` the moment any `terminal.released` row carries a malformed or legacy
> `open_shift_id` — so one bad row would hide every genuine orphan. Casting the column side
> cannot fail: `pos_shifts.id` is already a uuid.

Empty result = nothing owed.

### 1c. The population 1a/1b cannot see (know that it exists)

A `SESSION_OPEN` authored by the dead device but **synced after** the release committed projects a
shift onto a terminal that has already been released, and no audit row can have named it. Those are
found by looking at the shifts, not the register:

```sql
SELECT s.id, s.shift_number, s.opened_at, t.code
FROM pos_shifts s
JOIN pos_terminals t ON t.id = s.terminal_id
WHERE s.status = 'OPEN'
  AND t.hardware_identifier IS NULL;
```

> This query also lists every OPEN shift on a terminal that was simply **never claimed** — a
> `hardware_identifier` of NULL means "no device bound", not "device released". Cross-check each hit
> against §1a before treating it as an orphan.

`pos:shift:close-orphaned` **will refuse** these (exit 4) — by design: without the audit row there
is no evidence the shift was orphaned rather than merely open. Escalate one of these rather than
working around the refusal; the fix is a fresh forced release naming the shift, or an owner ruling.

---

## 2. Resolve — `pos:shift:close-orphaned`

The command is **dry run by default**. Nothing is written without `--apply`.

```bash
# 1. Preview. Read the table it prints before going further.
php artisan tenants:run pos:shift:close-orphaned \
    --tenants=<tenant-uuid> \
    --argument=shift=<shift-uuid> \
    --option=reason="Till POS02 stolen 2026-08-20, police report 1234; drawer not recovered" \
    --option=closed-by=<user-uuid>

# 2. Same command plus apply=1 once the preview reads correctly.
php artisan tenants:run pos:shift:close-orphaned \
    --tenants=<tenant-uuid> \
    --argument=shift=<shift-uuid> \
    --option=reason="Till POS02 stolen 2026-08-20, police report 1234; drawer not recovered" \
    --option=closed-by=<user-uuid> \
    --option=apply=1
```

> `tenants:run` needs the `--argument=` / `--option=` forms — bare `--reason=…` is rejected by the
> wrapper, and `tenants:run` **discards the child's exit code**, so read the printed verdict line
> rather than `$?`. If you already have a tenant bound (a shell inside the tenant context), the
> command takes ordinary flags and its exit code is meaningful.

### What it refuses, and why

| Exit | Meaning | What to do |
|---|---|---|
| `0` | Closed, or already closed (a re-run is a no-op), or a dry run | Nothing |
| `2` | Usage error — bad/absent `shift`, missing `--reason`, missing/malformed `--closed-by` | Fix the invocation |
| `3` | No `pos_shifts` row with that id **in this tenant** | Wrong tenant bound, or wrong id |
| `4` | The shift is OPEN but **nothing in the audit register says a forced release orphaned it** | See below |
| `5` | `--closed-by` is not a user in this tenant | Use a real user id |
| `6` | The derived expected cash is **negative** | The movement set is incomplete or mis-signed. A drawer cannot hold less than nothing and `pos_shifts_positive_amounts` refuses to store it. Read the movements the message names and resolve them with an accountant — do **not** look for a way to force a zero |
| `7` | The close was **rolled back** because its `shift.orphan_closed` audit row could not be confirmed | Nothing was written; the orphan is still OPEN and still closable. Check `audit_events` is writable and the log for the subscriber's error, then re-run |
| `8` | The shift carries a cash movement the server **cannot sign** (today: `CASH_CORRECTION`) | Resolve the movement with an accountant. The command refuses rather than dropping it silently — a dropped movement would be exported to the JET as a balanced count that is short by its value |
| `9` | A customer **account collection** on this terminal, inside the shift's window, carries no `shift_id` and cannot be attributed | Same standard as exit 8. Attribute the collection, or confirm it belongs to another shift, before closing. Cash collected against an account is physically in the drawer; leaving it out exports a short figure as a balanced count |

**Exit 4 is the whole point of the command and must not be worked around.** This is the only
operator-reachable writer of `ShiftStatus::Closed`; if it closed any shift on request it would be a
device-less shift close by the back door and the v3 device-authority refusal would become a
formality. Authorisation is **evidence**: a `terminal.released` audit row on that terminal with
`forced = true` and `payload.open_shift_id` equal to the shift. If the device still exists, close
the shift from the device.

### What it writes

- `pos_shifts`: `status = CLOSED`, `closed_at = now`, `closed_by = --closed-by`, and a
  `notes` line recording that the close was administrative, the reason, and the id of the release
  audit row that authorised it. Existing notes are preserved.
- The money pair (owner ruling; derivation corrected at fiscal gate r1): `expected_cash` comes from
  `ShiftExpectedCashService` — the **same derivation the Z path uses** — never from a formula local
  to this command. `actual_cash` = **the same number**; `variance` = **0**. Nobody counted this
  drawer — it left the building with the device — so no shortage or overage is asserted against a
  cashier who was never asked to count one. The pair satisfies `pos_shifts_variance_calc` by
  construction, and `closed_at`/`closed_by` satisfy `pos_shifts_closed_logic`.
- **Which table the movements come from depends on the terminal, and the command prints it.** A v3
  (device-authoritative) terminal books every drawer movement as a fiscal event in
  `pos_z_session_events` and writes no `pos_cash_drawer_operations` row at all, so its expected cash
  is `opening float + net cash tendered on the shift's receipts + its z-session movements + cash
  collected against customer accounts` — the device's own Z arithmetic. A v2 terminal's movements are
  `pos_cash_drawer_operations`, and its figure is the one
  `CashDrawerService::calculateExpectedCash()` produces for the v2 Z report. **Read the
  `movement source`, `drawer movements` and `cash account collections` rows in the preview table.** A
  movement count of `0` is printed with a warning: it is normal for a shift that never had one, and a
  red flag for a till that did.
- **The window ends at the RELEASE, not at now — and the preview prints it.** `pos_receipts` carries
  no shift id, so the receipt and collection window is `terminal + time`. Nothing stops a replacement
  device claiming the terminal and selling for weeks while the orphan sits OPEN, so the window is
  closed at the authorising release's `occurred_at`: the dead device's late-synced receipts still
  count (`posted_at` is device time), the replacement till's never do. Check the
  `window ends at (release time)` row before applying — if it looks wrong, the command matched the
  wrong release.
- An audit event `shift.orphan_closed` (`aggregate_type = 'Shift'`) carrying the shift, terminal,
  cashier, reason, `closed_by`, the money pair, and `release_audit_event_id`. This is a **new** event
  class (`OrphanedShiftClosedByOperator`) — the device's own `shift.closed` is deliberately **not**
  forged, because that would tell an auditor a cashier counted a drawer that no longer exists.

### If the device comes back

A close written here is provisional in one specific sense. The command is used on the **belief** that
a device is gone, and a till that was merely offline — dead battery, a week in a drawer, a shop that
reopened — can sync weeks later. When its own `SESSION_CLOSE` arrives, the projection **replaces**
the derived pair with the device's counted figures (including a real variance) and records the swap
as `shift.orphan_device_close_applied`, with both sides of the swap in the payload and a second
marker line appended to `pos_shifts.notes`. The device is authoritative for the shift lifecycle; the
operator's figures were only ever a stand-in for a count nobody could take.

Nothing needs doing when that happens — but if the shift fed a period that has already been reported,
the reported cash position for that day has changed, and an accountant should be told.

### Verify

```sql
SELECT status, closed_at, closed_by, expected_cash, actual_cash, variance, notes
FROM pos_shifts WHERE id = '<shift-uuid>';

SELECT event_type, occurred_at, payload
FROM audit_events
WHERE aggregate_type = 'Shift' AND aggregate_id = '<shift-uuid>'
ORDER BY occurred_at;
```

Then have the replacement device open a shift and confirm the `pos_shifts` row appears
server-side — that, not the closed row, is the outcome that matters.

---

## 3. The fiscal consequence, stated plainly

Once `closed_at` is set, the shift gains a `FERMETURE_CAISSE` entry in the NF525 JET export:
`Nf525XmlBuilder::addTechnicalEvents()` emits one for **every** shift with a non-null `closed_at`,
and the JET schema has **no field that can say "closed administratively"**.

This is still strictly better than the alternative — a shift that is OPEN forever, carrying an
`OUVERTURE_CAISSE` and no closure, on a terminal that has since been re-homed. But it means the JET
alone cannot distinguish an operator close from a cashier close. The provenance survives in exactly
two places: the `shift.orphan_closed` audit row, and `pos_shifts.notes`. **Do not sanitise either.**

Whether the JET itself needs a marker is owner ruling O-30(c) — see §5.

---

## 4. Accountable role

- **Authorises the forced release and owns the written reason:** the holder of
  `pos.manage_terminals` for that company (store/POS manager). The endpoint already refuses a forced
  release without a reason, and the reason is what an auditor reads years later — write the incident,
  the date and the reference (police report, RMA, ticket), not "device broken".
- **Executes `pos:shift:close-orphaned`:** the platform operator with shell access to the API
  container. Execution is mechanical; it is not the accountability.
- **Named in `--closed-by`, and therefore accountable for the close:** the same manager who
  authorised the release, **not** the operator who typed the command. `pos_shifts.closed_by` is
  FK-RESTRICTed to `users`, so it must be a real user in that tenant; the command refuses anything
  else (exit 5) rather than dying on a raw FK violation.
- **Cadence:** run query §1b after **every** forced release, and as a standing check at each
  month-end close — an unresolved orphan blocks that terminal's Z reports, so it will surface as
  missing Z numbers if it is not caught here first.

> **`audit_events.user_id` is NULL on an orphan close, and that is not anonymity.** There is no
> authenticated user in a console run, so the register's `user_id` column is empty; the accountable
> human is in `payload.closed_by`. An auditor querying the register **by `user_id`** will find these
> rows unattributed — query `payload->>'closed_by'` instead.

---

## 5. What the owner still owes (O-30(b) and O-30(c))

Both remain **OPEN** on the LEDGER. This runbook does not pre-empt either.

- **O-30(b) — is a first-class orphan-resolution surface required before tenants may use `release`
  unattended?** `device_loss_incidents` exists as a schema-only register with no production writer
  and no endpoint. Today's remedy is a shell command run by an operator; there is no UI, no
  in-product record of the incident, and no way for a manager to resolve an orphan themselves. The
  ruling is whether that is acceptable for tenant #1, or whether the D-1 stack must land first.
- **O-30(c) — is an NF525 device re-binding (`terminal.claimed` / `terminal.released`) a reportable
  JET terminal event?** `Nf525DataProvider` whitelists exactly three terminal event types
  (`terminal.activated`, `terminal.deactivated`, `terminal.software_updated`) and `Nf525EventType`
  has no case for a binding change, so both events land in `audit_events` and are absent from the
  JET — matching the existing treatment of `terminal.training_mode_changed`. Adding a JET event type
  alters a certified export's contents, so it is a certification decision, not a code change. §3
  above is the reason this matters: without such an event the JET shows a closure with no trace of
  the device change behind it.

### Known residuals (code, not owner rulings)

> **Corrected at fiscal gate r2:** an earlier version of this section claimed customer account
> collections could not be derived because `pos_account_payment_receipts` "carries no `shift_id` and
> no tender breakdown". That was wrong — the *column* has none, the *row* does
> (`payload_snapshot` is the whole `AccountPaymentPayload`, carrying `shift_id` and
> `payment.method_code` / `payment.amount`). The term is now part of the v3 derivation, and a
> collection that genuinely cannot be attributed refuses the close (exit 9) rather than deriving
> short.

- The concurrent race between `release()` and a shift opening is closed — all three sites
  (`TerminalController::release()`, `ZSessionLifecycleProjection::projectPosShiftOpen()`,
  `ShiftManagementService::openShift()`) now take the `pos_terminals` row `FOR UPDATE` in the same
  order. What is **not** closed: a `SESSION_OPEN` authored by the dead device but **synced after**
  the release commits. That shift is orphaned and no audit row names it — query §1c finds it, and the
  command refuses it (exit 4).
- **Legacy refund cash impact is not in the v3 derivation.** The device subtracts it
  (`zReportService.ts:253-258`) from a device-LOCAL table, `local_refund_records`, that has no server
  mirror. It is written only by the pre-v4 refund path, and a v4 return is already netted out of the
  receipts term — so this is zero for any terminal that has completed its v4 rollout. On one that has
  not, and that took a legacy cash refund in the orphaned shift, the derived figure is long by that
  amount.
