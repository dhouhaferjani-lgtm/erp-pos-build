# L-3 Centralize Net-Balance Formula — Codex Review

## Scope

Review target: partner net-balance formula reuse across backend DTO/list sorting and frontend partner list display.

Acceptance criteria:
- Backend formula has one source of truth.
- Frontend consumes API DTO value where possible.
- Formula behavior for both-type partners remains covered.

## Diff Reviewed

- `apps/api/app/Modules/Partner/Domain/Partner.php`
- `apps/api/app/Modules/Partner/Presentation/Controllers/PartnerController.php`
- `apps/api/app/Modules/Partner/Application/DTOs/PartnerData.php`
- `apps/api/tests/Feature/Partner/PartnerBalanceListTest.php`
- `apps/web/src/features/partners/PartnerListPage.tsx`
- `apps/web/src/features/partners/partnerNetBalance.ts`
- `apps/web/src/features/partners/partners.test.tsx`
- `apps/web/src/features/partners/__fixtures__/partner.ts`
- `packages/shared/types/generated.d.ts`

## Findings

No BLOCKER, HIGH, MEDIUM, or LOW findings.

The backend now centralizes the PHP accessor calculation and SQL list expression on `Partner`, with `PartnerController` consuming the model-provided SQL expression rather than embedding a duplicate CASE statement. `PartnerData` exposes `net_balance`, and the generated TypeScript type includes it. The frontend helper now prefers the API-provided decimal string and keeps fallback math on decimal-string helpers instead of adding float coercion.

## Verification Reviewed

- Red observed first: `php artisan test tests/Feature/Partner/PartnerBalanceListTest.php --filter test_balance_fields_appear_in_list_response` failed because `net_balance` was absent from the list response.
- Red observed first: `pnpm --filter @autoerp/web test -- src/features/partners/partners.test.tsx -t "uses API net balance"` failed because the helper recomputed `650` instead of using API `700.000`.
- Green after implementation: partner backend tests passed 22 tests, 80 assertions.
- Frontend partner test file passed 43 tests, with pre-existing localstorage and `/partners/1` route warnings.
- Web typecheck passed.
- Scoped frontend ESLint passed with 0 errors and pre-existing warnings.
- `CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync php artisan typescript:transform` completed and transformed 351 PHP types.
- PHPStan L8 on changed partner app files passed.
- Pint and `git diff --check` passed.

