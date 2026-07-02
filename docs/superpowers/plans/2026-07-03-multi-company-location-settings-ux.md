# Multi-Company / Multi-Location Settings & UX Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. **Executor: Codex, strict TDD (red → green → refactor per task).**

**Goal:** Make company vs. branch data management unambiguous: Settings edits the real Company entity, branch data (address + tax ID) is discoverable and complete, switching propagates consistently, and placeholders/labels follow the company's country instead of defaulting to France/EUR.

**Architecture:** No schema changes. Chunk 1 repoints the existing `/settings/company` endpoint from the deprecated Tenant fields to the `Company` entity (response contract unchanged). Chunks 2–5 are frontend: navigation/IA, form completeness, a country-profile consumption layer over the already-seeded `countries` API, and query-invalidation parity.

**Tech Stack:** Laravel 12 (PHPUnit), React 19 + TypeScript strict (Vitest), TanStack Query 5, react-i18next, Tailwind 4 with `lib/designTokens.ts`.

**Spec:** `docs/superpowers/specs/2026-07-03-multi-company-location-settings-ux-design.md`
**Worktree/branch:** `apps/erp.multiloc` → `fix/multi-company-location-ux` (off `origin/dev`)

## Global Constraints

- TDD per repo rules: failing test first, then minimal implementation. Backend: PHPUnit **by path** (never the full suite). Frontend: Vitest.
- No `any` (TS) / no `mixed` (PHP). Constructor injection only — never `app()`.
- All user-facing text via `t()`; every new/changed i18n key must land in **every** locale directory present under `apps/web/src/locales/` (en, fr, ar).
- When touching a `.tsx` file, migrate hardcoded Tailwind colors **in lines you touch** to tokens from `@/lib/designTokens` (rule 18). `AddLocationModal.tsx` is full of `bg-blue-600`/`text-gray-700` — migrate only what you edit.
- `apiGet`/`apiPatch` already unwrap `response.data.data` — do NOT double-unwrap.
- Response contract of `GET /settings/company` must stay byte-identical in key names (frontend `CompanyPage.tsx` consumes it).
- Commit after every green task: `feat(multiloc): …` / `fix(multiloc): …` with `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`.
- Quality gate before finishing: `cd apps/api && ./vendor/bin/pint --dirty && ./vendor/bin/phpstan` (level 8, zero new errors) and `cd apps/web && pnpm typecheck && pnpm lint && pnpm test -- --run <touched test files>`.

---

## Chunk 1 — `/settings/company` targets the Company entity (P0, BE)

### Task 1: Repoint CompanySettingsController from Tenant to Company

**Files:**
- Modify: `apps/api/app/Modules/Tenant/Application/DTOs/CompanySettingsData.php`
- Modify: `apps/api/app/Modules/Tenant/Presentation/Controllers/CompanySettingsController.php`
- Test: `apps/api/tests/Feature/Tenant/CompanySettingsTest.php` (extend existing)

**Interfaces:**
- Consumes: `CompanyContext::requireCompanyId(): string` (already constructor-injected in the controller), `App\Modules\Company\Domain\Company`.
- Produces: `CompanySettingsData::fromCompany(Company $company): array` — same key set as today's `fromTenant` (`name, legal_name, tax_id, registration_number, address{street,city,postal_code,country}, phone, email, website, logo_url, primary_color, country_code, currency_code, timezone, date_format, locale`).

- [ ] **Step 1: Write the failing tests** (add to `CompanySettingsTest.php`):

```php
public function test_show_returns_company_entity_values_not_tenant_values(): void
{
    // Arrange: make tenant row and company row deliberately DIVERGENT
    $this->tenant->update(['tax_id' => 'TENANT-TAX', 'currency_code' => 'EUR', 'name' => 'Tenant Shell']);
    $this->company->update([
        'tax_id' => '1234567AM000', 'currency' => 'TND', 'name' => 'PharmaBio Tunis',
        'address_street' => 'Av. Habib Bourguiba', 'address_city' => 'Tunis',
        'address_postal_code' => '1000', 'address_country' => 'TN', 'country_code' => 'TN',
    ]);

    $response = $this->actingAs($this->user)
        ->withHeader('X-Company-Id', $this->company->id)
        ->getJson('/api/v1/settings/company');

    $response->assertOk()
        ->assertJsonPath('data.name', 'PharmaBio Tunis')
        ->assertJsonPath('data.tax_id', '1234567AM000')
        ->assertJsonPath('data.currency_code', 'TND')
        ->assertJsonPath('data.address.street', 'Av. Habib Bourguiba')
        ->assertJsonPath('data.address.country', 'TN');
}

public function test_update_persists_to_company_and_never_touches_tenant(): void
{
    $tenantBefore = $this->tenant->fresh()->only(['tax_id', 'currency_code', 'name', 'address']);

    $response = $this->actingAs($this->user)
        ->withHeader('X-Company-Id', $this->company->id)
        ->patchJson('/api/v1/settings/company', [
            'tax_id' => '7654321BM000',
            'currency_code' => 'TND',
            'address' => ['street' => 'Rue de Marseille', 'city' => 'Sfax', 'postal_code' => '3000', 'country' => 'TN'],
        ]);

    $response->assertOk();
    $company = $this->company->fresh();
    $this->assertSame('7654321BM000', $company->tax_id);
    $this->assertSame('TND', $company->currency);
    $this->assertSame('Rue de Marseille', $company->address_street);
    $this->assertSame('Sfax', $company->address_city);
    // Tenant row untouched:
    $this->assertSame($tenantBefore, $this->tenant->fresh()->only(['tax_id', 'currency_code', 'name', 'address']));
}

public function test_show_response_contract_keys_are_unchanged(): void
{
    $response = $this->actingAs($this->user)
        ->withHeader('X-Company-Id', $this->company->id)
        ->getJson('/api/v1/settings/company');

    $response->assertOk()->assertJsonStructure(['data' => [
        'name', 'legal_name', 'tax_id', 'registration_number',
        'address' => ['street', 'city', 'postal_code', 'country'],
        'phone', 'email', 'website', 'logo_url', 'primary_color',
        'country_code', 'currency_code', 'timezone', 'date_format', 'locale',
    ]]);
}
```

Adapt setUp to the file's existing tenant/company fixture helpers (the test file already boots a tenant; add a Company via the existing factory + membership for `$this->user` if not present).

- [ ] **Step 2: Run to verify failure**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Tenant/CompanySettingsTest.php`
Expected: new tests FAIL (show returns tenant values; update writes tenant).

- [ ] **Step 3: Implement**

`CompanySettingsData` — add (keep `fromTenant` for now; delete it if nothing else references it after Step 5's grep):

```php
public static function fromCompany(\App\Modules\Company\Domain\Company $company): array
{
    return [
        'name' => $company->name,
        'legal_name' => $company->legal_name,
        'tax_id' => $company->tax_id,
        'registration_number' => $company->registration_number,
        'address' => [
            'street' => $company->address_street,
            'city' => $company->address_city,
            'postal_code' => $company->address_postal_code,
            'country' => $company->address_country ?? $company->country_code,
        ],
        'phone' => $company->phone,
        'email' => $company->email,
        'website' => $company->website,
        'logo_url' => $company->logo_path ? asset('storage/'.$company->logo_path) : null,
        'primary_color' => $company->primary_color,
        'country_code' => $company->country_code,
        'currency_code' => $company->currency,
        'timezone' => $company->timezone,
        'date_format' => $company->date_format,
        'locale' => $company->locale,
    ];
}
```

`CompanySettingsController::show` — replace `Tenant::find($user->tenant_id)` with:

```php
$company = Company::query()->find($this->companyContext->requireCompanyId());
// null → same NOT_FOUND envelope as today (message 'Company not found.')
return response()->json(['data' => CompanySettingsData::fromCompany($company), 'meta' => $this->getMeta($request)]);
```

`update` — map validated request keys to Company columns and persist to Company only:

```php
$map = [
    'name' => 'name', 'legal_name' => 'legal_name', 'tax_id' => 'tax_id',
    'registration_number' => 'registration_number', 'phone' => 'phone', 'email' => 'email',
    'website' => 'website', 'primary_color' => 'primary_color', 'country_code' => 'country_code',
    'currency_code' => 'currency', 'timezone' => 'timezone', 'date_format' => 'date_format', 'locale' => 'locale',
];
$attributes = [];
foreach ($map as $requestKey => $column) {
    if (array_key_exists($requestKey, $validated)) {
        $attributes[$column] = $validated[$requestKey];
    }
}
if (array_key_exists('address', $validated)) {
    $addr = $validated['address'] ?? [];
    $attributes['address_street'] = $addr['street'] ?? null;
    $attributes['address_city'] = $addr['city'] ?? null;
    $attributes['address_postal_code'] = $addr['postal_code'] ?? null;
    $attributes['address_country'] = $addr['country'] ?? null;
}
$company->update($attributes);
```

Keep the change-tracking audit block, computing old/new from the Company attributes (same field loop over `$map` + address). Keep permission checks and response envelopes identical. `UpdateCompanySettingsRequest` needs no rule changes (all keys already validated).

- [ ] **Step 4: Run tests to verify pass**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Tenant/CompanySettingsTest.php`
Expected: ALL PASS (including the pre-existing tests in the file — if a pre-existing test asserts tenant writes, update it to assert company writes; that is the intended behavior change).

- [ ] **Step 5: Deprecated-consumer inventory (report, don't fix)**

Run: `cd apps/api && grep -rn "tenant->tax_id\|tenant->currency_code\|tenant->country_code\|tenant->legal_name\|tenant->registration_number\|->address\b" app/ --include="*.php" | grep -iv "company\|test" | head -40`
List every live READ of the deprecated Tenant business fields in the final report. Provisioning/onboarding-time reads (before a company exists) are acceptable — flag anything else as a finding; do not silently repoint out-of-scope code.

- [ ] **Step 6: Pint + PHPStan on touched paths, then commit**

```bash
cd apps/api && ./vendor/bin/pint --dirty && ./vendor/bin/phpstan
git add -A && git commit -m "fix(multiloc): /settings/company reads/writes Company entity, not deprecated Tenant fields"
```

---

## Chunk 2 — Branch discoverability + scope clarity (P0, FE)

### Task 2: Locations card in Settings hub

**Files:**
- Modify: `apps/web/src/features/settings/SettingsPage.tsx` (sections array)
- Modify: `apps/web/src/locales/{en,fr,ar}/settings.json`
- Test: `apps/web/src/features/settings/__tests__/SettingsPage.test.tsx` (create or extend alongside existing settings tests)

**Interfaces:**
- Produces: hub card with `titleKey: 'sections.locations.title'`, `href: '/settings/locations'`; i18n keys `settings:sections.locations.title` + `.description`.

- [ ] **Step 1: Failing test** — render `SettingsPage` (follow the file's existing test setup pattern with i18n + router providers; if no test file exists, mirror the setup of another `features/settings` page test):

```tsx
it('links to the locations & branches management page', () => {
  renderSettingsPage()
  const card = screen.getByRole('link', { name: /locations/i })
  expect(card).toHaveAttribute('href', '/settings/locations')
})
```

- [ ] **Step 2: Run** `cd apps/web && pnpm test -- --run src/features/settings/__tests__/SettingsPage.test.tsx` — expect FAIL.
- [ ] **Step 3: Implement** — add to the sections array (after the company entry), pattern-matching neighbors (icon: `MapPin` from lucide, matching how other sections declare icons):

```tsx
{
  titleKey: 'sections.locations.title',
  descriptionKey: 'sections.locations.description',
  icon: MapPin,
  href: '/settings/locations',
},
```

i18n (en): `"locations": { "title": "Locations & Branches", "description": "Manage each branch's address, contact details, and tax ID" }`; fr: `"Sites & succursales" / "Gérez l'adresse, les contacts et l'identifiant fiscal de chaque succursale"`; ar: translate equivalently. Respect the permission-filtering mechanism if the sections array carries permission keys (use the same permission as the existing locations API: `locations.view` if the pattern exists — otherwise no gate, matching neighbors).

- [ ] **Step 4: Run test — PASS.** Also `pnpm typecheck`.
- [ ] **Step 5: Commit** `feat(multiloc): settings hub card for locations & branches`.

### Task 3: Scope banners + "Manage locations" in the switcher

**Files:**
- Modify: `apps/web/src/features/settings/CompanyPage.tsx` (banner under the page header)
- Modify: `apps/web/src/features/settings/LocationsPage.tsx` (subtitle line)
- Modify: `apps/web/src/components/organisms/LocationSwitcher/LocationSwitcher.tsx` (footer menu item)
- Modify: `apps/web/src/locales/{en,fr,ar}/settings.json`, `apps/web/src/locales/{en,fr,ar}/common.json`
- Test: `apps/web/src/features/settings/__tests__/CompanyPage.scope.test.tsx` (new), extend LocationSwitcher test if present

**Interfaces:**
- Consumes: `useCompany()` → current company name; existing `AddLocationModal` open pattern in the switcher.
- Produces: i18n keys `settings:company.scopeBanner` ("These are company-wide settings for {{company}}. Branch details (address, tax ID) are edited in Locations & Branches."), `settings:locations.scopeHint` ("Per-branch data. Blank tax fields inherit the company values."), `common:locations.manageLocations` ("Manage locations").

- [ ] **Step 1: Failing tests**

```tsx
it('shows a company-scope banner naming the current company and linking to locations', () => {
  renderCompanyPage({ companyName: 'PharmaBio Tunis' })
  expect(screen.getByText(/company-wide settings/i)).toHaveTextContent('PharmaBio Tunis')
  expect(screen.getByRole('link', { name: /locations & branches/i })).toHaveAttribute('href', '/settings/locations')
})
```

LocationSwitcher: assert the open dropdown contains a link/button "Manage locations" navigating to `/settings/locations`.

- [ ] **Step 2: Run — FAIL.**
- [ ] **Step 3: Implement.** CompanyPage: an info banner component under `PageHeader` using tokens (`tokens.*` info-surface style used elsewhere in settings pages — copy the pattern from an existing informational banner, e.g. the ones in import/opening-balances pages) with `<Trans>` interpolation for `{{company}}` and a `<Link to="/settings/locations">`. LocationSwitcher: after the "Add location" item, add a menu item that calls `navigate('/settings/locations')` and closes the menu. LocationsPage: subtitle via existing `PageHeader` `subtitle` prop.
- [ ] **Step 4: Run tests — PASS.** `pnpm typecheck && pnpm lint`.
- [ ] **Step 5: Commit** `feat(multiloc): scope banners + manage-locations entry point`.

---

## Chunk 3 — Complete the quick-add location modal (P1, FE)

### Task 4: Country select + conditional required tax fields in AddLocationModal

**Files:**
- Modify: `apps/web/src/components/organisms/AddLocationModal/AddLocationModal.tsx`
- Modify: `apps/web/src/features/location/api.ts` (`CreateLocationInput` — add `taxId`/`vatNumber` if absent; verify payload mapping to snake_case)
- Modify: `apps/web/src/locales/{en,fr,ar}/common.json`
- Test: `apps/web/src/components/organisms/AddLocationModal/__tests__/AddLocationModal.test.tsx`

**Interfaces:**
- Consumes: `useCompany()` → `company.country_code` (default country); `useCountries()` → country list for the select; the branch-tax-required country set `['FR','TN','MA']` — extract to `apps/web/src/features/location/branchTaxCountries.ts` as `export const BRANCH_TAX_ID_REQUIRED_COUNTRIES = ['FR', 'TN', 'MA'] as const` so LocationsPage's copy of the same list (`LocationsPage.tsx:33`) can import it too (dedupe).
- Produces: create payload now includes `address_country`, `tax_id`, `vat_number`.

- [ ] **Step 1: Failing tests**

```tsx
it('defaults the country to the current company country', () => {
  renderModal({ companyCountry: 'TN' })
  expect(screen.getByLabelText(/country/i)).toHaveValue('TN')
})

it('requires a tax ID for a shop in a branch-tax country and blocks submit', async () => {
  renderModal({ companyCountry: 'TN' })
  await user.type(screen.getByLabelText(/name/i), 'Branch Sfax')
  await user.click(screen.getByRole('button', { name: /create/i }))
  expect(await screen.findByText(/tax id is required/i)).toBeInTheDocument()
  expect(createLocationMock).not.toHaveBeenCalled()
})

it('does not show tax fields for a warehouse', async () => {
  renderModal({ companyCountry: 'TN' })
  await user.selectOptions(screen.getByLabelText(/type/i), 'warehouse')
  expect(screen.queryByLabelText(/tax id/i)).not.toBeInTheDocument()
})

it('sends country and tax fields in the create payload', async () => {
  renderModal({ companyCountry: 'TN' })
  await user.type(screen.getByLabelText(/name/i), 'Branch Sfax')
  await user.type(screen.getByLabelText(/tax id/i), '1234567AM000')
  await user.click(screen.getByRole('button', { name: /create/i }))
  expect(createLocationMock).toHaveBeenCalledWith(expect.objectContaining({
    addressCountry: 'TN', taxId: '1234567AM000',
  }))
})
```

Mock `useCompany`/`useCountries` per the repo's component-test conventions (hook mocks allowed).

- [ ] **Step 2: Run — FAIL** (no country/tax inputs exist).
- [ ] **Step 3: Implement.**
  - Add a country `<select>` populated from `useCountries()` (fall back to a plain 2-letter input if the query has no data), initialized once from `company?.country_code ?? ''` when the modal opens.
  - `const requiresTaxId = formData.type === 'shop' && BRANCH_TAX_ID_REQUIRED_COUNTRIES.includes(formData.addressCountry as never)` — when true render required `taxId` input + optional `vatNumber`; label/placeholder: reuse the existing `common:locations.taxId*` keys (Task 7 swaps in country-profile labels).
  - Client-side gate in `handleSubmit` mirroring the name check: `if (requiresTaxId && !formData.taxId.trim()) { setError(t('common:locations.modal.taxIdRequired')); return }` (new key, all locales).
  - Extend the payload + `CreateLocationInput` with `taxId`/`vatNumber` → snake_case in the API layer.
  - Success path: replace the bare `onClose()` flow with the repo's toast util (same one `useScopeChangeNotice` uses) — message `common:locations.modal.createdCompleteDetails` with an action linking `/settings/locations`.
  - Migrate Tailwind colors → tokens on every line you touch (rule 18).
- [ ] **Step 4: Run tests — PASS.** `pnpm typecheck && pnpm lint`.
- [ ] **Step 5: Commit** `feat(multiloc): quick-add location captures country + branch tax id when required`.

---

## Chunk 4 — Country-aware labels & placeholders (P1, FE)

### Task 5: `useCountryProfile` hook + `getCountryPlaceholders` util

**Files:**
- Create: `apps/web/src/features/settings/hooks/useCountryProfile.ts`
- Create: `apps/web/src/lib/countryPlaceholders.ts`
- Test: `apps/web/src/lib/__tests__/countryPlaceholders.test.ts`, `apps/web/src/features/settings/hooks/__tests__/useCountryProfile.test.tsx`

**Interfaces:**
- Consumes: `useCountry(code)` from `apps/web/src/features/settings/hooks/useCountries.ts`; `Country` type (`tax_id_label`, `tax_id_regex`, `phone_prefix`, `currency_code`, `currency_symbol`, `date_format`, `default_timezone`).
- Produces:

```ts
// useCountryProfile.ts
export interface CountryProfile {
  taxIdLabel: string | null
  taxIdRegex: string | null
  phonePrefix: string | null
  currencyCode: string | null
  currencySymbol: string | null
  dateFormat: string | null
  timezone: string | null
}
export function useCountryProfile(countryCode: string | null | undefined): { profile: CountryProfile | null, isLoading: boolean }

// countryPlaceholders.ts
export interface CountryPlaceholders {
  postalCode: string
  city: string
  phone: string
  taxId: string
  registrationNumber: string
  street: string
}
export function getCountryPlaceholders(countryCode: string | null | undefined, phonePrefix?: string | null): CountryPlaceholders
```

- [ ] **Step 1: Failing tests**

```ts
describe('getCountryPlaceholders', () => {
  it('returns Tunisian examples for TN', () => {
    const p = getCountryPlaceholders('TN')
    expect(p).toEqual({
      postalCode: '1000', city: 'Tunis', phone: '+216 71 123 456',
      taxId: '1234567AM000', registrationNumber: 'B011234562022',
      street: '10 Avenue Habib Bourguiba',
    })
  })
  it('returns French examples for FR', () => {
    expect(getCountryPlaceholders('FR').taxId).toBe('FR12345678901')
    expect(getCountryPlaceholders('FR').postalCode).toBe('75001')
  })
  it('returns neutral examples with the given phone prefix for unknown countries', () => {
    const p = getCountryPlaceholders('DE', '49')
    expect(p.phone).toBe('+49 …')
    expect(p.city).toBe('')          // neutral: let the label speak
    expect(p.postalCode).toBe('')
    expect(p.taxId).toBe('')
  })
  it('never returns French examples for non-FR countries', () => {
    expect(getCountryPlaceholders('DE', '49').postalCode).not.toBe('75001')
  })
})
```

`useCountryProfile` test: mock `useCountry` returning a TN row → hook maps `tax_id_label: 'Matricule Fiscal'` to `profile.taxIdLabel`; null/undefined code → `profile === null` without querying (pass `enabled` semantics through).

- [ ] **Step 2: Run — FAIL** (files don't exist).
- [ ] **Step 3: Implement.** `countryPlaceholders.ts`: a `Record<string, CountryPlaceholders>` with entries for `TN`, `FR`, `MA` (`ICE 001234567000089`, postal `20000`, city ` Casablanca`… use plausible MA formats), `DZ`, and a `neutral(prefix)` builder for everything else (`phone: prefix ? `+${prefix} …` : ''`, all other fields `''` — empty placeholder is better than a wrong-country example). `useCountryProfile`: thin mapping over `useCountry(code ?? '')` (hook is already `enabled`-guarded on empty code).
- [ ] **Step 4: Run tests — PASS.**
- [ ] **Step 5: Commit** `feat(multiloc): country profile hook + country-aware placeholder util`.

### Task 6: CompanyPage consumes the country profile

**Files:**
- Modify: `apps/web/src/features/settings/CompanyPage.tsx` (kill the `usesTunisiaDefaults` binary at lines ~205-206, 323-337, 378, 403)
- Modify: `apps/web/src/locales/{en,fr,ar}/settings.json`
- Test: `apps/web/src/features/settings/__tests__/CompanyPage.countryProfile.test.tsx`

**Interfaces:**
- Consumes: `useCountryProfile(form.countryCode)`, `getCountryPlaceholders(form.countryCode, profile?.phonePrefix)`.

- [ ] **Step 1: Failing tests**

```tsx
it('labels the tax field from the country profile (TN → Matricule Fiscal) and uses TN placeholders', () => {
  renderCompanyPage({ company: { country_code: 'TN' }, countryRow: tnCountry })
  expect(screen.getByLabelText(/matricule fiscal/i)).toBeInTheDocument()
  expect(screen.getByPlaceholderText('1000')).toBeInTheDocument()       // postal
  expect(screen.getByPlaceholderText('+216 71 123 456')).toBeInTheDocument()
})

it('falls back to the generic tax label when the country row is missing', () => {
  renderCompanyPage({ company: { country_code: 'DE' }, countryRow: null })
  expect(screen.getByLabelText(/tax id/i)).toBeInTheDocument()          // settings:company.fields.taxId fallback
  expect(screen.queryByPlaceholderText('75001')).not.toBeInTheDocument() // no French leakage
})
```

- [ ] **Step 2: Run — FAIL.**
- [ ] **Step 3: Implement.** Replace every `usesTunisiaDefaults ? tnValue : frValue` ternary with profile/placeholder lookups: tax-ID label = `profile?.taxIdLabel ?? t('company.fields.taxId')`; placeholders from `getCountryPlaceholders(...)`. The timezone select's option list stays, but the default highlighted option follows `profile?.timezone`. Remove now-unused TN/FR conditional i18n placeholder keys ONLY if no other component references them (grep first); otherwise leave keys in place and stop referencing them here.
- [ ] **Step 4: Run tests — PASS.** `pnpm typecheck && pnpm lint`.
- [ ] **Step 5: Commit** `fix(multiloc): company settings labels/placeholders follow company country`.

### Task 7: LocationsPage + AddLocationModal consume the country profile

**Files:**
- Modify: `apps/web/src/features/settings/LocationsPage.tsx` (tax field label/placeholder driven by the location form's `address_country`)
- Modify: `apps/web/src/components/organisms/AddLocationModal/AddLocationModal.tsx` (same, driven by selected country)
- Test: extend both components' test files

**Interfaces:**
- Consumes: `useCountryProfile(selectedCountry)`, `getCountryPlaceholders(selectedCountry, profile?.phonePrefix)`.

- [ ] **Step 1: Failing tests** — modal with country TN shows label `Matricule Fiscal` and placeholder `1234567AM000`; switching the country select to FR flips label to the FR row's `tax_id_label` (SIREN) and placeholder to `FR12345678901`. LocationsPage edit form: same assertion for a TN location.
- [ ] **Step 2: Run — FAIL.**
- [ ] **Step 3: Implement** — swap the static `common:locations.taxId` label for `profile?.taxIdLabel ?? t('common:locations.taxId')` in both forms; placeholders from the util.
- [ ] **Step 4: Run — PASS.**
- [ ] **Step 5: Commit** `fix(multiloc): location tax fields labeled per country profile`.

### Task 8: Neutralize FR-defaulted i18n placeholders + onboarding ordering

**Files:**
- Modify: `apps/web/src/locales/{en,fr,ar}/settings.json` + `common.json` (placeholder keys: `phonePlaceholder`, `cityPlaceholder`, `postalCodePlaceholder`, `taxIdPlaceholder`/`placeholders.taxId`, `placeholders.registrationNumber`, street placeholders)
- Modify: `apps/web/src/features/auth/config/countryData.ts` (ordering only — keep `FALLBACK_DEFAULTS`)
- Modify: `apps/web/src/features/company/CompanyOnboardingPage.tsx`, `apps/web/src/components/organisms/AddCompanyModal/AddCompanyModal.tsx` (country list ordering: current tenant/company country first, then alphabetical — France loses its hardcoded first position)
- Test: extend `AddCompanyModal` test (or create) asserting ordering; grep-based assertion test is NOT needed — rely on component tests from Tasks 6-7 for non-leakage.

- [ ] **Step 1: Failing test** — `AddCompanyModal` rendered with current company country `TN`: first option in the country select is Tunisia.
- [ ] **Step 2: Run — FAIL.**
- [ ] **Step 3: Implement.** i18n placeholder values that still carry FR examples become neutral (`""` or format hints like `"+— — — — —"` are worse than empty; use empty string) — components now inject country-aware placeholders from Task 5, so these keys are pure fallbacks. Ordering: sort helper `orderCountries(list, preferredCode)`.
- [ ] **Step 4: Run — PASS.** `pnpm typecheck && pnpm lint`.
- [ ] **Step 5: Commit** `fix(multiloc): neutral i18n placeholder fallbacks + local-first country ordering`.

---

## Chunk 5 — Location-switch propagation parity (P2, FE)

### Task 9: LocationSwitcher invalidates all queries

**Files:**
- Modify: `apps/web/src/components/organisms/LocationSwitcher/LocationSwitcher.tsx:69-81` (`handleLocationChange`)
- Test: extend the LocationSwitcher test file

**Interfaces:**
- Consumes: `queryClient.invalidateQueries()` (no predicate) — parity with `CompanySelector.tsx:36-43`.

- [ ] **Step 1: Failing test**

```tsx
it('invalidates all queries on location switch (parity with company switch)', async () => {
  const invalidateSpy = vi.spyOn(queryClient, 'invalidateQueries')
  renderSwitcher()
  await user.click(screen.getByRole('button', { name: /current branch/i }))
  await user.click(screen.getByText('Branch Sfax'))
  expect(invalidateSpy).toHaveBeenCalledWith() // no predicate → global
})
```

- [ ] **Step 2: Run — FAIL** (current code passes stock-scoped predicates).
- [ ] **Step 3: Implement** — replace the two predicate invalidations with a single `void queryClient.invalidateQueries()`. Keep the `locationId !== currentLocation?.id` guard and menu close. Delete the now-unused `scopedNamespacePredicate` import if nothing else in the file uses it.
- [ ] **Step 4: Run — PASS**, plus the file's existing tests.
- [ ] **Step 5: Commit** `fix(multiloc): location switch refetches all scoped data, not just stock`.

---

## Task 10: Preflight + report

- [ ] `cd apps/api && ./vendor/bin/pint --dirty && ./vendor/bin/phpstan` — zero new errors.
- [ ] `cd apps/api && ./vendor/bin/phpunit tests/Feature/Tenant/CompanySettingsTest.php` (and any other touched test paths) — green. **Never run the full backend suite.**
- [ ] `cd apps/web && pnpm typecheck && pnpm lint && pnpm test -- --run` on all touched test files — green.
- [ ] Final report: per-task status, the Task-1 Step-5 deprecated-consumer inventory, any pre-existing failures encountered (report, don't fix), files changed.

## Out of scope (do not touch)
NF525 export identity; per-location settings JSON; per-branch currency/numbering; `X-Location-Id` header context; Tenant column drops; POS desktop app (`apps/pos`).
