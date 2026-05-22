# Phase 3 Task 3 Codex Self-Adversarial Review

## Verdict

APPROVE.

Implementation commit reviewed: `cdb313545 Phase 3.3.1: Mirror customer credit controls`.

Task 3 adds the Phase 3 customer mirror credit-control fields needed for offline charge-to-account eligibility checks:

- `credit_limit`
- `payment_terms_days`
- `charge_account_enabled`
- `charge_policy_version`

The fields now flow from the server customer sync resource into the POS sync service and local SQLite customer mirror without weakening the existing tenant/company isolation.

## TDD Evidence

Red checks observed before implementation:

- `cd apps/pos && pnpm test -- customerRepository.test.ts customerSyncService.test.ts`
  - Expected failure: repository row did not persist `credit_limit`; assertion saw `undefined` instead of `500.000`.
- `cd apps/api && APP_KEY=... ./vendor/bin/phpunit tests/Feature/POS/PosCustomerSyncControllerTest.php --filter phase_three`
  - Expected failure: sync payload returned `credit_limit: null` instead of `500.0000`.

Green checks after implementation:

- `cd apps/pos && pnpm test -- customerRepository.test.ts customerSyncService.test.ts CustomerAttachPanel.test.tsx CustomerSearchInput.test.tsx`
  - 6 files passed, 29 tests passed.
- `cd apps/api && APP_KEY=... ./vendor/bin/phpunit tests/Feature/POS/PosCustomerSyncControllerTest.php`
  - 8 tests, 49 assertions.

Full pre-commit verification:

- `cd apps/api && APP_KEY=... ./vendor/bin/phpunit tests/Feature/Fiscal/ tests/Unit/Fiscal/ tests/Feature/POS/`
  - 1173 tests, 3999 assertions, 107 skipped, 2 incomplete.
- `cd apps/api && APP_KEY=... ./vendor/bin/phpstan analyse --level=8 app/Modules/POS/Presentation/Resources/PosCustomerMirrorResource.php app/Modules/POS/Presentation/Controllers/PosCustomerSyncController.php tests/Feature/POS/PosCustomerSyncControllerTest.php`
  - No errors.
- `cd apps/api && ./vendor/bin/pint --test app/Modules/POS app/Modules/Fiscal tests/Feature/POS tests/Feature/Fiscal tests/Unit/Fiscal`
  - PASS.
- `cd apps/pos && pnpm test`
  - 165 files passed, 1461 tests passed.
- `cd apps/pos && pnpm typecheck && pnpm lint`
  - Typecheck passed. Lint exited 0 with 41 existing warnings and 0 errors.
- `bash apps/api/scripts/check-saleReceipt-chokepoints.sh && bash apps/pos/scripts/check-pass-2b-pending.sh`
  - PASS.

An intentionally overbroad PHPStan run across all POS/Fiscal app and test paths reported pre-existing Fiscal static-analysis issues unrelated to Task 3. The touched-path PHPStan gate passed.

## Standing Pattern Review

### Cross-Tenant FK / Scope Safety

APPROVE.

No new server-side FK lookup was introduced. `PosCustomerMirrorResource` only serializes fields from the already-selected customer row. The existing POS customer sync controller path remains scoped by tenant/company through the current authenticated POS context and `X-Company-Id` request handling.

On the device, `upsertCustomer` still requires `tenant_id`, `company_id`, and `id`, and still uses `ON CONFLICT(tenant_id, company_id, id)`. The pre-existing drift guard remains live: a matching customer id under the same tenant but different company fails loudly before write. The new credit fields are extra payload columns only; they do not alter lookup scope or conflict keys.

### Fail-Loud Over Silent Downgrade

APPROVE.

The implementation does not add silent fallback paths. Required scope assertions remain. Server sync mismatch handling remains covered by existing customer sync tests. The repository normalizes `charge_account_enabled` to `1` or `0` before persistence, which is appropriate for SQLite storage and does not mask tenant/company drift.

### Dead-Path Rebuild

APPROVE.

Each new field has a live caller path:

- Server: `PosCustomerMirrorResource` emits the new fields in `/api/v1/pos/customers/sync`.
- POS sync: `pullCustomers` passes the resource row into `upsertCustomer` unchanged.
- SQLite mirror: migration version 41 adds the fields and repository upsert writes them.
- Reads/search/UI: `CustomerMirrorRow` now requires the fields, forcing mirror consumers and tests to account for them.

This is not a dead schema addition.

### Contract Drift

APPROVE.

Backend resource assertions, POS TypeScript shape, repository persistence tests, and sync-service pass-through tests agree on the four fields. The Task 3 field names match the Phase 3 plan vocabulary. The one semantic choice to watch is `charge_account_enabled`: it currently mirrors `is_active` because no independent credit-policy switch exists yet. That is explicit in code behavior and should be revisited if a separate server-side account-charge policy field is introduced.

### D16 Bounded-Modules Guard

APPROVE.

Task 3 is inbound mirror data only. No POS fiscal engine, projector, or customer mirror code imports Treasury, Accounting, Documents, or B2B modules. There is no outbound operational dependency from the engine layer.

### Rule 13 / Constructor Injection

APPROVE.

No production `app()`, `App::make`, or `resolve()` usage was added. Task 3 touched a resource, tests, POS repository, migrations, and UI/test type adapters only.

### Discriminated-Union Matrix

NOT APPLICABLE.

Task 3 did not introduce a new discriminated-union DTO. Existing sync result handling remains unchanged.

### Skip Hygiene

APPROVE.

No `markTestSkipped` or class-level skips were introduced.

### CI / Chokepoints

APPROVE.

No new PG-only fiscal test class or chokepoint script behavior was introduced. Existing sale receipt chokepoint and Pass 2B pending sentinels pass.

## Residual Risk

The only residual design risk is that `charge_account_enabled` is currently derived from `is_active`, not an independent credit-control flag. That is acceptable for Task 3 as a mirror plumbing step, but Task 4 or later eligibility work must fail closed if future policy metadata is absent or stale.
