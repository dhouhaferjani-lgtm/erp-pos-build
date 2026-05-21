# Executive Summary

Request changes. v4 closes the old-shape grep/test contradiction and the Tunisia regex gate cleanly, and the mutex primitive is implementable. Two areas still are not dispatch-ready: the VAT/discount section now contains a validator-level invoice-discount invariant that is underspecified and internally contradictory, and the NF525 line-level refactor still does not quite specify how every line/payment/VAT mapper bifurcates between canonical DTOs and legacy Eloquent rows. These are fixable, but Pass 2A should not dispatch with the current v4 text.

# Round-3 Closure Verification

| Finding | Verdict | Evidence |
|---|---|---|
| N-11 Pass 2A old-shape test contradiction with grep gate | CLOSED | v4 retracts the temporary integration-test idea in §0 and says no such test exists in either pass (`docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v4.md:16`). §8.A scopes the grep gate to `apps/api/tests/Feature/Fiscal/*.php`, `apps/api/tests/Unit/Fiscal/*.php`, and `apps/pos/src/lib/fiscal/__tests__/*.ts` (`:174-178`), explicitly excludes `receiptService.test.ts` and `paymentStore*.test.ts` from Pass 2A (`:190-192`), and moves their rewrite to Pass 2B (`:229-235`). I found no remaining v4 reference to a temporary old-shape integration test. |
| N-14 TN regex placeholder unenforceable | CLOSED | v4 replaces per-country regex enforcement with the universal `^[A-Za-z0-9 \-/.]{4,40}$` plus non-empty/control-char/trim checks (`:150-158`). Strict per-country validation is deferred to a named post-Pass-2 roadmap task (`:160-164`, `:221-227`), §11 points to universal validation only (`:265-270`), and §17 explicitly says TN regex confirmation no longer blocks Pass 2A (`:375-379`). |
| N-16 VAT partition rule not implementable enough | PARTIALLY-CLOSED | The partition algorithm is now concrete and uses BCMath primitives: group by `(vat_rate, tax_category_code)`, sum net/VAT with BCMath at `currency_scale`, derive gross with `bcadd`, and compare with `bccomp` (`:83-123`). The forensic prefixes are explicit (`:105-122`), and the seven negative tests cover duplicate/missing/extra partitions, one-cent drift, mixed 0% categories, scale, and total arithmetic (`:134-142`). What remains unclear is invoice-discount semantics: §6 says the validator does not enforce a relationship between `transaction_discount_amount` and `total` (`:131`) and then immediately mandates `subtotal + vat_total == total + transaction_discount_amount` (`:132`). The money-scale invariant also says it covers every bcformat field but only names `line_items[].*amount`, omitting `unit_price`, `line_subtotal`, and `line_vat` while the negative test expects `unit_price` to be checked (`:120-121`, `:141`). See N-19 and N-24. |
| N-12 Nf525DataProvider refactor scope | PARTIALLY-CLOSED | v4 now names the required top-level bifurcation for `mapSaleReceipt` by `$receipt->fiscal_event_id IS NOT NULL`, with `CanonicalPayloadReader::forSaleReceipt($event)` as the fiscal-event-backed source and legacy fallback for `IS NULL` (`:200-208`). It also names `mapVoidedReceipt`, `mapReturnReceipt`, `mapLine`, `mapPayment`, and `mapVatDetail`, plus tests for canonical-only `gtin`, `tax_category_code`, foreign currency, and `original_receipt_reference` (`:208-219`), and the LOC estimate is bumped from 3-4K to 4-5K for this complexity (`:357-360`). The remaining gap is that only the receipt-level methods are explicitly bifurcated by `fiscal_event_id`; the line/payment/VAT bullets say "extract from CanonicalPayloadReader" for fiscal-event-backed data but do not specify the method signatures or branching needed for legacy Eloquent rows vs canonical DTO rows (`:209-211`). |
| N-15 Concurrent-receipt mutex primitive | CLOSED | v4 specifies a per-`tenantId:terminalId` in-process Promise queue (`:276-291`, `:318-320`). The code uses `prev.then(fn, fn)` so `fn` runs after either prior success or prior failure, stores `next.catch(() => {})` so the queue itself does not remain rejected, and returns `next` so caller errors still propagate (`:281-290`). The retry loop uses linear `[50, 100, 200][attempts - 1]` backoff, caps after three retries with `FiscalChainContentionError`, and preserves the same `source_event_id` across retries (`:299-323`). T-1 through T-6 cover same-terminal serialization, pre-tx validation release, rollback release, retry success/exhaustion, and different-terminal concurrency (`:325-331`). The listing is valid TypeScript; the array index is safe for attempts 1, 2, and 3. |

# New Defects

## P1

### N-19 Invoice-Level Discount Semantics Are Still Not Contract-Safe

Severity: P1.

Description: §6 turns invoice-level discount handling into a validator invariant but does not define the business semantics tightly enough. The money regex allows negative values (`^-?`) for `transaction_discount_amount` (`docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v4.md:119-122`), while the text describes the field only as an invoice-level discount, not a surcharge or signed adjustment (`:127-132`). v3 says `"0" when none` and `transaction_discount_reason: string | null` (`docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v3.md:97-98`), but v4 never says whether zero must pair with `null` reason or whether a non-zero discount requires a reason. More importantly, v4 asserts one cross-regime convention: invoice-level discounts affect the effective total while VAT breakdown rows remain per-line-discount-only (`docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v4.md:127-132`). That may be a valid internal convention, but v4 cites it as "French + Tunisian + DE practice" without a per-regime fixture or source in the external research; the external research only establishes the need to carry transaction discount fields, not this VAT allocation rule (`docs/superpowers/research/2026-05-20-multicountry-fiscal-research.md:26-28`, `:163-169`). The current wording also contradicts itself by saying the validator does not enforce a relationship between discount and total, then requiring `subtotal + vat_total == total + transaction_discount_amount` (`docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v4.md:131-132`).

Fix: Decide the canonical meaning before Pass 2A. Either make `transaction_discount_amount` a non-negative post-tax/payment-level commercial discount and require `reason === null` iff amount is zero, or model signed adjustments explicitly with an adjustment type/reason. Add FR and TN fixtures proving the chosen convention, and rewrite the contradictory sentence so the arithmetic cross-check is stated as a deliberate invariant.

## P2

### N-23 Pass 2A Safety Depends On A Commit Message

Severity: P2.

Description: v4 knowingly leaves a window where Pass 2A server tests and fixtures use the 27-key contract while `receiptService.ts` still emits the 10-key shape (`docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v4.md:194-198`). The only stated mitigation is a required commit message warning that Pass 2B must land before any end-to-end checkout exercises the new contract (`:198`, `:235`). CI and branch protection do not read commit messages. A developer can merge or test an end-to-end path between Pass 2A and Pass 2B and hit a known contract break despite all Pass 2A checks passing.

Fix: Add an enforceable mechanism. Preferred: land Pass 2A and Pass 2B as a protected stacked pair with branch protection preventing unrelated merges between them. If they must be separate PRs, add a temporary CI sentinel that fails PRs touching `apps/pos/src/lib/offline/receiptService.ts`, `apps/pos/src/lib/offline/**`, or `apps/pos/src/stores/**` while the Pass 2A sentinel is present, except for the Pass 2B removal commit.

### N-24 Scale Invariant Omits Fields Its Own Test Claims To Check

Severity: P2.

Description: §6 says the scale invariant applies to "every bcformat string field" but the enumerated field list only includes top-level totals, `transaction_discount_amount`, `line_items[].*amount`, `vat_breakdown[].*amount`, `payments[].amount`, and voucher redeemed amounts (`docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v4.md:119-122`). Candidate C-v3 has additional decimal-string fields that must be scale checked, including `line_items[].unit_price`, `line_items[].line_subtotal`, `line_items[].line_vat`, and VAT rate fields (`docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v3.md:55-66`, `:99-104`). v4's own negative test expects `line_items[i].unit_price="10.000"` to fail when `currency_scale=2`, but `unit_price` is not covered by the written invariant (`docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v4.md:141`).

Fix: Replace the wildcard wording with an explicit field table: money fields checked at `currency_scale`, VAT rate fields checked at the chosen VAT-rate scale, and quantity checked at its own quantity scale or documented decimal policy. Include `unit_price`, `line_subtotal`, `line_vat`, `line_discount_amount`, `vat_breakdown.{net_amount,vat_amount,gross_amount}`, `subtotal`, `vat_total`, `total`, `transaction_discount_amount`, `payments[].amount`, `payments[].foreign_currency_amount`, and `vouchers_redeemed[].redeemed_amount`.

# Investigated But Not Findings

### N-20 Grep Gate Old-Shape Regex Is Shape-Unique

CONFIRM-OK. The 27-key v4 list uses `transaction_discount_amount` and `payments`, not `discount_total` or `payment_lines` (`docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v4.md:56-64`). The old-shape gate is explicitly keyed on `discount_total` and `payment_lines` (`:174-178`), so it is unique to the old 10-key shape. The newly-created `FiscalPayloadConstraintValidatorTest.php` is in the scoped path and is required to assert the 27-key shape (`:180-187`), so it should not contain the full old-shape signature unless the Pass 2A implementation violates v4.

### N-21 `.then(fn, fn)` Mutex Semantics Are Correct

CONFIRM-OK. In JavaScript, passing `fn` as both `onFulfilled` and `onRejected` means the next critical section starts after the previous queued promise settles either way; extra rejection arguments are ignored by a zero-argument `fn`. Because v4 stores `next.catch(() => {})` in the queue but returns `next`, the queue remains usable after a failure while the caller still observes the original error (`docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v4.md:281-290`). This is not a defect.

### N-22 Pre-Commit Gate Covers New In-Scope Files

CONFIRM-OK. v4 defines the assertion across "the IN-SCOPE files" (`docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v4.md:174-178`) and explicitly creates `apps/api/tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php` inside that scope (`:180-187`). A normal pre-commit/pre-push grep over the path globs will include files created in the same commit. No fix needed, beyond writing the hook/command against the working tree or staged file list rather than only `git diff --name-only HEAD`.

# Verdict

Request changes. v4 is close, but Pass 2A should not dispatch until §6's invoice-discount and scale invariants are tightened and §8.B makes the canonical-vs-legacy NF525 mapper bifurcation precise enough to implement without guesswork.

VERDICT: REQUEST-CHANGES
