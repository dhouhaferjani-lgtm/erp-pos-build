# Pre-dispatch adversarial review — CODEX-uom-baseline-burndown-2026-07-22.md

Two Opus lenses on the dispatch brief (per the every-milestone rule), 2026-07-22. Both verdicts **APPROVE-WITH-FIXES**; all fixes applied to the brief the same day, before dispatch.

## fiscal-pos lens — APPROVE-WITH-FIXES (7 findings, all applied)
- **IMPORTANT — `ZReportSyncController.php:424` misfiled by the ticket as quantity; it is MONEY feeding a stored event**: `$varianceRaw ?? '0.0000'` → `new VarianceAmount(amount, $currencyCode)` → event-sourced `CashCountRecorded` → fraud-alert `variance_amount`. Brief now prescribes rule-19 per-currency scale, hoisting `$currencyCode` (computed at `:431`, after the literal), zero-value only, no event-shape change (rule 8).
- **IMPORTANT — Part B fence "display-default literals only" was false**: per-site disposition added (response-serialization `ReceiptController:564,597,612` / stored-write `PosPendingCustomerController:84-86` on `Partner::create` + `StandaloneReceiptController:83` / event-payload `ZReportSync:424`).
- **IMPORTANT — pending-customer literals are stored writes**: prescribe `getScaleSafe($company->currency, 3)`, zero balance invariant (outside fiscal chain — safe).
- MINOR: ticket's route path wrong — actual `apps/api/app/Modules/POS/routes.php:103/:104`; `git log -S` proof for shipped builds; 404 soft-fail note; `syncCloseShift` stays. Multiloc overlap files named in the coordination fence. POS `String(item.quantity)` never parseFloat; signed-historical-receipt scale-4 fallback accepted.
- Positives verified: POS product sync populates `quantity_decimals` (`productRepository.ts:201,207,227`); no new POS migration; `sync/pull`+`sync/menu` zero callers across pos/web/mobile; the 3 device sites display-only.

## frontend-conventions lens — APPROVE-WITH-FIXES (1 BLOCKER, 2 MAJOR, 3 MINOR, all applied)
- **BLOCKER — 3 baseline entries are editable-input `value=` props, not display renders**: `DocumentLineEditor.tsx:575` + `CreateStockTransferPage.tsx:738` (`QuantityCell` — already correct; remedy = extend the scanner's exempt-wrapper set to `QuantityCell` with commit-message justification, the ONE authorized scanner edit) and `BundleComponentFormModal.tsx:372` (raw `<Input>` → migrate to `QuantityInput`). Wrapping these in `formatQuantity` breaks controlled inputs.
- **MAJOR — deprecated `lib/format` formatQuantity is ~13 baselined files, not 2**: fix = import-swap + `getQuantityDecimals` arg, never bare (trim→fixed-4 silently changes rendering); conservation rule for shared imports (`StockMovementsPage` `:248/:258/:265` all get decimals).
- **MAJOR — `DiscountBreakdownChart` is a remedy dead-end** (`analyticsApi.ts:56` name-grained aggregate, no product_id/resource): pre-authorized regroup-on-product_id + UoM-join remedy, STOP-adjudicate if semantics shift; forbids gaming the scanner with default scale-4.
- MINOR: amortization wording softened (several resource touches, 6 doc types); wave counts corrected ~26/~16; pnpm invocation normalized.
- Positives verified: every contract-table citation confirmed at file:line; scanner genuinely fails on stale entries (`audit-quantity-display.mjs:437-445`); baseline exactly 42; phpstan ratchet exactly 9; Task 0 drift exactly the 3 named permission-gated routes.
