# Adversarial Review — Domestic Procurement-to-Pay GR-IR Spec
Date: 2026-06-24 | Reviewer: Codex (cross-model) | Adjudication: Opus (verified against code)

Spec reviewed: `docs/superpowers/specs/2026-06-24-domestic-procurement-to-pay-gr-ir-design.md`

## Verdict: NEEDS-REVISION (all findings verified valid; spec revised — see adjudication)

## Findings (Codex) + adjudication (verified against dev HEAD)

### B-1 (BLOCKER→dependency): R-1 not satisfied — `PurchaseOrderConfirmedListener` still posts AP at PO confirmation.
**Verified:** correct; R-1 (revert H-3.2/H-3.1) is unexecuted. **Adjudication:** NOT a spec defect — the spec already declares R-1 a hard prerequisite (§8). Reinforced: Phase 1 must not begin until R-1 lands. No spec change beyond emphasis.

### B-2 (BLOCKER→dependency): R-2 not satisfied — `postEntry()` omits `chain_sequence`; `verifyChain()` requires it.
**Verified:** correct; R-2 unexecuted. **Adjudication:** already a stated prerequisite (§8). Phase 1 GL posts will fail `verifyChain` until R-2 lands. Reinforced. No spec change beyond emphasis.

### H-3 (HIGH): VAT account mismatch — spec hardcodes `4366`; seeded Tunisia chart uses `4456`.
**Verified:** CONFIRMED — `TunisiaChartOfAccountsSeeder.php:157` seeds `4456 "TVA déductible"` (France + Generic seeders also `4456`). My research cited the strict SCE number `4366/43666`; the codebase uses the French-PCG `4456`. **Adjudication:** the spec must NOT hardcode an account number. Resolve all GL legs through **`SystemAccountPurpose`** enums (the codebase pattern). `SystemAccountPurpose::VatDeductible` already exists (`SystemAccountPurpose.php:28`) → maps to the seeded `4456`. Spec revised to reference purposes, not numbers. The SCE-`4366`-vs-seeded-`4456` numbering question is logged as an accountant item (§13), separate from this build.

### H-4 (HIGH): GR-IR account purpose missing — `408` exists but has no `SystemAccountPurpose`.
**Verified:** CONFIRMED — `408 "Fournisseurs - Factures non parvenues"` is seeded (`TunisiaChartOfAccountsSeeder.php:140`) but `SystemAccountPurpose` has no case for it (it has the sales-side mirror `UninvoicedRevenue`=418 at `:22`, not the purchase-side). **Adjudication:** spec revised to ADD `SystemAccountPurpose::GoodsReceivedNotInvoiced` (→408) and a `PurchaseStampDuty` non-recoverable purpose, and to seed both mappings across charts. The GL listener resolves 408 via the new purpose, mirroring how 418 works for sales.

### H-5 (HIGH): `AttachmentService` hardcodes the `local` disk.
**Verified:** CONFIRMED — `AttachmentService::getStorageDisk()` `return 'local';` (`:197`, comment "Could be extended to support S3 per tenant"); `upload()` takes no disk param. The `s3` disk IS configured (`config/filesystems.php` with `AWS_ENDPOINT`). **Adjudication (SUPERSEDED by owner guidance 2026-06-24):** Codex correctly identified that the legacy `AttachmentService` hardcodes `local`. However, the owner directed that we **must NOT extend the legacy `DocumentAttachment`/`AttachmentService` path at all** (it is being retired — it would become a third consumer of the system under unification). The spec now targets the **unified `MediaAsset` system** (`Catalog/Domain/Media`) with a new `MediaOwnerType::SupplierInvoice`, MinIO-backed, behind a thin port, coordinated with the media-unification session — see spec §5. So H-5 is resolved by retargeting, not by patching the legacy disk.

### M-6 (MEDIUM): Goods-receipt linkage is positional — no durable receipt-operation record; multi-receipt→one-invoice matching needs a join the spec didn't define.
**Verified:** `GoodsReceiptService` tracks cumulative `quantity_received` per PO line (durable on the line) and records WAC purchase movements; there is no first-class "goods receipt operation" document. **Adjudication:** for Phase 1 domestic 3-way matching, matching the invoice line against the PO line's **cumulative `quantity_received`** (received-so-far) is sufficient and is how the spec will scope it. Precise per-receipt linkage (which physical receipt an invoice covers) is a refinement — spec revised to (a) match on cumulative received qty in Phase 1 and (b) flag a durable goods-receipt record (or stock-movement linkage) as the extension point if per-receipt audit linkage is later required.

## Code cross-check summary
| Claim in spec | Result | Evidence |
|---|---|---|
| 408 GR-IR account exists | CONFIRMED | `TunisiaChartOfAccountsSeeder.php:140` |
| Deductible VAT = 4366 | WRONG (seeded 4456) | `TunisiaChartOfAccountsSeeder.php:157` |
| `SystemAccountPurpose` for 408 | MISSING | `SystemAccountPurpose.php` (no case) |
| `VatDeductible`/`SupplierPayable`/`Inventory` purposes exist | CONFIRMED | `SystemAccountPurpose.php:28,25,21` |
| AttachmentService can target S3 disk | WRONG (hardcoded local) | `AttachmentService.php:197` |
| GoodsReceiptService posts no GL, partial-aware | CONFIRMED | (prior investigation) |
| Depends on R-1 + R-2 | CONFIRMED unsatisfied | listener still fires; postEntry no chain_sequence |
