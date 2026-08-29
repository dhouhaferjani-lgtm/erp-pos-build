# New Feature End-to-End Checklist

Use this checklist when adding a complete new feature spanning backend and frontend.

## Backend

### 1. Module Structure
Create directory tree under `apps/api/app/Modules/{ModuleName}/`:
- `Domain/` — Eloquent models (direct or in `Entities/`), plus:
  - `Enums/`, `Events/`, `Services/`, `Repositories/` (interfaces)
  - Optional: `ValueObjects/`, `Exceptions/`
- `Application/DTOs/`, `Application/Services/`
  - Optional: `Application/Commands/`, `Application/Queries/`
- `Infrastructure/Repositories/` (Eloquent implementations)
  - Optional: `Infrastructure/Listeners/`, `Infrastructure/External/`
- `Presentation/Controllers/`, `Presentation/Requests/`, `Presentation/Resources/`
- `Providers/{ModuleName}ServiceProvider.php`

> **Note:** Models can live at `Domain/Product.php` or `Domain/Entities/Batch.php` — both patterns exist in the codebase. Pick one per module and stay consistent.

### 2. ServiceProvider
Register in `apps/api/bootstrap/providers.php`. Bind repository interfaces to Eloquent implementations.

### 3. Routes (`routes.php`)
**Must use exact middleware pattern:**
```php
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;

Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class])->group(function () {
    // routes
});
```
Missing `'api'` = 401 errors. Missing `SetPermissionsTeam` = permission failures.

**If the feature is vertical-exclusive** (only some verticals should see it — see
[vertical-module-gating.md](../../docs/architecture/vertical-module-gating.md)):
- (a) Add `module:<Name>` middleware to its `routes.php` (`<Name>` = a `ModuleName` enum case, case-sensitive).
- (b) Gate its FE route with `<RequirePermission moduleKey="...">` (or `ModuleGuard`).
- (c) Gate any vertical-specific fields/sections inline with `hasModule('<Name>')` from `useCompanyConfig()`.
- (d) Add a module-access-control test asserting a wrong-vertical tenant gets **403** from the backend route.

### 4. Controller
- Constructor injection only (never `app()`)
- `private readonly` for all dependencies
- Thin: validate -> delegate to service -> return JsonResource

### 5. FormRequest
Extend `FormRequest`. Define `authorize()` and `rules()`.

### 6. Response DTOs (preferred) or JsonResource
The primary pattern uses Spatie LaravelData DTOs with the `#[TypeScript]` attribute:
```php
#[TypeScript]
class ProductData extends Data
{
    public function __construct(
        public readonly string $uuid,
        public readonly string $name,
        // ...
    ) {}
}
```
Controller returns: `ProductData::from($model)` (Laravel Data handles JSON serialization).

DTOs with `#[TypeScript]` are preferred because `php artisan typescript:transform` auto-generates TypeScript types from them.

JsonResource (`Presentation/Resources/`) is also used in some modules (e.g., `BatchResource`) and remains valid as a secondary pattern.

### 7. Permissions
Add to `apps/api/database/seeders/RolesAndPermissionsSeeder.php`:
- Standard CRUD: `{resource}.view`, `{resource}.create`, `{resource}.update`, `{resource}.delete`
- Domain-specific actions as needed: `invoices.post`, `invoices.cancel`, `receipts.void`, `settings.manage`, etc.
- Admin gets all, Manager gets operational, Viewer gets read-only
- Run: `php artisan db:seed --class=RolesAndPermissionsSeeder`

### 8. Type Generation
After modifying DTOs: `php artisan typescript:transform`
Generated types go to `packages/shared/types/`

## Frontend

### 9. API Client (`apps/web/src/features/{feature}/api/`)
```typescript
// CORRECT - apiGet already unwraps response.data.data
return apiGet<Item[]>('/items')

// WRONG - do NOT double-unwrap
const res = await apiGet<{data: Item[]}>('/items')
return res.data  // undefined!
```

### 10. React Query Hooks
Use key factory pattern. See `docs/conventions/05-REACT-QUERY.md`.

### 11. Pages
- List page, Detail page, Form page (create/edit)
- All text via `useTranslation` (no hardcoded strings)

### 12. Route Registration (`apps/web/src/routes/index.tsx`)
- Lazy import: `const Page = lazy(() => import(...))`
- Wrap with `RequirePermission` and `SuspenseWrapper`

### 13. Sidebar Entry (`apps/web/src/components/organisms/Sidebar/Sidebar.tsx`)
Add navigation item with permission check.

### 14. i18n Namespace
- Create `apps/web/src/locales/en/{namespace}.json`
- Create `apps/web/src/locales/fr/{namespace}.json`
- Update `apps/web/src/lib/i18n.ts` in 3 places:
  1. Import the JSON files
  2. Add to `resources` object
  3. Add namespace to `ns` array

## Cross-cutting (added 2026-08-29, Session I)

### 15. Second-of-everything, glossary, baseline
- Catalogue entity touched (code/SKU/number/name-keyed, operator-edited)? Add the second-company, second-location and re-run tests — `docs/conventions/09-SECOND-OF-EVERYTHING.md`. No new `unique(['tenant_id', …])` without `company_id` or a waiver.
- New noun? Add its row to `docs/glossary.md` in the same lane; one table, one write path, one operator surface — `docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md`.
- User-facing flow? The spec opens with the industry-baseline table — `docs/conventions/10-BENCHMARK-FIRST-SPECS.md`. If the flow is part of onboarding, add/extend a campaign leg — `docs/qa/ONBOARDING-CAMPAIGN.md`.
