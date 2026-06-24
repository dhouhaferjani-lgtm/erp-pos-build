# R-9 Codex Review

Scope reviewed:

- `.github/workflows/ci.yml`
- `apps/api/tests/Feature/Document/AgedReceivablesScalingTest.php`
- `apps/api/tests/Feature/Treasury/PaymentAllocationPrecisionTest.php`
- `apps/api/tests/Feature/Partner/PartnerMoneyPrecisionTest.php`

Findings:

- No blocking issues found.
- The CI PG-only invariant filter now includes both precision suites named by
  the remediation worklist.
- The statement precision test now fails under a float accumulator
  (`10000000000.999`) while the BCMath path returns the expected
  `10000000001.000`.
- The first attempted large fixture crossed SQLite's local numeric precision
  boundary; the final repeated-addition fixture avoids that false failure.
- Invoice/media attachment wiring is untouched.

Verification:

- `php artisan test tests/Feature/Document/AgedReceivablesScalingTest.php --filter=customer_statement_running_balance_preserves_third_decimal_for_tnd`
  passed with 3 assertions.
- `php artisan test tests/Feature/Document/AgedReceivablesScalingTest.php tests/Feature/Treasury/PaymentAllocationPrecisionTest.php tests/Feature/Partner/PartnerMoneyPrecisionTest.php`
  passed 7 tests and skipped 5 PG-only checks locally on SQLite.
- `./vendor/bin/phpstan analyse --level=8 tests/Feature/Document/AgedReceivablesScalingTest.php tests/Feature/Treasury/PaymentAllocationPrecisionTest.php tests/Feature/Partner/PartnerMoneyPrecisionTest.php --memory-limit=1G`
  reported no errors.
- `./vendor/bin/pint --test tests/Feature/Document/AgedReceivablesScalingTest.php tests/Feature/Treasury/PaymentAllocationPrecisionTest.php tests/Feature/Partner/PartnerMoneyPrecisionTest.php`
  passed.
- `ruby -e 'require "yaml"; YAML.load_file(".github/workflows/ci.yml"); puts "ci yaml ok"'`
  passed.
- `git diff --check` passed.
