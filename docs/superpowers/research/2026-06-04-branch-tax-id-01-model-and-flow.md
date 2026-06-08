# Branch (establishment) Tax-ID — Research Doc 01: Current Model + Data Flow + Where Per-Branch Would Attach

**Date:** 2026-06-04
**Branch:** `feat/branch-tax-id-spec` (checkout of current dev, tip `d35da55bd`)
**Scope:** Research only. This documents the current tax-identity model, every place company tax IDs propagate to outputs, and where a per-branch (per-establishment) tax ID would attach. It does **not** propose the spec.

> Context: France SIRET = 9-digit SIREN (the legal entity) + 5-digit NIC (the establishment) = 14 digits. The NIC is **per-establishment** — every physical branch of one company has its own SIRET sharing the SIREN. Tunisia matricule fiscal similarly has an establishment/secondary-number component. So "per-branch tax ID" maps almost exactly onto our `companies` (legal entity) → `locations` (physical place) split: today the SIRET lives only on `Company`, but legally it is an establishment-level number.

---

## 1. Where tax identity lives today

### 1.1 `Company` model/table — the ONLY home of tax identity

Model: `apps/api/app/Modules/Company/Domain/Company.php`
Table: `companies` (tenant DB). Created in `apps/api/database/migrations/tenant/2025_11_30_104000_create_companies_table.php`.

Tax / fiscal / legal columns (with casts and source):

| Column | Type (migration) | Cast | Notes |
|---|---|---|---|
| `country_code` | `char(2)` (required) | — | ISO 3166-1 alpha-2. Drives legal/fiscal system. `Company.php:34,40` docblock calls it CRITICAL. |
| `tax_id` | `string(50)` nullable | (none) | "VAT/Tax identification number" per docblock (`Company.php:41`). In FR/FacturX it is emitted as the **SIRET / "FC" registration**. Read into receipts + FacturX + TEJ + certificates. |
| `registration_number` | `string(100)` nullable | (none) | Company registration number. |
| `vat_number` | `string(50)` nullable | (none) | VAT number (may differ from `tax_id`). FacturX "VA" registration. |
| `legal_identifiers` | `jsonb default '{}'` | `array` | Country-specific identifiers bag. **The `siret` key here is what FacturX reads for the seller legal org** (see §2). |
| `default_tax_rate` | `decimal(5,2)` nullable | — | Added `2025_12_30_103000_add_tax_fields_to_companies.php`. |
| `default_tax_configuration_id` | `uuid` nullable FK → `tax_configurations` | — | Same migration. |
| `tax_status` | (enum col) | `CompanyTaxStatus::class` | `2026_01_02_100002_add_tax_status_to_companies.php`. Registered/not-registered; gates VAT recovery (`canRecoverVAT()` `Company.php:523`). |
| `fiscal_year_start_month` | `smallInteger default 1` | `integer` | |
| `fiscal_chain_seed` | (string) | (none) | 256-bit genesis seed for the fiscal hash chain (`2025_12_11_072844...`). Auto-generated in `booted()` `Company.php:124`. **Per-company chain seed — relevant if per-branch chains are ever considered.** |
| `compliance_profile` | string nullable | (none) | |

`fillable` is `Company.php:189-264`; `casts()` is `Company.php:271-305`.

Note the **inconsistency the NF525 code calls out**: `Nf525DataProvider::buildCompanyHeader()` (`apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php:524-558`) reads `$company->getAttribute('siret')` and `$company->getAttribute('address')` as **magic properties that do not exist as columns** — they fall through to `null`. So the NF525 JET `<Societe>` header currently emits a **null SIRET**. (`Nf525CompanyHeaderData` at `apps/api/app/Shared/Contracts/Compliance/DTOs/Nf525CompanyHeaderData.php` has `?string $siret`.) The real SIRET lives in `tax_id` or `legal_identifiers['siret']`, not a `siret` column — a latent bug worth flagging to the spec.

### 1.2 `Location` model/table — confirmed NO tax fields

Model: `apps/api/app/Modules/Company/Domain/Location.php`
Table: `locations` (tenant DB). Created `apps/api/database/migrations/tenant/2025_11_30_105000_create_locations_table.php`.

Columns: `id`, `company_id` (FK cascade), `name`, `code`, `type`, `phone`, `email`, `address_street`, `address_city`, `address_postal_code`, `address_country` (`char(2)` nullable), `latitude`, `longitude`, `is_default`, `is_active`, `pos_enabled`, `receipt_header`, `receipt_footer`, timestamps. **No `tax_id`, no `vat_number`, no `siret`, no `establishment_id`, no `nic`.** Confirmed in both migration (lines 23-65) and model `fillable` (`Location.php:70-88`).

Location already carries its own **address** (can differ from company) and its own **receipt_header/receipt_footer** — so the precedent of "Location overrides Company for output presentation" already exists for address and receipt chrome. Tax ID would be the natural next per-location override.

Location lives in the **Company module** (`app/Modules/Company/...`), not its own module.

### 1.3 Taxation module

`app/Modules/Taxation/` is about **tax rates / configurations / withholding / TEJ export**, not entity identity. It reads `company->tax_id` for output (see §2). It is **not** where a per-branch establishment ID belongs, but its validators (Partner module, see §4) are reusable.

### 1.4 Tenant model — deprecated tax_id

`app/Modules/Tenant/Domain/Tenant.php:39,94,155` still has a `tax_id` column but the docblock marks it `(deprecated - use Company)`. Ignore for per-branch work.

---

## 2. How company `tax_id` / `vat_number` PROPAGATES to outputs (concrete read sites)

These are every place a per-branch override would need to flow through. Grouped by output channel.

### 2.1 POS receipt PDF / print (the highest-volume per-branch case)

- `apps/api/resources/views/pos/receipt.blade.php:328-329` — prints `{{ $company->tax_id }}` under the company name/address header (label `pos.tax_id`). **This is the receipt seller block.** It reads `$company` directly; there is no `$location` tax field today. The blade already conditionally uses `$company->receipt_header/footer` and the receipt header/footer can be overridden per-Location (those columns exist on `locations`) — but the controller passes `$company`, so a per-branch tax ID would need the controller to resolve the receipt's location and override.
- Receipt controller / renderer: `app/Modules/POS/Presentation/Controllers/ReceiptController.php` (passes `company` to the view).

### 2.2 Fiscal SALE_RECEIPT canonical payload `seller` block

This is the legally load-bearing one. The canonical fiscal payload has a required `seller` object with keys `{address, name, tax_jurisdiction_country_code, tax_number}`:

- Validated in `app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:1468-1494` (`validateSeller()`): requires `name`, `tax_jurisdiction_country_code` (ISO 3166-1 alpha-2), `tax_number` (per-country regex via `assertTaxNumberForCountry` — see §4), and `address`.
- DTO surface: `app/Modules/Fiscal/Domain/DTOs/SaleReceiptPayload.php:62,121` (`$seller`), also `AccountPaymentPayload.php`, `AccountChargePayload.php`, `Canonical/SellerDTO.php`.
- Read back by `app/Modules/Fiscal/Application/Services/CanonicalPayloadReader.php:76,104,138,167` → `SellerDTO::fromArray($payload->seller)`.

**Important data-flow finding:** the `seller` block is **client/device-authoritative**. It arrives inside the device-submitted canonical payload (the Tauri/POS client builds it from its local company snapshot), is hashed into `fiscal_events.canonical_bytes`, and the server only **validates** it — there is no server-side `buildSeller(Company)` in this repo. Confirmed: no producer found for `'seller' =>` / `tax_jurisdiction_country_code` outside DTOs/validator/FacturX. `PosCoreReceiptProjection.php:92` documents the `seller` block as part of the inbound canonical payload. **Consequence:** to put a branch SIRET on the fiscal receipt, the *device* must source `seller.tax_number` from the branch, not (only) the company — so any per-branch model has a client-side counterpart, and because it's hashed into the immutable chain it cannot be retro-overridden server-side.

### 2.3 e-invoicing (FacturX / Factur-X / ZUGFeRD)

`app/Modules/Document/Application/Services/FacturXService.php`, `setSellerInformation()` (`:110-145`):
- `:112` seller name = `legal_name ?? name`
- `:114-121` seller address from `company->address_*` + `country_code`
- `:123-124` `addDocumentSellerTaxRegistration('VA', $company->vat_number)`
- `:127-128` `addDocumentSellerTaxRegistration('FC', $company->tax_id)`
- `:131-133` `legal_identifiers['siret']` → `setDocumentSellerLegalOrganisation(...)` (scheme `0002` = SIRET)

This is the **second** place a branch SIRET must flow: FR FacturX wants the establishment SIRET as the seller legal org. All four reads (`vat_number`, `tax_id`, address, `legal_identifiers['siret']`) are `$company->...`.

### 2.4 NF525 JET export

`app/Modules/POS/Application/Services/Nf525DataProvider.php:524-558` `buildCompanyHeader()` → `Nf525CompanyHeaderData{ id, name, siret, address }`. As noted in §1.1, `siret`/`address` are read as **non-existent magic attributes** and resolve to `null` today. A per-branch model + a fix here would let the JET header carry a real establishment SIRET. (The per-receipt fiscal `seller` block in §2.2 is separate and is the authoritative one.)

### 2.5 Tunisia TEJ export + withholding certificates

- `app/Modules/Taxation/Application/Services/TEJExportService.php:79` — `MatriculeFiscal` = `$company->tax_id` (declarant block). `:116` partner side reads `partner->vat_number`.
- `app/Modules/Taxation/Application/Services/CertificatePDFService.php:74` — `company_tax_id => $company->tax_id`.

### 2.6 Billing (platform billing of the tenant — out of scope)

`app/Modules/Billing/Application/Services/InvoiceService.php:402` reads `config('billing.company.vat_number')` — this is **Synerivia's own** billing entity, not the tenant's. Ignore.

### 2.7 Settings / API surfaces that read+write company tax id (entry, not output)

- `app/Modules/Company/Presentation/Controllers/CompanyController.php:76-78,560-562` (create/show).
- `app/Modules/Company/Presentation/Requests/CreateCompanyRequest.php:28-30`, `UpdateCompanyRequest.php:39-41` (`tax_id`, `vat_number` — `nullable string max:50`, **no per-country validation today**).
- `app/Modules/Tenant/Presentation/Controllers/CompanySettingsController.php:104` + `UpdateCompanySettingsRequest.php:30`.
- `app/Modules/Identity/Presentation/Controllers/AuthController.php:405`, `UserController.php:719`, `RegisterRequest.php:78` (signup-time capture).

**Summary of output sites a per-branch override must reach:** (1) `receipt.blade.php:328`, (2) device-side fiscal `seller.tax_number` (client) + its validator `FiscalPayloadConstraintValidator.php:1487-1492`, (3) `FacturXService.php:123-133`, (4) `Nf525DataProvider.php:524-558`, (5) `TEJExportService.php:79`, (6) `CertificatePDFService.php:74`.

---

## 3. What a per-branch tax-ID model would touch

### Option A — Add nullable override columns to `locations` (override-of-company)

Add to `locations`: `tax_id` (and/or `vat_number`, `establishment_id`/`nic`, `legal_identifiers` jsonb) — all nullable, semantics = "use this if set, else fall back to the parent company's value."

- Migration: new tenant migration in `apps/api/database/migrations/tenant/` (e.g. `..._add_tax_fields_to_locations.php`). All nullable → **no backfill required** (null = inherit company). Optionally backfill the company's `tax_id` onto the `is_default` location if the spec wants the default branch to be explicit.
- Touches: `Location.php` fillable + docblock + casts; `LocationResource.php` (add fields); `CreateLocationRequest.php`/`UpdateLocationRequest.php` (validation, ideally per-country — see §4); `LocationController::store/update` (`store()` whitelists fields at `:95-108`); frontend `types.ts`, `api.ts`, `LocationsPage.tsx`/`AddLocationModal` (see §5). Then a **resolver** (`Location->tax_id ?? Location->company->tax_id`) injected at each output site in §2.
- **3-tier naming rule** (`claude/database-topology.md:21` "3-tier naming rule (authoritative)"): `locations` is a tenant-DB, company-scoped table — adding columns to an existing tier-appropriate table needs no rename. New columns must be categorized but `locations` already sits correctly in the tenant DB.
- Pro: minimal, matches the existing "Location overrides Company for address + receipt header/footer" precedent. Con: blurs Location (physical place) with establishment (legal sub-entity).

### Option B — Separate `establishments` entity between Company and Location

A new `establishments` table (company_id FK, siret/nic, tax_id, address) with `locations.establishment_id` FK; many locations can map to one establishment.

- Heavier: new model + module placement decision (lives in Company module per §1.2), new migration, new resolver layer, FK on locations, backfill (every existing location → a default establishment per company). 
- Pro: legally cleaner (an establishment is exactly the SIRET-bearing unit; a "location" like a warehouse may not be a sales establishment). Con: larger surface, more migration/backfill risk, more UI.

**Migration location either way:** `apps/api/database/migrations/tenant/` (NOT central — tax identity is tenant data). Pattern to mirror: `2025_12_30_103000_add_tax_fields_to_companies.php` (Option A) or `2025_11_30_105000_create_locations_table.php` (Option B).

---

## 4. Validation reuse — per-country tax-number validators

Two independent validator layers exist; both are reusable for a per-branch establishment ID:

### 4.1 `TaxIdValidationService` (Partner module)

`app/Modules/Partner/Domain/Services/TaxIdValidationService.php` + `TaxIdValidationResult.php`. `validate(countryCode, registrationNumber)` dispatches by country:
- **FR** (`:26-65`): exactly **14 digits + Luhn checksum** → this is precisely a **SIRET** (returns format label `'SIRET'`). It already validates the establishment-level number. ✅ Directly usable for a branch SIRET.
- **TN** (`:67-91`): regex `^\d{7}[A-Z][A-Z0-9]{3}$` → "7 digits + 1 letter + 3 characters" matricule fiscal. The trailing 3 chars are effectively the establishment/secondary code, so it covers an establishment matricule. ✅
- **IT** (`:93-131`): Codice Fiscale (16) / Partita IVA (11). **GB** (`:133-157`): 8-digit CRN.
- Exposed via `PartnerController.php:373` (`$this->taxIdValidationService->validate(...)`).

### 4.2 Fiscal payload per-country regex table (Phase 1.5.2)

`app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:142` ("Phase 1.5.2 seller/customer tax-number regex table") + `assertTaxNumberForCountry()` (called at `:1492` for `seller.tax_number`). This validates the `tax_number` inside the fiscal `seller` block per `tax_jurisdiction_country_code`. A per-branch tax ID flowing into `seller.tax_number` is **already validated here** by jurisdiction — no new validator needed for the fiscal path; the branch number just has to satisfy the existing country regex.

**Recommendation for the spec:** reuse `TaxIdValidationService` in `CreateLocationRequest`/`UpdateLocationRequest` (entry-time), and rely on `FiscalPayloadConstraintValidator` for the fiscal-path (device-submitted) guarantee. Note both are FR-SIRET-aware already.

---

## 5. UI / config — where a per-branch tax ID would be entered

### Frontend (apps/web)
- **Page:** `apps/web/src/features/settings/LocationsPage.tsx` — the Location settings CRUD. `LocationFormData` (`:28-39`) has name/type/code/phone/email/address*/posEnabled — **no tax field**. A `taxId` field would be added here.
- **Modal:** `apps/web/src/features/location/AddLocationModal.tsx` (and `components/organisms/AddLocationModal/`).
- **API client:** `apps/web/src/features/location/api.ts` — `CreateLocationInput`/`UpdateLocationInput` (`:29-57`) + the snake_case `CreateLocationPayload`/`UpdateLocationPayload` (`:62-87`) + `transformLocationResponse` (`:161-180`). A `taxId ↔ tax_id` mapping must be added in all four shapes.
- **Types:** `apps/web/src/features/locations/types.ts` `Location` interface (`:8-25`) — add `taxId: string | null`. Also `apps/web/src/stores/locationStore.ts` `LocationType`/location store shape.

### Backend Location API / DTO (the contract to extend)
- **Resource (output):** `app/Modules/Company/Presentation/Resources/LocationResource.php:23-41` — add `tax_id`.
- **Requests (input):** `CreateLocationRequest.php:21-35`, `UpdateLocationRequest.php` — add `tax_id` rule (ideally country-aware via §4).
- **Controller:** `app/Modules/Company/Presentation/Controllers/LocationController.php` — `store()` whitelists fields explicitly at `:95-108` (must add `tax_id`); `update()` mass-applies `$validated` at `:152` (would pick up `tax_id` automatically once it's in the request rules + fillable).
- **Model:** `Location.php` fillable (`:70-88`) + casts + docblock.
- There is no `LocationData` DTO today (LocationService is a thin code-lookup at `LocationService.php`); the Resource + Requests ARE the contract.

---

## Open questions for the spec

1. **Establishment vs Location semantics.** Is a per-branch tax ID a nullable override on `locations` (Option A) or a distinct `establishments` entity (Option B)? Warehouses/offices are `locations` but are not necessarily sales establishments — does every location need a tax ID, or only `type=shop` / `pos_enabled`?
2. **Inherit-vs-required.** Should `locations.tax_id` be a nullable override (null ⇒ inherit company `tax_id`), or required once multi-branch is enabled in a SIRET-jurisdiction (FR)?
3. **Which fields per branch?** Just `tax_id` (SIRET)? Also `vat_number`? Also a per-branch `legal_identifiers`/`nic`? FR FacturX reads `vat_number` + `tax_id` + `legal_identifiers['siret']` separately (§2.3) — all three may need a branch variant for a fully correct FacturX seller block.
4. **Fiscal `seller` block is client-authoritative + hashed into the immutable chain (§2.2).** The branch tax number on a *fiscal receipt* must be sourced by the device, not retro-overridden server-side. Does the spec own the device/client change, or is server-side scope limited to receipts/FacturX/NF525/TEJ (non-chained outputs)? How do we keep the device's branch snapshot in sync?
5. **NF525 `<Societe>` SIRET bug.** `Nf525DataProvider::buildCompanyHeader()` reads non-existent `siret`/`address` magic attributes → emits null SIRET today (§1.1/§2.4). Fix as part of this spec (point it at the resolved branch/company SIRET) or track separately?
6. **`fiscal_chain_seed` is per-company** (§1.1). Does per-branch identity imply anything about per-branch/per-terminal chain segmentation, or is the chain firmly per-company/per-terminal and the branch tax ID is purely a presentation/registration attribute?
7. **Backfill of the default location.** For existing tenants, should the company's current `tax_id` be copied onto the `is_default` location, or left null (inherit)? Affects whether the migration is pure-additive or has a data step.
8. **Validation strictness at entry.** Wire `TaxIdValidationService` into the Location request (reject invalid SIRET/matricule), or accept free-form like the current company `tax_id` (which has NO per-country validation, §2.7)? Inconsistency to resolve.
9. **Tunisia establishment number.** TN matricule has an establishment component; the current regex (§4.1 TN) validates the whole matricule. Does per-branch in TN mean a different full matricule per branch, or a shared matricule + per-branch establishment suffix?
10. **Cross-module read path.** Other modules read `company->tax_id` directly (Taxation, Document/FacturX). Should the spec introduce a single resolver/service (`resolveTaxIdentity(locationId|receipt)`) to avoid scattering `?? company->tax_id` fallbacks across §2's six sites?
