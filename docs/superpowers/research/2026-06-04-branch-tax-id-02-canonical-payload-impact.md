# Branch Tax-ID — Dimension 02: Canonical Payload + Receipt/Invoice Seller-Identity Impact

**Date:** 2026-06-04
**Branch worktree:** `apps/erp.branch-tax-id` (current dev)
**Dimension:** Signed canonical fiscal payload + receipt/invoice seller identity
**Question:** If the seller `tax_number` must become the BRANCH establishment ID instead of the COMPANY tax ID, does that change the signed canonical bytes (high-risk, versioned event) or is it display-derived (low-risk)?

---

## VERDICT: HIGH-RISK — the seller `tax_number` IS in the signed canonical bytes.

The SALE_RECEIPT `seller` block (name, address, `tax_jurisdiction_country_code`, `tax_number`) is a **required, hash-covered key** in the device-authored canonical payload. The device builds it from **COMPANY** fields today, JCS-serializes the whole object (sorted keys, full payload), SHA-256-hashes it, and the server **re-hashes the verbatim bytes and never recomputes** them. The printed receipt and account-payment receipt read `tax_id` straight **out of the signed payload**, not from a live company/branch lookup. Therefore moving `seller.tax_number` from company-level to branch establishment-ID **changes the canonical bytes → changes every hash → requires the same versioned-event + device/server-coordination + golden-fixture-regen treatment as the deferred `unit_price` rename.**

It is NOT a display-only field. It cannot be changed by a server-side projection edit or a print-template tweak.

---

## 1. The SALE_RECEIPT canonical seller block — in the signed bytes

**`seller` is one of the 28 required canonical SALE_RECEIPT keys.**
- `apps/api/.../Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:222-252` — `PAYLOAD_KEYS['SALE_RECEIPT']` lists `seller` (line 240). Extras-rejection + missing-required gate (`validatePayloadKeySet`, lines 312-348) means `seller` must be present, exactly-shaped.
- Same file `validateSeller()` at **lines 1466-1497**: `seller` must be an object with EXACTLY the keys `['address', 'name', 'tax_jurisdiction_country_code', 'tax_number']` (line 1473). `tax_number` is validated per-country via `assertTaxNumberForCountry($seller['tax_number'], $jurisdiction, 'seller.tax_number')` (line 1492). Country regexes at lines 150-156 (FR `^([0-9]{9}|[0-9]{14})$`, TN `^[0-9]{7,8}[A-Z]{2}[0-9]{3}$`, SA, DE, IT) + universal baseline line 139.

**DTO surface confirms the same shape.**
- `apps/api/.../Fiscal/Domain/DTOs/SaleReceiptPayload.php:62` — `public array $seller` is a required constructor arg; `fromArray()` calls `requireArray($data, 'seller')` (line 83); `toArray()` emits `'seller' => $this->seller` (line 160).
- `apps/api/.../Fiscal/Domain/DTOs/Canonical/SellerDTO.php:18-52` — typed view: `address`, `name`, `taxJurisdictionCountryCode`, `taxNumber` (line 24, `tax_number` required-string). Doc-comment lines 11-16: "REQUIRED on every SALE_RECEIPT (NF525 SIRET + TN matricule fiscal + KSA 15-digit ...)". Built by `CanonicalPayloadReader::forSaleReceipt()` (`CanonicalPayloadReader.php:76`).

**Device builds the seller block from COMPANY fields today.**
- `apps/pos/src/lib/fiscal/payloads/SaleReceiptPayload.ts:232-260` — `buildSellerBlock()` emits `{address, name, tax_jurisdiction_country_code, tax_number}` into the payload object that is then hashed. `tax_number` comes from `input.seller.taxNumber` (line 235).
- `apps/pos/src/stores/paymentStore.ts:543-550` — the seller input is sourced entirely from `company`:
  `taxNumber: companyField(company, 'taxId', 'tax_id')`, `name: legalName/name`, `countryCode/street/city/postalCode` all `companyField(company, …)`. **This is the company-level binding that a branch-tax-id spec would have to repoint.**
- Same pattern for ACCOUNT_PAYMENT (`paymentStore.ts:654`) and ACCOUNT_CHARGE (`AccountChargePayload.ts:256`) seller blocks, and the validator enforces `seller` on those event types too (`FiscalPayloadConstraintValidator.php:918, 1092`).

## 2. Is it in the signed bytes or display-derived? — DEFINITIVELY in the signed bytes

- **Device-authors-and-hashes:** `apps/pos/src/lib/fiscal/FiscalEventCanonicalEncoder.ts:2-16` — JCS canonical encoder (sorted object keys, positional array order, full-object serialization) + SHA-256. The whole payload object — `seller` included — is serialized and hashed. There is no field-exclusion; the encoder hashes the entire canonical object.
- **Server re-hashes, never recomputes:** `apps/api/.../Fiscal/Application/Services/HashChainIntegrityProvider.php:18` — `return hash('sha256', $canonicalBytes);`. `OutboxIngestor.php:734` fails the row with `canonical_hash_mismatch:sha256(canonical_bytes)!=current_hash` if the device bytes don't re-hash to `current_hash`. The server stores `canonical_bytes` verbatim (`OutboxIngestor.php:815, 1173, 1211`). The in-code contract is stated explicitly at `SaleReceiptPayload.ts:26-32` ("the server stores it verbatim and verifies by re-hashing the bytes — it does NOT recompute the arithmetic").
- **Print/display reads OUT of the signed payload, not a fresh lookup:** `apps/pos/src/lib/buildReceiptData.ts:296-302` builds the printable receipt's `company.tax_id` from `payload.seller.tax_number` (line 302), name/address from `payload.seller.*`. So even the printed `tax_id` is the signed value — there is no print-time company/branch re-derivation that could be swapped cheaply.

**Conclusion:** any change to what `seller.tax_number` carries changes the bytes the device signs AND what the receipt prints. Both ends move together.

## 3. Invoice / Facture / FacturX e-invoice seller identity — also company-level, separate surface

- `apps/api/.../Document/Application/Services/FacturXService.php:108-142` — `setSellerInformation(builder, Company $company)` pulls seller identity **entirely from `$company`**:
  - `addDocumentSellerTaxRegistration('VA', $company->vat_number)` (line 124)
  - `addDocumentSellerTaxRegistration('FC', $company->tax_id)` (line 127)
  - `setDocumentSellerLegalOrganisation($siret, '0002', …)` from `$company->legal_identifiers['siret']` (lines 131-133)
  - seller name/address all `$company->*` (lines 112-120).
- This is the B2B invoice / credit-note / FacturX XML path. It is NOT hash-chained (it's document generation, not a fiscal event), so per-branch here is **lower-risk than the canonical payload** — but it is a SECOND code path that also hard-binds seller identity to `Company`. A branch-tax-id spec must repoint BOTH or accept that the POS ticket and the B2B invoice would disagree on the seller establishment ID.
- The B2B branch is reached when a partner has a VAT number and `company->country_code === 'FR'` (`FacturXService.php:42-49`). ACCOUNT_CHARGE `b2b_facture_draft_requested` classification (`FiscalPayloadConstraintValidator.php:199, 1127`) is the seam that feeds this.

## 4. Other fiscal artifacts embedding seller tax id

- **NF525 JET export XML:** `apps/api/.../Compliance/Services/Nf525/Nf525XmlBuilder.php:56-76` emits a company-level `SIRET` element from `$data['siret']` (company identity, archive header). Company-level; a branch establishment ID would need to flow here too for the archive to name the right establishment.
- **Z-reports / X-reports / audit tickets:** the Z/X-report family payloads (`validateZReportFamilyPayload`, `FiscalPayloadConstraintValidator.php:546-553`) carry `session_id / shift_id / operator_id / terminal_id` + `business_date` + `training_flag` — they do **NOT** embed a seller `tax_number` directly. Z-report linkage to fiscal events is at migration `2026_05_24_120000_add_fiscal_event_linkage_to_pos_z_reports.php`. So the hash chain's seller identity lives in the per-receipt SALE_RECEIPT / ACCOUNT_PAYMENT / ACCOUNT_CHARGE events, not the Z/X aggregates.
- **Hash chain itself:** chain integrity is over `canonical_bytes` (which include `seller`), so per-receipt seller identity is implicitly chained; there is no separate seller field on the chain envelope.

## 5. Blast radius if `seller.tax_number` becomes the branch establishment ID

This is materially the same shape as the deferred `unit_price` rename:

1. **Canonical bytes change** → every SALE_RECEIPT/ACCOUNT_PAYMENT/ACCOUNT_CHARGE hash changes. Not a backward-compatible additive change because the seller block is a fixed exact-key object (`validateSeller` lines 1473-1483 reject extras AND missing).
2. **Versioned fiscal event likely required.** Today `FiscalEventPayloadRegistry.php:52-53` maps `SALE_RECEIPT => [SaleReceiptPayload::class, 1]`, and the rationale comment in `SaleReceiptPayload.php:9-15` says event_version stays at 1 *"there is no production tenant to migrate, so we rewrite v1 in place."* If branches ship after a tenant goes live, the rewrite-in-place escape hatch is gone and a SALE_RECEIPT v2 (or an additive `seller.establishment_id` key) is needed — with the registry + `PAYLOAD_KEYS` + `validateSeller` exact-key-set all updated in lockstep.
3. **Device ↔ server coordination.** `buildSellerBlock` (TS) + `validateSeller` (PHP) + `SellerDTO` must change together or every ticket quarantines (`payload_seller_*` failures route to the same quarantine path as a parse failure).
4. **Golden-fixture regen, cross-language.** 15 server golden fixtures under `apps/api/tests/Fixtures/Fiscal/sale-receipt-golden/v4/*/payload.json` embed `seller.tax_number` literally in the hashed bytes (e.g. F-04 `"tax_number":"12345678901234"`). `apps/pos/scripts/check-fiscal-fixture-parity.sh` runs the cross-language encoder/hash parity tests. Changing the seller semantics forces a fixture + golden-hash regen on both sides plus the v3-golden-hashes vectors (`apps/api/tests/Fixtures/Fiscal/v3-golden-hashes/*.json`).
5. **Print-time + B2B-invoice repoint** (`buildReceiptData.ts:302`, `FacturXService.php:124-133`, `Nf525XmlBuilder.php:76`) so the displayed/archived establishment ID matches the signed one.

---

## Open questions for the spec

1. **Additive vs replace:** keep `seller.tax_number` = company tax ID and ADD a `seller.establishment_id` (SIRET-style) key, vs. REPLACE `tax_number` with the branch establishment ID? Additive still changes the exact-key set in `validateSeller` (lines 1473-1483) and the bytes, so it is still a versioned change — but it preserves the company-level fiscal registration number on the ticket. Decide which identifier the law actually requires on the ticket (FR: establishment SIRET on the ticket vs SIREN on the legal entity).
2. **Event version bump or rewrite-in-place?** Confirm whether any tenant is live by the time branch-tax-id ships. If yes, this needs SALE_RECEIPT vN + `FiscalEventPayloadRegistry` version handling + a migration story for already-signed v1 events. If no (still pre-prod), the v1 rewrite-in-place path (`SaleReceiptPayload.php:9-15`) is reusable but must be a deliberate, documented decision.
3. **Where does the device get the branch tax id?** `paymentStore.ts:543-550` reads `company.tax_id`. Branch tax id needs a branch/establishment field on the device's local company/branch model + SQLite mirror + sync. Which entity holds it — `companies`, a new `branches`/`establishments` table, or the existing terminal→branch mapping?
4. **Country scoping:** establishment-ID requirement is FR-specific (SIRET = SIREN + 5-digit establishment NIC). TN/SA/DE/IT may keep a single company-level matricule. The `tax_jurisdiction_country_code` field already gates per-country regex (`TAX_NUMBER_PATTERNS`, lines 150-156); the spec must define per-country whether tax_number is company- or branch-scoped.
5. **B2B / FacturX parity:** does the B2B invoice (`FacturXService.php`) need the branch establishment ID, or stay company-level? If they diverge, the POS ticket and the e-invoice would name different establishments for the same sale.
6. **NF525 JET archive header** (`Nf525XmlBuilder.php:76`) — single SIRET per export today; if a tenant has multiple branches, does the export need to be per-branch or carry a branch dimension?
7. **Already-signed events:** branch tax id cannot retroactively change a signed receipt's `seller.tax_number` (immutable canonical bytes). Confirm the cutover is forward-only and how historical receipts (carrying the old company tax id) are reconciled in reporting/audit.
