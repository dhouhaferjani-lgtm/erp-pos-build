# Per-Branch / Per-Establishment Tax ID — Design Spec (P0)

**Date:** 2026-06-04
**Branch:** `feat/branch-tax-id-spec` · worktree `apps/erp.branch-tax-id`
**Status:** Design — approved in brainstorming 2026-06-04; pending written-spec review.
**Research basis:** Docs 01–05 (`docs/superpowers/research/2026-06-04-branch-tax-id-01..05-*.md`).

## Scope statement (read first)

**In scope (this spec):** make the **seller tax identity** (tax number / VAT number / legal org id) resolve **per sellable branch**, with fallback to the company, across every output that prints or signs it. Owner-mandated (NON-NEGOTIABLE).

**Explicitly OUT of scope** — tracked in **Doc 08** (cross-module program) + 09a/b/c: per-branch *accounting* (ledger dimension, branch sub-accounts), per-branch *reporting/P&L*, per-branch *costing/valuation*, per-branch *user access control*, per-branch *invoice numbering*, and the B2B-document/commerce branch dimension generally. This spec is **P0** of that program and ships **independently** — it has no accounting or numbering impact.

---

## 1. Problem

Tax identity (`tax_id`, `vat_number`, `legal_identifiers`) lives **only on `Company`** (`Company.php:41,26`); `Location` has **no tax field** (`Location.php:70-88`). So every branch of a company emits the **same** tax number. But in AutoERP's core markets the establishment tax id genuinely differs per branch:
- **France:** SIRET = SIREN (9, company) + **NIC (5, establishment)** = 14 digits; NF525 v2.1 wants the issuing establishment's SIRET on the receipt.
- **Tunisia:** Matricule Fiscal carries a **3-digit establishment suffix** (`000`=HQ, `001`+=branches).
- Morocco (ICE establishment digits), Algeria (NIF/NIS suffix) similarly.
- IT/ES/DE/UAE/UK: single entity VAT number — branches inherit (Doc 05).

## 2. Goals / non-goals

**Goals:** (1) a nullable per-branch tax-identity override on `locations`; (2) **one** resolver (branch→company fallback) used by every output site; (3) the POS device authors `seller.tax_number` from the branch with **no fiscal payload schema/version change**; (4) repoint the non-signed outputs (FacturX, NF525-JET, receipt print, TEJ, withholding cert) through the resolver; (5) fix the latent NF525 null-SIRET bug.

**Non-goals:** anything in the Scope statement's out-of-scope list; per-country *required* branch tax ids (always optional/inherit); per-branch invoice numbering (per Doc 04/05 it's required nowhere; POS + fiscal chains are already per-terminal = per-branch).

## 3. Owner-locked decisions (from brainstorming 2026-06-04)

| # | Decision |
|---|---|
| D1 | **Model A** — nullable override columns on `locations`, not a separate `establishments` entity. |
| D2 | Override **fields = `tax_id` + `vat_number` + `legal_identifiers`** (full FacturX parity); per-field fallback `location.X ?? company.X`. |
| D3 | Columns on **all** locations, all nullable, **null = inherit company**. (Establishment *identity* is meaningful only for sellable locations, but the column is universal and inheriting; no type-gating logic.) |
| D4 | Validation = **country-driven format check when a value is provided** (reuse `TaxIdValidationService`); **never required**. FR multi-branch "should use SIRET" = non-blocking. |
| D5 | Migration is **pure-additive, no backfill** (no live tenants). |
| D6 | **No fiscal payload version bump** — value-source change only (see §7). |
| D7 | **No per-branch invoice numbering.** |

## 4. Model

New nullable columns on `locations` (tenant DB), mirroring `Company`'s tax-identity shape:

| Column | Type | Semantics |
|---|---|---|
| `tax_id` | `string(50)` nullable | Establishment tax number (FR SIRET, TN matricule, MA ICE…). `null` ⇒ inherit `company.tax_id`. |
| `vat_number` | `string(50)` nullable | Branch VAT registration (FacturX `VA`). `null` ⇒ inherit `company.vat_number`. |
| `legal_identifiers` | `jsonb default '{}'` | Branch country-specific identifiers; the `siret` key feeds FacturX legal-org. Empty/absent key ⇒ inherit `company.legal_identifiers`. |

**Migration:** new tenant migration `..._add_tax_fields_to_locations.php` (mirror `2025_12_30_103000_add_tax_fields_to_companies.php`). Additive only; no data step. `locations` already sits correctly in the tenant DB (3-tier rule — no rename).

**Model/contract changes:** `Location.php` fillable + casts (`legal_identifiers` ⇒ `array`) + docblock; `LocationResource.php:23-41` (+3 fields); `CreateLocationRequest`/`UpdateLocationRequest` (+rules, §6); `LocationController::store` whitelist (`:95-108`); frontend `features/locations/types.ts`, `features/location/api.ts` (all 4 shapes + `transformLocationResponse`), `LocationsPage.tsx`/`AddLocationModal`.

## 5. The resolver (one rule, two implementations)

**`TaxIdentityResolver`** — new service in the **Company module** (where `Location` lives).

```
resolve(Location|locationId): TaxIdentityDTO
  TaxIdentityDTO { taxId, vatNumber, legalIdentifiers[], countryCode }
  per-field: location.<f> ?? company.<f>
  countryCode: location.address_country ?? company.country_code   // for validation/jurisdiction
```

A `TaxIdentityDTO` (Shared/Contracts DTO, strict-typed) carries the resolved identity. Constructor injection only; no `app()`.

**The five server output sites call ONLY this resolver** (no scattered `?? company->tax_id`):

| # | Site | File | Change |
|---|---|---|---|
| 1 | POS receipt print | `resources/views/pos/receipt.blade.php:328-329` via `ReceiptController` | Controller resolves the receipt's `location_id` → pass resolved `tax_id` to the view. |
| 2 | FacturX seller block | `Document/.../FacturXService.php:110-145` | Resolve from `documents.location_id`; feed `VA`=vatNumber, `FC`=taxId, legal-org from `legalIdentifiers['siret']`, plus seller name/address (address stays company-level unless a branch address override is later added — out of scope). |
| 3 | NF525 JET header | `POS/.../Nf525DataProvider.php:524-558` (`buildCompanyHeader`) | **Fix the null-SIRET bug** (reads non-existent `siret`/`address` magic attrs today → null) by sourcing `siret` from the resolver. |
| 4 | Tunisia TEJ declarant | `Taxation/.../TEJExportService.php:79` | Resolve the declarant matricule per the export's establishment scope (export-level location, if available; else company). |
| 5 | Withholding certificate | `Taxation/.../CertificatePDFService.php:74` | Resolve `company_tax_id` via the resolver. |

**The device (Phase 2) is the 6th site** and applies the **identical fallback rule** on its local mirror (`terminal.location.tax_id ?? company.tax_id`) — same resolution contract, kept in parity with the PHP resolver. The PHP `TaxIdentityResolver` does **not** build the fiscal `seller` block (that is device-authored, §7).

## 6. Entry-time validation (D4)

Wire `Partner\Domain\Services\TaxIdValidationService` into `CreateLocationRequest`/`UpdateLocationRequest`:
- When `tax_id` (or `vat_number`) is **present**, validate its **format for the location's country** (`address_country ?? company.country_code`). Reject malformed (FR 14-digit SIRET+Luhn / 9-digit SIREN, TN compact matricule, etc. — already implemented in the service).
- When **absent**, accept (inherit). Never required.
- A **country-mode config** (Doc 05 §5, 4 modes: structural / separate-linked / branch-code / none) drives whether a branch value is *applicable* and which validator to apply. Initial config: FR/TN/MA = structural (validate); DZ = separate-linked (optional); IT/ES/DE/UAE/UK = none (typically inherit); EG/KSA = branch-code (e-invoice concern, deferred). Lives as a typed map (`config/tax_identity.php` or a Company-module enum/map) — the single source for "applicable per country."
- FR multi-branch "should use SIRET(14) not SIREN(9)": **non-blocking warning**, not a hard gate.

> Note: this is stricter than `Company.tax_id` (which has no validation today). Resolving that inconsistency for the company field is out of scope; flag it for the company-settings cleanup.

## 7. Fiscal-payload risk — reconciliation (D6, corrects Doc 02)

Doc 02 framed moving `seller.tax_number` to the branch as HIGH-RISK / versioned-event. **That conflated a value change with a schema change.** Reality:
- The canonical `seller` block keeps its exact 4-key shape `{address, name, tax_jurisdiction_country_code, tax_number}`. `seller.tax_number` still holds a per-country-valid tax number — **only the device's data *source* changes** (branch instead of company).
- ⇒ **No new key, no exact-key-set change, no `event_version` bump, no hash-chain risk.** `FiscalPayloadConstraintValidator`'s per-country `tax_number` regex already validates the branch value unchanged.
- **Golden fixtures stay valid** — they test that a *given* payload encodes/hashes correctly; they are not tied to company-vs-branch sourcing. No structural regen.
- Already-signed historical receipts keep their original (company) `tax_number` — the chain is immutable and the cutover is forward-only. Acceptable and expected.

## 8. Phasing

### Phase 1 — Server (moderate, NO fiscal risk) · its own PR
Migration (§4) · `Location` model/resource/requests + controller whitelist · `TaxIdentityResolver` + `TaxIdentityDTO` · country-mode config · entry validation (§6) · repoint the **5 server sites** (§5) incl. the **NF525 null-SIRET fix** · Location settings UI (web). Fully shippable alone; nothing fiscal.

### Phase 2 — Device (moderate, touches device, NO payload version bump) · its own PR
Confirmed device path: the device **already** syncs its `location` object (`terminal.location = {id,name,code}`) via `TerminalResource`.
1. **Backend:** add `tax_id`/`vat_number`/`legal_identifiers` to the location object in `POS/.../TerminalResource.php` (the location already eager-loads on activate `TerminalController.php:247`).
2. **Device store:** extend `terminalStore.ts` `Terminal.location` type with the tax fields (persisted to localStorage + SQLite mirror as today).
3. **Seller sourcing:** flip the binding in `paymentStore.ts:545` (SALE_RECEIPT), `:654` (ACCOUNT_PAYMENT), and `AccountChargePayload.ts:256` (ACCOUNT_CHARGE) to **prefer the branch**: `taxNumber: terminal.location?.tax_id ?? companyField(company, 'taxId', 'tax_id')`.
4. Parity: the device fallback rule mirrors the PHP `TaxIdentityResolver` exactly.

## 9. Testing (TDD)

- **Resolver unit tests:** per-field fallback; all-null inherit; partial override (e.g. branch `tax_id` set, `vat_number` null); country-code resolution.
- **Validation tests:** valid FR SIRET / TN matricule accepted; malformed rejected; absent accepted (inherit); per country-mode applicability.
- **Output-site tests:** receipt print, FacturX (`VA`/`FC`/legal-org), NF525 JET header (incl. the null-SIRET regression now fixed), TEJ, withholding cert — each asserts the branch value when set and the company value when null.
- **Phase 2:** `TerminalResource` carries branch tax fields; `paymentStore` sources branch over company; cross-language parity unaffected (fixtures unchanged, §7).
- Per AutoERP conventions: backend uses `RefreshDatabase` + real models + `RolesAndPermissionsSeeder`; no faked API responses. Frontend may `vi.mock` hooks for rendering isolation.

## 10. Gates (flag, not block)

- **TN per-establishment series is permissive** (Doc 05) — accountant confirmation; does not block this spec (no numbering change).
- **NACEF MDF** (TN, eff. 1 Jul 2026) — separate workstream; this spec only ensures the matricule's establishment suffix can be carried.
- **Turkey / Senegal-UEMOA** — low-confidence; out of initial scope.
- **EG/KSA branch-code-in-e-invoice** — config-aware, deferred with e-invoicing.

## 11. Open items for plan stage
- Exact location of the country-mode config (config file vs module map) — decide in plan.
- Whether `TaxIdentityDTO` lives in `Shared/Contracts` or the Company module (cross-module reads by Document/Taxation suggest `Shared/Contracts`).
- FacturX/TEJ "export-level location" resolution when an export spans branches — for this spec, resolve at document/company level; per-branch export is a Doc 08 (reporting) concern.

**End of design spec.** Next: writing-plans → implementation plan (Phase 1 first).
