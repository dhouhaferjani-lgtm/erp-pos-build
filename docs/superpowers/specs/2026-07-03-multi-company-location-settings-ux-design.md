# Multi-Company / Multi-Location Settings & UX Consistency — Design

**Date:** 2026-07-03
**Status:** Approved for implementation (owner directive: research → plan → land before demo, Codex TDD execution)
**Research:** `docs/superpowers/research/2026-07-03-multi-company-branch-ux-standards.md` (industry patterns: Odoo, ERPNext, SAP B1, Business Central, NetSuite, QBO, Lightspeed, Square)
**Related:** PR #177 branch tax-ID (`2026-06-04-branch-tax-id-design.md`) — MERGED; this spec is the missing UX layer on top of it.

---

## 1. Problem

Managing multiple companies and locations is confusing. Verified root causes (all evidence on `origin/dev` @ `66ce2ca7c`):

| # | Defect | Evidence |
|---|--------|----------|
| D1 | **Settings → Company edits the wrong entity.** `/settings/company` GET/PATCH reads/writes the **Tenant** (account) row — fields the Tenant model itself marks deprecated ("use Company"). The real `Company` entity (drives fiscal, currency, numbering, receipts) is only editable via `/companies/{id}`, which the Settings UI never calls. The two silently diverge. | `apps/api/app/Modules/Tenant/Presentation/Controllers/CompanySettingsController.php:61,87` (`Tenant::find`); `Tenant.php:39-48` (deprecation docblock); `apps/web/src/features/settings/CompanyPage.tsx:76,110` |
| D2 | **The branch editor exists but is unreachable.** `/settings/locations` (`LocationsPage.tsx`) has the full branch form — address, tax ID, VAT, clear-to-inherit — but is linked from **nowhere** (not in the Settings hub sections, not in the sidebar). Only reachable by typed URL. | `routes/index.tsx:1958-1967`; `SettingsPage.tsx` section list (11 entries, no locations) |
| D3 | **The reachable location-create path silently bypasses the branch-tax-ID rule.** The LocationSwitcher "Add location" modal captures no `address_country`, postal code, tax ID, or VAT. The backend required-tax-ID gate for sellable shops (FR/TN/MA) only fires **when a country is provided** — so TN shops get created with no country and no tax ID. | `AddLocationModal.tsx` (fields); `CreateLocationRequest.php:70-87` (`withValidator` early-returns when `$country === ''`) |
| D4 | **No scope indication.** After picking company + location, Settings shows a form with nothing saying which entity (or which level) it edits. | `CompanyPage.tsx` (no banner) |
| D5 | **Switch propagation is asymmetric.** Company switch invalidates ALL React Query caches; location switch invalidates only `stock-levels`/`stock-movements` — every other location-scoped view goes stale. | `CompanySelector.tsx:36-43` vs `LocationSwitcher.tsx:70-82` |
| D6 | **French/EUR placeholders for non-French companies.** Forms toggle on `country_code === 'TN'` only; every other country falls back to `75001`/`Paris`/`+33`/`FR12345678901`. The backend `countries` table already seeds per-country profiles (`tax_id_label` "Matricule Fiscal"/"SIREN", `tax_id_regex`, `phone_prefix`, currency) and the API exposes them — but **no form consumes `tax_id_label`**. EUR fallbacks: `useCurrency.ts:47`, `countryData.ts:205`. | `CompanyPage.tsx:205-206,323-403`; `locales/{en,fr}/settings.json:92-143`; `locales/{en,fr}/common.json` locations placeholders; `CountriesSeeder.php:30,45`; `useCountries.ts` (fetched, unused) |

## 2. Industry patterns applied (from the research doc)

- **Two tiers, split on books vs operations** — companies own books/tax/currency; locations are operational with an identity block (address, phone, receipt header, tax/establishment number). Our model already matches; no schema change needed.
- **Settings IA**: dedicated Locations management under Settings; every scoped settings page carries an explicit "editing X" indicator.
- **Inheritance = blank-means-inherit with explicit override** (QBO checkboxes, Odoo branch fields). Already implemented in `LocationsPage.tsx` + `TaxIdentityResolver`; keep.
- **Country chosen at entity creation seeds labels/placeholders/validation** (Odoo l10n packs). We already seed `countries` with exactly this data — the fix is consuming it.
- **Switch = scoped cache refresh with persistent context indicator** — already have the TopBar selectors + scope toast; fix the invalidation asymmetry.

## 3. Design — five independently mergeable chunks

### Chunk 1 (P0, BE+FE): `/settings/company` targets the Company entity
- `CompanySettingsController` resolves the current company via the already-injected `CompanyContext` (`requireCompanyId()`), reads/writes `Company` instead of `Tenant`. `CompanySettingsData::fromTenant` → `fromCompany` with field mapping (`currency_code` ↔ `companies.currency`, address nested object ↔ `address_*` columns) so the **frontend response contract stays unchanged**.
- Extend `UpdateCompanySettingsRequest` to validate the full field set against Company columns (currency, timezone, date_format, locale, logo, primary_color are missing from `UpdateCompanyRequest` today — validate here, not there).
- **Stop writing Tenant fields entirely.** Before removal, inventory all readers of the deprecated Tenant fields (`tax_id`, `currency_code`, `country_code`, `address`, `legal_name`, `registration_number`) and report; provisioning-time reads (before first company exists) are acceptable and stay.
- Audit logging keeps the existing CompanyContext-pinned company id.
- Tests: GET returns Company values (seed divergent Tenant vs Company rows to prove source); PATCH persists to `companies`, leaves `tenants` untouched; permission behavior unchanged; currency change reflected in `/user/companies` payload.

### Chunk 2 (P0, FE): Branch management discoverability + scope clarity
- Add a **Locations & Branches** card to the Settings hub section list (`SettingsPage.tsx`) → `/settings/locations`; i18n keys in en/fr/ar.
- Add a "Manage locations" footer action in the LocationSwitcher dropdown → `/settings/locations`.
- Scope banners: `CompanyPage` gets a persistent notice — "These are company-wide settings for **{company name}**. Branch-specific data (address, tax ID) is edited in Locations & Branches →" (link). `LocationsPage` gets the inverse header line ("Per-branch data; blank fields inherit from the company").
- Tests: hub renders the new card; banner renders with the current company name; links navigate.

### Chunk 3 (P1, FE): Complete the quick-add location modal
- `AddLocationModal` gains: `address_country` (select, **default = current company's `country_code`**), `address_postal_code`, and — when `type === 'shop'` AND the selected country requires a branch tax ID (mirror `CountryTaxIdentityConfig`: FR/TN/MA list already surfaced in `common.json` `taxIdRequiredHint`) — a **required** `tax_id` field plus optional `vat_number`. Field label/placeholder come from the country profile (Chunk 4 util; if Chunk 4 not yet merged, use the existing TN/FR conditional strings).
- Success toast gains a "Complete branch details" action → `/settings/locations`.
- Tests: TN shop → tax ID required client-side (and server error surfaced if bypassed); warehouse → no tax field; country defaults from company; payload includes new fields.

### Chunk 4 (P1, FE): Country-aware labels & placeholders
- New `useCountryProfile(countryCode)` hook wrapping the existing `useCountries` fetch: returns `{ taxIdLabel, taxIdRegex, phonePrefix, currencyCode, currencySymbol, dateFormat, timezone }` from the seeded `countries` API row.
- New `getCountryPlaceholders(countryCode, phonePrefix)` util: per-country example placeholders (postal, city, phone, tax ID, registration) for TN, FR, MA, DZ, and a **neutral generic fallback** (e.g. phone = `+{prefix} …`, postal = generic) — French examples are no longer the default for unknown countries.
- Consume in: `CompanyPage` (replace the `usesTunisiaDefaults` binary — labels AND placeholders driven by the company's country), `LocationsPage` and `AddLocationModal` (driven by the location's `address_country`), onboarding/`AddCompanyModal` (driven by the selected country).
- Tax-ID field **label** uses `countries.tax_id_label` ("Matricule Fiscal", "SIREN", "ICE"…) with the current static label as fallback.
- i18n: replace hardcoded `+33 1 23 45 67 89`, `Paris`, `75001`, `FR12345678901`, `123 456 789 RCS Paris` placeholder values in `settings.json`/`common.json` (en/fr/ar) with interpolated or neutral values; keep FR examples only inside the FR profile entry.
- EUR fallbacks: `useCurrency` keeps a last-resort fallback but must prefer `company.currency`; `countryData.ts` `FALLBACK_DEFAULTS` stays EUR-last-resort but the France-first ordering in onboarding country lists is replaced by locale-aware ordering (TN first for Tunisian browser locale / existing tenant country).
- Tests: TN company renders Matricule Fiscal + `+216` + `1000`; FR renders SIREN + `75001`; unknown country renders neutral placeholders; label falls back when countries API is empty.

### Chunk 5 (P2, FE): Location-switch propagation parity
- `LocationSwitcher` switch handler invalidates **all** queries (same as `CompanySelector`) instead of the stock-only namespace list. Scope toast unchanged. If a full invalidation proves disruptive in tests (POS-adjacent screens), fall back to an explicit allow-list that includes documents, reports, dashboard, and stock namespaces — but parity-by-default is the goal.
- Tests: switch handler triggers global invalidation; scope toast still fires once per genuine change.

## 4. Explicitly out of scope (post-demo)
- NF525 export branch identity (`Nf525DataProvider.php:526` stays company-level — it is a company-wide export; matches the rev2 decision in the branch-tax-id spec).
- Per-location settings store (JSON), per-branch currency, per-branch numbering.
- `X-Location-Id` header-driven LocationContext (server-side location context stays per-operation).
- The broader per-branch GL program (P1–P5 of `2026-06-04-per-branch-program-design.md`).
- Tenant deprecated-column removal migration (only stop *writing*; drop columns later).

## 5. Risks & mitigations
- **R1: hidden consumers of deprecated Tenant fields** (platform sync, provisioning, exports). Chunk 1 starts with a mandatory grep inventory; any live read of a field we stop writing must be repointed or the finding escalated before merge.
- **R2: FE/BE contract drift in Chunk 1.** The response shape is kept identical; a contract test asserts the exact JSON keys the frontend consumes.
- **R3: full invalidation on location switch could refetch heavy queries.** Acceptable (company switch already does it); fallback allow-list documented above.
- **R4: i18n churn across en/fr/ar.** Every key change must land in all three locales (rule 11).

## 6. Approaches considered
- **D1 fix**: (a) repoint FE to `/companies/{id}` vs **(b) repoint the controller to Company (chosen)** — keeps the FE contract and route stable, kills the deprecated-write at the source, and `/settings/company` is semantically correct; (a) would leave a live endpoint writing deprecated fields as a trap.
- **D3 fix**: (a) shrink modal to name-only + redirect to full form vs **(b) complete the modal conditionally (chosen)** — (a) breaks the quick-add flow used during onboarding/demo; (b) matches Square/Lightspeed quick-create-with-required-compliance-fields.
- **D6 fix**: (a) new `country_profiles` FE-only map vs **(b) consume the seeded `countries` API + thin FE placeholder map (chosen)** — the backend seeding already exists and is the single source of truth for labels/regex/prefix; only example placeholder strings stay FE-side.
