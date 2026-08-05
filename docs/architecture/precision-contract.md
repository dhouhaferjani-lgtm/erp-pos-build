# Monetary & Quantity Precision Contract

> Canonical reference for how money and quantity values are stored, validated, computed, and displayed across AutoERP. Established by the **Precision & Scale-Drift Remediation** (2026-05-28 → 2026-05-30, PRs #153–#160). Read this before adding any monetary or quantity column, FormRequest, service-layer arithmetic, or numeric input.

## TL;DR

| Concern | Storage scale | At-rest helper | Display |
|---|---|---|---|
| Currency (most columns) | `decimal(N, 3)` floor | `CurrencyScale::bcformatStrict($value, $scaleResolver->getScale($currency))` | `getDecimals(currency)` — 0 JPY/KRW, **2 EUR/USD/GBP**, **3 TND/LYD/JOD/KWD/OMR/BHD** |
| Quantity (canonical) | `decimal(N, 4)` | `QuantityScale::round($value, $decimalPlaces, $method)` (bcmath) | per-unit via `units.decimal_places` |
| Outliers | `pos_shifts.*` (16,4); `eco_tax`/voucher (N,5); `exchange_rate` (15,6) | as above at the column scale | — |

**Golden rule:** never let a JS/PHP float touch a money or quantity value. Use bcmath (PHP) / Big.js (`apps/pos`, `apps/web/src/lib/decimal.ts`) everywhere; round **once** at the write boundary.

## Storage tier

- Currency columns are `decimal(N,3)` (the canonical floor — accommodates TND's 3 millièmes). EUR/USD values store fine at scale 3 (`'20.000'` == `20.00`); the 3rd decimal is `0`. Display renders at `getDecimals(currency)` — **rounding**, not truncating; see § Emission & display for the ruling and its rationale.
- Quantity columns are `decimal(N,4)` (parapharmacy fractional sales: `0.5000 kg`, `0.0010`).
- Eloquent `decimal:N` casts are declared on every money/quantity model property so reads return canonical strings.
- DECIMAL **widening** is non-destructive on PostgreSQL. **Narrowing** needs a per-column PG pre-check that aborts on data that would truncate (see `widen_*` / the pos_orders scale-3 narrowing migration).

## Service tier (PHP)

- Inject `CurrencyScaleResolverInterface` (currency scale) — **constructor injection, never `app()`**. In-request callers use `getScale($currency?)` (throws `UnboundCompanyContextException` if no currency arg AND no `CompanyContext` bound — fail-loud). **Services reachable from queued listeners / console / domain transitions MUST pass the entity currency** (`getScale($entity->currency)`) or use `getScaleSafe($currency, 3)` — a bare no-arg `getScale()` in those contexts throws. (This bit Phase 3: `TaxCalculationService`/`PointEarningService` invoked from the WorkOrder→Invoice path & queued loyalty listener.)
- Intermediates compute at `scale + 1` (cart/tax line math) or `scale + 4` (WAC, landed cost), then round **once** at the boundary via `CurrencyScale::bcformat(..., scale)`.
- `CurrencyScale::bcformat` **truncates** toward zero (`bcadd($v,'0',scale)`). This is the canonical write-boundary behavior. WAC/landed-cost therefore truncate (≤1-millième downward bias on COGS) — *flagged for owner/accountant sign-off; switch to half-up is a localized change if desired.*
- Receipt/return VAT is computed by the shared `App\Modules\POS\Application\Concerns\RoundsVat` trait so creation and return paths cannot drift.
- Forbidden: `number_format((float)$v, …)`, `(float)$model->decimalProp`, native `* / + -` on money/quantity. There is exactly ONE narrow exemption — `FormatsReportNumbers::numericString`, for a float the code did not create and cannot prevent; see § Emission & display. It licenses nothing upstream: **producing** a float by PHP arithmetic on money and then `number_format`-ing it stays forbidden.

## Ingress tier (FormRequests / controllers)

Every validator field whose destination is `decimal(N,S)` keeps `numeric` and ADDS a regex decimal-place ceiling (the established `OpenShiftRequest` precedent — **non-breaking**: `numeric` accepts both JSON numbers and numeric-strings, so number-sending and string-sending clients both work):

- Money: `regex:/^-?\d+(\.\d{1,3})?$/` (use `^\d` without `-?` for non-negative fields; `-?` only where negatives are legitimate — journal debit/credit, price_adjustment, stock deltas).
- Quantity: `regex:/^-?\d+(\.\d{1,4})?$/`.
- Tax rate / percentage: `regex:/^\d+(\.\d{1,2})?$/`. **Percentages are NOT currency-scaled** — they keep a fixed 2-dp ceiling, independent of currency.
- Withholding *rate* fraction (`decimal(5,4)`): `regex:/^\d+(\.\d{1,4})?$/` (it's a 0–1 fraction, not a percentage).
- Voucher / eco_tax (scale 5): `regex:/^\d+(\.\d{1,5})?$/`.

Add a `<field>.regex` message ("… must have at most N decimal places"). **Normalize-on-write fields** (e.g. Scheduling `estimated_price`) intentionally omit the ceiling and canonicalize any precision via `bcformatStrict` in the service.

## JSONB tier

JSONB columns bypass Eloquent casts, so producers must pre-canonicalize numeric values to numeric-strings (at the storage scale — opening-balance staging uses fixed scale 3 to match the `decimal(N,3)` journal target) **before** `json_encode` (with `JSON_PRESERVE_ZERO_FRACTION`). Device-supplied JSONB (POS held-order snapshot, Z-report receipt_snapshots) is validated per-key with the same regex ceilings.

## Value objects

- Billing `Money`: readonly numeric-string; bcmath arithmetic; `equals()` via `bccomp` (no epsilon); `toCents()/fromCents()` scale-aware per currency (EUR×100, TND×1000, JPY×1) for Stripe minor units. `multiply()` truncates at the boundary (document this if a future proration caller needs half-up).
- Loyalty `PointsAmount` / `LoyaltyBalance`: numeric-string (scale 3, matching `decimal(15,3)` loyalty columns); bccomp comparisons; integrity check exact (no tolerance).

## Frontend

- **Never `parseFloat`/`Number(...)` on a monetary or quantity value.** Use `<MoneyInput value={string} onChange currency={...}>` / `<QuantityInput value={string} onChange decimalPlaces={...}>` (`apps/web/src/components/atoms/`) for input — they emit canonical strings and derive `step` from the currency scale / decimalPlaces. Display via `formatCurrency(amount: string, currency)` / `formatQuantity(value: string, decimalPlaces)`.
- API payloads send money/quantity as **strings**.
- `apps/pos` & `apps/web` money arithmetic uses Big.js helpers in `lib/decimal.ts` (`bcadd/bcmul/bcdiv/bcsub/bcformat`). **Device-authority fiscal note:** the POS device computes fiscal hashes; its canonicalization must match the server byte-for-byte. `Big.RM` is currently half-up while the server truncates — a latent edge-case divergence flagged for a future coordinated server+device rounding alignment.

## Emission & display

**Presentation-boundary money display ROUNDS half-away-from-zero — `CurrencyScale::bcround($value, $scale)` — and this deliberately differs from the write boundary, which truncates.** For *display*, it supersedes the "Display truncates to `getDecimals(currency)`" line in § Storage tier. Rationale (ruled 2026-08-05, `docs/superpowers/reviews/2026-08-05-l4-api-precision-gate.md` Q4): the POS device rounds this way — `apps/pos/src/lib/currency.ts` formats through `Intl.NumberFormat` (`halfExpand`) and `apps/pos/src/lib/decimal.ts` runs `Big.RM = 1` — so a truncating server would MANUFACTURE a server-vs-device millime disagreement on the Z/EOD cash surface. `bcround`'s own docblock (`app/Shared/Domain/CurrencyScale.php`) already designates it the presentation / GL-posting boundary helper, per NC 01 §62 (carry higher precision at rest, round only at the boundary). Live example: `pos_shifts.expected_cash` is `decimal(16,4)`, so a TND report must render `300.0005` as `300.001`, not `300.000` (`FormatsReportNumbers::decimalString`, `TrialBalanceService::emit`; pinned by `ReportNumberEmissionTest` + `TrialBalanceCurrencyScaleTest`). **Write/canonicalization boundaries are unchanged — they still truncate via `bcformat`**, including the fiscal-hash canonicalization, whose server-vs-device divergence remains the open item flagged in § Frontend.

**Emission-normalisation exemption — ONE sanctioned site, and it is not a licence.** `FormatsReportNumbers::numericString` (`app/Modules/Accounting/Application/Services/Reports/`) normalises a value to a numeric string with `number_format($value, MONEY_NORMALISATION_SCALE, '.', '')` (scale 4) *before* the `bcround` above. **The exemption applies ONLY to a float the code did not create and cannot prevent** — in practice a value handed back as a float by the DRIVER on a non-PostgreSQL path (SQLite's `SUM()`; PostgreSQL returns `numeric` as a *string*, so this branch is dead in production and the string path is the one that ships). The float is the *input*; there is no cast, and there is nothing upstream to fix.

**It does NOT license creating one.** Money computed with native PHP arithmetic — `$a * $b`, `array_sum()`, `Collection::sum()`, a `(float)` cast — and then passed through `number_format` is still a rule-19 violation, and no amount of downstream normalisation repairs the precision already lost. Fix the arithmetic (bcmath), not the formatting. Any second site claiming this exemption is a bug until it is added here by name.

Why `number_format` rather than the usual `bcformat`: `bcformat` truncates, and it ate the half BEFORE the currency rounding could see it — `777.775` is held as `777.77499999999998`, truncates to `777.7749`, and renders `777.77` instead of `777.78`. Domain of validity: exact for every magnitude below `1e13`, above which a double can no longer represent 4 decimals — three orders beyond `decimal(16,4)`'s own headroom and unreachable for money. A precision sweep that greps `number_format` will find this one call; it is correct, and reverting it reintroduces the truncation bug (pinned by `ReportNumberEmissionTest`).

Quantity has TWO scales: the canonical **storage** scale (`decimal(N,4)`, unit-agnostic) and the **display** scale, which is per-unit — `units.decimal_places` (pieces → `0`, so a whole number; weight → `3`; etc.), rounded with `units.rounding_method`. A quantity surfaced to a human (a rendered cell, an input's prefill/step) must render at the product unit's precision, NOT at raw scale 4 (`'7.0000'` for 7 pieces is wrong). Storage, wire, and internal arithmetic stay scale-4; formatting to the unit happens once, at the emission boundary.

**Backend — `QuantityScale::formatForUnit(string $value, ?int $decimalPlaces, ?string $roundingMethod = null): string`** (`app/Shared/Domain/QuantityScale.php`). Primitives only — Shared must not depend on a module's Uom entity, so callers pass `$unit?->decimal_places` and `$unit?->rounding_method?->value` (the enum's backing string, not the enum). Null args fall back to canonical scale-4 half-up (unchanged legacy behavior). Format in the **Application** layer and pass the string through; never bake a scale-4 literal into a Presentation Resource.

**Canonical display/input helpers:**

| App | Display (pad to unit precision) | Input | Deprecated / do-not-use for quantities |
|---|---|---|---|
| `apps/web` | `formatQuantity` from `lib/decimal.ts` (pads) + `getQuantityDecimals(product)` from `lib/quantityScale.ts` (clamps `units.decimal_places` to `[0,4]`) | `<QuantityInput>` atom (`components/atoms/`) | `formatQuantity` in `lib/format.ts` — **@deprecated for product quantities**: it TRIMS trailing zeros and locale-groups, wrong for unit-precision display |
| `apps/pos` | `formatQuantity` from `lib/quantity.ts` (pads via Big.js; `clampQuantityDecimals` for the clamp) | `<QuantityInput>` atom (`components/atoms/`) | — |

**POS data contract.** The server `/products` payload emits `quantity_decimals` (int, per product, from `units.decimal_places`). The POS maps it into SQLite via migration **v62** (`products.quantity_decimals INTEGER`, nullable — an older server that omits the field round-trips as `null`, and the atom/formatter falls back to scale 4). The replenishment feed rows also carry `quantity_decimals` plus a unit-formatted `suggested_qty`; the wire `requested_qty` stays canonical scale-4 (unchanged).

**Exemptions (intentional scale-4, not violations).** Pre-product standing fields — where no product/unit is yet chosen — stay scale-4: e.g. `ReplenishmentCapturePage` free-quantity capture and quote-request / RFQ lines (product not yet resolved). These carry an inline code comment pointing at the spec. `ProductInventorySection`'s `opening_qty` literal is a ticketed follow-up (needs unit-selection-aware plumbing), excluded from the ESLint scope. Guard baselines below are **shrink-only** — an exempted site may sit in a baseline, but the ratchet never lets the count grow.

**Guards (all four ratchet against new drift):**
- **PHPStan** (`app/PHPStan/Rules/`, registered in `phpstan.neon`): `ForbidFixedScaleQuantityLiteralRule` (no `'…0000'` scale-4 string literal in a module's `…\Presentation\…` namespace) + `ForbidQuantityScaleConstantInPresentationRule` (no `QuantityScale::round($v, QuantityScale::SCALE, …)` in Presentation). Fix = `formatForUnit()` in the Application layer.
- **ESLint** — web (`apps/web/eslint-rules/no-literal-decimal-places.js`, WARN): no hardcoded numeric `decimalPlaces={4}` literal on `<QuantityInput>` in the product-quantity feature dirs — derive via `getQuantityDecimals(...)`. POS (`apps/pos/eslint-rules/no-raw-quantity-input.js`, WARN→ERROR in the strict override): no raw `<input inputMode="decimal">` — route through `<QuantityInput>` (allowlist for money / non-quantity decimals). POS also copies `no-hardcoded-step`.
- **Scanner** — `apps/web/tools/audit-quantity-display.mjs` (`pnpm audit:quantity`, wired into lint/preflight/CI) scans BOTH `apps/web/src` and `apps/pos/src`: flags any JSX rendering a raw quantity identifier (`requested_qty`/`suggested_qty`/`received_qty`, member `quantity`) unless wrapped in the canonical `formatQuantity` or inside a `<QuantityInput value>`. Baseline `quantity-display-baseline.json` (line-number-free `"file:identifier"` entries) is **shrink-only**: a NEW entry fails CI, and a STALE entry (baselined site now fixed/removed) also fails — forcing baseline removal.

## Signed fiscal fields — the string-fidelity exception (SALE_RECEIPT v3 cash rounding)

Everything above governs values at rest and in transit. Two fields are stricter
still, because they live inside **cryptographically signed bytes** and any
re-serialization is a permanent, unrepairable defect.

`SALE_RECEIPT` payload v3 (`fiscal_events.event_version >= 3`, the single
cutover discriminator `App\Shared\Domain\CashRoundingCutover::EVENT_VERSION`)
carries:

| Field | Type | Rule |
|---|---|---|
| `cash_rounding_adjustment` | **SIGNED** decimal string at `currency_scale` | The one sanctioned negative-allowed money field. `-0` is REJECTED — canonical zero is the unsigned zero at scale (`'0.000'`) |
| `cash_rounding_denomination` | NON-NEGATIVE decimal string at `currency_scale` | Canonical zero when rounding did not apply |

`cash_rounding_adjustment` is the **exception this feature creates** to the
otherwise unqualified "money is non-negative at rest" habit: it is
`rounded_total − exact_total`, so it is negative exactly when the cash total
rounds DOWN. Every guard, CHECK and validator on its path must tolerate the
minus sign; only `-0` is illegal.

**The signed denomination must survive every hop UNMUTATED.** `'0.050'`
becoming `0.05` anywhere on the path is 100% quarantine for every receipt
authored after that point. Concretely:

- `country_payment_settings.cash_rounding_denomination` is `decimal(15,4)` with
  a `'string'` Eloquent cast (`app/Modules/Treasury/Domain/CountryPaymentSettings.php`)
  — NEVER a float cast, which re-serializes `0.0500` as `0.05`. On SQLite the
  raw query builder returns that column as a PHP float, so reads must go
  through the model, never `DB::table(...)`.
- `PosPaymentPolicyResolver` re-scales it to the company currency scale with
  `CurrencyScale::bcformatStrict()` and validates round-trip equality; a
  non-representable value is reported as `cashRoundingEnabled: false` rather
  than emitted broken.
- `PosPaymentPolicyDTO::$cashRoundingDenomination` is typed `string`; the
  generated TypeScript type is `string`; the device SQLite cache column is
  `TEXT` (NUMERIC/REAL affinity strips trailing zeros).

**Comparisons against policy use `bccomp`, never string equality.** PostgreSQL
renders `decimal(15,4)` as `0.0500` while the signed value is `0.050` — a string
compare would false-alarm on every rounded receipt.

**Sanctioned denominations are history-stable.**
`App\Shared\Domain\CashRoundingCaps` maps currency scale → maximum legal
denomination (`0 => '10'`, `2 => '1.00'`, `3 => '1.000'`; an unlisted scale
disables rounding, fail-closed). Every writer and verifier — the resolver, the
`pos:configure-cash-rounding` ops command, the payload validator — imports it
and never re-types the literals. Editing a value there re-interprets receipts
that were already signed under the old table, so treat it as a versioning
constant, not a tunable. The same applies to
`CashRoundingCutover::EVENT_VERSION`.

**`total` semantics under rounding.** On a v3 receipt the signed `total` is the
ROUNDED amount actually collected. The NF525 aggregate identity becomes:

```
subtotal + vat_total == (total − cash_rounding_adjustment) + discount
```

Absent fields mean zero, so v1/v2 reduce to the previous identity exactly.
Revenue and loyalty consume the EXACT value `total − adjustment`; the drawer,
the payable and the Z gross consume the rounded `total`; VAT is untouched
(rounding is VAT-neutral and is never allocated across VAT buckets, so
`total ≠ Σ vat gross` by exactly one adjustment is now legal).

**Two-semantics payments rule (read before summing any cash column).** From the
v3 cutover on, the two payment tables mean different things:

| Column | Semantics |
|---|---|
| `pos_receipt_payments.amount` | **TENDERED** — what the customer handed over (canonical, from the signed payload) |
| `payments.amount` (Treasury) | **RETAINED** — tendered minus the change given back; a leg fully netted to zero writes no row at all |

`TreasuryReceiptBridge` performs the netting (`app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php`
— see `computeNettedAmounts`). Any report that sums tendered cash as if it were
banked cash overstates from the cutover forward; subtract
`pos_receipts.change_due` or read the Treasury `payments` rows.

**`pos_receipts_totals` (pgsql-only CHECK)** is:

```sql
total = subtotal + tax_amount - discount_amount + COALESCE(cash_rounding_adjustment, 0)
```

The `COALESCE` is load-bearing: without it the expression is NULL on every
legacy row and PostgreSQL treats a NULL CHECK as satisfied, silently disabling
the identity for all pre-v3 data.

## Regression guards (CI)

- **PHPStan** (`app/PHPStan/Rules/`): `ForbidFloatCastOnDecimalProperty` (no `(float)` on a `decimal:N` prop), `ForbidHardcodedBcmathScale` (no literal scale arg in service-layer bcmath; use `$this->scale()`/`+N` or a `// precision-ok` exemption). Legacy hits are in `phpstan-baseline.neon`; NEW violations fail CI.
- **ESLint** (`apps/web/eslint-rules/`): `no-hardcoded-step` (no `step="0.0…"` literal — use the input atoms), `no-parsefloat-on-money` (no `parseFloat`/`Number` on money/quantity-named values). WARN-level; tracked by the lint-warning ratchet — new drift raises the count and fails CI.
- **Quantity display precision** (`## Emission & display` above): two PHPStan rules + two ESLint rules + `audit-quantity-display.mjs`, all shrink-only ratchets.

## Deliberate fiscal-fixture change

- NF525 golden fixture `tests/Fixtures/Nf525/jet_export_v1.xml`: `Quantite` regenerated 3→4 decimals (quantity canonical scale). Logged in the REALIGNMENT-LOG.

## Known follow-ups (deferred)

- **5.6 `pos_receipt_lines` line_total CHECK constraint** — NOT re-introduced (gate-stopped): infeasible as a plain PG CHECK because bcmath truncates (not rounds), the scale is per-currency (not on the row, and PG CHECK can't reference the parent receipt), and the projection supplies `line_total` verbatim from the canonical DTO. Requires persisting a per-row scale + a CHECK-reproducible rounding rule (touches the canonical fiscal payload). Escalated to owner.
- WAC/COGS truncation vs half-up — owner sign-off.
- POS device Big.RM half-up vs server truncation — coordinated rounding alignment.
- DocumentLineEditor client-side line-total still float (backend recomputes authoritatively) — full string pipeline.

## `unit_price` is context-overloaded: tax-INCLUSIVE (B2C POS) vs net/HT (B2B) — READ BEFORE TOUCHING PRICE FIELDS

The field name `unit_price` carries **different tax semantics depending on the flow**, and the same name is reused across layers. This has caused real confusion (it surfaced a false-positive in the fiscal line-arithmetic work). Always confirm which one you're in:

| Context | `unit_price` means | Evidence / where |
|---|---|---|
| **B2C POS (offline-first Tauri + web POS)** | **tax-INCLUSIVE** (TTC) — the displayed shelf price includes VAT | `apps/pos` cart is unconditionally tax-inclusive: `cartStore.computeTaxAmount` extracts VAT from the gross (`net = lineTotal/(1+rate/100)`). The canonical SALE_RECEIPT `line_items[].unit_price` is written **verbatim from this inclusive cart price**; the **net** appears separately as `line_subtotal` (= line_total − line_vat), and VAT is in `line_vat` / `vat_breakdown`. |
| **B2B / Documents (quotes, orders, invoices)** | **net / HT** (hors taxes) — pre-tax | Document line editors + the canonical `subtotal` (net) / `vat_total` / `total` (gross) aggregates. Golden vector F-04 shows the canonical *aggregate* contract: `unit_price=20.00` (net), `line_vat=4.00`, `line_subtotal=20.00`. |

**Consequences for code:**
- **Never** assert `line_subtotal == unit_price × qty − discount` against a POS canonical line — `unit_price` there is inclusive (gross), so that compares net vs gross and FALSE-POSITIVES on every taxed line. Fiscal integrity is enforced at the **aggregate** level instead (`subtotal + vat_total == total + transaction_discount`; `Σ vat_breakdown.{net,vat} == subtotal/vat_total`).
- When reading a `unit_price`, determine the flow (POS vs document/B2B) before doing tax math; convert explicitly (`net = gross / (1+rate)` or `gross = net × (1+rate)`), never assume.

**Deferred disambiguation (owner-flagged):** the cleanest long-term fix is to rename to unambiguous **English** fields — e.g. `unit_price_incl_tax` / `unit_price_excl_tax` (English, congruent with existing naming — avoid French TTC/HT) — across the POS cart, canonical payload, and document layers. That is a broad, multi-app, fixture-regenerating change (canonical payload field rename = versioned fiscal event), so it is **deferred**; until then, this section + the CLAUDE.md pointer are the contract. Any new feature touching price fields MUST consult this.
