# Per-Branch / Per-Establishment Tax ID — Design Spec (P0)

**Date:** 2026-06-04
**Branch:** `feat/branch-tax-id-spec` · worktree `apps/erp.branch-tax-id`
**Status:** Design — **rev 2** after Codex adversarial review 2026-06-04 (`docs/superpowers/reviews/2026-06-04-branch-tax-id-P0-codex-adversarial-review.md`, verdict NEEDS-REWORK → findings remediated below). Pending re-review.
**Research basis:** Docs 01–05 (`docs/superpowers/research/2026-06-04-branch-tax-id-01..05-*.md`).

**Rev-2 changes (Codex review remediation):** B1 — entry validation sourced from the **fiscal** per-country regex table, not the divergent Partner `TaxIdValidationService` (§6). B2 — tax-ID **required at branch creation** when a sellable `Shop` is in a country whose config marks it required (§3 D4, §6). M1 — corrected the "no hash-chain risk" wording (§7). M2 — only **2** sites are truly location-scoped; NF525/TEJ/cert are company-level (§5). M3 — corrected the ACCOUNT_CHARGE device reference (§8). m1/n1 — terminal refresh paths + `ReceiptPdfService` boundary (§8).

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
| D4 | Validation = **country-driven format check**, sourced from the **fiscal validator's per-country regex table** (NOT the Partner `TaxIdValidationService`, which diverges — §6). **Required at branch creation when the location is a sellable `Shop` AND the country config's `branch_tax_id_required` is true** (FR/TN-like); optional/inherit otherwise. *(rev 2 — was "never required"; owner refined 2026-06-04: require for sellable shops in structural-ID countries, config-driven.)* |
| D5 | Migration is **pure-additive, no backfill** (no live tenants). |
| D6 | **No fiscal payload version bump** — value-source change only (see §7). |
| D7 | **No per-branch invoice numbering.** |

## 4. Model

New nullable columns on `locations` (tenant DB), mirroring `Company`'s tax-identity shape:

| Column | Type | Semantics |
|---|---|---|
| `tax_id` | `string(50)` nullable | Establishment tax number (FR SIRET, TN matricule, MA ICE…). `null` ⇒ inherit `company.tax_id`. |
| `vat_number` | `string(50)` nullable | Branch VAT registration (FacturX `VA`). `null` ⇒ inherit `company.vat_number`. |
| `legal_identifiers` | `jsonb` nullable | Branch country-specific identifiers; the `siret` key feeds FacturX legal-org. `null`/empty/absent key ⇒ inherit `company.legal_identifiers`. |

**Migration:** new tenant migration `..._add_tax_fields_to_locations.php` (mirror the tax-identity column shapes in `2025_11_30_104000_create_companies_table.php`, while keeping all location overrides nullable per D3). Additive only; no data step. `locations` already sits correctly in the tenant DB (3-tier rule — no rename).

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

**Rev 2 (Codex M2):** only **two** server outputs are genuinely location-scoped today and call the resolver. The other three (NF525 JET, TEJ, withholding cert) are **company-wide exports with no single `location_id` to resolve** — they stay company-level here; per-branch export scoping is a Doc 08 (reporting) concern.

**Location-scoped sites — call the resolver per the document/receipt's location:**

| # | Site | File | Change |
|---|---|---|---|
| 1 | POS receipt print | `resources/views/pos/receipt.blade.php:328-329` rendered by **`ReceiptPdfService`** (loads `$receipt->location` already, `ReceiptPdfService.php:46-65,140-144`) | Resolve from the receipt's `location_id` in `ReceiptPdfService`; pass the resolved `tax_id` into the view (not in `ReceiptController` — n1). |
| 2 | FacturX seller block | `Document/.../FacturXService.php:110-145` (currently loads only company/partner/lines `:69-83`) | **Add `location` loading**, resolve from `documents.location_id` (fallback company when null); feed `VA`=vatNumber, `FC`=taxId, legal-org from `legalIdentifiers['siret']`. Seller address stays company-level (branch address override out of scope). |

**Company-level sites — fix/keep at company scope (no per-branch resolution):**

| # | Site | File | Change |
|---|---|---|---|
| 3 | NF525 JET header | `POS/.../Nf525DataProvider.php:524-558` (`buildCompanyHeader`, company-wide export `:86-99`) | **Fix the null-SIRET bug** (reads non-existent `siret`/`address` magic attrs → null) by sourcing from `Company` (or the export's per-terminal establishment if a future per-establishment export is built). Not a single-location resolve. |
| 4 | Tunisia TEJ declarant | `Taxation/.../TEJExportService.php:47-79` (batch uses first cert as declarant; `withholding_certificates` has no `location_id`) | **Company-level** declarant. Per-branch TEJ = future reporting scope. |
| 5 | Withholding certificate | `Taxation/.../CertificatePDFService.php:59-76` (no certificate-level location) | **Company-level.** Per-branch = future. |

**The device is the 6th, fiscal-path site** (Phase 2): it applies the **identical fallback rule** on its local mirror (`terminal.location.tax_id ?? company.tax_id`) — same resolution contract, kept in parity with the PHP resolver. The PHP `TaxIdentityResolver` does **not** build the fiscal `seller` block (device-authored, §7).

## 6. Entry-time validation (D4) — rev 2 (Codex B1 + B2)

**Do NOT reuse `Partner\Domain\Services\TaxIdValidationService`** — Codex confirmed it diverges from the fiscal/legal formats the device must author:
- FR: it requires **exactly 14 digits + Luhn** (`TaxIdValidationService.php:26-44`), rejecting the 9-digit SIREN the fiscal validator accepts.
- TN: it is `^\d{7}[A-Z][A-Z0-9]{3}$` = **1 letter** (e.g. `1234567A000`, `:67-91`), but the locked compact matricule is **2 letters** (`1234567AM000`, `FiscalPayloadConstraintValidator.php:150-156`).
- No MA/DZ path (unknown countries return *valid*), and it is wrong for `vat_number` (a FR `FR…` VAT id is not a SIRET).

**Single source of truth = the fiscal validator's per-country regex table** (`FiscalPayloadConstraintValidator` `TAX_NUMBER_PATTERNS`). Extract it into a shared validator (e.g. `Shared/Contracts` or a Taxation service) used by **both** entry-time Location requests **and** the fiscal path, so Phase 1 can never reject a value Phase 2/fiscal must author. Validate **per field** — `tax_id`, `vat_number`, and `legal_identifiers['siret']` have different formats.

**Required-vs-optional (owner decision 2026-06-04):** the **country-mode config** carries a per-country `branch_tax_id_required` flag (alongside the Doc 05 §5 4-mode: structural / separate-linked / branch-code / none). Rule:
- If the location is a **sellable `Shop`** AND `branch_tax_id_required` is true for its country (FR/TN-like, structural) → **`tax_id` is REQUIRED at branch creation** (hard validation on `CreateLocationRequest`).
- Otherwise → optional; `null` ⇒ inherit company.
- Non-shop locations (warehouse/office/mobile) → never required.
- Config lives as a typed map (`config/tax_identity.php` or a Company-module enum/map) — the single source for both "applicable" and "required" per country. Initial: FR/TN/MA structural + required; DZ separate-linked optional; IT/ES/DE/UAE/UK none; EG/KSA branch-code (deferred).

> The DB columns stay nullable (a warehouse, or a shop in a non-requiring country, is null=inherit); "required" is enforced at the **request** layer for sellable shops in requiring countries — not a NOT NULL constraint.

> Note: this is stricter than `Company.tax_id` (no validation today). Resolving that for the company field is out of scope; flag for company-settings cleanup.

## 7. Fiscal-payload risk — reconciliation (D6, corrects Doc 02)

Doc 02 framed moving `seller.tax_number` to the branch as HIGH-RISK / versioned-event. **That conflated a value change with a schema change.** Reality:
- The canonical `seller` block keeps its exact 4-key shape `{address, name, tax_jurisdiction_country_code, tax_number}`. `seller.tax_number` still holds a per-country-valid tax number — **only the device's data *source* changes** (branch instead of company).
- ⇒ **No new key, no exact-key-set change, no `event_version` bump.** This is a **forward-only v1 semantic cutover** (deliberate, documented). `FiscalPayloadConstraintValidator`'s per-country `tax_number` regex already validates the branch value unchanged.
- **Precise hash statement (rev 2 — Codex M1, corrects "no hash-chain risk"):** `seller.tax_number` **is** hash-covered (`FiscalEventEngine.ts:608-629` → `canonicalCore.ts:39-83`; server re-hashes verbatim, `HashChainIntegrityProvider.php:16-23`). So each **new** affected receipt's canonical bytes + event hash **naturally differ** when the value is the branch's — that is expected and fine (the chain verifies device bytes; it does not recompute). What is NOT true is any *retroactive* or *schema/version* break. Add **cross-language source-path tests** (device sources branch over company; PHP validator accepts it) to lock the behavior.
- **Golden fixtures stay valid** — they pin a *given* payload's bytes→hash; they are not tied to company-vs-branch sourcing. No structural regen. (If a golden *authoring* fixture is later changed to demonstrate branch sourcing, its bytes/hash update via the documented golden-update path `v3-golden-hashes/README.md` — that is a fixture edit, not a chain break.)
- Already-signed historical receipts keep their original (company) `tax_number` — the chain is immutable and the cutover is forward-only. Acceptable and expected.

## 8. Phasing

### Phase 1 — Server (moderate, NO fiscal risk) · its own PR
Migration (§4) · `Location` model/resource/requests + controller whitelist · `TaxIdentityResolver` + `TaxIdentityDTO` · country-mode config (incl. `branch_tax_id_required`) · shared fiscal-aligned validator + conditional-required entry validation (§6) · repoint the **2 location-scoped sites** (§5 #1 `ReceiptPdfService`, #2 `FacturXService`) · the **company-level NF525 null-SIRET fix** (§5 #3) · Location settings UI (web). Fully shippable alone; nothing fiscal.

### Phase 2 — Device (moderate, touches device, NO payload version bump) · its own PR
Confirmed device path: the device **already** syncs its `location` object (`terminal.location = {id,name,code}`) via `TerminalResource`.
1. **Backend:** add `tax_id`/`vat_number`/`legal_identifiers` to the `location` object in `POS/.../TerminalResource.php:27-34`. **(m1)** Cover **every** terminal endpoint the device store can consume, not just activate — `show`/`index`/`claim`/`by-device`/`toggle-training` (`TerminalController.php:50-82,247-249,300-308,470-481,522-523`) all shape the terminal object; ensure each loads `location` so the device never caches a tax-less location.
2. **Device store:** extend `terminalStore.ts` `Terminal.location` type with the tax fields (persisted to localStorage + SQLite mirror, `terminalStore.ts:390-451,527-570`).
3. **Seller sourcing (rev 2 — Codex M3):** flip the **live** bindings in `paymentStore.ts:543-550` (SALE_RECEIPT) and `:654-661` (ACCOUNT_PAYMENT) to **prefer the branch**: `taxNumber: terminal.location?.tax_id ?? companyField(company, 'taxId', 'tax_id')`. **ACCOUNT_CHARGE is NOT covered by `AccountChargePayload.ts:256`** — that line is inside `goldenAccountChargePayload()` (a fixture). The live builder is `accountChargeService.ts` (accepts `input.seller`, `:66-77,318-333`) and Codex found **no production caller** supplying account-charge seller data. ⇒ ACCOUNT_CHARGE seller sourcing is **out of P0** (not live); if/when a real account-charge UI/store caller is added, it must source the branch the same way. Document this rather than patching a fixture.
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
