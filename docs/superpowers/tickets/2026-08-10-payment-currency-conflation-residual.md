# Ticket — a payment whose currency differs from the company currency conflates the GL pool

**Raised by:** DPA `DPA-REV2-A` code gate, ruling 5.2 (2026-08-10)
**Class:** pre-existing, out of lane. **Low severity today**, real hazard.
**Status:** OPEN

## Why this exists

`DPA-REV2-A` closed OQ-7 as *"option (i) not implementable"* — `journal_entries`
and `journal_lines` carry **no currency column**, so a currency predicate cannot be
added to `PartnerBalanceService::getPartnerBalance()`.

The implementer's first framing was stronger: *"a partner **cannot** hold advances in
two currencies within one company's ledger."* **The code gate corrected that, and the
correction is the point of this ticket.** The absence of a currency dimension does not
*prevent* multi-currency advances — it means the GL **silently conflates** them.

## Mechanism

* `PaymentController.php:396` validates `'currency' => ['nullable','string','size:3']`
  with **no equality check against the company currency**.
* The N9 guard (`PaymentRefundService`, the cross-currency refusal in
  `postReversalGlAndMovement()`) ties the **repository** currency to the **payment**
  currency — never either to the **company's**.

So a foreign-currency payment on a matching foreign-currency repository passes every
existing guard and reaches `GeneralLedgerService::availableCustomerAdvance()`, which:

1. sums a **currency-blind** GL balance (all currencies pooled together), and
2. compares it at the **payment's** scale.

A-D3's ceiling is therefore computed against a conflated pool.

## Blast radius

Not limited to the reversal lane — `getPartnerBalance()` backs receivable/payable
balances, subledger reconciliation and partner statements. Anything reading a partner
balance in a multi-currency company reads a conflated figure.

## Why it was not fixed here

Out of lane, and the fix is not small: it needs a currency dimension on the GL (a
schema change on `journal_lines`/`journal_entries`), or a company-currency equality
guard at every payment writer, or an explicit multi-currency refusal. Each is its own
review. `DPA-REV2-A` only had to stop `availableCustomerAdvance()` resolving its SCALE
from a bare no-arg `getScale()` (gate finding I-2), which it did.

## Candidate fixes

1. **Cheapest, fail-closed:** validate `payments.currency === companies.currency` at
   the writers (`PaymentController::store()`/`storeMultiple()`), refusing otherwise.
   Matches the single-currency operating assumption the codebase already asserts in
   several places, and makes it explicit instead of implicit.
2. **Correct, expensive:** add a currency column to the GL and predicate every partner
   balance query on it. Enables genuine multi-currency, and is a lane of its own.
3. **Narrow:** have `availableCustomerAdvance()` refuse when the company currency and
   the passed currency differ. Protects A-D3 only; leaves every other
   `getPartnerBalance()` consumer conflated.

Recommendation: **1**, unless multi-currency is on the roadmap, in which case **2**.

## Verify before starting

```bash
psql -c "\d journal_entries" ; psql -c "\d journal_lines"   # confirm: still no currency column
grep -n "'currency'" apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php
grep -rn "getPartnerBalance" apps/api/app/                   # the full consumer list
```
