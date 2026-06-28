# Adversarial Review: Expense Flow Demo Cutoff Spec

Reviewed spec: `docs/superpowers/specs/2026-06-27-expense-flow-demo-cutoff-design.md`  
Review date: 2026-06-27  
Method: verified against the current codebase only. I did not accept audit/spec assertions without checking routes, controllers, models, migrations, frontend components, policies, services, and seeders.

## Verdict

The spec correctly identifies several real blockers: the API policy stubs deny record-level expense/category gates; `ExpenseController::show()` eager-loads a non-existent `attachments` relation; expense and category collection endpoints are unguarded; expense posting creates GL but does not decrement `payment_repositories.balance`; category `account_id` exists but is absent from the category UI; no expense categories are seeded; and the expense routes are frontend-gated by treasury permissions.

However, the spec has material inaccuracies and design conflicts:

- It says the complete policy should be moved into `apps/api/app/Policies`, but it does not mention that the only explicit `Gate::policy` registration is in the root orphan `app/Providers/AppServiceProvider.php`, not in the API provider. The API provider loaded by `apps/api/bootstrap/providers.php` has no policy registration.
- It proposes consuming Media module internals (`MediaAttachmentRepositoryInterface`, `MediaUploadService`, `MediaAttachmentService`) from the Expense module. That conflicts with the module-boundary rule unless the plan uses `App\Shared\Contracts\MediaServiceInterface` or keeps the endpoint in the Media module.
- It says `ExpenseResource` already shapes an attachments array from a contract. Current `ExpenseResource` only checks an Eloquent `attachments` relation, which does not exist.
- It says attachment UI must be added to `ExpenseDetailPage`, but current `ExpenseDetailPage` already renders `DocumentAttachments`.
- It says route guards can be replaced with `expenses.*`, but `usePermissions()` currently ignores `user.permissions` and lacks `expenses.*` keys, so those guards would deny everyone unless the permission hook is fixed too.
- It says the category DTO already exists; backend Expense has no Spatie Data DTO. The web feature has manually maintained TS interfaces, which conflicts with the repo's "types flow from backend" rule for new transport work.
- It references `.claude/agents/treasury-reviewer.md`, but that file does not exist in this workspace.

## Findings

### P0: Policy relocation plan is incomplete because API policy registration is also misplaced

The spec correctly states that the API-visible `DocumentPolicy` is an all-deny stub: `apps/api/app/Policies/DocumentPolicy.php:13-16`, `apps/api/app/Policies/DocumentPolicy.php:21-24`, `apps/api/app/Policies/DocumentPolicy.php:29-32`, `apps/api/app/Policies/DocumentPolicy.php:37-40`, `apps/api/app/Policies/DocumentPolicy.php:45-48`, `apps/api/app/Policies/DocumentPolicy.php:53-64`. The complete `DocumentPolicy` exists at root `app/Policies/DocumentPolicy.php:16-28`, `app/Policies/DocumentPolicy.php:34-51`, `app/Policies/DocumentPolicy.php:57-68`, `app/Policies/DocumentPolicy.php:74-91`, `app/Policies/DocumentPolicy.php:97-114`, `app/Policies/DocumentPolicy.php:120-133`.

But the spec understates the registration problem. The API autoload root maps `App\\` to `apps/api/app/` in `apps/api/composer.json:49-54`; the API app loads `App\Providers\AppServiceProvider` from `apps/api/bootstrap/providers.php:49-58`. That API provider contains no policy imports or `Gate::policy` registration in `apps/api/app/Providers/AppServiceProvider.php:24-45` or its boot body at `apps/api/app/Providers/AppServiceProvider.php:94-151`.

The only explicit registration is in the root orphan provider: `app/Providers/AppServiceProvider.php:15-16` imports the root policies and `app/Providers/AppServiceProvider.php:58-63` registers them. That provider is outside the API PSR-4 root and is not the provider shown in `apps/api/bootstrap/providers.php`.

Action required: moving the real policies into `apps/api/app/Policies` is necessary. The plan should also explicitly register `Document::class` and `ExpenseCategory::class` in the API provider or add a test proving Laravel discovery resolves custom-namespace models to `App\Policies\DocumentPolicy` and `App\Policies\ExpenseCategoryPolicy`.

### P0: Proposed Media integration violates module-boundary rules

The repo rule is explicit: cross-module communication must go through `Shared/Contracts`, events, or a module public service class; direct model/module imports are forbidden in `CLAUDE.md:54-56`. The spec proposes `MediaAttachmentRepositoryInterface::forOwners(...)`, but that interface lives in the Media module namespace at `apps/api/app/Modules/Media/Domain/Contracts/MediaAttachmentRepositoryInterface.php:5-10`, not under `App\Shared\Contracts`. The proposed upload services are also Media internals: `apps/api/app/Modules/Media/Application/Services/MediaUploadService.php:5-17` and `apps/api/app/Modules/Media/Application/Services/MediaAttachmentService.php:5-14`.

There is already a shared Media port: `App\Shared\Contracts\MediaServiceInterface` at `apps/api/app/Shared/Contracts/MediaServiceInterface.php:5-15`, with `attachUpload`, `listForOwner`, `download`, `detach`, and `purgeOwner` at `apps/api/app/Shared/Contracts/MediaServiceInterface.php:22-62`. If Expense needs to consume Media, the design should use that shared contract or keep attachment routes in the Media module.

### P1: Frontend permission plan will break unless `usePermissions()` is fixed

The backend seeds `expenses.*` and `expense-categories.*` permissions at `apps/api/database/seeders/RolesAndPermissionsSeeder.php:154-164`. Admin and several roles receive subsets of them at `apps/api/database/seeders/RolesAndPermissionsSeeder.php:416-417`, `apps/api/database/seeders/RolesAndPermissionsSeeder.php:495-496`, `apps/api/database/seeders/RolesAndPermissionsSeeder.php:532-533`, `apps/api/database/seeders/RolesAndPermissionsSeeder.php:595-596`, and `apps/api/database/seeders/RolesAndPermissionsSeeder.php:622-623`.

The frontend auth payload carries real permissions: `AuthUserData` includes `permissions` at `apps/api/app/Modules/Identity/Application/DTOs/AuthUserData.php:14-28` and reads them from Spatie at `apps/api/app/Modules/Identity/Application/DTOs/AuthUserData.php:39-52`. But `usePermissions()` ignores `user.permissions`, reads only roles at `apps/web/src/hooks/usePermissions.ts:229-239`, and returns false for any permission key missing from its hardcoded map at `apps/web/src/hooks/usePermissions.ts:236-239`. There are no `expenses.*` keys in the frontend map; search found no occurrences in `apps/web/src/hooks/usePermissions.ts`.

Therefore, the spec's route-guard replacement (`permission="expenses.view"` etc.) is incomplete. It must either add `expenses.*` and `expense-categories.*` to the hardcoded frontend map or, better, replace the map with the actual auth payload permissions.

### P1: Expense attachment API exists only under document permissions, not expense permissions

Current expense detail already renders attachments through the shared document component: `apps/web/src/features/expenses/pages/ExpenseDetailPage.tsx:15` imports `DocumentAttachments`, and `apps/web/src/features/expenses/pages/ExpenseDetailPage.tsx:277-283` renders it. That refutes the spec's claim that the detail page lacks attachment UI.

The component fetches/uploads/deletes via `/documents/{documentId}/attachments`: `apps/web/src/features/documents/hooks/useAttachments.ts:52-59`, `apps/web/src/features/documents/hooks/useAttachments.ts:90-107`, and `apps/web/src/features/documents/hooks/useAttachments.ts:129-132`. Backend routes for those endpoints are gated by `documents.view` and `documents.update`, not `expenses.view` / `expenses.update`, at `apps/api/app/Modules/Media/routes.php:24-40`. The upload request delegates authorization to route middleware at `apps/api/app/Modules/Media/Presentation/Requests/UploadDocumentMediaRequest.php:17-24`.

The spec's proposed `POST /expenses/{id}/attachments` gated by `expenses.update` is consistent with the desired permission model, but it should account for the existing document attachment endpoint and avoid duplicating routes with inconsistent permissions.

### P1: `ExpenseResource` does not already accept contract-loaded attachments

The spec says `ExpenseResource` "already shapes an attachments array" from the Media contract. Current code shapes attachments only when an Eloquent relation named `attachments` is loaded: `apps/api/app/Modules/Expense/Presentation/Resources/ExpenseResource.php:78-89`. `Document` has `expenseMetadata()` at `apps/api/app/Modules/Document/Domain/Document.php:287-293`, but no `attachments()` relation in `apps/api/app/Modules/Document/Domain/Document.php:260-380`. `ExpenseController::show()` eager-loads `attachments` at `apps/api/app/Modules/Expense/Presentation/Controllers/ExpenseController.php:125-135`, so the 500 claim is grounded, but the resource-contract claim is false.

### P1: Idempotent expense create has no current storage or request surface

The current expense request validates vendor/category/repository/date/total/etc. but no idempotency key at `apps/api/app/Modules/Expense/Presentation/Requests/ExpenseRequest.php:46-74`. `ExpenseService::create()` writes a `Document` and `ExpenseMetadata` only at `apps/api/app/Modules/Expense/Application/Services/ExpenseService.php:31-57`. `Document::$fillable` has no `idempotency_key` in `apps/api/app/Modules/Document/Domain/Document.php:118-162`, and the expense TS create DTO has no idempotency field at `apps/web/src/features/expenses/types/index.ts:115-128`. The web create API posts the payload unchanged to `/expenses` at `apps/web/src/features/expenses/api/expenseApi.ts:40-43`.

The spec is correct that this would be new work, but the claim "follow existing server-side idempotency pattern" is not pinned to an Expense-compatible mechanism. Existing patterns are elsewhere, such as stock transfers with `idempotency_key` in `apps/api/database/migrations/tenant/2026_05_28_120000_create_stock_transfers_table.php:53-67` and POS/cash-drawer flows, but Expense has no equivalent field.

### P1: `post` is not idempotent in the sense the spec claims

`ExpenseController::post()` returns a 422 error when the expense is already posted at `apps/api/app/Modules/Expense/Presentation/Controllers/ExpenseController.php:216-220`. `ExpenseService::post()` also throws unless the status is Draft at `apps/api/app/Modules/Expense/Application/Services/ExpenseService.php:103-107`. That refutes the spec's statement that re-post is a no-op. The current implementation prevents a second GL/cash mutation, but it is not an idempotent replay returning the existing posted expense.

### P1: Cash-balance decrement is absent, while GL posting is present

`ExpenseService::post()` updates document number/status and calls GL in one transaction at `apps/api/app/Modules/Expense/Application/Services/ExpenseService.php:109-124`. `GeneralLedgerService::createFromExpense()` creates a debit expense line and a credit cash/bank line at `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2047-2121`. It uses category `account_id` if loaded at `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2053-2069`, chooses Cash/Bank by repository type at `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2071-2076`, and posts after commit at `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2119-2121`.

No code in `ExpenseService::post()` or `createFromExpense()` updates `PaymentRepository::balance`. The `payment_repositories.balance` field exists at `apps/api/database/migrations/tenant/2025_11_30_120000_create_treasury_tables.php:28-31`, was widened to scale 3 at `apps/api/database/migrations/tenant/2026_03_11_200000_widen_monetary_columns_to_scale_3.php:113-116`, and is cast as `decimal:3` at `apps/api/app/Modules/Treasury/Domain/PaymentRepository.php:85-90`.

### P1: Category GL mapping exists in backend but is missing in the React form

The schema has `expense_categories.account_id` at `apps/api/database/migrations/tenant/2025_12_23_145145_create_expense_categories_table.php:16-24`. The model fillable/relationship exists at `apps/api/app/Modules/Expense/Domain/ExpenseCategory.php:50-58` and `apps/api/app/Modules/Expense/Domain/ExpenseCategory.php:112-118`. Backend request validation accepts company-scoped `account_id` at `apps/api/app/Modules/Expense/Presentation/Requests/ExpenseCategoryRequest.php:44-61`, and the resource emits it at `apps/api/app/Modules/Expense/Presentation/Resources/ExpenseCategoryResource.php:25-63`. Web TS types include `account_id` in `ExpenseCategory` and `CreateExpenseCategoryDTO` at `apps/web/src/features/expenses/types/index.ts:82-105` and `apps/web/src/features/expenses/types/index.ts:133-140`.

But `ExpenseCategoryPage` initializes and edits only `name`, `description`, `parent_id`, and `is_active` at `apps/web/src/features/expenses/pages/ExpenseCategoryPage.tsx:27-32`, `apps/web/src/features/expenses/pages/ExpenseCategoryPage.tsx:34-50`, and `apps/web/src/features/expenses/pages/ExpenseCategoryPage.tsx:55-63`. The rendered form has fields for name, description, parent, and active at `apps/web/src/features/expenses/pages/ExpenseCategoryPage.tsx:220-280`, with no `account_id` picker.

No seeder creates `expense_categories`; searches found the migration only, while the seeders list does not include an expense-category seeder. Tunisia class-6 accounts do exist in `TunisiaChartOfAccountsSeeder` at `apps/api/database/seeders/TunisiaChartOfAccountsSeeder.php:180-215`, with `GeneralExpense` mapped to account 65 at `apps/api/database/seeders/TunisiaChartOfAccountsSeeder.php:209-210`.

### P1: Precision plan is partly grounded but misses existing drift

Backend expense amount validation follows the scale-3 regex requirement: `apps/api/app/Modules/Expense/Presentation/Requests/ExpenseRequest.php:68`. The expense form uses `MoneyInput` and passes strings at `apps/web/src/features/expenses/components/organisms/ExpenseFormFields.tsx:128-143`.

But the existing expense card parses money with a float: `apps/web/src/features/expenses/components/organisms/ExpenseCard.tsx:71-80`. That directly conflicts with the precision contract, which says never `parseFloat` / `Number(...)` on money and to use `MoneyInput` / `formatCurrency` at `CLAUDE.md:69-76`.

For the proposed balance decrement, the spec is correct that it must use bcmath and `getScale($document->currency)`. The current GL service still has a no-arg `scale()` helper at `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:48-51`; the expense GL method itself does not do arithmetic, but any new repository-balance arithmetic must not copy that no-arg pattern.

### P2: Frontend route-guard claim is correct but incomplete

Expense routes are currently gated by `moduleKey="treasury"` or `permission="treasury.create"` / `"treasury.edit"` at `apps/web/src/routes/index.tsx:1432-1494`. The hardcoded frontend permission map has `treasury.view/create/edit` at `apps/web/src/hooks/usePermissions.ts:33-37`. That confirms the spec's route-guard claim.

The spec misses two extra stale entry points: Command Palette has expenses under `moduleKey: 'treasury'` and create-expense under `permission: 'treasury.create'` at `apps/web/src/components/organisms/CommandPalette/useCommandPalette.ts:78-95`; Quick Create uses `treasury.create` for expense at `apps/web/src/components/organisms/TopBar/QuickCreateButton.tsx:57-60`.

### P2: Existing route middleware pattern includes `EnforceTokenTenantClaim`

The Expense route group has `['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class]` at `apps/api/app/Modules/Expense/routes.php:21-27`. The Document and Procurement route groups use the same extra middleware at `apps/api/app/Modules/Document/Presentation/routes.php:33-45` and `apps/api/app/Modules/Procurement/Presentation/routes.php:24-29`. Adding `can:` middleware to Expense collection routes aligns with the route-middleware convention, but the spec should preserve `EnforceTokenTenantClaim` and not describe the stack as only the three-item base from the guideline.

### P2: The spec's DTO claim conflicts with current backend and repo conventions

The Expense module has no backend DTO classes; its files are only service, domain models, controllers, requests, resources, provider, and routes. `ExpenseCategoryResource` is a JsonResource at `apps/api/app/Modules/Expense/Presentation/Resources/ExpenseCategoryResource.php:5-23`, and `ExpenseCategoryRequest` validates raw request arrays at `apps/api/app/Modules/Expense/Presentation/Requests/ExpenseCategoryRequest.php:37-61`. The web feature defines manual TS interfaces at `apps/web/src/features/expenses/types/index.ts:82-140`.

This conflicts with the repository rule that transport types should flow from backend DTOs and generated shared types, not manual frontend interfaces, in `CLAUDE.md:37-38` and the conventions pointer at `CLAUDE.md:116-119`. The spec should not say the category DTO already exists unless it means the manual web interface; for backend/API contract work, it does not.

### P2: Attachment upload default role is not `SourceDocument`

The current document attachment controller allows a `role` input but defaults missing roles to `MediaRole::Datasheet` at `apps/api/app/Modules/Media/Presentation/Controllers/DocumentAttachmentController.php:81-95`. `MediaRole::SourceDocument` exists at `apps/api/app/Modules/Media/Domain/Enums/MediaRole.php:7-18`, and the request allows `SOURCE_DOCUMENT` at `apps/api/app/Modules/Media/Presentation/Requests/UploadDocumentMediaRequest.php:52-56`. If expense receipts must be `SourceDocument`, the plan must set that role explicitly for the expense path.

### P2: Non-existent process artifacts

The spec gates the review loop by `.claude/agents/treasury-reviewer.md`. That file does not exist in the workspace; `.claude/agents` is absent from the file listing, while `.claude` contains commands, context, hooks, settings, skills, and worktrees. The requested mobile handover doc `docs/handoff/HANDOVER-mobile-expense-logging.md` also does not exist today. These are acceptable planned deliverables only if the spec states they must be created; they are not existing artifacts.

## Claim Ledger

| Spec claim | Verdict | Evidence |
|---|---|---|
| API `DocumentPolicy` is all-deny and blocks Expense record actions. | Confirmed. | Stub methods return false in `apps/api/app/Policies/DocumentPolicy.php:13-64`; Expense calls `Gate::authorize` for show/update/delete/post in `apps/api/app/Modules/Expense/Presentation/Controllers/ExpenseController.php:137`, `:154`, `:186`, `:214`. |
| Complete `DocumentPolicy` exists at root and is outside API PSR-4. | Confirmed. | Complete policy is `app/Policies/DocumentPolicy.php:16-151`; API composer maps `App\\` to `apps/api/app/` at `apps/api/composer.json:49-54`. |
| Other Document controllers dodge policy by route middleware. | Confirmed for checked controllers/routes. | Document routes use `can:` middleware in `apps/api/app/Modules/Document/Presentation/routes.php:38-144`; `rg` found no `Gate::authorize` in Document presentation controllers, while Expense has the only Document-model Gate calls. |
| `ExpenseController::show()` 500s because it eager-loads `attachments`. | Confirmed. | Eager load at `apps/api/app/Modules/Expense/Presentation/Controllers/ExpenseController.php:125-135`; `Document` has `expenseMetadata()` but no `attachments()` in `apps/api/app/Modules/Document/Domain/Document.php:287-293` and `:260-380`. |
| Expense `index()` / `store()` are unguarded. | Confirmed. | Routes are plain `apiResource` with no `can:` at `apps/api/app/Modules/Expense/routes.php:21-27`; controller index/store have no `Gate` at `apps/api/app/Modules/Expense/Presentation/Controllers/ExpenseController.php:34-116`. |
| Category controller has same all-deny policy problem. | Confirmed for record endpoints; collection endpoints are also unguarded. | API stub returns false in `apps/api/app/Policies/ExpenseCategoryPolicy.php:13-64`; category show/update/delete call Gate at `apps/api/app/Modules/Expense/Presentation/Controllers/ExpenseCategoryController.php:98`, `:116`, `:148`; routes have no `can:` at `apps/api/app/Modules/Expense/routes.php:26-27`. |
| Posting creates balanced GL but moves no repository cash. | Confirmed. | Expense post calls GL in transaction at `apps/api/app/Modules/Expense/Application/Services/ExpenseService.php:109-124`; GL creates debit/credit lines at `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2094-2114`; no balance update appears in those methods. |
| Category `account_id` exists but UI omits it. | Confirmed. | Schema/model/request/resource/TS include `account_id` at `apps/api/database/migrations/tenant/2025_12_23_145145_create_expense_categories_table.php:23`, `apps/api/app/Modules/Expense/Domain/ExpenseCategory.php:50-58`, `apps/api/app/Modules/Expense/Presentation/Requests/ExpenseCategoryRequest.php:55-58`, `apps/api/app/Modules/Expense/Presentation/Resources/ExpenseCategoryResource.php:30`, `apps/web/src/features/expenses/types/index.ts:82-140`; React form omits it in `apps/web/src/features/expenses/pages/ExpenseCategoryPage.tsx:220-280`. |
| No categories are seeded. | Confirmed by code search. | No seeder writes `ExpenseCategory` / `expense_categories`; only migration references found at `apps/api/database/migrations/tenant/2025_12_23_145145_create_expense_categories_table.php:16-47`. |
| FE expense routes use treasury gates. | Confirmed. | `apps/web/src/routes/index.tsx:1432-1494`. |
| `ExpenseRequest::authorize()` stays true. | Grounded and acceptable. | `apps/api/app/Modules/Expense/Presentation/Requests/ExpenseRequest.php:27-32`; route/policy auth pattern also appears in `UploadDocumentMediaRequest` at `apps/api/app/Modules/Media/Presentation/Requests/UploadDocumentMediaRequest.php:17-24`. |
| `ExpenseResource` already shapes attachments from the Media contract. | Refuted. | It expects an Eloquent relation named `attachments` at `apps/api/app/Modules/Expense/Presentation/Resources/ExpenseResource.php:78-89`; no contract input exists. |
| Upload endpoint should use S3/MinIO. | Grounded. | `MediaUploadService` writes to `Storage::disk('s3')` and stores `storage_disk => 's3'` at `apps/api/app/Modules/Media/Application/Services/MediaUploadService.php:141-180`; S3 disk configured in `apps/api/config/filesystems.php:50-61`. |
| Attachment role/type enums exist. | Confirmed. | `MediaOwnerType::Document` at `apps/api/app/Modules/Media/Domain/Enums/MediaOwnerType.php:7-13`; `MediaRole::SourceDocument` at `apps/api/app/Modules/Media/Domain/Enums/MediaRole.php:7-18`; `MediaAssetType::Document` at `apps/api/app/Modules/Media/Domain/Enums/MediaAssetType.php:7-13`. |
| Detail page needs attachment UI added. | Refuted/stale. | Existing detail renders `DocumentAttachments` at `apps/web/src/features/expenses/pages/ExpenseDetailPage.tsx:277-283`. |
| Create form uses `MoneyInput`, no parseFloat. | Partially true. | Form uses `MoneyInput` at `apps/web/src/features/expenses/components/organisms/ExpenseFormFields.tsx:128-143`; list card uses `parseFloat` at `apps/web/src/features/expenses/components/organisms/ExpenseCard.tsx:71-80`. |
| Category select already exists. | Confirmed. | `ExpenseFormFields` imports and renders it at `apps/web/src/features/expenses/components/organisms/ExpenseFormFields.tsx:3` and `:110-118`. |
| Idempotent create exists as a pattern to follow. | Partially grounded elsewhere, absent for Expense. | Expense has no idempotency field in request/service/DTO (`ExpenseRequest.php:46-74`, `ExpenseService.php:31-57`, `types/index.ts:115-128`); stock transfer has a nullable idempotency key and unique index at `apps/api/database/migrations/tenant/2026_05_28_120000_create_stock_transfers_table.php:53-67`. |
| Posted expense delete is disallowed. | Confirmed. | `ExpenseController::destroy()` returns 422 unless Draft at `apps/api/app/Modules/Expense/Presentation/Controllers/ExpenseController.php:188-194`. |
| Re-post is a no-op. | Refuted. | Controller returns 422 for already posted at `apps/api/app/Modules/Expense/Presentation/Controllers/ExpenseController.php:216-220`; service throws for non-Draft at `apps/api/app/Modules/Expense/Application/Services/ExpenseService.php:103-107`. |
| No new tables. | Unproven/conflicts with idempotency unless stored elsewhere. | Existing `documents` fillable lacks idempotency at `apps/api/app/Modules/Document/Domain/Document.php:118-162`; no Expense idempotency table/field exists. |
| `.claude/agents/treasury-reviewer.md` exists/gates review loop. | Refuted. | File does not exist; `.claude` listing contains no `agents` directory. |

## Required Spec Corrections Before Writing Plans

1. Add an explicit API-provider policy-registration task, or a test proving Laravel auto-discovery resolves these custom model namespaces to `apps/api/app/Policies/*`.
2. Replace direct Expense-module dependencies on Media internals with `App\Shared\Contracts\MediaServiceInterface` or keep receipt attachment operations in Media-owned routes/controllers.
3. Decide whether expense receipts use the existing `/documents/{id}/attachments` endpoint or a new `/expenses/{id}/attachments` endpoint. Do not leave both with divergent permissions.
4. Update the frontend auth plan to fix `usePermissions()` so it uses `user.permissions`, or at minimum add `expenses.*` / `expense-categories.*` to the hardcoded map and update Command Palette / Quick Create too.
5. Specify an actual idempotency persistence mechanism for `POST /expenses`; "no new tables" is not enough because no current Expense field can hold the key.
6. Correct the stale attachment UI statement: `ExpenseDetailPage` already uses `DocumentAttachments`; the blocker is backend show/resource and permission alignment.
7. Correct the DTO statement: backend Expense DTOs do not exist; do not expand manual TS transport types without addressing generated shared types.
8. Include existing precision drift cleanup for `ExpenseCard`'s `parseFloat`.
9. Treat `.claude/agents/treasury-reviewer.md` and `docs/handoff/HANDOVER-mobile-expense-logging.md` as files to create, not existing artifacts.
