# Playwright first-tenant campaign — wave 2: imports with French accents, end to end — 2026-08-24

**Purpose:** the owner's exact ask — *"Simulate the tenant's flow and hunt for bugs from onboarding, catalog
and imports. We once had an import file that did not support French accented characters (é, è, ê, à, ç, …);
that must be supported ALL THE WAY: import → purchase order → goods receipt → supplier invoice → stock
transfer between locations → sale/receipt."*

Run as wave 2 of the first-tenant rehearsal, on a **second, freshly provisioned tenant** (day-one state),
against local `dev` @ `0b9db8452` — i.e. the wave-1 tree **plus** the merged N-1, N-2 and N-5 fixes.

**Verdict:** see §6 — written last, after every arm was run.

---

## 0. Environment — what was actually served

| Component | State | Evidence |
|---|---|---|
| Tree under test | local `dev` @ `0b9db8452` (`register: [Session A] N-1 merged (77610a8bb, MIGRATION-BEARING) …`) | `git log --oneline -1` |
| API | already running, **not restarted** | `GET http://127.0.0.1:8010/api/v1/health` → `{"status":"healthy","timestamp":"2026-08-24T16:49:55+00:00"}` (200) |
| Queue worker | running, PID 62234, `queue:work redis --queue=default,fiscal-projections,enrichment,images,imports --sleep=1 --tries=1` | `ps aux` — the `imports` queue is present |
| Web | vite dev on `http://localhost:5173` | 200 |
| PG | `127.0.0.1:5433`, user `autoerp`, central `iziposcentral` | read-only `psql` throughout |
| Tenant under test | **`01a034af-94ea-713d-8ce0-462216bf6ab5`**, DB `tenant01a034af-94ea-713d-8ce0-462216bf6ab5`, domain `parapharmacie-khemira-freres-sarl-uhjhnn.synerivia.tn` | provisioned by this campaign at 16:52 |
| Company | `Parapharmacie Khémira & Frères SARL` — TN / TND / fr / Africa/Tunis, default VAT 19,00, `pos_stock_policy=block`, `inventory_costing_method=weighted_average` | |
| Owner login | `naima.benaissa@pharmaccents.tn` / `Accents2026!Tn` (user `Naïma Ben Aïssa`) | |
| Screenshots | `.playwright-mcp/campaign-wave2/` | |
| Test data | `…/scratchpad/wave2/` — 5 CSVs, three encodings | see §2 |

No production code was modified. No git writes. The PHPUnit suite was never run.

### Test fixtures — the accented CSVs

Built to look like a real French/Tunisian Excel export: **semicolon**-delimited, accented headers-free but
accented values throughout, `–` en dash, `—` em dash, `'` apostrophe, `Ç`, `Ñ`, `É`, `è`, `à`, `â`, `ê`.

| File | Encoding | `file(1)` | Content |
|---|---|---|---|
| `products-utf8.csv` | UTF-8, **no BOM** | `Unicode text, UTF-8 text` | 5 products (`Crème hydratante Bébé 200ml`, `Pâte à l'eau`, `Élixir apaisant`, `Gel douche Ça va`, `Sérum anti-âge`), SKUs with dash **and** underscore, VAT 7/13/19 |
| `products-b-utf8bom.csv` | UTF-8 **with BOM** (Excel "CSV UTF-8") | `Unicode text, UTF-8 (with BOM) text` | 2 products (`Lingettes Bébé à l'Aloé`, `Huile d'Amande Douce Pressée à Froid`) |
| `products-c-cp1252.csv` | **Windows-1252** (`iconv -f UTF-8 -t WINDOWS-1252`) | `Non-ISO extended-ASCII text` | 2 products (`Shampooing Ultra-Doux à la Camomille`, `Baume à Lèvres Réparateur Cire d'Abeille`) |
| `partners-utf8.csv` | UTF-8 | | supplier `Société Générale de Parapharmacie – Tunis`, customer `Zoé Ñuñez`, address `Rue Ibn Khaldoun, L'Aouina` |
| `products-bad.csv` | UTF-8 | | 3 rows: **missing name**, **duplicate SKU**, 1 valid (`Écran solaire`) |

CP1252 bytes verified before upload: `Crème` → `43 72 e8 6d 65`, `Bébé` → `42 e9 62 e9` — genuinely
single-byte Latin-1, not UTF-8.

---
## 1. Flow-by-flow

### F1 — Self-service signup on a browser that already knows another company — **FAIL (W2-1)**

`/register`, 4 steps, all values accented (`Naïma Ben Aïssa`, `Parapharmacie Khémira & Frères SARL`).
The review step rendered every accent correctly (`w2-f1-signup-review.png`) and
`POST /api/v1/auth/register` → **201**.

**Then the app was dead.** Every authenticated call that followed returned **403**
`Access denied: User does not have access to the requested company.` — `/user/companies`,
`/company/config`, `/company/locations`, `/notifications/unread-count` and all ~16 report endpoints.
The Owner Dashboard rendered *"Could not load summary"* / *"Report unavailable."* in every tile, the sidebar
collapsed to three sections (Purchases / Point of Sale / Settings) because the permission payload never
loaded, and the header company chip displayed **another tenant's company name**,
*"Parapharmacie Élégance & Santé SARL"*. → **W2-1**, §5. `w2-f1-post-signup-403-stale-company.png`,
`w2-f1-company-switcher-empty-deadlock.png`.

Recovery through the UI: **Sign out → sign in again** works (`clearAllAppState` runs on logout, clearing the
stale key) and the app comes up correctly as `Parapharmacie Khémir…` with the full sidebar
(`w2-f1-recovered-after-logout-login.png`). The rest of the campaign ran on that recovered session.

Provisioning itself is as clean as wave 1 reported. DB after signup:

```
companies:             Parapharmacie Khémira & Frères SARL | TN | TND | fr | Africa/Tunis
                       default_tax_rate 19.00 | pos_stock_policy=block | inventory_costing_method=weighted_average
locations:             Main Location | MAIN | shop | is_active=t | pos_enabled=t
accounts:              141      country_tax_rates: 119     payment_methods: 7    payment_repositories: 2
tax_configurations:    TVA 19% (default) / TVA 13% / TVA 7% / Exonéré TVA / Timbre Fiscal ×3
units:                 0            <- N-9, known, not re-reported
```

Domain slug transliterates accents correctly: `parapharmacie-khemira-freres-sarl-uhjhnn.synerivia.tn`
(`é`→`e`, `è`→`e`) — **PASS**.

### F2 — Second location for the transfer arm — **PASS**

`/settings/locations` → *Add Location*: `Dépôt Ariana`, type **Warehouse**, code `ARIANA`,
street `Rue Ibn Khaldoun, L'Aouina`, city `Ariana`. Saved, listed.

```
name          | code   | type      | pos_enabled | encode(name::bytea,'hex')
Dépôt Ariana  | ARIANA | warehouse | f           | 44 c3a9 70 c3b4 74 20 417269616e61
```

`é` = `C3A9`, `ô` = `C3B4` — correct UTF-8 at rest, no mojibake. **PASS.**

### F3 — Business-partners import, accented (UTF-8) — **PASS (with W2-2)**

`/settings/import` shows exactly two primary cards with the correct ordering guidance (B-4, as wave 1).
`/settings/import/parties`, `partners-utf8.csv` (semicolon-delimited, 8 columns).

| Step | Result |
|---|---|
| Upload | ✅ *"File ready … 8 columns detected"* — semicolon delimiter auto-detected |
| Map Columns | ✅ **all 8 auto-suggested correctly**, including `vat_number`→`tax_id`, `address`→`address_line1`, `city`→`address_city`, `country`→`address_country` |
| Review | ✅ 2 total / 2 valid — **accents perfect in the preview grid**: `Société Générale de Parapharmacie – Tunis` (en dash intact), `Zoé Ñuñez`. **But** the four columns whose source header ≠ target field all render `-` → **W2-2** |
| Import | ✅ 2 imported / 0 failed |
| Result workbook | ✅ downloaded `import-…-result.xlsx`, **accents round-trip intact** in the XLSX shared strings: `Société Générale de Parapharmacie – Tunis`, `Zoé Ñuñez`, `Rue Ibn Khaldoun, L'Aouina` |

**Stored bytes — the owner's actual question — verified in PG:**

```
Société Générale de Parapharmacie – Tunis
  => 536f6369 c3a9 74 c3a9 2047 c3a9 6e c3a9 72616c65…2070617261706861726d61636965 20 e28093 2054756e6973
     ^^^^ é=C3A9 (correct 2-byte UTF-8)                                    ^^^^^^ – = E2 80 93 (U+2013 en dash)
Zoé Ñuñez => 5a6f c3a9 20 c391 75 c3b1 657a          (Ñ=C391, ñ=C3B1)
select count(*) from partners where name like '%Ã%' or name like '%?%'  =>  0
```

Full row content also correct: `street_address = Rue Ibn Khaldoun, L'Aouina`, `city = Tunis`,
`country = TN`. **No mojibake, no `?` substitution, no truncation.**
`w2-f2-partners-preview-accents-ok-mapped-cols-dash.png`.

### F4 — Products import, THREE encodings — **PASS on accents (the headline result); W2-3 on categories**

Three separate imports, distinct SKUs per file so each encoding is independently verifiable.

| # | File | Encoding | Upload | Map | Validate | Import |
|---|---|---|---|---|---|---|
| 1 | `products-utf8.csv` | UTF-8, no BOM | ✅ *12 columns detected* | ✅ all 12 identity-mapped incl. `tax_rate` | ✅ 5 total / **5 valid** | ✅ **5 / 0 failed** |
| 2 | `products-b-utf8bom.csv` | UTF-8 **with BOM** | ✅ *12 columns detected* | ✅ `name` still maps — **BOM stripped**, no `﻿ name` column | ✅ 2 / 2 valid | ✅ **2 / 0** |
| 3 | `products-c-cp1252.csv` | **Windows-1252** | ✅ *12 columns detected* | ✅ | ✅ 2 / 2 valid | ✅ **2 / 0** |

**Preview grid** rendered every accent correctly in all three, including the ones most likely to break:

```
Crème hydratante Bébé 200ml   Pâte à l'eau   Élixir apaisant   Gel douche Ça va   Sérum anti-âge
Crème pour peaux très sèches — bébé dès 0 mois        (em dash, from UTF-8)
Shampooing Ultra-Doux à la Camomille   Baume à Lèvres Réparateur Cire d'Abeille
Cheveux clairs — reflets dorés                        (em dash, from CP1252 byte 0x97)
```

`w2-f4-products-utf8-preview-accents.png`, `w2-f4-products-cp1252-preview-accents.png`.

**Stored bytes in PG — all 9 imported products:**

```
Crème hydratante Bébé 200ml  => 4372 c3a8 6d65 …2042 c3a9 62 c3a9…      è=C3A8  é=C3A9
Élixir apaisant              => c389 6c6978 6972…                        É=C389
Gel douche Ça va             => …646f75636865 20 c387 6120 7661         Ç=C387
Pâte à l'eau                 => 50 c3a2 7465 20 c3a0 206c27656175       â=C3A2  à=C3A0
Baume à Lèvres Réparateur…   => …c3a0 204c c3a8 767265 7320 52 c3a9…   (from the BOM file)
Shampooing Ultra-Doux à la…  => …446f7578 20 c3a0 206c61…              (from the CP1252 file)
description of SHAM-CAMO_250 => 4368657665757820636c6169727320 e28094 207265666c65747320646f72 c3a9 73
                                                                ^^^^^^ CP1252 0x97 → U+2014 em dash, correct
select count(*) from products where name ~ '(Ã|Â|�)' or description ~ '(Ã|Â|�)'  =>  0
```

**No mojibake, no `?` substitution, no truncation, in any of the three encodings — including the
Windows-1252 file that is the exact shape of the historical bug the owner remembers.**

The parser earns this: `Import/Services/SpreadsheetParserService.php:52-104` strips the BOM, detects the
delimiter from the header line (`;` wins over `,` here), and converts **per value** rather than per file
(`toUtf8()`, `:110-119`) — which is precisely why a stray CP1252 byte cannot corrupt already-valid UTF-8
elsewhere in the same file. That design is doing real work and it is worth keeping.

**Brands** were created with correct accents (`Laboratoire Éclat` = `…20c389636c6174`, `Ça va` = `c3876120 7661`,
`Institut Français` = `…4672616e c3a7 616973`) and linked to all 9 products.

**Categories were silently dropped** — see **W2-3**. `select count(*) from categories` = **0**; every product
has `category_id = NULL`, despite `category_name` being mapped and populated on all 9 rows
(`Soins Bébé`, `Aromathérapie`, `Hygiène`, `Soins Visage`).

**N-1 (wave 1, P0) — the import arm is CORRECT.** `products.tax_rate` matches the CSV exactly:

```
CREM-BEBE_200    7.00      LING-BEBE_72     7.00      BAUM-LEVR_10     7.00
ELIX-APAI_50    13.00      HUIL-AMAN_100   13.00
GEL-CAVA_500    19.00      SERU-ANTIAGE_30 19.00      SHAM-CAMO_250   19.00
```

**Observation (not a defect until proven downstream):** all imported products have
`default_tax_configuration_id = NULL` — the importer writes the denormalised rate but never links a
`tax_configurations` row (`ProductService::upsert`, `app/Modules/Product/Application/Services/ProductService.php:97-111`
only defaults the *rate*). Whether that matters was tested downstream, in F7/F10 — see there.

### F5 — Deliberately bad rows — **PASS (with W2-4 and one P3 nit)**

`products-bad.csv`: row 1 missing `name`, row 2 duplicate SKU `CREM-BEBE_200`, row 3 valid.

- **Missing required field — handled well.** Row 1 → **Error**, inline under the cell and in a dedicated
  *Validation Errors* panel that names the **row number** (`#1`) and the field (`name: The name field is
  required.`). Accents in the failing row are preserved in the error panel
  (`Ligne sans nom — doit échouer`). `w2-f5-bad-rows-validation-errors.png`.
- **Partial-import guard — good.** *Proceed to Import* raised a modal: *"Import with Errors — 2 rows are
  valid and will be imported. 1 rows have errors and will be skipped. You can download the failed rows
  after import."* with **Cancel** / **Import Valid Rows**. Clear, correct, and it stops an accidental
  full-send. `w2-f5-partial-import-confirm.png`. (*"1 rows"* — plural nit, P3.)
- **Duplicate SKU — silently overwrote an existing product** → **W2-4**. Row 2 was reported **Valid**, the
  summary said *"Successfully imported 2 / Failed 0"*, and in the DB:

  ```
  CREM-BEBE_200 | Crème hydratante Bébé 200ml   ->   Duplicata Crème hydratante
                | description likewise replaced with "SKU déjà utilisé — doit échouer"
  ```

### F6 — Catalog: list, detail, edit, search — **PASS on accents; W2-5 on the tax control; N-13 reproduced**

**List** `/inventory/products` — all 10 products render every accent correctly in both name and description,
prices in French format with the right currency (`24,900 TND`, `89,900 TND`).
`w2-f6-catalog-list-accents.png`.

**Detail** — `Crème hydratante Bébé 200ml`: **Tax Rate 7 %**, **Sale Price HT 24,900 TND**,
**Sale Price TTC 26,643 TND**. `24.900 × 1.07 = 26.643` exactly — **N-1 is fixed on the read path**: the
detail view prices at the product's real 7 %, not the company default 19 % (which would be 29,631).
`Category —` (W2-3 visible to the operator). Quantities render `0.0000` — N-9 consequence, known.

**N-1 verified fixed on the product FORM too.** Created `Thé vert détox à l'anis — bio` (`THE-DETOX_20`),
sale price HT 20.000, tax **TVA 13 %**. The form's *Sale price (incl. tax)* recomputed live to **22.600**
(= 20 × 1.13, **not** 23.800 = ×1.19). Persisted:

```
sku          | name                          | tax_rate | cfg     | percentage_rate | sale_price
THE-DETOX_20 | Thé vert détox à l'anis — bio |    13.00 | TVA 13% |           13.00 |     20.000
hex: 5468 c3a9 20766572742064 c3a9 746f7820 c3a0 206c27616e697320 e28094 2062696f
```

`tax_rate` now matches the chosen configuration and `default_tax_configuration_id` is populated —
the exact wave-1 N-1 failure, gone. Accents also survive the form → DB path unchanged.

**Editing an imported product — two findings.**
- The **Tax Rate control reads "Select tax…"** (blank) on every imported product even though the product is
  taxed at 7 %, because the importer leaves `default_tax_configuration_id` NULL and the control binds to the
  configuration id, not the rate → **W2-5**.
- Saving with the control left blank **does not clobber the rate** — verified: after a PATCH 200 that changed
  only the name, `tax_rate` was still `7.00`. So this is a misleading display, not (yet) a silent number
  change. It becomes one the moment an operator "fills in the blank" with the wrong entry.
- **N-13 (wave 1) reproduced exactly**: the first Save was a silent no-op — no toast, `window.scrollY` stayed
  `0`, and the only signal was one *"This field is required"* rendered far below the fold on the hidden
  **Parapharmacy → Product Category**. Known, not re-reported — but W2-3 sharpens it: **the import cannot
  populate that field**, so *every* imported product is un-editable until the operator discovers a required
  field the import never fills.

**Search — accent-SENSITIVE. Observation, not a defect (per brief), but it will bite a French tenant.**
`GET /api/v1/products?search=…`, all 200, none error:

| Query | Hits | Query | Hits |
|---|---|---|---|
| `crème` | 1 ✅ | `creme` | **0** ❌ |
| `Crème` | 1 ✅ (case-insensitive) | `elixir` | **0** ❌ |
| `Élixir` | 1 ✅ | `ca va` | **0** ❌ |
| `Ça va` | 1 ✅ | `levres` | **0** ❌ |
| `lèvres` | 1 ✅ | `bebe` | 2 ✅ — but **via SKU** (`CREM-BEBE_200`, `LING-BEBE_72`), not the name |

Search is **case-insensitive but not accent-insensitive**. A cashier or buyer typing `creme`, `elixir` or
`levres` — which is what people actually type — finds nothing, with no "did you mean". The only reason
`bebe` returns anything is that the SKUs happen to be ASCII. Fixing this is an `unaccent`/collation change on
the search predicate. Filed as an **observation** per the brief.

**Sort order is byte-order, not French collation** (observation, P3): the list orders
`… Produit, Pâte, Shampooing, Sérum, Élixir` — every accented initial sorts after all ASCII, so `Élixir`
lands last and `Pâte` after `Produit`. A French catalog should collate `É` with `E`.

### F7 — Purchase order → goods receipt — **PASS on money, stock and accents; W2-6 on the price default**

`PO-2026-0010` to `Société Générale de Parapharmacie – Tunis`, three imported products at **three different
VAT rates**. The supplier picker matched the accented query `Société` on the first try; the product picker
matched `Crème`, `Élixir` and `Ça va`.

**This is the decisive N-1 check the brief asked for, and it passes.** Line totals computed live in the form:

| Line | VAT | Qty × HT | Line HT | Tax |
|---|---|---|---|---|
| Crème hydratante Bébé 200ml | **7 %** | 30 × 15.000 | 450.000 | 31.500 |
| Élixir apaisant | **13 %** | 20 × 20.000 | 400.000 | 52.000 |
| Gel douche Ça va | **19 %** | 40 × 7.000 | 280.000 | 53.200 |
| | | **Subtotal 1 130,000** | | **Tax 136,700** — Total **1 266,700 TND** |

`31.5 + 52 + 53.2 = 136.700` **exactly**. Under wave-1's N-1 this would have been `1130 × 0.19 = 214.700`.
Persisted `document_lines.tax_rate` = **7.00 / 13.00 / 19.00**. **N-1 is fixed on document lines.**
`w2-f7-po-mixed-vat-7-13-19-correct.png`.

Confirm → dialog *"Once confirmed, it cannot be edited"* → **Confirmed / Not Received / Unpaid**.

**Goods receipt.** *Receive Goods* opens a well-built dialog: receiving destination (both locations listed,
`Dépôt Ariana` with its accents), per-line qty, **PO unit price vs Delivered unit price with a Variance
readout**, and batch + expiry per line. Posted as **`GRN-2026-0001`**:

```
stock_levels (Main Location):   CREM-BEBE_200 30.0000 | ELIX-APAI_50 20.0000 | GEL-CAVA_500 40.0000   ✅
JE-2026-000001  Dr 37 Stocks de marchandises 450.000 / Cr 408 Fournisseurs FNP 450.000                ✅
JE-2026-000002  Dr 37                        400.000 / Cr 408                        400.000          ✅
JE-2026-000003  Dr 37                        280.000 / Cr 408                        280.000          ✅
                                       total 1130.000 = the HT subtotal, no VAT at receipt stage      ✅
product_batches:  LOT-CRÈME-2026A  2028-06-30   hex 4c4f542d4352 c388 4d452d3230323641   (È = C388)
                  LOT-ÉLIXIR-2026B 2027-12-31   hex 4c4f542d c389 4c495849522d…          (É = C389)
                  LOT-ÇAVA-2026C   2029-03-31   hex 4c4f542d c387 4156412d…              (Ç = C387)
```

**Accents survive into batch numbers** — the deepest point in the stock lane. ✅

**W2-6 — the PO line's Unit Price defaults to the product's SALE price, not its purchase price.** On adding
each product the field pre-filled `24.900 / 32.500 / 12.000` (the retail prices) rather than
`15.000 / 20.000 / 7.000` (`products.purchase_price`, which the import populated correctly). An operator who
accepts the default books the receipt at retail: `Dr 37` would have been **1 852,000** instead of 1 130,000,
inflating stock valuation and WAC by ~64 % and corrupting the three-way match. See §5.

**Observation, not a defect (checked in code before filing).** All 10 *imported* products came out with
`requires_batch_tracking = true`, while the form-created `THE-DETOX_20` came out `false` and the column
default is `false`. This is **deliberate**: `Product::booted()`
(`app/Modules/Product/Domain/Product.php:186-211`) applies
`config("verticals.{$vertical}.product_defaults.requires_batch_tracking")` whenever the attribute is absent,
which is the case for every import row. The consequence is real though — every goods receipt line for every
imported product demands batch + expiry, and *Save and post* stays disabled until they are supplied. Correct
for a parapharmacy; worth the owner knowing it applies to shampoo and shower gel too. The *form* silently
overrides the vertical default to `false`, so the two creation paths disagree.

**N-14 (wave 1, dev-only) reproduced:** `PO-2026-0001` … `PO-2026-0009` exist as orphan drafts with
0,000 totals, burned by StrictMode auto-save before the real `PO-2026-0010`. Known, dev-only, not re-reported.

### F8 — Supplier invoice (three-way match) → GL — **PASS**

*Create supplier invoice* from the received PO. The screen pre-fills from the uninvoiced receipt lines and
shows **VAT per line carried correctly from the products: 7.00 / 13.00 / 19.00**, unit prices at the real
purchase prices, and all three lines **Matched**. Saved as **`SI-2026-0001`**, supplier reference
`FA-SGP-2026-0451`. The 3-way match panel reads ordered 30/20/40, received 30/20/40, invoiced 0, matchable
30/20/40, no price variance.

Posted → GL:

```
JE-2026-000004 (supplier_invoice)
  Dr 408  Fournisseurs - Factures non parvenues   1130.000
  Dr 4456 TVA déductible                           136.700
  Cr 401  Fournisseurs                                       1266.700     ✅ balanced
```

**`4456` carries 136.700, not 214.700.** A flat-19 % bug would have produced the latter. The mixed-rate VAT
survives intact from CSV → product → PO line → receipt → supplier invoice → **general ledger**. That is the
full chain the owner asked about, and it holds.

**Observation (P3):** after posting the GRN, the PO page left *Create supplier invoice* **disabled** and the
status stale at *Not Received*; a manual browser reload was required before the button enabled and the status
flipped to **Received / Fully Received**. The mutation does not invalidate the PO query.

### F9 — Stock transfer Main Location → Dépôt Ariana, and the cancel path — **PASS**

`/inventory/stock-transfers/new`. Both locations offered by name, `Dépôt Ariana` included.

**Transfer 1 — complete path.** `Élixir apaisant` × 8 (of 20 available at source), created as
**`TR-2026-00001`**, status *In Transit*, then *Confirm receipt* → dialog *"Destination stock will be
incremented and the company-wide weighted average cost will be recomputed…"* → **completed**.

```
stock_levels        ELIX-APAI_50 | Main Location | 12.0000     (was 20)
                    ELIX-APAI_50 | Dépôt Ariana  |  8.0000     ← new row created at the destination
stock_movements     ELIX-APAI_50 | Main Location | transfer_out | 8.0000 | 20.0000 → 12.0000
                                 |               | ref App\Modules\Inventory\Domain\StockTransfer / TR-2026-00001
                    ELIX-APAI_50 | Dépôt Ariana  | transfer_in  | 8.0000 |  0.0000 →  8.0000
                                 |               | ref App\Modules\Inventory\Domain\StockTransfer / TR-2026-00001
```

Both movements carry `quantity_before`/`quantity_after` and reference the transfer — the document-per-action
principle is satisfied. The **unit cost snapshot is 20.0000**, i.e. the WAC established by the goods receipt,
not the sale price. **Accents survive the whole transfer document**: product `Élixir apaisant`, destination
`Dépôt Ariana`, initiator `Naïma Ben Aïssa`, and the **lot `LOT-ÉLIXIR-2026B`** displayed against the line.
`w2-f9-stock-transfer-accents.png`.

**Transfer 2 — cancel path.** `Gel douche Ça va` × 5 → **`TR-2026-00002`** → *Cancel transfer* → dialog
*"Any stock already moved into transit will be returned to the source location."* with an optional reason.
Entered an accented reason. Result:

```
TR-2026-00002 | cancelled | Erreur de saisie — quantité déjà réservée     ← accents intact at rest
GEL-CAVA_500  | Main Location | 40.0000                                   ← fully restored (was 40, moved 5, back to 40)
```

Both paths behave correctly and no stock leaked.

### F10 — Sale arm: quote → order → **N-2 refusal** → receive → confirm → delivery → invoice → post — **PASS**

Customer `Zoé Ñuñez` (matched from the accented query `Zoé`). Two lines chosen deliberately: one **stocked**
(`Crème hydratante Bébé 200ml`, 7 %) and one **unstocked** (`Sérum anti-âge`, 19 %, no `stock_levels` row).

**Quote `QT-2026-0003`** — Subtotal 114,800, Tax **18,824** = 1,743 (24.900 × 7 %) + 17,081 (89.900 × 19 %),
Total 133,624. The detail view breaks VAT out **per rate**: *VAT 7.00% 1,743* / *VAT 19.00% 17,081*.
`w2-f10-quote-vat-breakdown-7-and-19.png`. Confirm → **Convert to Order** → `SO-2026-0001`.

**N-2 — VERIFIED FIXED.** Confirming the order with the unstocked line:

```
POST /api/v1/orders/{id}/confirm  ->  422 Unprocessable Content        (wave 1: raw 404 + Laravel stack)
{"error":{"code":"INSUFFICIENT_STOCK",
  "message":"Not enough stock for 'Sérum anti-âge' at 'Main Location'. Available: 0.0000, requested: 1.0000.
             Receive or transfer the goods first, then confirm."}}
```

Typed code, names the product (accents intact) and the location, gives available vs requested, and states the
remedy. **And it reaches the operator** — captured verbatim from the toast with a `MutationObserver` armed
before the click (wave 1's lesson applied):

```
"Not enough stock for 'Sérum anti-âge' at 'Main Location'. Available: 0.0000, requested: 1.0000.
 Receive or transfer the goods first, then confirm."
```

The mechanism is `StockReservationService.php:123-156` + `:249-265`, both now `first()` with an explicit
comment naming campaign N-2. Good fix, well documented.

**Then the remedy was followed, exactly as the brief specifies.** `PO-2026-0011` for `Sérum anti-âge`
×10 @ 55.000 → confirm → GRN (`LOT-SÉRUM-2026D`, expiry 2028-09-30) → `stock_levels` 10.0000.
Re-confirmed the order → **`SO-2026-0001` Confirmed**. The full N-2 loop works.

**Delivery note `DN-2026-0001`** → confirm:

```
stock_levels    CREM-BEBE_200 30 → 29     SERU-ANTIAGE_30 10 → 9        ✅
JE-2026-000006  Dr 603 Variation des stocks 15.000 / Cr 37 15.000       ✅ COGS at WAC (purchase price), not sale price
JE-2026-000007  Dr 603                     55.000 / Cr 37 55.000        ✅
```

**Invoice `INV-2026-0001`** — the headline document. `w2-f11-invoice-tva7-tva19-stamp-duty.png`:

```
Subtotal      114,800 TND
TVA 7%          1,743 TND
TVA 19%        17,081 TND
Stamp Duty      1,000 TND      ← Timbre Fiscal - Facture, correct TN amount
Total         134,624 TND
```

Confirm → **Post** succeeded (the `DELIVERY_REQUIRED_BEFORE_INVOICE` guard is satisfied because the delivery
note exists — the correct order of operations, and it worked). GL:

```
Dr 411  Clients                             134.624
Cr 707  Ventes de marchandises                        89.900
Cr 707  Ventes de marchandises                        24.900
Cr 4457 TVA collectée                                 17.081      ← 19 % line
Cr 4457 TVA collectée                                  1.743      ← 7 % line, SEPARATE
Cr 4375 État - Droit de timbre à reverser              1.000
                                            134.624 = 134.624     ✅ balanced
```

**Two `4457` lines, one per rate** — which is exactly what a DGI declaration needs. Under wave-1's N-1 this
would have been a single line at 21,812.

No payment was recorded on an unposted invoice (per the brief's instruction), so **N-6 was not triggered**.

**Final trial balance — in balance, `Dr 3151.324 = Cr 3151.324`:**

| Account | Dr | Cr | Net |
|---|---|---|---|
| 37 Stocks de marchandises | 1680.000 | 70.000 | 1610.000 |
| 401 Fournisseurs | 0.000 | 1266.700 | −1266.700 |
| 408 Fournisseurs FNP | 1130.000 | 1680.000 | −550.000 |
| 411 Clients | 134.624 | 0.000 | 134.624 |
| 4375 Droit de timbre | 0.000 | 1.000 | −1.000 |
| 4456 TVA déductible | 136.700 | 0.000 | 136.700 |
| 4457 TVA collectée | 0.000 | 18.824 | −18.824 |
| 603 Variation des stocks | 70.000 | 0.000 | 70.000 |
| 707 Ventes de marchandises | 0.000 | 114.800 | −114.800 |

(`408` nets −550.000 because `PO-2026-0011`'s receipt is accrued but not yet supplier-invoiced — correct.)

**Observation (P3):** the sales-invoice journal entry is numbered
`INV-20260824000000-01a03518ed8e723bb994bd902c064c55` with `source_type = 'Document'`, while every other
entry uses the clean `JE-2026-0000NN` with a semantic source type (`goods_receipt`, `supplier_invoice`,
`inventory_exit`). The General Ledger screen will show a 52-character raw identifier for exactly the
documents an accountant looks at most.

### F11 — Batch/lot integrity after the sale — **FAIL (W2-7, P0)**

While verifying the reservation created by the order confirm, `inventory_batch_stock` showed **the same
physical stock counted twice**. Timestamps make the cause unambiguous:

```
batch_pk | sku             | batch_number      | created_at          | quantity | reserved | location
       1 | CREM-BEBE_200   | LOT-CRÈME-2026A   | 2026-08-24 17:46:32 |  30.0000 |   0.0000 | Main Location   ← real lot, from the GRN
       7 | CREM-BEBE_200   | DEFAULT           | 2026-08-24 18:36:24 |  30.0000 |   1.0000 | Main Location   ← created by the ORDER CONFIRM
       6 | SERU-ANTIAGE_30 | LOT-SÉRUM-2026D   | 2026-08-24 18:33:10 |  10.0000 |   0.0000 | Main Location   ← real lot, from the GRN
       8 | SERU-ANTIAGE_30 | DEFAULT           | 2026-08-24 18:36:24 |  10.0000 |   1.0000 | Main Location   ← created by the ORDER CONFIRM
```

`18:36:24` is the moment `SO-2026-0001` was confirmed. See **W2-7** in §5.

### F12 — Reports, VAT declaration and exports — **PASS (N-4 did NOT reproduce); B-19 demonstrated at scale**

**VAT declaration `/finance/vat-periods` → August 2026 — the page RENDERS.** Wave-1's **N-4** (crash at
`VatReportPage.tsx:55` on `report.period.label`) **did not reproduce**, and the `document_count` wave 1 could
only see in raw JSON is now visible to a human. `w2-f12-vat-declaration-7-and-19-breakdown.png`:

| Rate (%) | Base (HT) | VAT amount | Documents |
|---|---|---|---|
| 7.00 | 24,900 | 1,743 | 1 |
| 19.00 | 89,900 | 17,081 | 1 |
| **Total** | **114,800** | **18,824** | **2** |

Special items: *Timbre Fiscal Count 1 / Amount 1,000 / Retenue à la Source 0,000*. Amount Payable **18,824**.
This is a correct Tunisian declaration for the sale that was made — **the mixed 7 %/13 %/19 % catalogue
reaches the DGI declaration correctly split by rate.** (I report N-4 as *did not reproduce* rather than
*fixed*; I did not diff the fix.)

**B-19 demonstrated, at a much larger number than wave 1.** *Input VAT* reads **0,000** while `4456 TVA
déductible` carries **136.700 TND** in the ledger from the posted supplier invoice. That is 136.700 TND of
recoverable VAT the tenant would fail to reclaim. Known hole, recorded not re-reported — but the magnitude is
worth the owner seeing.

**Exports.** VAT *Export* offers **PDF / CSV / TEIF XML**. The CSV downloaded and parsed correctly:

```
Rate,Direction,"Base HT","VAT Amount","Document Count","Is Recoverable"
7.00,Output,24.900,1.743,1,Yes
19.00,Output,89.900,17.081,1,Yes
```

Amounts exact, dot decimals (machine-readable — correct for an export). It contains **no free text**, so it
cannot test the accent round-trip. **The accent round-trip through an export was proven instead by the import
result workbook** (F3): the XLSX shared-strings table came back with `Société Générale de Parapharmacie –
Tunis`, `Zoé Ñuñez` and `Rue Ibn Khaldoun, L'Aouina` byte-perfect.

**Stock Levels `/inventory/stock`** — all accented products render across both locations, quantities matching
the DB exactly: `Sérum anti-âge` 9 @ Main, `Élixir apaisant` 8 @ **Dépôt Ariana** + 12 @ Main,
`Crème hydratante Bébé 200ml` 29, `Gel douche Ça va` 40.
`w2-f12-stock-levels-both-locations.png`.

**General Ledger `/finance/ledger`** — renders every entry, descriptions are genuinely good
(*"VAT 19.00% from Invoice INV-2026-0001"*, *"VAT 7.00% from Invoice INV-2026-0001"*) and account names keep
their accents (`État - Droit de timbre à reverser`, `TVA collectée`). Two known warts on display:
**N-8 reproduces exactly** — `$134.6240`, `$17.0810`, `$1.0000` on a TND tenant (dollar sign, 4 decimals) —
and the sales-invoice entry number renders as the full 52-character
`INV-20260824000000-01a03518ed8e723bb994bd902c064c55`.
`w2-f12-ledger-usd-and-long-entry-number.png`.

---

## 2. PASS / FAIL table

| # | Flow | Result | Note |
|---|---|---|---|
| 1 | Self-service signup (2nd tenant, day-one) | **FAIL** | **W2-1** — 403 deadlock when the browser holds another company's selection; recovers via logout→login |
| 1b | Tenant provisioning (chart of accounts, TN VAT, payment methods, domain slug) | **PASS** | 141 accounts, TVA 19/13/7/Exonéré + 3× Timbre, accented company name transliterates correctly |
| 2 | Second location `Dépôt Ariana` | **PASS** | UTF-8 correct at rest |
| 3 | Partners import (UTF-8, semicolon) | **PASS** | **W2-2** on the preview grid only; data + workbook correct |
| 4a | Products import — **UTF-8 no BOM** | **PASS** | 5/5, accents byte-perfect |
| 4b | Products import — **UTF-8 with BOM** | **PASS** | 2/2, BOM stripped, `name` maps |
| 4c | Products import — **Windows-1252** | **PASS** | 2/2, incl. CP1252 `0x97` em dash → U+2014 |
| 4d | Category assignment on import | **FAIL** | **W2-3** — `category_name` silently dropped on every row, no warning |
| 4e | Brand assignment on import | **PASS** | brands created with accents |
| 4f | `tax_rate` on import (7/13/19) | **PASS** | persisted exactly as in the CSV |
| 5a | Bad row: missing required field | **PASS** | Error + row number + field, accents preserved |
| 5b | Partial-import confirmation guard | **PASS** | clear modal, Cancel / Import Valid Rows |
| 5c | Bad row: duplicate SKU | **FAIL** | **W2-4** — silently overwrites the existing product, reported as a plain success |
| 6a | Catalog list / detail rendering | **PASS** | TTC computed at the product's own rate |
| 6b | Product form create at TVA 13 % (**N-1**) | **PASS** | `tax_rate=13.00` + configuration linked |
| 6c | Editing an imported product | **PARTIAL** | **W2-5** — tax control blank; rate survives the save; **N-13** reproduced |
| 6d | Search (accent handling) | **OBSERVATION** | case-insensitive, **not** accent-insensitive |
| 7a | PO with mixed 7/13/19 VAT (**N-1**) | **PASS** | tax 136,700 exact, line rates persisted |
| 7b | PO unit-price default | **FAIL** | **W2-6** — defaults to SALE price on a purchase order |
| 7c | Goods receipt → stock + GL | **PASS** | Dr 37 / Cr 408 × 3 = 1130.000; accented lots stored |
| 8 | Supplier invoice, 3-way match → GL | **PASS** | Dr 408 + Dr 4456 **136.700** / Cr 401, balanced |
| 9a | Stock transfer complete path | **PASS** | both locations move, movements reference the transfer |
| 9b | Stock transfer cancel path | **PASS** | stock fully restored, accented reason stored |
| 10a | Quote → confirm → order | **PASS** | per-rate VAT breakdown on the document |
| 10b | **Order confirm with no stock (N-2)** | **PASS** | typed **422 `INSUFFICIENT_STOCK`**, message reaches the toast |
| 10c | Receive stock → re-confirm order | **PASS** | `SO-2026-0001` Confirmed |
| 10d | Delivery note → stock + COGS | **PASS** | Dr 603 / Cr 37 at WAC |
| 10e | Invoice + Timbre Fiscal + post → GL | **PASS** | two `4457` lines by rate, balanced |
| 10f | Trial balance | **PASS** | `Dr 3151.324 = Cr 3151.324` |
| 11 | Batch/lot integrity after order confirm | **FAIL** | **W2-7 (P0)** — phantom `DEFAULT` batch duplicates on-hand quantity |
| 12a | VAT declaration screen | **PASS** | N-4 did **not** reproduce; 7 % and 19 % split correctly |
| 12b | Input VAT on the declaration | **FAIL (known B-19)** | 0,000 vs 136.700 in `4456` |
| 12c | VAT CSV export | **PASS** | amounts exact; no free text to test accents |
| 12d | Accent round-trip through an export | **PASS** | proven via the import result workbook (XLSX) |
| 12e | Stock levels report | **PASS** | accents + both locations correct |
| 12f | General Ledger formatting | **FAIL (known N-8)** | `$134.6240` on a TND tenant |

---

## 3. Headline answer to the owner's question

> *"We once had an import file that did not support French accented characters… that must be supported ALL
> THE WAY: import → purchase order → goods receipt → supplier invoice → stock transfer between locations →
> sale/receipt."*

**It is supported all the way. Every link in that chain was exercised and verified at the byte level.**

```
CSV (UTF-8 / UTF-8+BOM / Windows-1252)
   → import preview grid            Crème hydratante Bébé 200ml · Pâte à l'eau · Gel douche Ça va
   → products at rest               4372 c3a8 6d65 … (è = C3A8, correct UTF-8; 0 mojibake rows)
   → brands                         Laboratoire Éclat · Ça va · Institut Français
   → purchase order                 Société Générale de Parapharmacie – Tunis (en dash intact)
   → goods receipt + LOTS           LOT-CRÈME-2026A · LOT-ÉLIXIR-2026B · LOT-ÇAVA-2026C
   → supplier invoice               all three lines, right money, right VAT
   → stock transfer                 Élixir apaisant → Dépôt Ariana, lot shown, cancel reason
                                    "Erreur de saisie — quantité déjà réservée" stored intact
   → sale: quote/order/DN/invoice   Zoé Ñuñez, both products, TVA 7 % + TVA 19 % + Timbre Fiscal
   → GL, VAT declaration, exports   account names, XLSX round-trip — all clean
```

The Windows-1252 file — the exact shape of the historical bug — decoded perfectly, **including the CP1252
`0x97` em dash**, because `SpreadsheetParserService::toUtf8()` converts **per value** rather than per file.
Zero mojibake (`Ã`, `Â`, `�`) and zero `?` substitutions were found anywhere in the tenant database.

The defects found in this wave are **not** encoding defects. They are elsewhere, and two of them are serious.

---

## 4. Wave-1 defects re-tested

| Wave-1 item | Status in wave 2 | Evidence |
|---|---|---|
| **N-1** (P0) non-default VAT silently dropped | ✅ **VERIFIED FIXED**, on all four arms | product form (`tax_rate=13.00` + configuration), imported products (7/13/19 exact), PO line totals (tax **136,700**, not 214,700), sales invoice GL (two `4457` lines, 17.081 + 1.743) |
| **N-2** (P0) sales-order confirm 404s with no stock row | ✅ **VERIFIED FIXED** | typed **422 `INSUFFICIENT_STOCK`** naming product + location + available/requested + remedy, delivered to the operator's toast; loop closes after receiving stock |
| **N-4** (P1) VAT declaration crashes | ✅ **did not reproduce** | page renders, `document_count` visible, 7 %/19 % split |
| **N-5** (P1) `has-pins` | not re-tested | out of scope for this wave |
| **N-8** (P2) GL prints `$` + 4dp | ❌ **reproduces** | `$134.6240`, `$17.0810` on a TND tenant |
| **N-9** (P2) no units seeded | ❌ **reproduces** | `units` = 0; quantities render `29.0000` |
| **N-13** (P3) product-form save silent no-op | ❌ **reproduces** | `scrollY` 0, no toast, hidden required Parapharmacy category |
| **N-14** (dev-only) auto-save burns numbers | ❌ **reproduces** | `PO-2026-0001…0009` orphan drafts before the real `PO-2026-0010` |
| **B-19** supplier-invoice VAT not in the declaration | ❌ **reproduces, larger** | Input VAT `0,000` vs `4456` = **136.700 TND** |
| **a3** Tax ID column | ❌ **reproduces via import too** | `PartiesRowMapper.php:23` maps the `tax_id` import column into `partners.vat_number`, leaving `partners.tax_id` NULL — so imported partners also show `-` in the TAX ID column |
| **N-6** payment on unposted invoice | not triggered | posted before paying, per the brief |
| **N-3** opening-balances wizard | not re-tested | this wave used GRN-based stock, not opening balances |

---

## 5. NEW defects (W2-*)

### W2-7 — **P0** — Confirming a sales order creates a phantom `DEFAULT` batch that duplicates the product's entire on-hand quantity

**Symptom.** After `SO-2026-0001` was confirmed, `inventory_batch_stock` held **two rows per product for the
same physical stock** — the real lot from the goods receipt, and a `DEFAULT` batch created at the moment of
confirm carrying a **full copy** of the on-hand quantity:

```
batch_pk | sku             | batch_number     | created_at          | quantity | reserved | location
       1 | CREM-BEBE_200   | LOT-CRÈME-2026A  | 2026-08-24 17:46:32 |  30.0000 |   0.0000 | Main Location
       7 | CREM-BEBE_200   | DEFAULT          | 2026-08-24 18:36:24 |  30.0000 |   1.0000 | Main Location
       6 | SERU-ANTIAGE_30 | LOT-SÉRUM-2026D  | 2026-08-24 18:33:10 |  10.0000 |   0.0000 | Main Location
       8 | SERU-ANTIAGE_30 | DEFAULT          | 2026-08-24 18:36:24 |  10.0000 |   1.0000 | Main Location
```

`18:36:24` is exactly when the order was confirmed. `stock_levels` says 30 and 10; the batch ledger says
60 and 20.

**Mechanism** (`app/Modules/Inventory/Application/Services/StockReservationService.php`):
`reserve()` at `:101-105` resolves an implicit batch via
`resolveDefaultBatchIdForImplicitReservation()`; that method (`:235-278`) returns early only if the product
is not batch-tracked or has no `stock_levels` row, and otherwise calls

```php
$batch = $this->batchStockService->ensureDefaultBatch(
    …, targetQuantity: (string) $stockLevel->quantity, …
);
```

i.e. it seeds a `DEFAULT` batch to the **full aggregate on-hand quantity without subtracting the quantity
already held by real lots**. For stock that arrived through a GRN with explicit lots — which is the *normal*
path, and the only path this campaign used — that quantity is already fully represented, so the DEFAULT batch
double-books it.

**Why this is P0 for tenant #1.** The parapharmacy vertical forces `requires_batch_tracking = true` on every
imported product (`Product.php:186-211`), so **every imported product hits this on its first sales order**.
Consequences: batch-level available stock reads ~2× the real stock; the reservation is booked against a batch
with **no expiry date**, which defeats FEFO in a vertical where expiry control is the entire point of batch
tracking; and `stock_levels.reserved` stayed **0.0000** immediately after confirm, so the aggregate
availability the operator sees is not reduced by the confirmed order either.

**What I did NOT prove:** that this lets stock actually be over-sold. I observed the duplicate rows and the
zero aggregate reservation; I did not construct an oversell. That check belongs in the fix lane.

**Screenshot:** none — this is a DB-level finding; the query output above is the evidence.

### W2-1 — **P1** — A fresh signup lands on a completely dead app when the browser already holds another company's selection, with no in-product recovery

**Symptom.** `POST /api/v1/auth/register` → **201**, then **every** authenticated call → **403**
`{"message":"Access denied: User does not have access to the requested company."}` — `/user/companies`,
`/company/config`, `/company/locations`, `/notifications/unread-count`, all ~16 report endpoints. The Owner
Dashboard shows *"Could not load summary"* / *"Report unavailable."* everywhere, the sidebar collapses to
three sections, and the header displays **a different tenant's company name**.

**Mechanism.**
- `apps/web/src/lib/api.ts:169-171` attaches `X-Company-Id` from `useCompanyStore` to **every** request,
  including `/user/companies`.
- The selection is persisted origin-wide under `autoerp-company-selection`
  (`apps/web/src/stores/companyStore.ts:26`).
- `clearAllAppState()` (`apps/web/src/lib/clearAppState.ts:12-19`), which resets the company/location stores,
  is called from **only two places** — `features/auth/useLogout.ts` and `features/auth/AuthProvider.tsx`
  (401 handler). Its own docblock says *"Must be called on every logout and 401 session expiry."*
- **Neither `RegisterPage.tsx:88-101` nor `LoginPage.tsx:88-91` calls it.** They call `setAuth(...)` and
  navigate.

So a new session inherits the previous account's company id and sends it everywhere. Because
`/user/companies` **also** carries the stale header and 403s, the store can never learn the new membership
list — the deadlock cannot self-heal. The company switcher opens **empty** (only *Add Company*), so there is
no recovery affordance in the UI.

**Recovery that works:** Sign out → sign in (logout runs `clearAllAppState`, and the key is gone —
verified `localStorage.getItem('autoerp-company-selection') === null`).

**Scope, stated honestly:** a genuinely clean browser is unaffected (wave 1 signed up fine). This bites any
browser that has previously held a company selection — the owner's own machine during demos and onboarding,
a shared workstation, or a second tenant created from the same browser, which is exactly what this campaign
did. Two candidate fixes: call `clearAllAppState()` on successful register/login, and/or stop sending
`X-Company-Id` on `/user/companies` so the membership list can always be fetched.

**Screenshots:** `w2-f1-post-signup-403-stale-company.png`, `w2-f1-company-switcher-empty-deadlock.png`,
`w2-f1-recovered-after-logout-login.png`.

### W2-6 — **P1** — Purchase-order lines default Unit Price to the product's SALE price

**Symptom.** Adding a product to a purchase order pre-fills *Unit Price* with `products.sale_price`, not
`products.purchase_price`. Observed: `24.900 / 32.500 / 12.000` (retail) where the correct buying prices
`15.000 / 20.000 / 7.000` were sitting in `purchase_price`, populated by the import.

**Impact.** An operator who accepts the default books the goods receipt at retail. For this PO that would
have posted `Dr 37 Stocks` **1 852,000** instead of **1 130,000** — stock valuation and weighted-average cost
inflated ~64 % — and the supplier-invoice three-way match would then be against the wrong money. The value is
visible and editable, so it is a bad default rather than a hidden number; but it is plausible-looking, it is
on the primary purchasing path, and it moves inventory valuation and COGS.

**Screenshot:** `w2-f7-po-mixed-vat-7-13-19-correct.png` (taken after correcting the prices; the defaults are
quoted above from the live form state).

### W2-3 — **P1** — The products import silently discards `category_name` on every row

**Symptom.** All 9 imported rows carried `category_name` (`Soins Bébé`, `Aromathérapie`, `Hygiène`,
`Soins Visage`); the column was mapped and the values were visible in the preview grid. After import:
`select count(*) from categories` = **0**, and every product has `category_id = NULL`. The wizard reported
*"Successfully imported 5 / Failed to import 0"*. `select … from import_rows` shows **`warnings` NULL on all
9 rows** — the operator is never told.

**Mechanism.** `app/Modules/Product/Application/Services/ProductService.php:82-89`:

```php
if (isset($data['category_name']) && $data['category_name'] !== '') {
    $category = Category::where('company_id', $companyId)->where('name', $data['category_name'])->first();
    if ($category !== null) { $attributes['category_id'] = $category->id; }
}
```

Lookup-only: **no create, no warning, no error**. On a day-one tenant `categories` is empty, so *every*
`category_name` is dropped. The immediately following brand block (`:91-94`) uses
`brandResolution->resolve(...)`, which **does** create on miss — so the same import creates brands and
silently discards categories.

**Impact.** Revenue-by-Category reporting is empty for imported catalogues; category-based tax defaulting
(`getDefaultTaxForNewProduct($company, $categoryId)`) can never apply; and combined with N-13 the imported
products sit in a state the product form itself rejects.

**Screenshot:** `w2-f4-products-utf8-preview-accents.png` (values present in preview), catalog detail shows
`Category —`.

### W2-4 — **P2** — A duplicate SKU silently overwrites an existing product and is reported as a plain success

Row 2 of `products-bad.csv` reused SKU `CREM-BEBE_200`. The wizard marked it **Valid**, the *Import with
Errors* dialog counted it among *"2 rows are valid and will be imported"*, and the result read
*"Successfully imported 2 / Failed to import 0"*. In the DB the existing product was replaced:

```
Crème hydratante Bébé 200ml  →  Duplicata Crème hydratante
description likewise replaced with "SKU déjà utilisé — doit échouer"
```

Upsert-on-SKU is a defensible design for re-importing a corrected file. The defect is that **nothing
distinguishes "created" from "updated"** anywhere in the wizard, so an operator who accidentally ships a file
with a duplicate SKU destroys catalogue data and is told it succeeded. A *"N created, M updated"* line in the
summary — and ideally naming them in the result workbook — would close it. Given how careful the
*Import with Errors* dialog otherwise is, this omission stands out.

### W2-5 — **P2** — The tax selector reads "Select tax…" on imported products that do have a tax rate

The products import writes `products.tax_rate` but never links `default_tax_configuration_id`
(`ProductService::upsert` only defaults the *rate*, `:97-111`). The product edit form and the document line
editor both bind their tax control to the **configuration id**, so both render an empty *"Select tax…"* for a
product that is genuinely taxed at 7 %.

Verified **not** destructive today: a PATCH that changed only the name left `tax_rate = 7.00` intact, and PO
line totals still computed at 7/13/19 with the control blank. But the operator is shown a blank where a real
rate exists, cannot see from the document line what VAT is being charged, and any attempt to "fill in the
blank" changes the rate. The import should resolve and link the matching `tax_configurations` row.

### W2-2 — **P2** — The import preview grid renders `-` for every column whose source header differs from the target field

In the partners preview, `city`, `address`, `country` and `vat_number` all showed `-` while `name`, `type`,
`email` and `phone` rendered correctly. The four blanked columns are exactly the four whose **source header
name ≠ target field name** (`city`→`address_city`, `address`→`address_line1`, `country`→`address_country`,
`vat_number`→`tax_id`); the four that rendered are identity mappings. The grid prints the **source** header
but reads the row by the **target** key.

Display-only — the data imported correctly and the result workbook shows it — but the review step is the last
gate before writing to the tenant's database, and it under-reports precisely the columns a user had to map by
hand, which is where mapping mistakes actually live.

**Screenshot:** `w2-f2-partners-preview-accents-ok-mapped-cols-dash.png`.

### W2-8 — **P3** — "Import More Data" is a dead button

On the wizard's Done step, *Import More Data* does nothing — clicked twice, the wizard stayed on Done, no
navigation, no state reset. `ImportWizardPage.tsx:1093`:

```tsx
onClick={() => navigate(`/settings/import/${importType}`)}
```

It navigates to the URL the user is **already on**, so React Router treats it as a no-op and the component
never remounts. Anyone importing several files — three encodings, or a catalogue in batches — must go *Back
to Import Dashboard* and re-enter. Workaround exists; the button is simply inert.

---

## 6. Observations (not defects)

1. **Search is accent-sensitive.** `crème`/`Crème` match (case-insensitive); `creme`, `elixir`, `levres`,
   `ca va` return **0** results, no error. For a French-language tenant this is what users will actually
   type. Needs `unaccent`/collation on the search predicate. Reported as an observation per the brief.
2. **List sort is byte-order, not French collation** — `Élixir` sorts last, `Pâte` after `Produit`.
3. **Every imported product gets `requires_batch_tracking = true`.** This is *deliberate*
   (`Product.php:186-211` applies `config("verticals.parapharmacy.product_defaults.requires_batch_tracking")`
   whenever the attribute is absent, which is always true for import rows). I checked the code before filing
   it as a bug and it is not one. Two things worth the owner knowing: it applies to shower gel and shampoo as
   well as medicines, forcing batch + expiry entry on every goods-receipt line; and the product **form**
   silently overrides the same default to `false`, so the two creation paths disagree.
4. **The PO page does not refresh after posting a goods receipt** — *Create supplier invoice* stayed disabled
   and the status stale at *Not Received* until a manual browser reload. The mutation does not invalidate the
   PO query.
5. **Sales-invoice journal entries are numbered inconsistently** — `INV-20260824000000-01a03518ed8e…`
   (52 chars, `source_type = 'Document'`) versus the clean `JE-2026-0000NN` used by goods receipts, supplier
   invoices and inventory exits. The General Ledger shows the long raw identifier for the documents an
   accountant reads most.
6. **`"1 rows have errors"`** — plural not handled in the partial-import dialog.
7. **`wizard.progressAriaLabel`** is a raw i18n key in the import wizard's `<nav aria-label>` (rule 11).
8. **Line-level `tax_amount` is NULL** on `document_lines` while the header carries the correct total. The
   header is right and every downstream number was correct, so this is a note, not a finding.
9. **Two things I nearly filed as defects and did not**, having checked the code first: the missing
   Stock Transfers nav entry (it exists at `Sidebar.tsx:219`; the Inventory group was simply collapsed in the
   DOM) and the batch-tracking default above. Both are recorded here so the next run does not re-chase them.

---

## 7. Verdict — can the owner start manual testing / onboarding?

**On the owner's actual question — accents end to end — the answer is yes, unreservedly.** All three
encodings, including the Windows-1252 file that matches the historical bug, survive from CSV through
purchase, receipt, supplier invoice, inter-location transfer, sale, GL and export without a single mojibake
byte. That arm is done and it is solid.

**On promotion: still ⛔ NOT ready**, but the gap is much narrower than wave 1.

**What genuinely improved.** Both wave-1 P0s are verified fixed on every arm this campaign could reach:
N-1's mixed 7 %/13 %/19 % VAT is now correct in the product, the document line, the PO total, the supplier
invoice GL, the sales invoice GL and the DGI declaration; N-2 now returns a typed, actionable, operator-
visible 422 instead of a raw 404, and the remedy it proposes actually works. N-4 did not reproduce. The
trial balance closes at `3151.324` on both sides, COGS posts at WAC, the Timbre Fiscal is right, and the VAT
declaration splits by rate exactly as the DGI needs. The import wizard's error handling — row numbers, named
fields, the partial-import confirmation — is genuinely well built.

**What must be fixed before onboarding a real tenant:**

1. **W2-7 (P0)** — every batch-tracked product duplicates its own stock in the batch ledger on its first
   sales order, and the reservation lands on an expiry-less `DEFAULT` batch. In a parapharmacy, where the
   vertical turns batch tracking on for everything, this corrupts the one ledger that exists to control
   expiry. Nothing warns anyone.
2. **W2-3 (P1)** — a catalogue import silently loses its categories. The owner's first act of onboarding is
   importing a catalogue; it will report complete success and quietly produce an uncategorised catalogue that
   the product form itself will not accept (N-13).
3. **W2-6 (P1)** — purchase orders default to retail prices. The one number an operator is most likely to
   accept without thinking is the one that inflates stock valuation.
4. **W2-1 (P1)** — the owner will hit this the moment they create a second tenant, or demo signup on a
   browser they have used before. The app dies with no in-product way out. The fix is small.

**Recommended sequencing.** W2-7 belongs with the inventory/costing lane and should be reviewed against an
oversell test I did not write. W2-3, W2-4 and W2-5 are all the same lane (the products importer) and would be
cheap to fix together — resolve-or-create categories, link the tax configuration, and report created-vs-updated
counts. W2-1 is a two-line frontend change plus a decision about whether `/user/companies` should ignore
`X-Company-Id`. W2-6 is a one-line default.

**Can the owner start manual testing today?** Yes — with two cautions: do not trust batch/expiry numbers
until W2-7 is fixed, and set product categories manually after any import. **Onboarding a paying tenant
should wait** for W2-7 and W2-3.

---

*Artifacts: `.playwright-mcp/campaign-wave2/` (17 referenced screenshots + the 5 source CSVs under `csv/`), git-excluded.
Tenant `01a034af-94ea-713d-8ce0-462216bf6ab5` left in place for inspection. No production code was modified,
no git writes were made, and the PHPUnit suite was never run. All DB verification was read-only `psql`, with
monetary amounts read as strings.*
