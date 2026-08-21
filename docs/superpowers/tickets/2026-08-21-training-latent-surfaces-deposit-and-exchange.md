# Training containment — two latent surfaces recorded, neither fixed

- **Opened:** 2026-08-21
- **Severity:** P3 (both latent, neither live)
- **Status:** RECORDED — no action required now; re-check before enabling training authoring
- **Raised by:** LEDGER gate G-3 (`fix/g3-training-receipt-containment`)

These are the two surfaces the G-3 census examined and deliberately left alone,
recorded so a future training-enablement wave does not have to rediscover them.

---

## (a) `TreasuryDepositBridge` — ungated, but a training deposit is unauthorable

**Finding.** The bridge has zero training discrimination
(`grep -i training TreasuryDepositBridge.php` → no hits; same for
`DepositReceiptProjection.php` and `RecordCustomerDepositService.php`), yet the
payload DTO carries the flag: `App\Modules\Fiscal\Domain\DTOs\DepositReceiptPayload`
declares `'training_flag'` in `PAYLOAD_KEYS` (`:40`), the property at `:64`,
hydration at `:102`, re-emission at `:132`; `DepositReceiptView:9-15` exposes it.

**Why it is not live.**

- `DEPOSIT_RECEIPT` is server-only — `FiscalEventType::isServerOnly()`
  `:74-82` lists it at `:80`. Device ingress is therefore closed:
  `OutboxIngestor.php:155-157` rejects server-only types outright.
- The sole server author, `VirtualAdminFiscalEventService`, **hardcodes**
  `'training_flag' => false` at `:270`. No parameter, config or request field
  feeds that key — `RecordCustomerDepositService.php` and
  `RecordDepositRequest.php` contain no training reference at all.
- The validator does honour the flag (`FiscalPayloadConstraintValidator:606`
  passes it into `validateDepositReceiptPayment`, where it relaxes the
  `amount > 0` rule), so a `true` value would *validate* — it simply cannot be
  *produced*.

**Exposure.** Latent, not live. If any future deposit author threads the flag
through, it meets a completely ungated bridge: FIFO allocation, `Payment` row,
treasury movement, GL. **Add this bridge to the checklist of any wave that makes
training deposits authorable.**

---

## (b) `ExchangeService` → `ReceiptPaymentService` — training-blind but dead

**Finding.** `ExchangeService` constructor-injects `ReceiptPaymentService`
(`ExchangeService.php:76`) and calls `processReceiptPayments()` at `:264-268`,
inside `executeExchange()` `:197`, guarded by a net-positive check at `:255`.
`ReceiptPaymentService::processReceiptPayments()` has **no training
discrimination whatsoever** (`grep -c -i training` = 0 for the file; its
signature carries no flag and it never reads `is_training`).

**Reachability verdict: DEAD, not merely gated.** Verified by exhaustive sweep of
`apps/api`:

- `grep -rn "processExchange\|ExchangeRequestInput" app/` → hits only in
  `ExchangeService.php` itself, its two DTOs, and docblock mentions in
  `ReceiptCreationService.php:113` / `ReceiptFinalizationService.php:56`. **No
  controller, job or command calls it.**
- `grep -rn "ExchangeService\|processExchange" routes/ bootstrap/ config/ database/`
  → no matches.
- No `*Exchange*Controller` exists; no service-provider binding. It is resolved
  only by container auto-wiring inside `tests/Feature/POS/ExchangeServiceTest.php`.
- The HTTP surface is sealed on purpose: `RefundDestination::ExchangeDeferred` is
  excluded from `StoreReturnRequest.php:65-75` — "a service-layer value reserved
  for ExchangeService and must never be reachable over HTTP".
- This matches the codebase's own written disposition — `ExchangeService.php:59-70`
  and `scripts/saleReceipt-chokepoint-manifest.json:31-41, :53-60`
  (`"disposition": "b", "live": false, "retired_in_task": "Task 30-followup"`),
  CI-enforced by `scripts/check-saleReceipt-chokepoints.sh` and
  `tests/Feature/Fiscal/ChokepointCompletenessTest.php:198-224`.

The other caller, `ReceiptController::storePayments()` `:842-847`, sits behind a
route that returns **410 Gone** (`app/Modules/POS/routes.php:211-222`, code
`NEW_SALE_AUTHORING_RETIRED`). So `processReceiptPayments()` has **zero live
callers**.

**Circularity worth flagging.** `ReceiptPaymentService` is still classified as
production code *because of* this injection —
`tests/Feature/POS/StoreReceiptPaymentsTenantIsolationTest.php:84-86` states it
is "still production code reachable via `ExchangeService::processExchange`". That
justification is circular now that `processExchange` itself is proven
unreachable. Recommend the Task-30-followup cleanup resolve both together rather
than leaving a training-blind payment writer alive on a dead-code technicality.

**Action:** none now. Do not add a training guard to a dead path; retire the path.

---

## Census — pre-enable verification (money side)

**This is the artifact `TreasuryReceiptBridge`'s docblock points at.** The owner
runs these **per tenant database** at deploy, before training authoring is
enabled anywhere.

**Expected result: zero on every query.** `FiscalEventEngine` defaults
`chain_context` to `'operational'` (`FiscalEventEngine.ts:565`) and throws on a
`training_flag` outside a `training_*` context (`:815-818`); `SALE_RECEIPT` and
`ACCOUNT_PAYMENT` both sit in `OPERATIONAL_CHAIN_EVENT_TYPES` (`:219-220`) and
no producer passes a training context. So no training event should ever have
been sealed. **Non-zero anywhere means an event reached the projectors by a path
this analysis did not model — stop and investigate before enabling.**

Filters key on the **SEALED payload flag**, not the mutable
`pos_receipts.is_training` mirror, per the same discipline the guards themselves
follow. The `is_training` variants are kept commented as a cross-check: a
divergence between the two is itself a finding.

```sql
-- 1. Treasury payments created from a training fiscal event.
SELECT count(*) AS training_payments
FROM payments p
JOIN fiscal_events fe ON fe.id = p.fiscal_event_id
WHERE (fe.payload->>'training_flag')::boolean = true;
-- cross-check: ... JOIN pos_receipts r ON r.fiscal_event_id = p.fiscal_event_id WHERE r.is_training = true;

-- 2. POS/GL entries posted for a training receipt.
SELECT count(*) AS training_journal_entries
FROM journal_entries je
JOIN pos_receipts r ON r.id = je.source_id
JOIN fiscal_events fe ON fe.id = r.fiscal_event_id
WHERE je.source_type IN (
        'pos_receipt', 'pos_receipt_refund',
        'pos_cash_rounding', 'pos_cash_rounding_refund',
        'pos_tolerance_bridge'
      )
  AND (fe.payload->>'training_flag')::boolean = true;
-- cross-check: drop the fiscal_events join and use r.is_training = true.

-- 3. Drawer movements recorded from a training fiscal event
--    (covers SALE_RECEIPT and ACCOUNT_PAYMENT — both use sourceType 'fiscal_event').
SELECT count(*) AS training_repository_movements
FROM repository_movements rm
JOIN fiscal_events fe ON fe.id = rm.source_id
WHERE rm.source_type = 'fiscal_event'
  AND (fe.payload->>'training_flag')::boolean = true;

-- 4. Vouchers burned by a training receipt.
SELECT count(*) AS training_voucher_redemptions
FROM voucher_ledger vl
JOIN pos_receipts r ON r.id = vl.receipt_id
JOIN fiscal_events fe ON fe.id = r.fiscal_event_id
WHERE vl.event = 'redeemed'
  AND (fe.payload->>'training_flag')::boolean = true;
-- cross-check: ... WHERE vl.event = 'redeemed' AND r.is_training = true;
```

The **stock-side** census (not covered by G-3) lives in
`2026-08-21-training-stock-movement-gap.md`.
