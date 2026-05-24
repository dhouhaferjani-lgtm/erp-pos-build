# Phase 3 Task 3 R2 Codex Self-Adversarial Review

## Verdict

APPROVE.

Implementation commits reviewed:

- `cdb313545 Phase 3.3.1: Mirror customer credit controls`
- `8bb8c35a7 Phase 3.3.2: Fail closed on missing charge policy`

R2 addresses the Opus `REQUEST-CHANGES` finding in `docs/superpowers/reviews/2026-05-21-task-3-opus-review.md`.

## R2 Change Summary

- Changed POS migration v41 so existing mirrored customers receive `charge_account_enabled = 0`, not `1`, until a real customer sync writes charge-policy fields.
- Added a migration replay test: run through v40, insert a v39/v40-era customer row, run only v41, and assert `charge_account_enabled = 0` with `charge_policy_version = null`.
- Extended `AttachedCheckoutCustomer` to carry the mirrored credit-policy fields.
- Updated `CustomerAttachPanel.fromMirror()` to preserve real mirror fields.
- Changed pending local customer creation to attach `charge_account_enabled = false` and `charge_policy_version = null`.
- Removed synthetic enabled policy defaults from the balance-staleness adapter.

## Verification Evidence

Focused R2 checks:

- `cd apps/pos && pnpm test -- customerRepository.test.ts customerSyncService.test.ts CustomerAttachPanel.test.tsx CustomerSearchInput.test.tsx accountPaymentService.test.ts paymentStore.customerAttach.test.ts`
  - 8 files passed, 41 tests passed.
- `cd apps/pos && pnpm typecheck`
  - Exited 0.

Full pre-commit gate:

- `cd apps/api && APP_KEY=... ./vendor/bin/phpunit tests/Feature/Fiscal/ tests/Unit/Fiscal/ tests/Feature/POS/`
  - 1173 tests, 3999 assertions, 107 skipped, 2 incomplete, 16 deprecations.
- `cd apps/api && APP_KEY=... ./vendor/bin/phpstan analyse --level=8 app/Modules/POS/Presentation/Resources/PosCustomerMirrorResource.php app/Modules/POS/Presentation/Controllers/PosCustomerSyncController.php tests/Feature/POS/PosCustomerSyncControllerTest.php`
  - No errors.
- `cd apps/api && ./vendor/bin/pint --test app/Modules/POS app/Modules/Fiscal tests/Feature/POS tests/Feature/Fiscal tests/Unit/Fiscal`
  - PASS.
- `cd apps/pos && pnpm test`
  - 165 files passed, 1462 tests passed.
- `cd apps/pos && pnpm typecheck && pnpm lint`
  - Exited 0. Lint reported the existing 41 warnings, 0 errors.
- `bash apps/api/scripts/check-saleReceipt-chokepoints.sh && bash apps/pos/scripts/check-pass-2b-pending.sh`
  - PASS.

## Opus Finding Closure

### Finding 1: v41 Backfills Existing Customers As Charge-Enabled

APPROVE.

The blocking path is fixed. Migration v41 now adds:

```sql
charge_account_enabled INTEGER NOT NULL DEFAULT 0
```

The new replay test proves a customer inserted before v41 remains disabled and has no policy version after v41 runs. Fresh sync rows still explicitly write `true`/`1` when the server resource supplies enabled policy.

### Finding 2: UI Adapter Synthetic Defaults

APPROVE.

The checkout customer snapshot now carries the mirrored credit-policy fields directly. `fromMirror()` preserves the real values from `CustomerMirrorRow`. Locally pending customers attach as fail-closed: no credit limit, no terms, `charge_account_enabled = false`, and no policy version.

The `isBalanceStale()` adapter no longer fabricates `phase3-v1` or enabled policy. It only supplies repository metadata fields unrelated to credit eligibility (`is_active`, `sync_version`, `updated_at`, `synced_at`) so the stale-balance helper can keep its existing shape.

## Standing Pattern Review

### Cross-Tenant FK / Scope Safety

APPROVE.

No tenant/company scope was weakened in R2. The migration default changes only a local column default. Repository conflict scope remains `(tenant_id, company_id, id)`. The drift guard remains live. The checkout customer type extension adds fields but does not introduce any lookup.

### Fail-Loud / Fail-Closed

APPROVE.

The missing-policy path now fails closed at the mirror level:

- Existing rows after migration: disabled + null policy version.
- Pending local customers: disabled + null policy version.
- Synced rows: policy fields must arrive through customer sync.

Task 4 must still enforce eligibility fail-closed when policy metadata is null, stale, or unknown, but Task 3 no longer creates an enabled default for missing policy.

### Dead-Path Rebuild

APPROVE.

The fields are live across:

- Server sync resource.
- Sync service pass-through.
- SQLite schema and repository upsert/read.
- Checkout selected-customer state.
- UI tests for synced and pending customer attach.

### Contract Drift

APPROVE.

R2 aligns the mirror contract and checkout snapshot. `charge_account_enabled` is still derived from server-side `is_active` for Task 3, matching the current plan’s temporary mirror-plumbing scope. It is not treated as sufficient final eligibility policy.

### D16 Bounded Modules

APPROVE.

No Treasury, Accounting, B2B, or Document dependency was added. This remains inbound mirror data only.

### Rule 13

APPROVE.

No production `app()`, `App::make`, or `resolve()` usage was added.

### Skip Hygiene

APPROVE.

No new skips were added.

## Residual Risk

Task 4 is the next real policy-enforcement point. It must fail closed on null `charge_policy_version`, disabled `charge_account_enabled`, stale mirrors, and insufficient credit. Task 3 now supplies the data shape needed for that without pre-authorizing existing mirrors.
