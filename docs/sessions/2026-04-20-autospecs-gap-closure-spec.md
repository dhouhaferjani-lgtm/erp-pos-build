# AutoSpecs cluster — Phase A gap closure spec

**Status:** Load-bearing spec for Phase A implementer subagents.
**Source gaps doc:** `/Users/houssamr/Projects/syneriva/docs/sessions/2026-04-20-autospecs-discovered-gaps.md` (parent syneriva repo).
**Branches base:** `origin/dev` at `e2a58682` (post PR #10 merge).
**Tracking PR:** Umbrella PR #11 (`dev → main`) remains open; each Phase A branch lands on `dev` first via its own PR.

---

## 0. Execution order + cadence (DO NOT violate)

| # | Branch | Depends on |
|---|--------|------------|
| A.1 | `chore/autospecs-demo-seeder` | `origin/dev@e2a58682` |
| A.2 | `feat/autospecs-pickers` | A.1 merged to `dev` (needs seeded data to test) |
| A.3 | `feat/autospecs-bundle-authoring-ui` | A.2 merged to `dev` |
| A.4 | `feat/autospecs-technician-authoring` | A.3 merged to `dev` |

Each branch: worktree under `apps/erp/.worktrees/<branch-name>` off `origin/dev`, TDD, preflight green, PR to `dev`, pause for human review.

---

## 1. Shared context (all Phase A branches)

### 1.1 Vertical enum (confirmed)

- **FQN:** `App\Enums\Vertical`
- **File:** `apps/api/app/Enums/Vertical.php`
- **Mechanic case:** `Vertical::Mechanic` — resolves to `product() === 'otospex'`.
- **Column:** `tenants.vertical` (string-cast enum).

### 1.2 Module route middleware pattern (copy exactly)

All `routes.php` new additions use the stack enforced by CLAUDE.md rule #12:

```php
Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', \App\Http\Middleware\SetPermissionsTeam::class, 'module:Workshop'])
    ->group(function (): void {
        Route::post('/workshop/…')->middleware('can:workshop.…')->name('workshop.…');
    });
```

Missing `'api'` → 401. Missing `SetPermissionsTeam` → permission failures. Missing `module:Workshop` → untenanted route.

### 1.3 TDD workflow (CLAUDE.md rule #2)

- **Backend:** write a failing PHPUnit test first (`apps/api/tests/…`). Use `RefreshDatabase` + `RolesAndPermissionsSeeder` per CLAUDE.md testing conventions — **never mock** API responses.
- **Frontend:** Vitest + `renderWithProviders` at `apps/web/src/test/renderWithProviders.tsx`. Component tests MAY `vi.mock` hooks/i18n/router, per memory `MEMORY.md#Testing Conventions`. Never mock axios in API-layer tests.

### 1.4 Preflight (CLAUDE.md rule #10) — must pass before PR

```
./scripts/preflight.sh
```
Runs: Pint → PHPStan (level 8) → PHPUnit (`php artisan test`) → TypeScript transform (`php artisan typescript:transform`) → ESLint → `tsc --noEmit` → Vitest.

**Per-branch shortcut commands** (run during iteration):

```
cd apps/api && ./vendor/bin/pint && ./vendor/bin/phpstan analyse && php artisan test --filter=<TestClass>
cd apps/web && pnpm lint && pnpm typecheck && pnpm test --run
```

### 1.5 Strict-typing + no mocks in prod code (CLAUDE.md rules #1, #3)

- PHP: no `mixed`; all seeder data must use real enum casts and UUIDs from `Str::uuid()->toString()`.
- TS: no `any`. All new types flow from backend DTOs via `php artisan typescript:transform` — generated files in `packages/shared/types/` are not to be hand-edited (rule #7, and user's `Do NOT touch` list).
- **Do NOT touch** `packages/shared/types/*`, `apps/api/app/Modules/Catalog/*`, `apps/api/app/Modules/Menu/*`, `DocumentVehicleContext`, any fiscal-hash column.

### 1.6 i18n (CLAUDE.md rule #11)

All user-facing strings in new frontend code → `t()` keys under the correct namespace. Add new namespaces via `/project:add-i18n-namespace` if needed.

### 1.7 Design tokens (CLAUDE.md rule #18)

New `.tsx` files in `apps/web/src/` must use tokens from `@/lib/designTokens` exclusively — no `bg-blue-600`/`text-gray-700`/etc.

### 1.8 Work Order state machine (canonical reference)

Any branch that touches WorkOrder status transitions, events, or endpoints must align with **`docs/modules/workshop-work-orders.md`** — that doc is the source of truth for the shipped state machine (11 states, 20 edges). Pre-merge drafts of this spec and `docs/otospex/ROADMAP.md` §6.2 mentioned states (`Draft`, `Parts Reserved`, `Paid`) that were never shipped; ignore those and use the module doc.

Specifically: where §5 below references WO statuses (e.g. §5.3.3's `completed` / `invoiced` immutability rule for time entries), the authoritative value list is `WorkOrderStatus` at `apps/api/app/Modules/Workshop/WorkOrder/Domain/Enums/WorkOrderStatus.php`, cross-referenced in the module doc.

---

## 2. Phase A.1 — `chore/autospecs-demo-seeder`

### 2.1 Problem (from gaps doc)

- Plan A #4: `DemoTenantSeeder` does not seed roles/permissions nor reliably assign admin role; every smoke had to run `RolesAndPermissionsSeeder` + manual role assignment + manual tenant vertical flip.
- Plan A.5 #4: Bundles seeded with 0 components + no referenced products/services → demos show empty bundles.
- Plan D #1: `CreateTimeEntryOnWorkOrderStarted` listener blows up on missing `TechnicianProfile` — seeder must provision at least one.
- Plan C #1/#2/#3: `seedWorkshopTechnicians` not wired; default vertical was `retail` even for the unlimited demo; factory-created users need `status=Active`, `email_verified_at`, membership row.
- Tunisia COA not seeded for demo tenants.

### 2.2 Scope (inclusive)

Extend `apps/api/database/seeders/DemoTenantSeeder.php` and supporting seeders so that a clean `php artisan migrate:fresh --seed` produces a demo tenant where an operator can log in as `admin@demo.local / password` and execute all 5 AutoSpecs plan flows end-to-end without any manual fixup.

### 2.3 Required additions — ordered by dependency

#### 2.3.1 Boot call in `DemoTenantSeeder::run()`

Immediately after `PlansSeeder`, call `RolesAndPermissionsSeeder` explicitly (even though `PlansSeeder` already does so, make the dependency direct for readability and safety against `PlansSeeder` refactors):

```php
public function run(): void
{
    $this->call(PlansSeeder::class);
    $this->call(RolesAndPermissionsSeeder::class);

    $this->createUnlimitedDemoTenant();
    // … existing tenants …
}
```

#### 2.3.2 `createUnlimitedDemoTenant()` changes

- Set `Tenant::updateOrCreate([... slug='demo-unlimited' ...], ['vertical' => Vertical::Mechanic, …])` (import `App\Enums\Vertical`).
- Admin user: `admin@demo.local` (NOT `admin@otospex.com` — that collides with real Otospex admin; see `MEMORY.md#userEmail`). Set:
  - `status => UserStatus::Active`
  - `email_verified_at => now()`
  - `password => Hash::make('password')`
- Call Tunisia COA seeder with the primary company id: `$this->callWith(TunisiaChartOfAccountsSeeder::class, ['companyId' => $company->id, 'tenantId' => $tenant->id]);`. Guard with a `DB::table('chart_of_accounts')->where('company_id', $company->id)->doesntExist()` check for idempotency.
- Call new helpers **in this exact order** (each introduced below):
  1. `seedAutomotiveCatalog($tenant, $company)` — products, services, unit-of-measure.
  2. `seedWorkshopTechnicians($tenant, $company, $adminUser)` — 3 technicians.
  3. `seedWorkshopBundles($tenant, $company)` — existing, but now `hydrateBundleComponents($bundles)` after creation.
  4. `seedWorkOrders(...)` — existing.
  5. `seedScheduling(...)` — existing.

#### 2.3.3 New private helper: `seedAutomotiveCatalog`

Seeds the minimum automotive inventory required to hydrate the 6 bundle headers listed in §2.3.5. Use `App\Modules\Uom\Domain\Entities\Unit`, `App\Modules\Product\Domain\Product`, `App\Modules\Service\Domain\Service`.

**Units** (create or reuse):
- `L` (litre), `EA` (each), `HR` (hour), `KG` (kilogram)

**Products** (all `is_active=true`, `is_physical=true`, `type=ProductType::Goods`, `currency=TND`):

| SKU | Name | Unit | Sale price | Tax rate |
|-----|------|------|------------|----------|
| OIL-5W30-5L | Huile moteur 5W30 (bidon 5L) | L | 85.000 | 19 |
| OIL-10W40-5L | Huile moteur 10W40 (bidon 5L) | L | 75.000 | 19 |
| FILT-OIL-STD | Filtre à huile standard | EA | 25.000 | 19 |
| FILT-OIL-DIESEL | Filtre à huile diesel | EA | 30.000 | 19 |
| FILT-AIR-STD | Filtre à air standard | EA | 18.000 | 19 |
| BRAKE-PAD-FRONT | Plaquettes de frein (avant, jeu) | EA | 95.000 | 19 |
| BRAKE-DISC | Disque de frein | EA | 120.000 | 19 |
| TIRE-195-65-R15 | Pneu 195/65 R15 | EA | 210.000 | 19 |
| COOLANT-1L | Liquide de refroidissement 1L | L | 22.000 | 19 |
| SPARK-PLUG | Bougie d'allumage | EA | 12.000 | 19 |

**Services** (all `is_active=true`, `currency=TND`):

| Code | Name | Pricing type | Base price / hourly rate | Default duration (min) |
|------|------|--------------|--------------------------|-------------------------|
| LAB-OIL-CHANGE | Vidange + remplacement filtres | `hourly` | 45.000/h | 45 |
| LAB-BRAKE-FRONT | Remplacement plaquettes de frein avant | `hourly` | 45.000/h | 60 |
| LAB-ALIGN | Parallélisme | `flat_rate` | 80.000 | 60 |
| LAB-DIAG-OBD | Diagnostic OBD électronique | `flat_rate` | 60.000 | 45 |
| LAB-TIRE-MOUNT | Montage + équilibrage pneu | `hourly` | 35.000/h | 30 |
| LAB-TIMING-BELT | Remplacement courroie de distribution | `hourly` | 50.000/h | 240 |

**Idempotency rule:** Use `Product::updateOrCreate(['tenant_id' => $tenant->id, 'sku' => …], [...])` and `Service::updateOrCreate(['tenant_id' => $tenant->id, 'code' => …], [...])`.

#### 2.3.4 New private helper: `seedWorkshopTechnicians`

Three `TechnicianProfile` rows for the mechanic demo tenant:

| For user | skill_level | specialties (array<SpecialtyCode>) | hourly_cost_rate | hourly_billing_rate | employment_status | employee_code | weekly_schedule |
|----------|-------------|-----------------------------------|-----------------|---------------------|-------------------|---------------|----------------|
| `admin@demo.local` (existing adminUser) | `Master` | `[GeneralService, PreControl]` | 25.00 | 60.00 | `active` | `ADMIN-01` | Mon-Fri 08:00-17:00 |
| new user `yassine.trabelsi@demo.local` (create if missing, same bootstrap pattern: Active + verified + admin-team role `Technician`) | `Senior` | `[EngineMechanical, Brakes, Diesel]` | 18.00 | 45.00 | `active` | `TECH-01` | Mon-Fri 08:00-17:00 |
| new user `sarra.ben-ali@demo.local` (same bootstrap) | `General` | `[Tires, Alignment, AcClimate]` | 14.00 | 35.00 | `active` | `TECH-02` | Mon-Sat 09:00-18:00 (Sat half-day 09:00-13:00) |

**Weekly schedule JSON shape** (confirmed against migration `2026_04_19_120001`):

```json
{
  "monday":    [{"start": "08:00", "end": "17:00"}],
  "tuesday":   [{"start": "08:00", "end": "17:00"}],
  "wednesday": [{"start": "08:00", "end": "17:00"}],
  "thursday":  [{"start": "08:00", "end": "17:00"}],
  "friday":    [{"start": "08:00", "end": "17:00"}],
  "saturday":  [],
  "sunday":    []
}
```

**Certifications:** each of the 2 named technicians gets one `workshop_technician_certifications` row referencing a Product-module `Certification` seeded in `CertificationsSeeder`. If `CertificationsSeeder` isn't already called elsewhere in the run, call it first. Choose a plausible pair (e.g. `ASE-Master`, `Michelin-Tire-Certified`).

**Membership:** each new technician user gets a `UserCompanyMembership` with `role = MembershipRole::Technician`, `status = MembershipStatus::Active`, `is_primary = true`, `company_id = $company->id`.

**Spatie role:** after `setPermissionsTeamId($tenant->id)`, call `$user->assignRole('technician')`. Confirm `RolesAndPermissionsSeeder` creates a `technician` role — if not, assign the closest existing role and note the gap; don't silently skip.

#### 2.3.5 New private helper: `hydrateBundleComponents`

Wire components for each of the 6 already-seeded bundle headers. Use `ServiceBundleComponent::updateOrCreate(['tenant_id' => ..., 'bundle_id' => $bundle->id, 'display_order' => $i], [...])` for idempotency.

| Bundle code | Components (display_order : type : ref : qty : unit) |
|-------------|-------------------------------------------------------|
| VIDANGE-10K-ESSENCE | 1 : Part : product `OIL-5W30-5L` : 1 : L; 2 : Part : product `FILT-OIL-STD` : 1 : EA; 3 : Labor : service `LAB-OIL-CHANGE` : 0.75 : HR |
| VIDANGE-10K-DIESEL | 1 : Part : product `OIL-10W40-5L` : 1 : L; 2 : Part : product `FILT-OIL-DIESEL` : 1 : EA; 3 : Part : product `FILT-AIR-STD` : 1 : EA; 4 : Labor : service `LAB-OIL-CHANGE` : 1 : HR |
| FREINAGE-AV | 1 : Part : product `BRAKE-PAD-FRONT` : 1 : EA; 2 : Part : product `BRAKE-DISC` : 2 : EA; 3 : Labor : service `LAB-BRAKE-FRONT` : 1 : HR |
| REVISION-40K | 1 : NestedBundle : bundle `VIDANGE-10K-ESSENCE` (see note); 2 : Part : product `COOLANT-1L` : 2 : L; 3 : Part : product `SPARK-PLUG` : 4 : EA; 4 : Labor : service `LAB-TIMING-BELT` : 4 : HR |
| PNEUS-REMPLACEMENT-4 | 1 : Part : product `TIRE-195-65-R15` : 4 : EA; 2 : Labor : service `LAB-TIRE-MOUNT` : 2 : HR; 3 : Labor : service `LAB-ALIGN` : 1 : EA |
| DIAGNOSTIC-OBD | 1 : Labor : service `LAB-DIAG-OBD` : 1 : EA |

**Note on `REVISION-40K`:** the `NestedBundle` component type requires the referenced bundle to be pre-seeded. Because we hydrate after all 6 headers exist, this ordering is safe — but if `BundleExpansionService` enforces non-cyclicality at component insertion time (it does per §5 of PR #5), the nested bundle must not contain REVISION-40K in its own chain. VIDANGE-10K-ESSENCE has no nested bundle components, so this is fine.

**Mixed-VAT guard (per PR #5):** all seeded products/services use `tax_rate = 19`, so bundles remain single-rate and pass the mixed-VAT guard.

#### 2.3.6 Idempotency

The full seeder must support `php artisan db:seed --class=DemoTenantSeeder` being run **twice back to back** with no errors and no duplicate rows. Audit every `create()` in helpers and convert to `updateOrCreate(['unique_key' => …], [...])`.

### 2.4 Tests (TDD order)

Write these first in `apps/api/tests/Feature/Seeders/DemoTenantSeederTest.php`. They must fail before implementation:

1. `test_fresh_seed_creates_mechanic_demo_tenant_with_correct_vertical`
2. `test_admin_demo_local_user_is_active_and_verified_and_has_admin_role`
3. `test_admin_demo_local_has_primary_company_membership_with_admin_role`
4. `test_seeder_creates_at_least_3_technician_profiles_for_mechanic_tenant`
5. `test_admin_demo_local_has_technician_profile_for_listener_safety`
6. `test_all_6_bundles_have_at_least_1_component_after_seed`
7. `test_vidange_10k_essence_has_exact_3_components_in_correct_order`
8. `test_tunisia_chart_of_accounts_seeded_for_mechanic_tenant_company`
9. `test_seeder_is_idempotent_when_run_twice` (runs seed, counts, runs seed again, counts match)
10. `test_automotive_catalog_seeds_expected_products_and_services` (assert count + key SKUs)

### 2.5 Acceptance criteria

- `./scripts/preflight.sh` green.
- `php artisan migrate:fresh --seed` completes with zero errors.
- New test file has 10 passing tests.
- Manual sanity (document in PR body, not automated): log in as `admin@demo.local / password` → sidebar shows Workshop group → navigate to Bundles → VIDANGE-10K-ESSENCE has 3 components visible → Technicians list shows 3 names → creating a work order + starting it does not throw on the Plan C listener.

### 2.6 PR gate

- Branch: `chore/autospecs-demo-seeder`.
- Title: `chore(demo-seeder): full AutoSpecs mechanic demo tenant (admin bootstrap + techs + bundle components + Tunisia COA)`.
- PR body must list the 10 tests + the manual sanity steps above.
- Paused for human review before merge.

---

## 3. Phase A.2 — `feat/autospecs-pickers`

### 3.1 Problem (from gaps doc + Phase A smoke)

- Plan A.5 #2: `BundlePicker` exists but is not mounted anywhere (`apps/web/src/features/workshop-bundles/components/organisms/BundlePicker.tsx`). Never consumed by a route.
- `TransferOwnershipModal` (`apps/web/src/features/vehicles/components/organisms/TransferOwnershipModal.tsx`) — raw UUID input field `new_owner_partner_id` (line ~59).
- `WorkOrderCreatePage` (`apps/web/src/features/workshop-work-orders/pages/WorkOrderCreatePage.tsx`) — raw UUID inputs `customer_partner_id` (line ~100), `vehicle_id` (line ~115).
- `AppointmentFormDrawer` (`apps/web/src/features/scheduling/components/organisms/AppointmentFormDrawer.tsx`) — has `customer_name` + `vehicle_plate` **free-text** fields; linkage to real Partner + Vehicle entities is the real gap (not just "replace UUID input").

### 3.2 Scope

Build three reusable picker molecules and wire them into the three target components. Pickers must:

- Live under `apps/web/src/components/molecules/pickers/` (shared location, not locked to a single feature).
- Use TanStack Query for search with 250ms debounce on input (borrow debounce util from existing `useDebouncedValue` hook; if absent, add one to `apps/web/src/lib/hooks.ts`).
- Expose an accessible combobox pattern (ARIA `role="combobox"` + `role="listbox"`), keyboard navigable (ArrowUp/Down/Enter/Escape).
- Render disabled/loading/empty/error states via existing atoms (`LoadingSpinner`, `EmptyState`).
- Use design tokens exclusively (CLAUDE.md rule #18).
- All user-facing strings via `t()` under a new `pickers` i18n namespace (add via `/project:add-i18n-namespace pickers`).

### 3.3 Pickers to build

#### 3.3.1 `PartnerPicker`

- Props: `value: Partner | null`, `onChange(partner: Partner | null): void`, `label?: string`, `placeholder?: string`, `disabled?: boolean`, `required?: boolean`, `partnerType?: 'customer' | 'supplier' | 'all'` (default `'customer'`).
- Backing hook: `usePartnerSearch(q: string, type: 'customer' | 'supplier' | 'all')` → calls `GET /api/v1/partners?search=<q>&type=<type>&limit=20`. Confirm endpoint accepts `search`; if not, add a search query binding in the Partner controller — this is an allowable scope expansion for Phase A.2 since it's the minimum backend needed to make the picker work. If added, cover with a new feature test in `apps/api/tests/Feature/PartnerSearchTest.php`.
- Item renderer: partner name (bold) + type chip + city (muted) — use existing `Badge`.
- Empty state: `t('pickers.partner.empty')` with `Create new partner` CTA linking to `/partners/new` (opens in a new tab).

#### 3.3.2 `VehiclePicker`

- Props: `value: Vehicle | null`, `onChange(vehicle: Vehicle | null): void`, `partnerId?: string` (when provided, scopes search to that partner's vehicles).
- Backing hook: `useVehicleSearch(q: string, partnerId?: string)` → `GET /api/v1/vehicles?search=<q>&partner_id=<id>&limit=20`. Verify search support on vehicles endpoint; add if missing (same rule as PartnerPicker).
- Item renderer: license plate (mono-font chip, use `tokens.monoFont`) + brand + model + year.
- When `partnerId` changes, clear `value` to avoid cross-owner stale selection.

#### 3.3.3 `BundlePicker` integration (don't rebuild)

The existing `apps/web/src/features/workshop-bundles/components/organisms/BundlePicker.tsx` already exists and uses `useApplicableBundles()`. Move it to `apps/web/src/components/molecules/pickers/BundlePicker.tsx` (keeping the existing API surface identical), update all imports, and add a `value: ServiceBundle | null` + `onChange` prop so it behaves as a controlled form field rather than a command-palette-style selector. The existing search/applicability filtering stays. No new backend.

### 3.4 Wiring

#### 3.4.1 `TransferOwnershipModal`

- Replace the `newOwnerId` text input with `<PartnerPicker value={...} onChange={...} partnerType="customer" required />`.
- Form state (keep `useForm` or whatever exists) now stores the `Partner` object; on submit, post `new_owner_partner_id: value.id`.
- Validation: show inline error if picker is cleared on submit.

#### 3.4.2 `WorkOrderCreatePage`

- Replace `customer_partner_id` raw input with `<PartnerPicker partnerType="customer" required />`.
- Replace `vehicle_id` raw input with `<VehiclePicker partnerId={selectedPartner?.id} required />` — the partner must be chosen first; disable VehiclePicker until partner is set (use `disabled={!selectedPartner}`).
- If the user clears the Partner, clear the Vehicle too.

#### 3.4.3 `AppointmentFormDrawer`

- Convert `customer_name` free-text → `PartnerPicker` with a `allowNewInline` prop that, when true, shows a "+ Add new customer" entry in the empty state. Backend change: the appointment `store` endpoint must accept **either** `customer_partner_id` **or** the legacy `customer_name` + `customer_phone` combo (to preserve storefront/public-booking compatibility per Plan D's CAPTCHA flow). Add request validation rule `sometimes|uuid|exists:partners,id` for `customer_partner_id`; keep `customer_name` as `required_without:customer_partner_id`.
- Convert `vehicle_plate` free-text → `VehiclePicker` with `partnerId={selectedPartner?.id}` (if partner chosen) and the same "allowNewInline" for walk-ins. Add `vehicle_id` to the appointment schema as a nullable UUID FK to `vehicles.id`. **Migration required** (additive nullable column only — safe per rule #8 on events and rule on additive schema changes; confirm no fiscal-hash column touched).

### 3.5 Tests (TDD order)

**Frontend (Vitest + RTL):**
1. `PartnerPicker.test.tsx`: renders, debounces input, shows empty state, selects item via keyboard, clears selection.
2. `VehiclePicker.test.tsx`: all of the above + `partnerId` scoping (changing partnerId clears value).
3. `BundlePicker.test.tsx`: value/onChange controlled behaviour + applicability filter.
4. `TransferOwnershipModal.integration.test.tsx`: submit fires with partner UUID.
5. `WorkOrderCreatePage.integration.test.tsx`: vehicle picker disabled until partner set.
6. `AppointmentFormDrawer.integration.test.tsx`: both linked-entity flow and legacy free-text flow.

**Backend (PHPUnit, only if search endpoints need adding or appointment payload changes):**
- `PartnerSearchTest`: `GET /api/v1/partners?search=…` returns matches.
- `VehicleSearchTest`: `GET /api/v1/vehicles?search=…&partner_id=…` returns scoped matches.
- `AppointmentStoreAcceptsLinkedEntitiesTest`: post with `customer_partner_id` + `vehicle_id` creates appointment linked to both.

### 3.6 Acceptance criteria

- Preflight green.
- All three target components use pickers.
- Smoke (manual in PR body): log in → transfer vehicle ownership via picker → create work order via pickers → create appointment via pickers.
- Zero ESLint violations including the strict palette rule on new feature dirs.

### 3.7 PR gate

- Branch: `feat/autospecs-pickers`.
- Title: `feat(pickers): partner/vehicle/bundle pickers + wire into transfer / work-order / appointment flows`.
- Paused for human review before merge.

---

## 4. Phase A.3 — `feat/autospecs-bundle-authoring-ui`

### 4.1 Problem (from gaps doc)

- Plan A.5 #1: `BundleDetailPage` renders components read-only — no add/edit/delete form layer. Backend endpoints already work.

### 4.2 Scope

Add authoring UI to `apps/web/src/features/workshop-bundles/pages/BundleDetailPage.tsx` for:

- Add component (Part / Labor / NestedBundle).
- Edit component (quantity, unit, optional flag, notes, override_unit_price).
- Delete component.
- Add / edit / replace vehicle applicability rules (the backend endpoint is `PUT /api/v1/workshop/bundles/{id}/vehicle-applicabilities` which **replaces** the full set).

### 4.3 New components

#### 4.3.1 `BundleComponentFormModal`

- Modal (fixed dimensions per `feedback_modal_fixed_size.md` memory — width 560px, height fits content; do NOT resize on interaction).
- Fields by `component_type`:
  - `Part`: `<ProductPicker>` (new molecule; same pattern as other pickers, hits `GET /api/v1/products?search=`), `quantity` (decimal, use `CurrencyScale::bcformat`-safe input + `apps/web/src/lib/decimal.ts` helpers), `unit_id` (dropdown of Units — `GET /api/v1/units`), `is_optional` (checkbox), `override_unit_price` (nullable decimal + currency label), `display_order` (readonly, auto-assigned), `notes`.
  - `Labor`: `<ServicePicker>` (new molecule, hits `GET /api/v1/services?search=`), same other fields.
  - `NestedBundle`: `<BundlePicker>` (from A.2) scoped to same `tenant_id` and excluding `self.id` to prevent cycles at the UI layer as well; same other fields.
- On submit: call `POST /api/v1/workshop/bundles/{id}/components` for new, or `PATCH /api/v1/workshop/bundles/{id}/components/{componentId}` for edit. If the PATCH endpoint doesn't exist (gaps doc referenced only POST + DELETE), add it as part of this branch — minimal, additive, typed DTO — with a feature test.
- On 4xx response, surface validation errors under the relevant field using `zod` + `react-hook-form`.

#### 4.3.2 `BundleApplicabilityEditor`

- Inline section on `BundleDetailPage` below the components list.
- Shows current applicabilities as `VehicleApplicabilityChip`s with a ✕ for remove and a "+ Add rule" button.
- "+ Add rule" opens a dialog to construct a single applicability row (`platform_vehicle_id` via new `PlatformVehiclePicker` OR `vehicle_type` dropdown + `vehicle_display` free-text + `year_from` / `year_to`) — enforce the "both-null OR both-non-null" pair invariant client-side before submit (matches `BundleAuthoringService.php:302`).
- Client constructs the full new applicability list and submits via `PUT /api/v1/workshop/bundles/{id}/vehicle-applicabilities` (replace semantics).

### 4.4 Updates to `BundleDetailPage`

- Add `Edit` + `Delete` buttons to each `BundleComponentRow` that open `BundleComponentFormModal` pre-filled or call a confirmation dialog + `DELETE`.
- Add `+ Add component` button at the top of the components section.
- Invalidate `['bundles', id]` query on each mutation via `queryClient.invalidateQueries`.

### 4.5 Tests (TDD)

**Frontend:**
1. `BundleComponentFormModal.test.tsx`: renders per type, submits correctly, shows validation errors.
2. `BundleApplicabilityEditor.test.tsx`: adds + removes + submits full list.
3. `BundleDetailPage.integration.test.tsx`: end-to-end add/edit/delete flow with mocked hooks.

**Backend (if PATCH endpoint added):**
- `BundleComponentControllerPatchTest`: updates quantity + unit + optional flag.
- `BundleComponentCyclicDetectionTest`: posting a nested-bundle component that would create a cycle returns 422 with `ConflictDetail`-style error (reuse the existing cycle-detection util from PR #5).

### 4.6 Acceptance criteria

- Preflight green.
- All BundleDetailPage mutations surface optimistic UI + proper error handling.
- Manual smoke in PR body: create bundle → add 3 components of each type → edit one → delete one → add applicability rule → confirm backend state.

### 4.7 PR gate

- Branch: `feat/autospecs-bundle-authoring-ui`.
- Title: `feat(bundles): add/edit/remove component + applicability authoring UI`.
- Paused for human review before merge.

---

## 5. Phase A.4 — `feat/autospecs-technician-authoring`

### 5.1 Problem (from gaps doc)

- Plan C #5: Certifications display + Weekly Hours UI not wired — server-side DTOs exist but no frontend surface.
- Gaps doc §10 (Explore findings): TechnicianCertification + TimeOff + TimeEntry models/migrations exist, but **no controllers**. No PayrollExport feature exists at all.

### 5.2 Scope

Ship the deferred Plan C CRUD:

- **Backend:** 4 new controllers under `apps/api/app/Modules/Workshop/Technician/Http/Controllers/` + routes + permissions + policies.
- **Frontend:** enhance `TechnicianDetailPage` with three tabbed authoring sections (Certifications / Time Off / Time Entries) and add a `PayrollExportPage` (list + generate).

### 5.3 Backend controllers

Each controller is a standard REST resource + module middleware pattern (§1.2). Write feature tests **first**.

#### 5.3.1 `TechnicianCertificationController`

Routes under `/workshop/technicians/{technicianId}/certifications`:
- `GET` `index` — list certs for technician. Can: `workshop.technicians.view`.
- `POST` `store` — body: `certification_id` (FK to `certifications`), `issued_at`, `expires_at` (nullable), `certificate_number` (nullable). Can: `workshop.technicians.manage_certifications`.
- `PATCH` `update {id}` — same body, partial. Can: same.
- `DELETE` `destroy {id}`. Can: same.

Add the `workshop.technicians.manage_certifications` permission to `RolesAndPermissionsSeeder` under the `admin` + `manager` + `owner` roles. Do NOT grant it to `technician` role (self-edit of certifications is out of scope).

#### 5.3.2 `TechnicianTimeOffController`

Routes under `/workshop/technicians/{technicianId}/time-off`:
- `GET` `index` — list (with `?from=YYYY-MM-DD&to=YYYY-MM-DD` filter). Can: `workshop.technicians.view`.
- `POST` `store` — body: `type` (enum `TimeOffType::Vacation|Sick|Personal|Training`), `starts_at` (datetime), `ends_at` (datetime), `notes` (nullable). Validate no overlap with existing time-off for same tech. Can: `workshop.technicians.manage_time_off`.
- `PATCH` `update {id}` — same body. Can: same.
- `DELETE` `destroy {id}`. Can: same.

A `technician` role can view their own time-off only (add policy method `view` that checks `$user->id === $timeOff->technicianProfile->user_id || $user->can('workshop.technicians.view')`).

#### 5.3.3 `TechnicianTimeEntryController`

Routes under `/workshop/technicians/{technicianId}/time-entries`:
- `GET` `index` — list with date range filter. Can: `workshop.technicians.view`.
- `POST` `store` — body: `work_order_id` (nullable UUID), `starts_at`, `ends_at`, `activity_type` (enum), `notes`. Can: `workshop.technicians.manage_time_entries` OR the tech themselves (for self-logging).
- `PATCH` `update {id}` — admin/manager only (workers can't retroactively edit their own). Can: `workshop.technicians.manage_time_entries`.
- `DELETE` `destroy {id}` — admin/manager only. Can: same.

**Immutability rule:** once a time entry is tied to a work-order that is `completed` or `invoiced`, it becomes immutable. Update/delete returns 422 with error code `TIME_ENTRY_LOCKED`.

#### 5.3.4 `PayrollExportController`

Routes under `/workshop/payroll-exports`:
- `GET` `index` — list of past exports. Can: `workshop.payroll.view`.
- `POST` `generate` — body: `pay_period_start` (date), `pay_period_end` (date), `technician_ids` (nullable array, defaults to all active). Aggregates time entries in the period and computes gross pay from `hourly_cost_rate × hours` per tech. Returns a CSV response (stream) OR persists a `PayrollExport` row and returns its id (decide based on UI need; simplest: stream CSV inline for v1, no persistence). Can: `workshop.payroll.generate`.

**Scope note:** no new `payroll_exports` table in v1 — the export is stateless CSV. If the implementer finds this generates too much downstream friction (e.g. UI needs to show a history), escalate to the human before adding a table.

### 5.4 Frontend

#### 5.4.1 `TechnicianDetailPage` — three new tabs

- `Certifications` — list, `+ Add`, edit, delete via `CertificationFormModal`. Select existing `Certification` via dropdown (hit `GET /api/v1/certifications`).
- `Time Off` — list + calendar strip view if `@/components/molecules/CalendarStrip` exists (it does per smoke screenshots); `+ Add`, edit, delete.
- `Time Entries` — list with total hours for current week + month at top; `+ Add`, edit (if not locked), delete (if not locked).

Components:
- `CertificationFormModal.tsx`, `TimeOffFormModal.tsx`, `TimeEntryFormModal.tsx` — fixed dimensions per modal memory.
- Hooks under `apps/web/src/features/workshop-technicians/hooks/`: `useTechnicianCertifications`, `useTimeOff`, `useTimeEntries`, + mutation hooks.

#### 5.4.2 `PayrollExportPage`

- New route: `/workshop/payroll-exports`.
- UI: pay period picker (two date inputs, defaults to current month), optional technician multi-select, "Generate" button → downloads CSV.
- Add sidebar entry under Workshop group, gated on `workshop.payroll.view` permission.

### 5.5 Tests (TDD)

**Backend (PHPUnit):**
- `TechnicianCertificationControllerTest`: 5 tests (index, store, update, delete, permission denied for unauthorized).
- `TechnicianTimeOffControllerTest`: 6 tests (CRUD + overlap-detection + self-view policy).
- `TechnicianTimeEntryControllerTest`: 8 tests (CRUD + self-log + lock-on-completed-WO + admin-edit-locked returns 422).
- `PayrollExportControllerTest`: 4 tests (generate-all, generate-filtered, permission-denied, empty-period returns valid-but-empty CSV).
- Cover the permission additions via `RolesAndPermissionsSeederTest` (there should already be one; add assertions for the 4 new permissions).

**Frontend (Vitest):**
- One `*FormModal.test.tsx` per modal.
- One integration test per tab on `TechnicianDetailPage`.
- `PayrollExportPage.test.tsx`: form submission + loading state + CSV download mock.

### 5.6 Acceptance criteria

- Preflight green.
- All 4 controllers exist with correct middleware stack.
- Sidebar shows Payroll Exports under Workshop for admin users; hidden for technicians.
- Manual smoke in PR body: seed → log in → add a certification to Yassine → log time off for Sarra → log time entry linked to an open work order → complete that WO → attempting to edit that time entry returns the locked error → generate payroll export CSV.

### 5.7 PR gate

- Branch: `feat/autospecs-technician-authoring`.
- Title: `feat(technician): certifications + time-off + time-entry CRUD + payroll export`.
- Paused for human review before merge.

---

## 6. Anti-scope (all phases)

Do NOT touch (per user instruction + CLAUDE.md rules #6, #7, #8):
- `apps/api/app/Modules/Catalog/*`
- `apps/api/app/Modules/Menu/*`
- `DocumentVehicleContext` (any file referencing it)
- Any fiscal-hash column (`documents.fiscal_hash`, `documents.previous_fiscal_hash`, etc.)
- `packages/shared/types/*` (regenerated by the types-pipeline session)

Do NOT:
- Rename/delete events or event payload fields.
- Add `mixed` or `any`.
- Leave TODO or placeholder code.
- Hardcode user-facing strings.
- Create session/plan/status markdown files at the repo root (use `docs/sessions/` only).
- Auto-merge umbrella PR #11.

---

## 7. Post-Phase-A checkpoints

After each branch merges to `dev`, the orchestrator:

1. Updates `project_current_priorities.md` memory.
2. Marks the corresponding task in the cluster task list as completed.
3. Refreshes `/Users/houssamr/Projects/syneriva/docs/sessions/2026-04-20-autospecs-discovered-gaps.md` in the parent repo to strike the closed gaps.
4. Pauses and posts a status update for the user.

No forward progress on Phase B until all four Phase A branches are merged.
