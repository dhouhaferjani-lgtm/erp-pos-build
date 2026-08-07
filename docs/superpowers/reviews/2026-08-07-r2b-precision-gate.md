# GATE RECORD — R2-B (quote totals) — treasury/precision axis

**Round 1. Verdict: APPROVE-WITH-FIXES** — 0 Critical, 3 Important (1 blocking as a record-only merge condition), 4 minor.
**Branch:** `fix/r2b-quote-totals` @ `ca37ad51e` (2 commits on `264e6c483`, 2 files, +462/−3). Worktree left byte-clean after revert probes.

## 1. Precision contract — PASS
- No `(float)`, no `number_format`; every operation bcmath. Scale via constructor-injected `CurrencyScaleResolverInterface` (`QuoteController.php:57`, `scale()` `:61-64`); no-arg `getScale()` inside an HTTP controller is rule-19-permitted and pre-existing (see I-3 residual).
- Rounding once per line at currency scale inside `DocumentLine::computeLineTotal` (`DocumentLine.php:280-302`); no new hardcoded bcmath scale.
- `document_lines.line_total` is `decimal(15,3)` (widen migration `2026_03_11_200000` line 62); model cast `decimal:3` matches — no journal_lines-style drift.
- `lineDiscount()` reader byte-identical to siblings — awk-extracted bodies of Quote/Invoice(:88)/SalesOrder(:70) controllers all md5 `48f76b2004178fb0e0991719e8df26bf`.

## 2. Discount semantics — PASS (probed live, probes deleted)
`computeLineTotal` at `:225`/`:273`/`:398`+`:419` structurally identical to R2-P precedent (`SalesOrderController.php:212/256/379`). Edge shapes: percent=100 → zero floor holds; percent+amount → percent wins; amount==gross → 0; amount>gross → 422 `LineDiscountAmountWithinGross`; zero-qty unreachable (`gt:0`). `DocumentLineTaxResolver::resolve()` preserves discount keys.

## 3. Test non-vacuity — PASS, red claim independently reproduced
Controller reverted to base → 6/7 fail with exactly the claimed numbers (350 vs 300; line_total 200 vs 180; tax 60 vs 70 moved by confirm; quote-vs-order 60/70). The one green-at-base test is the internal-consistency guard as claimed. Head: OK 7/39. Money compares via `bccomp` (`:187-194`).

## 4. Blast radius — contained
`confirm()` (`:494-560`) and conversion untouched; their correctness now rides on net `line_total` — dependency real (`CopiesDocumentData.php:139` verbatim copy, `:289-307` header rebuild from the column).

## 5. Re-run of verification
QuoteDiscountTotalsTest 7/39 OK · ConversionChainVatIntegrity + DocumentConversionScenario + DiscountTolerance + DiscountPolicy 33/122 OK · Types/Quote* + Create/UpdateDocument 54/216 OK · PHPStan both files clean · Pint pass. Full suite never run.
Procedural note: one intermediate probe run produced base numbers, unreproducible ×4, on-disk controller verified fixed — stale-checkout artifact of the reviewer's own revert experiment.

## FINDINGS

**[I-1 · Important · MERGE CONDITION]** Already-persisted discount-blind quotes are not repaired and stay money-wrong through conversion. `confirm()` never writes `subtotal`/`line_total`; `CopiesDocumentData.php:139` copies verbatim and `:299-307` re-inflates. Only a `PATCH` re-sending `lines` heals a row. Remedy pattern already established (`2026-08-07-discount-lane-out-of-lane-findings.md` §4 detection-SQL + accountant-disposition); `AuditDiscountsCommand` covers `sales_order, invoice` only — quotes uncovered. **Fix before merge (record only):** per-tenant detection query + disposition line on the branch record / deploy checklist (SQL draft in the review transcript: quote lines where `line_total` exceeds discount-computed net, tolerance +0.0005).

**[I-2 · Important · ticket]** Residual draft-vs-confirm drift at sub-scale shapes. Measured TND `3×0.333@33.33% + 7×1.007−0.001 + 1.0001×0.001`: draft `7.716/1.465/9.181` → confirm total `9.180`. Root cause: controller computes at scale, `TaxCalculationService::calculateSubtotal` (`TaxCalculationService.php:331-334`) at scale+1-then-round, and `confirm()` never writes `subtotal`. Pre-existing, shared with invoice/order; this branch shrinks the drift 0.397 → 0.001. The lane's identity test can't see it (round-number payload).

**[I-3 · Important · pre-existing, separate lane]** Cross-currency quote computed at COMPANY scale then recomputed downstream at DOCUMENT scale. No-arg `getScale()` resolves from CompanyContext (`CurrencyScaleResolver.php:35-70`) while `currency` is client-settable (`CreateDocumentRequest.php:84`); downstream resolves `$document->currency`. Measured: TND quote under EUR/FR company → 2-dp truncation, confirm recomputes at 3 dp (9.160 → 9.179). One-line fix identical on Invoice/SalesOrder controllers — own lane.

**[m-1]** Chain test name overstates scope (never creates an invoice; forced status flip at `:401`). Rename or extend.
**[m-2]** Test comparison scale 2 (EUR) vs storage decimal(15,3) — 3rd-decimal regressions pass silently. Add TND case or compare at 3.
**[m-3]** FE/BE divergence on `discount_percent="0"` + `discount_amount>0` (`DocumentLineEditor.tsx:55-57` drops the amount; backend `DocumentLine.php:290-295` applies it). API-clients-only; pre-existing on invoice/order, now also on quotes. Note only.
**[m-4 · informational]** `store()` computes each line's net twice (`:225`/`:273`) vs `update()`'s single computation — mirrors the SalesOrder precedent exactly; future-divergence hazard only.

### Out-of-lane items — confirmed genuinely out-of-lane
- `DeliveryNoteController.php:256` persists discount fields, no `computeLineTotal` in file — same class, ticket-worthy, correctly deferred.
- `ReturnNoteController` — no discount handling at all; "silent drop" characterisation consistent.
- `confirm()` totalTax wholesale-consumption: **narrower than described** — the 2026-08-02 UNCONFIGURED ruling (`TaxCalculationService.php:245-265`) honours explicit line rates; TN seeder lists no quote token for DOCUMENT_TOTAL stamps (`TunisiaTaxConfigurationSeeder.php:92/101`). Ticket-worthy at lower severity.
