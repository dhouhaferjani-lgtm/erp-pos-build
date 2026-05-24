# SALE_RECEIPT Canonical Payload — Synthesis v2

**Date:** 2026-05-20
**Author:** Controller (session continuation)
**Status:** DRAFT — incorporates owner decisions Q1/Q2/Q3 + Codex round-1 BLOCK review. Pending Codex round-2 → owner sign-off → plan amendment.
**Prior artifacts:**
- v1 synthesis: `docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis.md` (Codex BLOCK 3+8+4+1).
- Codex round-1 review: `docs/superpowers/reviews/2026-05-20-sale-receipt-canonical-payload-codex-review.md`.
- External research grounding (now committed): `docs/superpowers/research/2026-05-20-multicountry-fiscal-research.md`.

---

## 1. Problem statement (recap)

Task 27B Pass 2 implementer pre-flight audit found that plan §2144-2348 A4 (the 25-field SALE_RECEIPT payload — "Candidate B") materially contradicts the production PHP authority `FiscalPayloadConstraintValidator::PAYLOAD_KEYS['SALE_RECEIPT']` (the 10-field shape — "Candidate A"). The implementer correctly refused to ship plan A4 verbatim because every receipt would fail the PHP validator's extras-rejection.

Owner directives 2026-05-20:
- D1. The 10-field shape may be legacy/partial; rebuild's whole point is more fields + more events for a more complete multi-country fiscal chain.
- D2. No dual chain — legacy fiscal chain logic deleted in Pass 2.
- D3. Commit to specs for all 4 regimes (NF525 + ZATCA + Germany + Italy); implement NF525 + ZATCA first; design so DE/IT addition later is incremental, not a rewrite.
- D4. Q1 — rewrite v1 in place (no event_version bump; no in-flight tenants).
- D5. Q2 — projector continues to write legacy mirror columns through Pass 2; **audit + drop** scheduled for post-Phase-2 cleanup so we don't ship dead code at go-live.
- D6. Q3 — Pass 2A (contract) + Pass 2B (assembler) split, both gated behind a feature flag so the contract is unreachable in production until 2B flips the switch.

This v2 synthesis carries D1–D6 into the canonical payload contract and the cross-task amendment plan, and addresses every Codex round-1 finding inline.

---

## 2. Verdict on payload candidates

- **Candidate A (10 fields)** — inadequate for ALL FOUR target regimes per the committed external research §1–§4. Each regime requires identifier fields (terminal, cashier/operator, receipt sequence, business date, event timestamp) and at least a seller block plus an invoice-type/VAT-category classifier that A omits.
- **Candidate B (plan A4, 25 fields)** — closer to compliance but still incomplete (missing buyer block, cashier_name, gtin, tax_category_code, invoice_type_code, original_receipt_reference, non_collected_subtype, lottery_code, payments[].foreign_currency_*, plus the field-collapse refactors Codex round-1 P1.6/P1.7 surfaced).
- **Candidate C-revised** — the cross-regime canonical superset per §5 below, after Codex round-1 closures (collapsed `tax_category_code` per P1.6; collapsed `receipt_uuid` per P1.7; explicit D16-safe buyer-block invariant per P1.4; explicit envelope-vs-payload mapping per B2).

The internal research subagent's earlier verdict ("10-field is intentional Phase 1 canon, expansion deferred to Phase 2–4") is incorrect — it conflated *feature-scope* phasing (SoT v3 + roadmap v2) with *fiscal-payload-shape* phasing. These are independent axes: feature scope can grow with new event types and feature flags; payload shape MUST be compliance-complete on day one because changing canonical_bytes later invalidates the chain (D1 violation in SoT v3 §1).

---

## 3. Architectural pattern: one rich canonical + country-specific adapters

Per committed external research §5, the four regimes split into two model families:

- **Saudi Arabia — full UBL hashed.** Cryptographic stamp covers seller, buyer, lines, VAT, totals, payments, references, identifiers (PIH, ICV, UUID). No minimal/rich split.
- **Germany + Italy — formal minimal-vs-rich split.** TSE signs a small `processData` (per-VAT-rate totals + payment-type breakdown); DSFinV-K is the rich export. RT signs a small documento commerciale; daily XML is the rich aggregate.
- **France — implicit split.** NF525 chains per-ticket + per-period totals; JET is a separate audit log; archive is a third artefact.

**For one engine to satisfy all four,** we adopt the strictest pattern (KSA) — **one rich canonical payload** containing every field reconstructible to any regime's compliance export. Country-specific signature/export adapters project from canonical → their respective minimal-signing or rich-export formats:

- **ZATCA adapter** — emits full UBL 2.1 XML from `(envelope + payload)`, C14N11-canonicalises, signs with ECDSA, embeds in XAdES B-B.
- **DE TSE adapter** — extracts minimal `processData` from canonical payload (`VAT_total per rate` + `payment_total per type`) before TSE signing; DSFinV-K export adapter writes Bonkopf/Bonpos/TSE_Transaktionen CSVs from the rich canonical.
- **IT RT adapter** — projects documento commerciale fields from canonical; daily XML aggregator pulls Riepilogo from canonical history.
- **NF525 adapter** — JET export reads from canonical; per-ticket chain is the engine's existing SHA-256 chain (canonical_bytes-based).

This pattern is consistent with SoT v3 §13.6 + D16 (bounded-modules asymmetric seam): the engine publishes ONE event; country-specific bridges/adapters consume. No country adapter requires modifying the engine.

---

## 4. Envelope vs payload mapping (Codex round-1 B2 closure)

Codex correctly flagged that Candidate B has no explicit place for ZATCA's `PIH`, `ICV`, and `cbc:ID`. The clean mapping:

| ZATCA UBL field | AutoERP source | Layer |
|---|---|---|
| `cbc:ID` (invoice number for B2C Simplified) | `payload.receipt_uuid` | Payload (device-authored) |
| `cbc:UUID` | `payload.receipt_uuid` (same field — Codex P1.7) | Payload |
| `cac:AdditionalDocumentReference[ID='PIH']` | `fiscal_events.previous_hash` (base64-encoded at export) | Envelope |
| `cac:AdditionalDocumentReference[ID='ICV']/cbc:UUID` | `fiscal_events.sequence_number` (stringified at export) | Envelope |
| `cbc:IssueDate` + `cbc:IssueTime` | `payload.event_time_device` (ISO 8601 split at export) | Payload |
| `cbc:InvoiceTypeCode` | derived from `payload.invoice_type_code` + `payload.invoice_subtype_code` | Payload |
| All seller / buyer / line / total / VAT / payment fields | Direct payload fields | Payload |

**Architectural lock:** `cbc:ID` for ZATCA Simplified Invoices = `payload.receipt_uuid` (device-authored). `cbc:UUID` reuses the same field. Server-allocated `pos_receipts.receipt_number` (Task 21's projection) is NOT used as `cbc:ID` — that would be a D1/D3 violation (server allocating a field inside the signed-by-device UBL).

For NF525 archive `id` + DSFinV-K `BON_ID` + IT documento commerciale numero: same source. One `receipt_uuid` field, all regimes.

---

## 5. Candidate C-revised — proposed canonical payload (sorted lexicographically per Codex P3)

```ts
export interface SaleReceiptPayload {
  business_date: string;               // YYYY-MM-DD
  buyer: BuyerBlock | null;            // sale-time snapshot, D16-safe — see §6
  cashier_id: string;                  // UUID
  cashier_name: string;                // DSFinV-K BEDIENER_NAME mandatory
  consumption_mode: 'dine_in' | 'takeaway' | null;
  currency_code: string;               // ISO 4217 ("EUR", "SAR", "TND", "GBP", "USD")
  currency_scale: number;              // 0|2|3
  event_time_device: string;           // ISO 8601 with ms + timezone offset
  invoice_subtype_code: 'STANDARD' | 'SIMPLIFIED' | null;  // KSA; null for non-KSA
  invoice_type_code: 'SALE' | 'REFUND' | 'VOID' | 'TRAINING';
  line_items: Array<{
    gtin: string | null;               // DSFinV-K Bonpos GTIN
    line_discount_amount: string;      // bcformat per currency_scale
    line_discount_reason: string | null;
    line_subtotal: string;             // net (before VAT)
    line_vat: string;
    name: string;
    non_collected_subtype: 'servizi' | 'beni' | 'omaggio' | 'successiva' | null;
    product_id: string;                // internal UUID
    quantity: string;                  // bcformat per quantity_scale
    sku: string;
    tax_category_code: string;         // S/Z/E/O (KSA) ≡ N1-N7 (IT); empty string = standard. UNIFIED axis per Codex P1.6.
    unit_price: string;
    vat_rate: string;                  // bcformat percentage
  }>;
  lottery_code: string | null;         // IT codice lotteria
  notes: string | null;
  original_receipt_reference: {        // refund / void — null otherwise
    fiscal_event_id: string;           // UUID of original SALE_RECEIPT fiscal event
    original_business_date: string;    // YYYY-MM-DD
    original_receipt_uuid: string;     // cross-reference
    refund_reason: string;
  } | null;
  payments: Array<{
    amount: string;                    // bcformat in currency_code
    foreign_currency_amount: string | null;  // DSFinV-K ZAHLWAEH_BETRAG
    foreign_currency_code: string | null;    // DSFinV-K ZAHLWAEH_CODE (ISO 4217)
    instrument_serial: string | null;
    instrument_type: string | null;    // voucher / card / etc.
    method_code: string;               // UN/ECE 4461 mapped (KSA cac:PaymentMeans/cbc:PaymentMeansCode)
  }>;
  receipt_uuid: string;                // device-authored UUID — single field replaces both receipt_local_id and the separate KSA UUID per Codex P1.7
  seller: {                            // REQUIRED — KSA stamp scope + DE EKaBS receipt
    address: { city: string; country_code: string; postal_code: string; street: string };
    name: string;
    tax_jurisdiction_country_code: string;  // ISO 3166-1 alpha-2 ("FR","SA","DE","IT","TN")
    tax_number: string;                // KSA 15-digit, FR SIRET/SIREN, IT P.IVA, DE USt-ID
  };
  shift_id: string;                    // UUID
  subtotal: string;                    // bcformat — net of VAT
  table_id: string | null;             // hospitality (DSFinV-K ABRECHNUNGSKREIS)
  terminal_id: string;                 // UUID
  total: string;                       // bcformat — gross (incl. VAT)
  training_flag: boolean;
  transaction_discount_amount: string; // bcformat — "0" when none
  transaction_discount_reason: string | null;
  vat_breakdown: Array<{
    gross_amount: string;
    net_amount: string;
    rate: string;                      // bcformat % ("20.00", "5.50", "0.00")
    tax_category_code: string;         // SAME field as line-level (Codex P1.6 — one axis, both layers)
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
  codice_fiscale: string | null;       // IT — distinct from tax_number
  contact_id: string | null;           // internal contact UUID — sale-time snapshot only
  customer_id: string | null;          // internal customer UUID — sale-time snapshot only
  name: string | null;
  tax_number: string | null;           // KSA 15-digit, IT P.IVA, DE USt-ID
}
```

**PAYLOAD_KEYS list (top-level, sorted lexicographically — Codex P3):**

```
[
  "business_date", "buyer", "cashier_id", "cashier_name", "consumption_mode",
  "currency_code", "currency_scale", "event_time_device", "invoice_subtype_code",
  "invoice_type_code", "line_items", "lottery_code", "notes",
  "original_receipt_reference", "payments", "receipt_uuid", "seller", "shift_id",
  "subtotal", "table_id", "terminal_id", "total", "training_flag",
  "transaction_discount_amount", "transaction_discount_reason", "vat_breakdown",
  "vat_total", "vouchers_redeemed"
]
```

**28 top-level keys** (vs v1 synthesis's 29 — one fewer after collapsing `receipt_local_id` + `receipt_uuid` per Codex P1.7).

**EXCLUDED — server-derived or envelope-level (never put in payload):**
- `receipt_number` (server-projected at Task 21 — for non-ZATCA business display; not the ZATCA cbc:ID).
- `fiscal_event_id` (envelope identity; only used in `original_receipt_reference` to reference a PRIOR event).
- `fiscal_hash` / `previous_hash` / `sequence_number` (envelope-level — see §4 mapping table).
- VAT-breakdown hashes / Payment-methods hash (Task 21 R2 computed real values — server-derived mirror columns).

---

## 6. D16-safe buyer block invariant (Codex P1.4 closure)

The buyer block is permitted in the canonical payload subject to the following invariants — these MUST be tested:

1. **Sale-time snapshot.** Buyer data is captured at engine.append() time from POS-local mirror data (already-mirrored customer/contact entities cached on device) OR from operator-entered ad-hoc data (B2C). The engine NEVER calls customer/B2B modules at append time.
2. **No projection-time enrichment.** `PosCoreReceiptProjection::apply()` reads buyer data from the parsed payload only. It does NOT call customer/contact lookups, B2B module reads, or any other module's operational runtime.
3. **Internal IDs are references, not authoritative.** `buyer.customer_id` and `buyer.contact_id` reference POS-local mirror rows for cross-linkage; if those rows are later deleted/changed, the sealed buyer snapshot is authoritative for the receipt.
4. **Projection test.** A new Task 21 test must seal a SALE_RECEIPT with `buyer.customer_id = X`, then delete the customer row, then re-run projection — assert projection succeeds using payload-snapshot data, no DB lookup attempted.
5. **D16 enforcement test.** A grep test in CI asserts `PosCoreReceiptProjection.php` does not import any class from `App\Modules\Customer`, `App\Modules\Contact`, `App\Modules\B2B`, `App\Modules\Treasury` (the bounded-modules guard).

---

## 7. Cross-task amendment scope

### 7.1 Task 14 — `FiscalPayloadConstraintValidator`

- `PAYLOAD_KEYS['SALE_RECEIPT']` expanded to the 28-key list.
- `validateSaleReceiptPayload` expanded: nested object shape (seller, buyer, original_receipt_reference); regex validation on UUIDs / dates / timestamps / hex / money fields; enum checks on `invoice_type_code` / `invoice_subtype_code` / `consumption_mode` / `non_collected_subtype`; ISO 3166-1 alpha-2 regex; per-regime tax-number patterns (KSA 15-digit, IT 11-digit P.IVA, DE USt-ID).
- Per Codex P2.13: golden vectors must cover nested objects, multi-key seller/buyer/original_receipt_reference, two line_items with multiple keys in reverse order, all VAT breakdown rows, null optionals, non-ASCII strings.

### 7.2 Task 16 — `StrictCanonicalParser`

- PAYLOAD_KEYS already shared via `FiscalPayloadConstraintValidator` (Task 24 R2 single source). No duplication.
- Per-event constraints expanded.
- Forensic failure prefixes follow existing `<snake_case>:<context>` convention.

### 7.3 Task 21 — `PosCoreReceiptProjection`

Per Codex P2.12: projector reads from `$event->payload` JSONB (the parser-derived, OutboxIngestor-stored shape) — NOT from `canonical_bytes` directly. D1 compliance is via parser provenance: `payload = StrictCanonicalParser::parse(canonical_bytes)` is the only path; OutboxIngestor stores `payload = NULL` on parse failure (Task 19).

- `pos_receipts` column expansion: minimal — most new fields default to canonical-only sourcing per Codex P2.15 (no new column unless a query/export/report consumer is named).
- Known new column candidates pending consumer-citation review:
  - `pos_receipts.invoice_type_code` — likely needed for query/filter; CITE: NF525 archive query splits by type.
  - `pos_receipts.training_flag` — likely needed for query exclusion; CITE: every report excludes training.
  - All others (seller, buyer, gtin, tax_category_code, non_collected_subtype, lottery_code, original_receipt_reference) — canonical-only by default.
- Projection tests: extend `PosCoreReceiptProjectionTest` with new payload-field projections; preserve existing payment + voucher + stock matrix.
- D16 enforcement tests per §6.

### 7.4 Task 25 — Cross-language drift gate

- TS `SALE_RECEIPT_PAYLOAD_KEYS` constant byte-mirrors PHP 28-key list.
- Existing pattern from Task 25 R3.

### 7.5 Task 4 — Golden vectors

Per D4 (Q1 = v1 rewrite in place, no production tenants): existing v3 fixtures DELETED. v4 fixtures generated from the new Candidate C-revised shape.

Per Codex P2.13 + P1.10: TS encoder is the producer; PHP parser/validator is the consumer. Golden vectors are hand-authored canonical strings; TS encoder must produce byte-identical output; PHP parser must accept exactly those bytes and reject drift.

Fixture coverage matrix:
- Single-payment, no buyer, no discount, single line, single VAT rate → baseline.
- Split-payment with foreign-currency leg.
- Voucher-redemption with `voucher_code`.
- Multi-line with multi-key reverse-order assertions.
- Multi-VAT-rate breakdown with `tax_category_code` variants (standard / zero / exempt / out-of-scope).
- B2B buyer block (KSA Standard Invoice equivalent).
- IT `non_collected_subtype` + `lottery_code` variant.
- Refund/void with `original_receipt_reference`.
- Training-mode (`training_flag: true`).
- Hospitality (`consumption_mode='dine_in', table_id=X`).
- Non-ASCII names (Arabic, French accents, German umlauts).
- Null-optionals exhaustive (every nullable field set null in at least one fixture).

Each fixture verified against:
- TS encoder produces byte-identical canonical_bytes.
- PHP parser accepts and validates.
- TS validator rejects when extras added.
- PHP validator rejects when extras added.
- Cross-language drift gate compares TS+PHP key-set equality.

### 7.6 TS `FiscalEventEngine.SaleReceiptPayloadInput` + `validateSaleReceiptPayload`

- Interface expanded to the 28-key shape.
- Runtime validator switch expanded.

### 7.7 Plan v4 → v5

- §2144–2348 (Q1-Q6 owner-approved decisions) — A1, A2, A3, A4, A5, A6 amended per §10 below.
- Task 27B Pass 2 absorbs Task 28's `/pos/receipts/sync` retirement + the device-side legacy chain deletion.
- Task 28 reduced to backend feature-suite migration cleanup only.
- Add new deferred task: **Phase-2 mirror-column audit + drop** (per D5 — after Phase 2, audit consumers of `pos_receipts.fiscal_hash` / `previous_hash` / `chain_sequence` / `vat_breakdown_hash` / `payment_methods_hash`; drop unused columns; remove projector writes for any column with no remaining consumer).

### 7.8 Spec v7 → v8 (or inline §11 amendment to v7)

- §11 SALE_RECEIPT event: payload-shape contract section added — the 28-key list + per-field semantics + per-regime mapping table (§4).
- §11.x new: "Country-specific signature/export adapters" — names the pattern in §3, declares that adapters project from `(envelope + payload)` only, never call modules.
- §13.6 + D16 reinforced with the buyer-block invariant from §6.
- §17 test-locks: add cross-language drift gate for SALE_RECEIPT 28-key list.

Recommendation: cut as full v8 since this is a material §11 amendment + new §11.x section. Smaller v7-inline amendment risks subsequent reviewer disagreement on "what changed where."

---

## 8. "No dual chain" — concrete deletion list (per D2)

### Device-side (DELETE — these are the legacy chain code paths)

`apps/pos/src/lib/offline/receiptService.ts`:
- DELETE `computeV3FiscalHash`, `buildCanonicalPayload` (v3 hash assembly).
- DELETE imports of `computeFiscalHash` from `@/lib/fiscal/hashService`.
- DELETE direct writes to `terminal_state.last_hash` and `terminal_state.hash_sequence`.
- DELETE the v3 `fiscalSchemaVersion` branching (no longer dual).
- Engine.append() is the sole writer to `terminal_state.fiscal_event_*` columns.

`apps/pos/src/lib/fiscal/hashService.ts`:
- DELETE if no consumers. If other paths (Z-report?) still use it, move to `apps/pos/src/lib/legacy/` with a single-purpose name and a deprecation docblock; out of Pass 2 scope to retire those consumers.

`apps/pos/src/lib/fiscal/v3/`:
- DELETE entire directory.

`apps/pos/src/lib/sync/syncService.ts`:
- DELETE the `/pos/receipts/sync` POST path.
- REPLACE with `/pos/sync/fiscal-events` push (Task 20's endpoint).

`apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts`:
- Stop writing `fiscal_hash` IF no on-device readers remain. Keep column briefly for backward-compat if any reader exists.

`apps/pos/src/lib/db/repositories/terminalStateRepository.ts`:
- `upsertTerminalState` adds write-once mirror to `fiscal_event_genesis_seed` per A2.
- Legacy `last_hash` / `hash_sequence` write paths DELETED.

### Server-side (DELETE — `/pos/receipts/sync` route + consumer)

`apps/api/app/Modules/POS/Presentation/Controllers/SyncController.php`:
- DELETE the `/pos/receipts/sync` handler.

`apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php`:
- DELETE `sync()` consumer entry-point. Other methods called elsewhere may stay.

`apps/api/app/Modules/POS/Application/DTOs/SyncReceiptPayload.php`,
`apps/api/app/Modules/POS/Presentation/Requests/SyncReceiptsRequest.php`,
`apps/api/app/Modules/POS/Application/DTOs/SyncReceiptResult.php`:
- DELETED.

`apps/api/app/Modules/POS/routes.php`:
- DELETE `POST /pos/receipts/sync` route definition.

`apps/api/tests/Feature/POS/SyncControllerTest.php` + §14.1 listed feature suites:
- Per-method skips citing Pass 2's retirement OR deletion entirely.

### Server-side mirror columns (KEEP — projector continues to mirror per D5)

`pos_receipts.fiscal_hash`, `previous_hash`, `chain_sequence`, `vat_breakdown_hash`, `payment_methods_hash`:
- KEEP columns. KEEP projector writes from canonical-bytes-derived data.
- **NEW DEFERRED TASK** added to plan: "Phase-2 mirror-column audit + drop." After Phase 2 ships, audit every SQL/code consumer of these columns. Drop columns with no remaining consumer. Per D5: "no dead code/unnecessary code when we go live."

`terminal_state.last_hash` / `terminal_state.hash_sequence` (device-side):
- KEEP columns briefly (low cost). Stop writing per D2. Same Phase-2 cleanup task drops them.

### CI gates

- §14.3 chokepoint gate at `apps/api/scripts/check-saleReceipt-chokepoints.sh`: post-Pass-2, `retired_in_task` markers in `saleReceipt-chokepoint-manifest.json` flip to `retired_in_task: "Task 27B Pass 2"` for the `/pos/receipts/sync`-related entries.
- CI PG-merge-gate filter at `.github/workflows/ci.yml:~404`: extended with new test classes (`TerminalClaimGenesisSeedMirrorTest`, `Nf525CanonicalPayloadV4Test`, `SaleReceiptCrossLanguageDriftTest`).

---

## 9. Pass 2A (contract) + Pass 2B (assembler) — feature-flag-gated split (per D6 / Codex P1.9)

**Feature flag:** `FISCAL_SALE_RECEIPT_CONTRACT_V4` (server-side via `config/fiscal.php`) + `fiscalSaleReceiptContractV4` (device-side via tauri config). Default OFF in production; ON in CI + dev.

### Pass 2A — contract land (gated)

Scope:
- PHP: Task 14 PAYLOAD_KEYS expansion + validator. Task 16 parser. Task 21 projector mapping (canonical-only by default).
- TS: PAYLOAD_KEYS mirror + drift gate update + engine validator expansion.
- Task 4: golden vectors regenerated.
- Spec v8 cut + §11 + §11.x.
- Plan v5 amendment of §2144-2348.
- All gated behind `FISCAL_SALE_RECEIPT_CONTRACT_V4`:
  - When flag OFF: parser accepts the old 10-key shape; validator runs the old per-event checks; legacy fixtures still pass.
  - When flag ON: parser accepts only the new 28-key shape; validator runs new per-event checks; new fixtures pass.
- Single atomic commit. CI runs both flag states (the legacy path stays alive until Pass 2B retires it).
- Device assembler still emits the old 10-key shape; production reach to the new path is blocked by flag default OFF.

### Pass 2B — assembler refactor (flips the flag)

Scope:
- Device receiptService refactor — emits Candidate C-revised 28-key shape via engine.append().
- Engine singleton wiring (async per-companyId) per amended A1.
- TerminalController::claim response field already present (per amended A2 — no PHP edit; device upsertTerminalState mirrors seed write-once).
- Legacy chain deletion per §8.
- Test migration per amended A6 (Hybrid: ~600-700 LOC mocks + ~300-400 LOC integration + ~400-500 LOC deletion).
- Flag flips from OFF to ON in `config/fiscal.php` defaults.
- Production assembler now reaches the new path.

### Why this split is safe

- Pass 2A is gated atomically. The contract is unreachable in production until 2B.
- Pass 2B is a simpler refactor of one entry point because the contract is already locked.
- Each pass's review-round cost is bounded (estimate: 2A = 3-4 rounds, 2B = 2-3 rounds).
- Combined 5-7 rounds vs. estimated 6+ for a single-atomic Pass 2; same review cost, smaller per-PR blast radius.
- The flag also serves as a safety net for the post-merge weeks: if a deployed Pass 2A surfaces a defect not caught in review, the flag stays OFF and engineering has time to fix without rolling back the whole branch.

---

## 10. Amended A1–A6 (final form, ready for plan §2144-2348)

### Amended A1 — `FiscalEventEngine` singleton

`getFiscalEventEngine(companyId: string): Promise<FiscalEventEngine>` — async, per-companyId-keyed memoised factory at `apps/pos/src/lib/fiscal/instance.ts`. Mirrors existing `getDatabase(companyId)` precedent. Singleton rebuilds when companyId changes. Test reset helper `__resetFiscalEventEngineForTesting()` mirrors `__resetDatabase()`.

Construction wires the 4 explicit FiscalEventEngine constructor args:
```ts
new FiscalEventEngine(
  await getDatabase(companyId),     // defaultDb
  new FiscalEventCanonicalEncoder(),
  new HashChainIntegrityProvider(),
  getFiscalEventPayloadRegistry(),  // module-scope memoised
);
```

### Amended A2 — `fiscal_event_genesis_seed` provisioning

`genesis_seed` is ALREADY in `TerminalResource:42` and reaches device via `/pos/terminals/claim` AND `/pos/terminals/{id}` (pullTerminalState). **No PHP edit required.**

Device: extend `upsertTerminalState` (terminalStateRepository.ts:134-191) to mirror legacy `genesis_seed` into v37 `terminal_state.fiscal_event_genesis_seed` — **write-once** (`SET fiscal_event_genesis_seed = ? WHERE id = ? AND (fiscal_event_genesis_seed = '' OR fiscal_event_genesis_seed IS NULL)`). Never overwrite non-empty (chain-restart protection).

Re-claim with mismatched seed: throws new `ChainGenesisSeedConflictError`. Operator must wipe terminal_state via Task 25 `ChainRecoveryService` before re-claiming.

Tests: failing test asserts `engine.append()` throws `ChainHeadNotInitializedError` when seed is empty.

### Amended A3 — `tenant_id` + `company_id` source

From `useTerminalStore.activeTerminal.{tenantId, companyId}` (`pos_terminals.tenant_id` per Task 19 envelope-validation).

`OfflineReceiptInput` gains required `tenantId: string` + `companyId: string`. `paymentStore.createReceiptLocalFirst()` reads from active terminal; throws new `ActiveTerminalRequiredError` if no active terminal. No reading from `useAuthStore` inside `receiptService` (CLAUDE.md rule 13).

### Amended A4 — Canonical SALE_RECEIPT payload shape

**Candidate C-revised** — the 28-key cross-regime superset per §5 above. Implementation at `apps/pos/src/lib/fiscal/payloads/SaleReceiptPayload.ts`.

Pass 2A cross-task amendments per §7. v1 rewrite in place per D4 — no event_version bump; every v1 fixture/test regenerated atomically.

D16 buyer-block invariants per §6 — sale-time snapshot only; no projection-time enrichment; CI grep asserts no cross-module imports in `PosCoreReceiptProjection`.

`bcformat` parity: TS implementation at `apps/pos/src/lib/fiscal/bcformat.ts` mirroring PHP `CurrencyScale::bcformat($value, $scale)` byte-for-byte. Golden vectors per §7.5 fixture matrix.

### Amended A5 — Transactional boundary

Single SQLite transaction wraps engine.append() + projector writes (device-side):
1. `engine.append({event_type: 'SALE_RECEIPT', payload: <Candidate C-revised>, …})` — inserts `fiscal_events` row + advances `terminal_state.fiscal_event_chain_head` + `fiscal_event_sequence_number`.
2. `INSERT offline_receipts` (business-document mirror; `offline_receipts.canonical_bytes` MIRRORS `fiscal_events.canonical_bytes`).
3. UPDATE vouchers (decrement balances) per `vouchers_redeemed[]`.

NOT device-side:
- `pos_receipt_lines` / `pos_receipt_payments` are SERVER-side tables (Task 21 writes them on projection). Device stores lines + payments as JSON columns on `offline_receipts`.
- Stock decrement: not currently in `createOfflineReceipt`; deferred to separate task.

Wrap via existing raw `BEGIN/COMMIT/ROLLBACK` (Tauri SQLite has no async withTransaction helper; engine.append() runs inside caller's transaction per existing contract).

Rollback semantics: any sub-write failure aborts entire tx; engine idempotency (`source_event_class` + `source_event_id`) prevents double-author on retry.

Legacy chain DELETED per §8. Engine is sole writer to `terminal_state.fiscal_event_*` columns. Source-level guard tightened per Codex P2.14: AST-aware or scoped-regex check that `receiptService.ts` contains a non-comment `.append(` call on a `FiscalEventEngine`-typed value; forbid `computeReceiptHash` / `computeV3FiscalHash` / `computeFiscalHash` / raw `UPDATE terminal_state SET (last_hash|hash_sequence)` SQL.

### Amended A6 — Test migration strategy

Hybrid per plan A6 + adjustments:

**Bucket 1 — KEEP as unit tests (mocks)** — ~600-700 LOC:
- Cart-line aggregation, voucher dedup, currency-scaling helpers, line-item discount logic, payload-assembly correctness.
- Tests mock `FiscalEventEngine.append` and assert Candidate C-revised payload shape passed to it.

**Bucket 2 — MIGRATE to `SqliteTestAdapter` integration tests** — ~300-400 LOC (expanded matrix):
- Engine-append + projector-write tx (single-payment happy path).
- Split-payment with foreign-currency leg.
- Voucher-redemption + voucher dedup.
- Atomic rollback on voucher-update failure / unique-key collision on `offline_receipts` / cross-tenant guard.
- Idempotency on retry.
- Genesis-seed-empty rejection (`ChainHeadNotInitializedError`).
- Cross-tenant guard (active terminal tenant mismatch).
- Refund/void path with `original_receipt_reference`.
- Training-mode (`training_flag: true`).
- D16 buyer-block snapshot invariant (delete customer row mid-projection).

**Bucket 3 — DELETE** — ~400-500 LOC:
- `computeReceiptHash` / `computeV3FiscalHash` / `buildCanonicalPayload` internal tests.
- Tests asserting `terminal_state.last_hash` / `hash_sequence` via direct SQL inspection.
- v3-specific hash chain shape tests.
- Legacy `offline_receipts.fiscal_hash` shape tests.

**Source-level guards (extending Pass 1's regex per Codex P2.14):**
- AST-aware (or scoped-regex) check: `receiptService.ts` contains `engine.append(` OR `await getFiscalEventEngine(...).append(` patterns; receiver typed/imported as FiscalEventEngine.
- Forbid `computeReceiptHash`, `computeV3FiscalHash`, `computeFiscalHash`.
- Forbid raw `UPDATE terminal_state SET (last_hash|hash_sequence|chain_sequence)` SQL.

---

## 11. Parse-failure resolution UX (Codex P1.8 deferral)

Expanding PAYLOAD_KEYS to 28 means `ParseFailureResolutionService::resolve()` (Task 24 R2) requires operators to supply a full corrected payload that passes DTO + key-set + per-event constraints. With 28 keys including nested objects, this becomes operator-hostile.

**Decision: DEFER to a new follow-up task.** The existing `ParseFailureResolutionService` remains correct for the new schema (it validates against the same `FiscalPayloadConstraintValidator`); the UX burden of crafting a 28-key payload is acceptable in Phase 1 because:
- Parse failures are rare (StrictCanonicalParser is strict; quarantine partition is for systemically corrupt inputs).
- Phase 1 has no large-scale operator-correction tooling; Phase 2+ will add admin tooling.
- A pre-fill workflow (best-effort parse → fill the structurally-valid fields → operator amends only the broken fields) is the natural Phase 2 add.

**New deferred task:** "ParseFailureResolution operator UX — pre-fill from best-effort parse OR partial-supplement semantic." Out of Pass 2 scope.

---

## 12. Cross-language drift framing (Codex P1.10 closure)

Per SoT v3 §1 + line 94: PHP NEVER re-serializes device-authored events. So the load-bearing risk is NOT "TS+PHP byte-match each other" — it's:

- **TS encoder must produce canonical_bytes byte-identical to hand-authored golden vectors.**
- **PHP parser must accept exactly those bytes and reject all drift.**

The cross-language drift gate test asserts:
- TS `SALE_RECEIPT_PAYLOAD_KEYS` constant has the same set as PHP `FiscalPayloadConstraintValidator::PAYLOAD_KEYS['SALE_RECEIPT']`. Set equality, not byte equality.
- For each golden vector: TS encoder output (canonical_bytes) matches the hand-authored expected string AND PHP parser/validator accept it (no errors).
- For each negative fixture (extras / wrong types / etc.): PHP parser rejects with the expected failure prefix.

There is no scenario where PHP re-serializes a payload for chain integrity. The only PHP re-serialization is for server-authored events (Task 26 §11.0 carve-out) — separate concern.

---

## 13. Open questions surfaced this round (need owner sign-off before Pass 2A dispatch)

**Q-revised-1 — Confirm Candidate C-revised (28 keys) as the canonical contract?** Recommendation: yes.

**Q-revised-2 — Spec v8 cut vs §11 inline amendment to v7?** Recommendation: full v8 cut — material §11 amendment + new §11.x country-adapter section justify version increment.

**Q-revised-3 — Pass 2A vs Pass 2B sequencing in the plan?** Pass 2A first per D6; recommend Pass 2A starts after this synthesis lands + plan amendment + spec v8. Pass 2B follows after Pass 2A merges to feat branch (NOT to main; merge to main is single-atomic at end of Phase 1).

**Q-revised-4 — Phase-2 mirror-column audit task slotting?** Recommendation: add to roadmap v2 §Phase-2 with explicit cite to D5 ("no dead code at go-live").

---

## 14. Scope + risk + effort estimate (revised)

- **Scope:** Pass 2A ~3-4K LOC (PHP + TS + spec + fixtures + plan). Pass 2B ~2-3K LOC (device refactor + legacy deletion + test migration).
- **Risk profile (revised after Codex round-1):** HIGH on Pass 2A (cross-task amendments + golden vector regeneration + drift gate update — same risk class as Task 30 three-rounder); MEDIUM on Pass 2B (well-scoped, single entry point, well-tested contract from 2A).
- **Expected review rounds:** Pass 2A = 3-4 rounds (per Task 30 precedent). Pass 2B = 2-3 rounds (per Task 27 Pass 1 precedent for well-scoped refactor).
- **Total Pass 2 = 5-7 rounds** vs. 6+ for single-atomic. Same review cost; safer per-PR blast radius; flag-gated production isolation.

---

## 15. Status

- v1 synthesis: superseded.
- This v2: pending Codex round-2 adversarial review + owner sign-off.
- After Codex round-2 APPROVE/APPROVE-WITH-MINOR-EDITS: plan §2144-2348 amended in place; spec v8 cut; Pass 2A implementer dispatched.

**End of synthesis v2.**
