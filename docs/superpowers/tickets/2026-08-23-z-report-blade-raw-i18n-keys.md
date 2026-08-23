# Ticket — the Z-report PDF printed raw i18n keys (FIXED in the B-6(ii) lane; recorded here for the audit trail)

> **Found by:** B-6(ii) implementation lane (`fix/b6ii-xz-refund-vat-display`), 2026-08-23.
> **Status:** FIXED in that lane's server commit. This ticket records the finding, its blast radius and the deviation from strict lane scope.

## The finding

`apps/api/resources/views/pos/z-report.blade.php` has referenced ~20 `__('pos.z_report_*')` keys since it was written. **None of them were ever defined** in `lang/en/pos.php`, `lang/fr/pos.php`, or anywhere else — verified by grep across `apps/api/lang` and `apps/api/resources/lang`.

Laravel's `__()` returns the key itself when no translation exists, so the **fiscal Z-report PDF a customer or an auditor receives was rendering literal strings like:**

```
pos.z_report_sales_summary
pos.z_report_gross_sales:        119.00 EUR
pos.z_report_net_sales:          100.00 EUR
pos.z_report_tax_amount:          19.00 EUR
```

`__('pos.generated_by')` was in the same state.

It went unnoticed because `ZReportPdfTest` asserts on **values** (`'119.00 EUR'`, `'211.00 EUR'`), never on labels — so the suite was fully green with the labels broken.

## Why it was fixed inside the B-6(ii) lane rather than deferred

B-6(ii) adds three new VAT lines to this exact section of this exact view. Adding them correctly (rule 11: user-facing text via a translation key with en+fr) would have produced a PDF where the three NEW lines render as words and every neighbouring line renders as a dotted key — visibly worse than either extreme, and certain to be flagged in review.

The lane is also explicitly *correctness-before-first-client*, and a fiscal document printing i18n keys is not shippable.

**Deviation acknowledged:** strict lane scope (agent rule 4) would have added only the five new keys. The backfill of the ~20 pre-existing ones is additive-only — no existing key is redefined, no code path changes, no test asserted on the old raw-key output — and is carried in the same commit as the disclosure work.

## What changed

`apps/api/lang/en/pos.php` and `apps/api/lang/fr/pos.php` gained a clearly-delimited Z-report block:

- the backfill: `generated_by`, `z_report`, `z_report_number`, `z_report_z_number`, `z_report_genesis`, `z_report_sales_summary`, `z_report_receipt_count`, `z_report_gross_sales`, `z_report_net_sales`, `z_report_tax_amount`, `z_report_refunds`, `z_report_voided`, `z_report_average_ticket`, `z_report_cash_summary`, `z_report_opening_cash`, `z_report_expected_cash`, `z_report_actual_cash`, `z_report_variance`, `z_report_vat_rate`, `z_report_vat_net`, `z_report_vat_amount`, `z_report_vat_gross`, `z_report_payment_method`, `z_report_payment_count`, `z_report_payment_amount`;
- B-6(ii)'s own: `z_report_vat_on_sales`, `z_report_vat_on_refunds`, `z_report_net_vat`, `z_report_vat_net_of_refunds`, `z_report_refund_vat_breakdown`, `z_report_vat_unreconciled`.

`ZReportRefundVatDisclosureTest::test_pdf_view_data_carries_the_disclosure_and_renders_it()` now asserts `assertStringNotContainsString('pos.z_report_', $html)` — no raw key may reach a fiscal document again.

## Open follow-ups (NOT done here)

1. **`lang/ar/pos.php` does not exist** (only `lang/ar/treasury.php` does). Arabic tenants get the English strings for the whole `pos` namespace, Z-report included. That predates this lane and is a whole-namespace decision, not a Z-report one.
2. **No guard exists** for `__('key')` references with no matching lang entry on the PHP side. `apps/web` has `tools/audit-i18n-completeness.mjs` + `scripts/i18n-baseline-authority.sh`; the Blade/`lang/` side has no equivalent, which is exactly why this survived. Worth a small census + CI check.
3. **Other Blade views may have the same hole.** Only `pos/z-report.blade.php` was examined; the census was not widened (rule 4).
