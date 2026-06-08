# Branch Tax-ID P0 Spec — Codex Adversarial Review

Reviewed spec: `docs/superpowers/specs/2026-06-04-branch-tax-id-design.md`

Posture: adversarial. I checked the spec against research docs 01-05 and the current code in `/Users/houssamr/Projects/syneriva/apps/erp.branch-tax-id`.

## Findings

### BLOCKER 1 — Entry validation plan is incompatible with the fiscal/legal tax-number formats it claims to support

The spec says to wire `Partner\Domain\Services\TaxIdValidationService` into location create/update and validate `tax_id` **and `vat_number`** for the location country when present (`docs/superpowers/specs/2026-06-04-branch-tax-id-design.md:83-89`). That service is not equivalent to the fiscal validator and is not a VAT-number validator:

- `TaxIdValidationService` FR accepts only exactly 14 digits plus Luhn (`apps/api/app/Modules/Partner/Domain/Services/TaxIdValidationService.php:26-44`). The fiscal payload validator accepts FR 9 or 14 digits without Luhn (`apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:150-156`, `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:2273-2302`), and the spec itself says FR 9-digit SIREN is a non-blocking policy warning (`docs/superpowers/specs/2026-06-04-branch-tax-id-design.md:87`).
- `TaxIdValidationService` TN accepts `^\d{7}[A-Z][A-Z0-9]{3}$`, e.g. `1234567A000` (`apps/api/app/Modules/Partner/Domain/Services/TaxIdValidationService.php:67-91`, `apps/api/tests/Unit/Partner/TaxIdValidationServiceTest.php:71-85`). The locked fiscal/legal compact matricule is 7-8 digits + **2 letters** + 3 digits, e.g. `1234567AM000` (`docs/superpowers/research/2026-06-04-branch-tax-id-03-compliance-requirements.md:85-99`; fiscal code at `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:150-156`; POS mirror at `apps/pos/src/lib/fiscal/FiscalEventEngine.ts:1290-1296`). The current fiscal fixtures and tests use `1234567AM000` repeatedly, including `apps/api/tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php:1810-1815`.
- The same service has no MA/DZ validation path: unknown countries return valid (`apps/api/app/Modules/Partner/Domain/Services/TaxIdValidationService.php:17-23`), contradicting the spec's country-mode claim that FR/TN/MA are structural and DZ is separate-linked/applicable (`docs/superpowers/specs/2026-06-04-branch-tax-id-design.md:86`).
- Applying this service to `vat_number` is wrong for FacturX parity. FacturX emits `vat_number` as `VA` and `tax_id` as `FC` (`apps/api/app/Modules/Document/Application/Services/FacturXService.php:123-129`). A French VAT number such as `FR...` is not a SIRET and would be rejected by the FR SIRET validator.

Impact: Phase 1 can reject values that Phase 2/fiscal payloads must author, especially Tunisian branch matricules, and can reject valid branch VAT IDs. The spec needs a dedicated tax-identity validation map per field (`tax_id`, `vat_number`, `legal_identifiers`) and must reconcile it with the fiscal validator before implementation.

### BLOCKER 2 — "Never required" is non-compliant for FR/TN multi-branch receipts

The spec makes all branch identity fields nullable and says absent values always inherit company identity, never required (`docs/superpowers/specs/2026-06-04-branch-tax-id-design.md:83-87`). That directly conflicts with the compliance research for the core structural countries:

- France: the issuing branch's SIRET/NIC must be authoritative for `seller.tax_number` on multi-branch receipts (`docs/superpowers/research/2026-06-04-branch-tax-id-03-compliance-requirements.md:69-75`, `docs/superpowers/research/2026-06-04-branch-tax-id-03-compliance-requirements.md:212-215`).
- Tunisia: the issuing branch's matricule including the correct 3-digit establishment suffix is required on receipts/invoices (`docs/superpowers/research/2026-06-04-branch-tax-id-03-compliance-requirements.md:91-99`, `docs/superpowers/research/2026-06-04-branch-tax-id-03-compliance-requirements.md:216-219`).

Current POS authoring uses the company value for `seller.tax_number` (`apps/pos/src/stores/paymentStore.ts:543-550`, `apps/pos/src/stores/paymentStore.ts:654-661`), and `seller.tax_number` is the exact slot the research says must become branch-authoritative. If a FR/TN tenant has multiple sellable branches and leaves a branch override null, inheriting the company/HQ value can keep producing non-compliant receipts while all validation passes.

Impact: the P0 spec can be "implemented" while still failing the stated legal reason for the work. At minimum the country-mode config needs a blocking readiness rule for sellable FR/TN branches (or an explicit owner-approved compliance waiver), not only validate-when-present.

### MAJOR 1 — The fiscal-payload conclusion is unsafe as written: no schema bump is plausible, but "no hash-chain risk" is false

The spec says the branch source change has "no `event_version` bump, no hash-chain risk" and fixtures stay valid because only the data source changes (`docs/superpowers/specs/2026-06-04-branch-tax-id-design.md:91-97`). The code proves a narrower conclusion:

- `seller` is a required `SALE_RECEIPT` payload key (`apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:222-252`) and its exact subkeys include `tax_number` (`apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:1466-1497`).
- The POS fiscal engine wraps the full payload object, including `payload.seller`, into the canonical event object and hashes it (`apps/pos/src/lib/fiscal/FiscalEventEngine.ts:608-629`).
- The canonical encoder recursively serializes whole objects with sorted keys and no field exclusion (`apps/pos/src/lib/fiscal/canonicalCore.ts:39-83`; `apps/pos/src/lib/fiscal/FiscalEventCanonicalEncoder.ts:34-41`).
- The server re-hashes `canonical_bytes` and stores those bytes verbatim (`apps/api/app/Modules/Fiscal/Application/Services/HashChainIntegrityProvider.php:16-23`; `apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php:730-735`, `apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php:815-817`, `apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php:1171-1174`, `apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php:1209-1211`).

Disproof of the broad claim: changing `seller.tax_number` from company to branch changes the canonical bytes and the event hash for every new affected receipt. Existing fixtures remain valid only because they are fixed payload/hash fixtures, not source-path fixtures; if a golden authoring fixture is changed to prove branch-over-company sourcing, its bytes/hash must change. The v3 fixture README also treats golden hash drift as a deliberate update path (`apps/api/tests/Fixtures/Fiscal/v3-golden-hashes/README.md:8-30`).

Nuance: I did **not** find a mandatory schema reason for a version bump if the key set and validator remain unchanged. Keeping event version 1 can be defensible as a forward-only semantic cutover, but the spec must say that explicitly and add cross-language source-path tests. Calling this "no hash-chain risk" is inaccurate.

### MAJOR 2 — The "five server output sites" overclaim: NF525, TEJ, and certificate outputs do not have a single correct location to resolve today

The spec requires a single resolver at five server sites (`docs/superpowers/specs/2026-06-04-branch-tax-id-design.md:69-79`), but several current call sites are not location-scoped:

- NF525 JET: `buildExportSnapshot()` loads one `Company`, then all company terminals, then builds one company header before mapping terminals (`apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php:86-99`). `buildCompanyHeader()` accepts only `Company` and currently reads non-existent `siret`/`address` magic attributes (`apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php:524-541`). There is no single `location_id` for a multi-branch company-wide export.
- TEJ batch: `generateBatchXML()` uses the first certificate as the declarant source for the whole batch (`apps/api/app/Modules/Taxation/Application/Services/TEJExportService.php:47-59`), and the controller batch query filters by company/year/direction/status only (`apps/api/app/Modules/Taxation/Presentation/Controllers/WithholdingCertificateController.php:288-312`). The `withholding_certificates` table has no `location_id` (`apps/api/database/migrations/tenant/2026_01_08_172147_create_withholding_certificates_table.php:14-82`), and the model has only optional `document_id`/`payment_id` relationships (`apps/api/app/Modules/Taxation/Domain/Entities/WithholdingCertificate.php:26-59`, `apps/api/app/Modules/Taxation/Domain/Entities/WithholdingCertificate.php:64-89`).
- Certificate PDF: `getCertificateData()` reads `$company->tax_id` from the certificate's company (`apps/api/app/Modules/Taxation/Application/Services/CertificatePDFService.php:59-76`). Again, no certificate-level location exists.

Receipt PDF and FacturX have usable location paths: receipt PDF already passes `$receipt->location` to the blade (`apps/api/app/Modules/POS/Application/Services/ReceiptPdfService.php:46-65`, `apps/api/app/Modules/POS/Application/Services/ReceiptPdfService.php:140-144`), and `Document` has `location_id` plus a `location()` relation (`apps/api/app/Modules/Document/Domain/Document.php:112-116`, `apps/api/app/Modules/Document/Domain/Document.php:199-205`). But FacturX currently loads only company/partner/lines and passes a `Company` into `setSellerInformation()` (`apps/api/app/Modules/Document/Application/Services/FacturXService.php:69-83`, `apps/api/app/Modules/Document/Application/Services/FacturXService.php:110-145`), so the spec must explicitly add `location` loading and decide fallback for null `documents.location_id`.

Impact: the resolver abstraction is fine, but these outputs need scope design first: split exports per location, add a location dimension to certificates/TEJ filters, or explicitly keep them company-level. "Resolve from the correct location_id" is not implementable for three cited sites as written.

### MAJOR 3 — The ACCOUNT_CHARGE device path is misidentified; the cited line is a golden fixture, not a live source binding

The spec says Phase 2 should flip `AccountChargePayload.ts:256` to prefer `terminal.location?.tax_id` (`docs/superpowers/specs/2026-06-04-branch-tax-id-design.md:104-109`). That line is inside `goldenAccountChargePayload()`, a static fixture (`apps/pos/src/lib/fiscal/payloads/AccountChargePayload.ts:184-265`), not the account-charge authoring source.

The live account-charge builder accepts `input.seller`, normalizes it, and embeds it into the canonical payload (`apps/pos/src/lib/accountCharge/accountChargeService.ts:66-77`, `apps/pos/src/lib/accountCharge/accountChargeService.ts:146-160`, `apps/pos/src/lib/accountCharge/accountChargeService.ts:318-333`, `apps/pos/src/lib/accountCharge/accountChargeService.ts:550-569`). I did not find a production caller that supplies account-charge seller data; `rg` only found tests plus the builder itself. By contrast, sale receipts and account payments are sourced in `paymentStore.ts` (`apps/pos/src/stores/paymentStore.ts:543-550`, `apps/pos/src/stores/paymentStore.ts:654-661`).

Impact: the spec can leave ACCOUNT_CHARGE unpatched while believing it was covered. It needs to identify the actual account-charge UI/store caller or explicitly state that account-charge authoring is not live in this P0 path.

### MINOR 1 — The terminal-location sync claim is mostly correct, but the spec should name all refresh paths, not only activation

`TerminalResource` already includes `location` when the relation is loaded, with `{id,name,code}` only (`apps/api/app/Modules/POS/Presentation/Resources/TerminalResource.php:27-34`). Activation loads it (`apps/api/app/Modules/POS/Presentation/Controllers/TerminalController.php:247-249`), and the POS store persists/refetches the terminal object in localStorage and in state (`apps/pos/src/stores/terminalStore.ts:390-451`, `apps/pos/src/stores/terminalStore.ts:527-570`).

However, not every controller return path is activation; show, list, claim, by-device, toggle-training, etc. also shape the terminal object (`apps/api/app/Modules/POS/Presentation/Controllers/TerminalController.php:50-82`, `apps/api/app/Modules/POS/Presentation/Controllers/TerminalController.php:300-308`, `apps/api/app/Modules/POS/Presentation/Controllers/TerminalController.php:470-481`, `apps/api/app/Modules/POS/Presentation/Controllers/TerminalController.php:522-523`). Phase 2 should update the resource shape and tests for every terminal endpoint the store can consume, not just `TerminalController.php:247`.

### NIT 1 — Receipt print responsibility is misstated as controller-only

The spec says the receipt blade change is "via `ReceiptController`" (`docs/superpowers/specs/2026-06-04-branch-tax-id-design.md:73`). In the current PDF path, `ReceiptPdfService` loads `company` and `location`, prepares view data, and renders `pos.receipt` (`apps/api/app/Modules/POS/Application/Services/ReceiptPdfService.php:46-67`, `apps/api/app/Modules/POS/Application/Services/ReceiptPdfService.php:104-170`). The blade currently prints `$company->tax_id` (`apps/api/resources/views/pos/receipt.blade.php:320-330`). The implementation plan should inject/use the resolver in `ReceiptPdfService` or pass an already-resolved value into the view; putting the change only in `ReceiptController` misses the service boundary.

## Targeted Claim Checks

1. **No version bump / no golden regen.** Schema/key-set bump is not forced by the current code, but the value is hash-covered. The safe statement is: "no exact-key schema change; forward-only v1 semantic cutover, with source-path tests; existing fixed payload fixtures stay valid unless their payload values are intentionally changed."
2. **Single PHP resolver + 5 server output sites.** Resolver is a good local abstraction for receipt and FacturX. NF525/TEJ/certificate need output-scope decisions before a resolver can choose a branch.
3. **Device path.** Terminal location is already synced and persisted, but only as `{id,name,code}`. `paymentStore.ts` has the live sale/account-payment seller source. `AccountChargePayload.ts:256` is not a live source.
4. **Validate-when-present / never required.** Format validation is currently the wrong validator, and "never required" conflicts with FR/TN multi-branch must-haves.

## Verdict

NEEDS-REWORK

Finding counts: BLOCKER 2, MAJOR 3, MINOR 1, NIT 1.
