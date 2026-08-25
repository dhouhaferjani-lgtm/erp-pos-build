# Playwright first-tenant campaign — WAVE 4 RE-RUN, on a fresh tenant, after the fix lanes — 2026-08-25

**Purpose.** Re-run the owner's critical path (`docs/handoff/PLAYWRIGHT-first-tenant-campaign-wave4-critical-path-2026-08-24.md`,
flows A–G) on a **fresh** TN parapharmacy tenant against local `dev` @ `81328ffdd`, after every wave-4 fix lane
landed **except W4-3**. Each flow is reported **side by side with its wave-4 result**.

**W4-3 (AP openings as customer invoices / partner ledger) is NOT merged** — owner ruling pending. Flows A/B were
run anyway; the W4-3 and W4-4 shapes are expected, recorded as **KNOWN-OPEN**, and not re-reported as defects.

**D-1 note (not a defect).** The D-1 server side is merged (`SALE_RECEIPT` v5, post-discount VAT base, forward
version gate). **Tills still seal v ≤ 4 until a POS build ships**, so the POS arm below was run at the version a
current till would author. The forward gate and the v5 clause were exercised separately (§D.11).

**Verdict:** see §7 — written last.

---

## 0. Environment — what was actually served

| Component | State | Evidence |
|---|---|---|
| Tree | local `dev` @ **`81328ffdd`** ("D-1 merged (9f2aed21e…)") | `git log --oneline -1` |
| API | `php artisan serve` on `127.0.0.1:8010`, PID 99884, started 12:59 **on current code** | `GET /api/v1/health` → `{"status":"healthy","timestamp":"2026-08-25T11:59:46+00:00"}` |
| Queue worker | PID 98528, started 12:58 **on current code**, queues `default,fiscal-projections,enrichment,images,imports` | `ps aux` |
| Web | vite dev on `http://localhost:5173` | 200 |
| PG | `127.0.0.1:5433`, `autoerp`, central `iziposcentral` — read-only `psql`, **all amounts read as strings** | |
| Tenant under test | **`01a038cd-4a42-7193-97db-2acb598752da`**, DB `tenant01a038cd-4a42-7193-97db-2acb598752da` | provisioned by this run at 12:02:53 |
| Company | `Parapharmacie Nour & Frères SARL` — TN / TND / default VAT 19,00 / `pos_stock_policy=block` / `inventory_costing_method=weighted_average`, id `01a038cd-621e-700c-af46-516e6aba0373` | |
| Owner login | `rym.trabelsi@parawave4r.tn` / `Wave4Rerun!2026` (user `Rym Trabelsi`) | |
| Screenshots | `.playwright-mcp/campaign-wave4-rerun/` (+ source CSVs under `csv/`) | |
| Scratch | session scratchpad `…/scratchpad/w4r/` | |

**POS execution mode — unchanged from wave 4.** No Tauri IziPOS dev app is running and the owner (the only
person permitted to type PINs) is not at the machine. The whole POS arm (flow D) was driven **against the server
POS API contract**, reusing wave 4's device canonicaliser, which was re-validated byte-for-byte against the
repo's golden vector before any event was minted. Every such row is labelled **API-contract, device run needed**.

**Provisioning verified (day-one state) — identical to wave 4:**

```
locations 1 (Main Location | MAIN | shop | pos_enabled=true)   accounts 141      tax_configurations 7
payment_methods 7          payment_repositories 2              units 0 (N-9)     categories 0
companies: Parapharmacie Nour & Frères SARL | TN | TND | default_tax_rate 19.00 | block | weighted_average
payment_repositories: CASH-01 Caisse principale + SAFE-01 Coffre-fort — BOTH on gl_account 53 Caisse  (unchanged)
```

Second branch `Boutique Ariana` (`ARIA`, shop, `pos_enabled=true`) created via `POST /locations` → **201**
(`01a038ce-581a-7114-8fc3-6d22f4a166f1`).

---

## 1. Flow-by-flow — this run vs wave 4

### A — Opening balances (products + stock + treasury float + AR/AP)

| # | Step | Wave 4 | **This run** | automated / needs manual |
|---|---|---|---|---|
| A.0 | Signup with a **stale company selection** in the browser profile (**W2-1**) | FAIL (76 console errors, every call 403) | **PASS — W2-1 VERIFIED FIXED** | automated |
| A.1 | Products import, accents, 3 VAT rates, `quantity` + `location_code` | PASS | **PASS** | automated |
| A.2 | Opening stock per branch + GL | PASS | **PASS** | automated |
| A.3 | Same SKU stocked in **two** branches (2nd import file) | PASS | **PASS** | automated |
| A.3b | Import creates **categories** (**W2-3**) | FAIL — 0 categories | **PASS — W2-3 VERIFIED FIXED** | automated |
| A.4 | Lots/expiry on opening stock | FAIL — W4-1 | **FAIL — W4-1 STILL OPEN (not merged)** | automated |
| A.5 | Treasury opening float (drawer 200 / safe 1 000) | **FAIL — W4-2 (P0)** | **PASS — W4-2 VERIFIED FIXED** | automated |
| A.6 | GL opening batch through the wizard (N-3) | PASS | **PASS** | automated |
| A.7 | AR opening (customer owes 150.000) | PARTIAL | **PARTIAL (unchanged)** | automated |
| A.8 | AP opening (we owe supplier 500.000) | PARTIAL — W4-3 | **PARTIAL — W4-3 KNOWN-OPEN** | automated |
| A.9 | Partner balance pages show the openings | FAIL — W4-4 | **FAIL — W4-4 KNOWN-OPEN** | automated |
| A.10 | Opening-balance wizard **Lock** step | not exercised | **FAIL — NEW W4R-1 (P3)** | automated |

**A.0 — W2-1 is fixed, and the test was the real condition, not a simulation.** The persistent Playwright profile
still held the wave-4 tenant's `autoerp-company-selection` = `01a035ba-b7c7-7014-889b-784cae531c67`. Auth was
cleared, that selection was left in place, and a brand-new tenant was signed up over it. Result:

```
before signup   autoerp-company-selection = 01a035ba-b7c7-…   (wave-4 tenant, foreign)
after  signup   autoerp-company-selection = 01a038cd-621e-…   (the NEW company)   ← rewritten
landing page    /reports rendered, ONE console error, and it is the pre-auth 401 on /auth/me
```

Wave 4 saw 76 console errors and a 403 on every authenticated call. This run has none. `w4r-a00-signup-stale-selection-w2-1-fixed.png`.

**A.1–A.3b — the import arm, and W2-3 is fixed.** Same two semicolon CSVs as wave 4 (7 products, VAT 7/13/19,
accents, stock split MAIN/ARIA), all 12 columns auto-mapped, 7/7 valid, 7 imported; then a second file
re-stating `GEL-HYDR_500` at ARIA.

```
stock_levels   SIRO-TOUX_150 MAIN 40 · CREM-SOLA_50 MAIN 25 · COMP-MAGN_60 MAIN 30 · HUIL-ARGA_100 MAIN 20
               GEL-HYDR_500 MAIN 60 + ARIA 25   ·   THER-DIGI_01 ARIA 15 · LAIT-CORP_400 ARIA 35
products       tax_rate 7.00 / 13.00 / 19.00 exactly as the CSV                       <- N-1 stays fixed
GL             INV-OB-2026-000001..8   Dr 37 / Cr 119 at purchase price   total 2367.500 both sides   ✅
categories     6 rows — Compléments · Hygiène · Matériel Médical · Phytothérapie · Soins Bébé · Soins Visage
               and category_id is NON-NULL on all 7 products                          <- W2-3 VERIFIED FIXED
brands         4, accents intact
```

Wave 4's number was the same 2367.500, so the stock arm is byte-identical and W2-3 is a pure gain.

**Known reproduced, not re-reported:** **W2-5** (`default_tax_configuration_id` NULL on all 7 — `tax_rate`
itself correct), **N-9** (`units` = 0).

**A.4 — W4-1 is still open, exactly as filed.** The lane is not merged, so this is expected and is recorded only
for the balances table:

```
product_batches   ALL 7 products | batch_number DEFAULT | expiry_date 2027-08-25   (= cutover + 365, invented)
```

**A.5 — W4-2 is fixed, and the fix is the shape the contract describes.** The GL opening template now carries a
fifth column, `repository_code`, and the wizard's Preview renders a **"Cash repository"** column naming the till
each cash debit seeds, with the legend *"Seeds this repository's opening float."*
(`w4r-a05-opening-preview-repository-code-w4-2-fixed.png`.)

Posting the batch (drawer 200.000 + safe 1 000.000 + AR 150.000 vs AP 500.000 + equity 850.000, 1 350,000 both sides):

```
payment_repositories   CASH-01 Caisse principale  balance = 200.000     ← was 0.000 in wave 4
                       SAFE-01 Coffre-fort        balance = 1000.000    ← was 0.000 in wave 4
repository_movements   in  200.000 balance_after  200.000 source_type opening_balance journal_entry_id SET
                       in 1000.000 balance_after 1000.000 source_type opening_balance journal_entry_id SET
GL  OB-2026-000001 (opening_balance, posted)
    Dr 53 Caisse 200.000 + Dr 53 Caisse 1000.000 + Dr 411 Clients 150.000
    Cr 401 Fournisseurs 500.000 + Cr 119 Solde d'ouverture 850.000        1350.000 = 1350.000  ✅
```

Two things worth stating beyond "it works". First, the movements carry a **`journal_entry_id`**, so the till's
opening movement and the GL leg are the same document — the reconciler can tie them. Second, **two repositories
sharing one GL account (`53`) are both seeded correctly from two rows on that account**, which is the exact
"merged row naming only one of two" hazard the contract's coverage check was written for; the day-one seeding
shape this tenant ships with is handled.

**A.7 / A.8 / A.9 — unchanged, and expected: W4-3 and W4-4 are KNOWN-OPEN.** Partners were created **with codes
first** (`CLI-001`, `FRN-001`) per wave 4's onboarding note. Both wizards ran clean and the AR preview still
states the split honestly. What landed:

```
HIST-INV-2026-00001 | type **invoice** | posted | 150.000 | balance_due 150.000 | is_historical=t | Nadia Chaâbane (customer)
HIST-INV-2026-00002 | type **invoice** | posted | 500.000 | balance_due 500.000 | is_historical=t | Laboratoires Méditerranée SA (supplier)
partners   receivable_balance 0.000 / payable_balance 0.000   on BOTH
journal_lines (opening)   partner_id NULL on the 411 and the 401 line
```

The AP item is still minted as a customer-side `invoice` with a `HIST-INV` prefix (**W4-3**), and no opening
balance reaches the partner sub-ledger (**W4-4**). Both as filed; not re-reported.

One thing the fix lane should know: the FE template comment for the GL batch now says *"W4-3 refuses a supplier
balance entered as a bare GL credit (it has to come through the AP open-items batch)"* — but on this build a bare
`401` credit of 500.000 in the ACCOUNTING batch **posted without any refusal**. The guard the comment describes
presumably ships with W4-3; worth confirming when that lane lands.

**A.10 — NEW W4R-1 (P3): the opening-balance wizard's last step is a dead end.** Posting auto-locks the batch in
the same transaction (`status = LOCKED`, `locked_at 12:08:41`) — correct, and wave 4 verified it. But the wizard
then advances to a step 6 **"Lock Batch"** whose button can only ever fail:

```
POST /companies/{co}/opening-batches/{batch}/lock
 -> 422 BATCH_LOCK_FAILED  "Cannot lock batch in Locked status. Batch must be validated first."
```

Nothing is surfaced in the UI — no toast, no error state; the wizard simply never reaches a "Done" step. The
first thing every onboarding tenant does ends on a button that always errors silently. Cosmetic in effect,
day-one in placement.

**Also observed in the wizard (not filed):**
- The `repository_code` column's "(optional)" hint renders as the **raw i18n key
  `openingBalances.upload.optionalColumn`** (`FileUpload.tsx:212`). The key exists in `en/common.json:639`, so
  this is a namespace resolution slip, the same class as N-7.
- The GL upload accepts **comma-delimited CSV only**, while the Products import accepts semicolons. A
  semicolon file is refused with *"Missing required columns: account_code, debit, credit, reference"* — accurate
  but it does not name the delimiter as the cause.
- Re-selecting a **file of the same name** after fixing it does not re-parse: the wizard keeps showing the
  previous error. Renaming the file cleared it.

### B — Purchasing: PO → partial GRN → remainder → supplier invoice → supplier payment

| # | Step | Wave 4 | **This run** | automated / needs manual |
|---|---|---|---|---|
| B.1 | PO unit price defaults to the **PURCHASE** price (**W2-6**) | not re-tested (API, explicit prices) | **PASS — W2-6 VERIFIED FIXED** | automated (UI) |
| B.2 | PO, 3 products, 3 VAT rates → confirm | PASS | **PASS** | automated (API) |
| B.3 | **Partial** GRN (30/50, 40/40, 0/20) + accented lots | PASS | **PASS** | automated (API) |
| B.4 | Remainder GRN (20/50, 20/20) → fully received | PASS | **PASS** | automated (API) |
| B.5 | Supplier invoice, 3-way match, mixed VAT → GL | PASS | **PASS** | automated (API) |
| B.6 | **Pay supplier in cash from the safe** | **BLOCKED by W4-2** | **PASS — unblocked by the W4-2 fix** | automated (API) |
| B.7 | Pay supplier by bank transfer | PASS | **PASS** | automated (API) |
| B.8 | Pay the AP opening item | FAIL — W4-3 (P0) | **STILL OPEN — W4-3, KNOWN-OPEN (verified by shape + code, not executed)** | automated (code) |
| B.9 | Supplier balance = opening + invoices − payments | FAIL — W4-4 | **STILL OPEN — W4-4, KNOWN-OPEN** | automated (API) |

**B.1 — W2-6 is fixed, and it says so on the line.** A PO was started in the **UI form** (the surface where W2-6
lived) and `SIRO-TOUX_150` added. The Unit Price cell defaulted to **8.500** — the purchase price — with an
explicit caption underneath: **"From purchase price"**. Wave 2 saw the 14.900 sale price here.
`w4r-b01-po-unit-price-from-purchase-price-w2-6-fixed.png`.

*(The line's Tax combobox still reads "Select tax…" while the line total is nonetheless computed at the
product's real 7 % — 8,500 HT → 0,595 tax. That is **W2-5** — `default_tax_configuration_id` is NULL on imported
products so nothing can be preselected — surfacing in the form. Known, not re-reported.)*

**B.2–B.5 — the mainline is byte-identical to wave 4, and the mixed-VAT chain holds end to end.**

```
PO-2026-0002   50 × 8.500 @ 7 %  +  40 × 9.000 @ 13 %  +  20 × 18.000 @ 19 %
               subtotal 1145.000   tax 144.950 (= 29.750 + 46.800 + 68.400)   total 1289.950     ← same as wave 4
receipt-status fully_received  110.0000 / 110.0000  100 %
stock_levels MAIN   SIRO 40→70→90   ·   MAGN 30→70   ·   ARGA 20→40
GL  JE-2026-000001..4 (goods_receipt)  Dr 37 / Cr 408  255 + 360 + 170 + 360 = 1145.000, no VAT at receipt  ✅
GL  JE-2026-000005 (supplier_invoice)  Dr 408 1145.000 + Dr 4456 144.950 / Cr 401 1289.950 (partner_id SET)  ✅
supplier payable_balance   0.000 → 1289.950
```

`4456` = **144.950**, not the 217.550 a flat-19 % bug would give. **N-1 stays fixed on the purchase arm.**

Lot ledger and aggregate agree, and accents survive into lot numbers:

```
SIRO-TOUX_150 MAIN   DEFAULT 40.0000 (exp 2027-08-25, W4-1) · LOT-SIRÔP-2026A 30.0000 · LOT-SIRÔP-2026C 20.0000  = 90 = stock_levels ✅
COMP-MAGN_60  MAIN   DEFAULT 30.0000 · LOT-MAGNÉS-2026B 40.0000                                                  = 70 = stock_levels ✅
HUIL-ARGA_100 MAIN   DEFAULT 20.0000 · LOT-ARGAN-2026D  20.0000                                                  = 40 = stock_levels ✅
```

**B.6 — this is the row wave 4 could not reach, and it now works.** With the safe seeded at 1 000.000 by the
W4-2 fix, a 500.000 cash payment against `SI-2026-0001`:

```
POST /payments {payment_method CASH, repository_id: <SAFE-01>, amount "500.000", allocations:[SI-2026-0001]}
 -> 201
repository_movements   SAFE-01  out 500.000  balance_after 500.000  source_type payment
GL  JE-2026-000006 (supplier_payment)   Dr 401 Fournisseurs 500.000 / Cr 53 Caisse 500.000     ✅
supplier payable_balance  1289.950 → 789.950     ·   SI-2026-0001 balance_due 789.950
```

**Smoke-sheet row 1.4, marked BLOCKED in wave 4, is now PASS.** Repository, GL and partner balance all move
together, in the same direction, by the same amount.

**B.7 — the bank leg, unchanged and still textbook.** A `bank_account` repository `BANK-01` on GL `512` (note it
still defaults to `allow_negative = true`, unlike the cash types), paying the 789.950 remainder:

```
repository_movements   BANK-01  out 789.950  balance_after -789.950  source_type payment
GL  JE-2026-000007 (supplier_payment)   Dr 401 789.950 / Cr 512 Banques 789.950               ✅
supplier payable_balance  789.950 → 0.000        (for the REAL invoice — the opening 500.000 is W4-4/W4-3)
```

**B.8 — W4-3 is unchanged, and it was verified WITHOUT executing the inverted payment.** Wave 4 established what
happens when the AP opening item is paid: `Dr 512 / Cr 411`, direction `in`, `receivable_balance −500.000`, the
`401` debt untouched, the document marked paid. Repeating that here would deliberately corrupt this tenant's
ledger and make every other number in §5 unreadable, so instead both links were re-verified as unchanged:

1. `Document/Application/Services/ArApOpeningService.php:160-165` — the mapper still has exactly two arms,
   `'invoice','inv' => DocumentType::Invoice` and `'credit_note','creditnote','cn' => DocumentType::CreditNote`,
   **no supplier-invoice arm**; and this run's AP batch duly minted `HIST-INV-2026-00002` with `type = invoice`
   against a `supplier` partner.
2. `Treasury/Presentation/Controllers/PaymentController.php:514` — supplier-ness is still decided solely by
   `$document->type === DocumentType::SupplierInvoice`, which such a document can never satisfy.

**KNOWN-OPEN, not re-reported.** The tenant's `411` is left clean so §5 reads true.

**B.9 — the supplier's three views, this run:**

| View | Says the supplier is owed |
|---|---|
| GL `401` net credit | **500.000** (the opening debt) |
| Supplier page (`payable_balance`) | **0.000** |
| Open AP document `HIST-INV-2026-00002` | 500.000, still `posted`, `balance_due 500.000` |
| Truth | **500.000** |

Better than wave 4 in one respect — the opening document is still *open* and correct, because the inverted
payment was not made — but the supplier **page** still reads 0.000 against a real 500.000 debt (**W4-4**).

### C — Branch transfers (Main Location → Boutique Ariana)

| # | Step | Wave 4 | **This run** | automated / needs manual |
|---|---|---|---|---|
| C.1 | Transfer A→B, ship, receive; stock + lots move | PASS | **PASS** | automated (API) |
| C.2 | FEFO enforcement on batch allocation | PASS (guard) | **PASS (guard)** | automated (API) |
| C.3 | Cancel an in-transit transfer | PASS | **PASS** | automated (API) |
| C.4 | No GL for an intracompany transfer | PASS | **PASS** | automated (API) |
| C.5 | Cost snapshot on transfer movements | OBSERVATION — W4-7 | **OBSERVATION — W4-7 unchanged** | automated (API) |

Both refusals fire before the happy path, verbatim as wave 4 recorded them:

```
no allocations                                 -> 422 INVALID_TRANSFER "Batch-tracked products require batch allocations."
20 from DEFAULT + 5 from LOT-SIRÔP-2026A       -> 422 INVALID_TRANSFER "Batch allocations must follow FEFO (earliest expiry first)."
```

`TR-2026-00001`, 25 × `SIRO-TOUX_150` from the earliest lot:

```
stock_levels    MAIN 90 → 65        ARIA 0 → 25
lots            MAIN DEFAULT 40→15 · LOT-A 30 · LOT-C 20     ARIA DEFAULT 25.0000, expiry 2027-08-25 preserved  ✅
inventory_batch_movements   DEFAULT −25 (transfer_out MAIN) / +25 (transfer_in ARIA)                           ✅
journal_entries with a transfer source_type  ->  0                                                             ✅
```

**C.3 — cancel.** `TR-2026-00002` (10 × `COMP-MAGN_60`): MAIN 70 → 60 in transit → cancel → **70 restored**, and
the lot ledger is restored too (`DEFAULT transfer_out −10` then `transfer_in +10`, both at MAIN, lot stock back
to 30 + 40 = 70). The accented reason stored intact.

**C.5 — W4-7 unchanged.** Both transfer legs still carry `unit_cost`, `total_cost`, `avg_cost_before`,
`avg_cost_after` = NULL while `opening` and `receipt` movements stamp them. Still a P3 observation; its
harmlessness is re-confirmed in F.5.

### D — POS: terminals, shift, cash sale, B2C refund, and the D-1 v5 arm

**Execution mode.** Every row is **API-contract, device run needed**. `POST /pos/receipts` is still retired
(**410 `NEW_SALE_AUTHORING_RETIRED`**), so the only live path is `POST /pos/sync/fiscal-events`. Wave 4's device
canonicaliser was re-validated before use — this time against **both** repo goldens:

```
sale-receipt-v4-refund-golden.json | re-encode match: true | sha256(canonical) == expected: true
sale-receipt-v5-golden.json        | re-encode match: true | sha256(canonical) == expected: true
```

Every event below came back `integrity_status = verified` (except the deliberate downgrade in D.11), so these
are genuine chain writes.

| # | Step | Wave 4 | **This run** | automated / needs manual |
|---|---|---|---|---|
| D.1 | Terminal refused at a non-POS location (B-3) | PASS | **PASS** | automated (API) |
| D.2 | Claim terminals at both POS branches | PASS | **PASS** | automated (API) |
| D.3 | PIN surfaces + offboarding cascade (**N-5**) | PASS | **PASS — N-5 stays fixed** | automated (API) |
| D.4 | Shift open (`SESSION_OPEN`, float 200.000) | PASS | **PASS** | API-contract, device run needed |
| D.5 | Cash sale, 3 VAT rates, hash-chained | PASS | **PASS** | API-contract, device run needed |
| D.6 | **Sale → GL, output VAT per rate** | **FAIL — W4-9 (P0)** | **PASS — W4-9 VERIFIED FIXED** | API-contract, device run needed |
| D.7 | **FEFO lot decrement on a POS sale** | FAIL — W4-5 | **FAIL — NEW W4R-2 (P1): the W4-5 fix is in the RETIRED path** | API-contract, device run needed |
| D.8 | B2C cash refund — chain, stock, drawer, COGS | PASS | **PASS** | API-contract, device run needed |
| D.9 | Refund GL carries the VAT reversal | FAIL (inherited W4-9) | **PASS — W4-9 VERIFIED FIXED on the refund arm too** | API-contract, device run needed |
| D.10 | X-report / Z-report / shift close | DEVICE-ONLY by design | **DEVICE-ONLY by design (unchanged)** | **needs manual (device)** |
| D.11 | **D-1: v5 discounted sale + forward version gate** | n/a (not merged) | **PASS — D-1 server side VERIFIED** | API-contract, device run needed |
| D.12 | Card / mixed / held order / offline resync / print | not run | **not run** | **needs manual (device)** |

**D.1–D.2 — the `pos_enabled` belt still fires first.**

```
POST /pos/terminals {location_id: <Dépôt Central, pos_enabled=false>}
 -> 422 LOCATION_POS_DISABLED  "POS is not enabled at this location. Enable POS for the location in Settings before using a terminal there."
```

Both POS branches claimed cleanly (`T-MAIN-01` = POS01, `T-ARIA-01` = POS02, both `fiscal_schema_version = 3`).

**D.3 — N-5 stays fixed, verified through the whole cascade:**

| Stage | `pin-data` | `has-pins` |
|---|---|---|
| no PIN yet | `[]` | **`false`** |
| PIN set, user still `pending_verification` | `[]` | **`false`** |
| user activated, PIN set, membership active | 1 row with `pin_hash` + roles + permissions | **`true`** |
| user deactivated (`status inactive`, `pos_pin` still on the row) | `[]` | **`false`** ✅ |

*Onboarding observation (not filed as a defect).* A cashier created through `POST /users` is born
`status = pending_verification`, and `PosAuthController::pinHolders()` (`:53-56`) filters on
`UserStatus::Active`. So the natural day-one sequence — create cashier → set PIN → walk to the till — leaves the
device reporting **no PINs at all**, with nothing in the product connecting the two facts. The belt is right;
the missing hint is the trap. The owner must press **Activate** on the user first.

**D.4–D.5 — shift open and the three-rate cash sale, both clean.**

```
SESSION_OPEN v1 | z_session seq 1 | previous_hash = pos_terminals.genesis_seed | verified
pos_shifts      shift #1 OPEN | opening_cash 200.0000 | terminal T-MAIN-01           ← projection created
SALE_RECEIPT v3 | operational seq 1 | verified
pos_receipts    FE-POS01-2026-00000001 | subtotal 400.000 | tax_amount 52.000 | total 452.000   ✅
pos_receipt_vat_details   7 % 100.000/7.000 · 13 % 200.000/26.000 · 19 % 100.000/19.000         ✅
stock_levels    SIRO 65→60 (−5)   MAGN 70→66 (−4)   GEL 60→58 (−2)                              ✅
drawer CASH-01  200.000 → 652.000   (repository_movements in 452.000, source_type fiscal_event) ✅
```

**D.6 — W4-9 IS FIXED, and the fix is exactly the right shape.** The GL entry the campaign called its most
consequential finding now reads:

```
GL  JE-2026-000008/9/10 (inventory_exit)  Dr 603 / Cr 37   42.500 + 36.000 + 9.000 = 87.500 at WAC     ✅
GL  JE-2026-000011 (pos_receipt)
      Dr  53   Caisse                    452.000        ← the GROSS tender, correctly
      Cr  707  Ventes de marchandises    400.000        ← NET, was 452.000 gross in wave 4
      Cr  4457 TVA collectée               7.000        ← per sealed rate, one line each
      Cr  4457 TVA collectée              26.000
      Cr  4457 TVA collectée              19.000
                                         452.000 = 452.000 ✅
```

`4457 TVA collectée`, which had **no journal line at all** in wave 4, now carries the receipt's sealed
three-rate split. The ledger and the DGI declaration finally agree (see F.2/F.4).

**Census clean.** The lane's own guard was run inside the tenant:

```
php artisan tenants:run pos:census-vat-legs --tenants=01a038cd-…
 -> "POS output-VAT leg census: none — every POS receipt carries its sealed output VAT in the ledger."
```

**D.7 — NEW W4R-2 (P1): the W4-5 fix landed in the RETIRED authoring path, so live POS sales still move no lot.**

The three-line sale decremented `stock_levels` correctly and left the lot ledger completely untouched:

```
pos_receipt_line_batch_allocations      0 rows
inventory_batch_movements               8 rows — receipts and transfers only, nothing from the sale
SIRO-TOUX_150 MAIN   stock_levels 60.0000   vs   Σ lots 15 + 30 + 20 = 65.0000     ← 5 units of drift, the sale
COMP-MAGN_60  MAIN   stock_levels 66.0000   vs   Σ lots 30 + 40      = 70.0000     ← 4 units
GEL-HYDR_500  MAIN   stock_levels 58.0000   vs   Σ lots            60.0000         ← 2 units
```

**Mechanism, and it is the reason this is worth its own id rather than "W4-5 still open".** The fix *is* in the
tree — `POS/Application/Services/ReceiptCreationService.php` allocates FEFO lots on the direct product line and
calls `consumeBatchesAtomically()` on composite leaves, with a docblock naming W4-5 explicitly (`:1414-1435`).
But `ReceiptCreationService` serves **`POST /pos/receipts`, which is retired — 410 `NEW_SALE_AUTHORING_RETIRED`**.
The live device-authored path projects through **`POS/Application/Projections/PosCoreReceiptProjection.php`**,
whose `decrementStockForLines()` / `decrementStock()` (`:1845`, `:1977`) touch `stock_levels` and
`stock_movements` only — the file contains **no FEFO call and no batch write of any kind**.

This is CLAUDE.md rule 20's own warning, realised: *"Retiring/gating a server endpoint: the client's fallback
path becomes the PRIMARY path — audit and test that path before shipping."* The lane fixed the channel that no
longer runs. For a vertical that forces batch tracking on every product, lot stock still only ever grows, expiry
control is still fiction, and there is still no which-lot-went-to-which-customer trail. (The document/DN half of
W4-5 is checked separately in §G — it behaves differently.)

**D.8 / D.9 — the refund is clean AND now carries its VAT reversal.** Second event on the same chain, sequence
2, `invoice_type_code = REFUND`, referencing the sale's fiscal event and receipt uuid:

```
fiscal_events   SALE_RECEIPT | seq 2 | operational | verified      ← chain continuous, no gap
pos_receipts    FE-POS01-2026-00000002 | receipt_type return | total 42.800 | tax 2.800
stock_levels    SIRO 60 → 62  (+2 back to the shelf)                                   ✅
drawer          652.000 → 609.200  (repository_movements out 42.800, source fiscal_event) ✅
GL  JE-2026-000012 (inventory_entry)      Dr 37 17.000 / Cr 603 17.000   (2 × 8.500 WAC) ✅
GL  JE-2026-000013 (pos_receipt_refund)   Dr 707 40.000 + Dr 4457 2.800 / Cr 53 42.800   ✅  ← was Dr 707 42.800 gross in wave 4
```

Revenue is reversed **net** and the output VAT is reversed with it. The sale and the refund are now mirror
images in the ledger as well as in the chain.

**D.11 — D-1's server side works, on both halves.** A second terminal (`T-ARIA-01`) was opened and a
**`SALE_RECEIPT` event_version 5** minted with a real transaction discount: two 19 % lines grossing 238.000, a
38.000 TND remise, tendered 200.000.

```
fiscal_events   SALE_RECEIPT | event_version 5 | operational seq 1 | verified
pos_receipts    FE-POS02-2026-00000001 | subtotal 168.067 | tax 31.933 | total 200.000 | discount_amount 38.000
pos_receipt_vat_details   19.00 | net 168.067 | vat 31.933 | gross 200.000 | **discount_allocated 38.000**   ← sealed
GL  JE-2026-000016 (pos_receipt)   Dr 53 200.000 / Cr 707 **168.067** (net OF the remise) + Cr 4457 31.933
                                   and NO 709 leg — exactly the v5 allocator contract                        ✅
```

**The forward version gate fires.** Replaying a `v3` sale onto that same chain after the v5 seal:

```
fiscal_events   SALE_RECEIPT | v3 | seq 2 | integrity_status **quarantined** | payload_parse_status **failed**
reason: …canonical_parse_failure:sale_receipt_version_downgrade:terminal=01a038dc-…:chain_context=operational:
        event_version=3:a chain that has authored the post-remise VAT base (v5) may never author the
        pre-discount base again
```

Nothing was projected — no fourth receipt, no GL, no stock. The gate refuses the downgrade and quarantines
rather than half-applying it.

*(Per the brief: **tills still seal v ≤ 4 until a POS build ships**, which is why the MAIN-terminal arm above
was authored at v3. That is a deploy-ordering fact, not a defect.)*

### E — Treasury: where the cash actually lands

| # | Step | Wave 4 | **This run** | automated / needs manual |
|---|---|---|---|---|
| E.1 | Drawer → safe remittance, float retained | PASS (no GL, shared account 53) | **PASS (same, no GL)** | automated (API) |
| E.2 | Safe → bank deposit + GL | PASS | **PASS** | automated (API) |
| E.3 | Expense paid from the drawer | **FAIL — W4-10 (P1)** | **PASS — W4-10 VERIFIED FIXED** | automated (API) |
| E.4 | Per-branch cash visibility (repository → location) | known N-12 | **known N-12 — sharper manifestation** | automated (DB) |
| E.5 | **GL cash vs repository balances reconcile** | **FAIL (off by 1155.000)** | **PASS — reconciles to zero** | automated (API) |

**E.1 — remittance, unchanged.** `609.200 + 200.000` in the drawer → remit `409.200` to the safe:

```
POST /payment-repositories/transfers  drawer → safe  409.200
 -> 201 { transfer_group_id: "0451e5a1…", journal_entry_id: **null**,
          out.balance_after "400.000", in.balance_after "909.200" }
```

`journal_entry_id` is still null on purpose — the seeded drawer and safe both point at GL `53`, so there is no
GL movement to make. The wave-4 observation stands verbatim: **as shipped, the ledger cannot tell the drawer
from the safe**, and `531 Caisse siège` exists in the TN chart. Still an owner provisioning decision.

**E.4 — N-12, and this run shows what it actually costs.** All three repositories still have
`location_id = NULL`. The consequence is no longer merely "cash cannot be attributed to a branch": the **Boutique
Ariana** terminal's 200.000 TND cash sale landed in **`CASH-01`, the Main Location drawer** —

```
CASH-01  in 452.000 (POS01, Main)   ·  out 42.800 (POS01 refund)  ·  in 200.000 (**POS02, Ariana**)
```

Two branches' takings commingle in one repository balance, so a per-branch cash count cannot reconcile against
anything. Known id, but the owner should read it as "one drawer for the whole company until repositories are
given locations."

**E.2 — the bank deposit posts, because the accounts differ.**

```
safe → bank 800.000  ->  GL JE-2026-000017 (treasury_transfer)  Dr 512 Banques 800.000 / Cr 53 Caisse 800.000  ✅
balances   safe 909.200 → 109.200      bank −789.950 → 10.050
```

**E.3 — W4-10 is fixed, and the guard is better than the old bug.** Creating the expense the obvious way — the
exact payload that in wave 4 silently produced `is_paid = true` with a NULL repository — is now **refused at the
entry point**:

```
POST /expenses {total "45.000", no repository}
 -> 422 EXPENSE_PAID_WITHOUT_REPOSITORY
    "An expense marked as paid in cash must name the payment repository the money left, so the till and the
     ledger move together. Choose a payment repository, choose a non-cash payment method, or leave the expense
     unpaid and settle it later."
```

Three named remedies, in the operator's language. With the drawer named, both legs move together:

```
EXP-2026-000001  45.000        GL JE-2026-000018  Dr 65 45.000 / Cr 53 45.000
                               repository_movements  CASH-01 out 45.000  balance_after 355.000  source_type **expense**  ✅
EXP-2026-000002  23.800 + VAT  GL JE-2026-000019  Dr 65 20.000 + Dr 4456 3.800 / Cr 53 23.800
                               repository_movements  CASH-01 out 23.800  balance_after 331.200  source_type expense      ✅
```

The drawer and GL `53` fall by the same amount at the same moment. *(A note for whoever reads the request shape:
the VAT field on `POST /expenses` is `vat_amount`, not `tax_amount`; the first expense above carries no VAT
split because the wrong key was sent — the request quietly accepts and drops an unknown key rather than
rejecting it. Cosmetic, recorded as an observation, not filed.)*

**E.5 — the reconciliation that was off by 1 155.000 in wave 4 now closes to zero.**

```
GL 53 Caisse net Dr                                  = 440.400
Sum of repositories backed by GL 53 (CASH-01 331.200 + SAFE-01 109.200) = 440.400      difference **0.000** ✅
GL 512 Banques net Dr                                =  10.050
BANK-01 balance                                      =  10.050                          difference **0.000** ✅
```

Wave 4's difference decomposed as `+1200.000` unseeded float (W4-2) `− 45.000` vanished expense (W4-10). Both
entry points are fixed, and the treasury spine — movements, ordinals, transfer groups, `balance_after`, the
`fiscal_event` source type on POS legs, and now `opening_balance` and `expense` — is internally consistent.

### F — Reports

| # | Step | Wave 4 | **This run** | automated / needs manual |
|---|---|---|---|---|
| F.1 | VAT declaration screen renders (**N-4**) | PASS | **PASS** | automated (UI) |
| F.2 | VAT split per rate incl. POS receipts, refund netted | PASS (but ledger disagreed) | **PASS — and the ledger now AGREES** | automated (UI) |
| F.3 | Input VAT completeness | FAIL (known B-19) | **FAIL (known B-19) — unchanged** | automated (UI) |
| F.4 | Trial balance closes | PASS (on two wrong numbers) | **PASS — and the numbers are right** | automated (API) |
| F.5 | Stock valuation per branch vs GL 37 | PASS | **PASS** | automated (API) |

**F.1/F.2 — the declaration and the ledger finally state the same thing.**
`w4r-f01-vat-declaration-matches-gl-w4-9-fixed.png`:

| Rate | Base (HT) | VAT | Documents |
|---|---|---|---|
| 7,00 | 60,000 | 4,200 | 2 |
| 13,00 | 200,000 | 26,000 | 1 |
| 19,00 | 268,067 | 50,933 | 2 |
| **Total** | **528,067** | **81,133** | **5** |

Cross-checked against the GL: `4457 TVA collectée` net credit = **81.133** and `707 Ventes` net credit =
**528.067** — identical to the millime. The 7 % row is still `100.000 − 40.000` base with the refund netted, and
the 19 % row includes the v5 **post-remise** base (100 + 168.067). In wave 4 this table was right while `4457`
had no journal line at all; that gap is closed.

**F.3 — B-19 reproduced, unchanged.** Input VAT reads **3,800** (the second expense only). `4456 TVA déductible`
carries **148.750**; the supplier invoice's **144.950** is still absent from the declaration. Known, not
re-reported — but note the tenant would under-reclaim 144.950 TND on a single purchase.

**F.4 — trial balance closes: `Dr 9210.500 = Cr 9210.500`,** and unlike wave 4 the constituent numbers are
right:

| Account | Dr | Cr | Net | vs wave 4 |
|---|---|---|---|---|
| 119 Solde d'ouverture | 0.000 | 3217.500 | −3217.500 | |
| 37 Stocks de marchandises | 3529.500 | 187.500 | 3342.000 | |
| 401 Fournisseurs | 1289.950 | 1789.950 | −500.000 | the opening debt, correctly still standing |
| 408 Fournisseurs FNP | 1145.000 | 1145.000 | 0.000 | |
| 411 Clients | 150.000 | 0.000 | **+150.000** | wave 4: −350.000, polluted by W4-3 |
| 4456 TVA déductible | 148.750 | 0.000 | 148.750 | |
| **4457 TVA collectée** | 2.800 | 83.933 | **−81.133** | wave 4: **account absent** ← W4-9 fixed |
| 512 Banques | 800.000 | 789.950 | 10.050 | |
| 53 Caisse | 1852.000 | 1411.600 | 440.400 | reconciles exactly to the tills |
| 603 Variation des stocks | 187.500 | 17.000 | 170.500 | |
| 65 Autres charges | 65.000 | 0.000 | 65.000 | |
| **707 Ventes de marchandises** | 40.000 | 568.067 | **−528.067** | wave 4: gross ← W4-9 fixed |

**F.5 — stock valuation reconciles exactly.** Per-branch on-hand × `cost_price`: **ARIA 940.000 + MAIN
2402.000 = 3342.000**, and `GL 37` net is **3342.000**. W4-7 (no cost snapshot on transfer legs) still does not
corrupt valuation, which is why it stays P3.

### G — Inventory counting **while sales are running** (the owner's addendum)

| # | Step | Wave 4 | **This run** | automated / needs manual |
|---|---|---|---|---|
| G.1 | Snapshot semantics — frozen at activation vs live | PASS (frozen) | **PASS (frozen, and now re-read at apply)** | automated (API) |
| G.2 | **POS sale** during the open count | document sale only | **PASS — a real device-authored POS sale** | API-contract, device run needed |
| G.3 | Enter counts: exact / short / over | PASS | **PASS** | automated (API) |
| G.4 | Terminal-sync ack + double-finalize / cancel-after-finalize refusals | PASS | **PASS** | automated (API) |
| G.5 | **Adjustments applied; in-count sale counted once** | **FAIL — W4-6 (P1)** | **PASS — W4-6 VERIFIED FIXED** | automated (API) |
| G.6 | Branch B untouched; `stock_levels.reserved` untouched | PASS | **PASS** | automated (API) |
| G.7 | **Count report: expected / counted / variance / applied** | **FAIL — summary contradicted its own data** | **PASS — W4-6 VERIFIED FIXED, API + UI** | automated (UI) |
| G.8 | Per-lot counting for a batch-tracked product | NOT SUPPORTED — W4-8 | **NOT SUPPORTED — W4-8 STILL OPEN (not merged)** | automated (code) |
| G.9 | **Count-correction GL posting (P-1)** | OFF by default, no GL | **PASS — P-1 VERIFIED: ON by default, posts 6586 / 7586** | automated (API) |
| G.10 | W2-7 phantom `DEFAULT` batch on outbound | **new manifestation: DEFAULT overwritten 15 → 65** | **PASS — W2-7 VERIFIED FIXED** | automated (API) |
| G.11 | W4-5 lot decrement on the **document/DN** arm | FAIL | **PASS — W4-5 VERIFIED FIXED on this arm** | automated (API) |

**G.10 / G.11 — the document arm of W2-7 and W4-5 is genuinely fixed.** A delivery note for 4 × `SIRO-TOUX_150`
was confirmed at MAIN, with the lot ledger read immediately before and after:

```
before confirm   MAIN DEFAULT 15.0000 · LOT-SIRÔP-2026A 30.0000 · LOT-SIRÔP-2026C 20.0000
after  confirm   MAIN DEFAULT **11.0000** · LOT-A 30.0000 · LOT-C 20.0000
inventory_batch_movements   DEFAULT | **issue** | **−4.0000** | MAIN          ← the lot ledger moved
stock_levels.reserved       0.0000 everywhere (no phantom reservation)
```

Two wave-4 findings close at once. **W2-7:** the `DEFAULT` lot was *not* rewritten to the aggregate — it stayed
the untracked remainder and simply fell by the issued quantity, which is exactly the "DEFAULT lot = untracked
remainder" contract. **W4-5 (document channel):** a confirmed outbound document now writes an `issue` batch
movement, where wave 4 produced none. FEFO picked `DEFAULT` because its (invented, W4-1) 2027-08-25 expiry is
the earliest on the product — the ranking rule is right, the date it ranks on is still W4-1's fiction.

**G.1–G.2 — the snapshot is frozen, and a real POS sale ran inside the window.** `CNT-2026-0002` (scope
`location` = Main Location) was activated while **both** POS shifts were open, then a device-authored
`SALE_RECEIPT` (3 × `SIRO-TOUX_150`, seq 3 on the MAIN chain) was minted mid-count:

```
                snapshot (theoretical_qty)   live stock_levels
SIRO-TOUX_150            58.0000                55.0000        ← the in-count sale, snapshot unmoved
COMP-MAGN_60             66.0000                66.0000
CREM-SOLA_50 / GEL-HYDR_500 / HUIL-ARGA_100   unchanged
```

**G.4 — the guards are all still there, and the acknowledgement path now completes.** The first finalize is
refused exactly as in wave 4:

```
POST …/finalize {}
 -> 422 TERMINAL_SYNC_ACKNOWLEDGEMENT_REQUIRED
    terminal_sync_health: { requires_acknowledgement: true, acknowledgement_signature: "3e165c5f…",
                            stale_after_seconds: 300, terminals: [ POS01 / T-MAIN-01, state "unknown" ] }
```

Re-posting with `acknowledge_terminal_sync_risk: true` + the signature succeeded. Afterwards all three terminal
transitions are refused with the typed `COUNTING_TRANSITION_REFUSED`, naming current and attempted status:

```
finalize again  -> "This counting is finalized and cannot move to finalized."
cancel          -> "This counting is finalized and cannot move to cancelled."
submit a count  -> "This counting is finalized and cannot move to count_1_in_progress."
```

**G.5 — W4-6 IS FIXED. The behaviour is the exact inverse of wave 4's.**

| SKU | snapshot | expected **at apply** | counted | true variance | stock after finalize | applied? |
|---|---|---|---|---|---|---|
| `SIRO-TOUX_150` | 58.0000 | **55.0000** (re-read) | 55.0000 | 0 — the −3 is entirely the in-count sale | **55.0000** ✅ | correctly nothing to apply |
| `COMP-MAGN_60` | 66.0000 | 66.0000 | 64.0000 | **−2 real shrinkage** | **64.0000** ✅ | **YES** |
| `HUIL-ARGA_100` | 40.0000 | 40.0000 | 42.0000 | **+2 real gain** | **42.0000** ✅ | **YES** |
| `CREM-SOLA_50` | 25.0000 | 25.0000 | 25.0000 | 0 | 25.0000 | no movement written |
| `GEL-HYDR_500` | 58.0000 | 58.0000 | 58.0000 | 0 | 58.0000 | no movement written |

```
stock_movements   COMP-MAGN_60  | adjustment | −2.0000 | 66.0000 → 64.0000 | reason **count_correction**
                  HUIL-ARGA_100 | adjustment | +2.0000 | 40.0000 → 42.0000 | reason **count_correction**
                  (and NOTHING else — wave 4 wrote no-op movements for the zero-variance items and nothing for the real ones)
```

Both halves are right this time: the in-count sale is still counted **exactly once** (SIRO ended at 55, not 52),
*and* the two genuine variances were applied. The `basket_window` flag survives as an **advisory** on the SIRO
row rather than a blocker — the replay audit shows the mechanism:

```
SIRO  replay_audit {"onHandAtApply":"55.0000","replayedDelta":"0.0000","expectedAtApply":"55.0000"}   → variance_qty 0.0000
MAGN  replay_audit {"onHandAtApply":"66.0000","replayedDelta":"0.0000","expectedAtApply":"64.0000"}   → variance_qty −2.0000, applied
```

**The count adjustments also reach the lot ledger**, through the untracked remainder:
`COMP-MAGN_60 DEFAULT 30 → 28`, `HUIL-ARGA_100 DEFAULT 20 → 22`.

**G.7 — the report no longer contradicts itself, and it answers the wave-4 complaint directly.**
`w4r-g07-counting-report-variance-applied-w4-6-fixed.png`:

```
summary  total_items_counted 5   items_no_variance 3   items_with_variance **2**       (wave 4: 0)
         total_variance_value { positive "36.000", negative "-18.000", net "18.000" }  (wave 4: all 0.000)
         **items_applied 2   items_not_applied 0**   late_sales_corrections 0          (fields did not exist)
```

The per-item rows carry the full set the fix lane promised — `theoretical_qty`, `expected_qty` (re-read at
apply), `counted_qty`, `final_qty`, `variance`, `variance_qty`, **`variance_applied`**, **`gl_posted`**,
`not_applied_reason`, `gl_not_posted_reason`, `replay_audit` — and the UI table renders exactly
**Product | Location | Expected | Counted | Var. | Applied | Reason**, every row reading **Applied**. Wave 4's
sentence — *"no field anywhere says these adjustments were not applied to stock"* — is answered by two new
fields and a column.

*(Unchanged cosmetic, re-observed: `flag_reason` (singular) and `flag_reasons[]` still disagree on the same row
— SIRO shows `flag_reason: "significant_variance"` while `flag_reasons: ["basket_window"]`, and the UI's Reason
column prints the singular one. Also `CREM-SOLA_50` reads `resolution_method: auto_all_match` while
`COMP-MAGN_60` reads `auto_counters_agree` on a single-count count. Neither affects stock or GL.)*

**G.9 — P-1 verified: count-correction GL posting is ON by default and posts the Option A accounts.**
`config/inventory.php:35` now reads `env('INVENTORY_COUNT_CORRECTION_GL_POSTING_ENABLED', true)`, and on a
default tenant with no overrides both variances posted:

```
JE-2026-000023 (inventory_shrinkage)  Dr **6586** Écarts d'inventaire — manquants et pertes  18.000 / Cr 37 18.000
                                      (2 × 9.000 WAC — the COMP-MAGN_60 shortage)
JE-2026-000024 (inventory_shrinkage)  Dr 37 36.000 / Cr **7586** Écarts d'inventaire — excédents  36.000
                                      (2 × 18.000 WAC — the HUIL-ARGA_100 overage)
```

Smoke-sheet row 7.5's *"variance GL"* now happens on a default tenant. Both accounts exist in the seeded TN
chart, and `gl_posted: true` on every report row.

**G.6 — isolation is correct.** Boutique Ariana is untouched by the Main Location count
(`GEL-HYDR_500` 15, `LAIT-CORP_400` 30, `SIRO-TOUX_150` 25, `THER-DIGI_01` 15 — the reductions there are the v5
POS sale, not the count), and `stock_levels.reserved` is `0.0000` on every row in the tenant.

**G.8 — W4-8 unchanged, as expected (lane not merged).** `inventory_counting_items` still has **no `batch_id` or
any lot column**, so a product carrying three lots with three expiry dates still takes one aggregate number and
no variance can be attributed to a lot. Smoke-sheet row 7.3's per-lot half remains not implementable.

**The lot-drift census — the one number that isolates what is left.** After the whole day (opening, receipts,
transfers, a DN issue, POS sales and a refund, and the count adjustments), `stock_levels` vs Σ lots per
product/location:

| SKU | Location | `stock_levels` | Σ lots | drift | explained by |
|---|---|---|---|---|---|
| GEL-HYDR_500 | ARIA | 15.0000 | 25.0000 | **−10** | v5 POS sale (10 units) |
| SIRO-TOUX_150 | MAIN | 55.0000 | 61.0000 | **−6** | POS 5 + 3 sold − 2 refunded |
| LAIT-CORP_400 | ARIA | 30.0000 | 35.0000 | **−5** | v5 POS sale (5 units) |
| COMP-MAGN_60 | MAIN | 64.0000 | 68.0000 | **−4** | POS sale (4 units) |
| GEL-HYDR_500 | MAIN | 58.0000 | 60.0000 | **−2** | POS sale (2 units) |
| CREM-SOLA_50 / HUIL-ARGA_100 / SIRO-TOUX_150 (ARIA) / THER-DIGI_01 | | | | **0.0000** | ✅ |

**Every unit of drift in this tenant — all 27 — is a live POS sale or refund, and nothing else.** Opening,
goods receipts, transfers, the delivery-note issue and the count corrections all keep the lot ledger truthful.
That is the precise scope of **W4R-2**, and the precise measure of how much of W4-5/W2-7 did land.

---

## 2. PASS / FAIL table — this run vs wave 4

| # | Flow | Wave 4 | **This run** | automated / needs manual |
|---|---|---|---|---|
| A.0 | Signup over a stale company selection (W2-1) | FAIL | **PASS — fixed** | automated |
| A.1–A.3 | Products import, accents, 3 VAT rates, stock per branch, same SKU in two branches | PASS | **PASS** | automated |
| A.3b | Import creates categories (W2-3) | FAIL | **PASS — fixed** | automated |
| A.4 | Lots/expiry on opening stock | FAIL — W4-1 | **FAIL — W4-1 still open** | automated |
| A.5 | Treasury opening float (drawer / safe) | **FAIL — W4-2 (P0)** | **PASS — fixed** | automated |
| A.6 | GL opening batch through the wizard (N-3) | PASS | **PASS** | automated |
| A.7–A.8 | AR / AP opening documents | PARTIAL / W4-3 | **PARTIAL / W4-3 KNOWN-OPEN** | automated |
| A.9 | Partner balance pages reflect the openings | FAIL — W4-4 | **FAIL — W4-4 KNOWN-OPEN** | automated |
| A.10 | Opening-balance wizard Lock step | not exercised | **FAIL — NEW W4R-1 (P3)** | automated |
| B.1 | PO unit price = purchase price (W2-6) | not re-tested | **PASS — fixed** | automated (UI) |
| B.2–B.5 | PO → confirm → partial GRN → remainder → supplier invoice → GL | PASS | **PASS** | automated |
| B.6 | Pay supplier **cash from the safe** | **BLOCKED by W4-2** | **PASS — unblocked** | automated |
| B.7 | Pay supplier by bank transfer | PASS | **PASS** | automated |
| B.8 | Pay the AP opening item | FAIL — W4-3 (P0) | **STILL OPEN — W4-3 (verified by shape + code, not executed)** | automated (code) |
| B.9 | Supplier balance = opening + invoices − payments | FAIL — W4-4 | **STILL OPEN — W4-4** | automated |
| C.1–C.4 | Transfer A→B, FEFO guard, cancel, no GL | PASS | **PASS** | automated |
| C.5 | Cost snapshot on transfer movements | OBSERVATION — W4-7 | **OBSERVATION — W4-7 unchanged** | automated |
| C.6 | Receive less than shipped (discrepancy path) | not run | **not run** | **needs manual** |
| D.1–D.2 | Terminal refused at a non-POS location; claim both branches | PASS | **PASS** | automated |
| D.3 | PIN surfaces + offboarding cascade (N-5) | PASS | **PASS** | automated |
| D.4–D.5 | Shift open; 3-rate cash sale — chain, stock, drawer | PASS | **PASS** | API-contract |
| D.6 | **Sale → GL, output VAT per rate** | **FAIL — W4-9 (P0)** | **PASS — fixed** | API-contract |
| D.7 | **FEFO lot decrement on a POS sale** | FAIL — W4-5 | **FAIL — NEW W4R-2 (P1)** | API-contract |
| D.8–D.9 | B2C cash refund — chain, stock, drawer, COGS **and VAT reversal** | PASS / FAIL(GL) | **PASS / PASS** | API-contract |
| D.10 | X-report / Z-report / shift close | DEVICE-ONLY by design | **DEVICE-ONLY by design** | **needs manual (device)** |
| D.11 | D-1: v5 discounted sale + forward version gate | n/a | **PASS** | API-contract |
| D.12 | Card, mixed, held order, void, print, offline resync | not run | **not run** | **needs manual (device)** |
| D.13 | B2B on-account sale at the POS + customer payment | not run | **not run** (`ACCOUNT_CHARGE` / `ACCOUNT_PAYMENT` are device-authored) | **needs manual (device)** |
| E.1 | Drawer → safe remittance, float retained | PASS (no GL) | **PASS (no GL)** | automated |
| E.2 | Safe → bank deposit + GL | PASS | **PASS** | automated |
| E.3 | Expense paid from the drawer | **FAIL — W4-10 (P1)** | **PASS — fixed** | automated |
| E.4 | Per-branch cash visibility | known N-12 | **known N-12 — sharper** | automated |
| E.5 | **GL cash vs repository balances reconcile** | **FAIL (off 1155.000)** | **PASS (off 0.000)** | automated |
| F.1–F.2 | VAT declaration renders; split per rate; refund netted | PASS (ledger disagreed) | **PASS — and ledger AGREES** | automated (UI) |
| F.3 | Input VAT completeness | FAIL (known B-19) | **FAIL (known B-19)** | automated (UI) |
| F.4 | Trial balance closes | PASS (on wrong numbers) | **PASS (on right numbers)** | automated |
| F.5 | Stock valuation per branch vs GL 37 | PASS | **PASS** | automated |
| G.1–G.4 | Count snapshot, in-count POS sale, counts entered, transition guards | PASS | **PASS** | automated + API-contract |
| G.5 | **Adjustments applied; in-count sale counted once** | **FAIL — W4-6 (P1)** | **PASS — fixed** | automated |
| G.6 | Branch B untouched; `reserved` untouched | PASS | **PASS** | automated |
| G.7 | **Count report expected/counted/variance/applied** | **FAIL — W4-6** | **PASS — fixed (API + UI)** | automated (UI) |
| G.8 | Per-lot counting | NOT SUPPORTED — W4-8 | **NOT SUPPORTED — W4-8 still open** | automated (code) |
| G.9 | **Count-correction GL (P-1)** | OFF by default | **PASS — ON by default, 6586 / 7586** | automated |
| G.10 | W2-7 phantom `DEFAULT` batch | FAIL (overwrite 15 → 65) | **PASS — fixed** | automated |
| G.11 | W4-5 lot decrement, **document/DN** arm | FAIL | **PASS — fixed** | automated |

---

## 3. FIXED-VERIFIED — with the evidence

| Id | Was | Verified fixed by |
|---|---|---|
| **W4-9** (P0) — POS booked gross revenue, no output VAT | `Dr 53 452.000 / Cr 707 452.000`, `4457` absent from the whole tenant | `JE-2026-000011`: `Dr 53 452.000 / Cr 707 **400.000** + Cr 4457 **7.000 / 26.000 / 19.000**` — one line per sealed rate. Refund `JE-2026-000013`: `Dr 707 40.000 + Dr 4457 2.800 / Cr 53 42.800`. `tenants:run pos:census-vat-legs` → *"none — every POS receipt carries its sealed output VAT in the ledger."* Declaration 85,333 = GL `4457` net 85.333 to the millime. |
| **W4-2** (P0) — no working path to an opening cash float | drawer/safe stuck at 0.000, every route refused or no-op'd | GL opening template gained `repository_code`; Preview renders a **Cash repository** column ("Seeds this repository's opening float"); posting produced `CASH-01 200.000` + `SAFE-01 1000.000` with two `repository_movements` rows `source_type = opening_balance` **carrying `journal_entry_id`**. Two tills on one GL account both seeded from two rows. `w4r-a05-…png` |
| **W4-10** (P1) — expense born paid, never touched a repository | `Dr 65 + Dr 4456 / Cr 53` with no repository movement, `/pay` then refused forever | `POST /expenses` with no repository → **422 `EXPENSE_PAID_WITHOUT_REPOSITORY`** naming three remedies. With the drawer named: `EXP-2026-000001/2` post `Dr 65 (+ Dr 4456) / Cr 53` **and** write `repository_movements … source_type = expense`; drawer falls by the same amount. |
| **W4-6** (P1) — count finalized, reported "no variance", applied nothing | every real variance suppressed as `basket_window`; only no-op movements written; summary said `items_with_variance: 0` | Real −2 and +2 **applied** (66→64, 40→42) with `reason = count_correction`; in-count POS sale still counted exactly once (55, not 52); zero-variance items write no movement; report summary `items_with_variance 2`, `net 18.000`, **new `items_applied 2` / `items_not_applied 0`**; UI table columns **Expected / Counted / Var. / Applied / Reason**. `w4r-g07-…png` |
| **P-1** — count-correction GL posting OFF by default | `config/inventory.php` shipped `false`; no journal entry for any variance | `config/inventory.php:35` now defaults **true**; on a default tenant `JE-2026-000023` `Dr **6586** / Cr 37 18.000` and `JE-2026-000024` `Dr 37 / Cr **7586** 36.000`; `gl_posted: true` on every report row. |
| **W2-7** — phantom/overwritten `DEFAULT` batch | confirm rewrote `DEFAULT` 15.0000 → 65.0000, lot ledger 115 vs `stock_levels` 65 | DN confirm left `DEFAULT` as the untracked remainder: `15.0000 → 11.0000`, no rewrite, `reserved` 0.0000, Σ lots = `stock_levels` on every non-POS line. |
| **W4-5** — outbound never decremented a lot (**document arm only**) | a confirmed DN produced no batch movement | DN confirm wrote `inventory_batch_movements: DEFAULT | issue | −4.0000 | MAIN`. **POS arm is NOT fixed — see W4R-2.** |
| **W2-1** — stale company selection deadlocked signup | 76 console errors, every authenticated call 403 | Signed up over the wave-4 tenant's `autoerp-company-selection`; it was rewritten to the new company, `/reports` rendered, **1** console error (the pre-auth 401). `w4r-a00-…png` |
| **W2-3** — import dropped categories | `categories` = 0, every `category_id` NULL, no warning | 6 categories created with accents; `category_id` non-NULL on all 7 products. |
| **W2-6** — PO unit price defaulted to the sale price | 14.900 (sale) offered on a purchase line | UI PO line defaults to **8.500** with the caption **"From purchase price"**. `w4r-b01-…png` |
| **D-1** (server side) — post-discount VAT base | n/a | `SALE_RECEIPT` **v5** accepted `verified`; `pos_receipt_vat_details.discount_allocated = 38.000` sealed; `Cr 707 168.067` **net of the remise** + `Cr 4457 31.933`, **no 709 leg**; a v3 replay on that chain was **quarantined** with `sale_receipt_version_downgrade:… may never author the pre-discount base again`. |
| **N-5** — `has-pins` ignored the offboarding belt | `true` after the cashier was deactivated | Four-stage cascade re-run; `has-pins` and `pin-data` admit the same population at every stage, `false` after deactivation. |
| **N-1 / N-2 / N-3 / N-4 / N-7** | wave-1 defects | Re-confirmed on this fresh tenant: mixed 7/13/19 % correct on import, PO, GRN, supplier invoice, POS receipt (v3 and v5) and the DGI declaration; no missing-`StockLevel` 404 anywhere; the opening-balance wizard reaches Preview/Post in proper English; the VAT declaration page renders. |
| **N-6** (payment on an unposted invoice) | ruled | **not triggered** this run — no payment was taken against an unposted sales invoice. Not evidence either way. |

---

## 4. STILL-OPEN — expected, recorded, not re-reported

| Id | Sev | State on this build |
|---|---|---|
| **W4-3** | P0 | **KNOWN-OPEN, lane not merged.** `ArApOpeningService.php:160-165` still maps only `Invoice` / `CreditNote` — no supplier-invoice arm; this run's AP batch minted `HIST-INV-2026-00002` `type = invoice` against a *supplier* partner. `PaymentController.php:514` still decides supplier-ness by `type === SupplierInvoice`. **The inverted payment was deliberately NOT executed** so the tenant's `411` stays clean and §5 is readable. Note for the lane: the FE template comment already claims *"W4-3 refuses a supplier balance entered as a bare GL credit"*, but a bare `401` credit of 500.000 in the ACCOUNTING batch **posted with no refusal** on this build — confirm that guard ships with the fix. |
| **W4-4** | P1 | **KNOWN-OPEN.** Both partner pages read `receivable_balance 0.000` / `payable_balance 0.000` against a live 150.000 receivable and a live 500.000 payable. The ACCOUNTING opening template still has no partner column, so both opening lines posted `partner_id = NULL`; AR/AP batches still post no GL by design. |
| **W4-1** | P1 | Unchanged. All 7 opening lots are `DEFAULT` with an invented `2027-08-25` (= cutover + 365). Compounds with FEFO: the fabricated date is the earliest on the product, so both the transfer guard and the DN allocation *compel* shipping it first. |
| **W4-8** | P2 | Unchanged. `inventory_counting_items` still has no `batch_id`/lot column; per-lot counting is not implementable. |
| **W4-7** | P3 | Unchanged. `transfer_out` / `transfer_in` carry NULL `unit_cost` / `total_cost` / `avg_cost_*`. Harmless: valuation reconciles exactly (F.5). |
| **B-19** | — | Reproduced. Input VAT on the declaration = **3,800** (the expense) while `4456` carries **148.750**; the supplier invoice's 144.950 is absent. |
| **N-12** | — | Reproduced, **sharper**: all repositories have `location_id = NULL`, and the **Ariana** terminal's 200.000 cash sale landed in the **Main** drawer. The dashboard prints it plainly: *By location — **Unattributed 514,650 TND***. Two branches share one drawer. `w4r-e04-…png` |
| **W2-5** | — | Reproduced: `default_tax_configuration_id` NULL on all 7 imported products (the `tax_rate` itself is correct). Surfaces in the PO form as a Tax combobox reading "Select tax…" while the line total is nonetheless taxed correctly. |
| **N-9** | — | Reproduced: `units` = 0 on a fresh tenant. |
| **N-14** | — | **Reproduced this run** (wave 4 recorded "not reproduced"). Abandoning the PO form after adding one line auto-saved `PO-2026-0001` as a `draft` with `total 0.000`, burning the number; the real order became `PO-2026-0002`. |

---

## 5. NEW defects

### W4R-2 — **P1** — The W4-5 fix landed in the RETIRED authoring path, so live POS sales still move no lot

`POS/Application/Services/ReceiptCreationService.php` does allocate FEFO lots on the direct product line and
calls `consumeBatchesAtomically()` on composite leaves, with a docblock naming W4-5 explicitly (`:1414-1435`).
But that service serves **`POST /pos/receipts`, which is retired — 410 `NEW_SALE_AUTHORING_RETIRED`** (verified
live this run). The only path a till can use, `POST /pos/sync/fiscal-events`, projects through
**`POS/Application/Projections/PosCoreReceiptProjection.php`**, whose `decrementStockForLines()` /
`decrementStock()` (`:1845`, `:1977`) touch `stock_levels` and `stock_movements` only — **no FEFO call, no batch
write of any kind** anywhere in the file.

Evidence: after four device-authored POS receipts, `pos_receipt_line_batch_allocations` = **0 rows** and
`inventory_batch_movements` holds only receipt / transfer / DN-issue rows. The lot-drift census (§G) is exact:
**27 units of drift across 5 product/location pairs, and every single unit is a POS sale or refund** — nothing
else in the tenant drifts.

This is CLAUDE.md rule 20's own warning realised: *"Retiring/gating a server endpoint: the client's fallback
path becomes the PRIMARY path — audit and test that path before shipping."* For a vertical that forces batch
tracking on every product, lot stock still only ever grows, expiry control is still fiction, and there is still
no which-lot-went-to-which-customer trail — the record a recall needs. **The DN/document half of W4-5 IS fixed;
only the POS channel is affected.**

### W4R-1 — **P3** — The opening-balance wizard's final Lock step is a dead end

Posting an opening batch auto-locks it in the same transaction (`status = LOCKED`, `locked_at 12:08:41`) —
correct. The wizard then advances to step 6 "Lock Batch", whose button can only ever fail:

```
POST /companies/{co}/opening-batches/{batch}/lock
 -> 422 BATCH_LOCK_FAILED  "Cannot lock batch in Locked status. Batch must be validated first."
```

Nothing is surfaced in the UI — no toast, no error state — and the wizard never reaches a Done step. Cosmetic in
effect, but it is the last screen of the very first thing an onboarding tenant does, and the message it hides
("must be validated first") is misleading about an already-locked batch.

### Observations (not filed as defects)

- **Raw i18n key on the opening-balance upload step.** The `repository_code` "(optional)" hint renders as
  `openingBalances.upload.optionalColumn` (`FileUpload.tsx:212`). The key exists at `en/common.json:639`, so
  this is a namespace-resolution slip — same class as N-7.
- **New cashiers are invisible to the POS PIN roster until activated.** `POST /users` creates
  `status = pending_verification`; `PosAuthController::pinHolders()` (`:53-56`) filters on `UserStatus::Active`.
  Create cashier → set PIN → walk to the till leaves the device reporting *no PINs*, with nothing connecting the
  two facts. The belt is right; the missing hint is the trap.
- **The GL opening upload accepts comma-delimited CSV only** while the Products import accepts semicolons; a
  semicolon file is refused with *"Missing required columns: account_code, debit, credit, reference"*, which is
  accurate but never names the delimiter. And **re-selecting a file of the same name after fixing it does not
  re-parse** — the wizard keeps showing the previous error until the file is renamed.
- **`POST /expenses` silently drops an unknown key**: the VAT field is `vat_amount`; sending `tax_amount`
  produced an expense with no VAT split and no warning.
- **Drawer and safe still share GL account `53`** (unchanged provisioning), so a drawer→safe remittance posts no
  journal entry and cash location is invisible in the ledger. `531 Caisse siège` exists in the TN chart. Owner
  decision, as wave 4 said.
- **Counting report flag fields still disagree**: `flag_reason` (singular, `"significant_variance"`) vs
  `flag_reasons[]` (`["basket_window"]`) on the same row; the UI prints the singular one. Also
  `resolution_method` reads `auto_all_match` on one zero-variance row and `auto_counters_agree` on another in a
  single-count count. Neither affects stock or GL.

---

## 6. Balances sanity — computed vs shown vs GL

All amounts read as strings from PG at end of run.

| Entity | Computed (truth) | Shown on its screen | GL account | Agree? |
|---|---|---|---|---|
| **Supplier** `Laboratoires Méditerranée SA` | opening 500.000 + SI 1289.950 − cash 500.000 − bank 789.950 = **500.000** | **0,000 TND** (Total Payable) | `401` net Cr **500.000** | ❌ page ≠ GL — **W4-4** (KNOWN-OPEN). *GL and truth now AGREE* (wave 4: all three differed) |
| **Customer** `Nadia Chaâbane` | opening 150.000 invoiced + 63.772 delivered-not-invoiced (DN-2026-0001) = **150.000 receivable** | **0,000 TND** (Total Receivable) | `411` net **+150.000** | ❌ page ≠ GL — **W4-4**. *GL is CLEAN* (wave 4: −350.000, polluted by W4-3) |
| **Drawer** `CASH-01` | float 200.000 + POS 452.000 − refund 42.800 + ARIA sale 200.000 + in-count sale 64.200 − remittance 409.200 − expenses 68.800 = **395.400** | **395,400 TND** | shares `53` | ✅ **exact** (caveat: the ARIA 200.000 should not be here — N-12) |
| **Safe** `SAFE-01` | opening 1000.000 + remittance 409.200 − supplier cash 500.000 − deposit 800.000 = **109.200** | **109,200 TND** | shares `53` | ✅ **exact** |
| **Bank** `BANK-01` | −789.950 supplier payment + 800.000 deposit = **10.050** | **10,050 TND** | `512` net **10.050** | ✅ **exact, all three** |
| **Cash reconciliation** | GL `53` net Dr **504.600** vs repositories on `53` (395.400 + 109.200) = **504.600** | | | ✅ **difference 0.000** (wave 4: 1155.000) |
| **Stock valuation** | Σ qty × cost = ARIA 940.000 + MAIN 2360.500 = **3300.500** | per-branch stock screens match | `37` net **3300.500** | ✅ **exact** |
| **Output VAT** | receipts seal **85.333** | declaration shows **85,333** | `4457` net **85.333** | ✅ **all three agree** (wave 4: `4457` absent) |
| **Trial balance** | — | — | Dr **9388.200** = Cr **9388.200** | ✅ balanced, **and the constituents are right** |
| **Lot ledger** | Σ lots vs `stock_levels` | | | ❌ 27 units of drift, **100 % of it live POS sales** — **W4R-2** |

**The single sentence for the owner, inverted from wave 4:** four of the five money entities now show a number
the operator can trust and reconcile to the ledger to the millime; the two that do not are the **partner pages**,
and they are blocked on the one lane (W4-3/W4-4) the owner has not yet ruled on.

---

## 7. Smoke-sheet mapping — what changed for the owner's bench time

Rows the sheet marked **FAIL / BLOCKED** in wave 4 that are now **PASS** and need only a spot-check:

| Sheet row | Wave 4 | Now |
|---|---|---|
| 0.2 opening float drawer 200 / safe 1 000 | **FAIL W4-2, "no working path exists"** | **PASS** — via the ACCOUNTING batch's `repository_code` column |
| 1.4 pay supplier from the SAFE in cash | **BLOCKED by W4-2** | **PASS** |
| 3.6 B2C refund (contract half) | PASS but **GL carried no VAT** | **PASS incl. the VAT reversal** |
| 5.3 expense paid from the drawer | **FAIL W4-10** | **PASS** — and the bad path is now refused with a typed error |
| 7.5 count variance applied + GL | **FAIL W4-6**, GL posting off | **PASS** — variances applied, `6586`/`7586` posted by default |
| 3.3 lot decremented (FEFO) on a POS sale | — | **still FAILS — W4R-2**; do not spend bench time, it is a code fix |

Rows still **FAIL** and not worth bench time: **0.4** (partner balances, W4-4), and the AP-opening payment path
(W4-3) — both blocked on the owner's ruling.

Rows that **remain 🟠 and genuinely need the device or a human** (unchanged from wave 4): 3.1–3.2 (PIN entry),
3.3–3.5, 3.7, 3.8, 3.10 (card/mixed tenders, exchange, held-order recall, printing), 3.9 (void before/after
seal), 3.11 (offline then resync), **3.12–3.14** (X-report, cash count + close, Z parity — server routes retired
by design), 4.1/4.2/4.4 (`ACCOUNT_CHARGE` / `ACCOUNT_PAYMENT` are device-authored), 1.6 (supplier return), 2.3
(receive less than shipped), 7.2/7.7 (POS sale during count is now covered at contract level; printable report
and physical checks are not).

**Deploy-order note for the bench:** the D-1 server side is merged and the forward version gate is live, but
**tills still seal v ≤ 4 until a POS build ships**. A transaction discount typed on a current till will still
seal the pre-discount VAT base. That is expected, not a defect — but it means the owner should not test POS
discounts on the bench and read the result as the D-1 behaviour.

---

## 8. Verdict — can the owner run manual testing tomorrow and onboard tenant #1 after promotion?

**Manual testing tomorrow: YES.** Every wave-4 blocker that stopped a bench session dead is fixed and verified
on a fresh tenant: the opening float can be entered (W4-2), a supplier can be paid in cash (unblocked), an
expense leaves the drawer (W4-10), a stock count applies its variances and posts them to the ledger (W4-6, P-1),
and — the one that mattered most — **the POS books net revenue with output VAT per rate, so the books and the
DGI declaration finally state the same number** (W4-9). Five of six previously-failing smoke-sheet rows flip to
PASS, and the treasury reconciliation that was off by 1 155.000 TND now closes to **zero**. The signup trap
(W2-1), the dropped categories (W2-3) and the wrong PO price (W2-6) are all fixed, so the onboarding hour itself
is clean.

**Onboarding tenant #1 after promotion: NOT YET — two things must land first, and one is an owner decision.**

1. **W4R-2 (P1, new) — live POS sales still move no lot.** The W4-5 fix went into the retired
   `POST /pos/receipts` path; the device-authored projection has no batch handling at all. On a parapharmacy
   that forces batch tracking on every product, lot stock only grows and expiry control is fiction from the
   first receipt. Twenty-seven units of drift appeared in a single test day, 100 % of it POS. This is a
   contained fix — port the FEFO call from `ReceiptCreationService` into `PosCoreReceiptProjection` — but it
   must land before a real shop sells for a week.
2. **W4-3 / W4-4 (P0 / P1) — the owner's pending ruling.** Importing open AP items is the first thing an
   onboarding tenant does, and today those items are minted as customer invoices while both partner pages read
   zero against real balances. This run deliberately did **not** execute the inverted payment, so the ledger is
   clean — but a real tenant will, and wave 4 documented what happens: money out recorded as money in. Until
   the ruling lands, tenant #1 cannot import open AP items.

**Also on the day-one list, in the order a tenant meets them:** W4-1 (every opening lot carries an invented
expiry, and FEFO now *compels* shipping it first — worse in a parapharmacy than the P1 suggests), N-12
(repositories have no location, so two branches share one drawer — a per-branch cash count cannot reconcile),
B-19 (144.950 TND of supplier VAT missing from the declaration), N-14 (an abandoned form burns a document
number), and W4R-1 (the opening wizard's last button always errors).

**What is genuinely solid, said plainly.** The chain is real and now the ledger under it is too: hand-minted
`SESSION_OPEN`, three `SALE_RECEIPT`s and a refund were accepted `verified`, shifts projected, stock moved per
line, the drawer moved both ways, COGS posted at WAC, and every receipt's VAT reached `4457` split by sealed
rate. D-1's server side works on both halves — a v5 discounted sale seals `discount_allocated` and posts revenue
net of the remise with no 709 leg, and a v3 replay onto that chain is quarantined by the forward gate rather
than half-applied. The mixed 7/13/19 % survives CSV → product → PO → GRN → supplier invoice → POS receipt → DGI
declaration. Partial-then-remainder receiving is exact; transfers move stock and lots, enforce FEFO and cancel
cleanly; the DN issue now decrements the right lot and leaves the `DEFAULT` remainder alone. Accents are
byte-perfect everywhere including inside lot numbers. Stock valuation, cash, bank and output VAT all reconcile
to the centime.

**The pattern worth naming, and it is the good news inverted.** Wave 4's shape was *"the same business fact is
written to two stores that then disagree, and nothing reconciles them"* — repository vs GL, partner sub-ledger
vs GL, receipt VAT vs GL VAT, batch ledger vs `stock_levels`, count summary vs its own items. Five of those six
pairs now agree exactly, and two of them (cash, VAT) agree because a **guard** was added, not just a writer:
`EXPENSE_PAID_WITHOUT_REPOSITORY`, `pos:census-vat-legs`, `items_applied` / `items_not_applied`. The two pairs
still disagreeing are the partner sub-ledger (W4-3/W4-4, awaiting a ruling) and the batch ledger (W4R-2, a fix
that went to the wrong channel). Both are known, both are contained, and neither is a mystery.

---

*Artifacts: `.playwright-mcp/campaign-wave4-rerun/` (screenshots + source CSVs under `csv/`), git-excluded.
Tenant `01a038cd-4a42-7193-97db-2acb598752da` left in place for inspection; owner login
`rym.trabelsi@parawave4r.tn` / `Wave4Rerun!2026`. The fiscal-event signer used for flow D is in the session
scratchpad (`w4r/sign.js`, `mksession.js`, `mksale.js`, `mksale-v5.js`, `mkrefund.js`) — a test harness, not
product code, re-validated against both repo goldens before use. No production code was modified, no git writes
were made, and the PHPUnit suite was never run. All DB verification was read-only `psql` with monetary amounts
read as strings.*
