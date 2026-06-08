# Customer-Account Deposit (Money-In) — Manual Test Protocol

**Protocol ID:** B1
**Target application:** IziPOS / Otospex desktop app (`apps/pos/`, Tauri 2)
**Feature source:** `feat/pos-customer-accounts-phase2` (customer-account `ACCOUNT_PAYMENT` flow)
**Status:** ✅ ready
**Tester:** ____________________
**Test session date:** ____________________

---

## What this verifies

A customer who has an account with the shop can **pay money into that account** at the POS — for example, paying down what they owe, or pre-loading credit for future purchases. The cashier attaches the customer, enters an amount, and records the payment. The app:

- writes the payment on the terminal **immediately** (works offline — "local-first"),
- updates the customer's **balance** (reduces what they owe; any extra becomes store credit),
- produces a **printable receipt**, and
- **syncs** the payment to the server when the terminal is online.

"Working" means: the balance moves correctly, a receipt is produced, and the payment shows up on the server after sync.

> ⚠️ **This is NOT the cash-drawer "deposit."** There is a separate, unrelated feature where a cashier adds cash *to the till* (cash-drawer deposit / "Record Deposit" on the drawer screen). **That is a different thing.** This protocol is about money paid into a **customer's account**, done from the **Customer panel** on the sale screen. If you find yourself on a cash-drawer / till screen, you're in the wrong place.

## Scope
- **In scope:** attaching/creating a customer, recording a deposit, balance update, receipt, offline behavior, sync, input validation.
- **Out of scope:** charging a sale *to* the account (money-out) — see protocol **B2**; account status/credit-limit approvals — see **B3**.

---

## Preconditions

Complete the [shared environment setup](README.md#shared-environment-setup) first (signed in, terminal selected, **shift open**), then:

- [ ] The company has at least one **cash payment method** and one **active cash register** (the deposit is recorded against the till). Without these the Record button errors.
- [ ] You can either **find an existing customer** (search by name) **or create a new local customer** (you'll do this in Step 2).
- [ ] Note the company **currency** (EUR = 2 decimals, TND = 3 decimals) — you'll check the amount formats correctly.
- [ ] *(For the server-sync checks)* access to the web-admin / owner view for this company, to confirm the balance after sync. If you don't have it, mark the sync rows `SKIP` and note it.

> If there is **no open shift**, recording will fail with an "active terminal required" message. That's expected — open a shift first.

---

## Where the feature lives

On the main **sale screen**, the **Customer panel** is at the **top of the cart column** (the left panel by default). It shows a "Customer" heading. When no customer is attached it offers **search** and **create**; once a customer is attached it shows their **balance** and an **Amount + Record** control. That Record button is the deposit action. There is no separate "deposit screen."

---

## Walkthrough (happy path)

**Scenario: customer owes money, pays part of it.**

1. On the sale screen, locate the **Customer panel** (top of the cart column). → It shows the "Customer" heading with search + create options.
2. **Attach a customer:**
   - *Existing:* type a name in the customer search and select the matching row. → The panel switches to show the customer's name, contact, and an **"Attached"** badge.
   - *New (if needed):* enter **Name** + **Phone or Email**, click **Create local customer**. → The customer is created locally and auto-attached. (A brand-new customer starts at a zero balance — for a "pays down what they owe" test, use a customer who already has an outstanding balance.)
3. Read the **balance badge**: note the **receivable** (what they owe) and **credit** (store credit) shown. If a **"stale"** indicator is shown, the local balance is older than the threshold — that's allowed; note it.
4. In the **Amount** field, type a deposit amount **less than** the receivable (e.g. customer owes 100.00, deposit 40.00).
5. Click **Record** (the button with the wallet icon).
6. → A **success** confirmation appears, the Amount field clears, and the **balance badge updates**: receivable drops by the deposit (100.00 → 60.00), credit unchanged.
7. → A **printable account-payment receipt** is generated (print or preview per your terminal setup). Confirm it shows the **customer**, the **amount**, and the company **currency** with the correct number of decimals.
8. **Sync check:** ensure the terminal is online. The pending-sync indicator should clear shortly. In web-admin, open the customer's account → confirm the **payment appears** and the **server balance matches** what the terminal showed (receivable 60.00).

**Expected end state:** the deposit is recorded once, the customer owes 40.00 less, a receipt exists, and after sync the server shows the same balance.

### Variation — overpayment becomes credit
Repeat with a deposit **greater than** the receivable (e.g. owes 60.00, deposit 100.00). → Receivable goes to **0.00** and the extra **40.00 becomes credit**. Net balance (receivable − credit) should read 0 / 40.00 credit.

---

## Edge & negative probes

Run these — they're where problems hide:

- ⚠️ **Zero amount:** enter `0` and click Record. → Rejected with a clear message; **no** receipt, **no** balance change. (Training mode is the only case where zero is allowed.)
- ⚠️ **Empty amount:** leave the field blank, click Record. → "Payment amount is required"; nothing recorded.
- ⚠️ **No customer attached:** confirm the Amount + Record control **does not appear** until a customer is attached (you can't deposit into "nobody").
- ⚠️ **Offline deposit:** disconnect the terminal's network, record a valid deposit. → It **succeeds locally**, balance updates, receipt prints, and a sync item is queued. Reconnect → the payment **syncs** and appears on the server. Balance must **not** double-count.
- ⚠️ **Double-tap / repeat:** click Record twice quickly, or record the same amount twice. → Only the intended payment(s) are recorded; the app must not create a duplicate from a single intent (in-flight is guarded; server is idempotent per payment).
- ⚠️ **Stale balance:** use a customer whose balance hasn't synced recently (stale badge shown). Record a deposit. → Allowed; the receipt/record is flagged as based on a stale snapshot; after sync the server reconciles to the correct balance.

---

## Scenario table

Copy into Excel / Google Sheets to record results. Status: `PASS`/`FAIL`/`BLOCKED`/`SKIP`/`N/A`. Severity only if `FAIL` (see [reporting conventions](README.md#reporting-conventions-all-protocols)).

| Test ID | Scenario | Steps (short) | Expected result | Status | Actual behavior | Severity | Tester | Date |
|---------|----------|---------------|-----------------|--------|-----------------|----------|--------|------|
| B1-01 | Deposit < amount owed | Attach customer who owes 100; deposit 40; Record | Success; receivable 100→60; credit unchanged; receipt printed | | | | | |
| B1-02 | Deposit = amount owed | Attach customer who owes 60; deposit 60; Record | Receivable →0; credit unchanged; net 0 | | | | | |
| B1-03 | Overpayment → credit | Owes 60; deposit 100; Record | Receivable →0; credit +40; net 0 / 40 credit | | | | | |
| B1-04 | Deposit to zero-balance customer | New customer; deposit 25; Record | Credit +25; receivable stays 0 | | | | | |
| B1-05 | Currency decimals | Any deposit; inspect amount on receipt | EUR shows 2 decimals; TND shows 3 | | | | | |
| B1-06 | Receipt content | After any deposit, view/print receipt | Shows customer, amount, currency, date | | | | | |
| B1-07 | Server sync | Online; record deposit; check web-admin | Payment + matching balance appear on server | | | | | |
| B1-08 | Zero amount rejected | Deposit 0; Record | Clear error; nothing recorded | | | | | |
| B1-09 | Empty amount rejected | Blank; Record | "Payment amount is required"; nothing recorded | | | | | |
| B1-10 | No customer attached | No customer; look for Record control | Amount + Record not shown | | | | | |
| B1-11 | Offline deposit + sync | Go offline; deposit 30; reconnect | Records locally; syncs once; no double-count | | | | | |
| B1-12 | Double-tap idempotency | Tap Record twice fast | Single payment recorded | | | | | |
| B1-13 | Stale balance deposit | Stale-badge customer; deposit; sync | Allowed; reconciles correctly after sync | | | | | |
| B1-14 | No open shift (negative) | Close shift; try to deposit | Rejected: active terminal/shift required | | | | | |

---

## Notes for the tester

- **Two "balances":** *receivable* = what the customer owes you; *credit* = money they've pre-paid / overpaid that they can spend later. A deposit pays down receivable first, then turns any leftover into credit. **Net balance** = receivable − credit (never below zero).
- **Offline is normal.** This feature is designed to work with no internet — the payment is saved on the terminal first and synced later. Always include at least one offline run (B1-11).
- **If Record errors with "active terminal required":** your shift closed or the terminal lost its session — reopen the shift and retry (this is B1-14's expected behavior, but it can also surprise you mid-session).
- **Found a balance that's wrong after sync?** That's `CRITICAL` or `HIGH` (money is involved) — escalate immediately with the Test ID, the customer, the amount, and what the terminal vs. the server showed.
