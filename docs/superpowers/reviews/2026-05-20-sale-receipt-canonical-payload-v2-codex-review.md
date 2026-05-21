# Executive Summary

Round 1 is only partially closed. v2 materially improves the sourcing and the 28-key contract, but it still leaves load-bearing ambiguities around ZATCA Standard Invoice identity, feature-flag synchronization, event_version/version-check consequences, existing 10-key fixture fallout, adapter scope, and canonical-only export reads. The remaining issues are not stylistic: several can make Pass 2A green in isolation while leaving Pass 2B or country export work to fail on paths v2 does not enumerate.

# Round-1 Closure Verification

- B1: CLOSED. The research doc now contains literal URL sources for the three cited regimes: NF525 v2.1 payment/operator claims are stated at `docs/superpowers/research/2026-05-20-multicountry-fiscal-research.md:20` with Crisalid/Dixisoft URLs at `:235` and `:237`; DSFinV-K `BEDIENER_NAME` appears in the mandatory Bonkopf field list at `:91-92` with the DSFinV-K URL at `:229`; ZATCA per-invoice UUID is stated at `:80` with ZATCA URLs at `:226-227`.
- B2: NOT-CLOSED. v2 maps `cbc:ID`, `cbc:UUID`, PIH, ICV, IssueDate/IssueTime, and InvoiceTypeCode at `docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v2.md:62-69`, but collapses seller/buyer/line/VAT/payment into one generic row at `:70`. It does not name the requested XPaths for `cac:TaxSubtotal`, `cac:InvoiceLine`, or `cac:PaymentMeans`; missing XPath means not closed.
- B3: PARTIALLY-CLOSED. v2 explicitly states v1 rewrite-in-place/no event_version bump at `docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v2.md:417`, and says existing v3 fixtures are deleted and v4 regenerated at `:231`. It does not address the concrete registry/parser consequence: `FiscalEventPayloadRegistry` still maps `SALE_RECEIPT` to version 1 at `apps/api/app/Modules/Fiscal/Application/Services/FiscalEventPayloadRegistry.php:37-39`, while `StrictCanonicalParser` rejects any envelope whose `event_version` differs from the registry at `apps/api/app/Modules/Fiscal/Application/Services/StrictCanonicalParser.php:515-526`.
- P1.4: PARTIALLY-CLOSED. v2 adds D16 invariants and checks more than `App\Modules\Customer` by naming Customer, Contact, B2B, and Treasury imports at `docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v2.md:190-194`. The grep guard still only checks direct module imports and does not catch indirect coupling through `App\Shared\Contracts` interfaces or container-resolved services.
- P1.5: CLOSED. NF525 v2.1 operator/payment-method claims are tied to vendor references: the claim is at `docs/superpowers/research/2026-05-20-multicountry-fiscal-research.md:20`, and the vendor URLs are listed at `:235-237`.
- P1.6: NOT-CLOSED. v2 unifies the name to `tax_category_code` on line and breakdown rows at `docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v2.md:103` and `:142`, but it does not state the rationale for carrying the same semantic field at both layers or the invariant when line categories aggregate into a breakdown row.
- P1.7: PARTIALLY-CLOSED. v2 maps `receipt_uuid` to both ZATCA Simplified `cbc:ID` and `cbc:UUID` at `docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v2.md:64-65` and locks that for Simplified invoices at `:72`. It does not address Standard/B2B invoices; the in-tree ZATCA sample distinguishes sequential `cbc:ID` from UUID at `docs/new_docs/03-MODULE-SPECS/hash-chain-fiscal-compliance-spec.md:417-419`, while v2 also includes a B2B buyer fixture at `docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v2.md:241`.
- P1.8: PARTIALLY-CLOSED. v2 acknowledges the 28-key corrected payload is operator-hostile at `docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v2.md:475-480`, but it explicitly defers the UX at `:482` and does not acknowledge the transition-period case where no operator tooling can supply the required 28-key object, making a parse failure practically unresolvable.
- P1.9: PARTIALLY-CLOSED. v2 names server and device flags at `docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v2.md:344`, defines OFF/ON behavior and CI both-state coverage at `:354-358`, and flips the flag in Pass 2B at `:368-369`. It does not define how the Tauri flag is delivered, synchronized with the server flag, or made safe during rollout.
- P1.10: CLOSED. v2 avoids the old "TS and PHP produce identical canonical_bytes" relapse: it states TS produces canonical bytes and PHP accepts/parses them at `docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v2.md:233` and reiterates the correct drift framing at `:488-498`.
- P1.11: PARTIALLY-CLOSED. The mirror-column cleanup list is accurate for the named columns at `docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v2.md:266` and `:328-330`, but v2 only recommends adding it to roadmap v2 at `:510`. It is not actually slotted into a roadmap document in the reviewed files.

# New Defects

## BLOCKER

No new round-2 blocker found. The remaining findings are P1/P2/P3, but the P1 set is enough to request changes.

## P1

### N-1 FEATURE FLAG DEAD PATH

Severity: P1.

What is wrong: v2 contradicts itself on whether Pass 2A is production-isolated or deployed behind a production-OFF flag. It says the new 28-key parser/validator is unreachable in production until 2B and production bugs can sit behind the OFF flag at `docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v2.md:344-358` and `:371-377`. Later it says Pass 2A merges only to the feature branch, not main, with a single atomic merge at the end at `:508`. Those are different rollout models. If Pass 2A can be deployed with the flag OFF, the new contract path is a dead production path until the flip; if it cannot be deployed, the feature flag rationale is overstated.

Fix: Choose one rollout model. If Pass 2A can reach production, add a staging/canary plan that runs real receipt ingestion with the flag ON before 2B and document rollback semantics. If Pass 2A is feature-branch-only, remove the "post-merge weeks" production-safety framing and make CI plus branch verification the stated safety mechanism.

### N-3 TASK 8 IMMUTABILITY VS SHAPE REWRITE

Severity: P1.

What is wrong: v2 says v1 is rewritten in place with no event_version bump and fixtures are regenerated at `docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v2.md:231` and `:417`. That does not address rows already inserted with 10-key or v3-shaped canonical bytes during mixed test runs or dev data. Server immutability allows `payload` to be written only in named parse-status transitions and never allows `canonical_bytes` replacement (`apps/api/database/migrations/2026_05_14_100002_create_fiscal_events_immutability.php:70-109`, `:180-197`). Device SQLite also blocks updates to `canonical_bytes` and every non-sync column (`apps/pos/src/lib/db/migrations.ts:1019-1045`). With registry version still at 1, old and new shapes are indistinguishable to the parser except by current key-set expectations.

Fix: Add a Pass 2A fixture/data transition section: no persisted `fiscal_events` rows may be reused across the flag boundary; tests must rebuild databases after fixture regeneration; any dev/staging rows with old canonical bytes must be explicitly discarded or quarantined. If any old row must survive, an event_version bump or compatibility parser is required.

### N-4 RECEIPT_UUID COLLAPSE VS B2B STANDARD INVOICE

Severity: P1.

What is wrong: v2 locks `cbc:ID` to `payload.receipt_uuid` for Simplified invoices at `docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v2.md:64` and `:72`, then broadens the same source to all regimes at `:74`. But the in-tree ZATCA sample shows invoice `cbc:ID` as a sequential invoice number and `cbc:UUID` as a UUID at `docs/new_docs/03-MODULE-SPECS/hash-chain-fiscal-compliance-spec.md:417-419`; the same spec distinguishes B2B Tax Invoice clearance from B2C Simplified reporting at `:390-395`. v2 also includes `invoice_subtype_code: 'STANDARD'` at `docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v2.md:90` and a B2B fixture at `:241`, so this is not clearly Phase-2-only.

Fix: Either declare ZATCA Standard/B2B out of Pass 2A/2B scope and forbid `invoice_subtype_code='STANDARD'` until a separate B2B contract lands, or add a separate canonical `invoice_id`/`invoice_number` field for Standard invoices while keeping `receipt_uuid` as `cbc:UUID`.

### N-7 TASK 14-19 FIXTURE SHAPE MISMATCH

Severity: P1.

What is wrong: v2 says Task 14-16 contract code and Task 19 ingestion are updated in Pass 2A at `docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v2.md:348-356`, but it does not survey the existing Task 14-19 feature tests that hard-code the 10-key shape. Examples: `OutboxIngestorTest::minimalSaleReceiptPayload()` documents and returns the old keys at `apps/api/tests/Feature/Fiscal/OutboxIngestorTest.php:807-840`; `ParseFailureResumeTest::correctedPayload()` does the same at `apps/api/tests/Feature/Fiscal/ParseFailureResumeTest.php:677-698`; `PosCoreReceiptProjectionTest` seeds old keys at `apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionTest.php:927-938`.

Fix: Add an explicit Pass 2A test migration inventory covering Task 14-19 files, not just golden vectors. Every helper named `minimalSaleReceiptPayload`, `correctedPayload`, or equivalent must either be regenerated to 28 keys under flag ON or pinned to legacy-only tests under flag OFF.

### N-10 DEVICE-SIDE FLAG SYNC

Severity: P1.

What is wrong: v2 names `FISCAL_SALE_RECEIPT_CONTRACT_V4` on the server and `fiscalSaleReceiptContractV4` in Tauri config at `docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v2.md:344`, but does not define how the device learns the server's accepted contract, how stale devices behave, or how CI proves mismatched flag states fail safely. This is dangerous because v2 explicitly makes parser acceptance shape depend on the flag at `:354-356`, while the device assembler continues emitting the old shape until Pass 2B at `:358`.

Fix: Add a contract negotiation rule. The terminal claim/pull response should carry `sale_receipt_contract_version` or equivalent, the device should persist it with terminal state, and sync should reject/hold events when the local assembler contract and server accepted contract disagree. CI needs matched and mismatched server/device flag-state tests.

## P2

### N-5 PER-COUNTRY TAX VALIDATION

Severity: P2.

What is wrong: v2 adds `seller.tax_jurisdiction_country_code` and `seller.tax_number` at `docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v2.md:127-128` and says the validator will check per-regime tax-number patterns at `:203`. The current validator is still only the 10-key money/list validator (`apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:68-73`, `:143-160`), so the synthesis is the only contract source for the future implementation. It does not spell out the actual patterns, does not cover FR/TN despite allowing those country codes, and does not say whether buyer tax numbers follow the same conditional rules.

Fix: Add a table keyed by `tax_jurisdiction_country_code` with exact regex/semantic checks for seller and buyer tax identifiers, including what is accepted for FR and TN. Add positive/negative golden fixtures per country.

### N-6 ADAPTER SCOPE EXPLOSION

Severity: P2.

What is wrong: v2 describes ZATCA, DE TSE/DSFinV-K, IT RT, and NF525 adapters as the architectural pattern at `docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v2.md:47-52`, and says spec v8 should add a country-adapter section at `:270-271`. But Pass 2A scope is contract/parser/projector/fixtures/spec/plan only at `:348-354`, while the effort estimate still frames Pass 2A as 3-4K LOC at `:516`. There is no explicit "adapter implementation is a separate task" boundary.

Fix: Add a dedicated adapter backlog section. State that Pass 2A only preserves data needed by adapters and does not implement ZATCA XML signing, DSFinV-K export, IT RT XML, or NF525 JET changes unless separately tasked.

### N-8 GTIN CANONICAL-ONLY READ PATTERN

Severity: P2.

What is wrong: v2 makes `line_items[].gtin` part of the canonical payload at `docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v2.md:93`, then says `gtin` is canonical-only by default at `:216-220`. The existing `pos_receipt_lines` table has no `gtin` column (`apps/api/database/migrations/2026_01_08_190638_create_pos_receipt_lines_table.php:38-58`), and `PosCoreReceiptProjection::writeLines()` does not store `gtin` (`apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:420-454`). Existing NF525 canonical read-back infrastructure only overrides monetary fields from `fiscal_events.payload` and says line-level data comes from projection because the old canonical contract did not carry it (`apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php:708-730`).

Fix: Add a canonical line-reader utility for export/report paths, or explicitly add `gtin` to projection storage. If `gtin` remains canonical-only, update NF525/DSFinV-K export tasks to read line arrays from `fiscal_events.payload` and test payload-only line fields.

### N-9 CONCURRENT RECEIPT SERIALIZATION / RETRY GAP

Severity: P2.

What is wrong: v2 puts `engine.append()` inside a caller-managed raw SQLite transaction at `docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v2.md:425-436`. The engine reads the chain head, computes `sequenceNumber = head + 1`, inserts, and only catches duplicate sequence as `ConcurrentChainAdvanceError` (`apps/pos/src/lib/fiscal/FiscalEventEngine.ts:356-372`, `:399-449`). v2 does not specify whether receiptService serializes checkout submissions, retries on `ConcurrentChainAdvanceError`, or treats it as a fatal sale failure. The result is not necessarily a database deadlock, but it is a concurrency contract gap at the exact receipt-sealing boundary.

Fix: Add a device-side serialization rule: one in-flight receipt seal per terminal, or catch `ConcurrentChainAdvanceError`, rollback, reread chain head, and retry with the same source idempotency key. Add an integration test with two concurrent checkouts against the same terminal.

## P3

### N-2 JSONB SIZE / DEPTH ACCEPTANCE CRITERIA

Severity: P3.

What is wrong: the server schema stores parsed payload in `fiscal_events.payload JSONB` at `apps/api/database/migrations/2026_05_14_100001_create_fiscal_events_table.php:83-85`, and v2 expands `SALE_RECEIPT` to 28 top-level keys with nested seller, buyer, line, payment, VAT, voucher, and original-reference objects at `docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v2.md:80-176`. This is probably fine for normal receipts, but v2 never defines a size/depth/line-count acceptance test, so a later implementation can claim shape correctness without proving storage and parser behavior on large real receipts.

Fix: Add a large-receipt fixture with realistic max line/payment/VAT counts and nested buyer/seller data, assert insert into JSONB, parse, projection dispatch, and export read-back. Document that no schema change is needed if the fixture passes.

# Verdict

Round 1 does not close cleanly. The document is close enough to salvage, but the P1s need a v3 edit before dispatching Pass 2A: resolve the rollout model, separate Standard/B2B invoice identity from Simplified receipt UUID semantics, inventory old 10-key tests/fixtures, define device/server flag sync, and state the immutability/data-transition rule for old canonical rows.

VERDICT: REQUEST-CHANGES
