# Cash Rounding — Phase 1 (server) deploy checklist

**Spec:** `docs/superpowers/specs/2026-07-27-pos-cash-rounding-tolerance-design.md` §7
**Plan:** `docs/superpowers/plans/2026-07-27-cash-rounding-server-phase1.md` (Tasks 1–13)
**Scope:** `apps/api` only. The device (`apps/pos`) ships in Phase 2
(`docs/superpowers/plans/2026-07-27-cash-rounding-device-phase2.md`).

Pushing to `origin/dev` auto-deploys staging **and runs `tenants:migrate`**.
Every migration in this batch is self-guarding, so the deploy itself is safe.
The steps below are what the auto-deploy does **not** do.

**Read §1 before promoting to production** — one intended, owner-visible
behaviour change lands with the migrations.

---

## 0. What lands automatically (`tenants:migrate`)

Three tenant migrations, in filename order:

| Migration | Effect |
|---|---|
| `2026_07_28_100000_add_is_cash_tender_to_payment_methods` | Adds `payment_methods.is_cash_tender` (default `false`); backfills `is_cash_tender = true` **and normalizes `code` to exactly `'CASH'`** for the unambiguous case-variant rows. Ambiguous rows (a company holding both `'CASH'` and `'cash'`, or several variants and no canonical row) are **left alone, fail-closed** and logged as `cash_rounding.backfill.skipped_ambiguous_cash_code`. |
| `2026_07_28_100100_add_cash_rounding_to_country_payment_settings` | Adds `cash_rounding_enabled` (`false`), `cash_rounding_denomination`, `pos_tolerance_enabled` (`false`); **UPSERTS the TN row** with `max_payment_tolerance_amount = 0.1000` and **`payment_tolerance_enabled = true`** (the `$pinned` array at `:66-71`, applied on BOTH the insert branch `:76` and the update branch `:94` — see §1). `cash_rounding_denomination = 0.0500` and both POS switches OFF are set on the **INSERT branch only**; the update branch never touches the two POS switches, and backfills the denomination only when it is still NULL. Skipped when the tenant's `countries` table has no `TN` row (fresh tenants — see §2 step 0). |
| `2026_07_28_100200_add_cash_rounding_to_pos_receipts` | Adds `pos_receipts.cash_rounding_adjustment` `decimal(12,3)` **signed** + `cash_rounding_denomination` `decimal(15,4)`, both nullable. **Swaps the `pos_receipts_totals` CHECK** (pgsql-guarded). Creates two partial unique indexes on `journal_entries`: `uniq_je_source_pos_cash_rounding` and `uniq_je_source_pos_tolerance_bridge`. |

Everything else in the batch is version-gated on
`fiscal_events.event_version >= 3` (`App\Shared\Domain\CashRoundingCutover`)
and is therefore **INERT** until a device signs a v3 receipt.

### 0.1 Lock note — the `pos_receipts_totals` CHECK swap

The swap is `DROP CONSTRAINT IF EXISTS` + `ADD CONSTRAINT … NOT VALID`, then a
separate savepoint-protected `VALIDATE CONSTRAINT`.

- `ADD CONSTRAINT` takes **ACCESS EXCLUSIVE** on `pos_receipts`. On its own
  that would be brief (`NOT VALID` skips the table scan) — **but it is not on
  its own.** Laravel runs PostgreSQL migrations inside a single transaction
  (`Migration::$withinTransaction = true` + `PostgresGrammar::$transactions = true`,
  stated in the migration's own docblock at `:64-70`), and the `VALIDATE
  CONSTRAINT` runs in the same migration — inside that same transaction, as a
  nested `DB::transaction()` savepoint. **A lock taken in a transaction is held
  until that transaction commits.** So the ACCESS EXCLUSIVE lock acquired by
  `ADD CONSTRAINT` is held *through* the VALIDATE scan and only released when
  the migration commits.
- **Practical effect: every reader AND writer of `pos_receipts` blocks for the
  full duration of the validation scan.** The standalone
  `VALIDATE CONSTRAINT`'s weaker SHARE UPDATE EXCLUSIVE lock buys nothing here,
  because the stronger lock is already held. On a large tenant's
  `pos_receipts` this is a hard outage window for POS sync and every report
  touching that table — size the deploy window against the row count of your
  biggest tenant, and run it out of trading hours.
- On a **first apply** the net effect is identical to a plain validating ADD:
  every existing row has `cash_rounding_adjustment IS NULL`, so
  `COALESCE(...) = 0` and the new expression reduces to the old one. VALIDATE
  succeeds and `convalidated` ends up `true`.

### 0.2 Rollback of `…_100200` is a ONE-WAY DOOR for the totals invariant

`down()` is deliberately asymmetric and **lossy**:

- It re-adds the legacy identity `NOT VALID` (a plain ADD would abort with
  23514 the moment one rounded receipt exists — i.e. exactly during the
  incident that motivated the rollback).
- It then **DROPS `cash_rounding_adjustment`, destroying the values.** A
  rounded receipt is left as bare `total 9.950 / subtotal 9.973` residue that
  satisfies neither identity, and fiscal immutability forbids deleting it
  (`prevent_receipt_modification` blocks DELETE on `pos_receipts`).
- Re-applying `up()` afterwards **succeeds** but leaves the rounding-aware
  constraint permanently `NOT VALID`, with this warning in the log:

  > `pos_receipts_totals left NOT VALID: existing rows violate the rounding-aware identity.`

  New writes are still fully enforced (PostgreSQL applies a NOT VALID CHECK to
  every subsequent INSERT/UPDATE); only the historical scan is skipped.

**Full recovery is manual and out-of-migration:** backfill
`pos_receipts.cash_rounding_adjustment` from each receipt's `canonical_bytes`,
then:

```sql
ALTER TABLE pos_receipts VALIDATE CONSTRAINT pos_receipts_totals;
```

**Do not roll `…_100200` back on a tenant that has taken a rounded sale** unless
you are prepared to run that backfill.

### 0.3 `change_due` now written for v3 — expected-cash figures shift at cutover

`PosCoreReceiptProjection` writes `pos_receipts.change_due` for v3 receipts
(it did not before). Once a rounding terminal is cut over, its **expected-cash
and shift-variance figures move** — this is the correct direction (change given
back is no longer counted as cash in the drawer), but it is a visible step
change for the store manager. Brief them before cutover, and do not read it as
a regression.

This is inert in Phase 1: no device signs v3 yet.

---

## 1. ⚠️ Owner-visible behaviour change (intended)

Inserting the TN `country_payment_settings` row **tightens the live B2B payment
tolerance ceiling from the `0.50` system default to the intended `0.1000`.**

No tenant has a row today — the original `2025_12_10_100000` seed insert was
broken (it omitted `id` on a NOT-NULL uuid PK and only ran when `countries` was
already populated, which it is not at tenant-migration time), so
`PaymentToleranceService::getToleranceSettings()` currently falls through to
system defaults. **This is the intended correction — confirm the accountant is
aware before promoting to production.**

### 1.1 🔴 The MIGRATION re-pins `payment_tolerance_enabled = true` on every `tenants:migrate`

This is the second, easier-to-miss half of the same change, and it needs **zero
operator action** to happen — it rides the auto-deploy.

`2026_07_28_100100`'s `$pinned` array (`:66-71`) contains
`'payment_tolerance_enabled' => true`, and `$pinned` is applied on **both**
branches: merged into the INSERT (`:76`) and passed to the UPDATE (`:94`).

**Consequence: a tenant that had B2B payment tolerance deliberately switched OFF
by hand gets it switched back ON the moment `tenants:migrate` runs** — together
with the tightened `0.1000` ceiling. Nobody runs a command; the auto-deploy does
it. The migration is not one-shot either: `tenants:migrate` is idempotent for
schema but this UPDATE re-runs whenever the migration is re-applied.

`CountryPaymentSettingsSeeder` pins the same three values on every run for every
country in `App\Shared\Domain\CountryPaymentDefaults` (TN `0.0050 / 0.1000`,
FR `0.0050 / 0.5000`), so running it (§2 step 0) has the same effect and extends
it to FR.

**If any tenant has B2B tolerance intentionally disabled, capture that state
BEFORE the deploy and restore it after:**

```sql
-- before
SELECT country_code, payment_tolerance_enabled, payment_tolerance_percentage, max_payment_tolerance_amount
FROM country_payment_settings;
```

### 1.2 What is NOT affected

**POS behaviour is unaffected in Phase 1.** `cash_rounding_enabled` and
`pos_tolerance_enabled` both default to `false` and are independent of each
other.

Scope this correctly — it is a claim about the two POS switches only:
**flipping `cash_rounding_enabled` or `pos_tolerance_enabled` (via
`pos:configure-cash-rounding`) never writes the B2B `payment_tolerance_enabled`
column.** The reverse is NOT true in the other direction: the migration and the
seeder both DO write `payment_tolerance_enabled` (§1.1). "Nothing touches B2B"
is wrong; "the POS kill-switches do not touch B2B" is right.

---

## 2. Manual steps, in order

> ### 🔑 HOW TO PASS FLAGS THROUGH `tenants:run` — read before copying any command below
>
> **`tenants:run <name> -- <flags>` DOES NOT WORK.** Symfony rejects the `--`
> passthrough. stancl's runner takes the command NAME as its single argument and
> forwards flags only through repeatable `--option='k=v'` pairs
> (`vendor/stancl/tenancy/src/Commands/Run.php:23-26` signature, `:50-51` the
> option reduce). **Boolean flags are passed as `=1`.**
>
> **And the exit code is NOT a gate.** `Run::handle()` returns null after
> `$this->call(...)` (`Run.php:33-56`), so every child's status is swallowed and
> `tenants:run` **always exits 0** — for EVERY command in this checklist, not
> just the cash-rounding ones. **Gate on the output tokens below, never on `$?`.**
>
> Both commands emit their token as the **last line of each tenant's block**, and
> `tenants:run` prints `Tenant: <uuid>` before each block — which is what makes
> the tenant count derivable from the same log.
>
> **The `|| echo 'GATE FAILED…'` lines below PRINT, they do not FAIL.** They
> leave `$?` at 0 and will not stop a script or a CI step. **Read the output
> with your eyes**, or wire the `!`/`test` expressions into your own
> `set -e` / `exit 1` harness. And before trusting either gate, confirm
> `tenants seen` is **greater than zero** and **matches your tenant inventory** —
> a log with zero `Tenant:` lines makes `TENANT_COUNT=0`, and both halves of the
> gate then pass vacuously on an empty file.

### Step 0 — fresh tenants self-heal; existing tenants may still need the seeder

Newly provisioned tenants get the row from
`TenantInitializationService::seedReferenceData()`, which now calls
`CountryPaymentSettingsSeeder` **after** `CountriesSeeder`. Existing tenants got
it from the `…_100100` migration upsert — **unless their `countries` table had
no `TN` row at migration time**, in which case the upsert was skipped.

The `--verify` run in step 2 will show this as either `No country_payment_settings
rows exist in this tenant database.` or a missing `country=TN` line. Remedy:

```bash
php artisan tenants:run db:seed --option='class=Database\Seeders\CountryPaymentSettingsSeeder' --option='force=1'
```

`CountryPaymentSettingsSeeder::run()` takes **no** optional `Company`
parameter, so it does not hit the silent-no-op trap that
`?Company $company = null` seeders hit under `tenants:run db:seed`.

### Step 1 — 🔴 HARD PREREQUISITE: backfill the tolerance purpose accounts

**This must complete cleanly BEFORE any v3 traffic reaches the server.** Without
the `6580` / `7580` accounts (resolved by `SystemAccountPurpose`, not by code),
`GeneralLedgerService::hasAccountForPurpose` returns false, and
`TreasuryReceiptBridge` **silently SKIPS the rounding / tolerance journal
entries** — it only emits a `pos.gl.tolerance_purpose_missing` audit event. The
sale still posts; the rounding gain/loss just never reaches the ledger, and
reconstructing it later is manual.

Dry run first:

```bash
php artisan tenants:run accounting:backfill-tolerance-purposes --option='dry-run=1' \
  | tee /tmp/tolerance-backfill-dry.log
```

Then apply and gate:

```bash
php artisan tenants:run accounting:backfill-tolerance-purposes \
  | tee /tmp/tolerance-backfill.log

# TENANT_COUNT is derived from the SAME log — tenants:run prints one
# "Tenant: <uuid>" line per tenant before that tenant's output block.
TENANT_COUNT=$(grep -c '^Tenant: ' /tmp/tolerance-backfill.log)
echo "tenants seen: $TENANT_COUNT"

# (a) no tenant reported a failure. NEVER gate with `grep -q '… : 0'` —
#     that passes as soon as ANY ONE tenant is clean and lets a tenant
#     reporting "FAILURES: 3" straight through the gate.
! grep -qE 'TOLERANCE-PURPOSE BACKFILL FAILURES: [1-9]' /tmp/tolerance-backfill.log \
  || echo 'GATE FAILED: a tenant reported backfill failures'

# (b) every tenant actually reported. Token ABSENCE means the command aborted
#     before finishing (e.g. the Schema guard tripped) and is a FAILURE, so the
#     token count must equal the tenant count.
test "$(grep -c 'TOLERANCE-PURPOSE BACKFILL FAILURES:' /tmp/tolerance-backfill.log)" -eq "$TENANT_COUNT" \
  || echo 'GATE FAILED: a tenant produced no backfill token'
```

`TOLERANCE-PURPOSE BACKFILL FAILURES: <n>` is a stable machine-readable token
pinned by `BackfillTolerancePurposesCommandTest` — do not reword it in either
place.

**A failure means a company is missing its chart parent (`65`/`75` or
`6000`/`7000`).** The command never invents a parent. Fix the chart, then re-run
— the command is idempotent (`PURPOSE FIRST, CODE SECOND`: a brownfield chart
that already carries the tolerance purpose on a legacy `658`/`758` code is
reported and left untouched, because `accounts_company_purpose_unique` would
abort the whole tenant run otherwise).

> Note: this command has no `DB::transaction` wrapper. A partial run is
> recoverable by re-running it (idempotent), not by rollback.

### Step 2 — 🔴 HARD GATE: verify the cash-tender predicate + country row state

**This MUST pass before the Phase-2 device build ships**, because the device's
cash-method selection switches from a `'CASH'` string match to
`is_cash_tender`. A company with no active `is_cash_tender` method would have a
**dead cash checkout** after Phase 2, and an unflagged CASH method silently
banks the customer's change and alerts on every over-tender.

```bash
php artisan tenants:run pos:configure-cash-rounding --option='verify=1' \
  | tee /tmp/cr-verify.log

# TENANT_COUNT is derived from the SAME log (see step 1).
TENANT_COUNT=$(grep -c '^Tenant: ' /tmp/cr-verify.log)
echo "tenants seen: $TENANT_COUNT"

# (a) no tenant reported a failure. NEVER gate with `grep -q '… : 0'`.
! grep -qE 'CASH-ROUNDING VERIFY FAILURES: [1-9]' /tmp/cr-verify.log \
  || echo 'GATE FAILED: a tenant reported verify failures'

# (b) every tenant actually reported — token ABSENCE = the command aborted
#     before verifying (e.g. the Schema guard tripped) and is a FAILURE.
test "$(grep -c 'CASH-ROUNDING VERIFY FAILURES:' /tmp/cr-verify.log)" -eq "$TENANT_COUNT" \
  || echo 'GATE FAILED: a tenant produced no verify token'
```

`CASH-ROUNDING VERIFY FAILURES: <n>` is a stable machine-readable token pinned
by `ConfigureCashRoundingCommandTest` — do not reword it in either place.

**Expected output shape per tenant:**

```
Tenant: 0192f3c1-…
country=TN rounding=off denomination=0.0500 pos_tolerance=off b2b_tolerance=ON pct=0.0050 max=0.1000
Company 0192f3c1-…: is_cash_tender OK.
Company 0192f3c2-…: is_cash_tender OK.
CASH-ROUNDING VERIFY FAILURES: 0
```

Confirm for every tenant:

- the `country=TN` line reads `rounding=off`, `denomination=0.0500`,
  `pos_tolerance=off`, `b2b_tolerance=ON`, `max=0.1000` (§1);
- **every company** prints `is_cash_tender OK`. A company that does not is
  reported as `… has no active is_cash_tender payment method; the POS cash
  checkout would be dead once the device predicate ships.` — fix it by flagging
  the company's canonical `'CASH'` payment method
  (`is_cash_tender = true` implies `code = 'CASH'` **exactly**), then re-run.

Three known blind spots of `--verify` (deliberate, but budget for them):

- a tenant with **zero companies** passes vacuously;
- `--verify` ignores `--country` by design — it reports every settings row;
- **`--verify` combined with any mutation flag silently discards the mutation.**
  `ConfigureCashRoundingCommand:113-115` short-circuits into `verify()` before
  the flags are read, so
  `--option='verify=1' --option='enable-rounding=1'` verifies and **does not
  enable anything**, with no warning. Never combine them — run the mutation and
  the verification as two separate invocations.

Also grep the deploy log for the migration's ambiguity warning, which is exactly
the population that will fail this gate:

```bash
grep -n 'cash_rounding.backfill.skipped_ambiguous_cash_code' <deploy-log>
```

### Step 3 — restart Horizon

So the workers pick up the new projection / bridge code (the projection and the
bridge both changed):

```bash
php artisan horizon:terminate
```

Confirm the supervisor brings Horizon back and that the queues drain.

### Step 4 — reseed nothing

This phase adds **no permissions**. There is NO `RolesAndPermissionsSeeder` run
and NO `permission:cache-reset` in this deploy.

---

## 3. Post-deploy verification

1. **Payment-policy endpoint** — `GET /api/v1/pos/payment-policy`
   (sanctum + `X-Company-Id`) returns a complete DTO under `data`:

   ```json
   {
     "data": {
       "companyId": "…",
       "currencyCode": "TND",
       "currencyScale": 3,
       "cashRoundingEnabled": false,
       "cashRoundingDenomination": "0.000",
       "tenderToleranceEnabled": false,
       "tenderTolerancePercentage": "0.0050",
       "tenderToleranceMaxAmount": "0.100",
       "refreshedAt": "2026-07-28T…Z"
     }
   }
   ```

   Every money-shaped field must be a **JSON string**, not a number. If
   `cashRoundingDenomination` comes back as `0` (unquoted), a float cast has
   crept onto the path — stop, do not cut any device over.

2. **Audit events stay silent** — no new rows for these event types while
   Phase 1 is inert:
   `pos.rounding.policy_mismatch`, `pos.gl.tolerance_purpose_missing`,
   `pos.tolerance.shortfall_exceeds_config`, `pos.change.exceeds_cash_legs`.

3. **Existing POS receipts keep projecting** — spot-check a freshly synced v2
   receipt:

   ```sql
   SELECT cash_rounding_adjustment, cash_rounding_denomination, change_due, tolerance_writeoff
   FROM pos_receipts ORDER BY created_at DESC LIMIT 5;
   ```

   All four must be NULL on v1/v2 rows.

4. **The CHECK is the new one, and it is validated:**

   ```sql
   SELECT conname, convalidated, pg_get_constraintdef(oid)
   FROM pg_constraint WHERE conname = 'pos_receipts_totals';
   ```

   The definition must contain `COALESCE(cash_rounding_adjustment`, and
   `convalidated` must be `t`. A `convalidated = f` on a **first** apply means
   the VALIDATE was swallowed — check the log for the
   `pos_receipts_totals left NOT VALID` warning and read §0.2.

5. **The two partial unique indexes exist:**

   ```sql
   SELECT indexname FROM pg_indexes
   WHERE tablename = 'journal_entries'
     AND indexname IN ('uniq_je_source_pos_cash_rounding', 'uniq_je_source_pos_tolerance_bridge');
   ```

6. **Horizon queues are draining** and no `ApplyFiscalEventProjectionJob` rows
   are piling up in `fiscal_event_projections` with an exhausted state.

---

## 4. Rollback

Code rollback is safe: the migrations are additive and the CHECK swap tolerates
NULL adjustments. Two caveats:

- **`…_100200` `down()` is lossy and one-way for the totals invariant on any
  tenant that has taken a rounded sale — read §0.2 before running it.** In
  Phase 1 no tenant has, so a Phase-1 rollback is clean.
- The only non-additive *data* effects are the TN row's tightened B2B ceiling
  **and the re-pinned `payment_tolerance_enabled = true`** (§1.1). Restore them
  with a direct update if needed:

  ```sql
  UPDATE country_payment_settings
  SET max_payment_tolerance_amount = 0.5000,
      payment_tolerance_enabled    = <your pre-deploy value>
  WHERE country_code = 'TN';
  ```

  **This revert is not durable.** Both the `…_100100` migration (on any re-apply
  of `tenants:migrate`) and the next `CountryPaymentSettingsSeeder` run re-pin
  the ceiling to `0.1000` and `payment_tolerance_enabled` back to `true`. A
  durable revert means editing `App\Shared\Domain\CountryPaymentDefaults` (for
  the seeder) — the migration's literals are deliberately frozen and are not
  read from that class.

---

## 5. Ops notes — what to watch once v3 traffic starts

These are inert in Phase 1 but become live the moment a terminal is cut over.
Put them in the on-call runbook now.

### 5.1 A never-projecting receipt now dead-letters the Z report too

`ZReportProjection` derives `cash_rounding_summary` from `pos_receipts` rows. If
a **verified v3 SALE_RECEIPT inside the Z window has no `pos_receipts` row**, the
Z projection throws `ProjectionDependencyMissingException` rather than emit an
undercounted summary — so one stuck receipt projection now also blocks the shift
close.

**Remedy:** retry the stuck receipt projection first, then the Z:

```bash
php artisan fiscal:retry-projections --dry-run --limit=50
php artisan fiscal:retry-projections --limit=50
```

Useful narrowing flags: `--projector=`, `--event-id=`, `--tenant=`,
`--min-age-minutes=` (default 16), `--sync` (run inline instead of dispatching).

**🎫 Ops-watch perf ticket (open, not fixed in Phase 1).** That completeness
anti-join (`fiscal_events` LEFT JOIN `pos_receipts`, filtered on
`terminal_id` + `event_time_device` + `SALE_RECEIPT` + verified) has **no
supporting index** — it seq-scans `fiscal_events` **inside the shift-close
lock**, and the cost grows unbounded with event volume. Watch Z-report close
latency after cutover. Fix = a partial index
`fiscal_events (terminal_id, event_time_device)` restricted to verified
`SALE_RECEIPT` rows.

### 5.2 Journal entry numbering

A receipt that is **both rounded and short-tendered consumes 3 journal entry
numbers** (sale + rounding + tolerance) instead of 1. Accountants who eyeball
entry-number continuity should be warned; there is no gap, just a higher
consumption rate.

The two partial unique indexes on `journal_entries (source_type, source_id)` are
**unscoped by status**: the bridge creates and posts inside one transaction, so
there is no Draft-then-reinsert path. A GL writer that re-inserts the same
`(source_type, source_id)` will get a `23505` and the queued job will
dead-letter rather than double-post.

### 5.3 Audit event types to alert on

| Event type | Meaning | Action |
|---|---|---|
| `pos.rounding.policy_mismatch` | A device signed a denomination that differs from live policy | Check whether policy was changed mid-shift; the signed value is authoritative |
| `pos.tolerance.shortfall_exceeds_config` | A shortfall exceeded the configured tolerance ceiling | Investigate the terminal/cashier; the entry still posts |
| `pos.gl.tolerance_purpose_missing` | `6580`/`7580` absent → **the GL entry was SKIPPED** | Run step 1's backfill for that tenant, then reconstruct the missed entries |
| `pos.change.exceeds_cash_legs` | Change exceeded the sum of cash tender legs; only what cash allowed was netted | Data-quality signal on the device payload — investigate |

All four are deduplicated on `(tenant_id, event_type, aggregate_type='fiscal_event',
aggregate_id)` — one alert per receipt, not one per projection retry.

### 5.4 Report semantics change (see also §6 tickets)

From the cutover, Treasury `payments.amount` is the **RETAINED** amount while
`pos_receipt_payments.amount` stays **TENDERED**, and a fully-netted cash leg
writes no Treasury payment row at all. Any dashboard that sums
`pos_receipt_payments` as banked cash will overstate. See
`docs/architecture/precision-contract.md` → *Signed fiscal fields* →
*Two-semantics payments rule*.

---

## 6. 🎫 PRE-CUTOVER ticket list (must be closed before any terminal signs v3)

Phase 1 deliberately did **not** fix these. They are harmless while no device
signs v3 and become wrong the moment one does.

**Server-side, blocking cutover:**

1. **`SalesReportService::paymentMethodBreakdown` overstates cash**
   (`apps/api/app/Modules/Accounting/Application/Services/Reports/SalesReportService.php:160-185`)
   — it sums `pos_receipt_payments.amount`, i.e. TENDERED cash. From the cutover
   this overstates by the change given back. Fix = subtract
   `pos_receipts.change_due` or read the Treasury `payments` rows.
2. **Cash-predicate split between Z and Treasury**
   (`apps/api/app/Modules/POS/Application/Services/ReportGenerationService.php:513`)
   — the Z path matches `UPPER(pos_receipt_payments.payment_method_code) = 'CASH'`
   while the rounding/netting path uses `is_cash_tender`. For an unflagged
   case-variant method the two disagree about what cash is. Converge on
   `is_cash_tender`.
3. **Two-semantics consumer sweep** — audit every remaining reader of the two
   payment tables against §5.4: `PosAnalyticsService`, `Nf525DataProvider`,
   `ReceiptPaymentService`, and any dashboard/export summing cash.
   **Include `tolerance_writeoff` in the sweep:** on v3 rows the projection
   ALWAYS writes it (canonical `'0.000'` when no tolerance applied — only v1/v2
   legacy and training rows stay NULL), so any consumer using
   `whereNotNull('tolerance_writeoff')` as a "has tolerance" predicate selects
   **every v3 receipt**. Compare with `bccomp` against zero instead. The
   `Receipt.php` docblock has been corrected; the consumers have not been
   audited.

**Environment / data hygiene:**

4. **Demo seeders never call `CountryPaymentSettingsSeeder`** —
   `DatabaseSeeder`, `CoffeeShopSeeder`, `ParapharmacySeeder` /
   `DemoPharmacySeeder`. Only `ProductionSeeder` and
   `TenantInitializationService` do. A locally seeded or demo tenant therefore
   has **no `country_payment_settings` row** until step 0 is run by hand. Wire
   the call in, or run step 0 on every demo tenant.
5. **deptrac ratchet is red on `dev` already** (61 → 97, pre-existing stale
   baseline). Branch CI fails on it regardless of this work — resolve at
   promotion (rebaseline ticket, or coordinate with whoever owns `dev`). **Do
   not read this failure as caused by the cash-rounding branch.**

**Plan B (device) blockers — fix in `apps/pos` BEFORE the Phase-2 build:**

6. **🔴 HIGHEST CONSEQUENCE — device TS key-set mirror + drift gate is MANDATORY
   before any device signs v3.** The server's canonical v3 key set is
   `FiscalPayloadConstraintValidator::SALE_RECEIPT_PAYLOAD_KEYS_V3` — **exactly
   30 keys, lexicographically sorted** (pinned by
   `tests/Unit/Fiscal/SaleReceiptV3KeySetTest.php:24,33`). The device must carry
   a byte-identical mirror **plus an automated drift gate** that fails the build
   when the two lists diverge. A device that signs with a key set the server
   does not recognise **quarantines 100% of its receipts** — every sale, not a
   sampled few — and the receipts are already signed, so the damage is
   discovered only after the fact.
   **Same item, second half: the device must canonical-zero-normalize.** It must
   never emit `'-0.000'` — the server rejects it as
   `payload_money_negative_zero`
   (`app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:2774`).
   `cash_rounding_adjustment` is the signed field, so a naive
   `negate(0)` on the device is exactly how this happens. Canonical zero is the
   unsigned `'0.000'`.

7. **`apps/pos/src/api/toleranceApi.ts` field mismatch.** `ToleranceReceiptRow`
   declares `receiptId` and `cashierName`; the server's
   `TolerancePaymentReceiptDTO`
   (`apps/api/app/Modules/Treasury/Application/DTOs/TolerancePaymentReceiptDTO.php:23-32`)
   emits `receiptNumber`, `userId`, `userName`, `writeoffAmount`,
   `currencyCode`, `occurredAt`. **Fix the client before enabling the
   tolerance drill-down panel** — the two declared-but-absent fields arrive
   `undefined`.
8. **Device Z hash-mirror ordering — HARD constraint.**
   `apps/pos/src/lib/fiscal/zReportHashService.ts` must gain the **identical
   `isset`-guarded `cash_rounding_summary` block**
   (`apps/api/app/Modules/POS/Domain/Services/ZReportHashService.php:148-152`)
   **BEFORE any device emits the key** — otherwise legacy-arm re-verification
   produces a false `CHAIN_BREAK`. Server zero-shape contract:
   `total_adjustment` = `'0.000'` (string, scale 3), `receipt_count` = int `0`.

---

## 7. Phase 2 preconditions (NOT part of this deploy)

In order. Do not reorder.

1. **Step 1 (purpose backfill) and step 2 (`--verify`) both green** for the
   tenant. These are the two hard gates.
2. **Close the §6 pre-cutover tickets** — at minimum items 1, 2, 6, 7 and 8.
3. **Cut every terminal in the tenant over to `fiscal_schema_version = 3`**
   via `FiscalSchemaCutoverService`
   (`POST /api/v1/pos/terminals/{terminal}/fiscal-schema-cutover`, admin-only,
   gated on no-open-shift + no-unzreported + empty-queue) **BEFORE** running
   `pos:configure-cash-rounding --enable-rounding`.

   **Why this order.** The device gates rounding on *both* the policy switch and
   its own `fiscal_schema_version`. Doing the cutover first guarantees the device
   can only ever observe two coherent states — `schema < 3` with rounding not yet
   enabled, or `schema = 3` with rounding enabled. Enabling first opens the third,
   incoherent state: **enabled policy on a terminal still at `schema < 3`**, where
   the terminal's two gates disagree and its behaviour depends on which one the
   build happens to check. Cutover-then-enable makes that drift state
   unreachable by construction rather than relying on the device to resolve it
   correctly. The cutover endpoint's own preconditions (no open shift, no
   unZ-reported shift, empty queue) mean no in-flight receipt straddles the
   boundary.
4. **Then enable rounding**, per country:

   ```bash
   # dry run first
   php artisan tenants:run pos:configure-cash-rounding \
     --option='country=TN' --option='enable-rounding=1' --option='dry-run=1'

   php artisan tenants:run pos:configure-cash-rounding \
     --option='country=TN' --option='enable-rounding=1'
   ```

   ⚠️ **Rounding is enabled per COUNTRY** — one flag flips **every company** in
   that country within the tenant. There is no per-company switch.

   To set the denomination explicitly (validated against `CashRoundingCaps` and
   the `decimal(15,4)` storage scale before it is written):

   ```bash
   php artisan tenants:run pos:configure-cash-rounding \
     --option='country=TN' --option='denomination=0.0500'
   ```

   Re-run `--verify` afterwards: a row with `rounding=ON` and a denomination the
   resolver would reject is reported as a **failure**, because the device would
   cache `enabled: false` while the operator believes rounding is live.

5. **Ship the POS build** (SQLite v63 + v3 payload authoring), last.

**Kill-switch semantics.** `cash_rounding_enabled` and `pos_tolerance_enabled`
are **independent** — `--enable-rounding` / `--disable-rounding` and
`--enable-tolerance` / `--disable-tolerance`. Each pair is mutually exclusive
(the command refuses both at once). **Neither of these two switches writes the
B2B `payment_tolerance_enabled` column** — but note that the migration and the
seeder DO write it (§1.1). Disabling rounding stops new rounded receipts; it
does not and cannot un-round receipts already signed.

**There IS a per-company force-disable for tolerance (but not for rounding).**
`PosPaymentPolicyResolver::resolveToleranceEnabled` (`:175-188`) applies
`companies.payment_tolerance_enabled` as a **fail-closed direction override**:
an explicit `false` on the company disables POS tender tolerance for that
company regardless of the country switch; `true` or `null` defers to the country
row. So `--enable-tolerance` on the country will NOT enable tolerance for a
company pinned to `false` — check that column when a company reports
`tenderToleranceEnabled: false` after you enabled the country. Cash rounding has
no such per-company override: it is country-wide, full stop.

**Adding a new country.** A country that has no entry in
`App\Shared\Domain\CountryPaymentDefaults` has no sanctioned tolerance ceilings,
and `pos:configure-cash-rounding` **refuses `--enable-tolerance` on its INSERT
branch** rather than fall back to the raw `0.50` column default. Add the entry
to `CountryPaymentDefaults` — never type the literals into a caller.

**Early v3 events.** A v3 event authored before the server half is live
quarantines rather than corrupting anything, and is repairable post-deploy
through the version-threaded repair path.
