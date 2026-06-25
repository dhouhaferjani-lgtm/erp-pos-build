# B2B / Back-Office AR & AP Balance Flow Audit

**Date:** 2026-06-22
**Scope:** Server-side production and consumption of partner (customer + supplier) AR/AP balances.
**Mode:** Read-only audit. No code changed.
**Locked convention being aligned to:** `partners.credit_balance` is a **NON-NEGATIVE MAGNITUDE**; `net = receivable − credit`; `receivable_balance > 0` = they owe us; `payable_balance > 0` = we owe supplier.

---

## TL;DR

The B2B AR/AP balance subsystem has **two parallel, divergent GL writers** and a **cache that is structurally disconnected from the production posting path**. The headline issues:

1. **There are TWO invoice→GL implementations.** The *production* one (`AccountingService::createInvoiceGLEntries`, wired via `InvoicePostedListener`) **omits `partner_id` on the AR line**, so the partner subledger query (which filters `WHERE partner_id = ?`) **never sees B2B invoices**. The *other* one (`GeneralLedgerService::createFromInvoice`) sets `partner_id` correctly but has **zero production callers** (tests only) and writes **Draft** entries the subledger query ignores anyway.
2. **`PartnerBalanceService::refreshPartnerBalance` writes `credit_balance` as a negative number** (the known buggy writer) — it stores `debit − credit` for the CustomerAdvance liability account, which is credit-natured, so the magnitude comes out negative. `net = receivable − credit` then *adds* the advance instead of subtracting it.
3. **All `GeneralLedgerService` AR/AP/advance/payment/clearing entries are created as `Draft` and never posted in production.** The subledger sums only `status='posted'`. So manual B2B payments, prepayment clearing, and back-office deposit advances **do not move the cached balance at all**. The back-office deposit service even documents this as "eventually consistent once the accounting cycle posts it" — but no accounting cycle posts them.

The net effect: for the production document path, `receivable_balance`/`credit_balance` are effectively driven by a posted-but-partnerless control account through `refreshPartnerBalance` — which means the subledger total is structurally **0 for the partner**, while the document-level `documents.balance_due` (trigger-maintained, a *separate* source of truth) is the figure that actually reflects what a B2B customer owes.

---

## 1. Sign / Units Conventions — As Found

### 1.1 Journal-line grain (the GL truth)
`JournalLine` stores separate `debit` and `credit` numeric-string columns. Balance = `SUM(debit) − SUM(credit)` (debit-positive). This is consistent everywhere:
- `PartnerBalanceService::getPartnerBalance` line 62: `bcsub($debitTotal, $creditTotal, 4)`.
- `getAllPartnerBalances` line 127, `getSubledgerTotal` line 153, `getControlAccountBalance` line 174 — all `SUM(debit) − SUM(credit)`.

Account natures:
- **CustomerReceivable** (asset): invoices Debit it (createInvoiceGLEntries line 144-150), payments Credit it. Debit-positive balance = customer owes us. ✅ matches "receivable positive = they owe us".
- **CustomerAdvance** (liability): advances Credit it (`createCustomerAdvanceJournalEntry` line 308-316). So `SUM(debit) − SUM(credit)` is **negative** for an outstanding advance.
- **SupplierPayable** (liability): supplier invoices Credit it (`createSupplierInvoiceJournalEntry` line 462-470), payments Debit it (`createSupplierPaymentJournalEntry` line 517-525). So `SUM(debit) − SUM(credit)` is **negative** for an outstanding payable.

### 1.2 Cache grain (`partners.*_balance`) — INCONSISTENT with the locked convention
`PartnerBalanceService::refreshPartnerBalance` (lines 304-330) writes the raw debit-positive GL balance into all three columns verbatim:
```
'receivable_balance' => $receivableResult['balance'],   // OK: asset, debit-positive
'credit_balance'     => $creditResult['balance'],        // BUG: advance liability → NEGATIVE
'payable_balance'    => $payableResult['balance'],        // BUG: payable liability → NEGATIVE
```
- `receivable_balance`: debit-positive → **positive when they owe us**. ✅ consistent.
- `credit_balance`: from CustomerAdvance (liability) → comes out **negative**. ❌ The locked convention wants a **non-negative magnitude**.
- `payable_balance`: from SupplierPayable (liability) → comes out **negative**. ❌ The locked convention wants `payable_balance > 0` = we owe supplier.

`Partner::getNetBalanceAttribute` (lines 274-283):
```
return bcsub($this->receivable_balance ?? '0', $this->credit_balance ?? '0', 4);  // customer
return $this->payable_balance ?? '0';                                              // supplier
```
This formula assumes the locked convention (credit & payable are positive magnitudes). Combined with the buggy writer it is **doubly wrong today**:
- For a customer with an advance: `net = receivable − (−advance) = receivable + advance` → net is *overstated* (advance increases what they appear to owe, the opposite of reality).
- For a supplier: `net = payable_balance` which is negative → net reads as a customer-style "they owe us" instead of "we owe them".

`PartnerController::index` (lines 96, 72) and `has_balance` filter both compute `(receivable_balance − credit_balance)` in SQL, inheriting the same wrong-sign assumption — so the list page net column and the "has balance" filter are wrong whenever a credit/advance exists.

### 1.3 Scales — INCONSISTENT across the stack
- `partners.receivable_balance/credit_balance/payable_balance`: `decimal(15,4)` (migration `2025_12_06_100001_add_balance_fields_to_partners.php` lines 18-24; cast `decimal:4` in `Partner::casts()` lines 156-158).
- `documents.total/subtotal/tax_amount/balance_due`: `decimal(15,2)` (migration `2025_11_30_080000_create_documents_table.php` lines 25-29).
- `payments.amount` and `payment_allocations.amount`: `decimal(15,2)` (migration `2025_11_30_120000_create_treasury_tables.php` lines 148, 175).
- Allocation engine arithmetic: hardcoded scale **4** throughout `PaymentAllocationService` (e.g. lines 187, 196, 468, 510, 532, 590).
- GL/partner-balance reads: hardcoded scale **4** (`getPartnerBalance` line 62; `reconcileSubledger` line 196; `getPartnerStatement` lines 273-277).

This violates the post-2026-05-28 precision contract: currency at-rest should be `decimal(N,3)` floor, never `decimal(15,2)`, and scale should come from `CurrencyScaleResolverInterface`, never hardcoded `4`/`2`. The `decimal(15,2)` storage **truncates** the scale-4 allocation math when persisted, and a 3-dp currency (the contract floor) cannot be represented at all. This is the memory-tracked "deferred payment_allocations scale ticket" and it is wider than just `payment_allocations`.

---

## 2. Flow-by-Flow Findings

### 2.1 Document → GL (invoice / credit note posting)

**Production path:** `DocumentPostingService::post()` seals the fiscal chain and dispatches `InvoicePosted` (DocumentPostingService.php lines 182-195) → `InvoicePostedListener::handle` (lines 18-34) → `AccountingService::createInvoiceGLEntries` / `createCreditNoteGLEntries`.

`AccountingService::createInvoiceGLEntries` (lines 104-208):
- Creates the entry **`JournalEntryStatus::Posted`** directly (line 118) with its own hash chain — good, it actually posts.
- **BUG (HIGH): AR line has NO `partner_id`** (lines 144-150). Same for the credit-note AR reversal (lines 260-266). The partner subledger query in `PartnerBalanceService` filters `WHERE journal_lines.partner_id = ?` (getPartnerBalance line 44), so **posted B2B invoices never appear in the customer's receivable subledger**. `refreshPartnerBalance` is called (lines 202-205) but resolves the receivable to 0 for that partner.
- Consequence: `reconcileSubledger` (PartnerBalanceService lines 190-217) will report the AR control account ≠ subledger total, with all invoice AR sitting in `entries_without_partner`. The reconciliation routine is effectively a built-in detector for this exact bug.

`DocumentPostingService::post()` itself creates **no** GL entry — GL is entirely event-driven via the listener.

**Dead parallel path:** `GeneralLedgerService::createFromInvoice` (lines 57-123) and `createFromCreditNote` (lines 132-198) DO set `partner_id` on the AR line (lines 86, 184) — but:
- They write `JournalEntryStatus::Draft` (lines 75, 150). The subledger ignores drafts.
- They have **no production callers** — only `tests/Feature/Accounting/*` and `tests/Feature/Document/CompleteSalesCycleWithReturnTest.php`. So the "correct" partner-tagged implementation is exercised only in tests, giving false confidence that the subledger works.

**Tax/COGS asymmetry:** `AccountingService` recomputes VAT per-line from `tax_rate` (groupTaxByRate lines 379-402) and credits revenue per line; `GeneralLedgerService::createFromInvoice` uses the stored `subtotal`/`tax_amount`. Two different tax derivations for the "same" entry — a divergence risk if the dead path is ever revived.

### 2.2 Customer-advance clearing (SO→Invoice conversion)

`SalesOrderToInvoiceConverter` (lines 397-424) calls `GeneralLedgerService::clearCustomerAdvanceToReceivable` when prepayments transfer from a confirmed order to its invoice.
- The entry is **Draft** (`clearCustomerAdvanceToReceivable` line 753) and is **never posted** → it never affects the cached balance.
- **Over-clear risk (MED):** `clearCustomerAdvanceToReceivable` blindly Debits CustomerAdvance and Credits AR for `$totalPrepaid` (lines 759-778) with no check that the partner's advance subledger actually holds ≥ `$totalPrepaid`. If the order's recorded prepayment exceeds the customer's real advance liability (e.g. partial refund happened, or the advance was already cleared), this drives the advance account into the wrong sign with no guard. Standard ERP practice caps an advance application at the outstanding advance balance.
- It also Credits AR for the *full prepaid amount* while `createInvoiceGLEntries` already debited AR for the full invoice total. Net AR is correct *only if both entries post*; but the invoice entry posts (and is partnerless) while the clearing entry is Draft (and is partner-tagged) — so the two halves land in different statuses and different subledger visibility. The partner subledger sees neither correctly.

### 2.3 Back-office customer deposit (`DEPOSIT_RECEIPT`)

`RecordCustomerDepositService::record` (lines 46-123):
- Authors the `DEPOSIT_RECEIPT` fiscal event + seeds projections atomically (lines 63-89), then runs projections synchronously (line 95). Good orchestration.
- Reads `settled`/`credited` from persisted `payment_allocations` via `DepositAllocationSummaryService` (lines 100-106) — *exact at write time*, decoupled from GL. Good.
- **Returns `credit_balance`/`net_balance` straight from the partner cache** (lines 119-121) after `refresh()`. But the deposit's advance leg is a **Draft** GL entry (see 2.4), so:
  - `credit_balance` returned to the UI **does not include the just-credited advance** (the advance JE is Draft → subledger ignores it → refresh won't pick it up).
  - Even once "posted," `credit_balance` would be **negative** (the §1.2 writer bug).
  - `net_balance` is therefore stale-and-wrong on the deposit response. The service's own docstring (lines 108-109) acknowledges the eventual-consistency gap but the posting that would resolve it never happens.

`DepositAllocationSummaryService::summaryForFiscalEvent` (lines 28-60):
- `credited = amount − settled` (line 55-57). This is correct **only if every allocation row is a settlement against an open invoice**. The allocation engine writes allocation rows for invoices *and* sales orders (prepayments); a SO prepayment allocation is technically not "credited as advance," so this `amount − settled` definition can mislabel a SO-prepayment allocation. Minor for the deposit flow (deposits FIFO into invoices) but a latent labeling bug.

### 2.4 Treasury allocation (`PaymentAllocationService`) + `TreasuryDepositBridge`

`TreasuryDepositBridge::apply` (lines 82-143) creates a `Payment` (origin BackOffice) and calls `PaymentAllocationService::applyAllocationFromCommand` with `AllocationMethod::FIFO` — the same engine the device `ACCOUNT_PAYMENT` path uses (anti-divergence guarantee, good). Idempotent on `payments.fiscal_event_id` with an advisory xact lock (lines 87-92, 99-112). Actor is required and must be an active company member (lines 234-259) — and the docstring (lines 219-233) correctly notes that a null actor would skip the advance GL leg.

`PaymentAllocationService::applyAllocationFromCommand` (lines 138-369):
- FIFO settle of open invoices, overflow → CustomerAdvance, with tolerance write-off — logic is reasonable. Open-item selection via `getOpenInvoices` (lines 407-437) is correctly tenant+company scoped and uses `total > SUM(allocations)`.
- **All GL entries it creates are Draft and never posted:**
  - `createPaymentReceivedJournalEntry` (Dr Bank / Cr AR-with-partner) — Draft (GeneralLedgerService line 580).
  - `createCustomerAdvanceJournalEntry` for SO prepayments and for excess (lines 282, 310) — Draft (line 291).
  - So a manual B2B payment **reduces `documents.balance_due` via the trigger** (line 189 comment; trigger in `2026_01_08_214145_add_balance_due_cache_trigger.php`) but **does NOT reduce `partners.receivable_balance`** (that needs a posted partner-tagged AR credit). The two "what they owe" numbers diverge immediately after any payment.
- **Excess→advance only fires when `actor instanceof User`** (lines 281, 308). If the actor can't be resolved (e.g. queued replay where the user was deactivated), the excess money is **silently dropped from GL** — no advance entry, no credit balance, but the Payment row exists. The bridge guards this for deposits (2.4 actor check) but the generic `applyAllocation` web path (lines 112-131) relies on `Auth::id()` and the membership check in `resolveCommandActor` (lines 371-393); a payment by a user who lost membership mid-session would create allocations with no advance GL for the excess.
- **`total_allocated` initialized as `'0.0000'`** (line 159) and accumulated at scale 4, but persisted into `payment_allocations.amount` `decimal(15,2)` → truncation on store (see §1.3).
- **Eventual-consistency / event-vs-transaction gap:** `PaymentAllocated` and `DocumentFullyPaid` events fire **after** the transaction commits (lines 343-366). The `documents.balance_due` cache is updated by a PG trigger *inside* the tx (synchronous), but `partners.*_balance` is only refreshed by the (Draft, unposted) GL entries' `refreshPartnerBalance` calls — which, being Draft, are no-ops for the subledger. So there is no path by which a manual payment refreshes the partner receivable cache to a correct value.

### 2.5 Supplier-side (AP) symmetry

- `createSupplierInvoiceJournalEntry` (lines 402-479) and `createSupplierPaymentJournalEntry` (lines 487-545) correctly tag `partner_id` on the SupplierPayable line (lines 465, 519). **But both are Draft** (lines 429, 511) and have **no production callers** found (grep shows only the GL service defines them; no listener/service invokes them). There is **no production supplier-invoice → GL path at all** analogous to `InvoicePostedListener` for sales. AP `payable_balance` is therefore never populated from supplier documents in production.
- `reverseSupplierAdvanceJournalEntry` (lines 334-393): Draft, no production caller found.
- Consequence: the AP half of the subledger is **entirely non-functional in production** today. `payable_balance` stays at its default 0 (or whatever opening-balance services seed) and `getNetBalanceAttribute` for suppliers returns that 0/seed value.
- Opening services (`AccountingOpeningService` line 227, `InventoryOpeningService` line 331, `ArApOpeningService`) DO post directly and are the only way AP/AR balances get real posted data — but `ArApOpeningService::postBatch` explicitly creates **no GL entry** (comment lines 237, 290) and relies on `documents.balance_due`, reinforcing that `documents.balance_due` — not the GL subledger — is the de-facto AR/AP truth.

### 2.6 Tolerance write-off

`createPaymentToleranceJournalEntry` (lines 627-716) is partner-tagged on the AR leg (lines 679, 692) and Draft. Invoked by `PaymentAllocationService` via `toleranceService->applyTolerance` (lines 202-210) and by `CloseInvoiceWithToleranceService`. Same Draft-never-posted caveat: tolerance adjustments don't reach the cached partner balance. Signs are internally consistent (underpayment Dr expense/Cr AR; overpayment Dr AR/Cr income).

---

## 3. Comparison to Standard ERP AR/AP Subledger Practice

| Standard practice | This codebase |
|---|---|
| **Single AR/AP control account** reconciled to a **partner subledger** (every AR/AP line carries the partner). | Two writers; the *production* one omits `partner_id` on AR. AP writer unused. Subledger ≠ control by construction. |
| **Open-item** management (each invoice tracked to settlement; FIFO/specific allocation). | Open-item exists via `payment_allocations` + `documents.balance_due` trigger — actually the *strongest* part of the system. But it's a **second source of truth** parallel to the GL subledger, and they disagree. |
| **Posted-only** balances; drafts excluded from the subledger but drafts get *posted* as part of the cycle. | Subledger correctly excludes drafts — but **nothing posts the AR/AP/payment/advance drafts**, so they're permanently invisible. |
| **Advances/prepayments** held in a dedicated liability and applied (capped) against invoices. | CustomerAdvance liability exists; application is **uncapped** (over-clear risk) and **Draft** (never posts). |
| **Credit-balance presented as a positive magnitude** distinct from receivable. | `credit_balance` stored **negative**; net formula assumes positive → double-counts advances. |
| **Control-account reconciliation report.** | Exists (`reconcileSubledger`) and would flag the partner-id bug — but isn't enforced anywhere. |

The system has effectively converged on **`documents.balance_due` as the real open-item AR ledger** while the GL partner subledger (`partners.*_balance`) is a vestigial, partially-wired cache. The locked credit-balance convention only matters once the writer is fixed *and* the production posting path actually populates the subledger.

---

## 4. Bugs (with file:line)

**B1 — HIGH — Production invoice/credit-note AR lines have no `partner_id`.**
`AccountingService::createInvoiceGLEntries` AR debit line — `apps/api/app/Modules/Accounting/Application/Services/AccountingService.php:144-150`; credit-note AR credit line `:260-266`. No `partner_id` set. Partner receivable subledger (`PartnerBalanceService::getPartnerBalance` filter at `apps/api/app/Modules/Accounting/Application/Services/PartnerBalanceService.php:44`) never matches them → `receivable_balance` is structurally 0 for all B2B invoices.

**B2 — HIGH — `credit_balance` (and `payable_balance`) stored negative.**
`PartnerBalanceService::refreshPartnerBalance` — `apps/api/.../PartnerBalanceService.php:327` (credit) and `:328` (payable). Writes raw `SUM(debit)−SUM(credit)` of liability accounts → negative magnitude. Violates locked convention. `Partner::getNetBalanceAttribute` (`apps/api/app/Modules/Partner/Domain/Partner.php:278`) then adds advances instead of subtracting. `PartnerController::index` SQL `(receivable_balance - credit_balance)` (`apps/api/.../PartnerController.php:96`, filter `:72`) inherits the error.

**B3 — HIGH — GL AR/AP/payment/advance/clearing/tolerance entries are Draft and never posted in production.**
All factory methods in `GeneralLedgerService` set `JournalEntryStatus::Draft` (e.g. `createPaymentReceivedJournalEntry` `:580`, `createCustomerAdvanceJournalEntry` `:291`, `clearCustomerAdvanceToReceivable` `:753`, supplier methods `:429`/`:511`). Only POS receipt bridges call `postEntry` (`ReceiptPaymentService.php:313,406`; `TreasuryReceiptBridge.php:432`). No scheduled/console job posts AR/AP drafts (confirmed: none in `app/Console`, `app/Jobs`). Subledger sums `status='posted'` only → these entries never move the cache.

**B4 — HIGH — No production supplier-invoice → GL path.**
`createSupplierInvoiceJournalEntry` / `createSupplierPaymentJournalEntry` have no callers outside `GeneralLedgerService` itself. `payable_balance` is never populated from supplier documents (only opening-balance services seed AP).

**B5 — MED — Back-office deposit response returns stale/wrong `credit_balance`/`net_balance`.**
`RecordCustomerDepositService::record` `apps/api/app/Modules/Partner/Application/Services/RecordCustomerDepositService.php:119-121` reads from the partner cache, but the deposit's advance leg is Draft (B3) and credit sign is wrong (B2). The `settled`/`credited` split (from allocations) is correct; the balances are not.

**B6 — MED — Uncapped advance application (over-clear).**
`clearCustomerAdvanceToReceivable` `apps/api/.../GeneralLedgerService.php:758-778` applies `$totalPrepaid` with no check against the partner's actual CustomerAdvance balance.

**B7 — MED — Currency scale mismatch / truncation.**
`payments.amount` & `payment_allocations.amount` are `decimal(15,2)` (`2025_11_30_120000_create_treasury_tables.php:148,175`); documents are `decimal(15,2)` (`2025_11_30_080000_create_documents_table.php:25-29`); partner balances are `decimal(15,4)`; allocation/balance math hardcodes scale 4/2 instead of `CurrencyScaleResolverInterface`. Violates the `decimal(N,3)` floor precision contract; truncates scale-4 math on store; can't represent 3-dp currencies.

**B8 — LOW — Excess→advance GL leg silently skipped when actor unresolved (generic web path).**
`PaymentAllocationService::applyAllocationFromCommand:281,308` gate advance creation on `actor instanceof User`. A payment whose actor lost company membership creates allocations with no advance GL for the excess (the deposit bridge guards this; the generic path does not fail loud).

**B9 — LOW — `credited = amount − settled` can mislabel SO-prepayment allocations.**
`DepositAllocationSummaryService:55-57` treats any non-settled remainder as "credited as advance," but allocation rows may include sales-order prepayments which aren't advances.

**B10 — LOW — Two divergent tax derivations for the same logical entry.**
`AccountingService::groupTaxByRate` (per-line recompute, `:379-402`) vs `GeneralLedgerService::createFromInvoice` (stored `tax_amount`, `:104`). If the dead path is revived, AR/VAT could disagree.

---

## 5. Prioritized Hardening Recommendations

**P0 — Make the subledger real and single-sourced.**
1. Add `partner_id` to the AR lines in `AccountingService::createInvoiceGLEntries`/`createCreditNoteGLEntries` (fixes B1). Decide on ONE invoice→GL writer and delete or quarantine the dead `GeneralLedgerService::createFromInvoice/createFromCreditNote` (fixes B10, removes false test confidence).
2. Decide the canonical AR open-item source: either (a) drive `partners.*_balance` from posted, partner-tagged GL (then B3 must be fixed), or (b) formally make `documents.balance_due` the AR truth and derive `receivable_balance` from it. Today both exist and disagree. Standard practice favors (a) with `documents.balance_due` as the open-item detail of the same subledger.

**P0 — Fix the credit/payable sign (B2), in lockstep with the locked decision.**
Flip the single writer: store `credit_balance` and `payable_balance` as non-negative magnitudes (e.g. `credit_balance = credit − debit` for the advance liability; `payable_balance = credit − debit` for SupplierPayable). Add a CHECK/test asserting `credit_balance >= 0` and `payable_balance >= 0`. Verify `getNetBalanceAttribute` and `PartnerController` SQL then produce the right net.

**P1 — Resolve the Draft-never-posted gap (B3, B4).**
Either post AR/AP/payment/advance entries inline (like the POS bridges do) within their authoring transaction, or build the missing "accounting cycle" poster the deposit service's comment assumes. Wire a production supplier-invoice→GL path (B4). Until then, the GL subledger should be treated as non-authoritative and the UI should source AR from `documents.balance_due`.

**P1 — Enforce reconciliation.**
`PartnerBalanceService::reconcileSubledger` already detects B1 (it counts `entries_without_partner`). Run it as a scheduled assertion per company and alert on `is_balanced=false` or `entries_without_partner>0`. This is the cheapest guardrail and would have caught B1 immediately.

**P2 — Cap advance application (B6)** at the partner's outstanding CustomerAdvance balance; throw/clamp on over-clear.

**P2 — Precision contract alignment (B7).**
Migrate `payments.amount`, `payment_allocations.amount`, and `documents.*` money columns to the `decimal(N,3)` floor; replace hardcoded scale `2`/`4` in `PaymentAllocationService` and `PartnerBalanceService` with `CurrencyScaleResolverInterface::getScale($currency)`. Round once at the persistence boundary via `CurrencyScale::bcformatStrict`.

**P3 — Fail loud on unresolved actor for excess advances (B8)**; refine deposit `credited` labeling to exclude SO-prepayment allocations (B9).

**P3 — B2B vs B2C note.** `customer_category` (Business vs Individual, `Partner::isB2B()` line 204) does not branch any balance logic today; credit-limit (`credit_limit`, `hasActiveCreditLimit()` lines 212-219) is enforced only in the POS account-charge credit-decision path (Fiscal payload validators), not in the back-office AR flows audited here. If credit limits should gate B2B invoicing/deposits, that enforcement is currently absent server-side in these flows.

---

## 6. Frontend Consumption (web) — confirms the locked convention

The web frontend already assumes the **locked** convention (credit/payable as positive magnitudes), so fixing the backend writer aligns them; today the frontend mis-renders because the backend ships negatives.

- `PartnerListPage.tsx` `getNetBalance` computes `receivable − credit` and assumes both positive; renders `Math.abs(balance)` with red (>0 = they owe us) / green (<0 = we owe them). With the negative `credit_balance` bug, a customer with a 50 advance against a 50 receivable shows **net 100 in red** ("they owe us more") instead of **0** — sign error compounds into a wrong direction *and* wrong magnitude. (PartnerListPage.tsx getNetBalance ~lines 65-72; balance cell ~line 362.)
- `RecordDepositModal.tsx` shows a success toast with `settled_amount`/`credited_amount` via `formatCurrency`, which preserves a leading minus — a negative `credited_amount` renders as "credited -50.00". (~lines 96-102.)
- `formatCurrency` (`lib/format.ts` ~21-43) uses `Intl.NumberFormat` and passes negatives straight through — no magnitude/sign normalization. TS types declare all balance fields `string | null` with **no sign enforcement**.
- `PartnerDetailPage.tsx` uses aggregated `total_receivable`/`total_payable`/`unallocated_balance` (positive magnitudes) and only renders the unallocated balance when `> 0`, which can *mask* a negative credit rather than surface it.

Net: the frontend is the right shape for the locked convention; B2 must be fixed at the single backend writer (`PartnerBalanceService::refreshPartnerBalance:327-328`) so positives flow through unchanged.

## Appendix — Key files

- `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php` (Draft writers; dead createFromInvoice/CreditNote)
- `apps/api/app/Modules/Accounting/Application/Services/AccountingService.php` (production invoice/CN GL — partnerless AR)
- `apps/api/app/Modules/Accounting/Application/Services/PartnerBalanceService.php` (cache writer; subledger reads; reconcile)
- `apps/api/app/Modules/Accounting/Listeners/InvoicePostedListener.php` (production wiring)
- `apps/api/app/Modules/Partner/Domain/Partner.php` (net_balance formula; casts)
- `apps/api/app/Modules/Partner/Presentation/Controllers/PartnerController.php` (index net/has_balance SQL)
- `apps/api/app/Modules/Partner/Application/Services/RecordCustomerDepositService.php` + DTO `RecordCustomerDepositResult.php`
- `apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php` (FIFO/excess/advance; Draft GL)
- `apps/api/app/Modules/Treasury/Application/Projections/TreasuryDepositBridge.php` (deposit→payment→allocation)
- `apps/api/app/Modules/Treasury/Application/Services/DepositAllocationSummaryService.php` (settled/credited split)
- `apps/api/app/Modules/Document/Domain/Services/DocumentPostingService.php` (posts doc, no GL)
- `apps/api/app/Modules/Document/Domain/Services/Conversion/Converters/SalesOrderToInvoiceConverter.php:397-424` (advance clearing)
- Migrations: `2025_12_06_100001_add_balance_fields_to_partners.php`, `2025_11_30_120000_create_treasury_tables.php`, `2025_11_30_080000_create_documents_table.php`, `2026_01_08_214145_add_balance_due_cache_trigger.php`
