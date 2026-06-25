# R-5 Opus Review

Opus was invoked headlessly as an adversarial post-review for the implemented
R-5 diff:

```sh
perl -e 'alarm shift; exec @ARGV' 120 claude --safe-mode --model opus -p ...
```

The process exited with code 142 after the 120 second alarm and produced no
review output.

Local adversarial review:

- The production change is scoped to `SalesOrderToInvoiceConverter` and leaves
  `GeneralLedgerService::clearCustomerAdvanceToReceivable()` strict for direct
  callers.
- The converter already treats GL clearing failures as non-fatal and records
  `gl_entry_skipped`; catching `InvalidArgumentException` preserves that
  workflow for GL guard failures such as an already-cleared advance.
- The regression test creates a real posted customer advance, clears it, then
  converts an order with a stale allocation and verifies the conversion commits,
  the allocation moves to the invoice, the invoice balance reflects the
  transferred prepayment, the skip reason is recorded, and no new
  `prepayment_application` GL entry is created for the invoice.
- The test file cleanup removes redundant assertions and fixes a misplaced
  helper PHPDoc so touched-file PHPStan can run cleanly.
- Invoice/media attachment wiring is untouched.

No issues found in the local review.
