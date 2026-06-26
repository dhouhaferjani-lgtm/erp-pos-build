# R-5 Opus Pre-Review

Opus was invoked headlessly as an adversarial pre-review for the R-5 converter
prepayment-clearing remediation:

```sh
perl -e 'alarm shift; exec @ARGV' 120 claude --safe-mode --model opus -p ...
```

The process exited with code 142 after the 120 second alarm and produced no
review output.

Local pre-review conclusion:

- `GeneralLedgerService::clearCustomerAdvanceToReceivable()` throws
  `InvalidArgumentException` when the clearing amount is non-positive or exceeds
  the available customer advance.
- `SalesOrderToInvoiceConverter::transferPrepayments()` intentionally treats GL
  clearing failures as non-fatal degradation, but currently catches only
  `RuntimeException`.
- Changing the converter catch boundary is the narrowest fix because the GL
  guard semantics can remain strict for direct callers while the conversion
  workflow preserves its documented graceful degradation path.
- Regression coverage should prove the invoice conversion still commits,
  transfers the payment allocation, records `gl_entry_skipped`, and creates no
  `prepayment_application` journal entry when the guard rejects an over-cap
  clearing.
- Invoice/media attachment wiring remains out of scope while unified media
  management is in transition.
