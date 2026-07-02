# CODEX Report: Import Mapping and Draft Document 500

Date: 2026-07-02
Branch: `fix/import-mapping-and-draft-500`
Commit: not created; orchestrator owns commits.

## Summary

- Aligned the frontend import wizard target columns with backend `ImportType` required and optional columns.
- Fixed partner import mapping drift by replacing frontend-only fields such as `tax_id` and `address_line1` with backend-supported fields such as `vat_number`, `address`, `city`, and `country`.
- Audited the other import type target lists and added `stock_levels` support to the frontend import type union and card icon map.
- Made skipped import columns visually distinct from mapped columns and suppressed green suggested checks when a suggestion points to an unsupported backend target.
- Added a mapping-step notice for template/source columns that will be skipped unless mapped.
- Made generic document DTO `document_number` nullable for draft expenses and regenerated shared TypeScript types.
- Made draft-capable frontend document number renderers null-safe with a translated draft placeholder.

## TDD Evidence

Backend red, before the DTO fix:

```text
FAIL  Tests\Feature\Document\ListDocumentsTest
Expected response status code [200] but received 500.
TypeError: App\Modules\Document\Application\DTOs\DocumentData::__construct():
Argument #13 ($document_number) must be of type string, null given
```

Backend green, after the DTO fix:

```text
php artisan test tests/Feature/Document/ListDocumentsTest.php --filter=generic_documents_list_allows_draft_expenses_without_document_number
PASS  Tests\Feature\Document\ListDocumentsTest
✓ generic documents list allows draft expenses without document number
Tests: 1 passed (4 assertions)
```

Frontend red, before mapper/null-safe fixes:

```text
ColumnMapper unsupported suggestion still showed "(suggested)" with a mapped/green state.
Skipped-column notice was absent.
Null document numbers did not render the draft placeholder in document/product document lists.
```

Frontend green, after fixes:

```text
pnpm --filter @autoerp/web test -- src/features/import/components/ColumnMapper.test.tsx src/features/documents/DocumentListPage.test.tsx src/features/inventory/components/__tests__/ProductDocumentsTab.test.tsx
Test Files 3 passed
Tests 21 passed
```

## Verification

```text
CACHE_STORE=array php artisan typescript:transform
Transformed 361 PHP types to TypeScript
Exit code: 0
```

```text
php artisan test tests/Feature/Document/ListDocumentsTest.php tests/Feature/Import
Tests: 85 passed (342 assertions)
Exit code: 0
```

```text
pnpm --filter @autoerp/web test -- src/features/import src/features/documents/DocumentListPage.test.tsx src/features/inventory/components/__tests__/ProductDocumentsTab.test.tsx
Test Files 5 passed
Tests 40 passed
Exit code: 0
```

```text
pnpm --filter @autoerp/web typecheck
Exit code: 0
```

```text
pnpm --filter @autoerp/web test:arch
[sweep-progress] Gate C -- useQuery/useQueries/queryClient queryKeys without an approved tenant scope: 14
Exit code: 0
```

```text
./vendor/bin/phpstan analyse app/Modules/Document/Application/DTOs/DocumentData.php --memory-limit=1G
[OK] No errors
Exit code: 0
```

```text
pnpm --filter @autoerp/web exec eslint <changed frontend files>
0 errors, warnings only
Exit code: 0
```

Notes:

- The focused Vitest run still prints the existing `--localstorage-file` warning and React `act(...)` warnings from `src/features/import/__tests__/tenantScope.test.tsx`; the command exits 0.
- Changed-file ESLint exits 0 but reports existing warning-class rules in touched legacy files, mostly hardcoded Tailwind token warnings and memoization/compiler warnings.

## Files Changed

- Backend DTO/test: `DocumentData.php`, `ListDocumentsTest.php`.
- Generated types: `packages/shared/types/generated.d.ts`.
- Import wizard/mapping: `ImportWizardPage.tsx`, `ColumnMapper.tsx`, `ColumnMapper.test.tsx`, import types, import i18n.
- Null-safe document number rendering: document list/header/related docs, product documents tab, partner detail, dashboard, expense detail/form/card, payment prefill references.
- Translations added for English, French, and Arabic draft placeholder and skipped-column notice.
