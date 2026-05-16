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

## Owner Sign-Off (2026-05-16)

The gate's purpose is to guarantee that no real fiscal data exists which the clean rebuild would destroy. The owner attests to the deployment state below in lieu of a remote command run; the deployment state makes a destructive scan unnecessary because no production deployment carries real merchant fiscal data.

**Grounding for this attestation:** `project_pos_go_live_checklist` records the POS go-live as merged locally 2026-05-12 with the pre-deployment checklist still carrying TBDs, the physical-terminal restore drill pending, and no smoke pass on a live terminal completed. No production merchant deployment of POS has happened.

### Server Surface

- **Environment — Production (`api.riserpos.app`, branch `main`, Dokploy container `erp-prod-api-ecl8o8`):** owner attests no merchant deployment of POS exists. Any `pos_receipts` / `pos_z_reports` / `pos_terminals` chain state present is pre-launch test data created during integration work and is acceptable to lose in the clean rebuild.
- **Environment — Staging (`api.erp.otospex.dev`, branch `dev`, Dokploy container `erp-staging-api-kghqex`):** owner attests staging carries only test data from `dev`-branch development and is acceptable to lose in the clean rebuild.
- **Result:** clear (no real merchant fiscal data exists; test data is acceptable to lose).
- **Archival/export path:** not required — no data needs preservation.
- **Verification method:** owner attestation in lieu of a remote command run; the deployment state recorded in `project_pos_go_live_checklist` plus owner knowledge of merchant onboarding state makes the destructive scan unnecessary. A retroactive command run on either environment remains available if a finding later contradicts this attestation.

### Device Surface

- **Terminal inventory:** **no deployed Tauri terminals exist.** No merchant-installed devices are in the field. The physical-terminal restore drill recorded as "pending" in `project_pos_go_live_checklist` confirms no terminal has been provisioned to a live merchant.
- **Result:** no deployed terminals.
- **Archival/export path:** not required.

### Web-POS Surface

- **Live receipt-creation path detected:** confirmed — `POST /api/v1/pos/receipts`, `POST /api/v1/pos/receipts/{id}/payments`, `POST /api/v1/pos/orders/{id}/close` are registered in `apps/api/app/Modules/POS/routes.php` and `routes_orders.php`, reached from `apps/web` per the §14.3 chokepoint enumeration.
- **Disposition decision:** **disable per §14.2 in Task 29** as planned in the spec. The web-POS new-sale path is not in production use by any merchant today; disabling it before the device-authority chain comes online (clean rebuild) is the correct order per `[SoT D8]` ("one fiscal pattern, no two-model coexistence"). Web-POS device-authority parity is deferred (§18 open item).
- **Knowingly retained:** `void` / `processReturn` paths remain server-side for Phase 1 — their event types (`SALE_VOID`, `REFUND_RECEIPT`, `PARTIAL_REFUND`) are Phase 2+ reserved.

### Signature

- **Owner:** otospexsolutions (project owner)
- **Signed at:** 2026-05-16

## Gate

**OPEN.** Schema-destructive Tasks 7–13 and 28–30 are cleared to proceed on the basis of this written owner attestation. If any later verification surfaces real merchant fiscal data that this attestation did not account for, work stops and an archival/export path is defined before continuing.
