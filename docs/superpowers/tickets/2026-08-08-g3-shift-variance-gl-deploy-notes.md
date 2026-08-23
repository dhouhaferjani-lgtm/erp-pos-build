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

## 3. HARD PRE-ENABLE GATES — do not flip the flag until all five are closed

### G-1 — Book the whole-drawer basis in Treasury before enabling
Cashiers count the **whole drawer**. The remaining gate is Treasury representation: the opening float
and mid-shift drawer operations that form the expected balance are still unbooked (SV-3/SV-4).
Enabling the variance leg before those flows are represented would create a cash/GL mismatch, so
`TREASURY_SHIFT_VARIANCE_GL_ENABLED` stays false.

Historical note: the retired takings-only server helper omitted opening cash because opening cash is
shift-level, not per-tender. That statement is accurate about the schema but false as a business
meaning: the defect is a missing join, not a wrong doctrine. This was the fourth reader to mistake
the dead helper for policy; its deprecation annotation exists to stop a fifth.

The separate questions about drawer-operation GL typing and negative-till behaviour remain owner-owed
and out of this Stage-1 change.

### G-2 — Tenant policy must move out of config, into a company setting
**Recorded verbatim from the treasury re-review (finding N2), as the reviewer wrote it:**

> **[Minor] [N2] `config/treasury.php:31` — right home for a kill switch, wrong home for the answer it is waiting on.**
> Ruling as asked. As a **disabled-by-default deployment kill switch** the config/env home is correct and has precedent the program itself blessed: the hardcoded-defaults register §4 item 7 classifies `config/fiscal.php:37` as "a deployment/operational knob — technical, not country-varying". But the question it gates ("do cashiers count the takings or the whole drawer?") is **tenant business policy**, and one env var is all-or-nothing across every tenant on the deployment. The same register flags `config/treasury.php:9` as **P-8** for exactly this class of mistake. The provider comment (`TreasuryServiceProvider.php:180-182`) claims the in-`handle()` placement gives "a future per-company dimension somewhere to live" — it does not today: `$event->companyId` is never consulted for gating. *Fix (pre-enable, not pre-merge): before the flag is ever flipped, move the decision to a company setting using the idiom the sibling listener already uses — `CompanyFraudSettingsRepository::findByCompany()` + `getDefaults()` (`OpenFraudAlertForShiftVariance.php:73-82`) — and keep the config flag as the global emergency off-switch above it.*

The in-`handle()` placement of the gate was chosen so this per-company dimension has somewhere to
land without moving the check; that is the only part of the design that anticipates it. Nothing
consults `$event->companyId` for gating today. The quoted takings-versus-whole-drawer question is
historical and superseded: cashiers count the whole drawer. G-2 now concerns only where the settled
tenant policy is stored before this global kill switch can be enabled.

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

### G-5 — Queue reachability and durability at Z-close (added by R-8, gate round 1 P3-6)

R-8 made `PostShiftCashVarianceAdjustment` a **queued** listener (`ShouldQueue`, `$tries = 3`,
`$backoff = [5,15]`, `$queue = 'default'`). That buys retries and a dead letter, and it moves two
things out of the request and into the infrastructure. Both must be checked **before** the flag is
flipped, because neither is visible until the lane is live.

- [ ] **`QUEUE_CONNECTION` is explicitly `redis` in every environment that serves POS.**
      `config/queue.php:16` defaults to **`database`** when the var is unset, and Horizon consumes
      **redis only** (`config/horizon.php:202`). An environment that forgets the var would enqueue
      into the `jobs` table and never consume it — silent, permanent loss of every variance, with a
      perfectly healthy-looking Horizon. Same class of failure as the 2026-06-12
      `fiscal-projections` incident.
- [ ] **Horizon is actually consuming `default`** on the target environment (`horizon:status`, and
      confirm `APP_ENV` matches a `horizon.environments` key — a non-matching env starts **zero**
      supervisors).
- [ ] **`TREASURY_SHIFT_VARIANCE_GL_ENABLED` is set identically in the API and the Horizon
      containers.** The flag is read in both processes — `shouldQueue()` decides whether to push,
      the belt in `handle()` decides whether to book. A skew is now AUDITED rather than silent
      (`treasury.shift_variance_gl_skipped`, reason `feature_disabled_after_enqueue`), but an
      audited drop is still a drop. Flip both together, and flip the WORKER first when enabling.
- [ ] **Accept, or mitigate, the retry durability window.** Between attempts the ONLY record of the
      variance is the queued job in Redis. Before R-8 the refusal row was written inline and
      durably; now a transient fault writes nothing until the retries are exhausted (deliberate — a
      refusal row per transient blip is noise). If the job is lost in that window (eviction,
      `horizon:clear`, a Redis restart without persistence) the variance disappears with no row
      anywhere. `failed_jobs` covers **exhaustion**, not **job loss**. Mitigation: Redis persistence
      (AOF/RDB) on the queue instance, and no `horizon:clear` during a close-of-day window.
- [ ] **Queue reachability at Z-close is guarded, not assumed.** A push failure no longer 500s a
      sealed Z report — both producers raise the event through
      `POS\Application\Services\CashCountDispatcher`, which `report()`s the fault (so it still
      reaches Sentry, as it did when it was an unhandled 500) and degrades it to a durable
      `pos.cash_count_consumers_failed` audit row. Alert on that event type.
- [ ] **Understand what that row means — the guard is ALL-OR-NOTHING, and the two faults are NOT
      symmetrical.** `Dispatcher::invokeListeners()` has no per-listener try/catch, so the first
      consumer to throw aborts the rest, and consumers run in provider-registration order:
      **(1) the queue push (Treasury) → (2) the fraud alert + its email (Compliance) → (3) the
      Spatie stored-event write** (a wildcard listener, always last). So read the `exception` field
      on the row before concluding anything:
      - a **push failure** (Redis down) throws in (1) and therefore suppresses the fraud alert, the
        fraud email AND the stored-event write. The audit row is the only survivor — **no** consumer
        ran, and the variance is only in that row.
      - a **fraud-listener failure** throws in (2). The GL job is **already enqueued** and will
        still run; only the stored-event write is lost. Do NOT re-drive the GL leg by hand here or
        you will chase a booking that is on its way.
      The ordering is pinned by `CashCountDispatchGuardTest`, so a provider reshuffle is a test
      failure rather than a silent inversion — but if you deliberately reorder, update this list and
      the `CashCountDispatcher` docblock together.
- [ ] **Decide on per-listener isolation before enabling.** The asymmetry above exists only because
      the guard wraps the whole dispatch. Case (1) is unreachable while the flag is false
      (`shouldQueue()` short-circuits before the push), which is why it was left alone at R-8 time.
      Once the flag flips with synchronous consumers still attached to `CashCountRecorded`, either
      accept that a Redis outage also costs the fraud alert and the stored event, or isolate each
      consumer in its own `try/catch` inside `CashCountDispatcher` (iterate
      `Event::getListeners(CashCountRecorded::class)`) and pin "unreachable queue ⇒ the fraud alert
      still lands".

## 4. After enabling — what to watch

Both outcomes are durable in `audit_events`, so verification is a query, not a log grep:

```sql
SELECT event_type, payload->>'reason' AS reason, count(*)
FROM audit_events
WHERE event_type IN (
  'treasury.shift_variance_gl_booked',
  'treasury.shift_variance_gl_skipped',
  -- R-8: the queue gave up on this variance after $tries attempts. Every field
  -- needed to re-book it by hand is in the payload, including the derived
  -- `adjustment_document_id`. Read `attempts` (exact, or NULL when the queue's
  -- own failed() handler wrote the row) — NOT `tries`, which is the budget.
  'treasury.shift_variance_gl_dead_lettered',
  -- R-8 gate P2-1: the close succeeded but a consumer threw and aborted the
  -- ones after it. Written by POS\Application\Services\CashCountDispatcher.
  -- Read the payload's `exception` to know WHICH: a push failure means nothing
  -- ran at all; a fraud-listener failure means the GL job is already enqueued.
  -- See the G-5 all-or-nothing checkbox in §3.
  'pos.cash_count_consumers_failed'
)
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
| `feature_disabled_after_enqueue` | R-8 gate P2-2: a job was enqueued while the flag was true but the WORKER sees it false — the API and Horizon environments disagree, or the flag flipped mid-flight. The variance is NOT booked. Reconcile the env and re-drive from the payload |

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
