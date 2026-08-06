# Discount lane (W-3) — out-of-lane findings from the dual gate (2026-08-07)

Source: docs/superpowers/reviews/2026-08-06-l5-discount-{backend,fe}-gate.md on
`fix/l5-discount-amount-toggle` (records travel with the branch at merge). In-lane items are
being closed in the lane's fix round; these are parked.

## 1. Pre-existing unsound is_numeric()+bcmath pattern (P1 — same 500-not-422 class as BE-1)

The backend gate proved `is_numeric()` admits exponent notation and padded strings that make
bcmath throw an uncaught ValueError (HTTP 500 on a validation surface). The NEW rule is being
hardened in-lane, but the same pattern PRE-EXISTS at:
- `DiscountAboveTolerance.php:44`
- `AppliesDiscountToleranceRule.php:73-77`
- `DiscountPolicyDocumentValidator.php:179`

Reachability: any document create/update with exponent-notation discount/qty/price fields on
routes carrying the tolerance rule. Fix = the same strict decimal-pattern guard being applied
in-lane; sweep the codebase for other `is_numeric` + bc* pairings while at it (candidate for a
PHPStan rule: forbid bc* calls on operands guarded only by is_numeric).

## 2. QuoteController ignores line discounts entirely (P1 — data-correctness on quotes)

Gate-confirmed pre-existing: `QuoteController::store()` (:196/:234/:351) computes totals with
bare `bcmul(qty, unit_price)` while PERSISTING the discount fields (:254-255/:375-376). A
quote's stored totals ignore its own line discounts, and quote→invoice conversion changes the
totals (invoice path applies the discount). The lane's new 422 boundary is currently the only
discount behaviour quotes have. Decide: make QuoteController route through
DocumentLine::computeLineTotal like other types (preferred), and check ConvertsDocuments for
which totals a conversion recomputes. Pre-launch relevance: quotes are in tenant #1 scope.

## 3. FE deps blind spot + LIVE pricing-popover staleness (P2) — FE gate m-4 + m-5, one fix

Confirmed entangled by the fix round (attempt made, reverted with reasoning inline at
`DocumentLineEditor.test.tsx:27-39`): the suite's fresh-closure `t` mock masks ALL missing
`lineColumns` useMemo deps, so (a) the `getDiscountMode` deps fix has no regression gate, and
(b) the PRE-EXISTING missing deps `openPricingLineId` / `pricingContext?.items` (present at
base `695f6814d`) are a LIVE staleness bug in the pricing popover — in production `t` IS
stable, so those cells don't re-render when pricing context changes. One fix, one pass:
stabilize the `t` mock to module scope AND add both missing deps; the two tests that go red
under a stable mock are the proof the deps were load-bearing. `react-hooks/exhaustive-deps`
is warn-level — consider promoting to error for this directory.

## 4. Persisted negative-line disposition (accountant list)

Live `demo-pharmacy-tn` carries at least one `-75.000` line (old MTP-DSC-04 tripwire
artifact). After the lane merges, recompute (incl. at confirm, into the hash chain) floors it
to zero → totals change. Run the lane's detection SQL pre-deploy per tenant; affected
documents join the accountant-disposition list (same class as the stranded 19.000 and the C-2
warehouse fiscal sale).
