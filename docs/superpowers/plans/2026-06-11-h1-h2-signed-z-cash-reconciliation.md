# H1+H2 — Signed-Z cash reconciliation rework (device = source of truth)

> Launch blockers H1 (split-tender attribution) + H2 (expected_cash must reflect the drawer),
> unified per owner + NF525/DSFinV-K research (`docs/superpowers/research/2026-06-11-nf525-z-report-cash-reconciliation-standards.md`).
> Branch `feat/zreport-launch-blockers`. TDD throughout; Codex-review the signed-hash change.
> Clean slate → no prior Z hashes to migrate.

## Canonical model (from research)

- **Per-method total = NET / allocated** (change netted into the cash figure; no separate change line).
  Split tenders: attribute each `payments_json` row to its own `method_code`.
  - CASH method total per receipt = `Σ(cash-method payment amounts) − receipt.change_due`.
  - Non-cash method total = `Σ(that method's payment amounts)`.
- **expected_cash (theoretical drawer)** folds ALL drawer movements:
  ```
  expected_cash = opening_float
                + Σ(net cash from sales)              // CASH per-method total above
                − Σ(cash refund impact)               // local_refund_records.cash_impact (already wired)
                + Σ(cash drawer deposits)             // offline_cash_drawer_ops type='deposit'
                − Σ(cash drawer payouts)              // offline_cash_drawer_ops type='payout'
                + Σ(cash account-payments)            // money received into drawer vs customer account
  ```
- Drawer movements + account collections are **cash-balance events**, NOT sales payment-method totals.

## Device data sources (all confirmed present)

| Source | Table / service | Sign | Notes |
|---|---|---|---|
| Net cash sales | `offline_receipts` (`payments_json`, `change_due`) | + | B1 already exposes `change_due`; reader now reads `method_code` |
| Cash refunds | `local_refund_records.cash_impact` | − | already summed in `zReportService` (B2) |
| Drawer deposit | `offline_cash_drawer_ops` type=`deposit` | + | `cashDrawerApi.ts:68-71` canonical: deposit=+ |
| Drawer payout | `offline_cash_drawer_ops` type=`payout` | − | payout=− |
| Cash account-payment | **NEW** `local_account_payment_records` mirror | + | see Increment 3 — `fiscal_events` has no shift_id and amount/method live in signed `canonical_bytes`, so mirror at author time (B2 pattern) |

## Sign conventions (locked)

- `offline_cash_drawer_ops`: **deposit = +drawer, payout = −drawer** (matches device `fetchDrawerBalance`).
  NOTE the server `CashDrawerService` uses DEPOSIT=− (bank-drop meaning) — do NOT copy the server sign;
  the device `deposit` is a paid-in.
- Cash account-payment = +drawer (money in against a receivable).
- Only `method_code === 'CASH'` movements affect `expected_cash`; non-cash account-payments / card do not.

## Increments (each its own commit, TDD)

**Increment 1 — split-tender net-cash in the per-method breakdown (H1)** — both surfaces.
- `zReportService.ts::aggregateReportData`: replace `receipt.payment_method_id`+`receipt.total` loop with a
  `payments_json` loop attributing each payment to its `method_code`; CASH total nets `receipt.change_due`
  once per receipt that has cash.
- `endOfDayPreview.ts`: refine B1 so the CASH per-method `total_amount` is net (subtract `change_due`);
  `expected_cash` value is unchanged (already opening + tendered − change = opening + net).
- Tests: split tender (cash+card), over-tender (change), pure card. Assert per-method nets + sums to sales.

**Increment 2 — fold drawer deposits/payouts into expected_cash (H2 part 1)** — both surfaces.
- Query `offline_cash_drawer_ops` for the shift (`shift_id`), add deposits, subtract payouts.
- `zReportService`: add to the `expectedCash` computation (line ~190) inside the signed `report_data`.
- `endOfDayPreview`: same fold so preview matches the signed Z.
- Tests: deposit raises expected, payout lowers it, mixed.

**Increment 3 — cash account-payments mirror + fold (H2 part 2)**.
- New migration: `local_account_payment_records` (id, shift_id, method_code, cash_impact, created_at),
  populated at ACCOUNT_PAYMENT author time (mirror `local_refund_records`).
- Fold cash ones (+) into expected_cash on both surfaces.
- Tests: cash account-payment raises expected; card account-payment does not.

**Increment 4 — Codex adversarial review of the signed-hash change** → save to
`docs/superpowers/reviews/`. Verify byte-compatibility / `report_data` shape; confirm no chain break.

## Byte-compatibility / signing note

`report_data` is hashed (`computeZReportHash`). The CASH per-method total and `expected_cash` already
live in `report_data`, so changing their VALUES changes the hash — fine on clean slate (no prior chain).
Open compliance Q (owner default = include): keep drawer-movement totals inside signed `report_data`.
If we later need server byte-parity, mirror the same formula in `ReportGenerationService` (v2 server path
is gated off for v3 device-authority terminals, so parity matters only for the stored/synced Z shape).

## Out of scope (separate items)

H3 empty-Z close, H4 stop v3 legacy push, M1-M5. Tunisia MDF per-ticket representation (research gap).
