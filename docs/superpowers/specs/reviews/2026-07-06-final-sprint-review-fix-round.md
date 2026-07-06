# Finalization sprint (F1–F6) — dual adversarial review + fix round closure

**Scope:** commit `695ec56f2` (finalization sprint F1–F6 on `feat/p2p-entry-points`) + uncommitted fix round (this commit).
**Reviewers:** treasury-reviewer, inventory-costing-reviewer (adversarial, file:line-verified). Both initially **BLOCK**; both **APPROVED** after closure verification of the fix round.

## Round 1 findings → resolutions

### Treasury (T1–T5)
| # | Sev | Finding | Resolution |
|---|-----|---------|-----------|
| T1 | Important | `balance_due` never served by `SupplierInvoiceController::formatDetail()` → F1/F2 quick-payment prefill always defaulted to full gross total (overpayment → supplier advance on partially-paid SIs) | `show` now emits `balance_due` via `supplierInvoiceBalanceDue()` using the SAME source `PaymentController@store` caps on (`$doc->balance_due ?? $doc->total`, PG-trigger-maintained column), formatted `CurrencyScale::bcformatStrict`. Backend partial-payment test pins `400.000` |
| T2 | Minor | F1 allocation sent raw entered amount (no client cap) | `bccomp` cap at balance due, mirroring `buildSingleDocumentAllocation` |
| T3 | Minor | "Pay in Treasury" + record-payment buttons not permission-gated | Gated on `hasPermission('payments.create')`; FE map roles mirror backend `POST /payments` gate |
| T4 | Nit | Stale "confirm C4 ships" docblock | Removed |
| T5 | Nit | Hardcoded `min="0.001"` (scale-3 assumption) | Removed; MoneyInput derives from currency scale |

### Inventory-costing (I1–I5)
| # | Sev | Finding | Resolution |
|---|-----|---------|-----------|
| I1 | Important | Total-mode `deriveUnitPrice` ignored line discounts → double-applied discount, corrupted persisted net (feeds PO→GRN→WAC) | Discount-aware back-solve (percent: `net/(qty·(1−pct/100))`; absolute: `(net+amount)/qty`), all big.js strings, working scale 4, round-once at 3. Guards: pct=100, qty=0. Round-trip tests for both discount modes (red on old code) |
| I2 | Important | Draft GRN cards read `supplier_id`/`supplier_name`/`purchase_order_number`/`lines_count` — never served → "0 lines" + raw UUIDs | `GoodsReceiptData` DTO + index/detail responses now serve them (eager `with(['lines','purchaseOrder.partner'])->withCount('lines')`, no N+1); FE falls back to `lines.length`; types regenerated; tenantScope test pins fields. Hardened `partner?->name` (nullable `documents.partner_id`) |
| I3 | Minor | Posting a draft (irreversible stock+WAC) had no confirmation | `window.confirm` with stock/WAC warning key |
| I4 | Minor | Post success didn't invalidate `purchase-orders` namespace | Both namespaces invalidated |
| I5 | Minor | Global `isPending` disabled all rows | Scoped to acting receipt id (`mutation.variables === receipt.id`) |

## Closure verification (round 2)
- Treasury: T1–T5 CLOSED with file:line evidence; no new treasury defects; over-entry now yields explicit 422 (`SUPPLIER_PAYMENT_EXCEEDS_PAYABLE`), never silent overpayment. **APPROVE**
- Inventory: I1–I5 CLOSED; forward/back-solve algebraically inverse incl. percent-precedence consistency; no WAC/movement math touched. **APPROVE**

## Verification (fix round)
- vitest by path (5 files): **79/79** (orchestrator re-ran independently)
- `pnpm --filter @autoerp/web typecheck`: clean
- `SupplierInvoiceApiTest.php` by path: 52 passed / 306 assertions (incl. new balance_due partial-payment test)
- PHPStan (touched production PHP): clean; `typescript:transform`: 382 types regenerated
