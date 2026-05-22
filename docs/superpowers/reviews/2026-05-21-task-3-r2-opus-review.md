# Phase 3 Task 3 R2 Opus Second-Pass Review

Implementation commits reviewed:

- `cdb313545ae91f89cf0e5c65e1c09c853ca548a2` (`Phase 3.3.1: Mirror customer credit controls`)
- `8bb8c35a7500e463a34b8f45f84c202421b07bff` (`Phase 3.3.2: Fail closed on missing charge policy`)

Original Opus review challenged:

- `docs/superpowers/reviews/2026-05-21-task-3-opus-review.md`

Codex R2 self-review challenged:

- `docs/superpowers/reviews/2026-05-21-task-3-r2-codex-review.md`

Verdict: APPROVE.

Task 3 is cleared after R2. I did not find a remaining blocker in the missing-policy default, migration replay coverage, attached-customer propagation, pending-create handling, tenant/company scope, bounded-module surface, or Rule 13 surface.

## Findings

No blocking or important findings.

One non-blocking deployment note: R2 mutates migration v41 rather than adding a v42 repair migration. That is acceptable for this unmerged feature branch because the reviewed upgrade path is v40-era customer rows receiving the final v41 definition. If any real device had already run the pre-R2 `cdb313545` v41 migration, production's `_migrations` guard would skip the corrected v41 on the next boot (`apps/pos/src/lib/db.ts:97`-`114`), and that device would need an explicit repair migration. I am not treating this as a Task 3 blocker because there is no evidence the unsafe v41 shipped beyond this review branch.

## R2 Finding Closure

### Original Finding 1: v41 backfills existing customers as charge-enabled

Closed.

Migration v41 now adds `charge_account_enabled` as `INTEGER NOT NULL DEFAULT 0` (`apps/pos/src/lib/db/migrations.ts:1197`-`1200`). That is the fail-closed shape the original review requested: pre-policy rows are disabled until a server sync writes explicit policy fields.

The new replay test is valid for the real branch upgrade path. It creates a fresh SQLite adapter, applies migrations through v40, inserts a customer using the v39/v40-era schema, runs only v41, and asserts `charge_account_enabled = 0` with `charge_policy_version = null` (`apps/pos/src/lib/db/repositories/__tests__/customerRepository.test.ts:141`-`174`). It does not accidentally re-run earlier migrations after the legacy row is inserted.

SQLite behavior is also correct here: adding a `NOT NULL` column with constant `DEFAULT 0` gives existing rows a readable zero value and makes future raw inserts default to zero when the column is omitted.

### Original Finding 2: UI adapter synthetic enabled policy

Closed.

`AttachedCheckoutCustomer` now carries `credit_limit`, `payment_terms_days`, `charge_account_enabled`, and `charge_policy_version` (`apps/pos/src/stores/paymentStore.ts:129`-`145`). `CustomerAttachPanel.fromMirror()` preserves those fields from the mirror row (`apps/pos/src/components/customers/CustomerAttachPanel.tsx:21`-`38`).

Pending local customer creation now attaches fail-closed policy fields: null credit/terms, disabled charge account, and null policy version (`apps/pos/src/components/customers/CustomerAttachPanel.tsx:114`-`130`). The balance-staleness adapter no longer fabricates `charge_account_enabled = 1` or `phase3-v1`; it spreads the selected customer and only fills repository metadata needed by `isBalanceStale()` (`apps/pos/src/components/customers/CustomerAttachPanel.tsx:142`-`150`).

## Axis Review

### Migration Replay / Idempotency

Approved. The replay test's v40-to-v41 setup exercises the intended legacy-row upgrade path. v41's duplicate-column handling remains consistent with local migration patterns (`apps/pos/src/lib/db/migrations.ts:1203`-`1211`). The only caveat is the branch-only migration mutation note above.

### Default 0 / NOT NULL Semantics

Approved. `DEFAULT 0` prevents unknown policy from becoming enabled for existing rows, and `NOT NULL` still has a valid constant default for SQLite. Fresh mirror rows inserted through the repository use the explicit server value after normalization (`apps/pos/src/lib/db/repositories/customerRepository.ts:42`-`45`, `apps/pos/src/lib/db/repositories/customerRepository.ts:67`-`113`).

### Attached Customer / Account Payment Type Drift

Approved. The new required fields were propagated to the store type, panel attach path, account-payment test fixtures, and payment-store fixtures. `ACCOUNT_PAYMENT` authoring still serializes only its existing payload customer subset, so the added snapshot fields do not drift the canonical account-payment payload (`apps/pos/src/lib/offline/accountPaymentService.ts:128`-`151`, `apps/pos/src/lib/offline/accountPaymentService.ts:227`-`262`).

### Pending Create / ACCOUNT_PAYMENT

Approved. Pending-created customers are now fail-closed for future charge eligibility but remain valid for existing `ACCOUNT_PAYMENT` behavior. The account-payment service still allows `customer_sync_status = pending_create` and only gates stale alias conflicts when an expected server alias is supplied (`apps/pos/src/lib/offline/accountPaymentService.ts:128`-`136`, `apps/pos/src/lib/offline/accountPaymentService.ts:269`-`277`).

### Tenant / Company Scope

Approved. R2 does not weaken scope. Customer repository writes still guard same-tenant cross-company drift before upsert (`apps/pos/src/lib/db/repositories/customerRepository.ts:47`-`65`), reads/searches remain tenant/company scoped (`apps/pos/src/lib/db/repositories/customerRepository.ts:117`-`164`), and checkout account-payment still rejects mismatched attached customer scope (`apps/pos/src/stores/paymentStore.ts:550`-`575`).

### Mirror Contract / Key Regression

Approved. Server sync still emits the four policy fields (`apps/api/app/Modules/POS/Presentation/Resources/PosCustomerMirrorResource.php:33`-`39`), the sync service still asserts tenant/company scope before upsert (`apps/pos/src/lib/customer/customerSyncService.ts:131`-`143`), and the local repository persists the fields without changing the composite mirror key.

### D16 / Rule 13

Approved. R2 adds no new production `app()`, `App::make()`, or service-container `resolve()` usage in the reviewed diff, and no new Treasury, Accounting, B2B, or Document dependency is introduced by the R2 changes.

### Fail-Loud / Fail-Closed Before Task 4

Approved for Task 3. Task 3 no longer creates an enabled local policy when policy is missing. Task 4 must still implement eligibility as the enforcement point and fail closed on disabled `charge_account_enabled`, null/unknown `charge_policy_version`, stale mirror state, and insufficient credit.

## Verification

I reproduced the R2 focused POS verification:

- `cd apps/pos && pnpm test -- customerRepository.test.ts customerSyncService.test.ts CustomerAttachPanel.test.tsx CustomerSearchInput.test.tsx accountPaymentService.test.ts paymentStore.customerAttach.test.ts`
  - 8 files passed, 41 tests passed.
- `cd apps/pos && pnpm typecheck`
  - Exited 0.

I did not rerun the full API/POS gate listed in the Codex R2 self-review. The focused evidence relevant to this R2 fix is accurate in this worktree.
