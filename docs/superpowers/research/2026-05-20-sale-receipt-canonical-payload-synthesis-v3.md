# SALE_RECEIPT Canonical Payload — Synthesis v3

**Date:** 2026-05-20
**Author:** Controller (session continuation)
**Status:** DRAFT — pending Codex round-3 → owner sign-off → plan amendment.
**Supersedes:** v1 (Codex BLOCK), v2 (Codex REQUEST-CHANGES) at `docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis{,-v2}.md`.

---

## 1. Owner decisions landing in v3 (D1–D9)

D1. **10-field PHP shape is incomplete for NF525 + multi-country.** Adopt expanded canonical contract.
D2. **No dual chain** — legacy v3 fiscal chain code DELETED in Pass 2 (device-side authoring path).
D3. **Specs commit to all 4 regimes; impl NF525 + Tunisia immediately; ZATCA + Germany + Italy DEFERRED.**
D4. **v1 rewrite in place** — no event_version bump; existing fixtures regenerated atomically (no production tenants).
D5. **Server-side mirror columns** stay through Pass 2; **NEW deferred task** "Phase-2 mirror-column audit + drop" added to roadmap so we don't ship dead code at go-live.
D6. **DROP feature flag.** Pass 2A + 2B = two clean commits on the dev branch. No production-isolation gate needed (no production deployment until end-of-Phase-1 merge to main).
D7. **B2C Simplified only via Tauri POS.** The Tauri offline-first POS authors B2C receipts only. The existing web B2B flow is UNTOUCHED. Drop `invoice_subtype_code` from canonical (ZATCA-only switch; defer).
D8. **B2B / ZATCA Tax Invoice path:** owner TBD later — either (i) Tauri authors Standard Invoice with sequential cbc:ID, OR (ii) web B2B flow aggregates POS receipts into proper invoices. Synthesis MUST NOT lock either model now. Implication: canonical payload has no B2B-specific fields.
D9. **Tunisia priority + immediate target.** Tunisia regime is lighter than NF525; NF525-certifiable canonical satisfies Tunisia by superset. "We want NF525-certifiable infrastructure even though it's overkill for Tunisia" → the immediate-target canonical IS the NF525 shape.

Deferred-but-future-proofed (canonical carries optional/nullable fields so addition is incremental):
- DE DSFinV-K specifics: `gtin`, `consumption_mode`, `table_id`, `payments[].foreign_currency_*`
- IT RT specifics: `lottery_code`, `non_collected_subtype`, `buyer.codice_fiscale`
- KSA ZATCA tax categorization: `tax_category_code` on line_items + vat_breakdown

ZATCA-only switches (NOT carried; add when ZATCA implementation happens):
- `invoice_subtype_code` (Standard vs Simplified) — DROP. The B2B path is undecided per D8; emitting it now is premature commitment.

---

## 2. Verdict on payload candidates (recap)

- **Candidate A (PHP 10 fields)** — inadequate per committed external research §1-§4 at `docs/superpowers/research/2026-05-20-multicountry-fiscal-research.md`.
- **Candidate B (plan A4, 25 fields)** — closer but missing seller block, cashier_name, gtin, tax_category_code, invoice_type_code, original_receipt_reference, non_collected_subtype, lottery_code, payments[].foreign_currency_*.
- **Candidate C-v3** — the 27-key cross-regime superset per §3 below. Drops the v2 `invoice_subtype_code` per D7+D8.

---

## 3. Candidate C-v3 — canonical payload (sorted lex per Codex P3)

```ts
export interface SaleReceiptPayload {
  business_date: string;                         // YYYY-MM-DD
  buyer: BuyerBlock | null;                      // sale-time snapshot — see §5
  cashier_id: string;                            // UUID
  cashier_name: string;                          // DSFinV-K BEDIENER_NAME (future) + NF525 v2.1 operator code
  consumption_mode: 'dine_in' | 'takeaway' | null;
  currency_code: string;                         // ISO 4217 ("EUR" FR, "TND" TN, "SAR" SA, "USD", "GBP")
  currency_scale: number;                        // 0|2|3
  event_time_device: string;                     // ISO 8601 with ms + tz offset
  invoice_type_code: 'SALE' | 'REFUND' | 'VOID' | 'TRAINING';
  line_items: Array<{
    gtin: string | null;                         // DSFinV-K Bonpos GTIN (future)
    line_discount_amount: string;                // bcformat per currency_scale
    line_discount_reason: string | null;
    line_subtotal: string;                       // net (pre-VAT)
    line_vat: string;
    name: string;
    non_collected_subtype: 'servizi' | 'beni' | 'omaggio' | 'successiva' | null;  // IT (future)
    product_id: string;
    quantity: string;                            // bcformat per quantity_scale
    sku: string;
    tax_category_code: string;                   // unified KSA BT-151 / IT Natura axis; "" = standard taxable
    unit_price: string;
    vat_rate: string;                            // bcformat % ("20.00", "5.50", "0.00")
  }>;
  lottery_code: string | null;                   // IT codice lotteria (future)
  notes: string | null;
  original_receipt_reference: {                  // refund / void — null otherwise
    fiscal_event_id: string;                     // UUID of original SALE_RECEIPT fiscal event
    original_business_date: string;
    original_receipt_uuid: string;
    refund_reason: string;
  } | null;
  payments: Array<{
    amount: string;                              // bcformat in currency_code
    foreign_currency_amount: string | null;      // DSFinV-K ZAHLWAEH_BETRAG (future)
    foreign_currency_code: string | null;        // DSFinV-K ZAHLWAEH_CODE
    instrument_serial: string | null;            // voucher serial, card last-4, etc.
    instrument_type: string | null;
    method_code: string;                         // UN/ECE 4461 mapped (KSA cac:PaymentMeansCode future)
  }>;
  receipt_uuid: string;                          // device-authored UUID (single field — Codex P1.7)
  seller: {                                      // REQUIRED — NF525 SIRET + TN matricule fiscal
    address: { city: string; country_code: string; postal_code: string; street: string };
    name: string;
    tax_jurisdiction_country_code: string;       // ISO 3166-1 alpha-2 ("FR","TN","SA","DE","IT")
    tax_number: string;                          // FR SIRET, TN matricule fiscal, KSA 15-digit, IT P.IVA, DE USt-ID
  };
  shift_id: string;                              // UUID
  subtotal: string;                              // bcformat — net of VAT
  table_id: string | null;                       // DSFinV-K ABRECHNUNGSKREIS (future)
  terminal_id: string;                           // UUID
  total: string;                                 // bcformat — gross (incl. VAT)
  training_flag: boolean;                        // NF525 "NON VALABLE POUR ENCAISSEMENT"
  transaction_discount_amount: string;           // bcformat — "0" when none
  transaction_discount_reason: string | null;
  vat_breakdown: Array<{
    gross_amount: string;
    net_amount: string;
    rate: string;                                // bcformat %
    tax_category_code: string;                   // UNIFIED axis — see §4
    vat_amount: string;
  }>;
  vat_total: string;
  vouchers_redeemed: Array<{
    redeemed_amount: string;
    voucher_code: string;
  }>;
}

interface BuyerBlock {
  address: { city: string; country_code: string; postal_code: string; street: string } | null;
  codice_fiscale: string | null;                 // IT (future)
  contact_id: string | null;                     // POS-local mirror only — sale-time snapshot
  customer_id: string | null;                    // POS-local mirror only — sale-time snapshot
  name: string | null;
  tax_number: string | null;                     // when buyer voluntarily provides; B2C optional
}
```

**PAYLOAD_KEYS list (top-level, sorted lex):**
```
[
  "business_date", "buyer", "cashier_id", "cashier_name", "consumption_mode",
  "currency_code", "currency_scale", "event_time_device", "invoice_type_code",
  "line_items", "lottery_code", "notes", "original_receipt_reference",
  "payments", "receipt_uuid", "seller", "shift_id", "subtotal", "table_id",
  "terminal_id", "total", "training_flag", "transaction_discount_amount",
  "transaction_discount_reason", "vat_breakdown", "vat_total", "vouchers_redeemed"
]
```

**27 top-level keys** (v3 = v2 minus `invoice_subtype_code` per D7+D8).

**EXCLUDED from payload (server-derived or envelope-level):**
- `receipt_number` (server-projected at Task 21 — business display; NOT used as ZATCA cbc:ID per §4).
- `fiscal_event_id` (envelope identity; only referenced in `original_receipt_reference`).
- `fiscal_hash` / `previous_hash` / `sequence_number` (envelope-level — see §4).
- VAT-breakdown / payment-methods hashes (Task 21 R2 mirror columns; derived data).

---

## 4. Envelope vs payload mapping — full ZATCA UBL (Codex round-1 B2 + round-2 closure)

Forward-looking — when ZATCA implementation gets approved (D8 deferred), the export adapter projects from `(envelope + payload)` to UBL 2.1 XML per this table. No new payload fields needed beyond what Candidate C-v3 carries.

| ZATCA UBL XPath | Source | Layer |
|---|---|---|
| `/Invoice/cbc:ID` (Simplified) | `payload.receipt_uuid` | Payload (device-authored) |
| `/Invoice/cbc:UUID` | `payload.receipt_uuid` (same field — Codex P1.7) | Payload |
| `/Invoice/cbc:IssueDate` + `cbc:IssueTime` | `payload.event_time_device` split at export | Payload |
| `/Invoice/cbc:InvoiceTypeCode` | derived from `payload.invoice_type_code` (388 SALE / 381 REFUND / 0200 marker for Simplified) | Payload |
| `/Invoice/cbc:DocumentCurrencyCode` | `payload.currency_code` | Payload |
| `/Invoice/cac:AdditionalDocumentReference[ID='PIH']/cac:Attachment/cbc:EmbeddedDocumentBinaryObject` | `fiscal_events.previous_hash` (base64-encoded at export) | Envelope |
| `/Invoice/cac:AdditionalDocumentReference[ID='ICV']/cbc:UUID` | `fiscal_events.sequence_number` | Envelope |
| `/Invoice/cac:AccountingSupplierParty/cac:Party/cac:PartyLegalEntity/cbc:RegistrationName` | `payload.seller.name` | Payload |
| `/Invoice/cac:AccountingSupplierParty/cac:Party/cac:PartyTaxScheme/cbc:CompanyID` | `payload.seller.tax_number` | Payload |
| `/Invoice/cac:AccountingSupplierParty/cac:Party/cac:PostalAddress/{cbc:StreetName, cbc:CityName, cbc:PostalZone, cac:Country/cbc:IdentificationCode}` | `payload.seller.address.{street, city, postal_code, country_code}` | Payload |
| `/Invoice/cac:AccountingCustomerParty/.../cbc:RegistrationName` | `payload.buyer.name` (when present) | Payload |
| `/Invoice/cac:AccountingCustomerParty/.../cbc:CompanyID` | `payload.buyer.tax_number` (when present) | Payload |
| `/Invoice/cac:LegalMonetaryTotal/cbc:LineExtensionAmount` | `payload.subtotal` | Payload |
| `/Invoice/cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount` | `payload.subtotal` | Payload |
| `/Invoice/cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount` | `payload.total` | Payload |
| `/Invoice/cac:LegalMonetaryTotal/cbc:AllowanceTotalAmount` | `payload.transaction_discount_amount` | Payload |
| `/Invoice/cac:LegalMonetaryTotal/cbc:PayableAmount` | `payload.total` | Payload |
| `/Invoice/cac:TaxTotal/cbc:TaxAmount` | `payload.vat_total` | Payload |
| `/Invoice/cac:TaxTotal/cac:TaxSubtotal[i]/cbc:TaxableAmount` | `payload.vat_breakdown[i].net_amount` | Payload |
| `/Invoice/cac:TaxTotal/cac:TaxSubtotal[i]/cbc:TaxAmount` | `payload.vat_breakdown[i].vat_amount` | Payload |
| `/Invoice/cac:TaxTotal/cac:TaxSubtotal[i]/cac:TaxCategory/cbc:ID` | `payload.vat_breakdown[i].tax_category_code` (BT-151) | Payload |
| `/Invoice/cac:TaxTotal/cac:TaxSubtotal[i]/cac:TaxCategory/cbc:Percent` | `payload.vat_breakdown[i].rate` | Payload |
| `/Invoice/cac:InvoiceLine[i]/cbc:ID` | array index `i` (1-based) at export | Derived |
| `/Invoice/cac:InvoiceLine[i]/cbc:InvoicedQuantity` | `payload.line_items[i].quantity` | Payload |
| `/Invoice/cac:InvoiceLine[i]/cbc:LineExtensionAmount` | `payload.line_items[i].line_subtotal` | Payload |
| `/Invoice/cac:InvoiceLine[i]/cac:Item/cbc:Name` | `payload.line_items[i].name` | Payload |
| `/Invoice/cac:InvoiceLine[i]/cac:Item/cac:StandardItemIdentification/cbc:ID` | `payload.line_items[i].gtin` (when not null) | Payload |
| `/Invoice/cac:InvoiceLine[i]/cac:Item/cac:SellersItemIdentification/cbc:ID` | `payload.line_items[i].sku` | Payload |
| `/Invoice/cac:InvoiceLine[i]/cac:Price/cbc:PriceAmount` | `payload.line_items[i].unit_price` | Payload |
| `/Invoice/cac:InvoiceLine[i]/cac:ClassifiedTaxCategory/cbc:ID` | `payload.line_items[i].tax_category_code` | Payload |
| `/Invoice/cac:InvoiceLine[i]/cac:ClassifiedTaxCategory/cbc:Percent` | `payload.line_items[i].vat_rate` | Payload |
| `/Invoice/cac:PaymentMeans[i]/cbc:PaymentMeansCode` | `payload.payments[i].method_code` mapped to UN/ECE 4461 at export | Payload + mapping |
| `/Invoice/cac:AllowanceCharge` (invoice-level) | `payload.transaction_discount_amount` + `transaction_discount_reason` | Payload |

**Architectural lock:** every ZATCA UBL field is reconstructible from `(envelope + payload)` alone. No server-side data lookup needed at adapter time. D1+D3 honored.

---

## 5. D16-safe buyer block invariant (Codex P1.4 strengthened)

Buyer block in canonical payload subject to invariants — TESTED in Pass 2A:

1. **Sale-time snapshot.** Buyer data captured at engine.append() time from POS-local mirror data (already-mirrored customer/contact rows cached on device) OR operator-entered ad-hoc data. Engine NEVER calls customer/B2B modules at append.
2. **No projection-time enrichment.** `PosCoreReceiptProjection::apply()` reads buyer data from parsed `payload` only. No customer/contact/B2B lookups.
3. **Internal IDs are references, not authoritative.** `buyer.customer_id` / `buyer.contact_id` reference POS-local mirror rows for cross-linkage; if those rows later change or get deleted, the SEALED buyer snapshot is authoritative.
4. **Projection survives downstream deletion.** Test: seal SALE_RECEIPT with `buyer.customer_id=X`, delete customer row X, re-run projection — succeeds using payload-snapshot, no DB lookup attempted.
5. **D16 grep guard.** CI grep asserts `PosCoreReceiptProjection.php` does not contain:
   - Direct imports: `use App\Modules\Customer\…`, `use App\Modules\Contact\…`, `use App\Modules\B2B\…`, `use App\Modules\Treasury\…`, `use App\Modules\Accounting\…`.
   - Indirect imports: `use App\Shared\Contracts\Customer\…`, `use App\Shared\Contracts\Contact\…`, `use App\Shared\Contracts\B2B\…`, `use App\Shared\Contracts\Treasury\…`, `use App\Shared\Contracts\Accounting\…`.
   - Container-resolved services: `app(…)`, `App::make(…)`, `resolve(…)` — already forbidden by CLAUDE.md rule 13 but check explicitly.
   - Eloquent cross-module reads: any `Customer::`, `Contact::`, `B2B::`, `TreasuryPayment::` reference.

---

## 6. Why `tax_category_code` at BOTH line + breakdown layers (Codex P1.6 closure)

Both layers needed because:

- **Line-level (`line_items[].tax_category_code`):** required for KSA per-line `BT-151` (`cac:ClassifiedTaxCategory/cbc:ID` at `cac:InvoiceLine[i]`) — ZATCA needs per-line categorization for mixed-category invoices (e.g. one line standard-taxable, one line zero-rated, one line exempt). The line-level code drives ZATCA UBL InvoiceLine and DSFinV-K per-line export.
- **Breakdown-level (`vat_breakdown[].tax_category_code`):** required for IT Natura aggregation. The IT `Riepilogo` block aggregates per-VAT-rate-AND-per-Natura-code (e.g. two breakdown rows at rate 0%: one with `N1=esenti` and one with `N3=non-imponibili`). The breakdown-level code drives IT Tipi Dati XML.

**Aggregation rule** (must be tested in Pass 2A validator):
- For every distinct `(vat_rate, tax_category_code)` pair across `line_items[]`, exactly one `vat_breakdown[]` row exists with the same pair and totaled amounts.
- Validator asserts: `vat_breakdown` is the partition of `line_items` by `(vat_rate, tax_category_code)`.

This is documented in v3 §3 and tested in Pass 2A `FiscalPayloadConstraintValidatorTest::test_vat_breakdown_partitions_line_items()`.

---

## 7. Per-country tax-number patterns (Codex N-5 closure)

Validator's per-country `seller.tax_number` + `buyer.tax_number` (when not null) checked against:

| `tax_jurisdiction_country_code` | seller `tax_number` regex | buyer `tax_number` regex (when present) |
|---|---|---|
| `FR` (France) | `^[0-9]{14}$` (SIRET) OR `^[0-9]{9}$` (SIREN) | `^FR[0-9]{11}$` (TVA intracommunautaire) |
| `TN` (Tunisia) | `^[0-9]{7}[A-Z]/[A-Z]/[A-Z]/[0-9]{3}$` (matricule fiscal) — placeholder; verify with TN accountant before Pass 2A ship | same |
| `SA` (Saudi Arabia) | `^[0-9]{15}$` (VAT registration number) | `^[0-9]{15}$` |
| `DE` (Germany) | `^DE[0-9]{9}$` (USt-IdNr.) | `^DE[0-9]{9}$` |
| `IT` (Italy) | `^[0-9]{11}$` (Partita IVA) | `^[0-9]{11}$` (P.IVA) or `^[A-Z]{6}[0-9]{2}[A-Z][0-9]{2}[A-Z][0-9]{3}[A-Z]$` (codice fiscale 16-char) |

Validator's `tax_jurisdiction_country_code` is an ISO 3166-1 alpha-2 regex `^[A-Z]{2}$`. Per-regime patterns applied conditionally on the country code.

For Pass 2A: TN pattern flagged TBD pending owner confirmation with accountant (D9 — Tunisia is the immediate target market). Synthesis lands a placeholder; pre-merge gate requires owner verification.

---

## 8. Pass 2A + Pass 2B — two clean commits on dev branch (no flag — Codex N-1/N-3/N-7/N-10 closure)

D6 closure: **drop the feature flag.** Pass 2A and Pass 2B are sequential commits on `feat/pos-fiscal-event-engine-phase1`. No flag, no production isolation, no device/server sync mechanism. The dev branch is the safety net; merge to main happens once at end-of-Phase-1.

### Pass 2A — contract land (single commit)

Scope:
- **PHP:** Task 14 `FiscalPayloadConstraintValidator` PAYLOAD_KEYS expanded to 27-key list; `validateSaleReceiptPayload` expanded with nested object shape + per-regime tax-number patterns + tax_category_code + non_collected_subtype + invoice_type_code enums + buyer block + seller block.
- **PHP:** Task 16 `StrictCanonicalParser` — already shares PAYLOAD_KEYS via Task 24 R2 single source. No duplication needed.
- **PHP:** Task 21 `PosCoreReceiptProjection` — payload field mapping expanded. Most new fields default to canonical-only sourcing per Codex P2.15 (NO new columns unless a consumer is named). Cited new columns: `pos_receipts.invoice_type_code` (query/filter for refund/void/training), `pos_receipts.training_flag` (every report excludes training). All others (seller, buyer, gtin, tax_category_code, non_collected_subtype, lottery_code, original_receipt_reference) canonical-only via `fiscal_events.payload` JSONB read.
- **PHP:** Existing `Nf525DataProvider` (Task 30) updated to read from new canonical shape. Bifurcates line-level data from `fiscal_events.payload.line_items[]` (new shape) for fiscal_event-backed receipts; legacy path (`fiscal_event_id IS NULL`) untouched in Pass 2A — DEAD CODE after Pass 2B (no new device receipts seal without fiscal_event_id). Task 30 deferred P3-1 closure absorbed into Pass 2A.
- **TS:** TS `SALE_RECEIPT_PAYLOAD_KEYS` constant byte-mirrors PHP 27-key list. Cross-language drift gate updated.
- **TS:** `FiscalEventEngine.SaleReceiptPayloadInput` + `validateSaleReceiptPayload` expanded.
- **Fixtures:** Task 4 golden vectors REGENERATED. Old v3 fixtures DELETED (replaced atomically in same commit per D4 — no production data, no migration). New fixture matrix per §10.
- **Test migration (Codex N-7 closure):** every existing test file that hard-codes the 10-key shape is migrated to the 27-key shape IN THE SAME COMMIT. Inventory:
  - `apps/api/tests/Feature/Fiscal/OutboxIngestorTest.php` — `minimalSaleReceiptPayload()` helper (line 807-840) regenerated.
  - `apps/api/tests/Feature/Fiscal/ParseFailureResumeTest.php` — `correctedPayload()` helper (line 677-698) regenerated.
  - `apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionTest.php` — payload seeders (line 927-938) regenerated.
  - `apps/api/tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php` — extras-rejection + key-set + per-event tests expanded.
  - `apps/api/tests/Feature/Fiscal/StrictCanonicalParserTest.php` — parse-success + parse-failure prefix tests expanded.
  - `apps/api/tests/Feature/Fiscal/FiscalEventIngestionEndpointTest.php` — happy-path stored / quarantined / idempotent / sequenceConflict / malformedEnvelope variants.
  - `apps/pos/src/lib/fiscal/__tests__/FiscalEventEngine.test.ts` — payload validator + drift gate tests.
  - Plus any other test file in `apps/api/tests/Feature/Fiscal/` or `apps/pos/src/lib/fiscal/__tests__/` that references the SALE_RECEIPT shape. **Pre-commit gate:** grep `currency_scale.*discount_total.*lines.*payment_lines` across the worktree must return ZERO matches after Pass 2A (the 10-key shape signature).
- **Spec:** spec v7 → v8 cut. §11 SALE_RECEIPT payload-shape contract section added. §11.x country-adapter pattern section added. §17 test-locks: cross-language drift gate for SALE_RECEIPT.
- **Plan:** plan v4 → v5. §2144-2348 (Q1-Q6) replaced with finalized amended A1-A6 per §11 below.

Atomicity: ALL of the above lands in ONE commit. Pre-commit verification:
- Full Fiscal PHPUnit suite green.
- Full POS Vitest suite green.
- PHPStan level 8 green.
- Pint --test green.
- Cross-language drift gate green.
- Source-level guard tests green (Codex N-7 grep above).
- §14.3 chokepoint gate still PASS.

### Pass 2B — receiptService refactor (second commit)

Scope (after Pass 2A merges into dev branch):
- Device `receiptService.ts` refactor — emits Candidate C-v3 27-key shape via `engine.append()`.
- Engine singleton wiring per amended A1.
- `upsertTerminalState` write-once mirror of `genesis_seed` → `fiscal_event_genesis_seed` per amended A2.
- `OfflineReceiptInput` gains required `tenantId` + `companyId` per amended A3.
- Legacy chain DELETED per §9 deletion list.
- Test migration per amended A6 — Hybrid (~600-700 LOC unit / ~300-400 LOC integration / ~400-500 LOC deletion).
- Task 28 absorbed: `/pos/receipts/sync` route + ReceiptSyncService consumer + SyncReceiptPayload + SyncReceiptsRequest + SyncReceiptResult DELETED in same commit.

### Why this is safe without a flag

- Dev branch is single-author by design (this controller session + its subagents).
- No production deployment until end-of-Phase-1 merge to main.
- Pass 2A delivers a working contract — the dev branch passes CI after 2A even before 2B (parser+validator accept the new shape; existing tests rewritten to new shape; existing receiptService still emits the old shape, but no test exercises the old emission path against the new validator since the receiptService unit tests are mock-based and are rewritten in Pass 2B alongside the refactor).
- Wait — there IS a residual risk: the existing live `createOfflineReceipt` in receiptService.ts continues to emit the OLD 10-key shape between Pass 2A merge and Pass 2B merge. Manual smoke testing or e2e harness against dev branch would fail in that window. **Mitigation:** Pass 2A includes a temporary integration test that pins `createOfflineReceipt`'s output AGAINST THE OLD SHAPE to clearly document what's still legacy; Pass 2B deletes that test. The new validator + new fixtures are exercised in their own tests. The cross-language drift gate runs on the new shape. No live receipt flow goes through the new validator between 2A and 2B.

---

## 9. "No dual chain" — deletion list (per D2)

### Device-side (`apps/pos/src/`) — DELETED in Pass 2B

- `lib/offline/receiptService.ts`: DELETE `computeV3FiscalHash`, `buildCanonicalPayload`; DELETE imports of `computeFiscalHash`; DELETE direct writes to `terminal_state.last_hash`/`hash_sequence`; DELETE v3 schema-version branching.
- `lib/fiscal/hashService.ts`: DELETE if no remaining consumers. If Z-report path still uses it, move to `lib/legacy/` with deprecation docblock (out of Pass 2 scope to retire those consumers).
- `lib/fiscal/v3/`: DELETE entire directory.
- `lib/sync/syncService.ts`: DELETE the `/pos/receipts/sync` POST path. REPLACE with `/pos/sync/fiscal-events` push (Task 20 endpoint).
- `lib/db/repositories/offlineReceiptRepository.ts`: stop writing `fiscal_hash` IF no on-device readers; otherwise keep briefly.
- `lib/db/repositories/terminalStateRepository.ts`: `upsertTerminalState` extended (write-once mirror of `fiscal_event_genesis_seed`). Legacy `last_hash`/`hash_sequence` write paths DELETED.

### Server-side — DELETED in Pass 2B

- `apps/api/app/Modules/POS/Presentation/Controllers/SyncController.php`: DELETE `/pos/receipts/sync` handler.
- `apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php`: DELETE `sync()` consumer entry-point.
- `apps/api/app/Modules/POS/Application/DTOs/SyncReceiptPayload.php`: DELETED.
- `apps/api/app/Modules/POS/Presentation/Requests/SyncReceiptsRequest.php`: DELETED.
- `apps/api/app/Modules/POS/Application/DTOs/SyncReceiptResult.php`: DELETED.
- `apps/api/app/Modules/POS/routes.php`: DELETE `POST /pos/receipts/sync` route.
- §14.1 feature suites: per-method skips citing Pass 2B retirement OR deletion entirely.

### Server-side mirror columns — KEEP through Pass 2 per D5

- `pos_receipts.fiscal_hash` / `previous_hash` / `chain_sequence` / `vat_breakdown_hash` / `payment_methods_hash`: KEEP columns + projector mirror writes.
- `terminal_state.last_hash` / `hash_sequence` (device): KEEP columns; stop writing per D2.

### NEW deferred Phase-2 task (Codex P1.11 closure) — slotted into roadmap v2

Add to `docs/superpowers/specs/2026-05-14-pos-customer-accounts-roadmap-v2.md` under Phase 2 prep work:

> **Phase-2 mirror-column audit + drop.** After Phase 1 ships and before Phase 2 features deploy, audit every SQL/code consumer of `pos_receipts.{fiscal_hash, previous_hash, chain_sequence, vat_breakdown_hash, payment_methods_hash}` and `terminal_state.{last_hash, hash_sequence}`. Drop columns with no remaining consumer via a new migration. Remove projector mirror writes for any dropped column. Goal per owner D5: "no dead code at go-live."

This entry will be appended to roadmap v2 in the Pass 2A commit.

### Pass 2A canonical-payload line-reader utility (Codex N-8 closure)

Pass 2A includes `apps/api/app/Modules/Fiscal/Application/Services/CanonicalPayloadReader.php` — a typed reader API for `fiscal_events.payload` JSONB. Methods:
- `forLineItems(FiscalEvent $event): iterable<SaleReceiptLineDTO>` — yields per-line records with full canonical fields including `gtin`, `tax_category_code`, `non_collected_subtype`.
- `forPayments(FiscalEvent $event): iterable<SaleReceiptPaymentDTO>` — includes `foreign_currency_*`, `instrument_*`.
- `forVatBreakdown(FiscalEvent $event): iterable<SaleReceiptVatBreakdownDTO>`.
- `forBuyer(FiscalEvent $event): SaleReceiptBuyerDTO|null`.
- `forSeller(FiscalEvent $event): SaleReceiptSellerDTO`.

Used by:
- `Nf525DataProvider` (updated in Pass 2A) — replaces direct `pos_receipt_lines` reads for fiscal_event-backed receipts.
- Future DSFinV-K + ZATCA + IT RT export adapters.

This eliminates the canonical-only-vs-column-expansion tension Codex flagged.

---

## 10. Golden vector fixture matrix (Codex P2.13 + N-2/P3 closure)

Pass 2A regenerates ALL golden vectors. Coverage:

- F-1. Single-payment, no buyer, no discount, single line, single VAT rate → baseline.
- F-2. Multi-payment (cash + card) with no foreign-currency.
- F-3. Split-payment with foreign-currency leg (DSFinV-K-style — EUR primary + USD secondary).
- F-4. Voucher-redemption with `voucher_code`.
- F-5. Multi-line (3+ lines), multi-key reverse-order assertions, mixed `tax_category_code` values.
- F-6. Multi-VAT-rate breakdown — standard (`""`), zero (`"Z"`), exempt (`"E"`), out-of-scope (`"O"`) categories.
- F-7. B2B-buyer fixture with full address + tax_number (D8 reminder: NOT for ZATCA Standard Invoice authoring — just for buyer-attach optional in B2C Simplified).
- F-8. IT-style `non_collected_subtype` + `lottery_code` populated (future-proof; not emitted by NF525/TN adapter).
- F-9. Refund with `original_receipt_reference` populated.
- F-10. Void with `invoice_type_code='VOID'` + minimal payment block.
- F-11. Training-mode (`training_flag: true`).
- F-12. Hospitality (`consumption_mode='dine_in'`, `table_id` set).
- F-13. Non-ASCII names (Arabic, French accents, German umlauts, IT special chars).
- F-14. Null-optionals exhaustive — every nullable field set null in at least one fixture.
- F-15. **Large-receipt acceptance fixture** (Codex N-2/P3 closure): 50 line_items, 10 payments, 8 vat_breakdown rows, full buyer block, full original_receipt_reference, all optional fields populated. Asserts:
  - TS encoder produces canonical_bytes within parser size limits.
  - PHP parser accepts in <100ms.
  - JSONB insert into `fiscal_events.payload` succeeds (PostgreSQL `jsonb` has effectively no depth/size limit at this scale).
  - Projection dispatches successfully.

Each fixture verified against:
- TS encoder produces byte-identical canonical_bytes (vs. hand-authored expected string).
- PHP parser accepts and validates.
- TS validator rejects when extras added.
- PHP validator rejects when extras added.
- Cross-language drift gate: TS+PHP key-set equality.

---

## 11. Amended A1–A6 — FINAL FORM (ready for plan §2144-2348)

### Amended A1 — `FiscalEventEngine` singleton

`getFiscalEventEngine(companyId: string): Promise<FiscalEventEngine>` — async, per-companyId-keyed memoised factory at `apps/pos/src/lib/fiscal/instance.ts`. Mirrors `getDatabase(companyId)` precedent. Test reset helper `__resetFiscalEventEngineForTesting()` mirrors `__resetDatabase()`.

```ts
new FiscalEventEngine(
  await getDatabase(companyId),
  new FiscalEventCanonicalEncoder(),
  new HashChainIntegrityProvider(),
  getFiscalEventPayloadRegistry(),
);
```

### Amended A2 — `fiscal_event_genesis_seed` provisioning

`genesis_seed` ALREADY in `TerminalResource:42` + reaches device via claim + pullTerminalState. **No PHP edit needed.**

Device: extend `upsertTerminalState` (terminalStateRepository.ts:134-191) — write-once mirror of legacy `genesis_seed` into v37 `terminal_state.fiscal_event_genesis_seed`. Never overwrite non-empty (chain-restart protection).

Re-claim with mismatched seed: throws new `ChainGenesisSeedConflictError`. Operator wipes terminal_state via Task 25 `ChainRecoveryService` before re-claim.

Tests: pin `engine.append()` throws `ChainHeadNotInitializedError` on empty seed.

### Amended A3 — `tenant_id` + `company_id` source

From `useTerminalStore.activeTerminal.{tenantId, companyId}`. `OfflineReceiptInput` gains required `tenantId: string` + `companyId: string`. `paymentStore.createReceiptLocalFirst()` reads active terminal; throws new `ActiveTerminalRequiredError` if missing. No `useAuthStore` reads inside receiptService (CLAUDE.md rule 13).

### Amended A4 — Canonical SALE_RECEIPT payload shape

**Candidate C-v3** — 27-key cross-regime superset per §3.

Pass 2A cross-task amendments per §8. v1 rewrite in place per D4 — no event_version bump; every v1 fixture/test regenerated atomically per Codex N-7 inventory.

D16 buyer-block invariants per §5. CI grep guard catches direct + indirect coupling + container-resolved services + Eloquent cross-module reads.

`tax_category_code` at both line + breakdown layers per §6. Validator asserts partition rule.

Per-country `tax_number` patterns per §7. TN pattern flagged TBD pending owner accountant confirmation.

`bcformat` parity: TS implementation at `apps/pos/src/lib/fiscal/bcformat.ts` mirroring PHP `CurrencyScale::bcformat` byte-for-byte. Golden vectors per §10 fixture matrix.

### Amended A5 — Transactional boundary

Single SQLite transaction wraps engine.append() + projector writes (device-side):

1. `engine.append({event_type: 'SALE_RECEIPT', payload: <Candidate C-v3>, …})` — inserts `fiscal_events` row + advances `terminal_state.fiscal_event_chain_head` + `fiscal_event_sequence_number`.
2. `INSERT offline_receipts` (business-document mirror; `offline_receipts.canonical_bytes` MIRRORS `fiscal_events.canonical_bytes`).
3. UPDATE vouchers (decrement balances) per `vouchers_redeemed[]`.

NOT device-side: `pos_receipt_lines`/`pos_receipt_payments` (server-side; Task 21 writes via projection); stock decrement (deferred to separate task).

Wrap via existing raw `BEGIN/COMMIT/ROLLBACK` (no `withTransaction` helper on Tauri SQLite; engine.append runs inside caller's tx).

**Concurrent-receipt rule (Codex N-9 closure):** one in-flight receipt seal per terminal. The device serializes checkout submissions via a per-terminal mutex (acquired in `paymentStore.createReceiptLocalFirst()` before the SQLite transaction opens). On `ConcurrentChainAdvanceError`: catch, rollback, re-read chain head, retry with SAME `source_event_id` (engine idempotency at `(source_event_class, source_event_id)`). After 3 retries → surface as `FiscalChainContentionError` to operator (manual retry needed). Integration test in Pass 2B covers two concurrent checkouts against same terminal.

Rollback semantics: any sub-write failure aborts entire tx; engine idempotency prevents double-author on retry.

Legacy chain DELETED per §9. Engine sole writer to `terminal_state.fiscal_event_*` columns.

Source-level guard refined per Codex P2.14:
- Forbid in `receiptService.ts`: `computeReceiptHash`, `computeV3FiscalHash`, `computeFiscalHash`, raw `UPDATE terminal_state SET (last_hash|hash_sequence|chain_sequence)` SQL.
- Require: at least one `.append(` invocation on a value imported as `FiscalEventEngine` (AST-aware OR scoped regex over non-comment lines).

### Amended A6 — Test migration strategy

Hybrid:

**Bucket 1 — KEEP as unit tests (mocks)** — ~600-700 LOC:
- Cart-line aggregation, voucher dedup, currency-scaling, line discount logic, payload-assembly correctness.
- Tests mock `FiscalEventEngine.append` and assert 27-key payload shape passed.

**Bucket 2 — MIGRATE to `SqliteTestAdapter` integration tests** — ~300-400 LOC:
- Engine-append + projector-write tx (single-payment happy path).
- Split-payment with foreign-currency leg.
- Voucher-redemption + voucher dedup.
- Atomic rollback on voucher-update failure / unique-key collision / cross-tenant guard.
- Idempotency on retry (same source_event_id).
- Genesis-seed-empty rejection.
- Cross-tenant guard (active terminal tenant mismatch).
- Refund/void path with `original_receipt_reference`.
- Training-mode (`training_flag: true`).
- D16 buyer-block snapshot invariant (delete customer mid-projection).
- Concurrent-receipt serialization rule from amended A5.

**Bucket 3 — DELETE** — ~400-500 LOC:
- `computeReceiptHash` / `computeV3FiscalHash` / `buildCanonicalPayload` internal tests.
- Tests asserting `terminal_state.last_hash`/`hash_sequence` via direct SQL inspection.
- v3 hash chain shape tests.
- Legacy `offline_receipts.fiscal_hash` shape tests.

**Source-level guards** per Codex P2.14 — see Amended A5 above.

---

## 12. Cross-language drift framing (Codex P1.10 confirmed CLOSED in v2; carried into v3)

Per SoT v3 §1 line 27 + line 94: PHP NEVER re-serializes device-authored events.

- TS encoder must produce canonical_bytes byte-identical to hand-authored golden vectors.
- PHP parser must accept exactly those bytes and reject all drift.

The drift gate asserts:
- TS `SALE_RECEIPT_PAYLOAD_KEYS` set equality with PHP `FiscalPayloadConstraintValidator::PAYLOAD_KEYS['SALE_RECEIPT']`.
- For each golden vector: TS encoder output matches expected string AND PHP parser/validator accept.
- Negative fixtures: PHP parser rejects with expected forensic prefix.

No PHP re-serialization scenario exists for device-authored SALE_RECEIPT. Task 26 §11.0 server-authored carve-out is separate (e.g. `TERMINAL_REGISTRY_SNAPSHOT`).

---

## 13. Parse-failure resolution UX (Codex P1.8 partial close + transition plan)

Pass 2A 27-key shape means `ParseFailureResolutionService::resolve()` (Task 24 R2) requires operators to supply a full 27-key corrected payload. Codex round-2 flagged: this may make parse failures practically UNRESOLVABLE in production until operator tooling exists.

**Transition-period plan:**

1. Parse failures are RARE (StrictCanonicalParser strict; quarantine partition is for systemically corrupt inputs that should not normally occur).
2. Pass 2A keeps `ParseFailureResolutionService::resolve()` working — validator runs against new 27-key shape. The service is correct; the UX cost is operators must hand-craft a 27-key payload.
3. Phase 1 acceptable workflow: when a parse failure surfaces, owner/admin operator copies the raw envelope from `fiscal_event_quarantine.raw_envelope`, identifies the broken field, hand-crafts a corrected 27-key payload, submits via `fiscal:resolve-quarantine` command. Acceptable because parse failures are exceptional.
4. **NEW deferred task** added to roadmap (Phase 2 prep): "ParseFailureResolution operator UX — pre-fill from best-effort parse + partial-supplement semantic." Builds an admin tool that:
   - Reads `fiscal_event_quarantine.raw_envelope` + attempts best-effort parse.
   - Pre-fills the structurally-valid 27 keys.
   - Operator amends only broken fields.
   - Submits via the existing resolve service.

---

## 14. Adapter scoping (Codex N-6 closure per D3)

Per D3 — implement NF525 + Tunisia immediately; ZATCA + Germany + Italy DEFERRED.

| Adapter | Status |
|---|---|
| `Nf525DataProvider` (existing, Task 30) | **UPDATED in Pass 2A** — reads from new 27-key canonical via `CanonicalPayloadReader` (§9). Tunisia uses NF525 export by superset per D9. |
| ZATCA UBL XML emitter | DEFERRED. Per D8, model TBD (POS-authored vs web-B2B-aggregated). Pass 2A canonical IS ZATCA-ready (§4 XPath mapping); implementation pending owner decision. |
| DSFinV-K CSV export | DEFERRED. Phase 2+ work. Pass 2A canonical IS DSFinV-K-ready (per `gtin`, `consumption_mode`, `table_id`, foreign-currency fields). |
| IT RT XML | DEFERRED. Phase 2+ work. Pass 2A canonical IS IT-RT-ready (per `lottery_code`, `non_collected_subtype`, `codice_fiscale`). |
| Tunisia receipt template | DEFERRED — no specific certification regime. Per D9, NF525 satisfies Tunisia. |

Pass 2A does NOT implement new adapters beyond updating existing NF525. Tasks 34+ deferred.

---

## 15. Scope + risk + effort estimate (v3 final)

- **Pass 2A (contract + Nf525 update + canonical reader + Phase-2 task entry + spec v8 + plan v5):** ~3-4K LOC across ~20+ files including test migration inventory.
- **Pass 2B (receiptService + engine wiring + legacy deletion + Hybrid test migration + Task 28 absorption):** ~2-3K LOC across ~15+ files.
- **Total Pass 2: ~5-7K LOC across ~30-35 files** (vs. v2 estimate ~5-8K — slightly under because no feature flag mechanism).
- **Expected review rounds:** Pass 2A = 2-3 rounds (cross-task amendment is well-scoped; golden vector regen is mechanical once contract locked); Pass 2B = 2-3 rounds (receiptService refactor + legacy deletion is well-precedented).
- **Total Pass 2 = 4-6 rounds** (lower than v2's 5-7 estimate due to flag elimination).

---

## 16. Status

- v1 + v2 synthesis: superseded.
- v3 (this doc): pending Codex round-3 adversarial review + owner sign-off on minor remaining items per §17.
- After Codex round-3 APPROVE / APPROVE-WITH-MINOR-EDITS: plan §2144-2348 amended in place; spec v8 cut; Pass 2A implementer subagent dispatched (the parked agent at `a58368ad0687cb326` resumed via SendMessage OR a fresh dispatch with the v3 brief).

---

## 17. Remaining items for owner

**One pre-merge gate:** confirm TN `seller.tax_number` regex pattern per §7 with accountant before Pass 2A ships. Placeholder pattern `^[0-9]{7}[A-Z]/[A-Z]/[A-Z]/[0-9]{3}$` is best-guess.

Everything else is locked. No further architectural decisions needed before Codex round-3.

**End of synthesis v3.**
