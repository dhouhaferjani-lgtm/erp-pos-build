# H-1 Codex Adversarial Review — AR Partner Tagging

Item: H-1 — production invoice/credit-note AR lines omit `partner_id`.

Review package: `.superpowers/review-packages/h1-ar-partner-id.diff`.

## Findings

### LOW — Non-AR lines were not asserted to remain partnerless

The initial patch asserted that invoice and credit-note AR lines are partner-tagged, but did not assert that revenue/VAT lines remain untagged. H-1 scope is the AR subledger; tagging unrelated revenue or VAT lines would broaden partner subledger semantics.

Evidence:
- Invoice revenue/VAT assertions are in `apps/api/tests/Feature/Accounting/InvoiceAndCreditNoteGLIntegrationTest.php`.
- Production code leaves invoice revenue/VAT lines without `partner_id` in `apps/api/app/Modules/Accounting/Application/Services/AccountingService.php`.
- Production code leaves credit-note revenue/VAT reversal lines without `partner_id`.

Disposition: fixed. The integration test now asserts invoice revenue/VAT lines and credit-note revenue/VAT reversal lines have `partner_id = null`.

## Result

No BLOCKER/HIGH/MED correctness findings. AR tagging is correct and scoped to customer receivable lines.

