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
