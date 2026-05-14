# Fiscal Preflight Sign-Off

Date: 2026-05-14

Plan: POS Phase 1 Fiscal Event Engine, Task 1

## Command

```bash
php artisan fiscal:preflight-gate
```

## Local Developer Run

Status: failed closed because the local PostgreSQL role is not available.

```text
SERVER SURFACE: unable to verify - database query failed
DEVICE SURFACE: requires manual inventory - record per-terminal SQLite findings in the sign-off
WEB-POS SURFACE: live receipt-creation path detected - disposition per section 14.2
SQLSTATE[08006] [7] connection to server at "127.0.0.1", port 5433 failed: FATAL:  role "autoerp" does not exist
```

## TDD Evidence

Red run before command/provider implementation:

```text
./vendor/bin/phpunit tests/Feature/Fiscal/PreflightFiscalGateCommandTest.php

ERRORS!
Tests: 2, Assertions: 3, Errors: 2.
Symfony\Component\Console\Exception\CommandNotFoundException: The command "fiscal:preflight-gate" does not exist.
```

Green run after implementation:

```text
./vendor/bin/phpunit tests/Feature/Fiscal/PreflightFiscalGateCommandTest.php

OK (2 tests, 5 assertions)
```

Green run after Task 01 Opus review P2 test-coverage edit:

```text
./vendor/bin/phpunit tests/Feature/Fiscal/PreflightFiscalGateCommandTest.php

OK (6 tests, 19 assertions)
```

## Required Owner Sign-Off

Owner sign-off is not provided by this implementation task. The owner must run the command against each staging/production tenant PostgreSQL database and complete all sections below before schema-destructive Tasks 7-13 and 28-30 start.

### Server Surface

- Environment:
- Command output:
- Result: clear / non-empty / unable to verify
- If non-empty, archival/export path:

### Device Surface

Record every deployed Tauri terminal's SQLite store, including `offline_receipts`, `terminal_state`, `z_reports`, and pending-sync rows. If no deployed terminals exist, record that fact explicitly.

- Terminal inventory:
- Result: clear / non-empty / no deployed terminals
- If non-empty, archival/export path:

### Web-POS Surface

The command detects the live `POST /api/v1/pos/receipts` receipt-creation route. The owner must record the section 14.2 disposition decision before destructive schema work starts.

- Note: route detection is conservative. It proves the web-POS receipt-creation route is registered; it does not by itself prove active web-terminal usage.
- Disposition decision:
- Owner:
- Signed at:

## Gate

Schema-destructive work remains blocked until written owner sign-off covers server, device, and web-POS surfaces.
