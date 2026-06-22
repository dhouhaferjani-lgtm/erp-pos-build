# H-7.2 Voucher Ledger Posting — Codex Adversarial Review

## Scope

Reviewed the H-7.2 diff for voucher ledger GL lifecycle hardening:

- `GeneralLedgerService::createVoucherLedgerEntry()`
- `VoucherIssuanceServiceTest::test_issue_from_refund_creates_voucher_ledger_and_g_l_entry()`
- H-7 progress notes in the work-list and coordination file

Review lens: correctness of posting lifecycle, actor attribution, fiscal hash creation, and unintended behavior changes for voucher events.

## Findings

No BLOCKER/HIGH/MEDIUM findings.

## Checks

- The red assertion covers the production refund-voucher issuance path that creates a `voucher_ledger` journal entry, and now verifies `Posted`, `posted_by`, `posted_at`, and `fiscal_hash`.
- `voucher_ledger.user_id` is required by the tenant migration and model PHPDoc, so resolving the `User` before entry creation is consistent with schema expectations.
- Posting happens after the journal header and lines are created, through the existing canonical helper used by other operational GL writers.
- Partially redeemed and unwired voucher events retain their existing early exceptions; this change does not broaden voucher event account wiring.
- Ledger currency is passed into the posting helper, avoiding ambient company-context currency assumptions.

## Residual Risk

This item does not address the remaining H-7 draft writers (`cogs`, `batch_write_off`). Those remain explicitly pending under H-7.
