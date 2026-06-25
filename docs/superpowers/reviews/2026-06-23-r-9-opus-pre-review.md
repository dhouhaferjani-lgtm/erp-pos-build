# R-9 Opus Pre-Review

Opus was invoked before edits with an adversarial review prompt for the R-9
test-quality and CI-coverage plan.

Command shape:

```bash
perl -e 'alarm shift; exec @ARGV' 120 claude --safe-mode --model opus -p "..."
```

Result: timed out after 120 seconds with exit code 142 and no output.

Planned scope after local verification:

- Add `PaymentAllocationPrecisionTest` and `PartnerMoneyPrecisionTest` to the
  `backend-test-pgsql` PHPUnit filter so their PG-only information_schema and
  pg_constraint assertions run in CI.
- Strengthen the TND customer statement running-balance test from a single
  ordinary amount to a repeated mill-level fixture. The first attempted
  large-plus-small value crossed SQLite's local numeric precision boundary,
  so the final fixture uses `10000000000.001` plus 999 one-mill invoices:
  BCMath expects `10000000001.000`, while a local PHP float sanity check
  produced `10000000000.999`.

Invoice/media attachment wiring remains deferred while unified media
management is in transition.
