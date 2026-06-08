# POS Charge-To-Account Checkout UI Spec

**Date:** 2026-06-04
**Branch:** `feat/pos-charge-to-account-checkout-ui` (off `dev`)
**Scope:** Wire the **only unbuilt piece** of the POS `ACCOUNT_CHARGE` feature — the cashier-facing checkout integration that makes `authorAccountCharge` reachable. Plus one correctness fix to existing account-charge test fixtures (tax-inclusive `unit_price`).

## 0. Status And Gates

The full `ACCOUNT_CHARGE` plumbing is already merged to `dev`:

- Device authoring service `apps/pos/src/lib/accountCharge/*` — `authorAccountCharge`, `buildAccountChargePayload`, `creditRulesEngine`, `accountChargePrintable`.
- `ACCOUNT_CHARGE` payload registry (TS + PHP), `StrictCanonicalParser`, `FiscalPayloadConstraintValidator`, `CanonicalPayloadReader::forAccountCharge`.
- POS-core `AccountChargeReceiptProjection`, `TreasuryAccountChargeBridge` (AR GL), `DocumentAccountChargeFactureBridge` (B2B), and their tests.
- Phase 4 override-approval authoring: `authorAccountCharge` authors `OPERATOR_APPROVAL_GRANTED` + `OVERRIDE_CREDIT_LIMIT` / `OVERRIDE_ACCOUNT_STATUS` internally when given an `overrideApproval` input, and `creditRulesEngine` consumes the resulting `override_evidence` (`approved_with_override`).

**Verified 2026-06-04:** there is **no caller** of `authorAccountCharge` in any store / UI / checkout code on any branch — the feature is unreachable from the POS. Because the event is device-authored only and nothing has ever called the authoring path, **zero production `ACCOUNT_CHARGE` events can exist**; correcting fixtures is safe.

Authoritative inputs:

- Phase 3 spec — `docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md`.
- Phase 3 plan — `docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md`.
- `unit_price` rename work — `feat/unit-price-rename-spec` (device cart is tax-INCLUSIVE).
- Project rules — `apps/erp/CLAUDE.md`.

## 1. Locked Architecture

`ACCOUNT_CHARGE` is whole-cart and non-collected: it authors **no payment lines** (`assertNoPaymentLines`), and the payload requires `totals.amount_charged_to_account === totals.total`. Therefore it is **mutually exclusive** with cash/card/voucher tenders and cannot be split in Phase 3.

The checkout integration must:

- Author through the existing `authorAccountCharge(db, input)` only. It must never route a charge through `ReceiptCreationService`, `/pos/receipts`, or any payment/tender path.
- Preserve offline-first synchronization: every sealed charge enqueues to the fiscal-event outbox and triggers sync. `authorAccountCharge` already calls `useSyncStore.getState().incrementPendingCount()` + `triggerSync()`; the new store action must not bypass or duplicate that.
- Not modify the fiscal payload contract, parser, validator, projections, or server bridges. Those are built and on `dev`.

## 2. Codebase Facts (integration surfaces)

- **Entry buttons** live in `PaymentSummary` (rendered by `TransactionCart`): `onPayCash` + `onAdvancedPayments`. `onAdvancedPayments` opens `AdvancedPaymentsModal` (the split-tender modal). `HomePage` owns the handlers and the `CheckoutSuccessModal`. `[apps/pos/src/pages/HomePage.tsx:1106-1133, 1177-1190; components/organisms/TransactionCart/TransactionCart.tsx:250-266]`
- **Attached customer** lives on `paymentStore.selectedCustomer: AttachedCheckoutCustomer | null`, set by `CustomerAttachPanel` via `attachCustomer`. `AttachedCheckoutCustomer` already carries `receivable_balance`, `credit_balance`, `credit_limit`, `payment_terms_days`, `charge_account_enabled`, `charge_policy_version`, `account_status`, `balance_updated_at`, `is_active`, `customer_sync_status`, `customer_category`, `tenant_id`, `company_id`. `[apps/pos/src/stores/paymentStore.ts:136-158]`
- **Sibling pattern**: `paymentStore.processAccountPayment` (money-in) gathers identity from `authStore` / `operatorStore` / `terminalStore`, builds the seller, calls the offline authoring service, sets `lastReceiptPrintData`, and updates the customer balance snapshot. `processAccountCharge` mirrors this shape. `[apps/pos/src/stores/paymentStore.ts:1091-1172; lib/offline/accountPaymentService.ts]`
- **Manager-PIN primitive**: `ManagerPinPanel` (`authorizedManagers`, `excludeUserId`, `onVerify`, `onSuccess(userId, name)`, `throttle`, `onThrottleUpdate`) is wired in `RefundConfirmModal` from `fetchAuthorizedManagers()` + `verifyManagerPin()` (`api/managerPinApi.ts`). `[components/pos/molecules/ManagerPinPanel.tsx; components/pos/RefundConfirmModal.tsx:245-251]`
- **Override authoring**: `authorAccountCharge` accepts `overrideApproval: AccountChargeOverrideApprovalInput | null` and authors the approval+override event chain itself for scopes `credit_limit_override` and `account_status_override`. `posOverrideAuthoring.ts` does **not** cover these scopes and is **not** used here. `[apps/pos/src/lib/accountCharge/accountChargeService.ts:54-64, 432-548]`
- **Printing**: `buildEscPosAccountPaymentReceiptData` in `lib/buildReceiptData.ts` converts an account-payment printable to `ReceiptData`. The charge path needs a sibling `buildEscPosAccountChargeReceiptData` consuming the existing `AccountChargePrintable`. After it is set as `lastReceiptPrintData`, `HomePage`'s existing print loader and `CheckoutSuccessModal` handle the rest. `[lib/buildReceiptData.ts; lib/offline/accountPaymentService.ts:12, 318]`
- **Tax-inclusive line contract** (CRITICAL): the SALE_RECEIPT line mapper writes `unit_price = item.unit_price` (gross / tax-inclusive, verbatim) and `line_subtotal = item.line_total − item.tax_amount` (net), `line_vat = item.tax_amount`. The charge assembler must mirror this exactly. `[lib/fiscal/payloads/SaleReceiptPayload.ts:155-181]`

## 3. Deliverables

1. **`processAccountCharge` store action** in `paymentStore` (mirror of `processAccountPayment`).
2. **Shared cart→line mapper** producing `AccountChargeLineInput[]` + `AccountChargeVatBreakdownInput[]` from `cartItems`, reusing the exact tax-inclusive formulas from the SALE_RECEIPT mapper, with a parity test.
3. **"On Account" entry inside `AdvancedPaymentsModal`** — a synthetic method, surfaced only when eligible, that switches the modal into account-charge mode.
4. **`AccountChargeConfirmation`** sub-view — the credit-decision UI (balance impact + decision + manager-PIN override on overridable rejections).
5. **`buildEscPosAccountChargeReceiptData`** in `buildReceiptData.ts`.
6. **HomePage wiring** — `onChargeToAccount` handler + success/print flow.
7. **Fixture correction** — account-charge fixtures to inclusive `unit_price`, regenerate golden canonical bytes (TS + PHP), keep parity green.
8. **i18n** keys (en + fr) for all new user-facing strings.

Out of scope: payload/parser/projection/bridge changes; any server-side work; partial (split) charge-to-account; new override *scope* primitives; web-POS parity.

## 4. Entry Point — "On Account" Inside AdvancedPaymentsModal

- Surface a synthetic **"On Account"** method in the modal's method list. It is shown **only when**: `selectedCustomer !== null` AND `selectedCustomer.charge_account_enabled` is truthy AND `total > 0` (not refund/net mode). Otherwise the option is absent (not a dead/disabled button).
- Selecting it puts the modal in **account-charge mode**: the split-tender controls (amount numpad, add-tender, other method buttons, payment-line list) are replaced by `AccountChargeConfirmation`. The charge amount is the full `total`; it cannot be combined with other tenders.
- The modal **keeps its fixed dimensions** — mode swaps content, never resizes (per the modal-sizing rule).
- The modal's Complete action, in account-charge mode, calls a new `onChargeToAccount(overrideApproval?: AccountChargeOverrideApprovalInput | null)` prop instead of `onComplete(payments)`. Deselecting / switching back to a tender restores normal split mode.

## 5. AccountChargeConfirmation (Credit-Decision UI)

Runs `evaluateAccountChargeCreditDecision` against the attached customer and the full cart `total` (charge amount = `total`), using the same staleness computation as `CustomerAttachPanel` (`isBalanceStale`) and `hard_stale_after_minutes` default 240.

Always displays:

- Customer name + account identifier/phone.
- Charge amount, currency.
- Current vs projected receivable balance.
- Credit limit and remaining available credit before/after.
- Due date / payment terms (when configured).
- Staleness warning if the snapshot is stale.

Decision handling:

- **`ok` (`approved`)** → Confirm enabled; Complete seals.
- **`ok` (`approved_with_override`)** → only reachable after a manager PIN has been captured and a matching `overrideApproval` is held; Complete seals an `approved_with_override` charge.
- **Rejection — overridable** (`credit_limit_exceeded` → scope `credit_limit_override`; `account_suspended` / `account_disputed` → scope `account_status_override`): show inline `ManagerPinPanel` (wired like `RefundConfirmModal`: `authorizedManagers` from `fetchAuthorizedManagers()`, `excludeUserId` = cashier, `onVerify` = `verifyManagerPin`, local throttle state). On PIN success, capture `{ supervisorUserId, supervisorUserSnapshot:{name,roles}, ... }` and build `AccountChargeOverrideApprovalInput { approvalId: crypto.randomUUID(), approvalScope, cashierUserId, reasonCode, reasonText, requestedAtDevice, resolvedAtDevice, supervisorUserId, supervisorUserSnapshot }`. Re-evaluate / enable Complete.
- **Rejection — hard** (`account_closed`, `customer_inactive`, `charge_account_disabled`, `charge_policy_missing`, `balance_snapshot_missing`, `balance_snapshot_invalid`, `balance_snapshot_hard_stale`, `customer_alias_ambiguous`, `customer_tenant_mismatch`, `customer_company_mismatch`, `money_scale_invalid`): block with a typed, localized message. No override.

All decision/rejection strings use `t()` keys.

## 6. processAccountCharge Store Action

Mirrors `processAccountPayment`. Signature (illustrative):

```ts
processAccountCharge(
  terminalId: string,
  options?: { overrideApproval?: AccountChargeOverrideApprovalInput | null },
): Promise<AccountChargeResult | null>
```

Sequence:

1. Read `selectedCustomer`; throw a typed error if null or not `charge_account_enabled`.
2. Gather identity exactly like the SALE_RECEIPT / account-payment path: `authStore` (tenantId, companyId, currency, company seller block), operator from `operatorStore` → `authStore` fallback, `terminalStore` (terminal id/name, shift id, `is_training_mode`). `db = getDatabase(companyId)`.
3. Assemble `lines` + `vatBreakdown` + `subtotal` / `vatTotal` / `total` / `transactionDiscountAmount` / `transactionDiscountReason` from `cartStore` via the shared mapper (§7).
4. Compute `balanceSnapshotStale` via `isBalanceStale`.
5. Call `authorAccountCharge(db, input)` with `overrideApproval` passed through. `assertNoPaymentLines` is enforced inside; the action passes **no** `payments`.
6. On success: build `ReceiptData` via `buildEscPosAccountChargeReceiptData(result.printable)`, set `lastReceiptPrintData`, update `selectedCustomer` receivable snapshot to the projected value, and clear the cart. Do **not** re-trigger sync (the service already did).
7. Return the `AccountChargeResult`.

Failure: any thrown error (credit rejection, missing identity) leaves cart and customer intact, surfaces a localized message, and authors **no** fiscal event.

## 7. Shared Cart→Line Mapper

A single function maps `CartItem[]` → `AccountChargeLineInput[]` and `vatBreakdown`, reusing the SALE_RECEIPT formulas verbatim:

- `unitPrice = bcformat(item.unit_price, scale)` — **gross / tax-inclusive**, written verbatim.
- `lineSubtotal = bcformat(item.line_total − item.tax_amount, scale)` — net.
- `lineVat = bcformat(item.tax_amount, scale)`.
- `quantity` at scale 3, `vatRate` at scale 2.
- Line discount reason required iff `lineDiscountAmount > 0` (same invariant as SALE_RECEIPT).
- `vatBreakdown` grouped by `(vat_rate, tax_category_code)` summing net/vat/gross, mirroring `buildVatBreakdown`.

A parity test asserts the mapper output matches the SALE_RECEIPT line mapping for the same cart, so the inclusive-`unit_price` contract cannot silently drift.

## 8. Printing

`buildEscPosAccountChargeReceiptData(printable: AccountChargePrintable): ReceiptData` in `lib/buildReceiptData.ts`, sibling of `buildEscPosAccountPaymentReceiptData`. Renders title `ACCOUNT CHARGE RECEIPT`, seller, customer, line items, VAT breakdown, totals, amount charged to account, previous/charge/projected balance, credit limit + remaining, due date / terms, staleness + training markers. Set as `lastReceiptPrintData`; `HomePage` + `CheckoutSuccessModal` print it through the existing path.

## 9. Fixture Correction

Pre-gated by the §0 safety check (already verified: no caller → no production events).

- Correct every account-charge fixture (TS golden `goldenAccountChargePayload`, PHP golden fixtures, any validator/parser/projection fixtures that hardcode a line) so `line_items[].unit_price` is the **gross/inclusive** price and `line_subtotal` stays net — e.g. one unit at 19% VAT on 100 net becomes `unit_price: '119.000'`, `line_subtotal: '100.000'`, `line_vat: '19.000'`, `quantity: '1.000'`.
- Regenerate the golden canonical bytes on **both** TS and PHP so byte parity holds.
- Confirm no validator/parser invariant couples `unit_price` to `line_subtotal` (SALE_RECEIPT carries inclusive `unit_price` with net `line_subtotal`, proving it does not). If any such coupling exists, stop and surface it.

## 10. Tests And Gates

- Shared mapper: inclusive-`unit_price` / net-`line_subtotal`; parity with SALE_RECEIPT mapping; discount-reason invariant.
- `processAccountCharge`: happy path (asserts `authorAccountCharge` called with correct input, **no** `payments`, `lastReceiptPrintData` set, cart cleared, balance snapshot updated); hard rejection (no fiscal event, cart intact, localized error); override path (passes `overrideApproval`, seals `approved_with_override`); offline-sync (relies on service's `incrementPendingCount`/`triggerSync`, no double-trigger).
- `AdvancedPaymentsModal`: "On Account" present only when eligible (`selectedCustomer` + `charge_account_enabled` + `total > 0`); absent otherwise; selecting it shows `AccountChargeConfirmation`; Complete calls `onChargeToAccount`; switching back restores split mode; fixed dimensions preserved.
- `AccountChargeConfirmation`: renders balance impact; approved → Complete enabled; overridable rejection → `ManagerPinPanel` shown, PIN success builds `overrideApproval`; hard rejection → blocked with message.
- `buildEscPosAccountChargeReceiptData`: renders required fields.
- Corrected fixtures keep `accountChargeCanonicalParity` (TS) and PHP canonical/parser tests green.
- i18n: no hardcoded strings; en + fr keys present.

Gates per task:

```bash
cd apps/pos
pnpm test
pnpm typecheck
pnpm lint

cd apps/api   # only when fixtures / PHP goldens change
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpunit tests/Unit/Fiscal/ tests/Feature/Fiscal/
./vendor/bin/pint --test <touched php>
```

Plus the existing chokepoint sentinel: `bash apps/api/scripts/check-accountCharge-chokepoints.sh` must stay green (charge authored only through `FiscalEventEngine.append`, never receipt/payment routes).
