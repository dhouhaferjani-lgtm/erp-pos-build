# Claude Code Fix Instructions

## 0. Reference Material
- User bug list: `docs/bugs/bugs1.md:1-53` (treat each phase as blocking work).
- Architecture guardrails: `CLAUDE.md` and `docs/ARCHITECTURE-AUDIT.md`.

## 1. Global Localization & Enum Mapping
1. Replace every hardcoded sidebar/page-title/table-header string with i18n keys. Start with `apps/web/src/features/documents/DocumentListPage.tsx:32-187` and the dashboard/sidebar components.
2. Implement a centralized status mapper so enum values like `Draft`, `Active`, `Inactive` resolve to translation keys instead of raw strings. Add helpers (e.g., `mapStatusToLabel`) and wire them into list/table components and badge renders.

## 2. CRM & Sales UX Improvements
1. **Context-Aware Partner Type:** Update the customer/supplier creation flow so the Type field defaults (and optionally hides) based on the route (`/sales/customers` → Customer, `/purchases/suppliers` → Supplier). Touch the partner form component plus related Zustand/React Query hooks in `apps/web/src/features/partners`.
2. **Product Combobox Empty State:** Adjust the quote line item product selector to fetch recent/popular products when the dropdown opens (no typing required). Show a "Recent Products" header before typeahead results.
3. **Pricing Defaults:** After a product is chosen, automatically fill Unit Price and Tax fields using backend pricing/tax data (list price, margin rules, region tax). Ensure overwrites stay editable but never default to 0 unless the SKU is actually free.
4. **In-Flow Product Creation:** Add a "Create new" control in the product dropdown footer that opens the existing "Add Product" modal. On success, close the modal, keep the user on the quote, and pre-select the newly created product.

## 3. Order Management & Flow Logic
1. **Ghost Error on Quote Save:** Debug the quote save mutation (likely under `apps/web/src/features/documents` or `.../sales`) so it correctly handles API responses even when 204/empty. Add optional chaining when reading response payloads and ensure success/error toasts are mutually exclusive.
2. **Sales Order Deposits:** In the Sales Order detail page, add a "Register Payment" button for confirmed orders. Calling it should open a payment dialog or redirect with pre-filled references so deposits can be recorded prior to invoicing.
3. **Order→Delivery Conversion Feedback:** When conversions fail, show precise errors (stock/credit) instead of silent blocks. Audit the backend state transition logic and surface domain errors to the UI.

## 4. Invoicing & Treasury Context Preservation
1. Rename ambiguous "Post" buttons on invoices to translated strings such as `t('invoices.actions.postToLedger')` so end users know it finalizes the document.
2. Refactor the "Record Payment" action to keep invoice context: use a modal or propagate invoice ID/amount/partner via URL state, pre-fill those fields, and always return the user to the originating invoice after save/cancel.
3. Fix `403` errors from creating payment repositories: align backend policies (`apps/api/app/Modules/Treasury/Presentation/routes.php:23-152`) with the UI and hide "Add Repository" when `can:repositories.manage` is missing.

## 5. Additional Issues Identified During Audit
1. **Document List i18n debt:** Besides the user-reported strings, `apps/web/src/features/documents/DocumentListPage.tsx:32-187` defines `typeLabels`, `statusLabels`, and header text in English. Replace them with translation keys and update the locale JSON files (`apps/web/src/locales/*/*.json`).
2. **Shared DTO Regeneration:** `packages/shared/types/generated.ts:1-8` only exposes pagination helpers. Run `php artisan typescript:transform` from `apps/api`, commit the regenerated `.ts` files, and refactor frontend stores/components that currently declare their own document/payment interfaces.
3. **Sales & Accounting Modules:** Directories exist (`apps/api/app/Modules/Sales`, `apps/api/app/Modules/Accounting`) but lack implementations. Build the domain/application/presentation layers so quotes/orders/invoices can post to the ledger per `CLAUDE.md` requirements, and add Pest/PHPUnit coverage for posting + GL flows.
4. **Tier-2 Audit/Event Store:** CLAUDE.md mandates a two-tier hash system; only Tier-1 fields live on `Document`. Implement the audit event store (TimescaleDB-backed) under `app/Shared` so every domain event is persisted with hash metadata and verification tooling.
5. **Treasury Tests:** The Treasury controllers already expose the universal payment switches (`apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentMethodController.php:46-111`), but there are no integration tests. Add HTTP + domain tests validating permissions, fee calculations, and split-payment workflows.

## 6. Delivery Expectations
- Follow TDD (tests first) and run `./scripts/preflight.sh` before completion.
- Update docs (README/CLAUDE or feature docs) when UX or workflow changes, especially for payment context preservation and new dropdown behaviors.
