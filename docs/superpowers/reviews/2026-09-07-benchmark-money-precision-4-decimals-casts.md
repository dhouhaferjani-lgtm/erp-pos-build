# Benchmark note — should the Eloquent money casts follow storage to `decimal:4`?

> **Owner decision note.** Storage widening to `decimal(15,4)` on `journal_lines.debit/credit` and
> `payment_repositories.balance/last_reconciled_balance` is already RULED (A1 follow-up,
> `docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md`). Operational precision stays the country
> preset. **Open: do the Eloquent `decimal:3` casts on those models — and by extension the other 17
> `decimal:3` casts in Treasury/Accounting — move to `decimal:4` now, or stay at 3?**
> Tracked as A10 in the same file. Convention-10 format. **The owner rules; nothing below is a decision.**

---

## 1. What this project already rules on rounding (recall, verified 2026-09-07)

| Rule | Where | Substance |
|---|---|---|
| Storage floor vs operational scale | `docs/architecture/precision-contract.md:9,17`; `CLAUDE.md:72` | Currency columns `decimal(N,3)` floor; **display renders at `getDecimals(currency)`**, not at storage scale. Scale-3 storage on a EUR tenant is already normal — the 3rd digit is `0` and nobody sees it. |
| Round **once**, at the boundary | `precision-contract.md:25-26`; `CLAUDE.md:73` | Intermediates at `scale+1` / `scale+4`; one rounding at the write boundary. |
| Write boundary truncates, presentation rounds | `precision-contract.md:26,71`; `CurrencyScale::bcround` docblock `app/Shared/Domain/CurrencyScale.php:147-171` | `bcformat` truncates (write); `bcround` is half-away-from-zero and is *designated* the **presentation / GL-posting boundary** helper, **citing NC 01 §62**. |
| Q4 display-rounding ruling | `docs/superpowers/reviews/2026-08-05-l4-api-precision-gate.md:200-225` | `bcround` is CORRECT at presentation; truncating would *manufacture* a server-vs-device millime disagreement. Grounds 3 cites the NC 01 §62 "carry higher precision at rest, round at the boundary" reading. |
| **Storage may legitimately exceed the currency scale** | `2026_05_30_000000_widen_wac_cost_columns_to_scale_6.php` docblock | WAC/cost columns carried at **scale 6** at rest, rounded HALF-UP to the currency scale **only at the GL/COGS posting boundary**. Same docblock, verbatim: *"Journal columns (journal_lines.debit/credit) stay at the currency scale 3 — COGS is rounded at the posting boundary before it lands there."* |
| A scale-4 money column already ships, with a scale-4 cast | `pos_shifts` `decimal(16,4)` (`2026_04_25_000002_widen_pos_shifts_monetary_columns_to_scale_4.php`); casts `app/Modules/POS/Domain/Shift.php:90-94`; guard `tests/Unit/POS/PosShiftsScale4Test.php` | **This is the in-repo precedent for option B** and it works: `expected_cash` `'300.0005'` renders `300.001` on a TND report via `bcround`. Note the same model mixes `decimal:4` and `decimal:3` (`tolerance_writeoff_total`, `:94`) without incident. |
| Emission normalisation already assumes 4 | `FormatsReportNumbers.php:49` `MONEY_NORMALISATION_SCALE = 4`, `:133` | Chosen because 4 is *"strictly finer than every currency scale in `CurrencyScale::SCALE_MAP` (max 3)"*. Reports emit through `bcround($value, $countryScale)` (`TrialBalanceService.php:111-113`), so **report surfaces are already insulated from a scale-4 cast.** |
| Laravel's `decimal:N` cast semantics | `vendor/…/Concerns/HasAttributes.php:1512-1519` | `BigDecimal::of((string)$value)->toScale($decimals, RoundingMode::HALF_UP)` — a **read-side re-scaler that rounds half-up**. It is not a validator; it silently reshapes whatever the DB holds. |

**Two facts that bound the whole question:**

1. **No 4th decimal can be written today.** Every writer formats at the country-resolved scale
   (`AccountingService.php:182` `getScaleSafe($document->currency, 3)`; `TreasuryMovementService.php:58,234`
   `getScale($intent->currency)`), the FormRequest ceilings are `{1,3}`
   (`CreateJournalEntryRequest.php:41-42`), and **no seeded country preset is 4** — `CountriesSeeder.php`
   has 3×`0`, 30×`2`, 7×`3`, zero `4`; `CurrencyScale::SCALE_MAP` contains no 4-dp entry either.
2. **Nothing fiscal or device-side reads these columns.** `journal_lines` appears in no Fiscal-module
   hash canonicalization (grep over `app/Modules/Fiscal`); `apps/pos` never references `journal_lines`
   or `last_reconciled_balance`. Comparisons in Treasury use `bccomp` (184 sites), never string equality
   (zero `===`/`!==` string comparisons against `->debit|credit|balance` in `app/`).

---

## 2. Benchmark

Sources are official docs or the shipped source of each system. `path` citations are AutoERP at HEAD 2026-09-07.

| Guarantee | Odoo 17/18 | ERPNext / Frappe v15 | Dolibarr | Tunisian norm | AutoERP today (path:line) | Gap | Decision |
|---|---|---|---|---|---|---|---|
| **1. Storage scale of booked journal amounts** | `account.move.line.debit/credit/balance` are `fields.Monetary` (`account_move_line.py:113-127`); `Monetary._column_type = ('numeric','numeric')` (`odoo/fields.py:1705-1720`) → PG **`numeric` with NO declared precision/scale** — capacity effectively unbounded | GL Entry `debit`/`credit` are fieldtype `Currency` (`gl_entry.json`); Frappe's MariaDB type map is `"Currency": ("decimal","21,9")` (`frappe/database/mariadb/database.py:174`) → **capacity 9 decimals** | `llx_accounting_bookkeeping.debit/credit` are **`double(24,8)`** — capacity 8 (and binary float, which Dolibarr itself has an open issue about) | NC 01 §62: *« L'arrondi n'est pas admis dans l'enregistrement des opérations. Il n'est admis que pour la présentation. »* — no scale prescribed, but rounding at recording is forbidden | `decimal(15,3)` live, widening to `decimal(15,4)` (ruled) | none — **AutoERP is the *narrowest* of the four even after widening** | **ALREADY (widening ruled)** |
| **2. Unit-price / rate precision above the currency scale** | `price_unit` is `fields.Float` (NOT Monetary) bound to `decimal.precision` "Product Price", default **2**, routinely raised to 4-5 (`account_move_line.py:359-363`; `product_data.xml:19-22`); `decimal.precision` "Payment Terms" ships at **6** (`account_data.xml`) | `float_precision` (System Settings, options 2-9, **fallback 3**) is separate from `currency_precision` (options 0-9, blank ⇒ number-format decimals) — `frappe/model/meta.py get_field_precision` | **`MAIN_MAX_DECIMALS_UNIT = 5`** vs **`MAIN_MAX_DECIMALS_TOT = 2`** vs `MAIN_MAX_DECIMALS_SHOWN = 8` (`conf.class.php`, defaults) — an explicit unit-vs-total split | **BCT publishes FX rates at 4 decimals** (EUR 3,3834 / USD 2,9174). VAT *prorata de déduction* is explicitly **2 decimals**. **No sourced Tunisian rule permits or requires 4-decimal unit prices** | `exchange_rate` `(15,6)`; WAC/cost `(19,6)`; eco_tax/voucher scale 5; percent ceiling `{1,2}` (`precision-contract.md:11,36`) | none | **ALREADY** |
| **3. Rounding rule at the posting boundary** | `Monetary.convert_to_column_insert` → `float_repr(currency.round(value), currency.decimal_places)` (`fields.py:1750-1770`); `currency.round()` = `float_round(amount, precision_rounding=self.rounding)`; `decimal_places` is **derived** from `rounding` (TND `rounding=0.001` → 3) | Business logic rounds: `general_ledger.py` resolves `precision = get_field_precision(GL Entry.debit, currency=default_currency)` once and applies `flt(x, precision)`; residual goes to the **Round Off account** if `abs(diff) >= 1/10**precision` | `price2num($v,'MT')` at `MAIN_MAX_DECIMALS_TOT`; `MAIN_ROUNDING_RULE_TOT` sets a *granularity* (e.g. 0.05), not a digit count | NC 01 §62 permits rounding only at presentation. EC Reg. 1103/97 Arts. 4-5 is the cleanest external articulation: rate carried at 6 significant figures, money rounded **once** at the end | `bcround($v, $scale)` half-away-from-zero at the GL/COGS posting boundary; WAC carried at 6 and rounded once (`CurrencyScale.php:147-198`; scale-6 migration docblock) | none | **ALREADY** |
| **4. Rounding rule at display** | `decimal_places` from the currency; display never exceeds it | `precision` is explicitly display-oriented — Frappe docs: *"Precision changes affect display only—not stored values"* | `MAIN_MAX_DECIMALS_SHOWN = 8` is a display cap, independent of storage | NC 01 §62 second sentence — rounding **is** admitted for presentation | `bcround` at `getDecimals(currency)`; ruled 2026-08-05 Q4; pinned by `ReportNumberEmissionTest` | none | **ALREADY** |
| **5. ORM/read precision vs storage precision** | Value read back already equals currency precision **because the WRITE rounded it**. There is **no read-side re-scaler** — Odoo never narrows on read | Reads return the stored `decimal(21,9)` value **verbatim**; `base_document` applies bare `flt()` with no precision at the ORM boundary. **No read-side narrowing** | Reads return the stored `double`; `price()` formats at display time. **No read-side narrowing** | not addressed (an ORM concept) | **Laravel `decimal:3` cast re-scales EVERY read half-up** (`HasAttributes.php:1512`) — `JournalLine.php:54-55`, `PaymentRepository.php:214-215`, + 15 more `decimal:3` casts in Treasury/Accounting | **THIS IS THE GAP.** All three benchmarks let the read return what storage holds; only AutoERP narrows below its own column scale. A `decimal:3` cast on a `decimal(15,4)` column means a 4th decimal that ever lands is **silently rounded away on read** — the app would report a number the DB does not hold | **OWNER DECISION (§3)** |
| **6. Percentages / divisions carried above the money scale** | "Payment Terms" precision 6; `Float` columns are `numeric` when `digits` set, `double precision` otherwise | `float_precision` fallback **3**, configurable to 9, independent of `currency_precision` | `MAIN_MAX_DECIMALS_UNIT = 5` covers unit-level division results | Owner's premise ("even in Tunisia they use four decimals when calculating percentages or dividing") is **directionally supported but not sourced to a Tunisian rule**; the sourced Tunisian cases are FX rates (4 dp) and the *prorata* (explicitly 2 dp) | Intermediates at `scale+1` / `scale+4`; percent ceiling is a fixed `{1,2}` and explicitly **NOT** currency-scaled (`precision-contract.md:36`) | none — **intermediates already run at 4-7 dp; they are simply not persisted in `journal_lines`** | **ALREADY** |
| **7. 4-decimal currency support** | `decimal_places` derived from `rounding`; a `rounding=0.0001` currency yields 4 automatically. Column is unconstrained `numeric` | `currency_precision` selectable up to 9 | `MAIN_MAX_DECIMALS_*` take a per-currency `_<CCY>` suffix | SIX Group `list-one.xml`: TND = **3**; the ISO 4217 **maximum minor unit is 4** (CLF, UYW, ZWG) — all funds/index codes, none a transactable retail currency | `CurrencyScale::SCALE_MAP` has **no 4-dp entry**; `CountriesSeeder` has **no preset of 4**; `countries.currency_decimal_places` is a `tinyInteger` so it *can* hold 4 | Storage will support 4; the **preset table cannot produce 4** until someone seeds it | **DEFER — the trigger condition does not exist yet** |

**Benchmark verdict.** All three ERPs run the same architecture AutoERP is moving to: **storage capacity generously
wider than operational precision, precision applied by the writer, currency scale applied at display.** On row 5 they
also agree on something AutoERP does *not* do: **none of them narrows a value on read below what the column holds.**
That is the only row where AutoERP diverges, and it is exactly the row the owner is asking about.

**Two owner-premise checks, stated plainly:** (a) *"even in Tunisia they sometimes use four decimals"* — sourced for
**FX rates** (BCT publishes 4 dp) and for **intermediate arithmetic**, both of which AutoERP already carries at 6 and
`scale+4`. It is **not sourced** for booked journal amounts or invoice unit prices; the Tunisian *prorata* rule is
explicitly 2 dp. (b) The strongest Tunisian support for the widening is **NC 01 §62 itself** — "no rounding in the
recording of operations" — not a claimed 4-decimal invoice rule. Anyone writing that justification into the
precision contract should cite §62, not an invoice-decimals rule that does not appear to exist.

---

## 3. Options

Measured blast radius (greps over `apps/api` at HEAD, 2026-09-07). **Note the lane brief's figure of
"153 test assertions" (`docs/superpowers/plans/2026-09-07-precision-widening-gl-repository-scale-4.md` §1 A)
is understated and mis-split** — the real numbers:

| Pattern | Count | Breaks under `decimal:4`? |
|---|---|---|
| `assertSame('N.NNN', …->debit\|credit\|balance\|last_reconciled_balance)` | **192** across **34 files** (15 in `tests/Feature/Treasury`, 7 in `tests/Feature/Accounting`, rest scattered) | **YES** — strict string compare, `'19.000' !== '19.0000'` |
| `assertEquals('N.NNN', …)` on the same accessors | 106 | No — loose numeric-string comparison |
| `assertDatabaseHas([... 'debit' => 'N.NNN'])` | 136 | No — PG compares `numeric`, `'100.000' = 100.0000` |

### Option A — casts stay `decimal:3` (the lane brief's assumption A; A10 recommendation)

- **Consequences:** none today. No test churn, no payload change, no doc churn beyond recording the gap.
- **Risk:** the row-5 divergence becomes *load-bearing*. The moment a 4th decimal reaches either column —
  a raw-SQL write, a PG trigger-computed balance, an import, or a future 4-dp preset — the model **silently
  reports a rounded value while the database holds another**. Reconciliation (`payment_repositories.balance`
  vs `repository_movements.balance_after`) would then compare a cast-rounded read against a raw value and
  either false-pass or false-fail. This is the rule-20 failure shape: a contract invisible from the other layer.
- **Mitigation already planned:** the census check `fourth_decimal_present`
  (`…2026-09-07-precision-widening…md` §T1 check 2) is exactly this detector, and the round-trip test reads
  with `DB::table(...)` rather than through the model. Option A is only safe **with that detector shipped
  and wired**, otherwise the divergence is undetectable.
- **Benchmark support:** partial. All three benchmarks keep app-layer precision unchanged when capacity
  widens — none of them re-narrows on read either, but none of them *has* a read-side re-scaler to leave
  mismatched, so they are not a positive endorsement of A so much as silent on it.

### Option B — casts → `decimal:4` now

- **Consequences (complete list):**
  1. **192 strict `assertSame` assertions** across 34 files must be re-baselined `'N.NNN'` → `'N.NNNN'`. Mechanical, but 34 files of churn on the eve of the parapharmacy launch.
  2. **API payload shape changes** for any DTO that copies the cast value verbatim — `JournalLineData.php:37-38,55-56` does exactly that (`debit: $line->debit`), so journal-entry responses become `'19.0000'`. **Report surfaces are unaffected** (they go through `bcround` at the country scale — `TrialBalanceService.php:111-113`, `FormatsReportNumbers::decimalString`).
  3. **TypeScript is unaffected** — `packages/shared/types/generated.d.ts:44,159,270` already types `debit: string`; only the value's shape changes. `LedgerTable.tsx:68` renders through `formatAmount`, `formatCurrency` rounds to the currency scale.
  4. **Fiscal-hash canonicalization is NOT affected** — no Fiscal-module code reads `journal_lines`; the SALE_RECEIPT/Z payloads are device-authored and never carry these columns.
  5. **POS device parity is NOT affected** — `apps/pos` never references either table.
  6. **Heterogeneous payload shapes.** Moving only 4 of the 19 `decimal:3` casts in Treasury/Accounting puts `'1000.0000'` next to `'600.000'` in one `array<string,string>` payload — the *exact* defect `PaymentRefundService.php:1461-1468` documents having fixed ("same source, same cast, same shape by construction"). Doing B properly means all 19 casts, which multiplies items 1-2.
  7. **One production site hardcodes 3 to match the cast:** `CloseInvoiceWithToleranceService.php:79-82` passes `outstandingBalance(3)` with a comment naming `balance_due`'s `decimal:3` cast; that value feeds the tolerance checker, the GL write-off amount and the API response. It must be reviewed, and `balance_due` is not in this lane's four columns.
- **Risk:** a broad, low-value change during a launch freeze that buys nothing operationally (no writer can emit a 4th decimal), in exchange for shape churn on money payloads.
- **Benchmark support:** none of the three benchmarks widened a *read* precision when they widened capacity — Odoo's read precision is the currency's, ERPNext's is `currency_precision`, Dolibarr's is `MAIN_MAX_DECIMALS_SHOWN`. **The benchmark does not call for B.** The in-repo `pos_shifts` precedent shows B is *safe*, not that it is *indicated*.

### Option C — remove the casts; numeric-string passthrough + country-scale formatting at emission

- **Consequences:** matches all three benchmarks most closely (row 5) and matches the repo's own strictest
  precedent — `country_payment_settings.cash_rounding_denomination` is deliberately a `'string'` cast to
  stop re-serialization (`precision-contract.md:124-128`). But PG returns `numeric` as `'19.0000'`, so the
  payload shape changes anyway (all of B's item 2), **plus** every consumer that today relies on the cast
  for a canonical shape must format explicitly, **plus** the `ForbidFloatCastOnDecimalProperty` PHPStan rule
  keys on `decimal:N` props and would stop protecting these columns.
- **Risk:** the largest blast radius of the three, and it trades a known-narrow read for an unformatted one.
  It is the architecturally cleanest end-state and the worst thing to attempt before a launch.

---

## 4. Recommended default (for the owner to accept, amend, or reject)

**Recommended: Option A — casts stay `decimal:3` — conditional on the 4th-decimal detector shipping in the
same lane.** Grounds:

1. The benchmark does **not** ask for B. All three ERPs already run capacity-wider-than-precision and none
   raises read precision to match capacity. The widening to `(15,4)` is the benchmark-conforming half; the
   cast is not.
2. The owner's own premise is satisfied *without* B: the four-decimal cases that are actually sourced —
   FX rates, percentage and division intermediates — are already carried at 6 and `scale+4` in AutoERP, and
   NC 01 §62's "no rounding while recording" is honoured by the storage widening itself.
3. B's cost is real and immediate (192 strict assertions, 34 files, money payload shape churn, one production
   site hardcoding 3, and a homogeneity problem unless all 19 casts move) while its benefit is zero until a
   4-dp preset exists — and **no such preset exists, in any table** (`CountriesSeeder`: 0/40 at 4;
   `CurrencyScale::SCALE_MAP`: no 4-dp entry; ISO 4217's only 4-dp codes are funds/index codes).
4. A's one real risk — silent read-vs-storage divergence — is convertible into a *detected* one by the census
   check `fourth_decimal_present` that the lane already specifies. **Without that detector, A is not
   recommendable and B becomes the safer choice.**

**Trigger to revisit (open B as a mechanical lane, all 19 casts at once, not just the four):** the first of —
a country preset seeded with `currency_decimal_places = 4`; a 4-dp entry added to `CurrencyScale::SCALE_MAP`;
or the census reporting a non-zero `fourth_decimal_present`.

**Also worth an explicit ruling while this is open:** the precision-contract update in P0-b should justify the
scale-4 floor with **NC 01 §62** and the ISO-4217 4-dp ceiling, and should *not* assert a Tunisian
4-decimal invoice/unit-price rule — research found no source for one in either direction.

**The owner rules.** A10 in `docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md` is the row to fill.

---

## Sources

Odoo: [`odoo/fields.py` (Monetary)](https://raw.githubusercontent.com/odoo/odoo/18.0/odoo/fields.py) ·
[`account_move_line.py`](https://raw.githubusercontent.com/odoo/odoo/18.0/addons/account/models/account_move_line.py) ·
[`res_currency.py`](https://raw.githubusercontent.com/odoo/odoo/18.0/odoo/addons/base/models/res_currency.py) ·
[`decimal_precision.py`](https://raw.githubusercontent.com/odoo/odoo/17.0/odoo/addons/base/models/decimal_precision.py) ·
[`product_data.xml`](https://raw.githubusercontent.com/odoo/odoo/18.0/addons/product/data/product_data.xml) ·
[`res_currency_data.xml`](https://raw.githubusercontent.com/odoo/odoo/17.0/odoo/addons/base/data/res_currency_data.xml)

ERPNext/Frappe: [`gl_entry.json`](https://raw.githubusercontent.com/frappe/erpnext/version-15/erpnext/accounts/doctype/gl_entry/gl_entry.json) ·
[`database/mariadb/database.py` (type map)](https://raw.githubusercontent.com/frappe/frappe/version-15/frappe/database/mariadb/database.py) ·
[`model/meta.py` (`get_field_precision`)](https://raw.githubusercontent.com/frappe/frappe/version-15/frappe/model/meta.py) ·
[`system_settings.json`](https://raw.githubusercontent.com/frappe/frappe/version-15/frappe/core/doctype/system_settings/system_settings.json) ·
[`general_ledger.py`](https://raw.githubusercontent.com/frappe/erpnext/version-15/erpnext/accounts/general_ledger.py) ·
[Set Precision (docs)](https://docs.frappe.io/erpnext/set-precision) ·
[Round Off account validation](https://docs.erpnext.com/docs/user/manual/en/round-off-account-validation)

Dolibarr: [`conf.class.php` (MAIN_MAX_DECIMALS_* defaults)](https://raw.githubusercontent.com/Dolibarr/dolibarr/develop/htdocs/core/class/conf.class.php) ·
[`llx_accounting_bookkeeping-accounting.sql`](https://raw.githubusercontent.com/Dolibarr/dolibarr/develop/htdocs/install/mysql/tables/llx_accounting_bookkeeping-accounting.sql) ·
[`llx_facturedet.sql`](https://raw.githubusercontent.com/Dolibarr/dolibarr/develop/htdocs/install/mysql/tables/llx_facturedet.sql) ·
[Setup Limits and accuracy (wiki)](https://wiki.dolibarr.org/index.php?title=Setup_Limits_and_accuracy) ·
[VAT calculation and rounding rules (wiki)](https://wiki.dolibarr.org/index.php/VAT_calculation_and_rounding_rules)

Tunisia / standards: [NC 01 §62 (procomptable, verbatim FR)](https://www.procomptable.com/normes/nc1_partie2.htm) ·
[OECT copy of NC 01 (PDF, text extraction failed — human sign-off advised)](https://oect.org.tn/wp-content/uploads/2023/01/NC_01.pdf) ·
[Code de la TVA art. 18-II (no decimal rule)](https://www.jurisitetunisie.com/tunisie/codes/tva/tva1060.htm) ·
[BCT daily FX rates at 4 decimals](https://www.bct.gov.tn/bct/siteprod/cours.jsp) ·
[Prorata de déduction = 2 decimals](https://www.profiscal.com/etudiants/TCA/tca_ch5_06.htm) ·
[SIX Group ISO 4217 `list-one.xml` (TND=3; max minor unit 4)](https://www.six-group.com/dam/download/financial-information/data-center/iso-currrency/lists/list-one.xml) ·
[EC Reg. 1103/97 arts. 4-5 (carry the rate, round money once)](https://eur-lex.europa.eu/eli/reg/1997/1103/oj/eng) ·
[IAS 1 §51 (level of rounding is a presentation attribute)](https://www.ifrs.org/content/dam/ifrs/publications/html-standards/english/2024/issued/ias1.html)

**Explicitly unsourced — do not let these into a spec as fact:** any Tunisian legal rule fixing invoice
decimals (unit price or total); any prescribed "arrondi au millime le plus proche" for VAT amounts;
4-decimal unit prices being standard in Tunisian pharma/parapharmacy/petroleum/utilities; an Odoo
`decimal.precision` usage literally named "Account" (standard Odoo 17 seeds only "Payment Terms" = 6);
Frappe's **PostgreSQL** type map (only MariaDB `decimal(21,9)` was verified); Dolibarr's `price2num()` body.
