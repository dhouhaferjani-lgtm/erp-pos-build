> Audit lane: roles & permissions, 2026-09-09. Produced by a Sonnet research agent from HEAD e85641a66 (dev); read-only. Verification verdicts: see 06-synthesis.md §0.

# API Route Authorization Audit

Repo: `/Users/houssamr/Projects/syneriva/apps/erp` (apps/api)  
Commit: `e85641a66` (branch `dev`, `git rev-parse --short HEAD` at repo root)  
Scope: read-only mechanical sweep. No repo files edited, no git writes, PHPUnit suite NOT run.

## Method

1. `php artisan route:list --json` from `apps/api` succeeded (vendor + `.env` present, DB reachable) — 1092 total registered routes.
2. Because `route:list --json` doesn't resolve middleware aliases to their raw route-level tokens (`can:x`, `module:x`, `super_admin`, …) or give controller `file:line`, a standalone PHP script (`analyze_routes.php`) was written that boots the full Laravel kernel (`bootstrap/app.php`, same bootstrap `artisan` uses) and then:
   - iterates `app('router')->getRoutes()`, reading each route's **unresolved** middleware via `Route::gatherMiddleware()` (gives `can:accounts.view`, `module:Menu`, `super_admin`, `central_admin_role:x,y`, `require.any.permission:a,b,c`, `auth:sanctum` / `auth:sanctum-admin`, etc. instead of fully-qualified class names)
   - for controller actions, uses `ReflectionMethod` to get `file:line` and the exact method source, then regex-scans it for `->authorize(`, `Gate::`, `->can(`, `->hasPermissionTo(`, `abort_unless(...can`, `abort_if(...!can`, `->hasRole(`, `->hasAnyRole(`, and extracts the literal permission/ability string argument
   - for any parameter type-hinted as a `FormRequest` subclass, reflects that class's `authorize()` method and classifies it as **open** (returns `true` unconditionally, including the case where the class doesn't override `authorize()` at all — the Laravel base class default) vs a **real check**
   - for the controller's constructor-injected services, does a **one-hop** scan: any `$this->prop->method(...)` call inside the action method, where `prop`'s constructor type is resolvable, is followed into that service method and scanned with the same regex set
   - for the 4 genuine `Closure` routes (see below), reflects the closure itself the same way
3. Middleware alias registry (`bootstrap/app.php:114-122`) confirmed: `can` → Laravel's built-in `Authorize` middleware (checked via Spatie's `Gate::before` permission hook), `module` → `App\Http\Middleware\RequireModule`, `super_admin` → `EnsureSuperAdmin`, `central_admin` → `EnsureCentralAdmin`, `central_admin_role` → `RequireCentralAdminRole`, `require.any.permission` → `RequireAnyPermission`.
4. `RolesAndPermissionsSeeder::permissionNames()` (304 entries, all dot-notated) extracted via the same booted container and diffed against every dot-notated permission string found anywhere (middleware `can:`/`require.any.permission:` args, `->can(`, `->authorize(`, `->hasPermissionTo(`, FormRequest checks, one-hop service checks). Bare, non-dotted ability words (`view`, `update`, `delete`, `post`, `revert`, …) were excluded from this diff — they are Laravel Policy ability names (`Gate::authorize('view', $model)` against a registered `Policy` class, e.g. `App\Policies\DocumentPolicy` for `Document`), not Spatie permission names, and were never expected to appear in the permission seeder.
5. Scripts: `analyze_routes.php`, `classify.py`, `write_report.py` (this file), all in the scratchpad dir. Reflection had **zero errors** across all 1092 routes (`refl_error` empty for every row).

## Scope actually audited

- **1092** routes registered in total.
- **1054** carry an `api/` URI prefix — this is the population classified below as "every authenticated API route."
- **38** non-`api/` infrastructure routes were excluded from the per-route CSV/classification (out of scope for an *application* permission sweep) but are swept briefly here for completeness:
  - **22** Horizon dashboard routes (`/horizon/*`) — gated by Laravel Horizon's own `Authenticate` middleware (`config/horizon.php` gate, typically env-based / local-only or a custom `Gate::define('viewHorizon', …)` — not part of the app's Spatie permission system).
  - **6** Debugbar routes (`/_debugbar/*`) — gated by `Barryvdh\Debugbar\Middleware\DebugbarEnabled` (config/env flag), no app-level auth.
  - **2** Scramble API-docs routes (`/docs`) — `Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess` (framework-level gate, not a Spatie permission).
  - `/up` (health check, no auth), `/storage/{path}` (public asset serving ×2), `/sanctum/csrf-cookie` (public, required for SPA auth bootstrapping), `/broadcasting/auth` (Sanctum-authenticated, permission model is channel-authorization closures in `routes/channels.php`, not route middleware), `/resend/webhook` (no auth middleware visible — webhook, presumably signature-checked in-controller or not at all — **not verified, out of this sweep's scope**), `/tenancy/*` (1 route, Stancl tenancy internal).

## Classification totals (1054 API routes)

| classification | count | meaning |
|---|---|---|
| MIDDLEWARE | 642 | route/group carries `can:<permission>` or `require.any.permission:<a,b,c>` |
| CONTROLLER | 130 | no middleware permission; controller method itself calls `Gate::`/`->can(`/`->authorize(`/`->hasPermissionTo(` with a dotted (Spatie) permission string |
| FORMREQUEST | 46 | no middleware/controller check; the injected FormRequest's `authorize()` performs a real (non-`true`) check |
| POLICY | 8 | controller calls `Gate::authorize()`/`->authorize()` with a bare (non-dotted) ability name against a model with a registered Laravel Policy class (e.g. `DocumentPolicy`) |
| SUPERADMIN_ONLY | 68 | gated by `super_admin` / `central_admin` / `central_admin_role:<roles>` (role-only gate, not a granular action permission) |
| AUTH_ONLY | 142 | authenticated (`auth:sanctum`/`auth:sanctum-admin`) but **no action-level permission check found** at any layer (middleware, controller, FormRequest, or one-hop service) |
| PUBLIC | 18 | no `auth` middleware at all — anonymous access |
| SERVICE | 0 | permission check found only one hop away, in an injected service method (none landed here — see note below) |
| UNKNOWN | 0 | reflection/parsing failure (none — 0 routes) |
| **TOTAL** | **1054** | |

Note on SERVICE: the one-hop service scan found permission-pattern hits in 10 routes' injected services (all `DiscountPermissionResolver::isAdmin` → `->hasRole(`), but every one of those 10 routes *also* had its own direct controller-level check (`Gate::authorize('pos.operate_terminal')` etc.), so they were classified CONTROLLER (controller check takes classification priority; the service hit is preserved in the CSV's `controller_check_permissions` column as a second `service[...]` entry). SERVICE would only be the final classification for a route with **no** controller-level check that delegates permission-checking entirely to an injected service — none exist in this codebase.

## Central / super-admin routes swept separately

`/api/v1/admin/*` — **69** routes, all on the `auth:sanctum-admin` guard (separate from the tenant `auth:sanctum` guard used by every other API route):
- **68** gated `SUPERADMIN_ONLY` via `super_admin` and/or `central_admin_role:<roles>` (roles seen: `super_admin`, `defaults_editor`, `support_approver`).
- **1** is `PUBLIC`: `POST /api/v1/admin/auth/login` (App\Http\Controllers\Api\Admin\SuperAdminAuthController) — expected, it's the central-admin login endpoint.
- Distinct role-gate combinations seen on admin routes: `central_admin` (any authenticated central admin — used for `/admin/auth/logout`, `/admin/auth/me`), `super_admin` alone (tenants, billing, monitoring, users, verticals, audit-logs), `central_admin_role:super_admin,defaults_editor` (country-defaults templates/assignments), `central_admin_role:super_admin,support_approver` then narrowed per-action to `central_admin_role:super_admin` or `central_admin_role:support_approver` (impersonation approve/revoke/elevate flows — a real separation-of-duties pattern: requesting an elevation needs `super_admin`, approving it needs `support_approver`).
- This is a coarse role gate, not a granular Spatie action permission — by design for the central/platform-operator surface (separate guard, separate `central_identities`/`super_admins` table per the tenancy model in CLAUDE.md), not a gap in the tenant permission system.

## POS-facing routes swept separately

`app/Modules/POS/*` + POS-prefixed routes in other modules (Loyalty POS bridge, BatchExpiry POS lookup) — **115** routes, all `api/v1/*` on `auth:sanctum`:
- CONTROLLER: 91
- MIDDLEWARE: 13
- AUTH_ONLY: 10
- FORMREQUEST: 1
- **Dominant style is CONTROLLER, not MIDDLEWARE**: 91 of 115 POS routes enforce authorization via a direct `Gate::authorize('pos.<ability>')` call inside the controller method body (e.g. `app/Modules/POS/Presentation/Controllers/TableController.php:33,46,59,72,84,...` calling `Gate::authorize('pos.manage_tables')` / `Gate::authorize('pos.operate_terminal')`), rather than the `can:` route middleware used almost everywhere else in the codebase. This is a legitimate but distinct style — worth flagging for consistency review, not a security gap (still a real permission check, just placed one layer down from the route table).
- **AUTH_ONLY in POS (10 routes)**: 4 are the tombstone closures below (return HTTP 410, no mutation, so effectively risk-free); the remaining 6 are all `GET`/preview reads (`discount-permissions`, `cart/preview-discounts` [POST but read-only preview], `fraud-settings`, `payment-policy`, `authorized-managers`, `products/{id}/batches`) — none write POS/fiscal state.
- **Tombstone closures** (4 routes, all `AUTH_ONLY`, all return **HTTP 410** unconditionally with no DB mutation — retired per "fiscal Phase 1 §14.2" / "DPA V9 owner ruling D3", confirmed by reading the route closures directly):
  - `POST /api/v1/pos/receipts` — `app/Modules/POS/routes.php:187`
  - `POST /api/v1/pos/receipts/{id}/void` — `app/Modules/POS/routes.php:222`
  - `POST /api/v1/pos/receipts/{id}/payments` — `app/Modules/POS/routes.php:231`
  - `POST /api/v1/pos/orders/{id}/close` — `app/Modules/POS/routes_orders.php:60`
  - These are genuinely `AUTH_ONLY` (authenticated, no permission check) but functionally inert — worth keeping AUTH_ONLY in the CSV for mechanical accuracy, but they are **not** part of the risk list below.

## AUTH_ONLY routes — the risk list

**142** of 1054 API routes are authenticated with **no action-level permission check found at any layer**. Of these, **57** are write verbs (POST/PUT/PATCH/DELETE) — the actual risk subset, since an unauthenticated-permission GET is lower severity (still a finding if it leaks tenant data cross-role, but out of scope for this permission-focused sweep to assess data sensitivity). Of the 57 write AUTH_ONLY routes, **4** are the inert 410-tombstones above, leaving **53** live write routes with no permission gate.

### Live write AUTH_ONLY routes (the actual finding — cite path:line)

| method | uri | controller@action | file:line | note |
|---|---|---|---|---|
| DELETE | `/api/v1/batches/{uuid}` | BatchController@destroy | `app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:176` | — |
| POST | `/api/v1/batches/{uuid}/recall` | BatchController@recall | `app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:193` | — |
| POST | `/api/v1/channels` | ChannelController@store | `app/Modules/Channel/Presentation/Controllers/ChannelController.php:36` | — |
| POST | `/api/v1/channels/{channelId}/orders/{orderId}/promote` | ChannelOrderController@promote | `app/Modules/Channel/Presentation/Controllers/ChannelOrderController.php:84` | — |
| POST | `/api/v1/channels/{channelId}/products/{productId}/publish` | ChannelProductController@publish | `app/Modules/Channel/Presentation/Controllers/ChannelProductController.php:20` | — |
| POST | `/api/v1/channels/{channelId}/products/{productId}/unpublish` | ChannelProductController@unpublish | `app/Modules/Channel/Presentation/Controllers/ChannelProductController.php:38` | — |
| POST | `/api/v1/channels/{id}/resync` | ChannelController@resync | `app/Modules/Channel/Presentation/Controllers/ChannelController.php:59` | — |
| POST | `/api/v1/channels/{id}/test-connection` | ChannelController@testConnection | `app/Modules/Channel/Presentation/Controllers/ChannelController.php:54` | — |
| POST | `/api/v1/companies` | CompanyController@store | `app/Modules/Company/Presentation/Controllers/CompanyController.php:74` | FormRequest App\Modules\Company\Presentation\Requests\CreateCompanyRequest::authorize() returns true unconditionally (no permission check) |
| DELETE | `/api/v1/coupons/{id}` | CouponController@destroy | `app/Modules/Coupon/Presentation/Controllers/CouponController.php:111` | — |
| POST | `/api/v1/coupons/{id}/reactivate` | CouponController@reactivate | `app/Modules/Coupon/Presentation/Controllers/CouponController.php:205` | — |
| POST | `/api/v1/coupons/{id}/revoke` | CouponController@revoke | `app/Modules/Coupon/Presentation/Controllers/CouponController.php:183` | — |
| POST | `/api/v1/auth/logout` | AuthController@logout | `app/Modules/Identity/Presentation/Controllers/AuthController.php:568` | — |
| POST | `/api/v1/auth/logout-all` | AuthController@logoutAll | `app/Modules/Identity/Presentation/Controllers/AuthController.php:593` | — |
| POST | `/api/v1/auth/resend-verification` | AuthController@resendVerification | `app/Modules/Identity/Presentation/Controllers/AuthController.php:665` | — |
| POST | `/api/v1/inventory/countings/{counting}/items/{item}/count` | CountingItemController@submitCount | `app/Modules/Inventory/Presentation/Controllers/CountingItemController.php:84` | FormRequest App\Modules\Inventory\Presentation\Requests\SubmitCountRequest::authorize() returns true unconditionally (no permission check) |
| DELETE | `/api/v1/menu-categories/{categoryId}/items/{itemId}` | MenuCategoryController@removeItem | `app/Modules/Menu/Presentation/Controllers/MenuCategoryController.php:148` | — |
| DELETE | `/api/v1/menu-categories/{id}` | MenuCategoryController@destroy | `app/Modules/Menu/Presentation/Controllers/MenuCategoryController.php:62` | — |
| DELETE | `/api/v1/menus/{id}` | MenuController@destroy | `app/Modules/Menu/Presentation/Controllers/MenuController.php:123` | — |
| POST | `/api/v1/notifications/read-all` | NotificationController@readAll | `app/Modules/Notification/Presentation/Controllers/NotificationController.php:63` | — |
| POST | `/api/v1/notifications/{id}/read` | NotificationController@markRead | `app/Modules/Notification/Presentation/Controllers/NotificationController.php:55` | — |
| POST | `/api/v1/pos/cart/preview-discounts` | DiscountController@previewDiscounts | `app/Modules/POS/Presentation/Controllers/DiscountController.php:143` | — |
| POST | `/api/v1/platform/barcode-lookup` | BarcodeLookupController@__invoke | `app/Modules/PlatformIntegration/Presentation/Controllers/BarcodeLookupController.php:20` | — |
| GET|POST|HEAD | `/api/v1/platform/catalog/articles/search-by-criteria` | CatalogBrowseController@searchByCriteria | `app/Modules/PlatformIntegration/Presentation/Controllers/CatalogBrowseController.php:93` | — |
| POST | `/api/v1/platform/vin-decode` | VinDecodeController@decode | `app/Modules/PlatformIntegration/Presentation/Controllers/VinDecodeController.php:15` | — |
| POST | `/api/v1/platform/vin-decode/confirm-match` | VinDecodeController@confirmMatch | `app/Modules/PlatformIntegration/Presentation/Controllers/VinDecodeController.php:41` | — |
| POST | `/api/v1/categories` | CategoryController@store | `app/Modules/Product/Presentation/Controllers/CategoryController.php:115` | — |
| POST | `/api/v1/categories/reorder` | CategoryController@reorder | `app/Modules/Product/Presentation/Controllers/CategoryController.php:274` | — |
| PUT | `/api/v1/categories/{id}` | CategoryController@update | `app/Modules/Product/Presentation/Controllers/CategoryController.php:188` | — |
| DELETE | `/api/v1/categories/{id}` | CategoryController@destroy | `app/Modules/Product/Presentation/Controllers/CategoryController.php:247` | — |
| POST | `/api/v1/progression/modules/{moduleId}/activate` | ModuleReadinessController@activate | `app/Modules/Progression/Presentation/Controllers/ModuleReadinessController.php:48` | — |
| POST | `/api/v1/progression/recommendations/{recommendationId}/accept` | RecommendationController@accept | `app/Modules/Progression/Presentation/Controllers/RecommendationController.php:41` | — |
| POST | `/api/v1/progression/recommendations/{recommendationId}/dismiss` | RecommendationController@dismiss | `app/Modules/Progression/Presentation/Controllers/RecommendationController.php:61` | — |
| POST | `/api/v1/progression/register` | CompanyProgressionController@register | `app/Modules/Progression/Presentation/Controllers/CompanyProgressionController.php:50` | — |
| DELETE | `/api/v1/promotions/{id}` | PromotionController@destroy | `app/Modules/Promotion/Presentation/Controllers/PromotionController.php:122` | — |
| POST | `/api/v1/promotions/{id}/activate` | PromotionController@activate | `app/Modules/Promotion/Presentation/Controllers/PromotionController.php:146` | — |
| POST | `/api/v1/promotions/{id}/archive` | PromotionController@archive | `app/Modules/Promotion/Presentation/Controllers/PromotionController.php:194` | — |
| POST | `/api/v1/promotions/{id}/pause` | PromotionController@pause | `app/Modules/Promotion/Presentation/Controllers/PromotionController.php:170` | — |
| POST | `/api/v1/purchase-hub/orders` | PurchaseHubOrderController@store | `app/Modules/PurchaseHub/Presentation/Controllers/PurchaseHubOrderController.php:20` | — |
| POST | `/api/v1/service-categories` | ServiceCategoryController@store | `app/Modules/Service/Presentation/Controllers/ServiceCategoryController.php:111` | FormRequest App\Modules\Service\Presentation\Requests\CreateServiceCategoryRequest::authorize() returns true unconditionally (no permission check) |
| PATCH | `/api/v1/service-categories/{category}` | ServiceCategoryController@update | `app/Modules/Service/Presentation/Controllers/ServiceCategoryController.php:132` | FormRequest App\Modules\Service\Presentation\Requests\UpdateServiceCategoryRequest::authorize() returns true unconditionally (no permission check) |
| DELETE | `/api/v1/service-categories/{category}` | ServiceCategoryController@destroy | `app/Modules/Service/Presentation/Controllers/ServiceCategoryController.php:172` | — |
| POST | `/api/v1/services` | ServiceController@store | `app/Modules/Service/Presentation/Controllers/ServiceController.php:107` | FormRequest App\Modules\Service\Presentation\Requests\CreateServiceRequest::authorize() returns true unconditionally (no permission check) |
| PATCH | `/api/v1/services/{service}` | ServiceController@update | `app/Modules/Service/Presentation/Controllers/ServiceController.php:128` | FormRequest App\Modules\Service\Presentation\Requests\UpdateServiceRequest::authorize() returns true unconditionally (no permission check) |
| DELETE | `/api/v1/services/{service}` | ServiceController@destroy | `app/Modules/Service/Presentation/Controllers/ServiceController.php:168` | — |
| POST | `/api/v1/smart-prompts/recommendations` | SmartPromptsController@recommendations | `app/Modules/SmartPrompts/Presentation/Controllers/SmartPromptsController.php:26` | — |
| POST | `/api/v1/support-access/sessions/{session}/exit` | TenantSessionController@exit | `app/Modules/SupportAccess/Presentation/Controllers/TenantSessionController.php:17` | — |
| POST | `/api/v1/withholding/preview` | WithholdingPreviewController@preview | `app/Modules/Taxation/Presentation/Controllers/WithholdingPreviewController.php:32` | FormRequest App\Modules\Taxation\Presentation\Requests\CalculateWithholdingRequest::authorize() returns true unconditionally (no permission check) |
| POST | `/api/v1/uom/convert` | UomController@convert | `app/Modules/Uom/Presentation/Controllers/UomController.php:302` | — |
| POST | `/api/v1/uom/unit-text-mappings` | UomController@applyUnitTextMapping | `app/Modules/Uom/Presentation/Controllers/UomController.php:50` | FormRequest App\Modules\Uom\Presentation\Requests\ApplyUnitTextMappingRequest::authorize() returns true unconditionally (no permission check) |
| POST | `/api/v1/uom/units` | UomController@storeUnit | `app/Modules/Uom/Presentation/Controllers/UomController.php:168` | FormRequest App\Modules\Uom\Presentation\Requests\CreateUnitRequest::authorize() returns true unconditionally (no permission check) |
| PUT | `/api/v1/uom/units/{id}` | UomController@updateUnit | `app/Modules/Uom/Presentation/Controllers/UomController.php:205` | FormRequest App\Modules\Uom\Presentation\Requests\UpdateUnitRequest::authorize() returns true unconditionally (no permission check) |
| DELETE | `/api/v1/uom/units/{id}` | UomController@destroyUnit | `app/Modules/Uom/Presentation/Controllers/UomController.php:259` | — |

**Reading this list**: some of these are plausibly fine by design (self-service actions on the caller's own resource — `POST /api/v1/auth/logout`, `logout-all`, `resend-verification`; `POST /api/v1/support-access/sessions/{session}/exit` — exiting your own impersonation session). Others look like genuine gaps worth a follow-up ticket: `DELETE /api/v1/categories/{id}`, `POST /api/v1/categories` (create) / `reorder`, `DELETE /api/v1/coupons/{id}` + `/revoke` + `/reactivate`, `POST /api/v1/channels` (create) + `/test-connection` + `/resync`, `POST .../channels/{channelId}/products/{productId}/publish` + `/unpublish`, `POST .../channels/{channelId}/orders/{orderId}/promote`, `DELETE /api/v1/batches/{uuid}` + `POST .../recall` (destructive batch-expiry ops), `POST /api/v1/services` create / `PATCH` update / `DELETE` (and same for `service-categories`), `POST /api/v1/uom/units` + `PUT`/`DELETE` + `unit-text-mappings`, `POST /api/v1/companies` (tenant company creation), `DELETE /api/v1/menus/{id}` / `menu-categories` delete + `removeItem`, and the four `App\Modules\Promotion\...\PromotionController` mutation routes (`destroy`/`activate`/`pause`/`archive`) — note `PromotionController::index`/`show`/`store`/`update` in the same file DO have a `promotions.view`/`promotions.manage` check (via `Gate::`/FormRequest respectively per the CSV), so `destroy`/`activate`/`pause`/`archive` look like an inconsistency within the same controller rather than an intentional policy, not a swept design decision — worth a direct look at `app/Modules/Promotion/Presentation/Controllers/PromotionController.php:122,146,170,194`.

### Full AUTH_ONLY list grouped by module (including read-only)

**BatchExpiry** (11):
- GET|HEAD `/api/v1/batches`
- GET|HEAD `/api/v1/batches/expired`
- GET|HEAD `/api/v1/batches/expiring`
- GET|HEAD `/api/v1/batches/{uuid}`
- DELETE `/api/v1/batches/{uuid}` [write]
- POST `/api/v1/batches/{uuid}/recall` [write]
- GET|HEAD `/api/v1/batches/{uuid}/stock`
- GET|HEAD `/api/v1/batches/{uuid}/traceability`
- GET|HEAD `/api/v1/partners/{partnerId}/batch-history`
- GET|HEAD `/api/v1/pos/products/{productId}/batches`
- GET|HEAD `/api/v1/products/{productId}/batch-stock`

**Channel** (10):
- GET|HEAD `/api/v1/channels`
- POST `/api/v1/channels` [write]
- GET|HEAD `/api/v1/channels/orders`
- POST `/api/v1/channels/{channelId}/orders/{orderId}/promote` [write]
- POST `/api/v1/channels/{channelId}/products/{productId}/publish` [write]
- POST `/api/v1/channels/{channelId}/products/{productId}/unpublish` [write]
- GET|HEAD `/api/v1/channels/{id}/orders`
- POST `/api/v1/channels/{id}/resync` [write]
- GET|HEAD `/api/v1/channels/{id}/sync-operations`
- POST `/api/v1/channels/{id}/test-connection` [write]

**Company** (5):
- POST `/api/v1/companies` [write]
- GET|HEAD `/api/v1/companies/{companyId}`
- GET|HEAD `/api/v1/companies/{companyId}/pos-settings`
- GET|HEAD `/api/v1/companies/{companyId}/reservation-settings`
- GET|HEAD `/api/v1/company/locations`

**Coupon** (5):
- GET|HEAD `/api/v1/coupons`
- GET|HEAD `/api/v1/coupons/{id}`
- DELETE `/api/v1/coupons/{id}` [write]
- POST `/api/v1/coupons/{id}/reactivate` [write]
- POST `/api/v1/coupons/{id}/revoke` [write]

**Dashboard** (1):
- GET|HEAD `/api/v1/dashboard/stats`

**Identity** (9):
- POST `/api/v1/auth/logout` [write]
- POST `/api/v1/auth/logout-all` [write]
- GET|HEAD `/api/v1/auth/me`
- POST `/api/v1/auth/resend-verification` [write]
- GET|HEAD `/api/v1/permissions`
- GET|HEAD `/api/v1/roles`
- GET|HEAD `/api/v1/roles/{id}`
- GET|HEAD `/api/v1/user/companies`
- GET|HEAD `/api/v1/users/{userId}/roles`

**Inventory** (5):
- GET|HEAD `/api/v1/inventory/countings/my-tasks`
- GET|HEAD `/api/v1/inventory/countings/{counting}/counter-view`
- GET|HEAD `/api/v1/inventory/countings/{counting}/items/to-count`
- POST `/api/v1/inventory/countings/{counting}/items/{item}/count` [write]
- GET|HEAD `/api/v1/inventory/countings/{counting}/lookup`

**Media** (1):
- GET|HEAD `/api/v1/attachments/config`

**Menu** (6):
- GET|HEAD `/api/v1/active-menu`
- DELETE `/api/v1/menu-categories/{categoryId}/items/{itemId}` [write]
- DELETE `/api/v1/menu-categories/{id}` [write]
- GET|HEAD `/api/v1/menus`
- GET|HEAD `/api/v1/menus/{id}`
- DELETE `/api/v1/menus/{id}` [write]

**Notification** (4):
- GET|HEAD `/api/v1/notifications`
- POST `/api/v1/notifications/read-all` [write]
- GET|HEAD `/api/v1/notifications/unread-count`
- POST `/api/v1/notifications/{id}/read` [write]

**POS** (5):
- GET|HEAD `/api/v1/pos/authorized-managers`
- POST `/api/v1/pos/cart/preview-discounts` [write]
- GET|HEAD `/api/v1/pos/discount-permissions`
- GET|HEAD `/api/v1/pos/fraud-settings`
- GET|HEAD `/api/v1/pos/payment-policy`

**POS-closures** (4):
- POST `/api/v1/pos/orders/{id}/close` [write] [tombstone-410]
- POST `/api/v1/pos/receipts` [write] [tombstone-410]
- POST `/api/v1/pos/receipts/{id}/payments` [write] [tombstone-410]
- POST `/api/v1/pos/receipts/{id}/void` [write] [tombstone-410]

**PlatformIntegration** (18):
- POST `/api/v1/platform/barcode-lookup` [write]
- GET|HEAD `/api/v1/platform/catalog/articles`
- GET|HEAD `/api/v1/platform/catalog/articles/cross-reference`
- GET|POST|HEAD `/api/v1/platform/catalog/articles/search-by-criteria` [write]
- GET|HEAD `/api/v1/platform/catalog/articles/{articleId}`
- GET|HEAD `/api/v1/platform/catalog/articles/{articleId}/linkages`
- GET|HEAD `/api/v1/platform/catalog/criteria`
- GET|HEAD `/api/v1/platform/catalog/manufacturers`
- GET|HEAD `/api/v1/platform/catalog/manufacturers/{manufacturerId}/model-series`
- GET|HEAD `/api/v1/platform/catalog/model-series/{modelSeriesId}/vehicles`
- GET|HEAD `/api/v1/platform/catalog/search-tree/roots`
- GET|HEAD `/api/v1/platform/catalog/search-tree/{nodeId}/articles`
- GET|HEAD `/api/v1/platform/catalog/search-tree/{nodeId}/children`
- GET|HEAD `/api/v1/platform/catalog/suppliers`
- GET|HEAD `/api/v1/platform/catalog/vehicles/{vehicleType}/{vehicleId}`
- GET|HEAD `/api/v1/platform/catalog/vehicles/{vehicleType}/{vehicleId}/articles`
- POST `/api/v1/platform/vin-decode` [write]
- POST `/api/v1/platform/vin-decode/confirm-match` [write]

**Product** (7):
- GET|HEAD `/api/v1/categories`
- POST `/api/v1/categories` [write]
- POST `/api/v1/categories/reorder` [write]
- GET|HEAD `/api/v1/categories/tree`
- GET|HEAD `/api/v1/categories/{id}`
- PUT `/api/v1/categories/{id}` [write]
- DELETE `/api/v1/categories/{id}` [write]

**Progression** (8):
- GET|HEAD `/api/v1/progression/milestones`
- GET|HEAD `/api/v1/progression/modules`
- POST `/api/v1/progression/modules/{moduleId}/activate` [write]
- GET|HEAD `/api/v1/progression/profile`
- GET|HEAD `/api/v1/progression/recommendations`
- POST `/api/v1/progression/recommendations/{recommendationId}/accept` [write]
- POST `/api/v1/progression/recommendations/{recommendationId}/dismiss` [write]
- POST `/api/v1/progression/register` [write]

**Promotion** (4):
- DELETE `/api/v1/promotions/{id}` [write]
- POST `/api/v1/promotions/{id}/activate` [write]
- POST `/api/v1/promotions/{id}/archive` [write]
- POST `/api/v1/promotions/{id}/pause` [write]

**PurchaseHub** (5):
- GET|HEAD `/api/v1/purchase-hub/offers`
- GET|HEAD `/api/v1/purchase-hub/offers/{id}`
- POST `/api/v1/purchase-hub/orders` [write]
- GET|HEAD `/api/v1/purchase-hub/orders`
- GET|HEAD `/api/v1/purchase-hub/orders/{id}`

**Scheduling** (4):
- GET|HEAD `/api/v1/scheduling/calendar/day`
- GET|HEAD `/api/v1/scheduling/calendar/free-slots`
- GET|HEAD `/api/v1/scheduling/calendar/month`
- GET|HEAD `/api/v1/scheduling/calendar/week`

**Service** (11):
- GET|HEAD `/api/v1/service-categories`
- POST `/api/v1/service-categories` [write]
- GET|HEAD `/api/v1/service-categories/tree`
- GET|HEAD `/api/v1/service-categories/{category}`
- PATCH `/api/v1/service-categories/{category}` [write]
- DELETE `/api/v1/service-categories/{category}` [write]
- GET|HEAD `/api/v1/services`
- POST `/api/v1/services` [write]
- GET|HEAD `/api/v1/services/{service}`
- PATCH `/api/v1/services/{service}` [write]
- DELETE `/api/v1/services/{service}` [write]

**SmartPrompts** (1):
- POST `/api/v1/smart-prompts/recommendations` [write]

**SupportAccess** (1):
- POST `/api/v1/support-access/sessions/{session}/exit` [write]

**Taxation** (5):
- GET|HEAD `/api/v1/taxation/configurations`
- GET|HEAD `/api/v1/taxation/configurations/capabilities`
- GET|HEAD `/api/v1/taxation/configurations/document-types`
- GET|HEAD `/api/v1/taxation/configurations/{id}`
- POST `/api/v1/withholding/preview` [write]

**Treasury** (1):
- GET|HEAD `/api/v1/banks`

**Unknown** (2):
- GET|HEAD `/api/v1/company/config`
- GET|HEAD `/api/v1/subscription`

**Uom** (9):
- GET|HEAD `/api/v1/uom/categories`
- POST `/api/v1/uom/convert` [write]
- POST `/api/v1/uom/unit-text-mappings` [write]
- GET|HEAD `/api/v1/uom/unit-text-mappings/unmapped`
- GET|HEAD `/api/v1/uom/units`
- POST `/api/v1/uom/units` [write]
- GET|HEAD `/api/v1/uom/units/{id}`
- PUT `/api/v1/uom/units/{id}` [write]
- DELETE `/api/v1/uom/units/{id}` [write]

## Mixed enforcement styles — permission strings used in middleware vs. controller-level checks

- **195** distinct permission strings appear in `can:`/`require.any.permission:` route middleware.
- **85** distinct permission strings appear in controller/FormRequest/service-level checks (`Gate::`, `->can(`, `->authorize(`, `->hasPermissionTo(`).
- **33** permission strings are used in **both** styles (same permission enforced at middleware level on some routes and at controller level on others — e.g. resource `index`/`show` via `can:` route middleware but `destroy`/action-endpoints via `Gate::authorize()` in the method body, or vice versa). This is the concrete evidence of "mixed styles" the task asked to surface. Full overlap list:
  `accounts.manage, bank-statements.reopen, batches.write-off, catalog.attributes.create, catalog.attributes.update, catalog.labels.print, catalog.variants.create, catalog.variants.update, composite-items.create, composite-items.manage-recipes, composite-items.update, enrichment.review, enrichment.view, inventory.adjust, inventory.adjustments.post, inventory.transfers.create, modifier-groups.manage, pos.operate_terminal, pricing.view_cost_prices, purchase-orders.create, replenishment.process, settings.update, settings.view, support-access.manage, taxation.withholding_rules.manage, users.manage_location_access, vehicles.log_mileage, vehicles.manage_ownership, workshop.payroll.generate, workshop.technicians.manage_certifications, workshop.technicians.manage_time_entries, workshop.technicians.manage_time_off, workshop.technicians.view`
- **162** permissions appear ONLY at middleware level (the more common/preferred pattern in this codebase — 642 of 1054 routes use it).
- **52** permissions appear ONLY at controller level (dominated by the POS module's `pos.*` permissions and the Workshop/Expense/Identity modules' `Gate::`/`->can()` calls). Full controller-only list:
  `batches.create, batches.update, coupons.manage, delete, enrichment.submit, menus.manage, payments.pay-supplier, pos.audit_sync, pos.configure_cash_count, pos.fiscal_schema_cutover, pos.generate_z_report, pos.manage_shifts, pos.manage_tables, pos.manage_terminals, pos.process_returns, pos.view_cross_location_stock, pos.view_receipts, pos.view_reports, post, pricing.sell_below_cost, pricing.sell_below_minimum_margin, pricing.sell_below_target_margin, promotions.manage, promotions.view, revert, roles.manage, scheduling.appointments.cancel, scheduling.appointments.convert, scheduling.appointments.create, scheduling.appointments.update, scheduling.appointments.view, scheduling.bays.manage, scheduling.bays.view, settings.fiscal.update, update, users.assign-roles, users.create, users.delete, users.update, users.view, view, work-orders.approve, work-orders.assign, work-orders.cancel, work-orders.complete, work-orders.create, work-orders.transition, work-orders.update, work-orders.view, work-orders.view_financials, workshop-bundles.manage, workshop-bundles.view`

## FormRequest `authorize()` returns `true` unconditionally (no check)

**180** routes inject a FormRequest whose `authorize()` is either absent (inherits the Laravel base `FormRequest::authorize()` → `true`) or explicitly `return true;` with nothing else. Breakdown by HTTP verb: POST=107, GET|HEAD=33, PATCH=25, PUT=11, PUT|PATCH=3, DELETE=1.
This is not automatically a gap — for most of these the FormRequest only validates input shape while the actual permission gate lives at the route's `can:` middleware (the common, intended pattern: FormRequest = validation, middleware = authorization). It only becomes a real gap when a route is BOTH FormRequest-open AND has no middleware permission AND lands in AUTH_ONLY above — that subset (10 routes: `withholding/preview`, `inventory/countings/.../count`, `services` create/update, `service-categories` create/update, `uom/units` create/update, `uom/unit-text-mappings`, `companies` create) is already called out with citations in the risk list above.

## Permission-string vs. seeder cross-check

Cross-checked every **dot-notated** permission string referenced by any route/controller/FormRequest/service against `database/seeders/RolesAndPermissionsSeeder.php::permissionNames()` (304 entries). Bare Policy-ability words (`view`, `update`, `delete`, `post`, `revert`, …) were excluded — they're Laravel Policy abilities, not Spatie permission names, and aren't expected in that seeder.

- **`credit-notes.cancel`** — referenced by 1 route(s): POST /api/v1/credit-notes/{creditNote}/cancel.
  - Finding: `can:credit-notes.cancel` is declared as route middleware at `app/Modules/Document/Presentation/routes.php:270-271`, but `credit-notes.cancel` is **absent** from `database/seeders/RolesAndPermissionsSeeder.php`. It IS defined in a **second, separate** permission seeder, `database/seeders/PermissionSeeder.php:72` (and again at `:214` in a role-grant list). Wiring check: `database/seeders/ProductionSeeder.php` calls **both** `RolesAndPermissionsSeeder::class` (line 70) and `PermissionSeeder::class` (line 75), so production gets the permission seeded. But `database/seeders/DatabaseSeeder.php:76` calls **only** `RolesAndPermissionsSeeder::class` — `PermissionSeeder` is never invoked from the default/dev seeding path. Net effect: any environment seeded via plain `DatabaseSeeder` (local dev, most test setups) will never have a `credit-notes.cancel` permission row to assign to any role, so `can:credit-notes.cancel` middleware will 403 every caller on that environment regardless of role — this is a real seeder-wiring gap, not just a naming inconsistency.

## POLICY-classified routes (Laravel Policy, not Spatie permission)

**8** routes resolve via a registered Laravel Policy rather than a permission string:
- GET|HEAD `/api/v1/expenses/{id}` — controller:view
- PUT|PATCH `/api/v1/expenses/{id}` — controller:update | formrequest[ExpenseRequest]:OPEN(returns true)
- DELETE `/api/v1/expenses/{id}` — controller:delete
- POST `/api/v1/expenses/{id}/post` — controller:post
- POST `/api/v1/expenses/{id}/reverse` — controller:post
- GET|HEAD `/api/v1/expense-categories/{id}` — controller:view
- PUT|PATCH `/api/v1/expense-categories/{id}` — controller:update | formrequest[ExpenseCategoryRequest]:OPEN(returns true)
- DELETE `/api/v1/expense-categories/{id}` — controller:delete

All 8 are on `Document`-typed models (`Expense`/`ExpenseCategory` sub-documents route through `App\Policies\DocumentPolicy` and `App\Policies\ExpenseCategoryPolicy` respectively, registered in `app/Providers/AppServiceProvider.php:275-276`). `Gate::authorize('view'|'update'|'delete'|'post', $expenseOrCategory)` delegates to the policy's `view()`/`update()`/`delete()`/`post()` methods, which presumably check the acting user's permissions/role internally — this sweep did not descend into the Policy class bodies (out of the one-hop budget), so treat these 8 as "a check exists" rather than verified-correct.

## Limitations / what a script-only sweep can miss

- **One-hop only**: a permission check two hops away (controller → service A → service B) would not be found and the route would misclassify as AUTH_ONLY. Only 10 routes hit the one-hop service path at all in this codebase, so the blast radius of this limitation looks small, but it is not zero-risk.
- **Regex-based pattern matching**, not full static analysis: a check wrapped in an unusual conditional, a trait method, an abstract base-controller `boot()`/constructor hook, or a custom macro would not be matched unless it's literally `->can(`, `->authorize(`, `Gate::`, `->hasPermissionTo(`, `->hasRole(`, `->hasAnyRole(`, or `abort_unless/abort_if(...can...)` textually inside the route's own controller method or FormRequest `authorize()`.
- **Policy class bodies were not opened** — POLICY-classified routes are trusted to "have a check" without verifying the policy method's actual logic.
- **PHPUnit was not run** (per instructions) — none of these classifications were verified against actual HTTP behavior (e.g. a 403 test). This is a static/mechanical sweep only.
- **UNKNOWN = 0**: every route resolved to a controller/closure with a readable file, so nothing fell into the catch-all UNKNOWN bucket. That's a sign the reflection approach had full coverage on this codebase, not necessarily that every classification is semantically correct (see POLICY and one-hop caveats above).

