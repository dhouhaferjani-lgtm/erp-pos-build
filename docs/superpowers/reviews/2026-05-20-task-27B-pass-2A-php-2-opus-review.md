# Task 27B Pass 2A.PHP.2 — Opus Spec-Compliance Review

**Reviewer:** Opus 4.7 (1M context)
**Date:** 2026-05-20
**Commit under review:** `d9cc8e250` on `feat/pos-fiscal-event-engine-phase1`
**Authoritative sources:** synthesis v5 §3 / §8.B / §10 / §11, spec v7 §11.2-§11.4, plan §2144 D1-D9, PHP.1 R1 `b6f143e1a` + R2 `ed17e022d` foundation, Codex R1 + R2 reviews, Opus PHP.1 review (3 P3 deferrals).
**Scope axis:** spec-compliance only. Code-quality findings deferred to Codex reviewer.

---

## Executive summary

PHP.2 cleanly lands the CONSUMER-MIGRATION layer of the 27-key canonical SALE_RECEIPT contract. Every one of the 10 dispatched scope items is closed with cited evidence; the 3 PHP.1 Opus P3 deferrals are all addressed (D16 grep widened with 4 Eloquent static-call regexes + Treasury back to the forbidden set; F-15 in-process double-construction determinism test added; F-15 SALE-with-null-reference resolved as the only legal interpretation per v5 §6 invariant). The PaymentMethodResolver seam at `App\Shared\Contracts\Fiscal\` is correctly placed on the asymmetric bounded-modules boundary (SoT §13.6/D16) — POS-core depends only on the contract; Treasury owns the Eloquent implementation; both projectors (POS-core + Treasury bridge) inject the contract via constructor; the binding lives in `TreasuryServiceProvider`. Nf525DataProvider bifurcates cleanly by `fiscal_event_id IS NOT NULL` for SALE / VOID / RETURN with three new `mapFromCanonical` surfaces and three preserved `mapLegacy` paths. All 9 test helpers migrated to the 27-key shape; 36 PHP.1 per-method skips removed (remaining 11 in-file skips are unrelated PG-only DDL skips). Constructor-injection-only honored across all 3 new production classes plus the two migrated consumers (zero `app()` / `App::make` / `resolve()` runtime calls). The only spec deviation I can identify is a P3-level coverage gap: synthesis v5 §8.B asks for **four** canonical-only round-trip tests (gtin, tax_category_code, foreign_currency_*, **original_receipt_reference**) plus the legacy regression guard — `Nf525ExportCanonicalRoundTripTest` lands the first three plus the legacy regression but does NOT include a dedicated `original_receipt_reference` round-trip. The refund-linkage surface is wired in `mapReturnReceiptFromCanonical` (lines 880-885) and indirectly tested via other fixtures, but a dedicated test would close the §8.B inventory. P3 only — not blocking.

---

## PHP.2 scope-compliance findings

| Spec item | Status | Evidence |
|---|---|---|
| 1. PaymentMethodResolver interface at `App\Shared\Contracts\Fiscal\` with signature `resolveByCode(string $tenantId, string $methodCode): ?string` | CLOSED | `apps/api/app/Shared/Contracts/Fiscal/PaymentMethodResolver.php:5` namespace; `:47` signature exact match. Docblock cites synthesis v5 §8.B + dispatch §0 Gap A; security stance documented (tenant-scoped, null = fail-closed, no silent cross-tenant match). |
| 2. EloquentPaymentMethodResolver at `App\Modules\Treasury\Infrastructure\` with `(tenant_id, code)` query | CLOSED | `apps/api/app/Modules/Treasury/Infrastructure/EloquentPaymentMethodResolver.php:5` namespace; `:33-36` query — `where('tenant_id', …)->where('code', …)` matches the schema's unique constraint at `2025_11_30_120000_create_treasury_tables.php:45`. QueryException wrapped → returns null (fail-closed); null model → returns null. |
| 3. TreasuryServiceProvider binding | CLOSED | `apps/api/app/Modules/Treasury/Providers/TreasuryServiceProvider.php:35-38` — `singleton(PaymentMethodResolver::class, EloquentPaymentMethodResolver::class)`. Bound at `register()` (correct lifecycle — early enough for projector construction). Docblock cites synthesis v5 §8.B + SoT §13.6/D16. |
| 4. PosCoreReceiptProjection field-read renames + new columns + PaymentMethodResolver | CLOSED | `PosCoreReceiptProjection.php`: `payload->vatTotal` (`:177`), `payload->lineItems` (`:285`), `payload->payments` (writePayments `:287`), `view->vouchersRedeemed` via redeemVouchers (`:288`), `payload->transactionDiscountAmount` (`:178`). New column writes — `invoice_type_code` (`:258`), `training_flag` (`:259`). `payment_method_id` resolved via `PaymentMethodResolver::resolveByCode` (`:584-587`); null → `RuntimeException` fail-closed (`:592-598`); same security stance as Task 21 R2 Opus F3. Zero direct Treasury imports — verified by D16 grep. |
| 5. Nf525DataProvider bifurcation per v5 §8.B | CLOSED | `Nf525DataProvider.php`: `mapSaleReceipt` bifurcation at `:597-609` (fiscal_event_id IS NOT NULL → `mapSaleReceiptFromCanonical` `:617`; else → `mapSaleReceiptLegacy` `:676`). `mapVoidedReceipt` at `:734-748` (`mapVoidedReceiptFromCanonical` `:751`; `mapVoidedReceiptLegacy` `:785`). `mapReturnReceipt` at `:818-832` (`mapReturnReceiptFromCanonical` `:835`; `mapReturnReceiptLegacy` `:897`). New `mapLineFromCanonical` (`:1005`), `mapPaymentFromCanonical` (`:1026`), `mapVatDetailFromCanonical` (`:1042`) read from canonical view; legacy `mapLineLegacy` (`:1057`), `mapPaymentLegacy` (`:1082`), `mapVatDetailLegacy` (`:1072`) preserved. CanonicalPayloadReader injected via constructor (`:80`). |
| 6. 9 test helpers migrated to 27-key | CLOSED | Grep `"27-key|Pass 2A\.PHP\.2|invoice_type_code|currency_scale|line_subtotal"` returns hits in all 9 named test files: OutboxIngestorTest (4), ParseFailureResumeTest (6), PosCoreReceiptProjectionTest (10), StrictCanonicalParserTest (38), FiscalEventIngestionEndpointTest (4), TreasuryReceiptBridgeTest (7), ApplyFiscalEventProjectionJobTest (4), ReceiptChainRebuildTest (4), FiscalEventPayloadRegistryTest (15). `StrictCanonicalParserTest::canonicalSaleReceiptPayload()` (`:617-680`) exhibits the canonical 27-key shape — sorted-lex keys, UUID identity fields, ISO 8601 ms+tz `event_time_device`, TND `currency_scale=3`. Python keylist count confirms exactly 27 sorted keys. |
| 7. 36 PHP.1 per-method skips removed | CLOSED | `grep -rn "markTestSkipped" apps/api/tests/{Feature,Unit}/Fiscal/ \| grep -E "PHP\.2\|Pass 2A"` returns 1 docblock reference (not a runtime skip). Total remaining `markTestSkipped` calls = 10, all PG-only DDL/trigger skips (PaymentsOriginColumnsTest, DeviceLossIncidentTest, FiscalEventQuarantineTableTest, ReceiptChainRebuildTest×3, FiscalEventsTableTest, PosReceiptsCanonicalBytesTest, FiscalEventProjectionsTableTest, FiscalEventsImmutabilityTest) — out of scope. Skip count 81 → 45 matches the 36 removal claim. |
| 8. D16 grep guard extended per Opus PHP.1 P3 #1 | CLOSED | `PosCoreReceiptProjectionD16Test.php:50-81`. Added: 4 Eloquent static-call regexes — `customer-static-call` (`/\bCustomer::/`), `contact-static-call` (`/\bContact::/`), `b2b-static-call` (`/\bB2B::/`), `treasury-payment-static-call` (`/\bTreasuryPayment::/`). Added: `treasury-module-direct` (`/\buse\s+App\\Modules\\Treasury\\/`) — Treasury BACK in forbidden set because PHP.2's Shared/Contracts seam closes Gap A. Docblock at `:25-37` is correctly worded — the rationale traces back to dispatch §0 Gap A pre-resolution. |
| 9. F-15 determinism test (Opus PHP.1 P3 #2 closure) | CLOSED | `FiscalPayloadConstraintValidatorTest.php:533-546` — `test_f15_large_receipt_generator_is_deterministic_via_double_construction` invokes `LargeReceiptFixtureGenerator::generate()` twice in the same process and asserts byte-equality on `GoldenFixtureBuilder::jcsCanonicalEncode($gen->payload)`. Docblock explicitly cites Opus P3 #2 closure. Locks the "no clock / RNG / global state" property. |
| 10. Nf525ExportCanonicalRoundTripTest canonical-only round-trips | PARTIAL | `apps/api/tests/Feature/Fiscal/Nf525ExportCanonicalRoundTripTest.php` exists with 4 test methods: `test_gtin_round_trips_from_canonical_payload_to_nf525_line_dto` (`:96`), `test_tax_category_code_round_trips_at_both_line_and_breakdown_level` (`:134`), `test_foreign_currency_amount_and_code_round_trip_on_payment_dto` (`:190`), `test_legacy_path_still_exports_when_fiscal_event_id_is_null` (`:220`). Synthesis v5 §8.B lines 309-314 enumerate FIVE tests: gtin, tax_category_code, foreign_currency_*, `original_receipt_reference`, legacy regression. The dedicated `original_receipt_reference` round-trip is missing from this file. The refund-linkage IS exercised on `mapReturnReceiptFromCanonical` lines 880-885 (`originalReceiptId` + `returnReasonValue` sourced from `view->originalReceiptReference`), but only via implicit side-paths in other tests. See Issue P3-1 below. |

---

## Standing-pattern adherence

- **Dead-path rebuild — PaymentMethodResolver interface has live callers.** Confirmed: PosCoreReceiptProjection injects it (`:117`) and calls `resolveByCode` twice (`:584-587` writePayment + `:831-834` computePaymentMethodsHash). TreasuryReceiptBridge injects it (`:123`) and calls `resolveByCode` once (`:333-336`). 3 live call sites; no dead-path warnings.
- **CLAUDE.md rule 13 (constructor injection only).** Grep `(\bapp\s*\(|App::make|\bresolve\s*\()` across all 3 new production files + 3 migrated consumer files returns ZERO matches. All dependencies on each class come through the constructor with `private readonly`. PosCoreReceiptProjection has 4 deps via ctor (`:114-118`); TreasuryReceiptBridge has 3 deps via ctor (`:121-124`); Nf525DataProvider has 5 deps via ctor (`:76-81`). Clean.
- **D16 invariant — projector doesn't import Treasury directly.** Grep `App\\Modules\\Treasury\\` on PosCoreReceiptProjection.php returns zero hits. The only Treasury surface the projector touches is `App\Shared\Contracts\Fiscal\PaymentMethodResolver` (line 31). D16Test grep guard locks this — line 62 (`treasury-module-direct` pattern) would fail if a future regression added the import.
- **D16 invariant on Nf525DataProvider — D16 grep does NOT extend to the Nf525 path.** Note: D16 grep only protects the projector. The Nf525 sub-inventory is a SECONDARY consumer; its bifurcated `mapFromCanonical` methods do touch `App\Modules\POS\Domain\*` (lines 13-27 — `CashDrawerOperation`, `GrandtotalEvent`, `Receipt`, `ReceiptLine`, etc.). These are intra-module dependencies, not D16 violations. Synthesis v5 §5 + §8.B do NOT require D16 grep on Nf525 — only the POS-core projector. Compliant.
- **v1 rewrite invariant (D4).** `FiscalEventPayloadRegistry.php` unchanged in this commit (not in the modified-file list). `event_version` stays at 1 per D4. Verified by absence in diff.
- **PHP.1 R2 helpers preserved.** PHP.2 doesn't modify `FiscalPayloadConstraintValidator`, `validateUuid`, `validateIsoDateTimeWithMs`, `CURRENCY_SCALES` allowlist, or the TRAINING-flag invariant — the validator file is NOT in the modified-file list (only the test file is, with the F-15 determinism addition). Helpers remain authoritative.
- **Idempotency anchor preserved.** `PosCoreReceiptProjection.php:143` retains the `Receipt::query()->where('fiscal_event_id', $event->id)->exists()` fast-path probe; the inner ON CONFLICT INSERT at `:307-322` retains the durable guard. The dual-fence pattern (fast probe + ON CONFLICT DO NOTHING RETURNING) is unchanged.
- **TreasuryReceiptBridge advisory-lock + dependency exception preserved.** Lines 255-260 (pg_advisory_xact_lock keyed on event_id + projector_name) + lines 228-234 (ProjectionDependencyMissingException on null receipt) — unchanged from Task 22 R2. PHP.2 only swapped the payment-line read path to canonical.

---

## Implementer design decisions assessment

**A. TreasuryReceiptBridge migrated alongside; repository_id resolved via "first tenant+company-scoped repository with non-null gl_account_id" heuristic.**

Sound. The synthesis v5 §3 27-key contract deliberately excludes per-payment `repository_id` (it's a Treasury-operational concern, not part of the audit seal). The heuristic at `TreasuryReceiptBridge.php:492-504` is `orderBy('id')->first()` — deterministic given that `payment_repositories.id` is a UUID v4 (lexicographic order is stable). For the common single-cash-drawer case (one repository per tenant+company) this is correct by construction. For multi-repository deployments the heuristic picks the lowest-UUID repository deterministically; this matches the legacy behaviour before §3 stripped repository_id (the pre-PHP.2 code read `repository_id` from the payload and validated it tenant-scoped; if absent or null, the legacy code path is essentially equivalent to "use the default"). Phase 1.5 deferral noted. No determinism risk — `orderBy('id')->first()` is replayable. No existing-test breakage risk — the migrated `TreasuryReceiptBridgeTest` exercises this path (per grep, 7 PHP.2 markers).

**B. return_reason canonical-vs-legacy split — projector writes ReturnReason::Other to legacy column; canonical free-text in payload is authoritative.**

Sound. The legacy `pos_receipts.return_reason` column is enum-typed (`ReturnReason`) and is gated by the `pos_receipts_return_logic` CHECK constraint requiring a non-null value for return-type rows. The canonical `original_receipt_reference.refund_reason` is free-text (synthesis v5 §3). The projector resolves the contradiction by writing `ReturnReason::Other` to the enum column (`:275`) — the audit-stable free text remains in `fiscal_events.payload.original_receipt_reference.refund_reason` and the Nf525DataProvider reads it via `view->originalReceiptReference?->refundReason` (`:662`, `:883`). The CHECK constraint stays satisfied; auditors can still query the free-text via the canonical reader. The legacy column's loss of information is a known consequence of the schema's enum constraint — not a fidelity regression. Acceptable.

**C. product_id FK resolution — canonical sealed snapshot vs legacy FK column.**

Sound. `resolveProductFk` at `:498-517` writes `pos_receipt_lines.product_id` only when (a) the canonical snapshot is a UUID AND (b) the matching `products` row still exists. Otherwise null. This honors the sealed-snapshot invariant: the canonical `line_items[].product_id` survives downstream `products` deletion (the FK column gets null, but the sealed snapshot in `fiscal_events.payload` remains authoritative). D16 invariant honored — the projector does NOT depend on the `products` table for correctness; it's a best-effort denormalization for legacy column readers. The stock-decrement path (`:701`) correctly skips non-UUID / no-match snapshots ("snapshot-survives-deletion semantics"). Sound.

---

## Issues

### P3-1 — Synthesis v5 §8.B canonical round-trip coverage missing `original_receipt_reference`

Synthesis v5 §8.B lines 309-314 enumerate FIVE Nf525ExportTest canonical-only assertions: (1) gtin round-trip, (2) tax_category_code round-trip (line + breakdown), (3) foreign_currency_* round-trip, (4) **original_receipt_reference round-trip for refund/void**, (5) legacy regression guard.

`Nf525ExportCanonicalRoundTripTest.php` lands (1), (2), (3), (5) — but does NOT land (4). The refund-linkage IS wired in `mapReturnReceiptFromCanonical` (`Nf525DataProvider.php:880-891`): `originalReceiptId: $view->originalReceiptReference->fiscalEventId`, `returnReasonValue: $view->originalReceiptReference->refundReason`. So the surface is correctly implemented; it just lacks a dedicated end-to-end round-trip test asserting that `payload.original_receipt_reference.{fiscal_event_id, refund_reason}` survive the export hop verbatim.

This is P3 because: (i) the canonical-only surface IS implemented, (ii) `FiscalPayloadConstraintValidatorTest::test_canonical_payload_reader_builds_original_receipt_reference_dto_on_refund` (`:726`) tests the READER side, and (iii) the bug class that the missing test would have caught — `originalReceiptReference` accidentally swapped or null-coalesced incorrectly in the bifurcated map — would surface in Phase 1.5 refund-flow integration tests. Not blocking.

**Suggested PHP.2 round-2 closure (cheap):** add a fifth test
```php
public function test_original_receipt_reference_round_trips_on_refund_receipt(): void
```
that constructs a SALE then a REFUND with full `original_receipt_reference` populated, builds the export snapshot, asserts `$returnEntry->originalReceiptId === $sale->fiscal_event_id` AND `$returnEntry->returnReasonValue === 'damaged_at_purchase'` (or whatever fixture value).

### P3-2 — PosCoreReceiptProjectionTest has no REFUND test case

Tangential to the dispatched scope (the spec items don't explicitly require it), but `PosCoreReceiptProjectionTest` does not exercise the `invoice_type_code='REFUND' + original_receipt_reference` path of `apply()`. Grep `REFUND` in the file returns only `handlesEventType(FiscalEventType::REFUND_RECEIPT)` rejection at `:239` (the projector explicitly handles only `SALE_RECEIPT`; canonical REFUND is a SALE_RECEIPT with `invoice_type_code='REFUND'`). The `resolveReceiptType` (`:397-404`) + `resolveOriginalReceiptId` (`:418-438`) paths are not directly tested.

P3 — synthesis v5 doesn't enumerate this as a required PHP.2 test, and PHP.1 §6.E case suite covers the validator's REFUND-without-reference rejection. The projector's REFUND branch is structurally simple (`if (REFUND|VOID && $originalReceiptId !== null) { return Return; } else { return Sale; }`), but a positive REFUND test would close the gap. Not blocking — can land in Phase 1.5 refund-flow integration tests.

---

## Verdict

All 10 dispatched PHP.2 scope items land cleanly with cited evidence. The 3 PHP.1 Opus P3 deferrals are closed verbatim. Standing patterns (dead-path rebuild, constructor injection, D16 grep, idempotency anchor, advisory lock, v1 rewrite) all preserved. The 3 implementer design decisions (TreasuryReceiptBridge default-repository heuristic, return_reason canonical-vs-legacy split, product_id FK resolution) are sound and audit-safe. The only spec deviation is a P3 coverage gap on the synthesis v5 §8.B `original_receipt_reference` canonical round-trip test (the surface IS implemented — only the dedicated end-to-end test is missing). P3-2 is a related P3 gap on REFUND projector coverage. Neither blocks dispatch; both can land in a round-2 follow-up or roll forward to Phase 1.5 refund-flow integration tests.

VERDICT: APPROVE-WITH-MINOR-EDITS
