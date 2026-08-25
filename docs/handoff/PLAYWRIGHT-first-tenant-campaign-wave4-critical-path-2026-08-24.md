# Playwright first-tenant campaign — wave 4: the owner-defined critical path, with a treasury lens — 2026-08-24

**Purpose:** the owner's critical path for the tenants that are lined up — (A) import products **with opening
balances** (stock per location, treasury opening float, AR/AP openings), (B) purchase orders → GRN → supplier
invoice → supplier payment, (C) stock transfers between branches of one company, (D) POS B2C sales **and
refunds** plus one B2B on-account sale, (E) treasury: drawer → count → safe/bank, (F) reports, and — added by
the owner mid-run — (G) **inventory counting while sales are running**.

The lens throughout: **at every money step, where did it land?** Repository balance, GL account, and the
partner balance page — and do the three agree.

**Tree under test:** local `dev` @ `5939ffd44`. N-1, N-2, N-3/N-4/N-7, N-5 merged. **Not** merged and therefore
expected: **W2-7** (phantom `DEFAULT` batch), **W2-3** (import drops categories), **W2-6** (PO unit price
defaults to sale price), **N-6** (payment on an unpostable invoice). Those are referenced by id and not
re-reported.

**Verdict:** see §7 — written last.

---

## 0. Environment — what was actually served

| Component | State | Evidence |
|---|---|---|
| Tree | local `dev` @ `5939ffd44` | `git log --oneline -1` |
| API | `php artisan serve` on `127.0.0.1:8010`, PID 57648/57537 (14:23), **not restarted** — `php -S` re-reads sources per request; `config:clear` + `route:clear` + `cache:clear` run at campaign start | `GET /api/v1/health` → `{"status":"healthy","timestamp":"2026-08-24T21:41:02+00:00"}` |
| Queue worker | **restarted on current code** at 21:41 (the running worker, PID 62234, was started 17:49 and predated the 17:53 / 18:29 / 19:04 POS + Voucher merges). New PID 96356, same queue list `default,fiscal-projections,enrichment,images,imports` | `ps aux` |
| Web | vite dev on `http://localhost:5173` | 200 |
| PG | `127.0.0.1:5433`, `autoerp`, central `iziposcentral` — read-only `psql`, **all amounts read as strings** | |
| Tenant under test | **`01a035ba-592b-72aa-a12a-1e6b2f7e1d06`**, DB `tenant01a035ba-592b-72aa-a12a-1e6b2f7e1d06` | provisioned by this campaign at 21:43 |
| Company | `Parapharmacie Oléa & Frères SARL` — TN / TND / default VAT 19,00 / `pos_stock_policy=block` / `inventory_costing_method=weighted_average` | |
| Owner login | `hedi.bensalem@parawave4.tn` / `Wave4Tenant!2026` (user `Hédi Ben Salem`) | |
| Screenshots | `.playwright-mcp/campaign-wave4/` | |
| Scratch | session scratchpad `…/scratchpad/wave4/` | |

**POS execution mode.** No Tauri IziPOS dev app was running (`ps` for `tauri`/`izipos`/:1420 → nothing) and
the owner — who is the only one permitted to type PINs — is not at the machine for this run. Per the brief's
fallback, **the whole POS arm (flow D) was driven against the server POS API contract** and every such step is
labelled **API-contract, device run needed**. Nothing in flow D should be read as device coverage.

**Provisioning verified (day-one state):**

```
locations 1 (Main Location | MAIN | shop | pos_enabled=true)   accounts 141      tax_configurations 7
payment_methods 7          payment_repositories 2              units 0 (N-9)     categories 0
companies: Parapharmacie Oléa & Frères SARL | TN | TND | default_tax_rate 19.00 | block | weighted_average
tax: TVA 19% (default) / TVA 13% / TVA 7% / Exonéré TVA 0%  + 3× Timbre Fiscal
```

Second branch `Boutique Ariana` (`ARIA`, shop, **`pos_enabled=true`**) created via `POST /locations` → **201**.

**W2-1 reproduced at signup** (known, not re-reported). The Playwright MCP browser profile is *persistent* and
still held the wave-2 tenant's `autoerp-company-selection` (`01a034af-b107-…`). `POST /auth/register` → 201 and
then **every** authenticated call 403'd `Access denied: User does not have access to the requested company.`
(76 console errors, all report tiles dead). The documented recovery — drop the origin-wide company selection and
sign in again — restored the app to 1 console error. Worth stating plainly for the owner: **this is not a
Playwright artifact, it is the exact condition W2-1 describes**, and any browser that has ever held a company
selection reproduces it on the next signup.

---

## 1. Flow-by-flow

*(written incrementally as each flow ran)*

### A — Opening balances (products + stock + treasury float + AR/AP) — **PARTIAL: stock arm PASS, treasury float ⛔ W4-2, partner sub-ledger ⛔ W4-4**

| # | Step | Result | automated / needs manual |
|---|---|---|---|
| A.1 | Products import, accents, 3 VAT rates, `quantity` + `location_code` | **PASS** | automated |
| A.2 | Opening stock per branch + GL | **PASS** | automated |
| A.3 | Same SKU stocked in **two** branches (2nd import file) | **PASS** | automated |
| A.4 | Lots/expiry on opening stock | **FAIL — W4-1** | automated |
| A.5 | Treasury opening float (drawer 200 / safe 1 000) | **FAIL — W4-2 (P0)** | automated |
| A.6 | GL opening batch through the wizard (N-3 re-test) | **PASS — N-3 verified fixed** | automated |
| A.7 | AR opening (customer owes 150.000) | **PARTIAL** | automated |
| A.8 | AP opening (we owe supplier 500.000) | **PARTIAL — W4-3** | automated |
| A.9 | Partner balance pages show the openings | **FAIL — W4-4 (P1)** | automated |

**A.1–A.3 — the opening-stock path, and it is the right one.** The `stock_levels` import is now **deprecated**
with an unusually good refusal message (`Import/Domain/Enums/ImportType.php:53`): *"It set stock quantities
directly, with no stock movement and no justifying document. Import your opening stock with the Products
import instead: it accepts quantity, purchase_price and location_code, and posts a proper opening stock
movement."* That is the document-per-action principle stated in the product, and the Products import honours it.

7 products (`Sirop antitussif à l'extrait de thym`, `Crème solaire SPF50+ Bébé`, `Compléments Magnésium &
Vitamine B6`, `Huile d'Argan Bio pressée à froid`, `Gel hydroalcoolique désinfectant`, `Thermomètre digital
frontal`, `Lait corporel à l'Aloé Vera`), VAT 7/13/19, stock split across `MAIN` and `ARIA`. Semicolon CSV,
12 columns, **all 12 auto-mapped**, 7/7 valid, 7 imported. Accents byte-perfect throughout.

```
stock_levels   SIRO-TOUX_150 MAIN 40.0000 · CREM-SOLA_50 MAIN 25.0000 · COMP-MAGN_60 MAIN 30.0000
               HUIL-ARGA_100 MAIN 20.0000 · GEL-HYDR_500 MAIN 60.0000
               THER-DIGI_01  ARIA 15.0000 · LAIT-CORP_400 ARIA 35.0000
products       tax_rate 7.00 / 13.00 / 19.00 exactly as the CSV        <- N-1 fixed on the import arm
GL             INV-OB-2026-000001..7   Dr 37 Stocks / Cr 119 Solde d'ouverture, at PURCHASE price
               340.000 + 300.000 + 270.000 + 360.000 + 270.000 + 330.000 + 385.000 = 2255.000
```

Each of the seven is its own journal entry with its own stock movement — one document per action, correctly.

**A.3 — same SKU in two branches works.** A second file re-stating `GEL-HYDR_500` with `location_code=ARIA`,
qty 25, produced **one** product row (upsert on SKU), **two** `stock_levels` rows (MAIN 60.0000 / ARIA
25.0000), two batch-stock rows, and a new `INV-OB-2026-000008` for 112.500. Trial balance moved 2255.000 →
**2367.500**, both sides. This is a real day-one need for a two-branch tenant and it is supported.

**Known reproduced, not re-reported:** W2-3 (`categories` = 0, every `category_id` NULL, no warning — brands
were created, 4 of them, with accents intact) and W2-5 (`default_tax_configuration_id` NULL on all 7).

**A.6 — N-3 is fixed.** The wizard reached **Preview** and rendered the entry it will post — the exact step
that took the whole page to the ErrorBoundary in wave 1. All six steps (Setup → Upload → Validate → Preview →
Post → Lock) render in proper English; **N-7's raw i18n keys are gone**. Posting auto-locked the batch in the
same transaction (`status = LOCKED`, `locked_at 21:53:13`), as wave 1 verified at API level — now reachable
through the UI. `w4-a02-opening-gl-preview-n3-fixed.png`.

```
OB-2026-000001 (opening_balance, posted)
  Dr 53  Caisse                1200.000        Dr 411 Clients        150.000
  Cr 401 Fournisseurs           500.000        Cr 119 Solde d'ouv.   850.000     1350.000 = 1350.000  ✅
```

**A.5 — the opening cash float has no working path. This is the treasury lens's first and worst answer.**

The float was entered exactly where the product tells the operator to enter it. `53 Caisse` now carries
**Dr 1 200.000** in the GL. The repositories that are supposed to *hold* that cash:

```
payment_repositories   Caisse principale (cash_register)  balance = 0.000
                       Coffre-fort      (safe)            balance = 0.000
repository_movements   0 rows
```

Every sanctioned exit is closed, and each refusal points at another one:

| Attempt | Result |
|---|---|
| `POST /payment-repositories/{drawer}/adjustments` `{direction:in, amount:"200.000"}` | **422 `REPOSITORY_NOT_SEEDED`** — *"Caisse principale (CASH-01) has never held money… If you are entering an opening cash float, it is an accounting opening balance: enter it in Settings → Opening balances. Recording it here would book it as income instead."* |
| Settings → Opening balances → GL Accounting, `53` Dr 1 200.000 | **200, posted, locked** — GL only. Repository balance **unchanged at 0.000**, no `repository_movements` row |
| `POST /payment-repositories/transfers` safe → drawer 100.000 | **422 `INSUFFICIENT_REPOSITORY_BALANCE`** — *"This repository's balance (0.000 TND) is insufficient…"* |

So the guard sends the operator to the opening-balances screen, the opening-balances screen posts to the GL
and not to the repository, and the transfer cannot move money that the repository does not believe it has.
**A day-one tenant cannot establish an opening cash float in the treasury at all.**

The schema was clearly built expecting a writer that does not exist: `MovementSourceType` has an
`OpeningBalance` case, and `repository_movements.journal_entry_id` is documented as *"null only for
opening_balance / same-account transfer legs"*. Grepping the whole API for that case returns **exactly one
hit, and it is a read** — `Treasury/Presentation/Console/ReconcileTreasuryCommand.php:904`. Nothing anywhere
writes an opening-balance repository movement. → **W4-2**.

**A.7 / A.8 — the AR/AP arms post documents but leave the sub-ledger empty.** Both wizards ran clean and the
AR preview is genuinely well written — it states the split in plain language: *"Historical documents will be
created with is_historical=true. No GL entry will be created (GL balance should be handled via Accounting
Opening)."* That is the correct design, honestly explained.

```
HIST-INV-2026-00001 | invoice | posted | total 150.000 | balance_due 150.000 | is_historical=t | Nadia Chaabane
HIST-INV-2026-00002 | invoice | posted | total 500.000 | balance_due 500.000 | is_historical=t | Laboratoires Méditerranée SA
```

Two problems fall out.

**A.8 → W4-3: the supplier opening item is created as a customer-side `invoice`.** `ArApOpeningService.php:162-163`
maps `document_type` through exactly two cases — `'invoice','inv' => DocumentType::Invoice` and
`'credit_note','cn' => DocumentType::CreditNote`. There is **no supplier-invoice case at all**, so the AP batch
and the AR batch mint the same document type; only the partner distinguishes them. The number prefix is
`HIST-INV` for both (`:452`). Consequence carried forward into flow B: `PaymentController` decides supplier-ness
by `$document->type === DocumentType::SupplierInvoice` — an AP opening item can never satisfy that test.

**A.9 → W4-4: no opening balance reaches the partner sub-ledger, so the partner pages read zero.** Three views,
three different answers for the same two facts:

| | Customer (Nadia Chaabane) | Supplier (Laboratoires Méditerranée SA) |
|---|---|---|
| GL | `411` Dr **150.000** | `401` Cr **500.000** |
| Open documents | `HIST-INV-…00001` balance_due **150.000** | `HIST-INV-…00002` balance_due **500.000** |
| **Partner balance (`GET /partners/{id}`)** | `receivable_balance` **0.000**, `net_balance` **0.000** | `payable_balance` **0.000**, `net_balance` **0.000** |

The mechanism is structural, not a slip. `partners.receivable_balance` / `payable_balance` are stored columns
refreshed by `Accounting/Listeners/RefreshPartnerBalanceOnJournalEntryPosted` — i.e. **from posted journal
lines carrying a partner dimension**. `journal_lines.partner_id` exists. But:

- the ACCOUNTING opening template is `account_code,debit,credit,reference` — it has **no partner column**, so
  both opening lines posted with `partner_id = NULL` (verified in PG);
- the AR/AP batches deliberately post **no GL at all**.

So no opening balance can ever be attributed to a partner, and the sub-ledger cannot agree with the GL at
cutover by construction. The smoke sheet's row 0.4 — *"Customer page 'Total receivable' 150.000; supplier page
500.000"* — **cannot be satisfied on this build**.

**A.4 → W4-1: every opening lot gets an invented expiry.** The parapharmacy vertical forces
`requires_batch_tracking = true` on all imported products (wave 2, deliberate), and the opening posting keeps
the invariant that batch-tracked stock must sit in a lot — `Inventory/Application/Services/OpeningBalancePostingService.php:152-168`
calls `ensureDefaultBatch(... shelfLifeDays: $product->default_shelf_life_days ...)`. The Products import has
**no expiry or lot column**, so `default_shelf_life_days` is `NULL` on all 7 products, and
`BatchExpiry/Application/Services/BatchStockService.php:75-76` falls back to `DEFAULT_SHELF_LIFE_DAYS`:

```php
$days = $shelfLifeDays ?? self::DEFAULT_SHELF_LIFE_DAYS;              // 365
$expiryDate = CarbonImmutable::parse($asOfDate)->addDays($days)->toDateString();
```

Result — the entire opening catalogue, in PG:

```
product_batches   ALL 7 products | batch_number DEFAULT | expiry_date 2027-08-24    (= cutover + 365, invented)
```

The quantities are right and there is no duplication here (this is **not** W2-7), but for a parapharmacy the
expiry date *is* the point of batch tracking, and on day one every number in that column is fiction that FEFO
will then pick against. Nothing in the wizard, the result summary or the product page says the date was
synthesised. The lot is also named `DEFAULT`, so it does not correspond to anything on the physical box.

### B — Purchasing: PO → partial GRN → remainder → supplier invoice → supplier payment — **PASS on the mainline; ⛔ two blockers at the money end**

*Driven against the API from the authenticated session (waves 1–2 already covered these screens through the UI;
this wave's novelty is the payment end). Labelled accordingly.*

| # | Step | Result | automated / needs manual |
|---|---|---|---|
| B.1 | PO, 3 products, 3 VAT rates, at PURCHASE prices | **PASS** | automated (API) |
| B.2 | Confirm PO | **PASS** | automated (API) |
| B.3 | **Partial** GRN (30/50, 40/40, 0/20) + accented lots | **PASS** | automated (API) |
| B.4 | Remainder GRN (20/50, 20/20) → fully received | **PASS** | automated (API) |
| B.5 | Supplier invoice, 3-way match, mixed VAT → GL | **PASS** | automated (API) |
| B.6 | **Pay supplier in cash from the safe** | **BLOCKED by W4-2** | automated (API) |
| B.7 | Pay supplier by bank transfer | **PASS** | automated (API) |
| B.8 | **Pay the AP opening item** | **FAIL — W4-3 (P0)** | automated (API) |
| B.9 | Supplier balance = opening + invoices − payments | **FAIL — W4-4** | automated (API) |

**B.1–B.5 — the mainline is solid and the mixed-VAT chain holds end to end.**

```
PO-2026-0001   50 × 8.500 @ 7 %  +  40 × 9.000 @ 13 %  +  20 × 18.000 @ 19 %
               subtotal 1145.000   tax 144.950 (= 29.750 + 46.800 + 68.400)   total 1289.950
```

A flat-19 % bug would have produced 217.550. It did not. **N-1 stays fixed on the purchase arm.**

**Partial receipt, then the remainder — both correct, and the lots behave.** `GRN-2026-0001` took 30 sirop +
40 magnésium and left the argan untouched; `GRN-2026-0002` took the remaining 20 + 20. `receipt-status` →
`fully_received`, `110.0000 / 110.0000`, 100 %.

```
stock_levels MAIN   SIRO-TOUX_150 40 → 70 → 90     COMP-MAGN_60 30 → 70     HUIL-ARGA_100 20 → 40
GL   JE-2026-000001..4 (goods_receipt)   Dr 37 Stocks / Cr 408 FNP   255 + 360 + 170 + 360 = 1145.000
     = the HT subtotal exactly, no VAT at receipt stage                                            ✅
```

**Accents survive into lot numbers, and real lots coexist with the opening `DEFAULT` lot without duplicating:**

```
SIRO-TOUX_150   DEFAULT           exp 2027-08-24   40.0000     (opening, W4-1's invented date)
                LOT-SIRÔP-2026A   exp 2028-03-31   30.0000     hex 4c4f542d534952 c394 502d3230323641   (Ô = C394)
                LOT-SIRÔP-2026C   exp 2028-06-30   20.0000
COMP-MAGN_60    DEFAULT           exp 2027-08-24   30.0000
                LOT-MAGNÉS-2026B  exp 2027-11-30   40.0000     hex 4c4f542d4d41474e c389 532d3230323642   (É = C389)
```

40 + 30 + 20 = 90 = `stock_levels`. **The batch ledger and the aggregate agree** — W2-7's doubling is a
*sales-order-confirm* effect and did not appear on the purchasing path.

**B.5 — supplier invoice.** `SI-2026-0001`, `match_status: matched`, subtotal 1145.000, tax 144.950,
`type = supplier_invoice` (contrast this with B.8). Posted:

```
JE-2026-000005 (supplier_invoice)
  Dr 408  Fournisseurs FNP        1145.000      partner_id NULL
  Dr 4456 TVA déductible           144.950      partner_id NULL
  Cr 401  Fournisseurs                      1289.950   partner_id = 01a035c2…   ← carries the partner dimension
```

`4456` = **144.950**, not 217.550. The mixed 7/13/19 % survives CSV → product → PO → receipt → supplier
invoice → GL, as in wave 2. Supplier `payable_balance` moved 0.000 → **1289.950**, because *this* 401 line
carries a `partner_id`. That is precisely the mechanism the opening batch lacks (W4-4).

**B.6 — the supplier cannot be paid in cash on day one.** Direct consequence of W4-2:

```
POST /payments  {repository_id: <safe>, amount: "500.000", allocations:[SI-2026-0001]}
 -> 422 INSUFFICIENT_REPOSITORY_BALANCE
    "This repository's balance (0.000 TND) is insufficient to record an outflow of 500.000 TND;
     this repository does not allow a negative balance."   available "0.000"  requested "500.000"
```

The refusal itself is well-formed and correct. The problem is that there is no way to make it stop being true.
Smoke-sheet row 1.4 is unreachable on this build.

**B.7 — the bank leg is textbook.** A `bank_account` repository (`BANK-01`, GL `512`) was created — note it
defaults to `allow_negative = true`, unlike the cash types, so it can go overdrawn. Paying `SI-2026-0001` in
full:

```
repository_movements   out  1289.950  balance_after -1289.950  source_type payment  journal_entry_id set   ✅
GL  JE-2026-000006 (supplier_payment)   Dr 401 Fournisseurs 1289.950 (partner_id set) / Cr 512 Banques 1289.950   ✅
supplier payable_balance   1289.950 -> 0.000
```

Repository, GL and partner balance all move together and all agree. **This is what the rest of the treasury
lane should look like.**

**B.8 — W4-3 (P0): paying a supplier's opening invoice moves the cash the wrong way and books it against customers.**

`HIST-INV-2026-00002` is the 500.000 TND the tenant owes its supplier, created by the AP opening batch. Paying
it through the same endpoint that just worked correctly for `SI-2026-0001`:

```
POST /payments  {partner_id: <supplier>, repository_id: <bank>, amount: "500.000",
                 allocations:[{document_id: HIST-INV-2026-00002, amount: "500.000"}]}
 -> 201
```

What it actually did:

```
GL  JE-2026-000007 (customer_payment)      Dr 512 Banques  500.000  /  Cr 411 Clients  500.000
repository_movements   direction **in**  500.000   balance_after  -1289.950 -> **-789.950**
partners   receivable_balance  **-500.000**        payable_balance 0.000
GL 401 (what we owe)   unchanged at 500.000 Cr     ← the debt was never cleared
GL 411 (what we are owed)  -150.000 -> **+350.000** ← a receivable that does not exist
documents  HIST-INV-2026-00002  ->  status **paid**, balance_due 0.000
```

The operator sent 500 TND to a supplier. The system recorded 500 TND **arriving** in the bank, cleared a
customer receivable that was never created, left the supplier debt outstanding, and marked the document paid.
Every number is wrong and nothing warns.

**Mechanism, two links:**
1. `Document/Application/Services/ArApOpeningService.php:162-163` — the document-type mapper has exactly two
   arms, `'invoice','inv' => DocumentType::Invoice` and `'credit_note','cn' => DocumentType::CreditNote`. There
   is **no supplier-invoice case**, so the AP batch mints customer-side invoices; the number prefix is
   `HIST-INV` for AR and AP alike (`:452`).
2. `Treasury/…/PaymentController.php:509-510` decides supplier-ness solely by
   `$document->type === DocumentType::SupplierInvoice`. An AP opening item can never satisfy it, so the payment
   silently takes the customer branch — direction `in`, `Cr 411`.

Fixing link 1 also repairs link 2. Note the corollary the campaign confirmed in passing: `payments.payment_type`
is `document_payment` on **both** rows, so the correct supplier payment and this inverted one are
indistinguishable in the payments table.

**B.9 — the three views of the supplier disagree.** After everything above:

| View | Says the supplier is owed |
|---|---|
| GL `401` net credit | **500.000** |
| Supplier page (`payable_balance`) | **0.000** |
| Open AP document | 0.000 (marked paid by the inverted payment) |
| Truth | **500.000** — the opening debt, never paid |

### C — Branch transfers (Main Location → Boutique Ariana) — **PASS**

| # | Step | Result | automated / needs manual |
|---|---|---|---|
| C.1 | Transfer A→B, ship, receive; stock + lots move | **PASS** | automated (API) |
| C.2 | FEFO enforcement on batch allocation | **PASS (guard)** | automated (API) |
| C.3 | Cancel an in-transit transfer | **PASS** | automated (API) |
| C.4 | No GL for an intracompany transfer | **PASS** | automated (API) |
| C.5 | Cost snapshot on transfer movements | **OBSERVATION — W4-7** | automated (API) |

Two refusals fired before the happy path, and both are right:

```
POST /stock-transfers  (no allocations)      -> 422 INVALID_TRANSFER
   "Batch-tracked products require batch allocations."
POST /stock-transfers  (20 from DEFAULT + 5 from LOT-SIRÔP-2026A, skipping earlier stock)
                                             -> 422 INVALID_TRANSFER
   "Batch allocations must follow FEFO (earliest expiry first)."
```

**FEFO is genuinely enforced**, not merely suggested. That is the right behaviour for a parapharmacy — and it
is also what makes **W4-1 bite**: the invented opening expiry (2027-08-24) is the *earliest* date on the
product, so the guard now *compels* the operator to ship the fabricated lot first and refuses any alternative.
The rule is correct; the date it is ranking on is fiction.

`TR-2026-00001`, 25 × `SIRO-TOUX_150`, MAIN → ARIA, allocated from the earliest lot:

```
stock_levels     MAIN 90.0000 -> 65.0000        ARIA  0 -> 25.0000
lots             MAIN DEFAULT 40 -> 15 · LOT-SIRÔP-2026A 30 · LOT-SIRÔP-2026C 20
                 ARIA DEFAULT 25.0000, expiry 2027-08-24 preserved across the move   ✅
stock_movements  transfer_out MAIN 25.0000  90.0000->65.0000   ref …\Domain\StockTransfer
                 transfer_in  ARIA 25.0000   0.0000->25.0000   ref …\Domain\StockTransfer
inventory_batch_movements   DEFAULT -25.0000 (transfer_out) / +25.0000 (transfer_in)   ✅ lot ledger follows
journal_entries with source_type ~ transfer   ->  0        ✅ correct, same legal entity
```

**C.3 — cancel.** `TR-2026-00002` (10 × `COMP-MAGN_60`): MAIN 70 → 60 while in transit → cancel → **70
restored**, `cancellation_reason` stored with accents intact (`Erreur de saisie — quantité déjà réservée`).
No stock leaked.

**C.5 — W4-7 (P3 observation): transfer movements carry no cost snapshot.** Every other movement type stamps
the cost; the two transfer legs do not:

```
opening      unit_cost 8.500000   total_cost 340.000000
receipt      unit_cost 8.500000   total_cost 255.000000
transfer_out unit_cost NULL       total_cost NULL      avg_cost_before NULL   avg_cost_after NULL
transfer_in  unit_cost NULL       total_cost NULL
```

Wave 2 reported a cost of `20.0000` on its transfer movement, so the two runs disagree and the difference is
worth a look by the costing lane. Flagged as an observation, and its effect on per-branch valuation is checked
in flow F.

### G — Inventory counting **while sales are running** (owner addendum) — **⛔ W4-6 (P1): the count applies nothing and reports zero variance**

| # | Step | Result | automated / needs manual |
|---|---|---|---|
| G.1 | Snapshot semantics — frozen at start vs live | **PASS (frozen) — answered** | automated (API) |
| G.2 | Sale during the open count | **PASS** (document sale; POS sale = needs manual) | automated (API) |
| G.3 | Enter counts: one exact, one short, one over | **PASS** | automated (API) |
| G.4 | Double-finalize / cancel-after-finalize refusals (Q-2) | **PASS** | automated (API) |
| G.5 | Adjustments = counted − expected, sale counted once | **FAIL — W4-6** | automated (API) |
| G.6 | Branch B untouched; `reserved` untouched | **PASS** | automated (API) |
| G.7 | Count report per branch | **FAIL — W4-6** (summary contradicts the data) | automated (API) |
| G.8 | Per-lot counting for a batch-tracked product | **NOT SUPPORTED — W4-8** | automated (code) |

**G.1 — expected quantity is SNAPSHOTTED at activation, not read live.** `CNT-2026-0001` was activated with
`SIRO-TOUX_150` at `theoretical_qty = 65.0000`; a delivery note then sold 5 units; the item's
`theoretical_qty` **stayed 65.0000** while `stock_levels` went to 60.0000. Confirmed in code —
`InventoryCountingService::generateCountingItems()` writes `theoretical_qty` from a read of `stock_levels` at
activation. The live value is re-read later, at *apply* time, through the replay path.

**G.4 — the Session B Q-2 guards are real and well-formed.** All three attempts on a finalized count return a
typed **422 `COUNTING_TRANSITION_REFUSED`** naming both the current and the attempted status:

```
finalize again    -> "This counting is finalized and cannot move to finalized."
cancel            -> "This counting is finalized and cannot move to cancelled."
submit a count    -> "This counting is finalized and cannot move to count_1_in_progress."
```

**A second, unlooked-for guard is genuinely good news for the owner's exact question.** The first finalize
attempt was refused outright:

```
422 TERMINAL_SYNC_ACKNOWLEDGEMENT_REQUIRED
"Terminal sync risk must be acknowledged before finalizing this count."
terminal_sync_health: { requires_acknowledgement: true, acknowledgement_signature: "657eb1e2…",
                        stale_after_seconds: 300, terminals: [ T-MAIN-01 … ] }
```

The system refuses to finalize a count while POS terminals may be holding unsynced sales, and makes the
operator acknowledge a *signed* health snapshot. That is precisely the "counting with sales running" hazard,
handled deliberately.

**G.5 / G.7 — W4-6 (P1). The count finalized, reported success, reported zero variance, and changed nothing.**

`CNT-2026-0002` was run on Main Location with a real sale inside the window (4 × `SIRO-TOUX_150` via a
delivery note, live 60 → 56) and two genuine discrepancies:

| SKU | theoretical | counted | true variance | stock after finalize | applied? |
|---|---|---|---|---|---|
| `SIRO-TOUX_150` | 60.0000 | 56.0000 | −4 (**entirely the in-count sale**) | **56.0000** ✅ | n/a — correct |
| `COMP-MAGN_60` | 70.0000 | 68.0000 | **−2 real shrinkage** | **70.0000** ❌ | **no** |
| `HUIL-ARGA_100` | 40.0000 | 42.0000 | **+2 real gain** | **40.0000** ❌ | **no** |
| `CREM-SOLA_50` | 25.0000 | 25.0000 | 0 | 25.0000 | a **no-op** movement was written |
| `GEL-HYDR_500` | 60.0000 | 60.0000 | 0 | 60.0000 | a **no-op** movement was written |

**The good half:** the in-count sale is counted **exactly once**. `SIRO` ended at 56, not 52 — no double
decrement, no lost sale. The replay audit shows why, and it is the right mechanism:
`{"onHandAtApply":"56.0000","replayedDelta":"0.0000","expectedAtApply":"56.0000"}`.

**The bad half:** every item with a non-zero variance was flagged `basket_window` and its adjustment
**suppressed**, while the two items with *zero* variance were the only ones that produced movement rows — and
those rows are no-ops (`qty=0.0000`, `25.0000->25.0000`). The behaviour is exactly inverted from what the
operator expects.

**Mechanism.** `Inventory/Application/Listeners/ApplyStockAdjustmentsOnCountingCompleted.php:266` calls
`MovementReplayService::hasMovementNear(product, location, variant, $asOf, $window)`, which returns true if
**any** stock movement exists within **±`ambiguity_window_minutes`** (model default **15**) of `$asOf`.
`CountingReplayGuardEvaluator::preApply()` (`:13-18`) turns that into a **blocking** `BasketWindow` flag and
the listener returns without posting (`:276-280`).

Two consequences worth stating separately:

1. **The window is centred on `final_qty_as_of`, which is stamped at *finalize*, not at the moment each item
   was counted** — every item shares essentially one instant. So `COMP-MAGN_60` and `HUIL-ARGA_100` were
   suppressed on the strength of their **goods receipts 13 minutes earlier**, despite having had no sale at
   all; while `CREM-SOLA_50` and `GEL-HYDR_500` escaped only because their last movement (the opening import)
   happened ~29 minutes before finalize. The guard is keyed to the wrong instant, and in a live shop — where
   every counted product has moved recently, which is the entire premise of "counting with sales running" —
   it will suppress essentially everything.
2. **Nothing tells the operator.** `POST …/finalize` returns **200 `"Counting finalized successfully"`**. And
   the count report actively contradicts its own data:

```
summary   total_items_counted 5   items_no_variance 5   items_with_variance **0**
          total_variance_value { positive "0.000", negative "0.000", net "0.000" }
flagged_items[]  SIRO  variance -4  flag_reasons ["basket_window"]
                 COMP-MAGN_60 / HUIL-ARGA_100  likewise, with their real variances
```

An owner reading the headline sees a clean count with no discrepancies. The 2-unit shrinkage that a count
exists to catch is reported as *no variance* and never posted. The truth is recoverable — it sits in
`flagged_items` — but the summary states the opposite and no field anywhere says *"these adjustments were not
applied to stock."*

**No GL either, for a second and independent reason:** `config('inventory.count_correction_gl_posting_enabled')`
ships **`false`** (`apps/api/config/inventory.php:29`), so even an applied variance would post no journal
entry. Zero entries with a shrinkage/count source type exist. Smoke-sheet row 7.5's *"GL Dr/Cr 6xx / 37"* does
not happen on a default tenant.

**G.6 — isolation is correct.** `Boutique Ariana` stock is untouched by the Main Location count
(`GEL-HYDR_500` 25, `LAIT-CORP_400` 35, `SIRO-TOUX_150` 25, `THER-DIGI_01` 15), and `stock_levels.reserved` is
`0.0000` on every counted line.

**G.8 — W4-8 (P2): counting has no lot grain.** The counting item's identity is
`(product_id, variant_id, location_id)` — `InventoryCountingItem::$fillable`
(`Inventory/Domain/InventoryCountingItem.php:75-113`) has **no `batch_id`**, and the seed shape is
`array{product_id, variant_id, location_id, theoretical_qty}` (`InventoryCountingService.php:274`). So on a
product carrying three lots with three different expiry dates, the operator can only enter one aggregate
number, and any variance cannot be attributed to a lot. For a vertical that forces batch tracking on
everything, expiry-level stock can never be corrected by a count. Smoke-sheet row 7.3's *"incl. per-lot for a
batch-tracked product"* is not implementable on this build.

**Known reproduced (not re-reported), with a new manifestation worth the fix lane's attention — W2-7.**
Confirming `SO-2026-0002` produced the phantom `DEFAULT` batch again, but not as wave 2 saw it. Here the
`DEFAULT` lot **already existed** with a real opening quantity, and the confirm **overwrote it**:

```
before confirm   DEFAULT 15.0000 · LOT-SIRÔP-2026A 30.0000 · LOT-SIRÔP-2026C 20.0000   = 65 = stock_levels ✅
after  confirm   DEFAULT **65.0000** (res 5.0000) · LOT-A 30.0000 · LOT-C 20.0000      = **115** vs stock_levels 65
```

`ensureDefaultBatch(targetQuantity: $stockLevel->quantity)` does not subtract what real lots already hold, so
it rewrote a lot that carried a meaning. `stock_levels.reserved` stayed `0.0000` while the batch reserved
5.0000 — as wave 2 reported. **The fix must handle "the DEFAULT lot already exists with a non-zero opening
quantity", not only "create a duplicate row".**

### D — POS: terminals, shift, cash sale, B2C refund — **the chain and the stock are right; ⛔ W4-9 (P0) on the GL**

**Execution mode.** Every row below is **API-contract, device run needed**. `POST /pos/receipts` is **retired
(410 `NEW_SALE_AUTHORING_RETIRED`)**, so the only live path is the device-authored, hash-chained
`POST /pos/sync/fiscal-events`. To exercise it without a device I reimplemented the device's canonicaliser in
Node and **validated it byte-for-byte against the repo's own golden vector**
(`apps/api/tests/Fixtures/Fiscal/sale-receipt-v4-refund-golden.json`: re-encoding its
`expected_canonical_string` reproduced it exactly, and `sha256(canonical) == expected_sha256_hex`). Only then
were events minted. Every event below came back `integrity_status = verified`, so these are genuine chain
writes, not fixtures.

| # | Step | Result | automated / needs manual |
|---|---|---|---|
| D.1 | Terminal creation refused at a non-POS location (B-3) | **PASS** | automated (API) |
| D.2 | Claim terminals at both POS branches | **PASS** | automated (API) |
| D.3 | PIN surfaces + offboarding cascade (**N-5**) | **PASS — N-5 verified fixed** | automated (API) |
| D.4 | Shift open (`SESSION_OPEN`, float 200.000) | **PASS** | API-contract, device run needed |
| D.5 | Cash sale, 3 VAT rates, hash-chained | **PASS (chain/stock/drawer)** | API-contract, device run needed |
| D.6 | **Sale → GL** | **FAIL — W4-9 (P0)** | API-contract, device run needed |
| D.7 | FEFO lot decrement on a POS sale | **FAIL — W4-5** | API-contract, device run needed |
| D.8 | B2C cash refund, chain continuity, stock back, cash out | **PASS** | API-contract, device run needed |
| D.9 | X-report / Z-report / shift close | **DEVICE-ONLY by design** | **needs manual (device)** |
| D.10 | Card / mixed / discount / held order / offline resync / print | **not run** | **needs manual (device)** |

**D.1–D.2 — the `pos_enabled` belt holds, and it fires earlier than expected.** Creating a terminal at a
warehouse with `pos_enabled = false` was refused outright:

```
POST /pos/terminals {location_id: <Dépôt Central>}  -> 422 LOCATION_POS_DISABLED
  "POS is not enabled at this location. Enable POS for the location in Settings before using a terminal there."
```

Both POS-enabled branches claimed cleanly (`T-MAIN-01`, `T-ARIA-01`, both `fiscal_schema_version = 3`).

**D.3 — N-5 is fixed.** The wave-1 defect was that `has-pins` ignored the offboarding belt. Re-run end to end:

| Stage | `pin-data` | `has-pins` |
|---|---|---|
| no PINs yet | `[]` | **`false`** |
| cashier active, PIN set | 1 row with `pin_hash` + roles | `true` |
| cashier deactivated (user `inactive`, membership `revoked`, `pos_pin` still on the row) | `[]` | **`false`** ✅ |

Wave 1 saw `true` at that last step. **The two surfaces now admit the same population**, which is exactly what
the lane's comment claimed and did not deliver.

**D.4 — shift open is device-authored and it works.** `SESSION_OPEN` v1 on the `z_session` chain, sequence 1,
`previous_hash = pos_terminals.genesis_seed`, `source_event_class = 'pos_session'`:

```
POST /pos/sync/fiscal-events -> 200 {"stored":true,"exception_class":null}
fiscal_events   SESSION_OPEN | seq 1 | z_session | integrity_status = **verified**
pos_shifts      shift #1 | OPEN | opening_cash 200.0000 | terminal T-MAIN-01     ← projection created
```

**D.5 — the sale.** `SALE_RECEIPT` v3, `operational` chain, sequence 1, three VAT rates in one basket:

| Line | qty | unit_price (TTC) | net | VAT | rate |
|---|---|---|---|---|---|
| Sirop antitussif | 5.000 | 21.400 | 100.000 | 7.000 | 7 % |
| Compléments Magnésium | 4.000 | 56.500 | 200.000 | 26.000 | 13 % |
| Gel hydroalcoolique | 2.000 | 59.500 | 100.000 | 19.000 | 19 % |
| | | | **400.000** | **52.000** | total **452.000** |

Accepted `verified`. What landed:

```
pos_receipts     FE-T-MAIN-01-2026-00000001 | subtotal 400.000 | tax_amount **52.000** | total 452.000  ✅
stock_levels     SIRO 56->51 (-5)   MAGN 70->66 (-4)   GEL 60->58 (-2)                                  ✅
payment_repositories  Caisse principale 0.000 -> **452.000**                                            ✅
repository_movements  in 452.000  balance_after 452.000  source_type **fiscal_event**                   ✅
GL  JE-2026-000010/11/12 (inventory_exit)  Dr 603 / Cr 37  42.500 + 36.000 + 9.000 = 87.500 at WAC      ✅
GL  JE-2026-000013 (pos_receipt)  **Dr 53 Caisse 452.000 / Cr 707 Ventes 452.000**                      ❌
```

**D.6 — W4-9 (P0): the POS books the GROSS tender as revenue and never posts output VAT.**

The receipt itself knows the tax — `pos_receipts.tax_amount = 52.000`, and `pos_receipt_vat_details` carries
the three-rate split. The GL entry ignores all of it: one debit to cash and one credit to `707 Ventes de
marchandises`, both for the full **452.000**. **`4457 TVA collectée` does not appear anywhere in this tenant's
trial balance** — verified directly: the account has no journal line at all.

Mechanism, and it is explicit in the method's own docblock —
`Accounting/Domain/Services/GeneralLedgerService.php:3641-3712`, `createPOSPaymentEntry()`:

```php
/**
 * POS payments are DIRECT TO REVENUE (no AR account).
 * Debit: Cash/Bank Account (from payment repository's GL account) …
 * Credit: Revenue Account (ProductRevenue system purpose)
 */
…
JournalLine::create([… 'account_id' => $repository->gl_account_id, 'debit'  => $payment->amount …]);  // :3688
JournalLine::create([… 'account_id' => $revenueAccount->id,        'credit' => $payment->amount …]);  // :3699
```

There is **no VAT leg in the method**. `$payment->amount` is the gross tender. Compare the document arm, which
gets this right (wave 2, and B.5 here): a posted sales invoice credits `707` **net** and `4457` **per rate**.

**Consequence, stated precisely — the declaration is fine, the books are not.** The VAT declaration reads the
receipt tables, not the GL, so it reports correctly (see F). The damage is in the ledger:

| | Books say | Truth |
|---|---|---|
| Revenue `707` (net of the refund) | **409.200** | 360.000 |
| Output VAT `4457` | **0.000 — account absent** | 49.200 |
| VAT declared to the DGI | 49.200 ✅ | 49.200 |

So the tenant declares and pays 49.200 TND of VAT that its own ledger has no liability for, while revenue is
overstated by the same amount. **The trial balance still closes**, so nothing raises an alarm. For a tenant
launching POS-first this is the most consequential finding in the campaign.

**D.7 — W4-5: the POS sale decremented no lot.** `pos_receipt_line_batch_allocations` is **empty (0 rows)** and
the lot ledger did not move. Detail and the document-arm twin are in §E/W4-5 below.

**D.8 — the refund is good.** A second event on the same chain (`sequence 2`, `invoice_type_code = 'REFUND'`,
`original_receipt_reference` pointing at the sale's fiscal event and receipt uuid), 2 × Sirop, 42.800 TND cash
out:

```
fiscal_events   SALE_RECEIPT | seq 2 | operational | **verified**   ← chain continuous, no gap
pos_receipts    FE-T-MAIN-01-2026-00000002 | receipt_type **return** | total 42.800 | tax 2.800
stock_levels    SIRO 51 -> **53**  (+2, returned to stock)                                      ✅
drawer          452.000 -> **409.200**  (repository_movements: out 42.800, source fiscal_event) ✅
GL  JE-2026-000014 (inventory_entry)      Dr 37 17.000 / Cr 603 17.000   (2 × 8.500 WAC)        ✅
GL  JE-2026-000015 (pos_receipt_refund)   Dr 707 42.800 / Cr 53 42.800                          — symmetric, and inherits W4-9
```

Cash, stock, COGS and the hash chain all behave. The refund is a clean mirror of the sale — including the
missing VAT leg, so the two errors are at least consistent with each other.

**D.9 — X-report, Z-report and shift close are device-only by design, and say so well.** All three legacy
server routes are retired with typed, explanatory refusals:

```
POST /pos/reports/x        -> 409 Z_SESSION_DEVICE_AUTHORITY_REQUIRED
   "Server-side X_REPORT authoring is retired for cutover terminal …. Use device-authored Z-session fiscal events."
POST /pos/reports/z        -> 409 Z_SESSION_DEVICE_AUTHORITY_REQUIRED
POST /pos/shifts/{id}/close-> 409 SHIFT_DEVICE_AUTHORITY_REQUIRED
   "Shift close is retired for device-authoritative terminal …. The device authors SESSION_CLOSE locally;
    pos_shifts is a projection."
POST /pos/receipts         -> 410 NEW_SALE_AUTHORING_RETIRED
```

This is correct and well-communicated — and it means smoke-sheet rows **3.12 (X-report), 3.13 (cash count +
close), 3.14 (Z parity) and 5.1 (remittance after close) cannot be automated at all** and must stay 🟠. They
are genuinely device work, not a gap in this campaign's coverage.

**D.10 — not run, and honestly so.** Card and mixed tenders, discounts, held-order recall, void before/after
seal, receipt printing with accents, and the offline-then-resync arm were **not executed**. Each needs either
the Tauri device or a materially larger hand-minted chain, and the owner types PINs. They remain 🟠.

### E — Treasury: where the cash actually lands — **remittance and deposit PASS; two divergences**

| # | Step | Result | automated / needs manual |
|---|---|---|---|
| E.1 | Drawer → safe remittance, float retained | **PASS** | automated (API) |
| E.2 | Safe → bank deposit + GL | **PASS** | automated (API) |
| E.3 | Expense paid from the drawer | **FAIL — W4-10** | automated (API) |
| E.4 | Per-branch cash visibility (repository → location) | **known N-12** | automated (UI) |
| E.5 | GL cash vs repository balances reconcile | **FAIL** (explained by W4-2 + W4-10) | automated (API) |

**E.1 — remittance is correct, and reveals a provisioning fact worth the owner's attention.**

```
POST /payment-repositories/transfers  drawer -> safe  209.200
 -> 201 { transfer_group_id: "3c43fd97…", journal_entry_id: **null**,
          out: { balance_after: "200.000" }, in: { balance_after: "209.200" } }
```

Drawer left holding exactly the 200.000 float; safe holds the day's takings. Two paired
`repository_movements` share one `transfer_group_id`. `journal_entry_id` is **null on purpose** — and here is
the fact: **the seeded drawer and the seeded safe both point at the same GL account, `53 Caisse`**, so there is
no GL movement to make. The smoke sheet's *"Dr 53-safe / Cr 53-drawer"* (row 5.1) therefore **cannot happen on
a default tenant**: cash location is invisible in the ledger. The TN chart does ship distinct accounts
(`531 Caisse siège` exists), so this is a provisioning choice, not a missing feature — but the owner should
decide it deliberately, because as shipped the GL cannot tell the drawer from the safe.

**E.2 — the bank deposit does post, precisely because the accounts differ.**

```
safe -> bank 200.000   ->  GL JE-2026-000016 (treasury_transfer)  Dr 512 Banques 200.000 / Cr 53 Caisse 200.000  ✅
balances   safe 209.200 -> 9.200      bank -789.950 -> -589.950
```

**E.3 — W4-10 (P1): an expense settles against GL cash without ever touching a repository, and can then never
be attached to one.**

```
POST /expenses {total "45.000", vat 7.185 @ 19 %}          -> 201, status draft
POST /expenses/{id}/post                                   -> 200  EXP-2026-000001
   GL JE-2026-000017 (expense)  Dr 65 Autres charges 37.815 + Dr 4456 TVA déductible 7.185 / Cr 53 Caisse 45.000
POST /expenses/{id}/pay {payment_repository_id: <drawer>}  -> 422 "This expense has already been paid."
```

The GL says 45.000 TND left the cash box. The cash box disagrees — the drawer is still **200.000**, and there
is **no `repository_movements` row with `source_type = expense`** (8 movements exist; none is an expense).

Root cause: `expense_metadata` was written with **`is_paid = true`, `payment_repository_id = NULL`,
`payment_date = NULL`** even though the create payload set none of them (`is_paid` is merely `['boolean']` in
`Expense/Presentation/Requests/ExpenseRequest.php:78`, with no explicit default at the request layer). Posting
then settles it to the default cash account, and the already-paid guard at
`Expense/Application/Services/ExpenseService.php:671-673` permanently blocks `/pay` — which is the *only*
endpoint where a repository can be chosen. So an expense created the obvious way is born paid, from nowhere.

**E.5 — the reconciliation, and it comes out exactly.**

```
GL 53 Caisse net Dr                                  = 1364.200
Sum of repositories backed by GL 53 (drawer + safe)  =  209.200
Difference                                           = 1155.000
```

and that difference is *precisely* the two known gaps:

```
+1200.000   opening float posted to GL 53 but never seeded into any repository   (W4-2)
-  45.000   expense credited to GL 53 but never moved a repository               (W4-10)
= 1155.000  ✅
```

Nothing else is unexplained. The treasury spine itself — movements, ordinals, transfer groups, balance_after,
the `fiscal_event` source type on POS legs — is working correctly; it is the two entry points that bypass it.

### F — Reports — **VAT declaration PASS (N-4 fixed); valuation reconciles; B-19 reproduced**

| # | Step | Result | automated / needs manual |
|---|---|---|---|
| F.1 | VAT declaration screen renders (**N-4** re-test) | **PASS — verified fixed** | automated (UI) |
| F.2 | VAT split per rate incl. POS receipts | **PASS** | automated (UI) |
| F.3 | Input VAT completeness | **FAIL (known B-19)** | automated (UI) |
| F.4 | Trial balance closes | **PASS** | automated (API) |
| F.5 | Stock valuation per branch vs GL 37 | **PASS** | automated (API) |
| F.6 | Sales by branch | **PASS** | automated (API) |

**F.1/F.2 — N-4 is fixed and the declaration is right.** `/finance/vat-report/{id}` renders fully — wave 1
took this page to the ErrorBoundary. `w4-f01-vat-declaration-7-13-19-n4-fixed.png`:

| Rate | Base (HT) | VAT | Documents |
|---|---|---|---|
| 7,00 | 60,000 | 4,200 | 2 |
| 13,00 | 200,000 | 26,000 | 1 |
| 19,00 | 100,000 | 19,000 | 1 |
| **Total** | **360,000** | **49,200** | **4** |

The 7 % row is `100.000 − 40.000` base and `7.000 − 2.800` VAT — **the refund is netted correctly**, and the
POS receipts reach the DGI declaration split by rate. Amount payable 42,015.

**F.3 — B-19 reproduced (known).** Input VAT shows only **7,185** (the expense). The supplier invoice's
**144.950 TND** sitting in `4456 TVA déductible` is absent from the declaration — the same hole waves 1 and 2
recorded, now at 144.950 TND of unreclaimed VAT on a single purchase.

**F.4 — trial balance closes: `Dr 8863.200 = Cr 8863.200`.**

| Account | Dr | Cr | Net |
|---|---|---|---|
| 119 Solde d'ouverture | 0.000 | 3217.500 | −3217.500 |
| 37 Stocks de marchandises | 3529.500 | 164.000 | 3365.500 |
| 401 Fournisseurs | 1289.950 | 1789.950 | −500.000 |
| 408 Fournisseurs FNP | 1145.000 | 1145.000 | 0.000 |
| 411 Clients | 150.000 | 500.000 | −350.000 ← W4-3 |
| 4456 TVA déductible | 152.135 | 0.000 | 152.135 |
| **4457 TVA collectée** | — | — | **absent ← W4-9** |
| 512 Banques | 700.000 | 1289.950 | −589.950 |
| 53 Caisse | 1652.000 | 287.800 | 1364.200 |
| 603 Variation des stocks | 164.000 | 17.000 | 147.000 |
| 65 Autres charges | 37.815 | 0.000 | 37.815 |
| 707 Ventes de marchandises | 42.800 | 452.000 | −409.200 ← gross, W4-9 |

That it balances while carrying two wrong numbers (`411` at −350.000, `707` at gross) is the point worth
making: **a closing trial balance is not evidence of correctness here.**

**F.5 — stock valuation reconciles exactly.** Per-branch on-hand × `cost_price` totals **3365.500**, and
`GL 37` net is **3365.500** — identical. So W4-7 (transfer movements carrying no cost snapshot) does **not**
corrupt valuation, which is why it stays a P3 observation.

```
ARIA  GEL 25 @4.5=112.500 · LAIT 35 @11=385.000 · SIRO 25 @8.5=212.500 · THER 15 @22=330.000
MAIN  MAGN 66 @9=594.000 · CREM 25 @12=300.000 · GEL 58 @4.5=261.000 · ARGA 40 @18=720.000 · SIRO 53 @8.5=450.500
```

**F.6 — sales by branch** returns `Main Location | gross_sales 452.000 | receipt_count 1` for the sale hour.


---

## 2. PASS / FAIL table

| # | Flow | Result | automated / needs manual |
|---|---|---|---|
| A.1 | Products import — accents, 3 VAT rates, `quantity` + `location_code` | **PASS** | automated |
| A.2 | Opening stock per branch + `Dr 37 / Cr 119` at purchase price | **PASS** | automated |
| A.3 | Same SKU stocked in two branches | **PASS** | automated |
| A.4 | Lots/expiry on opening stock | **FAIL — W4-1** | automated |
| A.5 | Treasury opening float (drawer / safe) | **FAIL — W4-2 (P0)** | automated |
| A.6 | GL opening batch through the wizard (N-3) | **PASS — N-3 fixed** | automated |
| A.7 | AR opening documents | **PARTIAL** | automated |
| A.8 | AP opening documents | **PARTIAL — W4-3** | automated |
| A.9 | Partner balance pages reflect the openings | **FAIL — W4-4** | automated |
| B.1–B.2 | PO with 3 VAT rates → confirm | **PASS** | automated |
| B.3–B.4 | Partial GRN → remainder, accented lots | **PASS** | automated |
| B.5 | Supplier invoice, 3-way match → GL | **PASS** | automated |
| B.6 | Pay supplier cash from the safe | **BLOCKED by W4-2** | automated |
| B.7 | Pay supplier by bank transfer | **PASS** | automated |
| B.8 | Pay the AP opening item | **FAIL — W4-3 (P0)** | automated |
| B.9 | Supplier balance = opening + invoices − payments | **FAIL — W4-4** | automated |
| C.1 | Transfer A→B, ship, receive, lots follow | **PASS** | automated |
| C.2 | FEFO enforcement on allocations | **PASS (guard)** | automated |
| C.3 | Cancel an in-transit transfer | **PASS** | automated |
| C.4 | No GL for an intracompany transfer | **PASS** | automated |
| C.5 | Cost snapshot on transfer movements | **OBSERVATION — W4-7** | automated |
| C.6 | Receive less than shipped (discrepancy path) | **not run** | **needs manual** |
| D.1 | Terminal refused at a non-POS location (B-3) | **PASS** | automated |
| D.2 | Claim terminals at both POS branches | **PASS** | automated |
| D.3 | PIN surfaces + offboarding cascade (N-5) | **PASS — N-5 fixed** | automated |
| D.4 | Shift open via `SESSION_OPEN`, float 200.000 | **PASS** | API-contract, device run needed |
| D.5 | Cash sale, 3 VAT rates — chain, stock, drawer | **PASS** | API-contract, device run needed |
| D.6 | Sale → GL | **FAIL — W4-9 (P0)** | API-contract, device run needed |
| D.7 | FEFO lot decrement on a POS sale | **FAIL — W4-5** | API-contract, device run needed |
| D.8 | B2C cash refund — chain, stock, drawer, COGS | **PASS** | API-contract, device run needed |
| D.9 | X-report / Z-report / shift close | **DEVICE-ONLY by design** | **needs manual (device)** |
| D.10 | Card, mixed, discount, held order, void, print, offline resync | **not run** | **needs manual (device)** |
| D.11 | B2B on-account sale at the POS + customer payment | **not run** (`ACCOUNT_CHARGE` / `ACCOUNT_PAYMENT` are device-authored too) | **needs manual (device)** |
| E.1 | Drawer → safe remittance, float retained | **PASS** | automated |
| E.2 | Safe → bank deposit + GL | **PASS** | automated |
| E.3 | Expense paid from the drawer | **FAIL — W4-10** | automated |
| E.4 | Per-branch cash visibility | **known N-12** | automated |
| E.5 | GL cash vs repository balances reconcile | **FAIL** (W4-2 + W4-10, exactly) | automated |
| F.1 | VAT declaration screen renders (N-4) | **PASS — N-4 fixed** | automated |
| F.2 | VAT split per rate, POS receipts included, refund netted | **PASS** | automated |
| F.3 | Input VAT completeness | **FAIL (known B-19)** | automated |
| F.4 | Trial balance closes | **PASS** | automated |
| F.5 | Stock valuation per branch vs GL 37 | **PASS** | automated |
| F.6 | Sales by branch | **PASS** | automated |
| G.1 | Count snapshot semantics (frozen vs live) | **PASS — answered: frozen** | automated |
| G.2 | Sale while the count is open | **PASS** (document sale) | automated / POS sale needs manual |
| G.3 | Counts entered: exact / short / over | **PASS** | automated |
| G.4 | Double-finalize + cancel-after-finalize (Q-2) | **PASS** | automated |
| G.5 | Adjustments applied, in-count sale counted once | **FAIL — W4-6** | automated |
| G.6 | Branch B untouched, `reserved` untouched | **PASS** | automated |
| G.7 | Count report per branch | **FAIL — W4-6** | automated |
| G.8 | Per-lot counting | **NOT SUPPORTED — W4-8** | automated (code) |

---

## 3. NEW defects

### W4-9 — **P0** — The POS books the gross tender as revenue and posts no output VAT to the ledger

`GeneralLedgerService::createPOSPaymentEntry()` (`:3641-3712`) writes exactly two lines — Dr repository GL
account, Cr `ProductRevenue` — both for `$payment->amount`, the **gross** tender. There is no VAT leg. On this
tenant `4457 TVA collectée` has **no journal line at all**, while `pos_receipts.tax_amount` correctly records
52.000 and `pos_receipt_vat_details` holds the three-rate split. The VAT declaration (which reads the receipt
tables) is right; the ledger is wrong: revenue overstated by the VAT, VAT payable unrecorded. The trial balance
still closes. Document-arm sales post this correctly, so the two channels disagree about the same transaction
type. **POS is tenant #1's first module — this is the campaign's most consequential finding.**

### W4-3 — **P0** — AP opening items are created as customer invoices, so paying that supplier moves the cash the wrong way

`ArApOpeningService.php:162-163` maps `document_type` to only `DocumentType::Invoice` / `CreditNote` — there is
no supplier-invoice arm, and both AR and AP batches mint `HIST-INV`. `PaymentController.php:509-510` decides
supplier-ness by `$document->type === DocumentType::SupplierInvoice`, which such a document can never satisfy.
Paying the 500.000 TND owed to a supplier produced `JE-2026-000007 (customer_payment)` **Dr 512 / Cr 411**, a
repository movement of **direction `in`** (bank −1289.950 → −789.950), `receivable_balance = −500.000`, the
`401` debt untouched, and the document marked `paid`. Money out was recorded as money in. Both payments also
persist as `payment_type = document_payment`, so the correct and the inverted one are indistinguishable.

### W4-2 — **P0** — There is no working path to an opening cash float; every sanctioned route refuses or no-ops

The `REPOSITORY_NOT_SEEDED` guard sends the operator to Settings → Opening balances; the GL opening batch posts
`Dr 53 1200.000` and leaves both repositories at `0.000` with zero `repository_movements`; a transfer then
fails `INSUFFICIENT_REPOSITORY_BALANCE`. The schema anticipates a writer that does not exist —
`MovementSourceType::OpeningBalance` has exactly **one** reference in the whole API and it is a read
(`ReconcileTreasuryCommand.php:904`). Consequence: a day-one tenant cannot pay a supplier in cash (B.6), and GL
cash exceeds treasury cash by the whole float.

### W4-6 — **P1** — A count finalizes, reports "no variance", and applies nothing

Every item with a non-zero variance was flagged `basket_window` and its adjustment suppressed; the only
movements written were **no-ops for the two zero-variance items**. A real 2-unit shrinkage and a real 2-unit
gain were silently discarded. `MovementReplayService::hasMovementNear()` blocks any item with **any** movement
within ±`ambiguity_window_minutes` (default 15) of `final_qty_as_of`, which is stamped at **finalize** — so the
window is centred on the wrong instant, and in a live shop (the entire premise of counting with sales running)
it suppresses essentially everything. `finalize` returns `200 "Counting finalized successfully"`, and the
report's summary states `items_with_variance: 0` / `net 0.000` while its own `flagged_items[]` carries the real
variances. Nothing anywhere says the adjustments were not applied. Independently, count-correction GL posting
is off by default (`config/inventory.php:29`), so no journal entry would post even if they were.
**The good half, verified:** the in-count sale is counted exactly once — stock ended at 56, not 52.

### W4-4 — **P1** — No opening balance can reach the partner sub-ledger, so partner pages read zero

`partners.receivable_balance` / `payable_balance` are refreshed from **posted journal lines carrying a partner
dimension** (`RefreshPartnerBalanceOnJournalEntryPosted`). `journal_lines.partner_id` exists, but the ACCOUNTING
opening template is `account_code,debit,credit,reference` — **no partner column** — so both opening lines posted
with `partner_id = NULL`; and the AR/AP batches post no GL by design. The customer page shows **Total Receivable
0,000** against a 150.000 open item; the supplier page shows **Total Payable 0,000** against `GL 401` of
500.000. The proof that the mechanism is otherwise sound: the *real* supplier invoice's `401` line does carry a
`partner_id`, and it moved the balance correctly.

### W4-5 — **P1** — Outbound sales never decrement a lot, on either channel

`inventory_batch_movements` contains rows for **receipts and transfers only**. A confirmed delivery note
(`issue -5.0000`) produced no batch movement, and the POS sale produced **zero**
`pos_receipt_line_batch_allocations`. After the day's trading, `SIRO-TOUX_150` at Main reads `stock_levels`
**53** against a lot ledger of **115** (DEFAULT 65 + LOT-A 30 + LOT-C 20). For a vertical that forces batch
tracking on every product, lot-level stock only ever grows, expiry control is fiction, and there is no
which-lot-went-to-which-customer trail — the exact record a product recall needs.

### W4-10 — **P1** — An expense is born paid, settles against GL cash, and can never be attached to a repository

`expense_metadata` is written with `is_paid = true`, `payment_repository_id = NULL`, `payment_date = NULL` when
the create payload sets none of them. Posting settles it to the default cash account
(`Dr 65 + Dr 4456 / Cr 53 45.000`) with **no `repository_movements` row**, and
`ExpenseService.php:671-673` then refuses `/pay` — the only endpoint that accepts a repository. GL cash falls
by 45.000; the drawer does not move.

### W4-1 — **P1** — Every opening lot is given an invented expiry date

The Products import has no expiry or lot column, so `default_shelf_life_days` is NULL on every imported
product and `BatchStockService.php:75-76` falls back to `DEFAULT_SHELF_LIFE_DAYS = 365`:
`expiry = cutover + 365`. All seven products received a `DEFAULT` lot expiring **2027-08-24**. The
default-batch invariant itself is deliberate and documented
(`OpeningBalancePostingService.php:152-168`) — the defect is that a **fabricated date is presented as data**,
with no warning and no way to supply the real one. It compounds with C.2: FEFO is genuinely enforced, and this
invented date is the earliest on the product, so the guard *compels* shipping the fabricated lot first.

### W4-8 — **P2** — Counting has no lot grain

`InventoryCountingItem` is keyed on `(product_id, variant_id, location_id)` with no `batch_id`
(`Domain/InventoryCountingItem.php:75-113`; seed shape at `InventoryCountingService.php:274`). On a product
carrying three lots with three expiry dates the operator can enter only one aggregate number, and no variance
can be attributed to a lot. Smoke-sheet row 7.3's per-lot count is not implementable.

### W4-7 — **P3, observation** — Transfer movements carry no cost snapshot

`transfer_out` / `transfer_in` store `unit_cost`, `total_cost`, `avg_cost_before`, `avg_cost_after` all NULL,
while `opening` and `receipt` movements stamp `8.500000` / `340.000000`. Wave 2 reported a populated cost on
its transfer, so the two runs disagree and the costing lane should reconcile them. **Not currently harmful:**
per-branch valuation reconciles exactly with `GL 37` (F.5), because valuation reads `products.cost_price`.

### Also observed (not filed as defects)

- The ACCOUNTING opening wizard offers a **`reference` column in its own template** that the backend never
  validates (it accepts `description`), so the operator's audit reference is silently dropped — visible in the
  stored rows, which carry only `debit`/`credit`/`account_code`.
- The seeded **drawer and safe share GL account `53`**, so a drawer→safe remittance posts no journal entry and
  cash location is invisible in the ledger. The TN chart ships `531 Caisse siège`; this is a provisioning
  choice the owner should make deliberately.
- `flagged_items[].flag_reason` (singular, `"significant_variance"`) disagrees with `flag_reasons[]`
  (`["basket_window"]`) on the same row.
- Partners created through the API/UI have `code = NULL`, while AR/AP opening batches match partners on
  **exact `partners.code`**. The code field exists on the create request; it is simply optional. Onboarding
  order matters: set codes before importing open items.

---

## 4. Known defects encountered (recorded, not re-reported)

| Known item | Encountered? | What was seen |
|---|---|---|
| **W2-1** stale company deadlock on signup | **YES — reproduced** | 76 console errors, every call 403; recovered by clearing the origin-wide selection and signing in again |
| **W2-7** phantom `DEFAULT` batch on order confirm | **YES — new manifestation** | the DEFAULT lot **already existed at 15.0000** and confirm **overwrote it to 65.0000**; batch ledger 115 vs `stock_levels` 65; `stock_levels.reserved` stayed 0.0000. The fix must handle overwrite, not only duplicate-row creation |
| **W2-3** import drops categories | **YES** | `categories` = 0, every `category_id` NULL, no warning; brands created correctly with accents |
| **W2-5** tax configuration not linked on import | **YES** | `default_tax_configuration_id` NULL on all 7 imported products (`tax_rate` itself correct) |
| **W2-6** PO unit price defaults to sale price | not re-tested | the PO was driven via API with explicit prices |
| **B-19** supplier-invoice VAT absent from the declaration | **YES — larger** | `4456` carries 152.135; the declaration's input VAT shows only the expense's 7.185 |
| **N-9** no units seeded | **YES** | `units` = 0; quantities render at 4 decimals |
| **N-12** repositories have `location_id = NULL` | **YES** | all three repositories show Location `-`; cash cannot be attributed to a branch |
| **N-6** payment on an unpostable invoice | not triggered | no payment was taken against an unposted sales invoice |
| **N-14** auto-save burns document numbers | **not reproduced** | documents were created via API, not the form; `PO-2026-0001` allocated with no orphan drafts |

**Verified fixed this wave:** **N-3** (opening-balance wizard reaches Preview and Post), **N-4** (VAT
declaration screen renders), **N-5** (`has-pins` respects the offboarding cascade), **N-7** (opening-balance
i18n keys resolved), **N-1** (mixed 7/13/19 % correct on import, PO, supplier invoice, POS receipt and the DGI
declaration), **N-2** (no missing-`StockLevel` 404 anywhere on this run).

---

## 5. Balances sanity — computed vs shown

All amounts read as strings from PG.

| Entity | Computed (truth) | Shown on its screen | GL account | Agree? |
|---|---|---|---|---|
| **Supplier** `Laboratoires Méditerranée SA` | opening 500.000 + SI 1289.950 − bank payment 1289.950 = **500.000** | **0,000 TND** (Total Payable) | `401` net Cr **500.000** | ❌ page ≠ GL ≠ truth — **W4-4** + **W4-3** |
| **Customer** `Nadia Chaabane` | opening 150.000 + 0 invoiced sales = **150.000** (plus 143.487 delivered-not-invoiced, correctly disclosed) | **0,000 TND** (Total Receivable) | `411` net **−350.000** | ❌ all three differ — **W4-4**, GL polluted by **W4-3** |
| **Drawer** `Caisse principale` | float 200.000 + cash sale 452.000 − refund 42.800 − remittance 209.200 − expense 45.000 = **355.000** | **200,000 TND** | shares `53` | ❌ float never seeded (**W4-2**), expense never moved it (**W4-10**) |
| **Safe** `Coffre-fort` | opening 1000.000 + remittance 209.200 − deposit 200.000 = **1009.200** | **9,200 TND** | shares `53` | ❌ float never seeded (**W4-2**) |
| **Bank** `Banque BIAT` | deposit 200.000 − supplier payment 1289.950 = **−1089.950** | **−589,950 TND** | `512` net **−589.950** | ❌ page = GL, but both carry the inverted +500.000 (**W4-3**) |
| **Cash reconciliation** | GL `53` net Dr **1364.200** vs repositories (drawer + safe) **209.200** | difference **1155.000** | | ✅ **fully explained**: +1200.000 unseeded float (W4-2) − 45.000 expense (W4-10) |
| **Stock valuation** | Σ qty × cost across both branches = **3365.500** | per-branch stock screens match | `37` net **3365.500** | ✅ **exact** |
| **Trial balance** | — | — | Dr **8863.200** = Cr **8863.200** | ✅ balanced (while carrying two wrong numbers) |
| **Output VAT** | receipts declare **49.200** | declaration shows **49,200** ✅ | `4457` **absent** | ❌ ledger ≠ declaration — **W4-9** |

**The single sentence for the owner: not one of the five money entities shows a number the operator can trust,
and the two that reconcile — stock valuation and the trial balance — reconcile to figures that are themselves
wrong.**

---

## 6. Smoke-sheet mapping — what the owner still has to run by hand

Rows now **automated** (verify only, evidence above): 0.1, 0.3, 0.4*, 1.1, 1.2, 1.3, 2.1, 2.2, 4.3*, 5.4,
5.5, 7.1, 7.4, 7.5*, 7.6.
*(0.4, 4.3 and 7.5 are automated but **FAIL** — see W4-4 and W4-6.)*

Rows **automated this wave that the sheet marked 🟠** — the owner can skip them: **0.2** (opening float — it
does not work, W4-2), **1.4** (supplier cash payment — blocked by W4-2), **1.5** (bank transfer — PASS),
**5.1** (drawer→safe — PASS, no GL because both share account 53), **5.2** (safe→bank — PASS), **5.3**
(expense from drawer — FAILS, W4-10).

Rows that **remain 🟠 and genuinely need the device or a human**:

| Row | Why it cannot be automated |
|---|---|
| 3.1–3.2 | PIN entry is the owner's; terminal claim itself is verified (D.1–D.2) |
| 3.3–3.5, 3.7, 3.8, 3.10 | card/mixed tenders, exchange, held-order recall, printing — device UI |
| 3.6 | B2C refund **is** verified at contract level (D.8); the drawer-cash-in-hand half is physical |
| 3.9 | void before/after seal — device authoring |
| 3.11 | offline sale then resync — device SQLite |
| 3.12–3.14 | X-report, cash count + close, Z parity — **server routes are retired by design** (409 `Z_SESSION_DEVICE_AUTHORITY_REQUIRED` / `SHIFT_DEVICE_AUTHORITY_REQUIRED`) |
| 4.1, 4.2, 4.4 | `ACCOUNT_CHARGE` / `ACCOUNT_PAYMENT` are device-authored fiscal events; N-6 needs an owner ruling first |
| 1.6 | supplier return / credit note — not run |
| 2.3 | receive less than shipped — not run |
| 7.2, 7.3, 7.7, 7.8 | POS sale during the count, per-lot entry (**not supported**, W4-8), printable report, DEFAULT-lot inspection |

---

## 7. Verdict

**⛔ NOT ready for the tenants that are lined up.** Three P0s, and all three are on the owner-defined critical
path rather than at its edges.

**The three that block onboarding:**

1. **W4-9 — the POS posts gross revenue and no output VAT.** Tenant #1 launches POS-first. Every cash sale
   overstates revenue by its VAT and records no VAT liability, while the DGI declaration — correctly — asks the
   tenant to pay it. The books and the filing disagree from the first receipt, and the trial balance closes
   anyway, so nothing surfaces it.
2. **W4-3 — paying a supplier's opening invoice moves the cash the wrong way.** Importing open AP items is the
   first thing an onboarding tenant does. Settling one of them credits `411`, debits the bank, marks the
   document paid and leaves the debt standing. Money out is recorded as money in.
3. **W4-2 — the opening cash float cannot be entered at all.** Every sanctioned route refuses or silently
   no-ops, each pointing at the next. The consequence is not cosmetic: the tenant cannot pay a supplier in cash
   on day one, and GL cash and treasury cash diverge by the float from the first hour.

**Then, in order of how quickly a tenant will meet them:** W4-4 (partner pages read zero — the owner will look
at these on day one), W4-6 (counting reports "no variance" and applies nothing — the owner's own addendum
scenario), W4-5 (lots never decrement — fatal for expiry control in a parapharmacy), W4-10 (expenses vanish
from the drawer), W4-1 (invented expiry dates on the whole opening catalogue).

**What is genuinely solid, and worth saying plainly.** The chain is real: hand-minted `SESSION_OPEN` and two
`SALE_RECEIPT` events were accepted `verified`, the shift projected, stock moved per line, the drawer moved
both ways, COGS posted at WAC, and the refund is a clean mirror of the sale with the chain continuous. The
mixed 7/13/19 % VAT survives from CSV through product, PO, GRN, supplier invoice and POS receipt to a correct
DGI declaration with the refund netted — **N-1 stays fixed on every arm**. Partial-then-remainder receiving is
exact. Transfers move stock and lots, enforce FEFO, and cancel cleanly. Accents are byte-perfect everywhere,
including inside lot numbers (`LOT-SIRÔP-2026A`, `LOT-MAGNÉS-2026B`). Stock valuation reconciles to the
centime with `GL 37`. **Four wave-1 defects are verified fixed** (N-3, N-4, N-5, N-7) and N-2 never appeared.
And the guards are excellent where they exist — `LOCATION_POS_DISABLED`, `INVALID_TRANSFER` FEFO enforcement,
`COUNTING_TRANSITION_REFUSED`, `TERMINAL_SYNC_ACKNOWLEDGEMENT_REQUIRED`, `INSUFFICIENT_REPOSITORY_BALANCE`,
`REPOSITORY_NOT_SEEDED`, and the four retired-route refusals are all typed, scoped and well written.

**The pattern worth naming.** Wave 1's blockers were API↔frontend contract drift. This wave's are a different
shape: **the same business fact is written to two stores that then disagree, and nothing reconciles them.**
Repository balance vs GL cash (W4-2, W4-10). Partner sub-ledger vs GL (W4-4). Receipt VAT vs GL VAT (W4-9).
Batch ledger vs `stock_levels` (W4-5, W2-7). Count report summary vs its own flagged items (W4-6). In every
case one store is right, the other is silently wrong, and the trial balance keeps closing. A reconciliation
check between each pair — the kind `ReconcileTreasuryCommand` already gestures at — would have caught six of
this wave's ten findings.

---

*Artifacts: `.playwright-mcp/campaign-wave4/` (screenshots + the source CSVs under `csv/`), git-excluded.
Tenant `01a035ba-592b-72aa-a12a-1e6b2f7e1d06` left in place for inspection. The fiscal-event signer used for
flow D is in the session scratchpad (`wave4/sign.js`, `mksale.js`, `mkrefund.js`) — it is a test harness, not
product code. No production code was modified, no git writes were made, and the PHPUnit suite was never run.
All DB verification was read-only `psql` with monetary amounts read as strings. The only environment change was
restarting the queue worker on current code at 21:41.*
