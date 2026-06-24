# Codex adversarial review — SaleReceiptV3 stamp-duty spec

**Reviewed:** `docs/superpowers/specs/2026-06-24-pos-sale-receipt-v3-stamp-duty.md`
**Date:** 2026-06-24 · **Reviewer:** Codex (adversarial, read-only, evidence-based)
**Verdict:** NEEDS-REVISION (1 BLOCKER, 6 HIGH, 5 MEDIUM)

---

## 1. Codebase claim verification

**(a) SALE_RECEIPT strict key-set + aggregate invariant keyed per TYPE, not VERSION: VERIFIED, and this is a blocker for V3 as written.**

`FiscalPayloadConstraintValidator::PAYLOAD_KEYS` is keyed by event type only, with one `SALE_RECEIPT` key list and no `stamp_duty_amount` today: `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:225`. `validatePayloadKeySet()` accepts type/payload/context, not `event_version`, and loads `self::PAYLOAD_KEYS[$type->value]`: `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:316`. Missing and extra keys are both fatal: `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:336`. `StrictCanonicalParser` extracts `event_version`, but calls key-set validation without it: `apps/api/app/Modules/Fiscal/Application/Services/StrictCanonicalParser.php:204`, `:226`. Per-event constraints are version-aware only after that: `:231`.

**(b) Current SALE_RECEIPT write version 2, accepted [1,2]: VERIFIED.**

The registry maps `SALE_RECEIPT` to `SaleReceiptPayload::class, 2`: `apps/api/app/Modules/Fiscal/Application/Services/FiscalEventPayloadRegistry.php:53`. Supported historical versions are `[1, 2]`: `:105`.

**(c) Aggregate identity is exactly `subtotal + vat_total == total + transaction_discount_amount`: VERIFIED.**

The validator checks the arithmetic directly: `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:779`. It repeats the aggregate consistency check as `subtotal + vat_total == total (+ transaction_discount_amount)`: `:866`. The POS device builder enforces the same invariant before signing: `apps/pos/src/lib/fiscal/payloads/SaleReceiptPayload.ts:134`, `:193`.

**(d) Device-PHP key-drift gate exists: VERIFIED.**

The POS test reads PHP `FiscalPayloadConstraintValidator.php`, extracts `SALE_RECEIPT`, and compares it against TS `SALE_RECEIPT_PAYLOAD_KEYS`: `apps/pos/src/lib/fiscal/__tests__/FiscalPayloadKeyDrift.test.ts:7`. It currently asserts length 28: `:12`.

**(e) The 12 listed touchpoints are mostly real, but the list is incomplete and one claimed path is not yet implemented.**

Real touchpoints verified: cart totals (`apps/pos/src/stores/cartStore.ts:601`), device payload builder (`apps/pos/src/lib/fiscal/payloads/SaleReceiptPayload.ts:106`), TS engine key-set (`apps/pos/src/lib/fiscal/FiscalEventEngine.ts:1032`), PHP DTO (`apps/api/app/Modules/Fiscal/Domain/DTOs/SaleReceiptPayload.php:45`), registry (`FiscalEventPayloadRegistry.php:53`), validator (`FiscalPayloadConstraintValidator.php:754`), parser (`StrictCanonicalParser.php:226`), projection (`apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:185`), Z/grand totals (`apps/api/app/Modules/POS/Application/Projections/ZReportProjection.php:120`, `apps/api/app/Modules/POS/Domain/Services/GrandtotalService.php:117`), GL (`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1366`), PDF (`apps/api/resources/views/pos/receipt.blade.php:419`), Rust thermal print (`apps/pos/src-tauri/src/printing/receipt_template.rs:28`).

"Device learns amount/applicability from synced tax config" is unimplemented. `syncService.ts` imports/upserts products, stock, variants, payment config, operators, terminal state, tables, menu, vouchers, and fiscal events, but no tax-configuration repository: `apps/pos/src/lib/sync/syncService.ts:1`. `TerminalStateResponse` carries hash/schema/shift fields, not tax config: `:117`. The server seed has a disabled Tunisian receipt stamp config: `apps/api/database/seeders/TunisiaTaxConfigurationSeeder.php:98`.

Missing touchpoints not listed in the spec:

- NF525 export DTO/XML has no stamp field: `apps/api/app/Shared/Contracts/Compliance/DTOs/Nf525ReceiptData.php:38`, `apps/api/app/Modules/Compliance/Services/Nf525/Nf525XmlBuilder.php:114`.
- NF525 re-verifies canonical bytes and maps authoritative payload totals, so it must understand V3: `apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php:1303`, `:620`.
- OutboxIngestor rehashes and reparses canonical bytes: `apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php:165`, `:379`.
- BestEffortPayloadParser and ParseFailureResolutionService use type-keyed payload keys without versioned key sets: `apps/api/app/Modules/Fiscal/Application/Services/BestEffortPayloadParser.php:77`, `apps/api/app/Modules/Fiscal/Application/Services/ParseFailureResolutionService.php:312`.
- POS SQLite offline receipt schema and repository have no stamp field: `apps/pos/src/lib/db/migrations.ts:99`, `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:29`.
- X_REPORT/Z_REPORT canonical payloads and authoring totals have no stamp bucket: `apps/api/app/Modules/Fiscal/Domain/DTOs/XReportPayload.php:9`, `apps/api/app/Modules/Fiscal/Domain/DTOs/ZReportPayload.php:9`, `apps/pos/src/lib/fiscal/zSessionAuthoring.ts:383`, `:480`.
- Web-admin receipt views show receipt total only, no stamp field/column: `apps/web/src/features/pos/components/ShiftReceiptsList.tsx:8`, `:57`.

---

## 2. Core decision challenge

The JCS reasoning is directionally correct: adding or omitting a key changes canonical bytes, and hashes are over exact bytes. The POS encoder canonicalizes sorted JSON and hashes that string: `apps/pos/src/lib/fiscal/FiscalEventCanonicalEncoder.ts:34`. PHP verifies SHA-256 over the submitted canonical bytes: `apps/api/app/Modules/Fiscal/Application/Services/HashChainIntegrityProvider.php:16`. But "always present `0.000`" does not remove the need for version-aware parsing. V2 receipts omit the field, and current key-set validation happens before version-aware constraints: `StrictCanonicalParser.php:226`.

V2 failure mode is real. The registry promises old versions remain parseable: `FiscalEventPayloadRegistry.php:99`. But `CanonicalPayloadReader::forSaleReceipt()` always hydrates one `SaleReceiptPayload` class: `apps/api/app/Modules/Fiscal/Application/Services/CanonicalPayloadReader.php:78`. If that DTO becomes V3-only with a required stamp field, historical V1/V2 reads break. If the global key list is updated to include the V3 key, V2 parse breaks even earlier via the strict extra-key check.

Currency scale is underspecified. The validator allows currency scales `{0, 2, 3}` and validates money strings against that scale: `FiscalPayloadConstraintValidator.php:699`, `:754`. The literal default `"0.000"` is only valid for scale 3. Either V3 must format the zero sentinel at `currency_scale`, or V3 must hard-constrain stamped receipts to TND/scale 3.

TTC/tax-inclusive pricing makes stamp placement load-bearing. POS line tax is extracted from an already-inclusive line total: `cartStore.ts:144`. Receipt subtotal is gross line total, discount reduces it, and total is `subtotal - discount`: `cartStore.ts:601`, `:642`. The canonical builder derives net subtotal as gross minus tax: `SaleReceiptPayload.ts:121`. A fixed stamp must not be included in VAT extraction, VAT breakdown, or discount base — and the spec does not explicitly isolate it.

The stamp belongs outside the current discount/VAT identity. The new invariant must be made explicit: `subtotal + vat_total + stamp_duty_amount == total + transaction_discount_amount`, with discount base excluding stamp. Current discount is capped against cart subtotal only: `cartStore.ts:624`. No version of that invariant is stated precisely in the spec.

---

## 3. Design gaps

**Refund/return receipts.** `invoice_type_code` already supports `REFUND` and `VOID`: `FiscalEventEngine.ts:406`. Projection maps REFUND/VOID with original receipt references into return rows: `PosCoreReceiptProjection.php:202`. The spec does not state whether the stamp is refundable, reversed, retained, or separately stamped on the return receipt.

**Voids.** Existing receipts have void handling and an immutability-safe void path: `apps/api/database/migrations/tenant/2026_01_08_190637_create_pos_receipts_table.php:67`, `:152`. The V3 design must declare whether voiding a stamped receipt reverses the stamp in reports, GL, and compliance export.

**GL liability split.** Regular SALE_RECEIPT payments go through `TreasuryReceiptBridge` into `createPOSPaymentEntry`: `apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php:414`. That service credits the full payment amount to product revenue: `GeneralLedgerService.php:1417`. There is no `StampDutyPayable` system purpose in the enum: `apps/api/app/Modules/Accounting/Domain/Enums/SystemAccountPurpose.php:24`. Adding stamp to `total` without a GL debit/credit split will overstate revenue and miss a government-liability credit.

**Multi-currency.** Payloads support arbitrary ISO currency plus scale `{0, 2, 3}`: `FiscalEventEngine.ts:400`. The legal seed is a fixed TND `0.100`: `TunisiaTaxConfigurationSeeder.php:98`. The spec has no rule for non-TND companies, cross-currency conversion, or scale-appropriate zero formatting.

**Device trust model.** The server verifies hash and parses bytes: `OutboxIngestor.php:165`. It does not currently recompute legal stamp applicability from tax config. If the device computes from synced config, stale or tampered config can produce internally consistent sealed bytes that are legally wrong — unless the server quarantines or cross-checks stamp applicability on ingest.

**Idempotency/replay.** Offline receipt creation returns the already-persisted row on idempotency-key retry without recomputing totals: `apps/pos/src/lib/offline/receiptService.ts:236`. That is probably correct, but the spec must explicitly state that stamp config and amount are captured at first seal and never recomputed on retry.

**Immutability trigger.** The original trigger explicitly guards `fiscal_hash`, `receipt_number`, `total`, `subtotal`, `tax_amount`, sequence, and timestamp during void updates: `2026_01_08_190637_create_pos_receipts_table.php:157`. A later migration notes new fields are outside the whitelist: `apps/api/database/migrations/tenant/2026_05_14_100005_add_canonical_bytes_and_fiscal_event_id_to_pos_receipts.php:41`. The V3 migration must explicitly add stamp column protection; the spec currently only asserts it will be guarded without providing a migration plan.

---

## 4. Severity table

| ID | Severity | File:Line | Description |
|---|---|---|---|
| F-01 | BLOCKER | `StrictCanonicalParser.php:226` | SALE_RECEIPT key-set validation is not version-aware; adding a V3 key globally will reject V3 as extra key (before version dispatch) or reject historical V1/V2 as missing key. No version-branching in key-set check exists today. |
| F-02 | HIGH | `CanonicalPayloadReader.php:78` | Canonical reader hydrates one `SaleReceiptPayload` class for all versions; making that DTO require stamp breaks V1/V2 reads across the entire database history. |
| F-03 | HIGH | `syncService.ts:117` | POS sync carries no tax/stamp config state; the "device computes applicability from synced config" touchpoint has no implementation in the codebase. |
| F-04 | HIGH | `GeneralLedgerService.php:1417` | POS payment GL credits full payment amount to revenue; stamp portion of total will overstate revenue without a separate StampDutyPayable system-account liability credit. No such account purpose exists. |
| F-05 | HIGH | `db/migrations.ts:99`, `offlineReceiptRepository.ts:29` | POS offline receipt SQLite schema and repository have no stamp field; offline reprints, local mirrors, and on-device sync state lose the stamp. |
| F-06 | HIGH | `Nf525ReceiptData.php:38`, `Nf525XmlBuilder.php:114` | NF525 compliance export DTO and XML builder have no stamp field; fiscal audits will hide stamp inside total or lose it entirely. |
| F-07 | HIGH | `XReportPayload.php:9`, `ZReportPayload.php:9`, `zSessionAuthoring.ts:383` | X/Z report payloads and totals have no stamp bucket; sealed session reports cannot reconcile stamp collections for tax authority submission. |
| F-08 | MEDIUM | `FiscalPayloadConstraintValidator.php:699` | Literal `"0.000"` sentinel is only valid for scale-3 currencies; scale-0 or scale-2 companies would produce a format-validation rejection on V3 receipts. |
| F-09 | MEDIUM | `cartStore.ts:144`, `SaleReceiptPayload.ts:121` | Tax-inclusive pricing means stamp must be isolated outside VAT extraction and discount base; the spec does not lock down the new aggregate identity or cart-total computation path. |
| F-10 | MEDIUM | `PosCoreReceiptProjection.php:202`, `FiscalEventEngine.ts:406` | Refund/void stamp behavior is undefined despite existing REFUND/VOID projection and event-type paths that will encounter V3 receipts. |
| F-11 | MEDIUM | `OutboxIngestor.php:165` | Server verifies hash and parse but not stamp applicability against active tax config; device-authored stamp amount is trusted without server-side cross-check. |
| F-12 | MEDIUM | `2026_01_08_...create_pos_receipts_table.php:157` | Immutability-trigger protection for the stamp column is asserted by spec but not grounded in a migration; the claim is not verifiable without an explicit new PG trigger migration. |

---

## 5. Verdict

**NEEDS-REVISION.**

The core idea — sealing stamp duty into a JCS-canonicalized, hash-chained V3 payload with an always-present `0.000` sentinel — is technically sound and the JCS reasoning is correct. However, the spec hits a structural BLOCKER: key-set validation today is type-scoped and version-unaware, so any change to the SALE_RECEIPT key list will either reject existing V1/V2 canonical bytes or reject incoming V3 bytes, depending on whether the key is added or gated — and the spec proposes no migration of that validation layer. Beyond the BLOCKER, seven HIGH-severity gaps (GL liability split, offline SQLite schema, NF525 export, X/Z report payloads, sync config propagation, V1/V2 reader compat, and NF525 revalidation) represent fiscal correctness regressions that would silently corrupt compliance data at launch. The spec needs a versioned key-set dispatch plan, explicit aggregate identity for stamp-in-total, and complete touchpoint coverage for reporting, export, refunds, and GL before implementation begins.
