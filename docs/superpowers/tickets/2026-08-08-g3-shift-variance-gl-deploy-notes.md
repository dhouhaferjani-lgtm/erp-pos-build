# Deploy notes: DPA lane G3 — shift-close cash variance GL leg

Branch `feat/dpa-g3-shift-variance-gl`. Both gate halves APPROVED (spec ✅ / spec ✅) on 2026-08-08.
Read this **before** deploying, and again before anyone flips the flag.

---

## 1. What ships ENABLED at deploy (⚠ expect an alert-volume change)

The lane has two halves and **only one of them is behind the kill switch.**

The **C1 half is UNGATED and takes effect the moment this deploys** (treasury re-review N1).
`ZReportSyncController::resolveSyncedAggregateVariance()` now derives the shift aggregate from
`SUM(cash_counts[].variance_amount)` when the device sends no `shift_fields.variance_amount` —
which is what the shipping device always does (`apps/pos/src/lib/offline/types.ts:139-144`:
`LocalZReportShiftFields` has no `variance_amount`, no `actual_cash`). Consequences from the first
sync after deploy, on every legacy (`fiscal_schema_version < 3`) terminal:

- `pos_shifts.variance` is **stamped** where it previously stayed **NULL**;
- `CashCountRecorded::$aggregateVariance` carries a **real, non-zero** figure where it previously
  carried a hard zero;
- therefore `OpenFraudAlertForShiftVariance.php:24-26` **no longer short-circuits on `isZero()`** →
  a `fraud_alerts` row is opened for every synced shift with a genuine counted variance;
- and `:68-70` `dispatchToFraudEmails()` **may send email**, at whatever
  `cash_variance_email_severity` each company has configured.

This is the correct fix — the alerts were being suppressed by a defect — but it lands *precisely
while the GL half is deliberately off*, so nobody will be watching for it.

**Actions before deploy:**
- [ ] Tell the owner that cash-variance fraud alerts will start firing, and that this is the bug
      being fixed, not a new problem.
- [ ] Check `company_fraud_settings.cash_variance_email_severity` per tenant and confirm the
      resulting email volume is wanted. `'none'` suppresses email while still recording the alert.
- [ ] Expect a one-off backlog spike on the first sync window after deploy.

## 2. What ships DISABLED

`treasury.shift_variance_gl_enabled` — `config/treasury.php`, `(bool) env('TREASURY_SHIFT_VARIANCE_GL_ENABLED', false)`.
Default **false**; set nothing and the GL half is inert (checked as the first statement of
`PostShiftCashVarianceAdjustment::handle()`, before any query, any audit write and any idempotency
consumption).

Migration in this batch: `2026_08_08_140000_unique_repository_adjustments_pos_shift.php` — additive,
`CREATE UNIQUE INDEX IF NOT EXISTS … WHERE pos_shift_id IS NOT NULL`, unattended-safe. The column
shipped NULL-only with the V3 table in the same undeployed batch, so no backfill is possible and no
tenant can hold a conflicting pair.

## 3. HARD PRE-ENABLE GATES — do not flip the flag until all four are closed

### G-1 — Owner ruling on POS count semantics
Do cashiers count the shift's **takings** or the **whole drawer**? The listener books the number
the system already computes, and `ReportGenerationService::buildExpectedPerMethod()` sums receipt
payments ONLY — no opening float, no deposits, no payouts. Under whole-drawer semantics the float
would be booked to 658/758 on **every** close, permanently. The ruling should be taken together
with two linked questions: whether mid-shift `DEPOSIT`/`PAYOUT` drawer operations belong in the
expected basis, and whether an OUT variance that would take a till negative should refuse (today's
behaviour) or record-and-alert.

### G-2 — Tenant policy must move out of config, into a company setting
**Recorded verbatim from the treasury re-review (finding N2), as the reviewer wrote it:**

> **[Minor] [N2] `config/treasury.php:31` — right home for a kill switch, wrong home for the answer it is waiting on.**
> Ruling as asked. As a **disabled-by-default deployment kill switch** the config/env home is correct and has precedent the program itself blessed: the hardcoded-defaults register §4 item 7 classifies `config/fiscal.php:37` as "a deployment/operational knob — technical, not country-varying". But the question it gates ("do cashiers count the takings or the whole drawer?") is **tenant business policy**, and one env var is all-or-nothing across every tenant on the deployment. The same register flags `config/treasury.php:9` as **P-8** for exactly this class of mistake. The provider comment (`TreasuryServiceProvider.php:180-182`) claims the in-`handle()` placement gives "a future per-company dimension somewhere to live" — it does not today: `$event->companyId` is never consulted for gating. *Fix (pre-enable, not pre-merge): before the flag is ever flipped, move the decision to a company setting using the idiom the sibling listener already uses — `CompanyFraudSettingsRepository::findByCompany()` + `getDefaults()` (`OpenFraudAlertForShiftVariance.php:73-82`) — and keep the config flag as the global emergency off-switch above it.*

The in-`handle()` placement of the gate was chosen so this per-company dimension has somewhere to
land without moving the check; that is the only part of the design that anticipates it. Nothing
consults `$event->companyId` for gating today.

### G-3 — A backfill command, or a written-off window
The disabled window is **permanently unrecoverable** without one: a re-sync short-circuits at
`200 duplicate` before the dispatch block, so no shift closed while the flag was off will ever
raise its event again. See
`docs/superpowers/tickets/2026-08-08-g3-kill-switch-window-no-backfill.md` for the command shape.
Either ship it, or get explicit owner sign-off that the window is written off — and record the
window's start date so the hole is bounded.

### G-4 — Seed `default_repository_id` on every physical payment method
Without a mapping, `TenderRepositoryResolver`'s historical fallback picks the first GL-linked
repository by UUID, which may be a bank account. The listener refuses that case
(`resolved_repository_is_not_a_cash_till`, audited) rather than mis-booking it — so this is not a
correctness risk, but an unseeded tenant will simply get **no GL leg at all**, silently except for
the audit row. Seed the mappings before enabling, or the flag will look like it did nothing.

## 4. After enabling — what to watch

Both outcomes are durable in `audit_events`, so verification is a query, not a log grep:

```sql
SELECT event_type, payload->>'reason' AS reason, count(*)
FROM audit_events
WHERE event_type IN ('treasury.shift_variance_gl_booked','treasury.shift_variance_gl_skipped')
GROUP BY 1, 2 ORDER BY 3 DESC;
```

Refusal reasons and what each means:

| reason | meaning |
|---|---|
| `insufficient_repository_balance` | shortfall exceeds the till's cached balance; `allowNegative` is false (policy, see G-1) |
| `repository_frozen` | till frozen; the amount is server-computed so `allowWhileFrozen` is false |
| `no_repository_resolved` / `resolved_repository_is_not_a_cash_till` | see G-4 |
| `tender_not_physical_or_unknown` | a card tender or an unknown method id arrived in the count |
| `ambiguous_repositories` | moved tenders map to more than one repository |
| `aggregate_breakdown_mismatch` | the declared aggregate disagrees with the per-tender sum |
| `unattributable_tolerance_writeoff` | belt-and-braces guard, see the report §A.2 correction — **can refuse a whole shift**; if this appears at any volume, investigate before assuming a double-count risk |
| `currency_mismatch` | repository currency ≠ counted currency |
| `exception` | a genuine fault — investigate |

A `treasury.shift_variance_gl_booked` row carries `truncated_residual`: the scale-4 → money-scale
remainder that was NOT booked. Non-zero values are expected and explain any last-digit
disagreement between the ledger and `pos_shifts.variance`.

## 5. Related tickets

- `2026-08-08-g3-kill-switch-window-no-backfill.md` (G-3)
- `2026-08-08-g3-v3-terminals-no-cashcount-producer.md` — cutover terminals have no producer at
  all; answer "does any tenant still run a `fiscal_schema_version < 3` terminal?" first, because if
  none does, this lane is inert in production regardless of the flag
- `2026-08-08-g3-legacy-closeshift-no-gl-leg.md` — gated on G-1
- `2026-07-31-cashdrawer-v3-expected-cash-blind.md` — pre-existing, overlaps G-1
