# R-9 Opus Review

Opus was invoked headlessly as an adversarial post-review for the implemented
R-9 diff:

```sh
perl -e 'alarm shift; exec @ARGV' 120 claude --safe-mode --model opus -p ...
```

The process exited with code 142 after the 120 second alarm and produced no
review output.

Local adversarial review:

- The `backend-test-pgsql` filter now includes `PaymentAllocationPrecisionTest`
  and `PartnerMoneyPrecisionTest`, so their PG-only schema and constraint
  assertions are no longer invisible to CI.
- The TND customer-statement behavioral test no longer relies on a single
  ordinary amount. Its repeated mill-level fixture distinguishes BCMath
  accumulation from PHP float accumulation while staying below SQLite's local
  precision boundary.
- `PartnerMoneyPrecisionTest` no longer relies on dynamic DB row properties,
  keeping the now-CI-visible test clean under PHPStan.
- Invoice/media attachment wiring is untouched.

No issues found in the local review.
