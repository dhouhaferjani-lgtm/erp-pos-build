# Task 04 R2 Opus Second-Pass Review — POS Customer Mirror Page Cursor

## Verdict

APPROVE.

I found no request-changes or blocker issue in `1b3610987 Phase 2.4.2: Page POS customer mirror sync`.

## Scope Reviewed

- R2 commit: `1b3610987 Phase 2.4.2: Page POS customer mirror sync`
- Original Task 4 commit: `f9023476c Phase 2.4.1: Sync POS customer mirror`
- Prior Opus review: `docs/superpowers/reviews/2026-05-21-task-04-opus-review.md`
- R2 Codex review: `docs/superpowers/reviews/2026-05-21-task-04-r2-codex-review.md`
- Touched production files:
  - `apps/api/app/Modules/POS/Presentation/Controllers/PosCustomerSyncController.php`
  - `apps/pos/src/lib/customer/customerSyncService.ts`
- Touched tests:
  - `apps/api/tests/Feature/POS/PosCustomerSyncControllerTest.php`
  - `apps/pos/src/lib/customer/__tests__/customerSyncService.test.ts`

## Findings

None.

## Review Notes

### R1 P1 cursor skip

Resolved. The backend now over-fetches by one row (`limit + 1`) at `apps/api/app/Modules/POS/Presentation/Controllers/PosCustomerSyncController.php:44-50`, returns `has_more`, and emits a continuation cursor from the last returned row only when another row exists (`:59-65`). The POS client loops until `has_more` is false and persists `customers.updated_since` only on the exhausted page (`apps/pos/src/lib/customer/customerSyncService.ts:131-158`).

The same-`updated_at` boundary is handled by the composite predicate:

- `updated_at > cursor_timestamp`
- or `updated_at = cursor_timestamp AND id > cursor_id`

That is implemented at `apps/api/app/Modules/POS/Presentation/Controllers/PosCustomerSyncController.php:157-164` and covered by `test_pos_customer_sync_returns_composite_continuation_cursor_for_full_pages()` at `apps/api/tests/Feature/POS/PosCustomerSyncControllerTest.php:132-184`.

### Duplicate/infinite-loop risk

Pass for the actual server/client pair. Server-side `has_more` can only be true after a non-empty over-fetched result with `limit >= 1`, because `parseLimit()` rejects zero and caps positive limits (`apps/api/app/Modules/POS/Presentation/Controllers/PosCustomerSyncController.php:123-140`). The continuation cursor is the exact ordered tuple of the last returned page row (`updated_at`, `id`), and the next query excludes that tuple while including later rows at the same timestamp. That avoids both boundary skips and repeat-page loops for responses produced by this endpoint.

### Cursor advancement and partial failures

Pass. Intermediate page cursors stay in memory only (`apps/pos/src/lib/customer/customerSyncService.ts:157-158`). `setSyncMetadata()` runs only after a terminal page (`:147-154`). If a later page request, response parse, scope validation, or upsert fails, the stored `customers.updated_since` is not advanced; already-upserted earlier rows are replayed idempotently on retry rather than skipped. The multi-page test asserts a single metadata write after the second page (`apps/pos/src/lib/customer/__tests__/customerSyncService.test.ts:148-197`).

### Malformed cursor handling

Pass. Server rejects malformed continuation request state: `updated_since_id` without `updated_since`, array-shaped IDs, and non-UUID IDs all abort with 422 (`apps/api/app/Modules/POS/Presentation/Controllers/PosCustomerSyncController.php:95-120`; tests at `apps/api/tests/Feature/POS/PosCustomerSyncControllerTest.php:207-225`). The POS client fails loudly when `has_more` is missing, when a `has_more: true` response omits either continuation cursor, or when `synced_at` is absent (`apps/pos/src/lib/customer/customerSyncService.ts:64-115`; tests at `apps/pos/src/lib/customer/__tests__/customerSyncService.test.ts:199-246`).

### Tenant/company safety

Pass. The backend query remains tenant/company scoped before type filtering (`apps/api/app/Modules/POS/Presentation/Controllers/PosCustomerSyncController.php:39-42`). The POS client validates every row in a page before any upsert (`apps/pos/src/lib/customer/customerSyncService.ts:137-143`). The R2 mixed-page test catches future row-by-row drift by placing a valid row before a foreign-tenant row and asserting no upsert and no metadata write (`apps/pos/src/lib/customer/__tests__/customerSyncService.test.ts:128-146`).

### D16 bounded-module guard / CLAUDE rule 13

Pass. The R2 production diff stays inside the POS presentation endpoint and POS local customer sync client. It does not introduce Treasury, B2B, Customers, or Accounting operational dependencies, and does not add `app()`, `resolve()`, or service-locator usage in production. The only `app()` observed in the touched R2 area is existing test setup through Spatie permission state (`apps/api/tests/Feature/POS/PosCustomerSyncControllerTest.php:49`), not production code.

### Contract drift

Pass. The Task 3 backend envelope remains compatible with the POS `apiGet()` unwrapping model: the endpoint returns Laravel top-level `data`, and the client parses the unwrapped object. The customer row fields emitted by `PosCustomerMirrorResource` (`apps/api/app/Modules/POS/Presentation/Resources/PosCustomerMirrorResource.php:24-39`) still match `CustomerMirrorRow` (`apps/pos/src/lib/customer/customerTypes.ts:1-17`). R2 adds pagination fields to the sync response and updates the Task 4 parser to require them, so stale Task 3-style responses fail loudly rather than silently reverting to the unsafe one-page behavior.

## Verification

- `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Feature/POS/PosCustomerSyncControllerTest.php` from `apps/api` — passed, 7 tests / 43 assertions.
- `pnpm test -- customerSyncService.test.ts` from `apps/pos` — passed, 1 file / 8 tests.

