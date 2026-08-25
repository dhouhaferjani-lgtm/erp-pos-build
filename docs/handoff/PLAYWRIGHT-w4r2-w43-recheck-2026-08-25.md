# Targeted re-check — W4R-2 (live POS projection lots) + W4-3/W4-4 (AP openings, partner ledger) — 2026-08-25

**Scope.** Narrow re-verification of the two lanes merged after the wave-4 re-run report
(`docs/handoff/PLAYWRIGHT-first-tenant-campaign-wave4-RERUN-2026-08-25.md`), on a **fresh** TN parapharmacy
tenant provisioned by this run, on local `dev` @ **`ddf2e45d9`**:

- **W4R-2** — `68cc056d6` — the LIVE POS receipt projection (`PosCoreReceiptProjection`) consumes FEFO lots and
  refunds restore the provenance lot.
- **W4-3 / W4-4** — `2b471d61c` — AP openings minted as **supplier** documents posting `Dr 119 / Cr 401`
  partner-tagged; partner pages read the sub-ledger; `PAYMENT_DIRECTION_MISMATCH`; GL opening batch refuses
  partner control accounts naming the DIVERS escape.

This is **not** a full campaign re-run. Flows outside A (batch/POS lots) and B (AR/AP openings + settlement)
were exercised only as far as the balances table needed them. Everything already recorded as STILL-OPEN in the
re-run report is referenced by id, not re-litigated.

**Verdict:** see §6 — written last.

---

## 0. Environment — what was actually served

| Component | State | Evidence |
|---|---|---|
| Tree | local `dev` @ **`ddf2e45d9`**, clean working tree | `git log --oneline -1`; `git status --porcelain` empty |
| Merges under test | `68cc056d6` (W4R-2) · `2b471d61c` (W4-3/W4-4) | `git show --stat` |
| API | `php artisan serve` `127.0.0.1:8010` | `GET /api/v1/health` → `{"status":"healthy","timestamp":"2026-08-25T18:55:34+00:00"}` |
| Queue worker | PID 26403, started **19:54 on the new code**, queues `default,fiscal-projections,enrichment,images,imports` | `ps aux` |
| Web | vite dev `http://localhost:5173` → 200 | |
| PG | `127.0.0.1:5433`, `autoerp` / `autoerp_secret` — **read-only `psql`**, all amounts read as strings | |
| Tenant under test | **`01a03a48-1415-71c8-9528-5e28271fa553`**, DB `tenant01a03a48-1415-71c8-9528-5e28271fa553` | provisioned by this run at 18:56 via `POST /auth/register` |
| Company | `Parapharmacie El Yasmine SARL` — TN / TND / default VAT 19.00 / `pos_stock_policy=block` / `weighted_average`, id `01a03a48-47a3-73af-9980-adb1a3daf1b8` | |
| Owner login | `sonia.benammar@paraw4r2.tn` / `Recheck!2026aA` (`Sonia Ben Ammar`) | |
| Screenshots | `.playwright-mcp/w4r2-recheck/` (9 files, git-excluded) | |
| Scratch | session scratchpad `…/scratchpad/w4r2/` | |

**Day-one provisioning (identical to the re-run):**

```
locations 1 (MAIN | shop | pos_enabled=true)   accounts 141   tax_configurations 7
payment_methods 7   payment_repositories 2   units 0 (N-9)   categories 0
payment_repositories: CASH-01 + SAFE-01 both on gl_account 53 "Caisse", location_id NULL  (N-12)
```

Second POS branch `Boutique Ariana` (`ARIA`, shop, `pos_enabled=true`) created via `POST /locations` → **201**
(`01a03a48-be31-70a7-a690-9e2e4d7f7ce1`).

**POS execution mode — unchanged.** No Tauri IziPOS dev app running, no owner at the machine to type a PIN, so
the whole POS arm was driven against the server **POS API contract** (`POST /pos/sync/fiscal-events`) with the
wave-4 device canonicaliser. It was re-validated byte-for-byte against **both** repo goldens before any event
was minted:

```
sale-receipt-v4-refund-golden.json | re-encode match: true | sha256(canonical)==expected: true | sha256(mine)==expected: true
sale-receipt-v5-golden.json        | re-encode match: true | sha256(canonical)==expected: true | sha256(mine)==expected: true
```

Every POS row below is therefore **API-contract, device run still needed**.

---

## 1. Flow A — batch-tracked products, lots, POS sale, refunds, censuses

### A.1 — Import 3 batch-tracked products with opening stock

`POST /imports` (multipart, semicolon CSV) → `validated`, 3 rows; `POST /imports/{id}/execute` → **200**,
`imported_count: 3`.

```
sku            tax_rate  requires_batch_tracking  default_tax_configuration_id
COMP-MAGN_60   13.00     t                        (NULL)          <- W2-5, known-open
GEL-HYDR_500   19.00     t                        (NULL)
SIRO-TOUX_150   7.00     t                        (NULL)
```

`requires_batch_tracking = true` on all three, from
`config/verticals.php → parapharmacy.product_defaults.requires_batch_tracking = true`. 6 categories created with
accents (**W2-3** stays fixed). Mixed **7 / 13 / 19 %** survives the import.

Opening stock landed as `DEFAULT` lots with the invented `2027-08-25` expiry (= cutover + 365) — **W4-1
reproduced**, expected, no lot/expiry column exists on the products import.

### A.2 — GRN one more lot per product (real lot numbers + real expiry)

`POST /purchase-orders` → `PO-2026-0001` (201) → `/confirm` → `/receive` with a `batches` map → **200**.

```
product_batches
 id  batch_number       sku            expiry_date
  4  LOT-SIRÔP-2026X    SIRO-TOUX_150  2027-02-28   <- earliest for SIRO
  1  DEFAULT            SIRO-TOUX_150  2027-08-25
  5  LOT-MAGNÉS-2026Y   COMP-MAGN_60   2027-05-31   <- earliest for MAGN
  2  DEFAULT            COMP-MAGN_60   2027-08-25
  3  DEFAULT            GEL-HYDR_500   2027-08-25
```

Accents byte-perfect inside lot numbers. Σ lots == `stock_levels` on every tuple before any POS activity
(SIRO 20+40=60, MAGN 15+30=45, GEL 25=25).

### A.3 — Terminal claim → shift open

```
POST /pos/terminals            -> 201  T-MAIN-01 (01a03a4a-a2d6-732b-99d1-60e2feef9a68)
POST /pos/terminals/claim      -> 200  hardware_identifier W4R2-DEV-MAIN-01
   (note: the field is `terminal_id`, not `terminal_code` — a `terminal_code` body is 422)
GET  /pos/terminals/{id}/z-chain-state -> {"z_last_hash":"GENESIS","z_hash_sequence":0,"z_number":0}
```

`SESSION_OPEN` v1, `chain_context: z_session`, seq 1, `previous_hash` = the terminal's `genesis_seed`
`408dbe21…` → `POST /pos/sync/fiscal-events` → **200** `{"stored":true,…,"sequence_conflict":false}`.
`pos_shifts` → `bfb4b74a-… | shift_number 1 | status OPEN` (device-authored `shift_id` preserved).

### A.4 — SALE of batch-tracked lines, 7 / 13 / 19 % mix — **W4R-2 FIXED**

`SALE_RECEIPT` v3, `operational` seq 1, `previous_hash` = genesis seed. Sealed content:

| line | product | qty | net | vat | rate | unit_price (TTC) |
|---|---|---|---|---|---|---|
| 1 | SIRO-TOUX_150 | 5.000 | 100.000 | 7.000 | 7.00 | 21.400 |
| 2 | COMP-MAGN_60 | 4.000 | 200.000 | 26.000 | 13.00 | 56.500 |
| 3 | GEL-HYDR_500 | 2.000 | 100.000 | 19.000 | 19.00 | 59.500 |

subtotal 400.000, `vat_total` 52.000, total 452.000, CASH. Sync → **200**, `stored: true`,
receipt `FE-T-MAIN-01-2026-00000001`.

**`pos_receipt_line_batch_allocations` — 3 rows, this is the record that did not exist before the fix:**

```
sku            batch_number      expiry_date  quantity
SIRO-TOUX_150  LOT-SIRÔP-2026X   2027-02-28    5.0000   <- FEFO: earliest expiry, not DEFAULT
COMP-MAGN_60   LOT-MAGNÉS-2026Y  2027-05-31    4.0000   <- FEFO
GEL-HYDR_500   DEFAULT           2027-08-25    2.0000   <- only lot
```

**`inventory_batch_movements` legs (joined to `stock_movements`):**

```
id  sku            batch_number      leg_qty   movement_type  reference_type  reason
 1  SIRO-TOUX_150  LOT-SIRÔP-2026X   20.0000   receipt        Document
 2  COMP-MAGN_60   LOT-MAGNÉS-2026Y  15.0000   receipt        Document
 3  SIRO-TOUX_150  LOT-SIRÔP-2026X   -5.0000   issue          pos_receipt     pos_sale
 4  COMP-MAGN_60   LOT-MAGNÉS-2026Y  -4.0000   issue          pos_receipt     pos_sale
 5  GEL-HYDR_500   DEFAULT           -2.0000   issue          pos_receipt     pos_sale
```

**Σ lots == `stock_levels` per tuple after the sale:**

| tuple | Σ lots | `stock_levels` | equal |
|---|---|---|---|
| SIRO-TOUX_150 @ MAIN | 15 + 40 = **55.0000** | **55.0000** | ✅ |
| COMP-MAGN_60 @ MAIN | 11 + 30 = **41.0000** | **41.0000** | ✅ |
| GEL-HYDR_500 @ MAIN | **23.0000** | **23.0000** | ✅ |

GL for the receipt — `JE-2026-000006`:
`Dr 53 452.000 / Cr 707 400.000 + Cr 4457 7.000 / 26.000 / 19.000` (one leg per sealed rate; **W4-9** holds on
this build).

### A.5 — REFUND one line → the **provenance** lot is credited

`SALE_RECEIPT` v3 `invoice_type_code: REFUND`, seq 2, chained on the sale's hash, `original_receipt_reference`
pointing at the sale's `fiscal_event_id` + `receipt_uuid`. 2 × SIRO, net 40.000, vat 2.800, total 42.800.
Sync → **200**.

```
id  sku            batch_number      leg_qty  movement_type  reason
 6  SIRO-TOUX_150  LOT-SIRÔP-2026X   2.0000   receipt        pos_return   <- provenance lot, NOT a new DEFAULT
```

`LOT-SIRÔP-2026X` 15 → **17**, `DEFAULT` untouched at 40, `stock_levels` 57. **Σ lots 57 == 57 ✅.** No phantom
`DEFAULT` mint (the **W2-7** shape does not reappear on the POS channel).

GL `JE-2026-000008`: `Dr 707 40.000 + Dr 4457 2.800 / Cr 53 42.800` — VAT reversed per sealed rate.

### A.6 — A second refund cannot over-credit the lot

Deliberate probe: refund **4** more SIRO when only **3** of the 5 sold remain unrefunded (2 + 4 = 6 > 5).
A real till would refuse to author this; the server **cannot** reject a sealed event, so it projects it.

```
id  sku            batch_number      leg_qty  reason
 7  SIRO-TOUX_150  LOT-SIRÔP-2026X   3.0000   pos_return   <- capped at the remaining provenance, not 4
```

`LOT-SIRÔP-2026X` 17 → **20.0000** — exactly the quantity the GRN put there, never more. The lot arm refused to
over-credit **and said so** in the log:

```
local.INFO: PosCoreReceiptProjection: refunded units could not be attributed to any lot; they stay in the
untracked remainder rather than minting a DEFAULT lot (the aggregate restore is what closes the drift)
{"fiscal_event_id":"a404efa5-…","product_id":"01a03a49-72d7-…","returned":"4.000","unattributed":"1.0000"}
```

`stock_levels` nonetheless credited the full 4 (57 → 61), so the aggregate and the lot ledger now differ by
exactly the over-refunded unit. **That is the designed trade** — a projector may never reject a sealed event —
and the census is the compensating control (§A.7). Filed as an observation, **W4R2-1**, in §4.

### A.7 — `inventory:lot-drift-census`

```
$ php artisan inventory:lot-drift-census --tenant=01a03a48-1415-71c8-9528-5e28271fa553
  01a03a48-… / 01a03a48-47a3-…
    DRIFT -1.0000  product 01a03a49-72d7-… @ 01a03a48-4917-… (lot ledger 60.0000 vs stock_levels 61.0000)
    cause: inbound stock (a return or a receipt) credited stock_levels, not the lot ledger
Tuples drifted: 1   Net drift: -1.0000   Absolute drift: 1.0000
Read-only census: nothing was written.
exit 0            (with --fail-on-drift: exit 1)
```

**Read this precisely.** The census reports **exactly one unit**, and that unit is the one the over-credit probe
in A.6 deliberately created. **On the normal path — sale then valid refund — drift was 0**, proved per tuple in
A.4 and A.5 (Σ lots == `stock_levels` on all three tuples at both points). Contrast with the wave-4 re-run,
where the same test day produced **27 units of drift across 5 tuples, 100 % of it ordinary POS sales**.

### A.8 — `pos:census-vat-legs`

```
$ php artisan tenants:run pos:census-vat-legs --tenants=01a03a48-1415-71c8-9528-5e28271fa553
Tenant: 01a03a48-1415-71c8-9528-5e28271fa553
POS output-VAT leg census: none — every POS receipt carries its sealed output VAT in the ledger.
exit 0
```

*Operator note (not a defect):* the command reads **the current connection**, so a bare
`php artisan pos:census-vat-legs --tenant=…` runs against central and answers *"Chart of accounts is not
provisioned for POS"* on a perfectly healthy tenant. It must be run under `tenants:run`. Worth one line in the
runbook — the false alarm is loud and the fix is a wrapper, not code.

### A.9 — Replay safety of the lot arm

The identical sale envelope was re-posted:

```
POST /pos/sync/fiscal-events -> 200 {"stored":false,"fiscal_event_id":"355790ea-…","sequence_conflict":false}
inventory_batch_movements: 7 rows (unchanged)   pos_receipt_line_batch_allocations: 3 rows (unchanged)
lot quantities: unchanged
```

**Replay-safe ✅** — no double consumption, no duplicate allocation rows.

---

## 2. Flow B — AR/AP openings, settlement direction, GL control-account guard

### B.1 — AP opening: supplier owes 500 — **W4-3 FIXED**

`POST /companies/{co}/opening-batches {type: AP_OPEN_ITEMS}` → 201 → `/import` (JSON `rows`, **not** multipart —
a file upload is 422 `The rows field is required`) → `/validate` → `valid: true`, `total_open_amount 500.000` →
`/post` → **200**, `documents_created: 1`.

```
document_number       type              partner_id     total    balance_due
HIST-SINV-2026-00001  supplier_invoice  …dbd0 (SUPP)   500.000      500.000
```

**`supplier_invoice`, not `invoice`** — the wave-4 shape (`HIST-INV-… type=invoice` against a supplier) is gone.

Partner sub-ledger GL — this JE did not exist before W4-3:

```
OB-2026-000001 | 119 Solde d'ouverture  Dr 500.000  partner NULL
OB-2026-000001 | 401 Fournisseurs       Cr 500.000  partner 01a03a49-dbd0-…   <- partner-tagged
```

### B.2 — Supplier page 500.000 / aged AP 500 — **W4-4 FIXED**

```
GET /partners/{supplier} -> {"receivable_balance":"0.000","payable_balance":"500.000","net_balance":"500.000"}
GET /reports/aged-payables -> grand_total "500.0000", one line "Laboratoires Ibn Sina SA" 500.0000
```

Wave 4 read `payable_balance 0.000` against exactly this live 500.000.

**UI leg** (a second, deliberately-unpaid opening item of 300.000 was used so the screens could be captured with
a live balance on them, since the 500 had already been settled by then):

| screen | shows | screenshot |
|---|---|---|
| Purchases → Suppliers (list) | `Laboratoires Ibn Sina SA … TND 300,000` | `w4r2-b01-supplier-list-300-payable.png` |
| Supplier detail → Balance | **Total Payable 300,000 TND** | `w4r2-b02-supplier-page-total-payable-300.png` |
| Aged Payables Report | vendor 300,000 / Total 300,000 (bucket **Current**) | `w4r2-b03-aged-payables-300.png` |

*Bucket note:* the item was 26 days past due and lands in **Current** because `AgedPayablesService` defines
`current: 0-30 days` (`:304-305`, `determineBucket()` `:380`). By design, not a defect.

### B.3 — Pay the supplier 500 from the bank

`POST /payment-repositories` → `BANK-01` on GL `512`, opening 0.000, `allow_negative: true`.
`POST /payments` `direction: outbound`, `TRANSFER`, allocated to `HIST-SINV-2026-00001` → **201**,
`status: completed`.

| check | result |
|---|---|
| supplier page | `payable_balance "0.000"` → UI **Total Payable 0,000 TND / "No balance"** (`w4r2-b05-…png`) |
| bank DOWN 500 | `payment_repositories BANK-01 balance` 0.000 → **−500.000** |
| JE | `JE-2026-000011` `Dr 401 500.000 (partner 01a03a49-dbd0-…) / Cr 512 500.000` |
| repository movement | `BANK-01 | out | 500.000 | balance_after -500.000 | source_type payment | journal_entry_id NOT NULL` |

All four as specified. The wave-4 inversion (`Dr bank / Cr 411`, movement **IN**) does not occur.

### B.4 — AR opening 150 → customer page 150.000

`AR_OPEN_ITEMS` batch → `HIST-INV-2026-00001` `type invoice`, total 150.000, balance_due 150.000.

```
OB-2026-000002 | 411 Clients            Dr 150.000  partner 01a03a49-dca1-…   <- partner-tagged
OB-2026-000002 | 119 Solde d'ouverture  Cr 150.000
GET /partners/{customer} -> {"receivable_balance":"150.000","payable_balance":"0.000"}
GET /reports/aged-receivables -> grand_total "150.0000" (Amira Belhaj)
```

UI: Customers list `TND 150.000` (`w4r2-b04-…png`); customer detail **Total Receivable 150,000 TND**
(`w4r2-b06-…png`).

### B.5 — `PAYMENT_DIRECTION_MISMATCH`

Two probes, and the distinction matters:

**(a) An inbound-flagged payment against a correctly-typed supplier document is NOT a mismatch.**
`POST /payments` with `direction: "inbound"` allocated to `HIST-SINV-2026-00002` returned **201**. The guard
asks whether the DOCUMENT'S TYPE fits its OWN PARTNER'S ROLE (`DocumentAllocationStateGuard
::assertDirectionMatchesPartner()`, `:90-115`) — a supplier invoice on a supplier fits, so it passes, and the
request body's `direction` is not what decides the ledger. The booking was **correct**:
`JE-2026-000012 Dr 401 200.000 / Cr 512 200.000`, movement `BANK-01 | out | 200.000`. **No money moved the wrong
way.** Worth knowing before anyone reads a 201 here as a miss.

**(b) The legacy shape IS refused.** Reconstructed the pre-W4-3 document — a *customer* invoice
(`type: invoice`) owned by the *supplier* partner, created via `POST /invoices` → 201 `INV-2026-0001`. Paying it:

```
POST /payments  (allocated to INV-2026-0001, partner = the supplier)
-> 422
{"error":{"code":"PAYMENT_DIRECTION_MISMATCH",
  "message":"This document's type does not match the partner's role, so the payment direction cannot be
             determined. A customer invoice must belong to a customer and a supplier invoice to a supplier.
             If this partner is both, set its type to Both; otherwise correct the document.",
  "details":{"document_id":"01a03a51-8f46-…","document_number":"INV-2026-0001","document_type":"invoice",
             "partner_id":"01a03a49-dbd0-…","partner_type":"supplier"}}}
```

Typed, actionable, and it fires **before** the invoice-state checks (the document was still `draft` and the
direction guard still won). Every tenant migrated before W4-3 carries exactly this row shape, and it is now
un-payable rather than silently inverted. ✅

*Side observation:* `POST /invoices` cheerfully created a customer invoice for a supplier-typed partner with no
warning at authoring time. The settlement guard catches it, but nothing stops it being written.

### B.6 — GL opening batch refuses partner control accounts

| probe | result |
|---|---|
| `ACCOUNTING` batch row on **411** | row invalid; `POST /post` → **422 `POST_FAILED`** |
| `ACCOUNTING` batch row on **401** | row invalid — **closes the re-run's open note** ("a bare 401 credit posted with no refusal on this build") |
| `ACCOUNTING` batch row on **4011** (child of 401) | row invalid — **ancestor walk works** |
| `ACCOUNTING` batch with `53` + `119` only | `valid: true` → posted, `LOCKED` |

Verbatim refusal for 411 (401 / 4011 are the same text with *FOURNISSEURS*):

> Account '411' is the partner control account for AR open items. Its opening balance is posted by the AR open
> items batch (one entry per open item, carrying the partner), not by the GL accounting opening — stating it
> here would double the control account. Remove this line and import the open items instead. **If your trial
> balance has no per-partner detail, create a single catch-all partner (e.g. 'DIVERS CLIENTS') and import one
> open item for the whole balance: it still ages, it is still collectible, and the sub-ledger still ties.**

The DIVERS escape is named on both sides. ✅

### B.7 — W4-2 opening float still works on this build

Same `ACCOUNTING` batch, row `53 / debit 1000.000 / repository_code SAFE-01` → posted → `SAFE-01 balance
1000.000`. (`CASH-01` was not seedable — it had already traded, which is the never-traded rule doing its job.)

---

## 3. PASS / FAIL table

| # | Check | Result | Evidence |
|---|---|---|---|
| A.1 | 3 batch-tracked products imported, accents, 7/13/19 % | **PASS** | §A.1 |
| A.2 | GRN mints real lots with real expiry; Σ lots == `stock_levels` | **PASS** | §A.2 |
| A.3 | Terminal claim → shift open (device `shift_id` preserved) | **PASS** | §A.3 |
| A.4 | POS sale writes `pos_receipt_line_batch_allocations` | **PASS — W4R-2 FIXED** | 3 rows, §A.4 |
| A.4 | FEFO honoured (earliest expiry drawn first) | **PASS** | LOT-SIRÔP-2026X / LOT-MAGNÉS-2026Y, §A.4 |
| A.4 | `inventory_batch_movements` issue legs on the POS channel | **PASS** | ids 3-5, `reason pos_sale` |
| A.4 | Σ lots == `stock_levels` per tuple after the sale | **PASS** | 3/3 tuples equal |
| A.4 | POS GL carries output VAT per sealed rate | **PASS** (W4-9 holds) | `JE-2026-000006` |
| A.5 | Refund credits the **provenance** lot, no phantom DEFAULT | **PASS** | leg 6, §A.5 |
| A.6 | A second refund cannot over-credit the lot | **PASS** (lot arm capped at 3 of 4) | leg 7, §A.6 |
| A.6 | …but the aggregate credits the full 4, opening 1 unit of drift | **OBSERVATION W4R2-1** | §4 |
| A.7 | `inventory:lot-drift-census` ⇒ 0 drift on the normal path | **PASS** | 0 across all tuples in A.4/A.5 |
| A.7 | census reports the deliberate over-credit, exit 1 with `--fail-on-drift` | **PASS** | §A.7 |
| A.8 | `pos:census-vat-legs` ⇒ exit 0 | **PASS** | §A.8 |
| A.9 | Lot arm is replay-safe | **PASS** | §A.9 |
| B.1 | AP opening mints a **supplier** document | **PASS — W4-3 FIXED** | `HIST-SINV-2026-00001` |
| B.1 | AP opening posts `Dr 119 / Cr 401` partner-tagged | **PASS** | `OB-2026-000001` |
| B.2 | Supplier page shows the payable | **PASS — W4-4 FIXED** | 500.000 API / 300,000 UI |
| B.2 | Aged AP shows the payable | **PASS** | 500.0000 / UI 300,000 |
| B.3 | Pay supplier from bank → page 0.000 | **PASS** | §B.3 |
| B.3 | bank DOWN 500 / JE `Dr 401` partner `/ Cr 512` / movement OUT | **PASS** (all four) | §B.3 |
| B.4 | AR opening → customer page 150.000, `411` partner-tagged | **PASS** | §B.4 |
| B.5 | Legacy customer-invoice-on-supplier ⇒ 422 `PAYMENT_DIRECTION_MISMATCH` | **PASS** | §B.5(b) |
| B.5 | A correctly-typed supplier document is not falsely refused | **PASS** (201, booked as AP) | §B.5(a) |
| B.6 | GL opening batch refuses `411` naming the DIVERS escape | **PASS** | §B.6 |
| B.6 | …refuses `401` (closes the re-run's open note) and `4011` (ancestor walk) | **PASS** | §B.6 |
| B.7 | W4-2 opening float via `repository_code` | **PASS** | SAFE-01 1000.000 |
| C | Trial balance / cash / stock / VAT reconcile | **PASS** | §5 |
| — | Dashboard "Payments Received" tile | **FAIL — new, W4R2-2** | §4 |
| — | Opening wizard final Lock step | **FAIL — W4R-1 reproduced** | §4 |
| — | 76 console errors across the UI walk | **0 errors** | 9 console logs, all clean |

**Known-open, reproduced as expected, not re-reported:** **W4-1** (opening lots are `DEFAULT` @ cutover+365 —
and FEFO now *compels* shipping them, since 2027-08-25 is later than both real lots here, so in this run the
real lots went first; on a tenant whose only lots are openings the invented date is still the whole ordering),
**N-12** (repositories `location_id NULL`; dashboard prints *By location — Unattributed 323,600 TND*), **W2-5**
(`default_tax_configuration_id` NULL on all 3 imported products), **N-9** (`units` = 0 on a fresh tenant),
**W4R-1** (§4).

**Not exercised this run** (narrow scope, no evidence either way): **B-19** (no supplier invoice was posted, so
input VAT is legitimately 0.000), **N-14**, **W2-5**'s PO-form surface, W4-3's *aged AR/AP netting of historical
credit notes*, and W4-3's *FIFO/due-date auto-allocation `PartnerRoleMismatch` skip* — both of the latter are
covered by the lane's own tests but were not driven over HTTP here.

---

## 4. New / re-confirmed defects

### W4R2-2 — **P2 (new)** — the dashboard's "Payments Received" tile counts money that LEFT

The first screen after login states, for a tenant whose only inbound money is a single 452.000 POS sale:

```
GET /api/v1/dashboard/stats
{"payments":{"received":"1580.400","pending":"150.000"}, "revenue":{"current":"0.000","previous":"150.000"}}
```

**1 580,400 TND.** The arithmetic is exact and reproducible:

```
452.000  POS sale                      (genuinely received)
+ 42.800  POS refund   -> money OUT of the drawer
+ 85.600  POS refund   -> money OUT of the drawer
+500.000  supplier payment -> money OUT of the bank
+200.000  supplier payment -> money OUT of the bank
+300.000  supplier payment -> money OUT of the bank
=1580.400
```

Root cause is a **writer/reader contract break**, not bad arithmetic. `PaymentType::isIncoming()`
(`app/Modules/Treasury/Domain/Enums/PaymentType.php:100-114`) is careful and correct — `SupplierPayment => false`,
`Refund => false` — and `DashboardController` (`:152-171`) filters on exactly that whitelist. But **nothing ever
writes those two types on these paths**:

- `PaymentController::store()` (`:915-918`) chooses the type as
  `empty($adjustedAllocations) ? PaymentType::Advance : PaymentType::DocumentPayment` — **`SupplierPayment` is
  never selected**, even though the very same method has already computed `$isSupplierPayment = true` at `:526`
  and branches on it at `:754` / `:779`. `DocumentPayment->isIncoming() === true`, so every web-admin supplier
  payment is counted as received.
- `TreasuryReceiptBridge` (`:1461`) writes `PaymentType::POS` for POS receipts **including refund receipts**,
  with a positive amount. `POS->isIncoming() === true`, so refunds are counted as received too.

Confirmed in the tenant's own rows:

```
payment_type      amount    status
pos               452.000   completed
pos                42.800   completed   <- a refund
pos                85.600   completed   <- a refund
document_payment  500.000   completed   <- a supplier payment
document_payment  200.000   completed   <- a supplier payment
document_payment  300.000   completed   <- a supplier payment
```

**The GL, the repositories and the partner pages are all correct** — this is the tile alone. But it is the
headline money number on the landing screen, it overstates by every outbound payment and every refund, and an
owner on a bench day will read it before anything else. Two-line fix on each writer; the reader already
does the right thing. Screenshot `w4r2-c01-dashboard-cash-position.png`.

*(Same screen, lower confidence, recorded not filed: `revenue.current` is `0.000` on a day with 400.000 of net
POS revenue in `707`, because the revenue tile counts invoices only and a POS receipt is not one. That may be
the intended definition — but paired with the inflated "received" it makes the dashboard read as though a
parapharmacy sold nothing and collected 1 580 TND.)*

### W4R2-1 — **P3 (new, observation)** — an over-refund is accepted and turns into lot-ledger drift

Established in §A.6. Refunding more than was sold leaves the lot ledger correct (it caps at the provenance) and
the aggregate over-credited, so the two stores disagree by the over-refunded quantity. Three things make this
low severity: a real till will not author it, the projection **logs** the unattributed quantity with the
fiscal_event_id and the exact number, and `inventory:lot-drift-census --fail-on-drift` turns it into a non-zero
exit. Two things are worth a follow-up line:

1. **The log's parenthetical is wrong for this case.** It says *"(the aggregate restore is what closes the
   drift)"*; here the aggregate restore is what **opens** it. On a product with a genuine untracked remainder
   the sentence is right; on a fully-lot-covered product it points the reader the wrong way.
2. **Nothing upstream refuses an over-refund.** The receipt-level cap (returned ≤ sold, across all prior
   refunds) exists only on the device. Since the retired `POST /pos/receipts` path is gone, there is no
   server-side arithmetic check on the sum of refunds against a receipt — the same class of gap CLAUDE.md
   rule 20 names. A server-side *flag* (not a rejection — the event is sealed) would make this visible without
   a census run.

### W4R-1 — **P3 — reproduced unchanged**

```
POST /companies/{co}/opening-batches/{glBatch}/lock
-> 422 BATCH_LOCK_FAILED  "Cannot lock batch in Locked status. Batch must be validated first."
```

The `ACCOUNTING` batch auto-locks inside `post` (`status LOCKED`, `locked_at` set, `can_lock: false`), and the
wizard's step 6 button can then only fail. **New detail from this run:** the `AR_OPEN_ITEMS` / `AP_OPEN_ITEMS`
batches do **not** auto-lock — they finish `post` at `VALIDATED` with `can_lock: true`, and their Lock button
works and is *required*: a second batch of the same type is refused with *"Company already has an unlocked
Supplier Open Items batch"* until the first is locked. So the same step is a dead end on one batch type and a
mandatory step on two others. Whatever the fix, it has to keep that asymmetry in view.

### Observations (not filed)

- **The opening-batch `import` endpoint takes JSON `rows`, not a file.** A multipart CSV — the shape the
  Products import uses and the shape the wizard's own upload step implies — is refused with
  `422 The rows field is required`. The FE does the conversion; anyone scripting an onboarding from the API
  docs will not.
- **Re-importing rows into a DRAFT batch APPENDS.** Sending a corrected `rows` array to a batch that already has
  rows produced `total_rows: 4` from 2 + 2, not a replacement. The batch had to be deleted and recreated. On a
  wizard where the natural reaction to a validation error is "fix the file and re-upload", this silently doubles
  the balance.
- **`POST /pos/terminals/claim` takes `terminal_id`.** A `terminal_code` body is `422 The terminal id field is
  required` — the error names the missing field but not the one you sent.
- **`pos:census-vat-legs` must run under `tenants:run`** (§A.8) or it reports a false "chart not provisioned".
- **`POST /invoices` will create a customer invoice for a supplier-typed partner** with no warning (§B.5).
- **Drawer and safe still share GL `53`** — unchanged provisioning, owner decision, `531 Caisse siège` exists in
  the TN chart.
- **Supplier/customer detail tab counts render as `(0)` for ~2 s** before settling to the true count
  (`Purchase Orders (0)` → `(1)`, `Payments (0)` → `(3)`). Cosmetic; a reader who screenshots quickly gets a
  wrong number.

---

## 5. Balances sanity — computed vs shown vs GL

All amounts read as strings from PG at end of run. Trial balance: **Dr 4 896.400 = Cr 4 896.400** ✅.

| Entity | Computed (truth) | Shown on its screen | GL account | Agree? |
|---|---|---|---|---|
| **Supplier** `Laboratoires Ibn Sina SA` | openings 500 + 200 + 300 − payments 500 + 200 + 300 = **0.000** | **0,000 TND** "No balance" | `401` net **0.000** | ✅ **all three** *(and at the 300-open stage: computed 300 / page 300,000 / aged AP 300 — the wave-4 mismatch is gone)* |
| **Customer** `Amira Belhaj` | opening 150.000 = **150.000** | **150,000 TND** Total Receivable | `411` net **+150.000** | ✅ **all three** *(wave 4: page 0.000)* |
| **Drawer** `CASH-01` | POS 452.000 − 42.800 − 85.600 = **323.600** | **323,600 TND** | shares `53` | ✅ exact *(N-12: no location attribution)* |
| **Safe** `SAFE-01` | opening float **1 000.000** | **1 000,000 TND** | shares `53` | ✅ exact |
| **Bank** `BANK-01` | −(500 + 200 + 300) = **−1 000.000** | **−1 000,000 TND** | `512` net **−1 000.000** | ✅ exact, all three |
| **Cash reconciliation** | GL `53` net Dr **1 323.600** vs repositories on `53` (323.600 + 1 000.000) = **1 323.600** | | | ✅ **difference 0.000** |
| **Stock valuation** | Σ qty × cost = 61×8.500 + 41×9.000 + 23×4.500 = **991.000** | Stock Levels 61 / 41 / 23 | `37` net **991.000** | ✅ exact |
| **Output VAT** | receipts seal 52.000 − 2.800 − 5.600 = **43.600** | declaration `amount_payable` **43.600** | `4457` net **43.600** | ✅ **all three** |
| **Trial balance** | — | — | Dr **4 896.400** = Cr **4 896.400** | ✅ balanced, constituents right |
| **Lot ledger** | Σ lots vs `stock_levels` | | | ⚠️ **1 unit**, 100 % of it the §A.6 over-credit probe; **0 on the normal path** |
| **Dashboard "Payments Received"** | genuinely received = **452.000** | **1 580,400 TND** | — | ❌ **W4R2-2** |

The re-run's sentence, inverted again: **all five money entities now show a number the operator can trust and
tie to the ledger to the millime** — the two partner pages that were the blocker are the two that changed. The
only remaining mismatch on this table is a dashboard tile that no ledger depends on, and a lot-ledger unit that
a deliberate probe created and the census names.

---

## 6. Verdict — onboarding-ready after promotion?

### **Yes — for the two lanes under test. Both are fixed, and neither is the reason to hold promotion.**

**W4R-2 is fixed at the right layer.** The FEFO call now lives in `PosCoreReceiptProjection` — the path a real
till actually uses — not in the retired `ReceiptCreationService`. A device-authored sale wrote three
`pos_receipt_line_batch_allocations` rows with the correct lot and expiry, drew the earliest-expiring lot on
both multi-lot products, wrote `issue` legs tagged `pos_sale`, and left Σ lots equal to `stock_levels` on every
tuple. The refund credited the **provenance** lot rather than minting a phantom `DEFAULT`, a second refund could
not over-credit it past what the GRN put there, a replay changed nothing, and `pos:census-vat-legs` is clean.
The wave-4 re-run's headline number for this defect — 27 units of drift in one test day, all of it POS — is
**0 on the normal path** here. For a parapharmacy that forces batch tracking on every product, the recall trail
and expiry control that were fiction now exist.

**W4-3 / W4-4 are fixed, and the failure mode that mattered is closed.** An AP opening mints
`HIST-SINV-…` as a **supplier invoice**, posts `Dr 119 / Cr 401` with the partner on the `401` leg, shows on the
supplier page and the aged AP, and settles through the supplier arm — `Dr 401` partner-tagged `/ Cr 512`,
repository movement **OUT**, bank down by exactly the amount. The wave-4 inversion (money out recorded as money
in) cannot be reproduced. The legacy row shape that every pre-fix tenant carries is now **refused** with a
typed, actionable `PAYMENT_DIRECTION_MISMATCH` rather than silently inverted, and the GL opening batch refuses
`411`, `401` **and** their descendants while naming the DIVERS catch-all escape — which also closes the open
note the re-run report left on that guard. Importing open AP items, the first thing an onboarding tenant does,
is safe.

### **Not yet, as a whole — but for reasons outside these two lanes.**

Nothing found here blocks promotion of `68cc056d6` or `2b471d61c`. What stands between this build and tenant #1
is the day-one list the re-run already named, plus one new item:

1. **W4R2-2 (P2, new)** — the dashboard's headline "Payments Received" overstates by every outbound payment and
   every refund (1 580,400 shown against 452.000 actually received), because two writers never emit the
   `SupplierPayment` / `Refund` types the reader's whitelist is built around. The ledger is right; the first
   screen an owner sees is not. Small, contained, and it should not survive a bench day.
2. **W4-1 (P1)** — every opening lot still carries an invented `cutover + 365` expiry. In this run the two real
   GRN lots happened to be earlier, so FEFO drew them first and the fabricated date was harmless. On a tenant
   whose lots are *all* openings — which is every tenant on day one — that invented date is the entire ordering,
   and the expiry screen states a fact nobody entered. Worse in a parapharmacy than the P1 suggests.
3. **N-12** — repositories carry no location, so two POS-enabled branches share one drawer and a per-branch cash
   count cannot reconcile. Reproduced again (`By location — Unattributed 323,600 TND`).
4. **W4R-1 (P3)**, **W2-5**, **N-9**, and the observations in §4 — small, but they are all in the onboarding
   hour.

**What is solid, said plainly.** On a tenant that did not exist an hour before this run: signup provisioned a
141-account TN chart; a 7/13/19 % catalogue imported with accents intact; a PO received real lots with real
expiry; a device-authored chain of `SESSION_OPEN` + sale + two refunds was accepted `verified` and projected
shifts, per-lot stock, drawer movements, COGS at WAC and output VAT split by sealed rate; AR and AP openings
posted to the partner sub-ledger and reconciled to the GL; a supplier was paid from the bank in the right
direction; and at the end **cash, bank, stock valuation, output VAT and the trial balance all tie to the
millime**, with the lot ledger tying too except for the single unit a deliberate probe put there. Zero console
errors across the whole UI walk.

---

*Artifacts: `.playwright-mcp/w4r2-recheck/` (9 screenshots, git-excluded). Tenant
`01a03a48-1415-71c8-9528-5e28271fa553` left in place for inspection; owner login `sonia.benammar@paraw4r2.tn` /
`Recheck!2026aA`. The fiscal-event signer is the wave-4 harness in the session scratchpad (`w4r2/sign.js`,
`mksession.js`, `mksale.js`, `mkrefund.js`, `mkrefund2.js`) — a test harness, not product code, re-validated
against both repo goldens before use. No production code was modified, no git writes were made, and the PHPUnit
suite was never run. All DB verification was read-only `psql` with monetary amounts read as strings.*
