# Adversarial Review: partners.credit_balance Sign Convention

**Reviewed:** 2026-06-20  
**Reviewer:** Codex (adversarial probe, independent code inspection)  
**Branch:** docs/media-subsystem-architecture  

---

## Verdict

**APPROVE Direction B**  
Confidence: HIGH  
Blockers: NONE

---

## Evidence Verification

### Claim 1 — FiscalPayloadConstraintValidator moneyRegex

**CONFIRMED.**  
`moneyRegex()` has no minus path at `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:2590`.  
ACCOUNT_PAYMENT applies it to `credit_balance_before` and related snapshot fields at `:1056`.  
POS builds those fields from mirrored `customer.credit_balance` at `apps/pos/src/lib/offline/accountPaymentService.ts:161`.  
API mirror sends raw `credit_balance` at `apps/api/app/Modules/POS/Presentation/Resources/PosCustomerMirrorResource.php:37`.  
A negative stored value feeds directly into the non-negative regex check — ACCOUNT_PAYMENT fiscal events would be quarantined.

### Claim 2 — creditRulesEngine.ts rejects negative credit

**CONFIRMED. Direction A's core claim is REFUTED.**  
`parseMinorUnits()` uses a strictly non-negative regex at `apps/pos/src/lib/accountCharge/creditRulesEngine.ts:61`.  
`credit_balance` is parsed at `:209`, rejected as `money_scale_invalid` if invalid at `:214`, then used in `receivable - credit` at `:219`.  
The engine does NOT encode negative — a negative stored value causes `money_scale_invalid`, blocking charge-to-account entirely.

### Claim 3 — Every reader assumes positive credit

**CONFIRMED.**  
- `Partner::getNetBalanceAttribute()` subtracts credit at `apps/api/app/Modules/Partner/Domain/Partner.php:274`  
- `PartnerController` SQL: `(receivable_balance - credit_balance) AS net_balance` at `apps/api/app/Modules/Partner/Presentation/Controllers/PartnerController.php:72` and `:96`  
- Web list at `apps/web/src/features/partners/PartnerListPage.tsx:65`  
- POS badge prints `Credit {format(creditBalance)}` at `apps/pos/src/components/customers/CustomerBalanceBadge.tsx:30` — a negative value would render "Credit -50"

### Claim 4 — Tests and migration encode positive intent

**CONFIRMED.**  
`tests/Feature/Partner/PartnerBalanceListTest.php:139` — credit `800` produces lower net than credit `100` (higher credit = lower net, consistent with subtraction from receivable using positive credit).  
Migration `apps/api/database/migrations/tenant/2025_12_06_100001_add_balance_fields_to_partners.php:20` comment: credit_balance = "advance payments/credits (what we owe them)".

### Claim 5 — Writer currently produces negative

**CONFIRMED.**  
`getPartnerBalance()` returns `debit - credit` at `apps/api/app/Modules/Accounting/Application/Services/PartnerBalanceService.php:62`.  
`refreshPartnerBalance()` writes that raw CustomerAdvance result into `credit_balance` at `:311` and `:327`.  
`createCustomerAdvanceJournalEntry()` puts `partner_id` on the CustomerAdvance **credit** line at `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:307`, then calls refresh at `:321`.  
For a credit-holding customer: `debit_total=0, credit_total=120` → stored value = `0 - 120 = -120`.

### Claim 6 — refreshPartnerBalance is the only writer

**CONFIRMED** with scope caveat.  
`refreshPartnerBalance()` at `PartnerBalanceService.php:325` is the sole `partners.credit_balance` cache writer on the server.  
Multiple callers funnel through it: `PaymentController.php:413`, `:723`, `:864`.  
No `abs()`/negation found in any consumer; all readers pass through or subtract.

---

## Adversarial Probe Results

### Probe A — Consumers requiring negative sign

**DOES NOT BREAK Direction B.**  
Raw subledger methods use GL directly, not the cache:  
- `getAllPartnerBalances()` at `PartnerBalanceService.php:107`  
- `getSubledgerTotal()` at `:142`  
- `reconcileSubledger()` at `:190`  

These never touch `partners.credit_balance`, so the sign change is invisible to them.  
`usePartnerBalanceRealtime` hook only invalidates React Query caches at `apps/web/src/features/partners/hooks/usePartnerBalanceRealtime.ts:35` — it does not read the raw value.  
`RecordCustomerDepositService.php:119` returns cache as a display balance — it benefits from the fix.  
`RecordCustomerDepositResult` DTO passes it through, no sign assumption.  
TS `generated.d.ts` types `credit_balance` as `string` (PHP `decimal` passthrough) — no numeric sign assumption in the type contract.  
Back-office partner detail page renders via `getNetBalanceAttribute` — correct under Direction B.

### Probe B — GL sign claim and round-trip correctness

**DOES NOT BREAK Direction B.**  
`createCustomerAdvanceJournalEntry()` credits CustomerAdvance with `partner_id` at `GeneralLedgerService.php:307`.  
`clearCustomerAdvanceToReceivable()` debits CustomerAdvance for same partner at `:758`.  
Round-trip: advance creation → stored magnitude increases; clearing → stored magnitude returns to zero. No phantom residual under Direction B.

### Probe C — Can credit_balance ever legitimately be negative?

**DOES NOT BREAK Direction B.**  
CustomerAdvance is typed as a liability at `SystemAccountPurpose.php:24` with expected liability normal balance at `:143`.  
A debit-normal CustomerAdvance balance for a partner would indicate over-clearing or a manual journal anomaly — not a legitimate customer credit state.  
`max(0, credit - debit)` clamping correctly returns `0` for such edge cases.  
Minor caveat: `clearCustomerAdvanceToReceivable()` has no existing-balance guard at `GeneralLedgerService.php:735`, so over-clearing is theoretically possible, but that is an independent bug, not an argument for Direction A.

### Probe D — Correctness for Both-type partners and suppliers

**DOES NOT BREAK Direction B.**  
`Both` partners are customers via `isCustomer()` at `Partner.php:191`.  
Customer net uses `receivable - credit` at `:274` (correct under positive credit).  
Supplier-only net uses payable at `:281` — does not touch `credit_balance`.  
No scenario found where a supplier's `credit_balance` is read and negation would be expected.

### Probe E — Backfill sufficiency

**PARTLY UNCERTAIN — operational caveat only, not a blocker.**  
`refreshAllPartnerBalances()` at `PartnerBalanceService.php:350` backfills the server `partners.credit_balance` cache. Sufficient for the server side.  
POS SQLite mirrors persist `credit_balance` in local DB at `apps/pos/src/lib/db/migrations.ts:1178`; mirrors update only on sync at `apps/pos/src/lib/db/repositories/customerRepository.ts:83`.  
Any POS device that has not synced after the backfill will temporarily hold the old negative value. A POS sync trigger or forced customer data refresh is needed as part of rollout — this is an ops requirement, not a Direction B defect.  
Fiscal payloads/snapshots are immutable (`fiscal_events.payload` write-once per migrations `2026_05_14_100001...php:84` and `2026_05_14_100002...php:181`); already-written fiscal events are not touched by the cache fix.

### Probe F — Steelman of Direction A

**A coherent Direction A exists but is strictly more invasive and cannot satisfy the immutable fiscal payload constraint without adding conversion logic everywhere.**

The only coherent Direction A would require:
1. Every fiscal/POS balance snapshot builder adds `abs($creditBalance)` before feeding to `FiscalPayloadConstraintValidator`
2. `creditRulesEngine.ts` would need `parseMinorUnits` replaced with a signed parser, plus sign-flip logic
3. `CustomerBalanceBadge` would need `abs(creditBalance)` in the render path
4. `getNetBalanceAttribute` would need `receivable + credit` (addition, not subtraction)

This is a 5+ file coordinated change with a high probability of introducing new display bugs. It also cannot keep the fiscal payload truly "unchanged" — it requires the snapshot builders to compensate before the immutable write. If any builder is missed, fiscal events are quarantined.

**Conclusion: Direction A has no clean steelman. It requires N-surface changes versus Direction B's one-surface change, and it cannot avoid touching fiscal boundary code.**

### Probe G — One-writer fix with zero reader changes

**CONFIRMED** for the server cache and all readers that consume `partners.credit_balance`.  
Direction B requires changing only `PartnerBalanceService::refreshPartnerBalance()` (one function, one line of sign arithmetic).  
All readers (`getNetBalanceAttribute`, `PartnerController`, `PartnerListPage`, `CustomerBalanceBadge`, `creditRulesEngine.ts`, fiscal snapshot builders) already assume positive magnitude and require zero changes.  
The POS mirror resync is an ops step, not a code change to readers.

---

## Strongest Counterarguments Against Direction B

1. **POS mirror staleness window** (`apps/pos/src/lib/db/migrations.ts:1178`, `customerRepository.ts:83`): Between server backfill completion and each device's next sync, POS devices hold the old negative `credit_balance`. Any ACCOUNT_PAYMENT or charge-to-account attempt during this window will still hit the non-negative regex failure. This is not a reason to reject Direction B, but rollout must include a forced POS sync or a brief maintenance window. Severity: LOW (operational, not architectural).

2. **`clearCustomerAdvanceToReceivable()` lacks balance guard** (`GeneralLedgerService.php:735`): Over-clearing would produce `debit > credit`, making `credit - debit < 0`, and `max(0,...)` would silently clamp to zero. The anomaly would be invisible in the cache. However, this is a pre-existing independent bug that affects both directions equally, and clamping to zero is still more correct than caching `-50` under Direction A.

3. **`reconcileSubledger()` uses raw GL** (`PartnerBalanceService.php:190`): This method computes expected subledger balance directly from GL, then compares to cached `credit_balance`. After the fix, cached values will be positive magnitudes while the raw GL still returns `debit - credit` (negative). The reconciliation comparison logic must be verified to apply `abs()` or the inverse sign when checking cache vs. GL. If it currently does `|expected - cached| < threshold`, a cached `+120` vs. GL `-120` would produce a false reconciliation failure. **This is the strongest technical counterargument** — verify `reconcileSubledger()` sign handling before deploying.

---

## Recommended Fix

### Scope
Single file, single function: `apps/api/app/Modules/Accounting/Application/Services/PartnerBalanceService.php`, `refreshPartnerBalance()` (~line 312–327).

### Change
Replace:
```php
$creditBalance = $this->getPartnerBalance($partner, SystemAccountPurpose::CustomerAdvance)->balance;
// balance = debit - credit (negative for credit-holding customers)
$partner->credit_balance = $creditBalance;
```
With:
```php
$rawBalance = $this->getPartnerBalance($partner, SystemAccountPurpose::CustomerAdvance)->balance;
// balance = debit - credit; negate and floor at zero to get a positive magnitude
$partner->credit_balance = bcsub('0', $rawBalance, $scale) > '0'
    ? bcsub('0', $rawBalance, $scale)
    : '0';
// Or equivalently: bccomp($rawBalance, '0', $scale) < 0 ? bcsub('0', $rawBalance, $scale) : '0'
```

### Pre-deploy check
Verify that `reconcileSubledger()` comparison logic (`PartnerBalanceService.php:190` area) correctly handles positive cache vs. negative GL expectation. If it does a direct equality check, add a sign-inversion to the expected value in the reconcile comparison only — do not change `getPartnerBalance()` itself.

### Tests to add
- `refreshPartnerBalance()` after CustomerAdvance credit entry stores positive magnitude
- After `clearCustomerAdvanceToReceivable()`, `credit_balance` returns to `0`
- `reconcileSubledger()` passes with the fixed cache sign

### Rollout
1. Deploy API with the writer fix
2. Run `php artisan accounting:refresh-all-partner-balances` (or equivalent) per tenant
3. Trigger POS customer data resync for all active devices (forced sync or maintenance window)
4. Verify `CustomerBalanceBadge` displays positive amounts in smoke test
5. Verify ACCOUNT_PAYMENT fiscal events are no longer quarantined

---

*Generated by Codex adversarial review, 2026-06-20. Every file:line citation verified against live codebase.*
