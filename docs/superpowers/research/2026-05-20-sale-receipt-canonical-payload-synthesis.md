# SALE_RECEIPT Canonical Payload — Synthesis & Recommendation

**Date:** 2026-05-20
**Author:** Controller (session continuation)
**Status:** DRAFT — pending Codex adversarial review + owner sign-off → plan amendment.

---

## 1. Problem statement

Task 27B Pass 2 implementer pre-flight surfaced that plan §2144–2348 A4 (the 25-field SALE_RECEIPT payload — "Candidate B") materially contradicts the production PHP authority `FiscalPayloadConstraintValidator::PAYLOAD_KEYS['SALE_RECEIPT']` (the 10-field shape — "Candidate A"). Implementing Candidate B verbatim against an unchanged PHP validator would fail every receipt at canonical-parse / payload-extras gates.

Owner directive 2026-05-20: do PROPER NF525 + multi-country research; the 10-field shape may be a legacy/partial implementation, the rebuild's whole point is more fields + more events; no dual chain.

This document synthesizes findings from two parallel research streams (internal artifacts + external compliance specs) and the in-tree consolidated spec `docs/new_docs/03-MODULE-SPECS/hash-chain-fiscal-compliance-spec.md` (v1.0, Jan 8 2026). It proposes **Candidate C** — the cross-regime canonical superset — plus the cross-task amendment scope and the "no dual chain" deletion list.

---

## 2. Verdict on Candidates A and B

### Candidate A (PHP 10 fields)

`{currency, currency_scale, discount_total, lines, payment_lines, subtotal, tax_total, total, vat_breakdown, voucher_redemptions}`

**Inadequate for ALL FOUR target regimes (NF525 / ZATCA Phase 2 / KassenSichV-DSFinV-K / Italian RT).** Per the external research:

> "Each regime requires per-receipt identifier fields (terminal, cashier/operator, receipt sequence, business date, event timestamp) and at least a seller block plus an invoice-type/VAT-nature classifier that Candidate A entirely omits, so adopting Candidate A would block fiscal certification in every one of the four target markets."

Concrete missing fields per regime:

- **France NF525 v2.1:** operator code, terminal/caisse ID, ticket sequence number, payment method code (all mandatory v2.1), per-line discount percentage, business date, period close ID.
- **Saudi ZATCA Phase 2:** per-invoice UUID, ICV, PIH, seller name/VAT-number/address, IssueDate+IssueTime, invoice-type-code, line VAT-category-code (BT-151), payment-means-code per UN/ECE 4461.
- **Germany DSFinV-K v2.3:** Z_KASSE_ID, Z_NR, BON_ID, BON_NR, BON_TYP, TERMINAL_ID, BEDIENER_ID + BEDIENER_NAME, BON_START/ENDE, INHAUS, ART_NR + GTIN, KUNDE_*, ABRECHNUNGSKREIS, TSE_TANR/SIGZ/SIG, ZAHLART_TYP.
- **Italy RT v11.1:** matricola dispositivo, numero documento (closure-progressivo), DataOraRilevazione, partita IVA esercente, Natura code per VAT line, codice_fiscale buyer, lottery code, original-receipt reference.

**Internal research subagent's conclusion ("10-field is intentional Phase 1 canon") is rejected** because it conflated the *feature-scope* phasing (SoT v3 + roadmap v2 — Phase 1 = no customer features) with the *fiscal-payload-shape* phasing. The two are independent: feature scope can grow with new event types and feature flags, but fiscal payload shape MUST be compliance-complete on day one because changing canonical_bytes later invalidates the chain (D1 violation).

### Candidate B (plan A4 — 25 fields)

The plan's verbatim shape is materially closer to compliance reality but **still missing fields the external research surfaced as required by at least one of the four regimes**:

- `vat_nature_code` per VAT breakdown row (IT Natura N1–N7 for exempt/zero/reverse-charge; KSA line-level BT-151 category)
- `seller.{name, vat_number, address, country_code}` block (KSA cryptographic stamp scope + DE EKaBS receipt mandatory)
- `buyer.{name, tax_number, address, country_code, codice_fiscale}` block (B2B all regimes; conditional)
- `cashier_name` alongside `cashier_id` (DSFinV-K BEDIENER_NAME mandatory)
- `gtin` distinct from `sku` (DSFinV-K Bonpos)
- `receipt_uuid` distinct from `receipt_local_id` (KSA per-invoice UUID)
- `invoice_type_code` (SALE / REFUND / VOID / TRAINING / Standard vs Simplified)
- `original_receipt_reference` (refund/void linkage — all regimes; IT 4+4 composite, DE Bon_Referenzen, KSA BillingReference)
- `non_collected_subtype` (IT non riscosso classifier: servizi/beni/omaggio/successiva)
- `lottery_code` (IT optional)
- `payments[].currency_code` + `payments[].foreign_currency_amount` (DSFinV-K Bonkopf_Zahlarten ZAHLWAEH_CODE/BETRAG)
- `line_items[].vat_category_code` (KSA BT-151 / IT Natura per-line — distinct from `vat_rate`)

---

## 3. The two-payload-level pattern

External research found that DE + IT formally split a minimal chain-signing payload from a rich audit-export payload. KSA hashes the full UBL (no split). FR has an implicit split (per-vendor docs).

For AutoERP's single-engine multi-country target, the **architecturally cleanest path is one rich canonical payload, hashed as canonical_bytes via JCS, with country-specific signature + export adapters extracting their respective projections.** Justification:

- KSA hashes everything; matching that model satisfies the strictest requirement.
- DE TSE adapter can extract minimal processData from the rich payload before sending to the TSE.
- IT RT adapter can extract documento commerciale fields from the rich payload before signing PADES/CADES.
- DSFinV-K / NF525 JET / IT XML exporters all project from the canonical payload (D1 + D2 honored).
- Single chain over single canonical avoids the dual-chain reasoning the owner explicitly rejected.

This is consistent with the SoT v3 §13.6 + D16 asymmetric bounded-modules pattern: the engine publishes one canonical event; country-specific bridges/adapters consume and project.

---

## 4. Candidate C — proposed canonical payload (cross-regime superset)

This is the canonical SALE_RECEIPT payload shape proposed for Pass 2. Fields are alphabetically sorted (JCS requires sorted keys).

```ts
export interface SaleReceiptPayload {
  // -----------------------------------------------------------------
  // Buyer block (conditional — present for B2B Standard Invoice or
  // when customer/contact is loyalty-attached for the receipt)
  // -----------------------------------------------------------------
  buyer: {
    address: { city: string; country_code: string; postal_code: string; street: string } | null;
    codice_fiscale: string | null;     // IT — distinct from tax_number
    contact_id: string | null;         // internal contact UUID
    customer_id: string | null;        // internal customer UUID
    name: string | null;
    tax_number: string | null;         // KSA 15-digit, IT P.IVA, DE USt-ID
  } | null;

  // -----------------------------------------------------------------
  // Identity / chain position
  // -----------------------------------------------------------------
  business_date: string;               // YYYY-MM-DD
  cashier_id: string;                  // UUID
  cashier_name: string;                // DSFinV-K BEDIENER_NAME mandatory
  consumption_mode: 'dine_in' | 'takeaway' | null;
  currency_code: string;               // ISO 4217 — "EUR", "SAR", "TND", "GBP", "USD"
  currency_scale: number;              // 0|2|3
  event_time_device: string;           // ISO 8601 with ms + timezone offset
  invoice_type_code: 'SALE' | 'REFUND' | 'VOID' | 'TRAINING';
                                       // Sub-type for KSA + IT below via separate field
  invoice_subtype_code: 'STANDARD' | 'SIMPLIFIED' | null;
                                       // KSA Standard (T) vs Simplified (S); null for non-KSA
  notes: string | null;
  receipt_local_id: string;            // device-side UUID (offline_receipts.id mirror)
  receipt_uuid: string;                // per-invoice UUID (distinct from local_id — KSA)
  shift_id: string;                    // UUID
  table_id: string | null;             // hospitality
  terminal_id: string;                 // UUID
  training_flag: boolean;

  // -----------------------------------------------------------------
  // Line items
  // -----------------------------------------------------------------
  line_items: Array<{
    gtin: string | null;               // DSFinV-K Bonpos GTIN
    line_discount_amount: string;      // bcformat per currency_scale
    line_discount_reason: string | null;
    line_subtotal: string;             // net (before VAT)
    line_vat: string;
    name: string;                      // description
    non_collected_subtype: 'servizi' | 'beni' | 'omaggio' | 'successiva' | null;
                                       // IT non riscosso
    product_id: string;                // internal UUID
    quantity: string;                  // bcformat per quantity_scale
    sku: string;
    unit_price: string;                // bcformat per currency_scale
    vat_category_code: string;         // KSA BT-151 (S/Z/E/O); IT Natura (N1-N7); null = standard
    vat_rate: string;                  // bcformat percentage ("0.00", "20.00", "5.50")
  }>;

  // -----------------------------------------------------------------
  // Original receipt reference (refund / void — null otherwise)
  // -----------------------------------------------------------------
  original_receipt_reference: {
    fiscal_event_id: string;           // UUID of the original SALE_RECEIPT fiscal event
    original_business_date: string;    // YYYY-MM-DD
    original_receipt_local_id: string; // for cross-reference
    refund_reason: string;
  } | null;

  // -----------------------------------------------------------------
  // Payments
  // -----------------------------------------------------------------
  payments: Array<{
    amount: string;                    // bcformat in currency_code
    foreign_currency_amount: string | null;  // DSFinV-K ZAHLWAEH_BETRAG (foreign currency original)
    foreign_currency_code: string | null;    // DSFinV-K ZAHLWAEH_CODE (ISO 4217)
    instrument_serial: string | null;
    instrument_type: string | null;    // voucher / card / etc.
    method_code: string;               // existing PaymentMethod codes — must map to UN/ECE 4461 for KSA
  }>;

  // -----------------------------------------------------------------
  // Seller block (REQUIRED — KSA cryptographic stamp + DE EKaBS receipt)
  // -----------------------------------------------------------------
  seller: {
    address: { city: string; country_code: string; postal_code: string; street: string };
    name: string;
    tax_jurisdiction_country_code: string;  // ISO 3166-1 alpha-2 — "FR", "SA", "DE", "IT", "TN"
    tax_number: string;                 // KSA 15-digit VAT, FR SIRET/SIREN, IT P.IVA, DE USt-ID
  };

  // -----------------------------------------------------------------
  // Totals + VAT breakdown
  // -----------------------------------------------------------------
  subtotal: string;                    // bcformat — net of VAT
  total: string;                       // bcformat — gross (incl. VAT)
  transaction_discount_amount: string; // bcformat — "0" when none
  transaction_discount_reason: string | null;
  vat_breakdown: Array<{
    gross_amount: string;
    net_amount: string;
    rate: string;                      // bcformat % ("20.00", "5.50", "0.00")
    vat_amount: string;
    vat_nature_code: string;           // KSA BT-151 / IT Natura; null = standard taxable
  }>;
  vat_total: string;

  // -----------------------------------------------------------------
  // Vouchers redeemed against this sale (loyalty / wallet / gift-card)
  // -----------------------------------------------------------------
  vouchers_redeemed: Array<{
    redeemed_amount: string;
    voucher_code: string;
  }>;

  // -----------------------------------------------------------------
  // Optional IT lottery (codice lotteria) — present only if customer
  // provides one AND codice_fiscale is null (mutually exclusive per IT spec)
  // -----------------------------------------------------------------
  lottery_code: string | null;
}
```

Cardinality count: 5 (seller) + 6 (buyer-as-block-or-null) + 18 (top-level identity/totals/refs) + N (line_items) + N (payments) + N (vat_breakdown) + N (vouchers_redeemed) + N (top-level optional fields). The **top-level PAYLOAD_KEYS list** (the keys gated by the canonical parser's extras-rejection) is:

```
[
  "business_date", "buyer", "cashier_id", "cashier_name", "consumption_mode",
  "currency_code", "currency_scale", "event_time_device", "invoice_subtype_code",
  "invoice_type_code", "line_items", "lottery_code", "notes",
  "original_receipt_reference", "payments", "receipt_local_id", "receipt_uuid",
  "seller", "shift_id", "subtotal", "table_id", "terminal_id", "training_flag",
  "transaction_discount_amount", "transaction_discount_reason", "vat_breakdown",
  "vat_total", "total", "vouchers_redeemed"
]
```

29 top-level keys (vs 10 in PHP Candidate A; vs 25 in plan Candidate B).

**EXCLUDED — never put in payload** (server-derived or envelope-level — same as Candidate B):
- `receipt_number` (server-derived per Task 21 `PosCoreReceiptProjection`)
- `pos_receipts.id` (server-derived row id)
- `fiscal_event_id` (the envelope's id, not the payload's; UNLESS used in `original_receipt_reference`)
- `fiscal_hash` / `previous_hash` (envelope-level)
- VAT-breakdown hashes / Payment-methods hash (Task 21 R2 computed real values)

---

## 5. Cross-task amendment scope

Pass 2 is no longer "wire the engine"; it's "wire the engine + lock the canonical SALE_RECEIPT contract for multi-country compliance." Concrete cross-task amendments:

### 5.1 Task 14 — `FiscalPayloadConstraintValidator`

- `PAYLOAD_KEYS['SALE_RECEIPT']` expanded to the 29-key list above.
- Per-event validator `validateSaleReceiptPayload` expanded: shape checks for `seller`, `buyer`, `line_items[].vat_category_code`, `line_items[].gtin`, `original_receipt_reference`, etc.
- `validateServerAuthoredPayload` already handles server-authored events (Task 26 amendment) — does NOT cover SALE_RECEIPT (device-authored), no change.
- Tests: expand `FiscalPayloadConstraintValidatorTest` per-event-type round-trip + extras-rejection coverage.

### 5.2 Task 16 — `StrictCanonicalParser`

- `PAYLOAD_KEYS` map mirrors Task 14 (already shared via `FiscalPayloadConstraintValidator` — single source post-Task-24-R2).
- Per-event constraints in `validateSaleReceiptPayload` extended: nested object shape, regex validation on `business_date` / `event_time_device`, UUID format on `receipt_uuid` / nested `cashier_id` / `terminal_id` / `shift_id` / `buyer.customer_id` / `original_receipt_reference.fiscal_event_id`, money regex on all bcformat fields with scale-awareness, enum checks on `invoice_type_code` / `invoice_subtype_code` / `consumption_mode`, ISO 3166-1 country-code regex on `seller.tax_jurisdiction_country_code` + addresses, alpha-2 + 15-digit VAT regex per KSA + 11-digit P.IVA per IT.
- Forensic failure prefixes for each new constraint (`<snake_case>:<context>`).

### 5.3 Task 21 — `PosCoreReceiptProjection`

- Mapping the new payload fields onto projector outputs:
  - `seller` block → `pos_receipts` company-scope columns (likely already present from the `company` setup; just verify it doesn't drift from canonical).
  - `buyer` block → `pos_receipts.customer_id` / `contact_id` (existing FK columns).
  - `line_items[].gtin` → `pos_receipt_lines.gtin` (new column needed) OR retained on payload only with DSFinV-K export reading from canonical.
  - `line_items[].vat_category_code` → `pos_receipt_lines.vat_category_code` (new column needed for projection ergonomics) OR canonical-only.
  - `original_receipt_reference` → `pos_receipts.original_fiscal_event_id` (new column for refund/void linkage) OR canonical-only.
  - `invoice_type_code`, `training_flag`, `consumption_mode`, `table_id` → existing `pos_receipts.type` column extended OR new columns.
- D1 principle: every column on `pos_receipts` must be sourceable from canonical_bytes. Either (a) source from canonical at projection time, OR (b) drop the column and let exports re-derive from canonical_bytes (preferred for new fields — minimize column expansion).
- Tests: extend `PosCoreReceiptProjectionTest` with new field projections; preserve the existing payment + voucher + stock matrix.

### 5.4 Task 25 — Cross-language drift gate

- TS `SALE_RECEIPT_PAYLOAD_KEYS` constant byte-mirrors the PHP 29-key list.
- Cross-language test asserts key-set parity (existing pattern from Task 25 R3).

### 5.5 Task 4 — Golden vectors

- Re-generate golden vectors against the new canonical shape.
- 47 v3 fixture tests will need regeneration — but per "no dual chain," v3 is going away entirely; the v3 fixtures get DELETED, replaced with v4 (new canonical) fixtures.
- This is the BIGGEST golden-vector swap of the project — needs careful regen via PHP+TS round-trip.

### 5.6 TS `FiscalEventEngine.SaleReceiptPayloadInput` + `validateSaleReceiptPayload`

- Interface expanded to the 29-key shape (see Candidate C above).
- Runtime validator switch expanded (extras-rejection, regex, enum checks).

### 5.7 Spec v7 amendment → v8

- §4 envelope: no change (envelope is separate from payload).
- §11 SALE_RECEIPT event: payload-shape contract section added/expanded with the 29-key list + per-field semantics + per-regime mapping table.
- §14.3 chokepoint gate: unchanged.
- §17 test-locks: add cross-language drift gate for SALE_RECEIPT.

### 5.8 Plan v4 → v5

- §2144–2348 (Q1-Q6 owner-approved decisions) A4 amended to point at Candidate C.
- A1/A2/A3/A5/A6 also amended per the 5 implementer pre-flight divergences (async getFiscalEventEngine, upsertTerminalState write-once mirror, device-only tables, no dual chain).
- Task 27B Pass 2 scope grows to absorb Task 28's `/pos/receipts/sync` retirement.
- Task 28 reduced to just the §14.1 backend feature-suite migration cleanup (not the route deletion which Pass 2 absorbs).

---

## 6. "No dual chain" — concrete deletion list

Owner directive 2026-05-20: legacy fiscal chain DELETED in Pass 2. Concrete file/code surgery:

### Device-side (`apps/pos/src/`)

- `lib/offline/receiptService.ts`:
  - DELETE `computeV3FiscalHash`, `buildCanonicalPayload` (the v3 hash assembly).
  - DELETE imports of `computeFiscalHash` from `@/lib/fiscal/hashService`.
  - DELETE direct writes to `terminal_state.last_hash` and `terminal_state.hash_sequence`.
  - DELETE the v3 fiscalSchemaVersion branching (no longer dual).
  - Engine.append() is the sole writer to `terminal_state.fiscal_event_*` columns.
- `lib/fiscal/hashService.ts`: file becomes DELETABLE if no other consumers; if there are non-receipt consumers (e.g. Z-report hash), they migrate to engine.append() or stay on a clearly-renamed non-fiscal helper (e.g. `legacy/`).
- `lib/fiscal/v3/`: entire directory DELETED.
- `lib/offline/__tests__/receiptService.test.ts`: tests of `computeReceiptHash` / `computeV3FiscalHash` internals — DELETED (Bucket 3 from plan A6).
- `lib/offline/__tests__/offlineCheckoutService.test.ts:275` source-level guard tightened: assert `computeV3FiscalHash` AND `computeFiscalHash` AND raw `terminal_state.last_hash` UPDATE SQL ALL absent from `receiptService.ts`.
- `lib/sync/syncService.ts`: legacy `/pos/receipts/sync` POST path DELETED; replaced with `/pos/sync/fiscal-events` push (Task 20's endpoint).
- `lib/db/repositories/offlineReceiptRepository.ts`: `fiscal_hash` write path retained briefly for non-receipt readers — but if no non-receipt readers, DELETE.
- `lib/db/repositories/terminalStateRepository.ts`: `upsertTerminalState` writes the v37 `fiscal_event_*` columns (was missing); the legacy `last_hash` / `hash_sequence` columns either kept for non-receipt readers or stripped from the write path.

### Server-side (`apps/api/app/Modules/POS/`)

- `Presentation/Controllers/SyncController.php` — DELETE the `/pos/receipts/sync` handler.
- `Application/Services/ReceiptSyncService.php` — `sync()` consumer entry-point DELETED; the rest of the class (called by other code paths) may stay.
- `Application/DTOs/SyncReceiptPayload.php` — DELETED.
- `Presentation/Requests/SyncReceiptsRequest.php` — DELETED.
- `Application/DTOs/SyncReceiptResult.php` — DELETED.
- `routes.php` — DELETE the `POST /pos/receipts/sync` route definition.
- `Tests/Feature/POS/SyncControllerTest.php` and the §14.1 listed feature suites — converted to per-method skips citing Pass 2's retirement OR deleted entirely.

### Schema-level (`apps/api/database/migrations/` + `apps/pos/src/lib/db/migrations/`)

- `pos_receipts.fiscal_hash`, `pos_receipts.previous_hash`, `pos_receipts.chain_sequence`, `pos_receipts.vat_breakdown_hash`, `pos_receipts.payment_methods_hash`: legacy chain mirror columns. Either DROP via a new migration (clean rebuild) OR leave the columns with nullable values written by the projector for forensic continuity (the new chain is in `fiscal_events`). **Recommend leaving them in place** — column drops are higher-risk and lower-value than just stopping the writes; the canonical_bytes / fiscal_event_id columns are the authoritative chain anchor going forward. A Phase-2 cleanup migration can drop them once dust settles.
- `terminal_state.last_hash` / `terminal_state.hash_sequence`: same as above — stop writing, leave columns.

### CI gates

- §14.3 chokepoint gate at `apps/api/scripts/check-saleReceipt-chokepoints.sh`: post-Pass-2, the `Task 28` retired_in_task markers in `saleReceipt-chokepoint-manifest.json` flip to `retired_in_task: "Task 27B Pass 2"` (or just `retired: true` with no follow-up task).
- Existing CI PG-merge-gate filter at `.github/workflows/ci.yml:~404`: extended with any new test classes Pass 2 introduces (e.g. `TerminalClaimGenesisSeedTest`, `Nf525CanonicalPayloadV4Test`, etc.).

---

## 7. Amended A1–A6 (for plan §2144–2348)

Pending owner sign-off; if approved, replace plan §2144-2348 in place.

### Amended A1 — `FiscalEventEngine` singleton

`getFiscalEventEngine(companyId: string): Promise<FiscalEventEngine>` — async, per-companyId-keyed memoised factory at `apps/pos/src/lib/fiscal/instance.ts`. Mirrors the existing `getDatabase(companyId)` precedent (the originally cited `getSqlSurface()` does not exist). Singleton rebuilds when companyId changes. Test reset helper `__resetFiscalEventEngineForTesting()` mirrors `__resetDatabase()` precedent.

Construction wires the 4 explicit constructor args of `FiscalEventEngine`:
```ts
new FiscalEventEngine(
  await getDatabase(companyId),                // defaultDb
  new FiscalEventCanonicalEncoder(),           // encoder
  new HashChainIntegrityProvider(),            // integrityProvider
  getFiscalEventPayloadRegistry(),             // registry (module-scope memoised)
);
```

### Amended A2 — `fiscal_event_genesis_seed` provisioning

Server-side: `genesis_seed` is ALREADY in `TerminalResource:42` and reaches device via `/pos/terminals/claim` AND `/pos/terminals/{id}` (pullTerminalState). **No PHP edit required.**

Device-side: extend `upsertTerminalState` (terminalStateRepository.ts:134-191) to mirror the legacy `genesis_seed` value into the v37-added `terminal_state.fiscal_event_genesis_seed` column — write-once (`UPDATE … SET fiscal_event_genesis_seed = ? WHERE id = ? AND (fiscal_event_genesis_seed = '' OR fiscal_event_genesis_seed IS NULL)`). Never overwrite a non-empty seed (chain-restart protection).

Re-claim with mismatched seed: throws new `ChainGenesisSeedConflictError`. Operator must explicitly wipe terminal_state via Task 25 `ChainRecoveryService` before re-claiming.

Tests: failing test asserts `engine.append()` throws `ChainHeadNotInitializedError` when seed is empty (Task 15 R2 already throws this; pin it explicitly).

### Amended A3 — `tenant_id` source

From `useTerminalStore.activeTerminal.tenantId` (`pos_terminals.tenant_id` per Task 19 envelope-validation). `company_id` flows the same way (`useTerminalStore.activeTerminal.companyId`).

`OfflineReceiptInput` gains required `tenantId: string` + `companyId: string`. `paymentStore.createReceiptLocalFirst()` reads from `useTerminalStore.getState().activeTerminal`; throws new `ActiveTerminalRequiredError` if no active terminal.

No reading from `useAuthStore` inside `receiptService` (CLAUDE.md rule 13).

### Amended A4 — Canonical SALE_RECEIPT payload shape

**Adopt Candidate C** (the 29-key cross-regime superset — see §4 above). Implement at `apps/pos/src/lib/fiscal/payloads/SaleReceiptPayload.ts`.

Cross-task amendments per §5 above:
- Task 14 PHP validator: PAYLOAD_KEYS + validateSaleReceiptPayload expanded.
- Task 16 PHP parser: per-event constraints expanded.
- Task 21 PHP projector: field mapping expanded (column expansion OR canonical-only sourcing per D1).
- Task 25 cross-language drift gate: TS↔PHP byte-mirror updated.
- Task 4 golden vectors: REGENERATED (v3 fixtures DELETED, v4 fixtures generated).
- TS FiscalEventEngine validator: extras-rejection + per-field runtime checks expanded.

Bcformat parity: ship `apps/pos/src/lib/fiscal/bcformat.ts` mirroring PHP `CurrencyScale::bcformat($value, $scale)` byte-for-byte if no existing helper. Golden vectors for scale=0 (TND), scale=2 (EUR/USD/GBP), scale=3 (legacy).

### Amended A5 — Transactional boundary

Single SQLite transaction wraps engine.append() + projector writes (device-side):
1. `engine.append({event_type: 'SALE_RECEIPT', payload: <Candidate C>, …})` — inserts `fiscal_events` row + advances `terminal_state.fiscal_event_chain_head` + `fiscal_event_sequence_number`.
2. `INSERT offline_receipts` (business-document mirror). `offline_receipts.canonical_bytes` MIRRORS `fiscal_events.canonical_bytes` from engine return.
3. UPDATE vouchers (decrement balances) per `vouchers_redeemed[]`.

NOT device-side (per implementer pre-flight finding):
- `pos_receipt_lines` and `pos_receipt_payments` are SERVER-side tables — written by Task 21's `PosCoreReceiptProjection` on the SERVER after the device syncs the `fiscal_events` row. Device stores lines + payments as JSON columns on `offline_receipts` (existing schema).
- Stock decrement: not currently in `createOfflineReceipt` (verified by reading the file); deferred to a separate task. NOT in Pass 2 scope.

Wrap via the existing raw `BEGIN/COMMIT/ROLLBACK` pattern that `createOfflineReceipt` uses (NOT a `sqlSurface.withTransaction` helper — that doesn't exist). Engine.append() documented as running inside caller's transaction.

Rollback semantics: any sub-write failure aborts the entire tx; no `fiscal_events` row persists; device retries with same `OfflineReceiptInput`; engine idempotency check (`source_event_class` + `source_event_id`) prevents double-author.

**Legacy chain DELETED per §6 deletion list.** Engine is sole writer to `terminal_state.fiscal_event_*` columns. No `terminal_state.hash_sequence` advance from receiptService. Source-level guard tightened.

### Amended A6 — Test migration strategy

Hybrid per plan A6 + adjusted per amended A4 + A5:

**Bucket 1 — KEEP as unit tests (mocks)** — ~600-700 LOC:
- Cart-line aggregation, voucher dedup, currency-scaling helpers, line-item discount logic, payload-assembly correctness.
- Tests mock `FiscalEventEngine.append` and assert the Candidate C payload shape the assembler PASSED.

**Bucket 2 — MIGRATE to `SqliteTestAdapter` integration tests** — ~300-400 LOC (expanded matrix):
- Engine-append + projector-write tx (single-payment happy path).
- Split-payment.
- Voucher-redemption + voucher dedup edge cases.
- **Atomic rollback** on voucher-update failure / unique-key collision on `offline_receipts` / cross-tenant guard.
- **Idempotency on retry** (source_event_class + source_event_id duplicate doesn't double-author).
- **Genesis-seed-empty rejection** (`ChainHeadNotInitializedError`).
- **Cross-tenant guard** (active terminal tenant mismatch).
- **Refund/void path** (original_receipt_reference linkage; invoice_type_code='REFUND' or 'VOID').
- **Training-mode path** (training_flag=true; assert NOT in production chain per regime semantics).

**Bucket 3 — DELETE** — ~400-500 LOC:
- All `computeReceiptHash` / `computeV3FiscalHash` / `buildCanonicalPayload` internal tests.
- Tests asserting `terminal_state.last_hash` / `hash_sequence` via direct SQL inspection.
- Tests asserting v3-specific hash chain shapes.
- Tests asserting legacy `offline_receipts.fiscal_hash` shape.

**Source-level guards** (extend Pass 1's regex):
- `computeReceiptHash` MUST NOT appear in `apps/pos/src/lib/offline/receiptService.ts`.
- `computeV3FiscalHash` MUST NOT appear.
- `computeFiscalHash` MUST NOT appear.
- Raw `UPDATE terminal_state SET (last_hash|hash_sequence)` SQL MUST NOT appear.
- Engine.append() invocation MUST appear (presence-of-correct-behavior per Task 29 pattern).

---

## 8. Scope, risk, and effort estimate

- **Scope:** materially larger than Q1-Q6 implied. The canonical payload expansion is cross-task (Tasks 14/16/21/25 + Task 4 fixtures regenerated + spec v8 cut). Adding "no dual chain" absorbs most of Task 28. Adding the §5 cross-task amendments brings it to ~5-8K LOC across ~15+ files.
- **Risk profile:** HIGH. Golden vector regeneration is the highest-risk single sub-task — JCS canonical encoding output must byte-match between TS device + PHP server for the cross-language drift gate. Task 4 + Task 5 patterns + the existing `canonicalCore.ts` extraction (Task 5 deferred P2 closure) help.
- **Expected review rounds:** 5+ per Task 30 precedent (substantial-architecture + multi-task amendments). Plan for it.
- **Recommended pacing:** consider splitting into **Pass 2A** (canonical payload contract amendments — Tasks 14/16/21/25/4 + spec v8) and **Pass 2B** (receiptService refactor on the new contract + legacy chain deletion + Task 28 absorption). Pass 2A is the contract surgery; Pass 2B is the behavior change. This reduces single-review-round blast radius and lets the contract amendment land cleanly before the device code rewrites against it.

---

## 9. Open questions for the owner (need explicit answer)

**Q-A:** Adopt Candidate C as the canonical payload? (Recommended yes.)

**Q-B:** Split Pass 2 into Pass 2A (contract) + Pass 2B (refactor)? (Recommended yes per §8 risk.)

**Q-C:** Delete legacy chain mirror columns (`pos_receipts.fiscal_hash` etc.) in Pass 2, or just stop writing them and defer column drops to Phase 2 cleanup? (Recommended: stop writing, defer drops.)

**Q-D:** Absorb Task 28 `/pos/receipts/sync` route deletion into Pass 2 (recommended) — leaving Task 28 reduced to the §14.1 feature-suite migration cleanup? Or keep Task 28 separate?

**Q-E:** Spec v8 amendment cut now (before Pass 2A dispatch) so the implementer brief points at v8 not v7? (Recommended yes.)

---

**End of synthesis. Next step: Codex adversarial review of this document, then back to owner for sign-off on Q-A through Q-E.**
