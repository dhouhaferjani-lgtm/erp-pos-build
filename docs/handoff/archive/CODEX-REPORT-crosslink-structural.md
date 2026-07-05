# Cross-Link Structural Tier Report

Date: 2026-07-02
Branch: `feat/crosslink-structural`
Commit: not created; orchestrator owns commits.

## Scope

Implemented the four structural cross-linking/navigability deliverables enumerated in the user prompt. The referenced audit file was not present at `docs/superpowers/audits/2026-07-02-cross-linking-navigability-audit.md` in this worktree; a sibling copy was later found at `../erp/docs/superpowers/audits/2026-07-02-cross-linking-navigability-audit.md` and checked against this scope.

Broader Phase 2 audit items that were not part of the enumerated request remain out of scope for this change, including location detail pages, batch movement ledgers, vehicle related-record tabs, supplier-side partner tabs beyond documents/payments pagination, and goods receipt canonical URLs.

## Changes

- Added tab deep-linking for product and partner detail pages. `?tab=` is read on load, tab clicks write the search param, and browser back/forward stays in sync.
- Extended `entityRoutes` so product, variant, customer, supplier, partner, and document routes can receive an optional tab.
- Added stock movement source-document provenance to the API response as `source_document_id` and `source_document_type`.
- Rendered stock movement references as `EntityLink` when the movement points at a document-backed source; otherwise the reference remains plain text.
- Updated journal entry source provenance so document-backed source types link to their documents and non-document source types render as plain badges.
- Added server-backed pagination to related-record tabs:
  - `ProductMovementsTab`
  - `ProductDocumentsTab`
  - Partner documents tab
  - Partner payments tab
- Added paged response support to document, stock movement, and payment APIs when callers pass `page`; existing unpaged behavior is preserved when `page` is omitted.

## Tests Added/Updated

- `apps/api/tests/Feature/Inventory/StockMovementTest.php`
  - Verifies stock movement list responses expose source document provenance.
- `apps/web/src/lib/entityRoutes.test.ts`
  - Verifies optional tab params in entity routes.
- `apps/web/src/features/inventory/ProductDetailPage.test.tsx`
  - Verifies product tabs read and write `?tab=`.
- `apps/web/src/features/partners/partners.test.tsx`
  - Verifies partner tabs read and write `?tab=`.
- `apps/web/src/features/inventory/components/__tests__/ProductMovementsTab.test.tsx`
  - Verifies document links and paged movement requests.
- `apps/web/src/features/inventory/components/__tests__/ProductDocumentsTab.test.tsx`
  - Verifies paged document requests.
- `apps/web/src/features/finance/pages/JournalEntryDetailPage.test.tsx`
  - Verifies document-backed journal source links and non-document badges.

## Verification

Run commands:

```bash
cd apps/api && php artisan test tests/Feature/Inventory/StockMovementTest.php
pnpm --filter @autoerp/web test -- src/lib/entityRoutes.test.ts src/features/inventory/ProductDetailPage.test.tsx src/features/partners/partners.test.tsx src/features/inventory/components/__tests__/ProductMovementsTab.test.tsx src/features/inventory/components/__tests__/ProductDocumentsTab.test.tsx src/features/finance/pages/JournalEntryDetailPage.test.tsx
pnpm --filter @autoerp/web typecheck
pnpm --filter @autoerp/web audit:keys
pnpm --filter @autoerp/web exec eslint src/features/finance/pages/JournalEntryDetailPage.test.tsx src/features/finance/pages/JournalEntryDetailPage.tsx src/features/inventory/ProductDetailPage.test.tsx src/features/inventory/ProductDetailPage.tsx src/features/inventory/StockMovementsPage.tsx src/features/inventory/components/ProductDocumentsTab.tsx src/features/inventory/components/ProductMovementsTab.tsx src/features/inventory/components/__tests__/ProductDocumentsTab.test.tsx src/features/inventory/components/__tests__/ProductMovementsTab.test.tsx src/features/partners/PartnerDetailPage.tsx src/features/partners/partners.test.tsx src/lib/entityRoutes.test.ts src/lib/entityRoutes.ts
```

Notes:

- Vitest passes for the touched frontend test set.
- PHP feature test passes for the touched backend path.
- TypeScript typecheck passes.
- TanStack query-key audit passes with the existing acknowledged baseline.
- ESLint exits successfully with warnings only, mostly existing Tailwind color utility warnings in `PartnerDetailPage.tsx`.
