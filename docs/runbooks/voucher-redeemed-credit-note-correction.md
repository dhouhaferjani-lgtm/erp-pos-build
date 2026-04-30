# Runbook: Voucher Redeemed — Credit Note Correction

**Audience:** Finance / Accounting Operations  
**Severity:** Medium (revenue integrity impact; no immediate data loss)  
**Phase 1 hard-block:** This situation prevents automated void of the credit note.  
**Phase 1.1 resolution:** Automated `VoucherFiscalCorrection` flow (ETA: see roadmap).

---

## What happened

A credit note was voided in the ERP. However, the system detected that at least one voucher issued from that credit note has already been redeemed by a customer. Because the customer has spent some or all of the voucher value, the system refuses to automatically reverse the credit note — doing so without accounting for the spent amount would create a GL imbalance and violate fiscal integrity.

**In plain terms:** You gave a customer a refund voucher (via a credit note). They used the voucher to pay for a new purchase. Now someone is trying to cancel the original credit note, but the voucher spend has already been recorded in the books.

---

## Why Phase 1 blocks (technical)

The ERP can safely cascade-void an unredeemed voucher by mirror-reversing the issuance GL entry:

```
Issuance:    Dr SalesReturnsClearing  /  Cr VoucherLiability
Void/reversal: Dr VoucherLiability   /  Cr SalesReturnsClearing
```

However, if any voucher amount has been redeemed, the ledger already shows:

```
Redemption:  Dr VoucherLiability  /  Cr PosTenderClearing
```

The PosTenderClearing entry offsets revenue in the shift's Z-report. Reversing the issuance now would leave PosTenderClearing with an unmatched debit — a GL error. Phase 1 blocks rather than create a broken ledger state.

---

## Manual correction procedure

Complete **all steps** in order. Two-person review is required (Maker/Checker).

### Step 1 — Assess the situation

1. Identify the credit note ID from the exception or the support ticket.
2. Query all vouchers linked to that credit note:
   ```sql
   SELECT id, code, status, current_balance, initial_balance, source
   FROM vouchers
   WHERE source_receipt_id = '<credit_note_receipt_id>';
   ```
3. For each voucher, check redemption history:
   ```sql
   SELECT event, amount, occurred_at, receipt_id
   FROM voucher_ledger
   WHERE voucher_id = '<voucher_id>'
   ORDER BY occurred_at;
   ```
4. Determine the **redeemed amount** and the **remaining (unredeemed) balance** for each voucher.

### Step 2 — Issue a corrective debit note

Issue a corrective debit note (or an offsetting invoice) for the redeemed voucher amount(s):

- The debit note amount = the total value redeemed across all vouchers.
- This offsets the original credit note for the portion the customer has spent.
- The remaining (unredeemed) balance is addressed in Step 4.
- Record the debit note number and GL journal entry ID for audit.

### Step 3 — Notify the customer (if applicable)

If the credit note void changes the customer's outstanding balance or voucher entitlement:

- Contact the customer to explain the adjustment.
- Confirm that their voucher balance has been updated (Step 4).
- Provide them with any replacement credit or documentation as per your customer-service policy.

### Step 4 — Adjust voucher records via DB console

Perform this step with **two-person review** (Maker/Checker sign-off in the change log).

For each voucher linked to the voided credit note:

```sql
-- Set to Voided, zero the balance
UPDATE vouchers
SET status = 'voided',
    current_balance = '0.00000',
    updated_at = NOW()
WHERE id = '<voucher_id>';

-- Append a Voided ledger row (append-only table)
INSERT INTO voucher_ledger (
  id, tenant_id, company_id, voucher_id, event, amount, currency,
  receipt_id, terminal_id, user_id, gl_journal_entry_id,
  authorized_by_user_id, policy_trigger, reverses_voucher_ledger_id, occurred_at, created_at
)
VALUES (
  gen_random_uuid(),
  '<tenant_id>', '<company_id>', '<voucher_id>',
  'voided',
  '<negative_current_balance_at_time_of_void>',
  '<currency>',
  NULL, NULL, '<operator_user_id>',
  '<gl_journal_entry_id_from_step_5>',
  '<approver_user_id>',
  'manual_correction_post_credit_note_void',
  NULL,
  NOW(), NOW()
);
```

### Step 5 — Create manual GL journal entries

For each voucher, post two journal entries manually:

**Entry A — Unredeemed remainder write-off (mirrors the unredeemed issuance):**
```
Dr VoucherLiability         <unredeemed_amount>
Cr SalesReturnsClearing     <unredeemed_amount>
```
Description: "Manual correction: void unredeemed portion of voucher [CODE] — credit note [CN#] voided"

**Entry B — Redeemed amount correction (recognises the spend that already occurred):**
```
Dr VoucherLiability         <redeemed_amount>
Cr VoucherCorrection        <redeemed_amount>
```
Where `VoucherCorrection` is the appropriate clearing/correction account as designated by your chart of accounts. Document the reason: "Manual correction: redeemed voucher portion — credit note [CN#] voided; offsetted by debit note [DN#]".

Record all journal entry IDs in the support ticket and your change log.

### Step 6 — Verify GL balance

After posting all entries, verify:

1. `VoucherLiability` balance for this voucher's company is not negative.
2. `SalesReturnsClearing` net movement reconciles against the original credit note.
3. The corrective debit note (Step 2) and GL entries (Step 5) produce a net-zero impact on the fiscal period's retained earnings.

Escalate to your external accountant or auditor if the reconciliation does not balance.

### Step 7 — Mark the credit note as voided in the system

Once the manual GL is complete:

1. Re-trigger the credit note void from the back-office. With the vouchers now manually set to `Voided`, the cascade check will pass and the system void will proceed.
2. Confirm the credit note status is `Voided` in the ERP.
3. Archive this runbook execution (ticket reference, journal entry IDs, approver) in your audit log.

---

## Escalation

If you are uncertain about any step, or if the amounts do not reconcile, escalate to:

1. **Finance manager** — authorise the corrective debit note.
2. **External auditor** — validate the GL correction for fiscal compliance.
3. **ERP Engineering** — if you suspect a system bug or if the amounts are unexpectedly large.

---

## Phase 1.1 automation

Phase 1.1 will introduce a `VoucherFiscalCorrection` workflow that automates Steps 2–5 above:

- The system will calculate redeemed vs. unredeemed amounts automatically.
- It will generate the corrective debit note and GL entries in a single atomic transaction.
- Two-person review will be enforced via the existing four-eyes approval mechanism.
- This runbook will be retired once Phase 1.1 ships.

See the project roadmap for the Phase 1.1 target date.
