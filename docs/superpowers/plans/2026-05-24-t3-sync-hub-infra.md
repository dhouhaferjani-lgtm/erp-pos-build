# T3 Sync Hub Infrastructure Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the adapter-agnostic multi-channel sync hub shared infrastructure with no production concrete channel adapters.

**Architecture:** Add a `Channel` backend module with hexagonal adapter contracts, tenant-scoped Eloquent entities, queue-backed dispatch and webhook ingest services, and an adapter registry that starts empty. Add an adapter-agnostic React admin feature that renders the no-adapters state and routes to five operational pages.

**Tech Stack:** Laravel 12, PHP 8.3 strict typing, Eloquent tenant migrations, queue jobs, React 19, TanStack Query, Vite, Vitest, react-i18next.

---

### Task 1: Backend Contracts and Registry

**Files:**
- Create: `apps/api/app/Modules/Channel/Application/Contracts/ChannelAdapter.php`
- Create: `apps/api/app/Modules/Channel/Application/Contracts/ChannelSignatureStrategy.php`
- Create: `apps/api/app/Modules/Channel/Application/DTOs/ConnectionTestResult.php`
- Create: `apps/api/app/Modules/Channel/Application/DTOs/OrderStatusUpdateDTO.php`
- Create: `apps/api/app/Modules/Channel/Application/DTOs/PriceUpdateDTO.php`
- Create: `apps/api/app/Modules/Channel/Application/DTOs/StockUpdateDTO.php`
- Create: `apps/api/app/Modules/Channel/Application/DTOs/SyncResult.php`
- Create: `apps/api/app/Modules/Channel/Application/Services/AdapterRegistry.php`
- Test: `apps/api/tests/Unit/Channel/AdapterRegistryTest.php`

- [ ] **Step 1: Write failing registry tests**

Run: `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= php artisan test tests/Unit/Channel/AdapterRegistryTest.php`
Expected: FAIL because `AdapterRegistry` and contracts do not exist.

- [ ] **Step 2: Implement contracts and registry**

Implement `ChannelAdapter` exactly as the T3 spec describes, with product/variant/mapping dependencies typed to existing model classes where available and `object|null` fallback only for missing T2 variant class. Implement `AdapterRegistry::register`, `resolve`, `has`, and `listRegistered`; `resolve` throws `RuntimeException` with "No adapter registered for type X. Concrete adapters ship in a follow-up sprint." for missing adapter types.

- [ ] **Step 3: Verify green**

Run: `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= php artisan test tests/Unit/Channel/AdapterRegistryTest.php`
Expected: PASS.

### Task 2: Tenant Domain Models and Migrations

**Files:**
- Create: `apps/api/database/migrations/tenant/2026_05_24_120000_create_channels_table.php`
- Create: `apps/api/database/migrations/tenant/2026_05_24_120001_create_channel_credentials_table.php`
- Create: `apps/api/database/migrations/tenant/2026_05_24_120002_create_channel_product_mappings_table.php`
- Create: `apps/api/database/migrations/tenant/2026_05_24_120003_create_channel_orders_table.php`
- Create: `apps/api/database/migrations/tenant/2026_05_24_120004_create_channel_sync_operations_table.php`
- Create: `apps/api/app/Modules/Channel/Domain/Enums/*.php`
- Create: `apps/api/app/Modules/Channel/Domain/Models/*.php`
- Test: `apps/api/tests/Feature/Channel/ChannelPersistenceTest.php`

- [ ] **Step 1: Write failing persistence tests**

Assert that migrations create the five tenant tables, enum casts hydrate correctly, `ChannelCredential::encrypted_payload` is encrypted at rest, and no migration file contains `constrained('tenants')` or `on('tenants')`.

- [ ] **Step 2: Implement migrations and models**

Place all migrations in `database/migrations/tenant/`, use intra-tenant FKs only, store `adapter_type` as string, use enum casts for status/type columns, and use Laravel's `encrypted:array` cast for credentials.

- [ ] **Step 3: Verify green**

Run: `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= php artisan test tests/Feature/Channel/ChannelPersistenceTest.php`
Expected: PASS.

### Task 3: Services, Jobs, Events, Webhook

**Files:**
- Create: `apps/api/app/Modules/Channel/Application/Commands/CreateChannelCommand.php`
- Create: `apps/api/app/Modules/Channel/Application/Services/ChannelService.php`
- Create: `apps/api/app/Modules/Channel/Application/Services/ChannelOrderIngestService.php`
- Create: `apps/api/app/Modules/Channel/Application/Jobs/DispatchProductToChannelJob.php`
- Create: `apps/api/app/Modules/Channel/Application/Jobs/DispatchStockChangeToChannelJob.php`
- Create: `apps/api/app/Modules/Channel/Application/Jobs/IngestChannelOrderJob.php`
- Create: `apps/api/app/Modules/Channel/Application/Jobs/ChannelReconciliationJob.php`
- Create: `apps/api/app/Modules/Channel/Application/Listeners/DispatchStockChangeToChannels.php`
- Create: `apps/api/app/Modules/Channel/Domain/Events/*.php`
- Create: `apps/api/app/Modules/Channel/Presentation/Controllers/*.php`
- Create: `apps/api/app/Modules/Channel/Presentation/routes.php`
- Create: `apps/api/app/Modules/Channel/Providers/ChannelServiceProvider.php`
- Modify: `apps/api/bootstrap/providers.php`
- Test: `apps/api/tests/Feature/Channel/ChannelServiceTest.php`
- Test: `apps/api/tests/Feature/Channel/ChannelWebhookTest.php`
- Test: `apps/api/tests/Feature/Channel/ChannelDispatchJobsTest.php`

- [ ] **Step 1: Write failing service/job/webhook tests**

Assert no-adapter errors, ExampleTestAdapter success when registered in test scope, product publish idempotency by `last_sync_hash`, stock event listener dispatch debounce behavior, webhook signature strategy acceptance/rejection, 300-second replay window rejection, order ingest idempotency, and reconciliation drift event emission.

- [ ] **Step 2: Implement services and jobs**

Use constructor injection only, queue jobs carry IDs and re-query tenant-scoped models, dispatch failures create `ChannelDispatchFailed`, webhook controller verifies the registry-provided signature strategy before queue handoff, and listener finds active channel mappings by `company_id` and `product_id`.

- [ ] **Step 3: Verify green**

Run: `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= php artisan test tests/Feature/Channel`
Expected: PASS.

### Task 4: Admin UI

**Files:**
- Create: `apps/web/src/features/channels/api.ts`
- Create: `apps/web/src/features/channels/types.ts`
- Create: `apps/web/src/features/channels/index.ts`
- Create: `apps/web/src/features/channels/pages/ChannelListPage.tsx`
- Create: `apps/web/src/features/channels/pages/ChannelCreateWizard.tsx`
- Create: `apps/web/src/features/channels/pages/ChannelProductMappingPage.tsx`
- Create: `apps/web/src/features/channels/pages/ChannelSyncStatusDashboard.tsx`
- Create: `apps/web/src/features/channels/pages/ChannelOrdersPage.tsx`
- Create: `apps/web/src/features/channels/pages/ChannelListPage.test.tsx`
- Modify: `apps/web/src/routes/index.tsx`
- Modify: `apps/web/src/components/organisms/Sidebar/Sidebar.tsx`
- Modify: `apps/web/src/locales/en/common.json`
- Modify: `apps/web/src/locales/fr/common.json`
- Modify: `apps/web/src/locales/ar/common.json`
- Create: `apps/web/src/locales/en/channels.json`
- Create: `apps/web/src/locales/fr/channels.json`
- Create: `apps/web/src/locales/ar/channels.json`
- Modify: `apps/web/src/lib/i18n.ts`

- [ ] **Step 1: Write failing UI test**

Run: `pnpm --filter @autoerp/web test -- ChannelListPage.test.tsx`
Expected: FAIL because the channels feature does not exist.

- [ ] **Step 2: Implement UI**

Use translated copy only, lucide icons, design tokens for new feature styling, five routes under `/channels`, sidebar entry under Inventory & Catalog, and a visible "No adapters available yet" state when `registered_adapters` is empty.

- [ ] **Step 3: Verify green**

Run: `pnpm --filter @autoerp/web test -- ChannelListPage.test.tsx`
Expected: PASS.

### Task 5: Final Verification and Review

**Files:**
- Create: `apps/api/tests/Fixtures/Channel/ExampleTestAdapter.php`
- Create: `apps/api/tests/Fixtures/Channel/RejectingSignatureStrategy.php`
- Create: `apps/erp/docs/superpowers/reviews/2026-05-24-t3-implementation-opus-review.md` if running from repo parent, or `docs/superpowers/reviews/2026-05-24-t3-implementation-opus-review.md` from this worktree.

- [ ] **Step 1: Run adapter-specific test suite**

Run: `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= php artisan test tests/Unit/Channel tests/Feature/Channel`
Expected: PASS.

- [ ] **Step 2: Run frontend checks**

Run: `pnpm --filter @autoerp/web typecheck && pnpm --filter @autoerp/web lint`
Expected: PASS.

- [ ] **Step 3: Run preflight**

Run: `./scripts/preflight.sh`
Expected: PASS.

- [ ] **Step 4: Adversarial review**

Save a review at `docs/superpowers/reviews/2026-05-24-t3-implementation-opus-review.md` that checks the implementation against the spec, especially no production concrete adapters, no WooCommerce dependency, tenant migration placement, encrypted casts, webhook signature/replay handling, debounce, idempotency, and UI no-adapters state.

- [ ] **Step 5: Commit and PR**

Commit as `Phase 3.0.0: Create sync hub shared infrastructure`, push branch `feat/t3-sync-hub-infra`, and open a PR against `dev` titled `feat(sync-hub): T3 multi-channel sync hub shared infrastructure`.
