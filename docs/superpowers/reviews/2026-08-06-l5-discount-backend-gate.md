# Merge gate — L5 line `discount_amount` guard (backend half)

- **Branch:** `fix/l5-discount-amount-toggle`, worktree `/Users/houssamr/Projects/syneriva/apps/erp.fix-l5-discount`
- **Commit under review:** `47b4114e1` — *fix(document): reject over-gross line discount_amount at the 422 boundary, floor computeLineTotal at zero (W-3)*
- **Diffed against:** `695f6814d`
- **Out of scope:** FE commit `07a60fa05` (gated separately)
- **Spec:** owner ruling W-3 "Option A" (`docs/superpowers/tickets/2026-08-03-w3-line-discount-amount-no-ui-and-negative-net.md`)
- **Reviewer stance:** adversarial; every claim re-verified against code or executed. Gate only — no code modified, nothing merged.

## Verdict: **APPROVE-WITH-FIXES**

The fix is architecturally correct and does what the ruling asked: the 422 boundary is real, it
covers every document type that has a discount surface, the domain floor is a genuine backstop,
and the floor propagates correctly into subtotal, tax base and totals. One defect in the new
rule must be fixed before merge (CRITICAL-1, a ~2-line change in the file being added), and one
convention violation in the same diff should go with it (IMPORTANT-2).

---

## What was executed

| Command | Result |
|---|---|
| `phpunit tests/Unit/Modules/Document/DocumentLineModelTest.php tests/Feature/Modules/Document/CreateDocumentLineValidationTest.php` | **OK 20/20**, 50 assertions |
| `phpunit tests/Feature/Document/DiscountToleranceValidationTest.php tests/Feature/Document/DiscountPolicyDocumentValidationTest.php` | **OK 17/17**, 48 assertions |
| `phpunit tests/Feature/Document/Types` | **OK 53/53**, 174 assertions |
| `phpunit tests/Feature/Document/IngressPrecisionTest.php` | **ERRORS 13/22** — pre-existing, see IMPORTANT-3 |
| `phpstan analyse` on the 5 touched `app/` files (level 8) | **No errors** |
| Standalone `ValidationRuleParser` probe (Laravel 12.58.0) | wildcard-vs-explicit claim **confirmed** |
| Standalone `Illuminate\Validation\Factory` probe against the real rule class | per-index targeting **confirmed**; bcmath `ValueError` **confirmed** |

---

## Findings

### CRITICAL-1 — the new rule turns malformed-but-`is_numeric` input into an uncaught 500 instead of the 422 the spec mandates

`app/Modules/Document/Presentation/Rules/LineDiscountAmountWithinGross.php:53` guards with
`is_numeric($value)` and `:73` with `is_numeric($quantity) || is_numeric($unitPrice)`, then feeds
those values straight into `bcmul(...)` at `:79` and `bccomp(...)` at `:82`. PHP's `is_numeric()`
accepts strings that bcmath rejects as "not well-formed" — exponent notation and
leading/trailing whitespace. bcmath then throws an uncaught `ValueError`, which is a 500, not a
422.

Proven by executing the real rule class through a real `Illuminate\Validation\Factory`:

| Payload | Result |
|---|---|
| `discount_amount: "1e3"` (string) | `ValueError: bccomp(): Argument #1 ($num1) is not well-formed` |
| `discount_amount: 1e25` (JSON number → PHP float → `(string)` → `"1.0E+25"`) | `ValueError` on `bccomp` |
| `discount_amount: " 21 "` (padded) | `ValueError` on `bccomp` |
| `quantity: "1e2"` | `ValueError: bcmul(): Argument #1 ($num1) is not well-formed` |

The `regex:/^\d+(\.\d{1,3})?$/` sibling rule does **not** save this: Laravel has no implicit bail,
`Validator::shouldStopValidating()` only short-circuits on `Bail`, `uploaded`, or a failed
implicit `Required` — so the gross rule still runs after the regex has already failed.

**Concrete failure scenario.** `POST /api/v1/quotes` with one *service* or free-text line (no
`product_id`) and `{"quantity":"1","unit_price":"10.000","discount_amount":"1e3"}` returns **500**.
At `695f6814d` the identical payload returned a clean **422** from the regex rule.

**Newly introduced surface.** The same unsound `is_numeric → bcmath` pattern already exists at
`app/Modules/Treasury/Presentation/Rules/DiscountAboveTolerance.php:44`,
`app/Modules/Document/Presentation/Requests/Concerns/AppliesDiscountToleranceRule.php:73-77`, and
`app/Modules/Document/Presentation/Validation/DiscountPolicyDocumentValidator.php:179` — so
invoice/order routes, and lines carrying a `product_id` on any route (the discount-policy
after-hook `continue`s on lines without one,
`DiscountPolicyDocumentValidator.php:66`), already 500 on these payloads. What this diff adds is
the same crash on **service / free-text lines across every document type**, i.e. previously-clean
422 paths.

**Remedy** (in the new file only): replace both `is_numeric` guards with a bcmath-well-formed
check — e.g. `preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)$/', $s)` — and return early (leaving the
`numeric`/`regex` rules to emit the 422) when it fails. File the codebase-wide pattern
(`DiscountAboveTolerance`, `AppliesDiscountToleranceRule`, `DiscountPolicyDocumentValidator`,
`numericString()`) as a separate ticket; it is out of this diff's scope.

### IMPORTANT-2 — Rule 13 violation: `app()` helper used where the dependency is already injected

`app/Modules/Document/Presentation/Requests/Concerns/AppliesDiscountToleranceRule.php:59`:

```php
$scale = app(CurrencyScaleResolverInterface::class)->getScale($company->currency);
```

This is new in this diff and it is avoidable. The trait has exactly two consumers —
`CreateDocumentRequest` and `UpdateDocumentRequest` — and **both now constructor-inject the
resolver** in this very commit (`CreateDocumentRequest.php:32`, `UpdateDocumentRequest.php:32`).
The trait can read `$this->scaleResolver` directly. CLAUDE.md rule 13 is unambiguous: "Never use
`app()` helper."

(The pre-existing `app(CompanyContext::class)` at `:53` is out of scope, but the identical remedy
is available — both requests inject `CompanyContext` too.)

### IMPORTANT-3 — "existing suites green" is false: `IngressPrecisionTest` errors 13/22, including both `discount_amount` precision tripwires

`tests/Feature/Document/IngressPrecisionTest.php` errors on every test routed through its
`documentLineRules()` helper (`:277-296`), which does
`new CreateDocumentRequest($context, app(CompanyConfigService::class), app(PurchaseBonusGate::class))`
— 3 arguments against a constructor that now requires 5.

**This is pre-existing, not caused by this diff:** the test file is byte-identical to `695f6814d`
(`git diff 695f6814d HEAD -- …/IngressPrecisionTest.php` is empty) and the base constructor
already required 4 non-defaulted promoted params (`git show 695f6814d:…/CreateDocumentRequest.php`
lines 24-31) — the breakage landed with `1a0f366ca`. But two things follow:

1. The implementer's blast-radius claim ("existing suites green") is untrue for this file, and it
   was not spot-checked.
2. This is precisely the suite that asserts the **effective rule set** for `lines.*.discount_amount`
   (`test_document_line_rejects_4_decimal_discount_amount`,
   `test_document_line_accepts_3_decimal_discount_amount`). It is the test that would have caught
   a dropped `regex` ceiling — the exact risk the wildcard-vs-explicit merge question was about —
   and it has been dead the whole time. Fixing the arity (adding the two new ctor args to the
   helper) is a 2-line change and belongs with this commit, not a ticket.

### IMPORTANT-4 — no detection or decision for already-persisted negative `line_total` rows

The floor changes what a **recompute** produces, not only what a create accepts. Every recompute
path routes through `computeLineTotal()`/`calculateTotal()`:
`TaxCalculationService.php:150` and `:332`, `DocumentTotalsCalculator.php:43`,
`StripSubToleranceDiscountsService.php:101`, `CreditNoteService.php:317/601/1034`. So any document
already holding a negative-net line will silently change its subtotal / tax_amount / total on its
next recompute — including the fiscal recompute at confirm, meaning the confirmed (hash-chained)
totals will differ from what the operator last saw on the draft.

Such rows demonstrably exist: the W-3 ticket records MTP-DSC-04 as **green as a tripwire** against
live `demo-pharmacy-tn`, i.e. a real invoice with `line_total = -75.000`, `subtotal = -75.000`,
`tax_amount = -13.250`, `total = -88.250` was created there. This commit ships no detection query,
no backfill, and no deploy note. **Owed before staging deploy:** a
`SELECT … FROM document_lines WHERE line_total < 0` sweep per tenant DB, and an owner decision on
what to do with the hits (fix-forward vs leave-as-is-and-never-recompute).

### minor-5 — `PriceEntryMode::Total` lines can take a false 422 on a field the controller never reads

`PurchaseOrderController::normalizePurchaseLine()` `:101-106` **derives** `unit_price` from the
client-supplied `line_total` in Total mode and never applies `discount_amount` at all (the
discount branch is the `else`, `:108-118`). But `lines.*.unit_price` is still `required`
(`CreateDocumentRequest.php:124`) and the new rule compares `discount_amount` against
`qty × unit_price` regardless of mode. A Total-mode line sent with a placeholder `unit_price` can
be rejected over a value that has no effect on the stored document. Narrow — Total mode is
purchase-bonus-gated (`PurchaseBonusGate`) — but worth a mode check or a doc note.

### minor-6 — the comparison scale comes from the *company* currency, not the *document* currency

`CreateDocumentRequest.php:141`, `UpdateDocumentRequest.php:119` and
`AppliesDiscountToleranceRule.php:59` all resolve `getScale($company->currency)`. Documents can be
persisted in a different currency — `InvoiceController.php:307`:
`'currency' => $validated['currency'] ?? $company->currency`. The guard is nonetheless
**self-consistent** with the arithmetic it is guarding, because the controllers' own `scale()`
(`InvoiceController.php:76-79`, `QuoteController.php:61-64`) is a bare no-arg
`$this->scaleResolver->getScale()` resolving from the same company context. So no guard/compute
divergence is introduced. Both mis-scale a foreign-currency document — pre-existing, inherited,
not introduced. Note only.

### minor-7 — gross truncation is conservative but produces a sub-scale false reject

`bcmul(...)` at `:79` truncates, so the computed gross can be lower than the true gross. Verified:
at scale 2 with `quantity 3 × unit_price 10.005` (true gross 30.015), gross is computed as `30.01`
and a `30.015` discount is **rejected**. The direction is safe (never a false pass), and
`unit_price` is regex-capped at 3dp, so this only bites on a scale-2 currency with a 3dp unit
price. Acceptable; worth one line in the rule's docblock.

### minor-8 — docblock overstates coverage

`LineDiscountAmountWithinGross.php:23-24` says the rule fires for "quotes, **credit notes**,
delivery notes, invoices, orders". `CreditNoteController::store()` (`:125`) takes a plain
`Illuminate\Http\Request` with inline rules (`:135-165`) and accepts no per-line
`discount_amount` at all. Harmless (no surface to guard), but the comment is wrong and will
mislead the next reader into thinking credit notes are covered by `CreateDocumentRequest`.

### minor-9 — the two "negative discount_amount" tests are not red-at-base

`negative_discount_amount_fails_validation_on_quotes` / `…_on_invoices`
(`CreateDocumentLineValidationTest.php`) would have passed at `695f6814d`: `min:0` plus
`regex:/^\d+(\.\d{1,3})?$/` already rejected negatives on the base wildcard
(`CreateDocumentRequest.php:130` at base) **and** on the tolerance override
(`AppliesDiscountToleranceRule.php:102`, pre-existing). Negative `discount_amount` was never
reachable through these HTTP routes, on create or update. Good regression guards; they prove
nothing about the new rule, and spec item "reject negative discount_amount" was already satisfied.
No action.

### minor-10 — the MTP-DSC-04 tripwire flip lives in the FE-gated commit, not this one

`apps/web/e2e/money-campaign/documents-discounts.spec.ts:163-211` is correctly **updated, not
deleted**: it now asserts `422` and that the body contains `lines.0.discount_amount`, with the
tripwire rationale retained in the comment (`:174-190`). It would go red if the fix were reverted
(the old behaviour was `201` with `line_total = -75.000`). **But** `git diff --name-only 695f6814d
47b4114e1` contains no e2e file — the flip is in `07a60fa05`, the FE commit. If the FE half is
held or reverted independently, CI runs the old red assertion against a fixed backend. Sequence
the two gates, or land the spec change with the backend.

### minor-11 — test payloads use PHP float literals for money

`CreateDocumentLineValidationTest.php` sets `$line['unit_price'] = 12.500;`,
`$line['discount_amount'] = 200.000;` etc. as PHP floats. These particular values are exactly
representable so the tests are stable, but rule 19 says payloads go as strings, and float literals
weaken the tests as precision guards.

---

## Claims verified clean

**Claim 1 — per-line index targeting.** Confirmed by executing the real rule class through
`Illuminate\Validation\Factory` with a payload of 4 lines and a **single shared** rule instance
(the way `CreateDocumentRequest::rules()` constructs it). Only `lines.1.discount_amount` failed,
and the message carried *line 1's* gross (`2.000`), not line 0's. Missing/null/`"abc"` `quantity`
or `unit_price` on the same line → early return at `:73-77`, no fatal and no false pass (the line
is separately rejected by `required` / `required_with`). Out-of-range index → early return at
`:69-71`. The regex anchor at `:59` correctly ignores the header-level `discount_amount`
attribute.

**Claim 2 — Laravel wildcard-vs-explicit merge.** The implementer's reading is **correct**, and I
verified it two ways.

*Mechanism* (`vendor/laravel/framework/src/Illuminate/Validation/ValidationRuleParser.php`):
`explodeRules()` `:68-80` iterates a snapshot of the original array. The wildcard key is expanded
first (insertion order — `rules()` builds the wildcards, then `withDiscountToleranceRules()`
appends the per-index keys), merging into `$rules['lines.0.discount_amount']` via
`explodeWildcardRules()` `:159-189` → `mergeRulesForAttribute()` `:223-231`. The loop then reaches
the explicit key **from the pre-merge snapshot** and does `$rules[$key] = explodeExplicitRule($rule, $key)`
at `:76` — an assignment, not a merge, overwriting the merged result.

*Empirically*, with marker rules through the real parser:

```
WITH explicit override:  lines.0.discount_amount => [nullable, numeric, min:0, regex, TOLERANCE_MARKER, GROSS_MARKER]
WITHOUT:                 lines.0.discount_amount => [nullable, numeric, min:0, regex, BASE_WILDCARD_MARKER]
```

`BASE_WILDCARD_MARKER` is gone — replacement, not merge. So the explicit listing in the trait
(`:107-110`) is **necessary**, not redundant.

*Direction (b) — did anything get dropped?* No. Effective rule sets:

| Route | Effective `lines.N.discount_amount` |
|---|---|
| base (`quotes.*`, `delivery-notes.*`, `return-notes.*`, `purchase-orders.*`) | `nullable`, `numeric`, `min:0`, `regex 3dp`, `LineDiscountAmountWithinGross` |
| tolerance (`invoices.store/update`, `orders.store/update`) | `nullable`, `numeric`, `min:0`, `regex 3dp`, `DiscountAboveTolerance`, `LineDiscountAmountWithinGross` |

The `regex` precision ceiling was **already present** in the trait's override
(`AppliesDiscountToleranceRule.php:102`) before this diff, so no rule-19 ceiling was silently
lost. The header `discount_amount` override (`:121-130`) has no base wildcard counterpart in
`CreateDocumentRequest`, so nothing is dropped there either.

*Updates.* `UpdateDocumentRequest` gets identical treatment (`:111-120`) and the trait fires on
`invoices.update` / `orders.update` (`:152-157`). **Partial line updates are not a hole**:
`lines.*.quantity` and `lines.*.unit_price` are `required_with:lines`
(`UpdateDocumentRequest.php:97`, `:101`), so a line can never arrive carrying a `discount_amount`
without its own siblings.

**Claim 4 — floor propagation.** `DocumentLine.php:297-299` floors, and the floored value is what
everything downstream consumes. `InvoiceController.php:276-286` computes
`$lineTax = bcmul($lineSubtotal, …)` from the **floored** `$lineSubtotal` and sums the floored
value into `$subtotal`; `$total = bcadd($subtotal, $taxAmount)` at `:289`; the persisted
`line_total` at `:321-327` is the same floored call. Same shape in
`SalesOrderController.php:212/256/379` and `PurchaseOrderController.php:111`.
`TaxCalculationService.php:150` and `:332` and `DocumentTotalsCalculator.php:43` go through
`calculateTotal()`, which delegates to the floored helper (`DocumentLine.php:248-256`). A floored
line therefore contributes **0** — not a negative — to subtotal, tax base, total, and to the GL
entries derived from those totals. `bcmul('0','0',$scale)` is a correct scaled zero at every
scale including 0. Matches the ticket's expected post-fix shape (line net 0, line VAT 0, TN stamp
duty still `1.000`).

**Non-issue: combined percent + amount.** `computeLineTotal()` `:290-296` is an `if/elseif` —
`discount_percent` takes precedence and `discount_amount` is ignored when a non-zero percent is
present. So a "50% *and* 100.000 off a 125.000 gross" payload cannot slip past the amount-vs-gross
guard into a silent floor. Verified in code; no gap.

**Claim 6 — i18n.** Key present in both locales — `lang/en/documents.php:20`,
`lang/fr/documents.php:8`, both inside the `discount` array — and referenced by the rule at
`LineDiscountAmountWithinGross.php:83` with the `:gross` placeholder supplied. `lang/` contains
only `en` and `fr`; no third locale is owed.

**Claim 7 — quality.** PHPStan level 8: **no errors** on
`LineDiscountAmountWithinGross.php`, `CreateDocumentRequest.php`, `UpdateDocumentRequest.php`,
`AppliesDiscountToleranceRule.php`, `DocumentLine.php`. No float touches money anywhere in the
production diff — all arithmetic is bcmath on strings. Constructor injection is used in both
FormRequests; the one `app()` call is IMPORTANT-2.

**POS untouched.** `git diff --stat 695f6814d 47b4114e1` covers only
`app/Modules/Document/**`, `lang/{en,fr}/documents.php`, and two test files. No `apps/pos`, no
device code, no projections.

**Coverage across document types.** `CreateDocumentRequest` / `UpdateDocumentRequest` back
`QuoteController`, `InvoiceController`, `SalesOrderController`, `DeliveryNoteController`,
`ReturnNoteController`, `PurchaseOrderController`. `CreditNoteController::store()` does not use
them, but accepts no per-line `discount_amount` (see minor-8) — so the spec's "ALL document types"
is satisfied in substance.

---

## Pre-existing bug confirmed and correctly left alone

**`QuoteController` ignores line discounts entirely.** Quote store computes header totals with a
bare `bcmul($quantity, $unitPrice, $this->scale())` at `QuoteController.php:196` and persists the
same undiscounted product as `line_total` at `:234` / `:257`, while faithfully storing
`discount_percent` and `discount_amount` on the line at `:254-255`. The update path repeats it at
`:351` / `:378`. So a quote's `line_total`, `subtotal`, `tax_amount` and `total` are all computed
as if no line discount existed — unlike `InvoiceController`, `SalesOrderController` and
`PurchaseOrderController`, which route through `computeLineTotal()`.

Genuinely pre-existing (present at `695f6814d`; `QuoteController.php` does not appear in
`git diff --name-only 695f6814d 47b4114e1`) and correctly untouched under rule 4 (no scope creep).
Two consequences worth a ticket:

- The new 422 guard is currently the *only* line-discount behaviour quotes have — the value is
  validated, stored, and then ignored by every total.
- A quote → invoice conversion changes the totals, because the invoice side *does* apply the
  discount.

---

## Merge conditions

1. **CRITICAL-1** — replace the two `is_numeric` guards in
   `LineDiscountAmountWithinGross.php:53` and `:73` with a bcmath-well-formed check. Add a
   regression test for `discount_amount: "1e3"` on a service/free-text line asserting **422**, not
   500.
2. **IMPORTANT-2** — drop `app(CurrencyScaleResolverInterface::class)` from
   `AppliesDiscountToleranceRule.php:59` in favour of the `$this->scaleResolver` this commit
   already injects into both consumers.
3. **IMPORTANT-3** — fix the `IngressPrecisionTest::documentLineRules()` constructor arity
   (`:288-292`) so the `discount_amount` precision tripwires actually run again, and re-report the
   blast radius honestly.
4. **IMPORTANT-4** — file the negative-`line_total` detection sweep as a deploy prerequisite with
   an owner decision; do not deploy to staging without it.
5. minor-5..11 — ticket, no merge block. Sequence the FE gate (minor-10) so the flipped MTP-DSC-04
   assertion and the backend fix land together.

---

## Fix-round disposition (implementer, 2026-08-07)

All four merge conditions addressed on `fix/l5-discount-amount-toggle`:

1. **CRITICAL-1** — fixed. `LineDiscountAmountWithinGross::isBcmathSafeDecimal()` now guards every
   operand (`discount_amount`, `quantity`, `unit_price`) with `is_numeric()` (for PHPStan
   `numeric-string` narrowing) **plus** the stricter bcmath grammar `/^-?\d+(\.\d+)?$/` before any
   value reaches `bcmul()`/`bccomp()`. A non-conforming value makes the rule return silently,
   leaving the 422 to the field's own `numeric`/`regex` rule — never a 500. Red-proven against the
   pre-fix rule (via `git stash` on just that file) with 4 new regression tests in
   `CreateDocumentLineValidationTest.php`: exponent-string `"1e3"`, JSON-float-exponent `1.0e25`,
   padded whitespace `" 21 "` (passes for a different, still-correct reason — see the test's inline
   note, this app's global `TrimStrings` middleware normalizes it before validation), and exponent
   `quantity: "1e2"` alongside a well-formed `discount_amount`. All 4 reproduced the documented
   `bcmul()/bccomp(): ... is not well-formed` `ValueError` pre-fix, all 4 pass post-fix.
2. **IMPORTANT-2** — fixed. `AppliesDiscountToleranceRule::withDiscountToleranceRules()` now reads
   `$this->scaleResolver` (both consumers constructor-inject `CurrencyScaleResolverInterface` as
   that exact property name; traits share the host class's scope, so the private property is
   directly visible) instead of `app(CurrencyScaleResolverInterface::class)`. The now-unused import
   was removed. Zero `app()` calls added by this diff (the pre-existing
   `app(CompanyContext::class)` at the top of the same method predates this branch and is left
   alone, out of scope per rule 4).
3. **IMPORTANT-3** — fixed. `IngressPrecisionTest::documentLineRules()` now passes
   `app(DiscountPolicyDocumentValidator::class)` and `app(CurrencyScaleResolverInterface::class)`
   as the CreateDocumentRequest constructor's 4th/5th args. Full suite re-run: **22/22 passed, 98
   assertions**, including both `discount_amount` precision tripwires
   (`test_document_line_rejects_4_decimal_discount_amount`,
   `test_document_line_accepts_3_decimal_discount_amount`). No deeper reds surfaced beyond the
   constructor arity — the only other output is two pre-existing PHPUnit metadata-doc-comment
   deprecation warnings (unrelated, not blast radius from this branch).
4. **IMPORTANT-4** — detection query below, no migration, no auto-fix, per the directive.

### BE-4 — pre-existing negative-net row detection (run per-tenant DB before staging deploy)

```sql
-- W-3 backend gate BE-4 (2026-08-06) — detects rows a RECOMPUTE will change once the
-- floor lands: either already negative, or over-discounted such that the next
-- recompute (including at invoice/order confirm) will floor them to zero.
-- Detection only. Do NOT auto-fix; route hits to the accountant/owner for
-- fix-forward-vs-leave-as-is disposition. Run once per tenant database
-- (database-per-tenant topology).
SELECT
    dl.id                          AS line_id,
    dl.document_id,
    dl.line_number,
    dl.quantity,
    dl.unit_price,
    dl.discount_percent,
    dl.discount_amount,
    dl.line_total,
    (dl.quantity * dl.unit_price)  AS line_gross,
    d.document_number,
    d.type                         AS document_type,
    d.status                       AS document_status,
    d.company_id
FROM document_lines dl
JOIN documents d ON d.id = dl.document_id
WHERE dl.line_total < 0
   OR (dl.discount_amount IS NOT NULL AND dl.discount_amount > (dl.quantity * dl.unit_price))
ORDER BY d.company_id, dl.document_id, dl.line_number;
```

**Behaviour-change note (also in the fix-round commit body):** the domain floor changes what a
*recompute* produces, not only what a *create* accepts. Every recompute path
(`TaxCalculationService`, `DocumentTotalsCalculator`, `StripSubToleranceDiscountsService`,
`CreditNoteService`, and the confirm-time fiscal recompute on `InvoiceController`/
`SalesOrderController`) routes through the now-floored `DocumentLine::computeLineTotal()`. Any
document already holding a negative-net line — the MTP-DSC-04 tripwire's own live artifact on
`demo-pharmacy-tn` is a known instance — will show a **different** `line_total`/`subtotal`/
`tax_amount`/`total` the next time it is recomputed, including at confirm, meaning the confirmed
(hash-chained) totals could differ from what the operator last saw on the draft. This branch ships
no backfill and no migration; the query above is the pre-deploy detection step, and disposition is
an owner/accountant decision, not an engineering one.

**Deferred with a ticket (minor-5..11, no merge block, per the gate's own instruction):**
`PriceEntryMode::Total` false-422 exposure (minor-5), company- vs document-currency scale mismatch
(minor-6, pre-existing/inherited), sub-scale gross truncation false-reject (minor-7), the
`LineDiscountAmountWithinGross` docblock overstating credit-note coverage (minor-8), the two
already-negative-test notes (minor-9, no action needed), and `QuoteController` ignoring line
discounts entirely in its totals (pre-existing, confirmed correctly untouched under rule 4).

---

# Fix-round re-verify (reviewer, 2026-08-07)

Re-verified commit **`c3df4fafc`** stacked on `47b4114e1`. FE fix `1f1d85a34` confirmed web-only
(`git show --name-only 1f1d85a34` touches no `apps/api` path) and remains out of scope. Narrow
pass — only the four findings, plus the probes the coordinator asked for. Nothing re-reviewed that
was already cleared.

## Verdict: **CLEAR TO MERGE** (backend half)

All four merge conditions are genuinely closed. Two residual observations are recorded below; both
are ticket-grade and neither blocks.

### Re-verification executed

| Check | Result |
|---|---|
| `phpstan` level 8, 5 touched files | **No errors** |
| `phpunit IngressPrecisionTest` | **OK 22/22, 98 assertions** — matches the claim exactly |
| `phpunit CreateDocumentLineValidationTest + DocumentLineModelTest + DiscountToleranceValidationTest + DiscountPolicyDocumentValidationTest` | **OK 41/41, 113 assertions** |
| `git diff 695f6814d c3df4fafc -- apps/api/app \| grep '^+.*app('` | only a *comment* mentioning `app()`; **zero added `app()` calls** |
| 11-form probe of the fixed rule through a real `Illuminate\Validation\Factory` | all clean 422s, no fatals, no false-422s |
| 25-form fuzz: helper-accepts ⊆ bcmath-accepts | **0 subset violations** |

### CRITICAL-1 — CLOSED

`isBcmathSafeDecimal()` (`LineDiscountAmountWithinGross.php:105-113`) is applied to all three
operands (`:53` for `$value`, `:80` for `$quantity`/`$unitPrice`). I re-ran every crash payload
from the original finding, plus the coordinator's additional forms, through the real validator with
the production rule arrays. Every one is now a clean 422 owned by the sibling `regex`/`numeric`
rule, with the gross rule **silent** (no `GROSS-RULE-FIRED` message, no `ValueError`):

| Input | Before (`47b4114e1`) | After (`c3df4fafc`) |
|---|---|---|
| `discount_amount: "1e3"` | `ValueError` (500) | 422 `regex` |
| `discount_amount: 1.0e25` (JSON float) | `ValueError` (500) | 422 `regex` |
| `discount_amount: "1e-3"` (negative exponent) | `ValueError` (500) | 422 `regex` |
| `discount_amount: "1.5E+3"` | `ValueError` (500) | 422 `regex` |
| `discount_amount: "+21"` (leading plus) | passed to bcmath (bcmath-safe) | 422 `regex` |
| `discount_amount: " 21 "` (untrimmed) | `ValueError` (500) | 422 `regex` |
| `discount_amount: ".5"` / `"21."` | bcmath-safe | 422 `regex` |
| `quantity: "1e2"` | `ValueError` on `bcmul` (500) | 422 `lines.0.quantity.regex` |
| `unit_price: "1e2"` | `ValueError` on `bcmul` (500) | 422 `lines.0.unit_price.regex` |

**No false-422s and no lost coverage.** The guard still fires exactly where it must: `200.000` vs
gross `10.000` → rejected with `gross=10.000`; `10.000` == gross → **accepted, no errors**;
`10.001` (gross + 0.001) → rejected. So the rule contributes nothing on malformed input and
everything on well-formed input — the message ownership the coordinator asked about is correct.

**The helper is provably safe, not just stricter.** Fuzzed 25 forms: every value the helper accepts
is bcmath-well-formed (0 subset violations), so no path can reach `bcmul`/`bccomp` with a bad
operand. The forms the helper rejects but bcmath would accept — `.5`, `21.`, `+21`, `-.5`, `-` — are
all independently rejected by the field's own `regex:/^\d+(\.\d{1,3})?$/`, on the base wildcard
*and* on the tolerance override (`AppliesDiscountToleranceRule.php:102`). **No over-gross value can
escape to a 201 through the stricter guard.** The `-?` in the helper's grammar is harmless:
negatives still 422 on `min:0` + `regex`.

**HTTP-layer fidelity of their proof — confirmed.** The coordinator asked which layer the
JSON-float test exercised. The 4 new tests use `postJson` against `/api/v1/quotes`
(`CreateDocumentLineValidationTest.php`), i.e. the full HTTP kernel including global middleware —
not a synthetic validator. I verified the wire round-trip independently:
`json_encode(1.0e25)` → `{"discount_amount":1.0e+25}` → decodes to PHP `double` → stringifies as
`"1.0E+25"`, `is_numeric() === true`. So that test really does deliver an exponent-stringifying
value to the rule through a real request. Redness pre-fix does not rest on the implementer's
stash-revert alone — my own round-1 `Illuminate\Validation\Factory` run independently produced the
`ValueError` for `"1e3"`, `1.0E+25`, `" 21 "` and `"1e2"`.

**The `" 21 "` test is honestly labelled.** Global `TrimStrings` normalizes it to `21` before
validation, so on this pipeline it 422s as a legitimate over-gross (`21 > 10.000`), not as a
bcmath-safety proof. The implementer documented this inline rather than banking it as false
evidence — correct handling, and the test is still worth keeping as a shape regression.

### IMPORTANT-2 — CLOSED

`AppliesDiscountToleranceRule.php:63` now reads `$this->scaleResolver`; the
`CurrencyScaleResolverInterface` import is gone. Both hosts declare the property with a concrete
type — `CreateDocumentRequest.php:31` and `UpdateDocumentRequest.php:31`, both
`private readonly CurrencyScaleResolverInterface $scaleResolver`. Grep over the whole branch diff
confirms **zero added `app()` calls**. PHPStan level 8 clean, which is the meaningful check here:
PHPStan analyses a trait body once per using class, so an undeclared property would have been
caught.

*Residual (minor, ticket):* the trait declares neither the property nor an abstract accessor, so a
future third consumer that forgets to inject `scaleResolver` fails at runtime, not at analysis
time. The same latent coupling already exists for `app(CompanyContext::class)`'s replacement path.
An `@property-read` docblock or an abstract getter on the trait would make the contract explicit.
Not a blocker — there are exactly two consumers and both satisfy it.

### IMPORTANT-3 — CLOSED

Arity fixed at `IngressPrecisionTest.php:294-295` (adds `DiscountPolicyDocumentValidator` and
`CurrencyScaleResolverInterface`). I re-ran the suite myself: **22/22, 98 assertions** — the claim
is accurate. The only remaining output is two pre-existing PHPUnit metadata deprecations.

**The regex-ceiling assertions genuinely execute against the current effective rule set.**
`documentLineRules()` (`:277-313`) pulls `lines.*.discount_amount` live out of `$request->rules()`
and hands the array verbatim to `Validator::make`, rule objects included — it is not a filtered
string subset. `test_document_line_rejects_4_decimal_discount_amount` fails on `'1.2345'` while
gross is `1 × 10.000`, so the failure is unambiguously the regex and not the gross rule; and
`test_document_line_accepts_3_decimal_discount_amount` asserts `'1.234'` produces **no** errors,
which would go red if the ceiling were dropped. Both tripwires are live again.

*Residual (minor, ticket) — two honest limits on what this suite proves:*
1. The helper builds the request with **no bound route**, so `isPaymentDueDocumentRoute()`
   (`AppliesDiscountToleranceRule.php:139-146`) returns false and the per-index override never
   enters `$rules`. The suite therefore covers the **base wildcard set only** — the
   `invoices.store` / `orders.store` explicit override, the array that the wildcard-vs-explicit
   *replacement* semantics make load-bearing, is still untested. Dropping the regex from
   `AppliesDiscountToleranceRule.php:102` would leave this suite green. My round-1 parser probe
   established the current effective sets are correct; a committed test asserting them is owed.
2. The helper re-keys `lines.*.discount_amount` → `discount_amount`, so the attribute the rule sees
   fails its `preg_match('/^lines\.(\d+)\.discount_amount$/')` anchor at `:66` and returns early.
   The gross rule is **inert** in this suite. Fine for a precision suite; just don't cite these
   tests as gross-guard coverage.

### IMPORTANT-4 — CLOSED as documented (owner action still open)

The detection SQL is sound. Verified against the two shapes that matter:

| Row | Clause 1 `line_total < 0` | Clause 2 `discount_amount > qty*price` | Flagged? |
|---|---|---|---|
| MTP-DSC-04 artifact: qty 10, price 12.500, disc 200.000, `line_total` −75.000 | TRUE | 200.000 > 125.000 TRUE | **yes** ✓ |
| Legit zero line: qty 1, price 10.000, disc 10.000, `line_total` 0.000 | FALSE (0 ≮ 0) | FALSE (strict `>`, not `>=`) | **no** ✓ |
| Legit zero line via `discount_percent = 100`, `discount_amount` NULL | FALSE | FALSE (`IS NOT NULL` guard) | **no** ✓ |

So it catches the target shape and does **not** false-positive on legitimate zero-total lines —
the strict `>` and the `IS NOT NULL` guard are both load-bearing and both correct. All referenced
columns exist and are `decimal`/`numeric` (`quantity` 15,4; `unit_price`, `discount_amount`,
`line_total` 15,2→3), so the arithmetic and comparisons are exact — no float in the query.

Three refinements worth folding in before it is run, none of which change the verdict:

- **One real false-positive class.** `computeLineTotal()` `:290-296` is `if/elseif` — a non-zero
  `discount_percent` makes `discount_amount` **inert**. A historical row with, say,
  `discount_percent = 10` and `discount_amount = 999` trips clause 2 even though its `line_total`
  is correct and a recompute changes nothing. Add
  `AND (dl.discount_percent IS NULL OR dl.discount_percent = 0)` to clause 2 to suppress it.
- **Sub-scale blind spot in clause 2.** PG computes `quantity * unit_price` at full precision,
  whereas `computeLineTotal()` truncates the gross to the currency scale. A discount exceeding the
  *truncated* gross but not the full-precision product is not flagged. Ultra-marginal (needs a
  scale-2 currency with a 3dp unit price) and clause 1 catches anything that materialised as
  negative; note only.
- **Soft-deleted documents are included.** `Document` uses `SoftDeletes` (`Document.php:112`);
  `DocumentLine` does not. The join has no `d.deleted_at IS NULL` predicate, so lines of
  soft-deleted documents appear. Arguably right for detection (a restore would resurrect them) —
  but add `d.deleted_at` to the SELECT so the operator can triage rather than silently including
  them.

**Still open and owner-gated:** the query has not been *run*. BE-4 remains a pre-deploy
prerequisite for staging — the MTP-DSC-04 live artifact on `demo-pharmacy-tn` is a known hit, and
its disposition (fix-forward vs leave-as-is-and-never-recompute) is an owner/accountant decision.

### minor-5..11 — accepted as deferred

The implementer's disposition matches this record's own instruction ("ticket, no merge block").
`QuoteController` re-confirmed untouched by the fix round.

## Merge conditions — final status

| # | Condition | Status |
|---|---|---|
| 1 | CRITICAL-1 bcmath-safe guard + regression tests | **CLOSED** — re-verified independently |
| 2 | IMPORTANT-2 drop `app()` | **CLOSED** — zero added `app()`, PHPStan clean |
| 3 | IMPORTANT-3 repair `IngressPrecisionTest` | **CLOSED** — 22/22 re-run by reviewer |
| 4 | IMPORTANT-4 detection sweep documented | **CLOSED as documented**; *running* it is an open pre-deploy owner gate |
| 5 | minor-5..11 ticketed; FE gate sequenced | **Accepted** |

**Backend half: CLEAR TO MERGE.** Do not deploy to staging until BE-4 has actually been run and
its hits dispositioned. Land `07a60fa05` + `1f1d85a34` together with the backend so the flipped
MTP-DSC-04 assertion and the fix arrive in the same CI run.
