# R2-P purchasing lane residuals (2026-08-07, non-blocking)

1. **priceStatus read-model not bonus-aware** (fix-round find, documented inline in
   MTP-PUR-16): `SupplierInvoiceController::buildMatchBlock` → `SupplierInvoiceMatcher::priceStatus()`
   reports spurious `price_variance: true` on every bonus line in the DISPLAY match block —
   does not affect match_status or postability (the authoritative match()/assertPostable()
   path is bonus-aware). Align the read model.
2. **Invoice-first bonus refusal** (B2 ruling record): is_bonus_line + invoice_first is now
   a 422 boundary refusal — supporting bonus on invoice-first would need a per-line
   paid/free split contract that doesn't exist. If TN pharmacies ever need invoice-first
   bonus flows, that's a spec'd feature, not a fix.

3. **(bonus re-gate (a))** `matchable` at SupplierInvoiceController:611 is ALSO not
   bonus-aware, alongside price_variance :614 — false variance chip on every bonus line in
   the exact TN-pharmacy flow this unlocks. Not backlog-forever; align both read-model
   fields with the matcher's bonus-skip.
4. **(bonus re-gate (b), minor)** gate-off + bonus + nonzero price returns BOTH the
   prohibited and must-be-zero messages — cosmetic, but discloses the bonus feature to
   ungated tenants.
5. **(bonus re-gate (c), minor)** the pending_receipt arm of the invoice-first refusal is
   code-covered but test-uncovered.
