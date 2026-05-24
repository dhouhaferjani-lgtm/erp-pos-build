# Phase 3 Task 3 Opus Second-Pass Review

Implementation commit reviewed: `cdb313545ae91f89cf0e5c65e1c09c853ca548a2` (`Phase 3.3.1: Mirror customer credit controls`)

Codex self-review challenged: `docs/superpowers/reviews/2026-05-21-task-3-codex-review.md`

Verdict: REQUEST-CHANGES.

Task 3 is close on the narrow server-sync-to-SQLite mirror path, and I did not find a tenant/company scope regression or a new outbound Treasury/Accounting/B2B/Document dependency. However, the local migration currently creates an unsafe missing-policy fallback for already-cached customers. Task 3 is not cleared until that is corrected and covered.

## Findings

### 1. REQUEST-CHANGES: v41 backfills pre-existing local customers as charge-enabled before any real policy mirror exists

`apps/pos/src/lib/db/migrations.ts:1197`-`1200` adds the new local customer columns, but `charge_account_enabled` is `INTEGER NOT NULL DEFAULT 1`.

That default applies to every customer row already present from migration v39 before the next `/pos/customers/sync` pull. Those legacy rows then read as:

- `charge_account_enabled = 1`
- `charge_policy_version = NULL`
- `credit_limit = NULL`
- `payment_terms_days = NULL`

This violates the fail-loud/fail-closed requirement for missing policy data. It also makes the Codex self-review's statement that "The implementation does not add silent fallback paths" (`docs/superpowers/reviews/2026-05-21-task-3-codex-review.md:61`-`65`) too strong: v41 silently turns unknown policy into enabled policy for existing device mirrors.

Required fix:

- Change the migration/backfill behavior so rows that have not received a real server mirror policy are not charge-enabled. The lowest-risk shape is `charge_account_enabled INTEGER NOT NULL DEFAULT 0`, with server sync still explicitly writing `1` for eligible rows.
- Add a migration/repository test that applies migrations through v40, inserts a customer, runs v41, and asserts the pre-existing row is not charge-enabled unless a sync upsert supplies the field.
- Keep `charge_policy_version` null for missing policy, and make the later eligibility path fail closed on null/unknown policy version.

### 2. MINOR: UI adapter defaults can hide whether selected customers carried real mirror policy

`apps/pos/src/components/customers/CustomerAttachPanel.tsx:134`-`146` constructs a `CustomerMirrorRow` shape from `selectedCustomer` only to call `isBalanceStale()`, and fills `credit_limit`, `payment_terms_days`, `charge_account_enabled`, and `charge_policy_version` with synthetic defaults. This does not currently authorize charge-to-account, so it is not the same severity as finding 1.

The problem is that it normalizes an attached customer's policy shape to "phase3-v1 / enabled" even when the attached checkout snapshot does not carry those fields at all. If Task 4 or Task 5 reuses `AttachedCheckoutCustomer` for credit decisions, this becomes a dead-path or unsafe-default trap.

Required follow-up before eligibility authoring:

- Either carry the mirrored credit fields into `AttachedCheckoutCustomer` when `fromMirror()` runs (`apps/pos/src/components/customers/CustomerAttachPanel.tsx:21`-`35`), or keep credit eligibility reading directly from the local mirror row by tenant/company/id.
- Avoid using synthetic enabled/default policy values except in tests that explicitly assert they are placeholders outside the eligibility path.

## Axis Review

### Cross-Tenant / Customer Scope

No regression found. Server sync remains scoped by authenticated tenant and company in `apps/api/app/Modules/POS/Presentation/Controllers/PosCustomerSyncController.php:33`-`42`. Local writes still use the composite key `(tenant_id, company_id, id)` and the drift guard still rejects a same-tenant/same-customer UUID under a different company before upsert (`apps/pos/src/lib/db/repositories/customerRepository.ts:47`-`65`). Reads/searches remain tenant/company scoped (`apps/pos/src/lib/db/repositories/customerRepository.ts:127`-`164`).

### Server Sync Query

No issue found. The implementation adds fields only in `PosCustomerMirrorResource`; it does not weaken the controller query. `PartnerType::Customer` and `PartnerType::Both` remain the only included partner types.

### `charge_account_enabled` Semantics

Mapping `charge_account_enabled` from `is_active` at `apps/api/app/Modules/POS/Presentation/Resources/PosCustomerMirrorResource.php:37` matches the Task 3 plan text (`docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md:487`-`494`) and is acceptable as a temporary mirror-plumbing proxy because no independent server account-charge policy field exists in this task.

It must not become the final eligibility policy. Older customer-account design separates active partner status from account policy/defaults (`docs/superpowers/specs/2026-05-13-pos-customer-accounts-design-v1.1.md:189`-`213`). Task 4 must treat inactive customers and charge-disabled policy as separate failure reasons, and must fail closed when policy metadata is absent or stale.

### Local Type And Persistence

The repository normalizes `true`/`1` to SQLite `1` and everything else to `0` (`apps/pos/src/lib/db/repositories/customerRepository.ts:45`), and writes/updates all four new columns (`apps/pos/src/lib/db/repositories/customerRepository.ts:67`-`113`). Search/read paths return `SELECT *`, so the new fields are readable.

Coverage is slightly thin: repository tests prove the `true -> 1` path but do not read back `false` or numeric `0`. That is not blocking once finding 1 is fixed, but adding a false/0 readback assertion would better support the self-review's normalization claim.

### Migration Idempotency / Order

Version order is correct: v41 follows v39 customer mirror and v40 pending customer tables (`apps/pos/src/lib/db/migrations.ts:1125`-`1214`). Duplicate-column handling follows the existing local pattern. The unsafe part is the default `1`, not SQLite idempotency.

### D16 Bounded Modules And Rule 13

No Task 3 bounded-module violation found. The touched production path does not add hard Treasury, Accounting, B2B, or Document imports. I also found no new production `app()`, `App::make()`, or `resolve()` usage in the Task 3 diff.

### Dead-Path Rebuild

The main mirror path is live: backend resource emits the fields (`apps/api/app/Modules/POS/Presentation/Resources/PosCustomerMirrorResource.php:35`-`38`), sync passes rows to `upsertCustomer()` (`apps/pos/src/lib/customer/customerSyncService.ts:131`-`143`), and SQLite stores/reads them.

The attached checkout snapshot does not yet carry those fields, so eligibility work must not assume the selected customer already has them. That is a Task 4/5 integration risk, not a Task 3 scope blocker by itself.

### Test / Verification Evidence

The self-review's cross-tenant and scoped-query claims are supported by code inspection. The fail-loud claim is overstated because it misses the v41 default-1 backfill path. The "Reads/search/UI" claim (`docs/superpowers/reviews/2026-05-21-task-3-codex-review.md:71`-`76`) is partly true for repository reads/searches, but the UI adapter currently uses synthetic credit-policy defaults rather than preserving real mirror fields into the selected checkout customer.

I did not rerun the full test suite for this second-pass review; this review is based on commit diff and source inspection.
