# System Architecture Audit

## Scope & Certification Targets
AutoERP is positioned as a certification-ready ERP for automotive and retail workflows with a roadmap toward NF525 and other fiscal seals, pairing Laravel 12 + React as codified in `README.md:34-76`. CLAUDE.md reiterates the governing principles: strict hexagonal layering, event-first persistence, schema-per-tenant Postgres, and a two-tier hash chain for fiscal/audit trails (`CLAUDE.md:132-232`). This audit confirms how those principles surface in the current codebase and highlights readiness gaps before NF525 validation.

## Monorepo Layout & Contracts
The pnpm workspace hosts three runnable apps (`apps/api`, `apps/web`, `apps/mobile`) plus shared types at `packages/shared` so every surface relies on a single domain contract. The shared package exports generated DTOs (e.g., `packages/shared/types/generated.ts:1-8`) for consumption by the web/mobile clients, while the PHP DTOs serve as the upstream truth via `php artisan typescript:transform` referenced across docs/FRONTEND.

## Backend Service Layer (Laravel)
Modules under `apps/api/app/Modules` all share `Domain`, `Application`, `Presentation`, and (when needed) `Infrastructure` folders, matching the port/adapter layout described in CLAUDE.md. Document-critical entities (e.g., `App\Modules\Document\Domain\Document.php`) expose tenant, fiscal hash, and chaining fields along with typed enums and relationships, ensuring auditability at the aggregate level (`apps/api/app/Modules/Document/Domain/Document.php:22-187`). Service classes such as `CompanyContext` centralize tenant/company scoping and enforce schema isolation before any query is made (`apps/api/app/Modules/Company/Services/CompanyContext.php:11-98`). Route entry points are kept thin: `routes/api.php` wires Sanctum-guarded prefixes and then defers to each module’s Presentation layer for feature-specific routing (`apps/api/routes/api.php:17-52`).

Treasury illustrates the pattern end-to-end: HTTP controllers validate and authorize requests, pivot through CompanyContext to locate tenant schemas, and marshal domain models (`apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentMethodController.php:11-115`). Each handler returns DTO-shaped payloads to keep frontend contracts predictable. Similar scaffolding exists for Partner, Document, Identity, and Inventory modules, while Sales/Accounting folders are stubbed but empty—highlighting upcoming work before full NF525 coverage.

## Backend Interaction Flow Example
1. A user initiates a payment-method change from the UI.
2. The request hits `/api/v1/payment-methods/{id}` protected by Sanctum and permission middleware (routes loaded from `apps/api/app/Modules/Treasury/Presentation/routes.php:23-152`).
3. `PaymentMethodController::update` validates request data, scopes the query by tenant/company, and persists the changes atomically (`PaymentMethodController.php:63-119`).
4. The controller formats the domain model into a serializable array aligning with the shared DTO expectations before returning JSON.
5. Any downstream posting (posting invoices, GL, etc.) would emit events via the domain services noted in CLAUDE.md’s event-first diagram, ensuring the fiscal hash chain stays intact.

## Frontend Web Client
The web client follows an atomic-derived structure: foundational UI atoms/molecules in `src/components/ui` and `src/components/molecules`, domain-level experiences in `src/features/*`, and routing within `src/routes`. SearchInput, for instance, is declared once in the molecules layer with translation-aware UX and then re-exported for consumers (`apps/web/src/components/molecules/SearchInput/SearchInput.tsx:1-70`, `apps/web/src/components/ui/SearchInput.tsx:1-2`). Screens leverage React Router + Suspense for module boundaries and permission gating (`apps/web/src/routes/index.tsx:1-200`). Minimal client state is held in persisted Zustand stores (`apps/web/src/stores/authStore.ts:1-72`), while server data is fetched through TanStack Query hooks inside features like `DocumentListPage` (`apps/web/src/features/documents/DocumentListPage.tsx:1-199`).

A centralized Axios client (`apps/web/src/lib/api.ts:1-167`) layers Sanctum headers, company-context headers, and consistent error handling so every feature shares the same contract and metadata expectations defined in CLAUDE.md. Translations are namespace-based JSON files (e.g., `apps/web/src/locales/en/common.json:1-64`), enabling multi-lingual readiness for Europe/North Africa rollouts. Some legacy screens (Document list tab labels, etc.) still embed English literals, so a sweep is required to finish the “no hardcoded text” rule before NF525 reviews.

## Client ↔ Server Interaction Example
For Document browsing:
1. `DocumentListPage` derives the document type from routing, renders atomic components (SearchInput, FilterTabs), and invokes a TanStack Query keyed by document type/status (`apps/web/src/features/documents/DocumentListPage.tsx:68-199`).
2. The hook calls `api.get` which funnels through the shared API helper enforcing headers and error normalization (`apps/web/src/lib/api.ts:62-167`).
3. The Laravel route `/api/v1/{document-type}` (declared inside each module’s route file) routes to controllers that hydrate Document read models, ensuring fiscal metadata like hashes and balances are present because the underlying entity stores them (`apps/api/app/Modules/Document/Domain/Document.php:22-123`).
4. Results flow back through the shared DTO shape so React components can format totals, statuses, and call translation keys without guessing field names.

## Shared Packages & Mobile Edge
`packages/shared` packages the generated DTOs and helper utilities so React web, React Native (`apps/mobile`) and any desktop shell can import identical types (`packages/shared/package.json:1-23`). The Expo app mirrors the same folder taxonomy (`apps/mobile/src/{components,features,lib,providers}`), keeping parity for future POS/tablet experiences demanded by NF525 once offline signing is introduced.

## Compliance & Observations
- Fiscal hash chain data already exists on the Document aggregate, satisfying CLAUDE.md’s Tier-1 requirement; Tier-2 audit events still need a persisted store implementation.
- Multi-tenancy is enforced through middleware + CompanyContext, aligning with schema-based isolation rules.
- Treasury/payment APIs expose the country-agnostic switches (physical, maturity, third-party, fee plan) directly on the controller contracts, demonstrating the universal payment model that NF525 mandates (`apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentMethodController.php:46-111`). Automated tests for split payments or event emission are still pending.
- Sales/Accounting modules are scaffolded yet empty; without those services NF525 auditors will flag missing posting workflows, Z-report generation, and immutable journal flows.
- Frontend atomic layering is in place, yet some lists still hardcode strings (“Quotes”, “Invoices”) instead of translation keys.

## Next Steps Toward Certification
1. **Finish domain implementations** for Sales, Accounting, and Event Store modules so posting, GL, and fiscal closures emit immutable events.
2. **Backfill automated tests** (PHPUnit, Pest, Vitest, Playwright) covering fiscal hash verification, payment life cycles, and translation enforcement.
3. **Complete localization** by replacing the remaining literals with `t('...')` keys and populating FR/AR namespaces.
4. **Prototype NF525 POS flows** (receipt chain, Z-reporting, offline sealing) using the existing hash services as building blocks.
5. **Extend shared DTO coverage**—`packages/shared/types/generated.ts:1-8` currently exposes only pagination helpers; run the transformer for all DTOs.
