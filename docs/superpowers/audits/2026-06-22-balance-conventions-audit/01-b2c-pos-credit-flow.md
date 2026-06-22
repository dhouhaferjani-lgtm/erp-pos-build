# B2C / POS Customer Credit & Charge-to-Account Balance Flow — End-to-End Audit

**Date:** 2026-06-22
**Scope:** Read-only audit of the B2C / POS customer credit/debit balance flow across the POS device (`apps/pos`), the fiscal-event server boundary, the customer mirror sync, and the balance-display layer.
**Locked decision being aligned against:** `partners.credit_balance` is standardized as a **NON-NEGATIVE MAGNITUDE** ("credit the customer holds"). `net = receivable − credit`. The immutable fiscal payload enforces non-negative money via `FiscalPayloadConstraintValidator::moneyRegex`. The single buggy writer (`PartnerBalanceService::refreshPartnerBalance`) currently stores it negative.

All file paths are absolute. Citations are `file:line`.

---

## 0. Executive Summary

The device side (POS) and the immutable fiscal payload contract are **internally consistent and already match the locked positive-magnitude convention**:
- Device authoring (`accountChargeService.ts`, `accountPaymentService.ts`, `creditRulesEngine.ts`) treats `credit_balance` as a non-negative magnitude and computes `net = receivable − credit`.
- The fiscal payload validator's `moneyRegex` only accepts non-negative decimal strings, so the immutable canonical bytes **cannot** carry a negative `credit_balance`/`net`.
- The display layer (`CustomerBalanceBadge.tsx`) renders `net = receivable − credit` floored at zero.

The **server cache is the single source of the sign defect**. `PartnerBalanceService::refreshPartnerBalance` is the sole writer of `partners.credit_balance` for both the charge and payment flows, and it stores the value as `SUM(debit) − SUM(credit)` of the credit-nature `CustomerAdvance` account, which is **negative**. That negative value is then served verbatim to the device mirror via `PosCustomerMirrorResource`.

**Net effect of the bug (when CustomerAdvance entries are posted):**
- Server displays / API consumers using `Partner::net_balance` get `receivable − (negative credit)` = `receivable + |credit|` — the customer's credit **inflates** their apparent debt instead of reducing it.
- The device mirror receives a negative `credit_balance` string (e.g. `"-50.0000"`). On the next ACCOUNT_CHARGE, the device feeds it to `creditRulesEngine.parseMinorUnits`, whose regex requires a non-negative form, so it **hard-rejects** the charge with `money_scale_invalid:credit_balance` — a functional break, not just a cosmetic display error.

---

## 1. Sign / Units Convention — actual, per layer

| Layer | File:line | `credit_balance` sign | `net` formula | Matches locked convention? |
|---|---|---|---|---|
| Device — credit rules engine | `apps/pos/src/lib/accountCharge/creditRulesEngine.ts:208-225` | non-negative magnitude (regex-enforced) | `net = max(receivable − credit, 0)`; `credit_available = credit_limit − net` | **Yes** |
| Device — charge authoring snapshot | `apps/pos/src/lib/accountCharge/accountChargeService.ts:182-199` | non-negative | `netBefore = max(receivable − credit, 0)` | **Yes** |
| Device — payment authoring snapshot | `apps/pos/src/lib/offline/accountPaymentService.ts:155-181` | non-negative; overpayment **adds** to credit | `netBefore = max(receivable − credit, 0)`; `projectedCredit = credit + overpayment` | **Yes** |
| Immutable fiscal payload (TS types) | `apps/pos/src/lib/fiscal/payloads/AccountChargePayload.ts:92-101`, `AccountPaymentPayload.ts:59-68` | non-negative strings | snapshot carries `credit_balance_before`, `projected_credit_balance_after` | **Yes** |
| Server payload validator | `apps/api/.../FiscalPayloadConstraintValidator.php:2590-2597` (`moneyRegex`) | non-negative only (`^(0\|[1-9]\d*)(\.\d{N})?$`) | n/a (rejects negatives outright) | **Yes** |
| Customer mirror display | `apps/pos/src/components/customers/CustomerBalanceBadge.tsx:19-20` | non-negative magnitude | `netDue = max(receivable − credit, 0)` | **Yes** |
| Partner model accessor | `apps/api/.../Partner/Domain/Partner.php:274-283` | **expects** non-negative | `net = receivable − credit` | Formula is correct *for* the convention |
| **Server balance writer** | `apps/api/.../Accounting/Application/Services/PartnerBalanceService.php:62, 311-316, 327` | **NEGATIVE** (`SUM(debit) − SUM(credit)` of credit-nature account) | n/a | **NO — this is the bug** |
| Mirror serializer | `apps/api/.../POS/Presentation/Resources/PosCustomerMirrorResource.php:38-39` | passes raw `partners.credit_balance` (negative) through | n/a | Inherits the bug |

**Units / scale:** at rest the server stores `decimal:4` (`Partner::casts()` `apps/api/.../Partner/Domain/Partner.php:156-158`). The mirror resource emits the `decimal:4` strings (e.g. `"25.2500"`). The device re-formats every money field to the currency scale (0/2/3) via `bcformat(value, scale)` before authoring, so scale is reconciled on-device; **scale is not the problem, sign is.**

---

## 2. Flow 1 — Charge-to-account (ACCOUNT_CHARGE)

### 2.1 Device authoring & credit decision
- `authorAccountCharge` (`apps/pos/src/lib/accountCharge/accountChargeService.ts:432`) builds the payload, runs the credit decision, and appends the `ACCOUNT_CHARGE` fiscal event in a single SQLite transaction.
- Credit decision input is assembled at `accountChargeService.ts:228-251`, reading `customer.receivable_balance`, `customer.credit_balance`, `customer.credit_limit` (all `bcformat`-ed to the currency scale) and `balance_updated_at`.
- `evaluateAccountChargeCreditDecision` (`creditRulesEngine.ts:129`) is the gate. Its math (`creditRulesEngine.ts:218-225`):
  ```
  netBefore = receivable > credit ? receivable − credit : 0
  creditAvailableBefore = credit_limit − netBefore
  projectedNetAfter = max(receivable + charge − credit, 0)
  creditAvailableAfter = credit_limit − projectedNetAfter
  ```
  This is the canonical positive-magnitude formula. `parseMinorUnits` (`creditRulesEngine.ts:61-71`) requires `^\d+$` (scale 0) or `^(\d+)\.(\d{N})$` — **a negative input string returns `null` → `money_scale_invalid` rejection** (`creditRulesEngine.ts:213-216`).
- The decision (`credit_available_before/after`, `credit_limit`, `limit_exceeded`, `policy_version`, `override_evidence`) is sealed into the immutable payload at `accountChargeService.ts:360`.

### 2.2 Balance snapshot in the payload
`buildBalanceSnapshot` (`accountChargeService.ts:176-200`):
- `credit_balance_before = credit` (unchanged by a charge), `projected_credit_balance_after = credit` (charges never touch credit).
- `projected_receivable_balance_after = receivable + charge`.
- `net_balance_before` / `projected_net_balance_after` both `max(.. − credit, 0)`.

### 2.3 Server validation
`FiscalPayloadConstraintValidator::validateAccountChargePayload` (`apps/api/.../FiscalPayloadConstraintValidator.php:1124`):
- All snapshot money fields validated against `moneyRegex` → **non-negative enforced** (`:1342-1360`).
- `validateAccountChargeArithmetic` (`:1458-1504`) re-derives: `projected_receivable = receivable_before + charge_amount` (`:1490`) and `projected_net = max(projected_receivable − projected_credit, 0)` (`:1497-1502`) — the **server independently enforces the positive-magnitude net formula on the immutable bytes**.
- `payments` key is forbidden recursively (`:1521-1536`) — ACCOUNT_CHARGE is non-collected.

### 2.4 Projection → balance write
- `AccountChargeReceiptProjection` writes only a printable receipt row, **no balance/GL** (`apps/api/.../POS/Application/Projections/AccountChargeReceiptProjection.php:48-93`).
- The Treasury-gated bridge does the GL: `TreasuryAccountChargeBridge::apply` → `GeneralLedgerService::createPOSChargeEntry` (`apps/api/.../Treasury/Application/Projections/TreasuryAccountChargeBridge.php:80`).
- `createPOSChargeEntry` (`apps/api/.../Accounting/Domain/Services/GeneralLedgerService.php:1277-1366`): **DEBIT** `CustomerReceivable = total` with `partner_id` (`:1319-1327`); **CREDIT** `ProductRevenue = subtotal`, optional `VatCollected`; entry created `Draft` (`:1312`); then `refreshPartnerBalance($companyId, $partnerId)` (`:1363`).
- Receivable sign is **correct**: `SUM(debit) − SUM(credit)` on the debit-nature `CustomerReceivable` → positive = "customer owes us."

---

## 3. Flow 2 — Account payment (ACCOUNT_PAYMENT)

### 3.1 Device authoring
`createAccountPayment` (`apps/pos/src/lib/offline/accountPaymentService.ts:266`) builds + appends `ACCOUNT_PAYMENT` in one transaction, mirrors a local cash record for the Z (`:311-322`), and triggers sync.
- `computeBalanceSnapshot` (`:155-181`): `receivableReduction = min(payment, receivable)`, `projectedReceivable = max(receivable − reduction, 0)`, `overpayment = max(payment − receivable, 0)`, `projectedCredit = credit + overpayment`. **Overpayment correctly accrues to credit as a positive magnitude.**

### 3.2 Server validation
`validateAccountPaymentPayload` (`apps/api/.../FiscalPayloadConstraintValidator.php:963-1000`) + `validateAccountPaymentBalanceSnapshot` (`:1056-1082`): every snapshot money field validated by `moneyRegex` → non-negative.

### 3.3 Projection → balance write
- `AccountPaymentReceiptProjection` writes only a printable receipt row, **no balance/GL** (`apps/api/.../POS/Application/Projections/AccountPaymentReceiptProjection.php:48-74`).
- `TreasuryAccountPaymentBridge::apply` creates a `Payment` row and runs FIFO allocation (`apps/api/.../Treasury/Application/Projections/TreasuryAccountPaymentBridge.php:99-126`).
- `PaymentAllocationService` posts excess to a customer advance: `GeneralLedgerService::createCustomerAdvanceJournalEntry` (`apps/api/.../Accounting/Domain/Services/GeneralLedgerService.php:267-325`) — **DEBIT** Bank/Cash, **CREDIT** `CustomerAdvance` with `partner_id` (`:307-316`), entry `Draft`, then `refreshPartnerBalance` (`:322`). Excess-handling label is literally `'credit_balance'` (`PaymentAllocationService.php:541,596`).

---

## 4. Customer mirror sync

- Server → device: `PosCustomerSyncController::index` (`apps/api/.../POS/Presentation/Controllers/PosCustomerSyncController.php:29`) serializes each `Partner` through `PosCustomerMirrorResource`.
- `PosCustomerMirrorResource` (`apps/api/.../POS/Presentation/Resources/PosCustomerMirrorResource.php:37-50`) emits `receivable_balance`, `credit_balance`, `credit_limit` **raw from the model** (the `decimal:4` cached columns), plus a hard-coded `charge_policy_version = 'phase4-v1'` (`:45`) and `charge_account_enabled = is_active && account_status === active` (`:26,44`).
- Device ingest: `customerRepository.upsertCustomer` (`apps/pos/src/lib/db/repositories/customerRepository.ts:42-124`) writes the values verbatim into the `customers` mirror table; `getCustomerById` / `searchCustomers` `SELECT *` them back unchanged. **No sign transform anywhere on the device** (confirmed across `customerSyncService`, `customerRepository`, `CustomerAttachPanel.fromMirror`, `paymentStore`).
- Because the server stores `credit_balance` negative (§5), **the device mirror receives a negative magnitude**, in direct conflict with everything the device assumes.

### Staleness handling
- Device-side staleness: `isBalanceStale` (`customerRepository.ts:176-191`) — `balance_updated_at === null` → stale; non-numeric date → stale; older than threshold → stale. Used by `CustomerAttachPanel` (default 30 min) and folded into the payload `staleness` block.
- Hard-stale gate (charge only): `creditRulesEngine.ts:182-197` rejects when `balance_updated_at` is null, in the future, or older than `hard_stale_after_minutes` (default 240 — `accountChargeService.ts:249`). This is the **anti-downgrade** guard: a charge cannot proceed on a too-old balance snapshot, so a stale negative credit can't silently authorize over-limit credit.
- `mirror_stale_at_authoring` is hard-coded `false` in the approved decision builder (`creditRulesEngine.ts:121`) even though the input carries staleness — see §6 LOW-3.

---

## 5. The bug — `PartnerBalanceService::refreshPartnerBalance`

**Single buggy writer, confirmed as the sole balance writer for both flows.**

`getPartnerBalance` (`apps/api/.../Accounting/Application/Services/PartnerBalanceService.php:52-62`) returns `balance = bcsub(debit_total, credit_total, 4)` = `SUM(debit) − SUM(credit)`.

`refreshPartnerBalance` (`:288-344`) writes:
```php
'receivable_balance' => getPartnerBalance(.. CustomerReceivable).balance,  // debit-nature  → positive  ✓
'credit_balance'     => getPartnerBalance(.. CustomerAdvance).balance,      // credit-nature → NEGATIVE  ✗
'payable_balance'    => getPartnerBalance(.. SupplierPayable).balance,      // credit-nature → NEGATIVE  (separate concern)
```

`CustomerAdvance` is a credit-nature liability (`SystemAccountPurpose::CustomerAdvance = 'customer_advance'`, "Customer Advance/Prepayment"). Customer prepayments post as **credits** (`GeneralLedgerService.php:307-316`). Therefore `SUM(debit) − SUM(credit)` is **negative**, and `partners.credit_balance` is stored negative.

Every downstream consumer expects a non-negative magnitude:
- `Partner::getNetBalanceAttribute` = `receivable − credit` (`Partner.php:274-283`) → with negative credit becomes `receivable + |credit|` (debt inflated).
- `PosCustomerMirrorResource` → device mirror → `creditRulesEngine.parseMinorUnits` rejects the negative string → charge fails closed.

### Fix direction (single line)
In `refreshPartnerBalance`, store the **magnitude** of the advance balance:
```php
'credit_balance' => bcmul($creditResult['balance'], '-1', 4),   // or credit_total − debit_total
```
(Apply the same reasoning to `payable_balance` if supplier-side consumers also expect a positive magnitude — out of this audit's B2C scope but worth a paired ticket.)

### Why the tests don't catch it (coverage gap)
- `tests/Feature/Partner/PartnerBalanceListTest.php:85,108` and `tests/Feature/POS/PosCustomerSyncControllerTest.php:267` assert **positive** `credit_balance` (`'200.0000'`, `'25.2500'`) — but they set the column **directly** via `Partner::create([...])`, bypassing `refreshPartnerBalance`. They encode the correct expectation while never exercising the buggy writer.

### Secondary: Draft-vs-posted (material, may currently mask the bug)
`getPartnerBalance` filters `journal_entries.status = 'posted'` (`PartnerBalanceService.php:45`), but the POS charge and customer-advance entries are created as `Draft` (`GeneralLedgerService.php:1312, 291`). If these drafts are never posted by these flows, the subledger SUM excludes them and balances read `0` — so the negative-credit symptom only manifests once CustomerAdvance entries reach `posted`. This means the sign bug is **latent**: today it may read 0, but the first posted advance flips it negative. Verify the posting lifecycle as part of the fix.

---

## 6. Latent bugs & risks

- **HIGH-1 — Negative `credit_balance` breaks the next charge (fail-closed DoS-ish).** Once a customer accrues posted advance credit, the mirror serves a negative `credit_balance`; the very next ACCOUNT_CHARGE for that customer is rejected by `creditRulesEngine` `money_scale_invalid` (`creditRulesEngine.ts:214`). Root cause = §5. The device has no graceful "treat negative credit as 0" fallback.
- **HIGH-2 — Net balance inflation on the server.** `Partner::net_balance` (`Partner.php:278`) reports `receivable + |credit|` for any customer with credit. Any server-side dunning/credit-limit/statement logic reading `net_balance` over-states what the customer owes. (B2B facture path reads partner state too.)
- **MED-1 — No DB-level non-negativity invariant.** `partners.credit_balance` is `decimal(15,4) default 0` with **no CHECK constraint** (`database/migrations/tenant/2025_12_06_100001_add_balance_fields_to_partners.php:21`). Nothing at the storage layer prevents the negative write; the convention lives only in code comments.
- **MED-2 — Mirror emits scale-4 strings, device assumes scale 0/2/3.** `PosCustomerMirrorResource` emits `decimal:4` (e.g. `"25.2500"`); the device reformats via `bcformat(value, currencyScale)`. This currently works (big.js truncates/rounds to scale), but it is an **implicit** contract — if the device ever fed the raw mirror string to `parseMinorUnits` without reformatting (it does not today, but `creditRulesEngine` consumers must `bcformat` first), a 4-dp string would fail the 3-dp regex. Worth a contract note + test.
- **MED-3 — `charge_policy_version` is a server-side constant.** `PosCustomerMirrorResource.php:45` hard-codes `'phase4-v1'`; the device's override-evidence match (`creditRulesEngine.ts:99`) and the server validator (`:1326`) both pin `policy_version`. A future server bump without coordinated device handling would invalidate in-flight override evidence. Acceptable now, fragile later.
- **LOW-1 — Draft entries never posted** (see §5 secondary) — silently zeroes subledger balances; either a separate correctness bug or the thing currently masking HIGH-1/HIGH-2.
- **LOW-2 — `formatCurrency` uses `parseFloat` on money for display.** `apps/pos/src/lib/currency.ts:49` (hit transitively by `CustomerBalanceBadge` `format()`). Display-only, but a precision-contract smell; the badge's own net math is big.js-clean.
- **LOW-3 — `mirror_stale_at_authoring` hard-coded `false`.** `creditRulesEngine.ts:121` always emits `false` in the approved decision even when the input snapshot is older-than-threshold (but within the hard-stale window). The hard-stale gate still protects correctness, but the immutable receipt loses the "authored on a soft-stale snapshot" forensic signal.

---

## 7. Hardening recommendations (prioritized)

1. **[P0] Fix `refreshPartnerBalance` to store `credit_balance` (and review `payable_balance`) as a non-negative magnitude.** `credit_balance = credit_total − debit_total` (or `bcmul(balance,'-1',4)`). One line; the documented convention. (`PartnerBalanceService.php:327`.)
2. **[P0] Add a regression test that drives the *real* writer.** Post a customer-advance JE, run `refreshPartnerBalance`, assert `credit_balance >= 0` AND `net_balance == receivable − credit`. The current tests set the column directly and miss the bug.
3. **[P0] Resolve the Draft-vs-posted lifecycle** for POS charge / customer-advance entries (`GeneralLedgerService.php:1312, 291`). Confirm whether these are posted; if not, balances are silently wrong (zeroed) regardless of sign. Add a test asserting balances reflect the events.
4. **[P1] DB CHECK constraint** `credit_balance >= 0` (and `receivable_balance >= 0`, `payable_balance >= 0`) on `partners` so the storage layer enforces the invariant the code assumes. Backfill/repair existing negatives first.
5. **[P1] Type-level encoding of the magnitude convention.** Introduce a `NonNegativeMoney` value object / branded string (`type CreditMagnitude = string & { __nonNegative: true }`) on both sides, with a single constructor that asserts `bccomp(v,'0') >= 0`. Makes a negative `credit_balance` unrepresentable past the boundary, and gives the mirror serializer one place to fail loudly instead of silently shipping a negative.
6. **[P1] Defensive clamp + alarm on the mirror boundary.** `PosCustomerMirrorResource` should `max(credit_balance, 0)` AND emit a server log/metric if it ever sees a negative (so the underlying bug is observable, not just papered over). Symmetric device-side: `creditRulesEngine` could clamp a negative mirror credit to `0` with a warning rather than fail the whole charge.
7. **[P2] Make the mirror↔device scale contract explicit.** Either emit currency-scaled strings from `PosCustomerMirrorResource`, or document + test that the device always `bcformat`s before `parseMinorUnits`. Add a golden-vector test covering TND (scale 3) and a scale-0 currency.
8. **[P2] Wire `mirror_stale_at_authoring`** from the actual staleness input in `creditRulesEngine.buildApprovedDecision` so soft-stale authoring is preserved in the immutable receipt.
9. **[P2] Centralize the net formula.** `net = max(receivable − credit, 0)` is re-implemented in `Partner.php`, `CustomerBalanceBadge.tsx`, `creditRulesEngine.ts`, both authoring snapshot builders, and the server arithmetic validators. Extract a single shared helper per side and reference the spec, so a future convention change is one edit, not six.

---

## 8. Comparison to mature POS / retail systems (store credit, on-account sales, credit limits)

- **Store credit as a positive liability.** Shopify POS, Square, Lightspeed, and ERP AR subledgers all model store credit / customer credit as a **positive balance the business owes the customer** (a liability), displayed as "Credit: $X" and **subtracted** from receivables to get net. The locked convention here matches that norm; the bug is purely that the cached column stores the GL signed balance instead of the display magnitude. Mature systems solve this by storing the **subledger balance with an explicit normal-balance sign per account type** and converting to a magnitude at the presentation/cache boundary — exactly recommendation #6.
- **Credit-limit math on net exposure.** Standard practice (SAP, NetSuite, Dynamics) gates on-account sales against `available_credit = credit_limit − (open_AR − unapplied_credits)`, i.e. net exposure — which is precisely `creditRulesEngine`'s `credit_limit − net`. The engine is well-aligned; the only gap is that a corrupt (negative) credit input causes a hard reject rather than a clamped, audited degrade.
- **Override evidence & immutability.** Tying a credit-limit/account-status override to a signed approval event (`OPERATOR_APPROVAL_GRANTED` + `OVERRIDE_*` + `override_evidence` matched field-by-field in `creditRulesEngine.ts:87-100` and re-validated server-side) is **stronger** than most retail POS, which typically log a manager PIN without a chained, hash-sealed evidence record. This is a genuine strength of the design.
- **Staleness / offline credit.** Best-in-class offline-first POS (e.g. high-end retail terminals) refuse on-account sales against a too-old credit snapshot and fail closed — matching the hard-stale gate (`creditRulesEngine.ts:195`). The improvement opportunity is the lost soft-stale forensic flag (#8) and the absence of a clamp-with-warning path for corrupt inputs.
- **Storage invariant.** Mature ledgers enforce non-negativity / normal-balance sign at the DB or domain-object layer, not just in convention. The missing CHECK constraint (#4) and the absence of a value-object (#5) are the main maturity gaps relative to those systems.
