# Precision P0 Sweep — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eliminate every `float` touchpoint on money/quantity in the six live posting/fiscal-output paths flagged P0 by the 2026-06-24 hexagonal/SoC audit (re-verified still-live 2026-06-29), converting each to numeric-string + bcmath, with a regression test per fix.

**Architecture:** Each fix is localized. The repo already ships the precision toolkit (`CurrencyScale`, `CurrencyScaleResolverInterface`, the `Money` VO in Billing) and two PHPStan guards (`ForbidFloatCastOnDecimalProperty`, `ForbidHardcodedBcmathScale`). We route each flagged path through that toolkit and replace every float *comparison* (status/condition gate) with `bccomp`. After the path is clean, we lock it by extending the PHPStan guards' coverage where applicable.

**Tech Stack:** Laravel 12 / PHP 8.2 strict, PHPUnit (by-path), bcmath; React 19 / TS strict + Vitest for the one frontend fix.

## Global Constraints (precision contract — rule 19; applies to EVERY task)

- **Never let a float touch money or quantity.** No `(float)` cast on a decimal property; no `number_format((float)…)`; no native `*`/`+`/`-`/`<`/`>` on a money/qty value.
- **At rest:** currency → `CurrencyScale::bcformat($value, $scale)` (truncates once at the boundary). Quantity → quantity scale (4). Cost → cost scale (6). Intermediates carried at `scale+1` (currency) / `scale+4` (qty) before the single final `bcformat`.
- **Scale resolution:** constructor-inject `CurrencyScaleResolverInterface`; call `getScale($currency)`; in queued/console/context-less paths use `getScaleSafe($currency, 3)`. **Never** a bare no-arg `getScale()` there (throws). Never `app()`.
- **Comparisons / status gates:** use `bccomp($a, $b, $scale) <op> 0`, never `(float)$a > (float)$b`.
- **Helper API (verbatim):** `CurrencyScale::bcformat(string|int|float|null $value, int $scale): string` (truncates); `bcformatStrict(string $value, int $scale): string`; `bcround(string $value, int $scale): string` (HALF-UP, boundary only). Resolver: `getScale(?string $currencyCode = null): int`, `getScaleSafe(?string $currencyCode = null, int $fallback = 3): int`.
- **Run tests BY PATH** (never the full suite — it fatals on any one broken test and risks the laptop). PHPUnit: `cd apps/api && ./vendor/bin/phpunit --filter <name> <path>`. Worktree env: `vendor/` installed, `.env` copied; transform/cache needs `CACHE_STORE=array`.
- **Commit after each green task.** Co-author trailer: `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`.
- **Scope discipline:** touch only the cited path per task. Note adjacent drift; don't fix it here.

**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp.hex-p0` on `fix/precision-p0-sweep` (off the audit branch, merged to current `dev`). All paths below are under `apps/api/` unless prefixed `apps/web/`.

---

## Task sequence & status

1. **Task 1 — P0-2 Document line total** (self-contained, internal callers only) — cleanest, do first.
2. **Task 2 — P0-4 POS discount % + change-given** (param type `float→string` + `bccomp`).
3. **Task 3 — P0-5 Frontend POS float sum** (one-line `reduce` → `bcadd`).
4. **Task 4 — P0-1 WAC pipeline** (signatures `float→string`; only caller is `GoodsReceiptService`; `calculateNewWAC` has no callers).
5. **Task 5 — P0-6 Tax/fiscal output** (`CertificatePDFService`, `MtdJsonExporter`, `WithholdingCalculationService`).
6. **Task 6 — P0-3 Billing entities** — ⚠️ **SCOPE-GATED** on "is SaaS subscription billing in launch scope?" (audit's own caveat). Do **only if confirmed in-scope**; else file as P1 and stop after Task 7.
7. **Task 7 — Lock it:** extend PHPStan guard coverage over the cleaned paths + slice preflight on touched files.

---

## Task 1: P0-2 — Document line total computed via float

**Files:**
- Modify: `app/Modules/Document/Domain/Services/DraftPersistenceService.php` (three sites: line ~248 `addLine`, ~386 `modifyLine`, ~578/612 `addLinesBatch`)
- Test: `tests/Unit/Modules/Document/DraftPersistenceServiceTest.php` (extend)

**Interfaces:**
- Consumes: `CurrencyScale::bcformat`, `CurrencyScaleResolverInterface` (already injected? verify the constructor — if not, add it as `private readonly CurrencyScaleResolverInterface $scaleResolver`). Document currency is on the draft/document aggregate the service persists onto — use it for `getScale($currency)`.
- Produces: `line_total` persisted as a currency-scaled numeric-string equal to `bcmul(quantity, unit_price)` truncated once at currency scale.

- [ ] **Step 1: Write the failing test** — a line of qty `3` × unit_price that drifts under float (e.g. `0.145` currency-3 or a qty/price pair whose float product mis-rounds). Add to `DraftPersistenceServiceTest`:

```php
public function test_line_total_is_bcmath_not_float_product(): void
{
    // qty 1.1 * unit_price 1.1 = 1.21 exactly; float 1.1*1.1 = 1.2100000000000002
    $line = $this->service->addLine($draft, [
        'product_id'  => $product->id,
        'quantity'    => '1.1',
        'unit_price'  => '1.1',
    ]);

    $this->assertSame('1.210', $line->line_total); // currency scale 3, exact
}
```

- [ ] **Step 2: Run, verify it fails** — `cd apps/api && ./vendor/bin/phpunit --filter test_line_total_is_bcmath_not_float_product tests/Unit/Modules/Document/DraftPersistenceServiceTest.php`. Expected: FAIL (float product yields `1.2100000000000002` → cast/format drift, or the asserted exact string mismatches).

- [ ] **Step 3: Fix all three sites.** Replace each `(string)($quantity * $unitPrice)` (lines ~248, ~386, ~578) with a single bcmath product truncated at the document currency scale:

```php
$scale     = $this->scaleResolver->getScale($document->currency); // currency scale, e.g. 3
$lineTotal = CurrencyScale::bcformat(
    bcmul((string) $quantity, (string) $unitPrice, $scale + 1), // carry one extra
    $scale
);
```

Apply identically at all three sites (no `(float)` anywhere). Where the current code carries `$quantityFloat`/`$unitPriceFloat` locals (line ~383-386), delete those float locals and use the string inputs directly. Confirm the constructor injects `CurrencyScaleResolverInterface`; add it if missing (constructor injection only).

- [ ] **Step 4: Run, verify pass** — same filter. Expected: PASS. Also run the whole file: `./vendor/bin/phpunit tests/Unit/Modules/Document/DraftPersistenceServiceTest.php`.

- [ ] **Step 5: Commit** — `git add -A && git commit -m "fix(document): line_total via bcmath, not float product (P0-2)"`.

---

## Task 2: P0-4 — POS discount percent + change-given through float

**Files:**
- Modify: `app/Modules/POS/Domain/Services/DiscountCalculationService.php:144` (`calculateLineDiscountAmount(string $baseAmount, float $discountPercent)` → `string $discountPercent`)
- Modify: `app/Modules/POS/Application/Services/ReceiptCreationService.php` (~1004,1008,1014,1035 — drop the `(float) $discountPercent` casts; pass the numeric-string)
- Modify: `app/Modules/POS/Application/Services/ReceiptPdfService.php:133` (float `>` comparison → `bccomp`)
- Test: `tests/Unit/POS/DiscountCalculationServiceTest.php`, `tests/Feature/POS/ReceiptPdfChangeDueTest.php` (extend)

**Interfaces:**
- Consumes: existing `bcdiv`/`bcmul`/`bccomp`, `CurrencyScale`.
- Produces: `calculateLineDiscountAmount(string $baseAmount, string $discountPercent): string` — percent is NOT currency-scaled (it's a rate); validate with the percent regex upstream, keep as numeric-string here.

- [ ] **Step 1: Failing test** — percent that float-drifts. In `DiscountCalculationServiceTest`:

```php
public function test_line_discount_accepts_numeric_string_percent_no_float(): void
{
    // 10.05% of 100.00 = 10.050; float 10.05 path can drift the last digit
    $this->assertSame(
        '10.050',
        $this->service->calculateLineDiscountAmount('100.000', '10.05')
    );
}
```

And for change-given, in `ReceiptPdfChangeDueTest` assert change is `'0.000'` when `total_paid == total` exactly (the float `>` currently returns false correctly, but a paid-equals-total-by-cents case must not bcsub a spurious value).

- [ ] **Step 2: Run, verify fails** — `./vendor/bin/phpunit --filter test_line_discount_accepts_numeric_string_percent_no_float tests/Unit/POS/DiscountCalculationServiceTest.php`. Expected: FAIL (signature still `float`, or PHP coerces and the type contract is wrong).

- [ ] **Step 3: Fix.**
  - `DiscountCalculationService::calculateLineDiscountAmount` — change param to `string $discountPercent`; body already uses `bcdiv((string) $discountPercent, '100', 10)` → drop the redundant cast: `bcdiv($discountPercent, '100', 10)`.
  - `ReceiptCreationService` ~1004: change `(float) $discountPercent > 0` to `bccomp($discountPercent, '0', 10) > 0`; ~1008/1014/1035: pass `$discountPercent` / `$derivedPercent` as strings (remove `(float)`). Ensure `$derivedPercent` is produced as a numeric-string upstream.
  - `ReceiptPdfService:133`: replace `(float) $totalPaid > (float) $receipt->total` with `bccomp($totalPaidStr, $receiptTotal, $this->scale()) > 0` (both string locals already exist).

- [ ] **Step 4: Run, verify pass** — both filters, then `./vendor/bin/phpunit tests/Unit/POS/DiscountCalculationServiceTest.php tests/Feature/POS/ReceiptPdfChangeDueTest.php`. Also run `tests/Feature/POS/DiscountEnforcementTest.php` (regression).

- [ ] **Step 5: Commit** — `git commit -am "fix(pos): discount percent + change-given via bcmath/bccomp, not float (P0-4)"`.

---

## Task 3: P0-5 — Frontend POS float sum on payment amounts

**Files:**
- Modify: `apps/web/src/features/pos/organisms/AdvancedPaymentsModal/AdvancedPaymentsModal.tsx:217` (the `maxSafeChange` `reduce((sum,p)=>sum+p.amount,0)`)
- Test: colocated `*.test.tsx` (add if absent) — Vitest.

**Interfaces:**
- Consumes: `bcadd` (already imported at top of the file; line 197 uses it: `addedPayments.reduce((sum, l) => bcadd(sum, String(l.amount), decimals), '0')`).
- Produces: `maxSafeChange` as a decimal **string**, summed via `bcadd`, consistent with `totalPaidStr`.

- [ ] **Step 1: Failing test** — two payment amounts that float-drift (e.g. `0.1 + 0.2`). Assert the memoized `maxSafeChange` equals `'0.30…'` (string, exact) not `0.30000000000000004`.

- [ ] **Step 2: Run, verify fails** — `cd apps/web && pnpm vitest run src/features/pos/organisms/AdvancedPaymentsModal`. Expected: FAIL.

- [ ] **Step 3: Fix** — mirror line 197:

```typescript
const maxSafeChange = useMemo(() => {
  return addedPayments
    .filter(p => {
      const method = paymentMethods.find(m => m.id === p.methodId)
      if (!method) return false
      return (method.is_physical && !method.has_maturity) || method.requires_third_party
    })
    .reduce((sum, p) => bcadd(sum, String(p.amount), decimals), '0')
}, [addedPayments, paymentMethods, decimals])
```

Update any downstream numeric use of `maxSafeChange` to treat it as a string (compare via the existing bc-helpers, not `>`).

- [ ] **Step 4: Run, verify pass** — same Vitest path; then `pnpm typecheck`.

- [ ] **Step 5: Commit** — `git commit -am "fix(web/pos): maxSafeChange via bcadd, not float reduce (P0-5)"`.

---

## Task 4: P0-1 — WAC pipeline float-typed end-to-end

**Files:**
- Modify: `app/Modules/Inventory/Domain/Services/WeightedAverageCostService.php` (`recordPurchase` sig ~140; `calculateNewWAC` sig+body ~803-830)
- Modify: `app/Modules/Inventory/Application/Services/GoodsReceiptService.php:158,169` (drop `(float)` casts feeding `recordPurchase`)
- Test: `tests/Unit/Inventory/WeightedAverageCostServiceTest.php`, `tests/Feature/Inventory/GoodsReceiptTest.php` (extend)

**Interfaces:**
- Consumes: cost scale (6) via `costScale()`/resolver; qty scale (4); `CurrencyScale::bcformat`.
- Produces: `recordPurchase(Product $product, Location $location, string $quantity, string $landedUnitCost, …): StockMovement`; `calculateNewWAC(string $currentQty, string $currentCost, string $newQty, string $newCost): string`. **Blast radius:** `recordPurchase`'s only caller is `GoodsReceiptService:166`; `calculateNewWAC` has **no callers** (preview/utility) — signature change is safe.

- [ ] **Step 1: Failing test** — a receipt whose WAC rebases through a float-drifting product. In `WeightedAverageCostServiceTest`:

```php
public function test_new_wac_is_bcmath_exact_no_float_rebase(): void
{
    // on-hand 3 @ cost 0.1, receive 0 qty... use a pair where float product mis-rounds:
    // current 1 @ 0.1, receive 2 @ 0.2  => (0.1 + 0.4) / 3 = 0.166667 at cost scale 6
    $this->assertSame(
        '0.166667',
        $this->service->calculateNewWAC('1', '0.100000', '2', '0.200000')
    );
}
```

- [ ] **Step 2: Run, verify fails** — `./vendor/bin/phpunit --filter test_new_wac_is_bcmath_exact_no_float_rebase tests/Unit/Inventory/WeightedAverageCostServiceTest.php`. Expected: FAIL (float signature coerces; PHPStan also flags once the cast is removed).

- [ ] **Step 3: Fix.**
  - `calculateNewWAC`: change all four params + return to `string`. Body: `$totalCost = bcadd(bcmul($currentQty,$currentCost,$cs+1), bcmul($newQty,$newCost,$cs+1), $cs+1); $totalQty = bcadd($currentQty,$newQty, $qs); return bccomp($totalQty,'0',$qs) === 0 ? CurrencyScale::bcformat('0',$cs) : CurrencyScale::bcformat(bcdiv($totalCost,$totalQty,$cs+1), $cs);` where `$cs` = cost scale (6), `$qs` = qty scale (4). Resolve scales via the injected resolver (`getScaleSafe($currency, …)` — this runs in a queue/no-context path; pass the product/company currency, never bare).
  - `recordPurchase`: change `float $quantity, float $landedUnitCost` → `string`. Internally it already calls `CurrencyScale::bcformat($quantity, …)` — now feed it the string directly (no prior float round-trip).
  - `GoodsReceiptService:158,169`: delete `$landedUnitCost = (float) $unitCostStr;` → `$landedUnitCost = $unitCostStr;` and `quantity: (float) $qtyToReceive` → `quantity: $qtyToReceive` (already strings).

- [ ] **Step 4: Run, verify pass** — the unit filter, then `./vendor/bin/phpunit tests/Unit/Inventory/WeightedAverageCostServiceTest.php tests/Feature/Inventory/GoodsReceiptTest.php tests/Feature/Inventory/GoodsReceiptServiceVariantTest.php`. Then PHPStan on the two files: `./vendor/bin/phpstan analyse app/Modules/Inventory/Domain/Services/WeightedAverageCostService.php app/Modules/Inventory/Application/Services/GoodsReceiptService.php`.

- [ ] **Step 5: Commit** — `git commit -am "fix(inventory): WAC pipeline numeric-string end-to-end, no float rebase (P0-1)"`.

---

## Task 5: P0-6 — Tax/fiscal output floated

**Files:**
- Modify: `app/Modules/Taxation/Application/Services/CertificatePDFService.php:85-88` (`number_format((float)…)` → bcmath formatting)
- Modify: `app/Modules/Taxation/Infrastructure/Exporters/MtdJsonExporter.php` (`toDecimal2` returns float ~89-92; `abs((float)$netVat)` ~68)
- Test: `tests/Feature/Taxation/WithholdingCertificateTest.php`, `tests/Feature/Taxation/WithholdingPrecisionTest.php` (extend)

**Interfaces:**
- Consumes: `CurrencyScale::bcformat`, `bcadd`/`bcsub`/`bcabs`-via-`ltrim`.
- Produces: certificate PDF figures formatted from numeric-strings; MTD box values computed entirely in bcmath, cast to float **once** only at the final HMRC JSON payload assembly (HMRC MTD VAT requires JSON numbers — that single edge cast on an already-2dp-rounded string is the only permitted float, with a comment).

- [ ] **Step 1: Failing test** — `WithholdingCertificateTest`: assert the rendered certificate amount string for a gross that float-`number_format` would drift equals the bcmath-formatted value (exact). For MTD: a `WithholdingPrecisionTest` case asserting box5 = `abs(box3 - box4)` computed in bcmath equals the expected 2dp string before serialization.

- [ ] **Step 2: Run, verify fails** — `./vendor/bin/phpunit --filter <name> tests/Feature/Taxation/WithholdingCertificateTest.php`. Expected: FAIL.

- [ ] **Step 3: Fix.**
  - `CertificatePDFService:85-88`: replace `number_format((float) $certificate->gross_amount, 3, '.', ',')` with a bcmath-scaled string then group only for display: `$g = CurrencyScale::bcformat((string) $certificate->gross_amount, 3);` and format thousands without re-floating (use a string grouping helper or `number_format` on the **already-bcformatted string** is acceptable since it's display-only at the very end — but do NOT `(float)` the source). Apply to all three figures.
  - `MtdJsonExporter`: keep every box (`box1..box9`) as a numeric-string through all arithmetic; `box5 = bcabs(bcsub($box3,$box4,2))` (implement `bcabs` as `ltrim($v,'-')` after a `bccomp`). Change `toDecimal2(string): float` to `toDecimal2(string): string` returning `CurrencyScale::bcformat($value, 2)`. At the final `return [...]` payload, cast each numeric box to float **once** with a `// HMRC MTD JSON requires numeric type; value already bcmath-rounded to 2dp` comment.

- [ ] **Step 4: Run, verify pass** — `./vendor/bin/phpunit tests/Feature/Taxation/WithholdingCertificateTest.php tests/Feature/Taxation/WithholdingPrecisionTest.php`. PHPStan on the two files.

- [ ] **Step 5: Commit** — `git commit -am "fix(taxation): certificate + MTD VAT figures via bcmath, float only at JSON edge (P0-6)"`.

---

## Task 6: P0-3 — Billing entities float arithmetic + float status gates  ⚠️ SCOPE-GATED

> **DO NOT START until the owner confirms SaaS subscription billing is in launch scope.** If deferred, re-file as P1 (`docs/superpowers/audits/.../README.md` already notes this) and skip to Task 7. The audit raised this exact gate.

**Files:**
- Modify: `app/Modules/Billing/Domain/Invoice.php` (`recordPayment` ~195), `Payment.php` (`isRefundable`/`getRefundableAmount`/`recordRefund` ~182-244), `InvoiceItem.php` (`calculate*` ~94-113), `Refund.php` (`markAsSucceeded` ~108)
- Modify: `app/Modules/Billing/Presentation/Controllers/AdminBillingController.php:277,341,388`
- Use: `app/Modules/Billing/Domain/ValueObjects/Money.php` (already bcmath: `add/subtract/multiply/equals/greaterThan/lessThan`)
- Test: `tests/Unit/Billing/MoneyBcmathTest.php`, `tests/Feature/Billing/CreateManualInvoicePrecisionTest.php` (extend)

**Interfaces:**
- Produces: `recordPayment(string $amount)`, `recordRefund(string $amount)`, `getRefundableAmount(): string`, `calculateAmount(): string` etc. — all numeric-string; status gates via `Money`/`bccomp`.

- [ ] **Step 1: Failing test** — invoice whose `total` and a sequence of partial payments sum to exactly `total` but float-drift would leave `amount_due` at `0.00…2`, asserting status flips to `Paid` (not stuck `PartiallyPaid`). Plus an `InvoiceItem::calculateAmount` exactness test.

- [ ] **Step 2: Run, verify fails** — `./vendor/bin/phpunit --filter <name> tests/Feature/Billing/CreateManualInvoicePrecisionTest.php`. Expected: FAIL.

- [ ] **Step 3: Fix** — route each entity through `Money` (or numeric-string bcmath): `recordPayment`/`recordRefund` take `string`, compute `$newAmountPaid = bcadd((string)$this->amount_paid, $amount, $scale)`, gate with `bccomp($newAmountDue, '0', $scale) <= 0`. `calculate*` return bcmath strings. `AdminBillingController` drops the three `(float)` casts; `calculateMRR` uses `bcadd`/`bcdiv`. Update the (few) callers — `Payment.php:213`, `Refund.php:108`, `AdminBillingController:277` — to pass strings.

- [ ] **Step 4: Run, verify pass** — `./vendor/bin/phpunit tests/Unit/Billing/MoneyBcmathTest.php tests/Feature/Billing/CreateManualInvoicePrecisionTest.php`. PHPStan on the Billing files.

- [ ] **Step 5: Commit** — `git commit -am "fix(billing): entity money via Money VO/bcmath, no float status gates (P0-3)"`.

---

## Task 7: Lock the cleaned paths + slice preflight

**Files:**
- Inspect: `app/PHPStan/Rules/ForbidFloatCastOnDecimalProperty.php`, `ForbidHardcodedBcmathScale.php` and `phpstan.neon` (their `paths`/scope)
- Modify: `phpstan.neon` if the rules are path-scoped and don't yet cover the files touched above.

- [ ] **Step 1:** Read the two guard rules + `phpstan.neon` to see whether they run repo-wide or on a path allowlist.
- [ ] **Step 2:** If allowlisted, add the files cleaned in Tasks 1–6 so a re-introduced `(float)` on these paths fails CI. If repo-wide already, confirm the touched files now pass and note it.
- [ ] **Step 3: Slice preflight (touched files only — never full suite):**
  - `cd apps/api && ./vendor/bin/pint --dirty`
  - `./vendor/bin/phpstan analyse <each touched .php file>` → 0 errors
  - Re-run each task's test file (collect the filters)
  - `cd apps/web && pnpm typecheck && pnpm vitest run src/features/pos/organisms/AdvancedPaymentsModal`
- [ ] **Step 4: Commit** — `git commit -am "test(precision): extend float-cast guard coverage over cleaned P0 paths (P0 sweep)"`.
- [ ] **Step 5:** Update `docs/superpowers/audits/2026-06-24-hexagonal-soc-audit/VERIFICATION-2026-06-29.md` — mark each P0 fixed with its commit sha; if P0-3 deferred, note it dropped to P1.

---

## Self-review notes

- **Spec coverage:** P0-1…P0-6 each have a task; P0-3 explicitly gated per the audit's own caveat; Task 7 closes the "extend the PHPStan guards to lock these once fixed" item from README §5.1.
- **Not in this plan (correct):** P1/P2/P3 (cross-module deptrac ruleset, Domain-purity, fat controllers, frontend volume debt) — separate efforts. P0-6's `WithholdingTaxRule::getRateAsPercentage` already returns string (no task needed; verified 2026-06-29).
- **Blast-radius confirmed small:** WAC `recordPurchase` has one caller; `calculateNewWAC` none; POS discount param has one Domain method + its Application caller; the rest are leaf entities.
- **Open confirmations for the implementer (read the full method before editing — do not placeholder):** (a) `DraftPersistenceService` currency source for the scale resolver; (b) `ReceiptCreationService` `$derivedPercent` is produced as a numeric-string; (c) MTD HMRC payload genuinely requires JSON number type at the edge (keep the single documented cast).
