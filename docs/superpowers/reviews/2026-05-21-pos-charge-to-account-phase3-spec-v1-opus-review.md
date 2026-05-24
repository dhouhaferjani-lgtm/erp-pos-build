# Phase 3 Stage A Spec v1 Opus-Equivalent Review

**Reviewed artifact:** `docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md`

**Codex self-review:** `docs/superpowers/reviews/2026-05-21-pos-charge-to-account-phase3-spec-v1-codex-review.md`

**Verdict:** REQUEST-CHANGES

## Findings

### 1. `ACCOUNT_CHARGE` payload drops locked SALE_RECEIPT sale-evidence fields needed for multi-country parity and future projections

**Severity:** Request changes

The spec says `ACCOUNT_CHARGE` follows the `SALE_RECEIPT` / `ACCOUNT_PAYMENT` compliance-rich multi-country precedent (`docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:131-133`) and that the base payload must include sale evidence, buyer snapshot, totals, VAT, and future DE/IT/ZATCA projection inputs (`docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:244`). The nested payload shape does not actually preserve that contract:

- `line_items` omits `product_id` and `non_collected_subtype` (`docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:179-192`). The locked `SALE_RECEIPT` canonical line DTO requires `product_id` and nullable `non_collected_subtype` (`apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/LineItemDTO.php:25-39`, `apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/LineItemDTO.php:44-60`). `product_id` is also the audit-stable key used by the POS projection for product FK resolution (`apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:539-579`). Dropping it makes charge projections weaker than sale receipt projections and risks cross-tenant/product lookup hacks later.
- `customer` has `tax_number` but no Italian `codice_fiscale` / equivalent buyer-fiscal-code field (`docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:167-177`), while the same spec claims shared buyer-codice-fiscale prefixes apply (`docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:265-273`). The landed SALE_RECEIPT buyer DTO and synthesis explicitly treat `buyer.codice_fiscale` as separate from `tax_number` (`apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/BuyerDTO.php:20-31`, `apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/BuyerDTO.php:54-61`; `docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md:165-178`).
- The Italy analysis defers Natura/lottery/codice-fiscale specifics into `regime_extensions` (`docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:299-302`), but the multi-country research called `non_collected_subtype`, lottery code, and buyer codice fiscale out as concrete fields missing from thinner candidates (`docs/superpowers/research/2026-05-20-multicountry-fiscal-research.md:144-147`). SALE_RECEIPT resolved that by carrying these as first-class canonical fields where applicable, not by hiding them in a free-form extension block.

Suggested fix: make `ACCOUNT_CHARGE` line items intentionally mirror the SALE_RECEIPT line evidence field set unless there is an explicit, cited reason not to. At minimum add `product_id` and `non_collected_subtype` to the nested line contract, and add a first-class customer/buyer `codice_fiscale` field or explicitly define the renamed path and forensic prefix. Then add those keys to the test matrix for PHP/TS parity, exact key-set validation, positive fixtures, and missing/extra-key rejects.

### 2. AR GL posting is still under-specified on the VAT/revenue split, leaving room to recreate the known cash-path defect with a new method name

**Severity:** Request changes

The spec correctly rejects `ReceiptPaymentService` / `createPOSPaymentEntry()` for charge-to-account and requires a new AR path (`docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:46`, `docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:359-368`). The problem is that the replacement path does not lock the journal line shape. It only says the command includes charge amount, VAT/totals, and line/VAT summary (`docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:366`) and that missing AR/revenue/VAT accounts fail loud (`docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:367`).

That is not enough for the exact defect the codebase audit warned about. `createPOSPaymentEntry()` is wrong for this flow because it credits the entire payment amount to `ProductRevenue`, has no AR line, no VAT line, and no `partner_id` (`docs/superpowers/research/2026-05-14-pos-fiscal-codebase-reality.md:58-62`; current implementation at `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1190-1247`). A new `createPOSChargeEntry()` that debits AR but still credits total to revenue would be almost the same accounting defect.

Suggested fix: make the AR posting invariant explicit in the spec and tests:

- `Dr AccountsReceivable` for `totals.amount_charged_to_account` / `totals.total`, with `partner_id` set and tenant/company-scoped customer resolution.
- `Cr ProductRevenue` for net revenue, normally `totals.subtotal` adjusted per the locked AutoERP discount convention.
- `Cr VAT liability/output tax` for `totals.vat_total`, partitioned from `vat_breakdown` where required.
- Journal must balance at `currency_scale`, be idempotent by `fiscal_event_id`, and never create a Treasury `Payment` row or payment line for the charge itself.
- Focused tests should assert the exact debit/credit amounts, partner attribution, VAT line presence, and no `createPOSPaymentEntry()` call.

## Checks That Passed

- **D16 bounded-module boundary:** The spec keeps Fiscal/POS-core free of operational Treasury/Accounting/B2B/Partner/Customer dependencies and routes operational effects through module-gated projectors (`docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:29`, `docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:344-383`).
- **D8 owner question:** The spec surfaces the owner question and recommends web-B2B aggregates; it does not quietly make POS a Tax Invoice authoring surface (`docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:69-82`, `docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:289-292`).
- **Settlement vs payment-line split:** `ACCOUNT_CHARGE` forbids a `payments` block and preserves later money received as `ACCOUNT_PAYMENT` (`docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:165`, `docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:248-253`).
- **Customer category drift:** Exact `business` is B2B; `individual`, `retail`, `para-pharmacy`, null, and every other value remain non-B2B and are not destroyed during sync (`docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:88-94`, `docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:167-177`, `docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:263`).
- **Cross-tenant FK safety:** Future bridge lookups are specified as tenant/company scoped, and missing/cross-company dependencies fail loud (`docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:96-101`, `docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:366-368`, `docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:414-416`).
- **Fail-loud behavior:** The spec rejects silent downgrade in device sealing and projection/reconciliation (`docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:116-129`, `docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:338-342`, `docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:401-402`).
- **Standing patterns:** Dead-path rebuild, discriminated-union matrix, contract drift, per-method skips, skip citation accuracy, constructor injection only, and R2 review requirements are carried forward (`docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:404-445`).

## Verification Commands Run

- Read reviewed spec, Codex self-review, and authoritative handoff/research files with `sed`/`nl`.
- `rg -n "ACCOUNT_CHARGE|D16|D8|createPOSPaymentEntry|Treasury|B2B|Tax Invoice|AR" ...` across the handoff, roadmap, SoT, and codebase reality audit.
- `rg -n "product_id|non_collected_subtype|codice_fiscale|buyer|vat_total|line_items" ...` across the spec, synthesis v5, multi-country research, and current DTO/projection code.
- `rg -n "app\\(|App::make|resolve\\(|markTestSkipped|skip" docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md docs/superpowers/reviews/2026-05-21-pos-charge-to-account-phase3-spec-v1-codex-review.md`.
- Inspected current projector/module-boundary implementations: `FiscalEventProjector`, `TreasuryReceiptBridge`, `TreasuryAccountPaymentBridge`, and `TreasuryServiceProvider`.
