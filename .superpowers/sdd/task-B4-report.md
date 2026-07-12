# Task B4 Report — Treasury drift and maturity in-app alerts

## Status

Complete. The reconcile and maturity commands now deliver their existing alerts to the tenant notification center without weakening freeze or audit behavior.

## Requirements reviewed

- Plan Global Constraints and Task B4.
- Binding design specification §6.3, §10, and §15 amendment A-3.
- Plan-review findings H2, M1, and M2.

## Files

- Modified `apps/api/app/Modules/Treasury/Presentation/Console/ReconcileTreasuryCommand.php`.
- Modified `apps/api/app/Modules/Treasury/Presentation/Console/InstrumentMaturityAlertsCommand.php`.
- Extended `apps/api/tests/Feature/Treasury/ReconcileTreasuryTest.php`.
- Extended `apps/api/tests/Feature/Treasury/InstrumentMaturityAlertsTest.php`.
- Updated `.superpowers/sdd/progress.md`.

No recipient-resolver, notification class, movement port, fiscal-perimeter, frontend, or unrelated module file was changed.

## TDD evidence

1. Reconcile RED:

   `./vendor/bin/phpunit tests/Feature/Treasury/ReconcileTreasuryTest.php --filter='drift_freeze_notifies|notification_send_failure|portfolio_drift_notification'`

   Exit 2: 3 tests errored as expected. Drift and portfolio notification rows did not exist, and notification failure did not emit the required `treasury.reconcile.alert_failed` notification-channel record.

2. Maturity RED:

   `./vendor/bin/phpunit tests/Feature/Treasury/InstrumentMaturityAlertsTest.php --filter='maturity_alert_sends'`

   Exit 2: actionable delivery errored because no notification row existed. The zero-count test was already green before production changes; it became the anti-spam regression pin while the actionable RED drove implementation.

3. Initial focused GREEN:

   Reconcile: `OK (3 tests, 15 assertions)`.

   Maturity: `OK (2 tests, 7 assertions)`.

4. Full focused-file GREEN before final formatting:

   Reconcile: `OK (20 tests, 88 assertions)`.

   Maturity: `OK (6 tests, 27 assertions)`.

## Contract coverage

- Cash drift: one database notification is sent to an active `treasury.manage` holder in the repository company, with the company, repository deep link, severity, repository code, and drift reason. A manager belonging only to another company receives nothing.
- Freeze isolation: the freeze is written first; log, audit, and notification remain independent channels. A notification send failure leaves the repository frozen, preserves the drift audit row, and records `treasury.reconcile.alert_failed` with `channel=notification` through the existing exact repository helper signature.
- Portfolio drift: managers receive a company-scoped warning linked to `/finance/overview`. Notification failure uses a repository-less failure logger, preserves the already-written portfolio audit event, and does not turn the command into a per-company check error.
- Maturity: each recipient receives one aggregate notification per company per run, never one per instrument. Payload includes the configured window and both actionable counts with `/treasury/instruments?maturing=1`.
- Zero-count guard: the audit event retains its existing unconditional cadence, while notification delivery is gated on the sum of both counts being positive.
- Recipient resolution remains entirely inside `TreasuryAlertRecipients`; team selection, company membership filtering, and Spatie cache handling were not duplicated in either command.

## Verification

- `./vendor/bin/phpunit tests/Feature/Treasury/ReconcileTreasuryTest.php`: `OK (20 tests, 88 assertions)`.
- `./vendor/bin/phpunit tests/Feature/Treasury/InstrumentMaturityAlertsTest.php`: `OK (6 tests, 29 assertions)`.
- `php -d memory_limit=1G ./vendor/bin/phpstan --no-progress`: `[OK] No errors`.
- Focused Pint invocation: `{"result":"pass"}`.
- `git diff --check`: exit 0 with no output.

## Deviations

None.

## Concerns

None.
