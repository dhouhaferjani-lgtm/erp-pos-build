# M4 SaleReceiptV2 Fiscal-Integrity Review

Commit reviewed: `be5144593b5aa17afdab79984b9ef97688305643`

Scope/method:
- Ran `git show HEAD --stat` first.
- Read every file changed by the commit in full before forming findings.
- Traced the primary device authoring path, server ingest/parser path, repair/best-effort paths, projection path, and `apps/pos` sale-builder callers.

## Findings

### P2-1: Secondary server validation paths still validate SALE_RECEIPT with the default v1 line contract

Primary ingest is version-aware, but two non-primary server paths still call `validatePerEventConstraints()` without passing the event version. For a valid v2 SALE_RECEIPT payload, that default is v1 and the validator's v1 line-item branch rejects v2 line rows as having extra keys (`variant_id`, `variant_name`, `variant_sku`).

OBSERVED:
- `FiscalPayloadConstraintValidator::validatePerEventConstraints()` defaults `$eventVersion = 1`, and `SALE_RECEIPT` delegates that value into `validateSaleReceiptPayload()` at `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:361` and `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:368`.
- `validateSaleReceiptPayload()` also defaults to v1 at `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:690`.
- The line validator chooses V2 keys only when `$eventVersion >= 2`; otherwise it uses V1 keys and rejects extras at `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:1736` and `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:1744`.
- `BestEffortPayloadParser` calls the validator without event version at `apps/api/app/Modules/Fiscal/Application/Services/BestEffortPayloadParser.php:127`.
- `ParseFailureResolutionService` validates corrected payloads without event version at `apps/api/app/Modules/Fiscal/Application/Services/ParseFailureResolutionService.php:325`.

Impact:
- This is not the normal sale ingest path; primary ingest uses `StrictCanonicalParser` and passes the envelope's version. But quarantine diagnostics and corrected-payload resolution can falsely flag/reject a valid v2 SALE_RECEIPT payload as v1-shaped.
- This matters for "v1 parseable forever" and "v2 after this commit" because operators correcting or inspecting v2 parse failures will see false v1 contract failures.

Recommendation:
- Thread `event_version` into both secondary call sites. For `ParseFailureResolutionService`, use `$event->event_version`. For `BestEffortPayloadParser`, decode `event_version` from the envelope once JSON decoding succeeds and pass it when it is a positive int supported for the event type.

### HYP P2-2: POS V2 builder relies on upstream variant metadata being fiscal-valid, so bad catalog/reference data hard-fails checkout before authoring

The primary POS path does not appear to let the device persist an invalid v2 event that the server later quarantines: `createOfflineReceipt()` builds the V2 payload and immediately passes it to `FiscalEventEngine.append()`, whose local validator matches the server's V2 rules. However, the V2 builder itself copies variant metadata from cart/API state without validating or normalizing it. If an active variant reaches POS with an uppercase/non-UUID id or empty `sku`, checkout fails locally when the engine validates the payload.

OBSERVED:
- The V2 builder copies `item.product.variant_id`, `variant_name`, and `variant_sku` directly and only checks the orphan direction (metadata without id) at `apps/pos/src/lib/fiscal/payloads/SaleReceiptV2Payload.ts:53`.
- The cart store copies `variant.id`, `variant.name_suffix`, and `variant.sku` into the cart product with no fiscal validation at `apps/pos/src/stores/cartStore.ts:305`.
- The POS variant API maps wire `id`, `sku`, and `name_suffix` directly into `POSProductVariant` at `apps/pos/src/api/variantApi.ts:33`.
- Device validation requires lowercase-hex UUID for UUIDs at `apps/pos/src/lib/fiscal/FiscalEventEngine.ts:2898`, and rejects empty optional strings at `apps/pos/src/lib/fiscal/FiscalEventEngine.ts:2929`.
- The SALE_RECEIPT V2 line validator applies those variant rules at `apps/pos/src/lib/fiscal/FiscalEventEngine.ts:2617` and `apps/pos/src/lib/fiscal/FiscalEventEngine.ts:2623`.
- PHP server validation has the same lowercase UUID and non-empty-string/null rules at `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:1795` and `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:1801`.

Why HYP:
- I did not find a normal backend path that obviously emits uppercase UUIDs; Laravel `HasUuids`/PostgreSQL UUID storage should normally produce lowercase canonical UUIDs. Empty strings may also be blocked by request normalization/validation before persistence. The unguarded POS path is still real if catalog/import/test/reference data violates the fiscal variant contract.

Recommendation:
- Either validate/normalize at `toVariant()` or make `buildSaleReceiptV2Payload()` fail with the same fiscal message before reaching `engine.append()`. The cleanest boundary is the API mapper, since all POS variant consumers benefit from a single guard.

### P2-3: Projection variant tests use impossible `event_version=1` rows with V2 line keys

The projection tests added for variant FK binding do exercise `PosCoreReceiptProjection`, but their helper persists `event_version = 1` while also adding `variant_id`, `variant_name`, and `variant_sku` into `line_items[]`. A real v1 SALE_RECEIPT with those line extras is rejected by the server validator; a real v2 SALE_RECEIPT should be stored as event_version 2.

OBSERVED:
- The projection test helper adds V2 variant keys into every line at `apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionTest.php:1362`.
- The same helper canonical envelope hardcodes `event_version` to 1 at `apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionTest.php:1433`.
- The same helper persists the `fiscal_events.event_version` column as 1 at `apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionTest.php:1456`.
- The new variant projection happy path builds a line with `variant_id` and applies the projector through that helper at `apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionTest.php:527`.
- Production parser support requires v2 for that line shape: V1 line keys are defined without variant fields, V2 line keys include them at `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:1712` and `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:1720`.

Impact:
- The test can pass even if the production v2 parse/validate/persist path regresses, because it bypasses `StrictCanonicalParser` and stores an impossible parsed fiscal event directly.
- This does not prove production projection is wrong. It is a coverage hole in the new projection tests.

Recommendation:
- Update this helper to accept `eventVersion` and default to 2 when it emits V2 line keys, or split V1 and V2 helpers so impossible fixture shapes cannot be created accidentally.

### P3-1: Stale comments now contradict the V2 contract

OBSERVED:
- `SaleReceiptPayload` still says "event_version stays at 1" and that the registry mapping remains version 1 at `apps/api/app/Modules/Fiscal/Domain/DTOs/SaleReceiptPayload.php:13`.
- `PosCoreReceiptProjection::decrementStockForLines()` still says `LineItemDTO` does not carry `variant_id`, even though the V2 DTO now does; the actual code intentionally omits `variantId` in the stock call at `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:856` and `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:891`.

Impact:
- No runtime fiscal break observed. This is a maintenance hazard around the exact version mechanics being reviewed.

Recommendation:
- Update the comments to say V2 adds variant identity but this commit deliberately leaves stock decrement at product-level/null-variant scope.

## Question Answers

### (a) BYTE PARITY

No primary-path mismatch found where the device authors a v2 event that the server rejects because of differing line-item rules.

Rule-for-rule comparison:
- Both validators require exact V2 line keys when validating v2/device-authored SALE_RECEIPT lines. Device V2 keys are at `apps/pos/src/lib/fiscal/FiscalEventEngine.ts:1684`; PHP V2 keys are at `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:1720`.
- UUID case: both require lowercase-hex UUID. Device rule is `LOWER_HEX_UUID` through `assertUuidAt()` at `apps/pos/src/lib/fiscal/FiscalEventEngine.ts:2898`; PHP uses `LOWER_HEX_UUID` at `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:128` and checks `variant_id` at `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:1795`.
- Empty string vs null: both accept `variant_name`/`variant_sku` as null or non-empty string and reject `''`. Device optional-string rule is at `apps/pos/src/lib/fiscal/FiscalEventEngine.ts:2929`; PHP non-empty check is at `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:2250`; V2 variant fields are checked at `apps/pos/src/lib/fiscal/FiscalEventEngine.ts:2623` and `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:1801`.
- Orphan direction: both reject `variant_name`/`variant_sku` present when `variant_id` is null, but both allow `variant_id` with null name/sku. Device orphan check is at `apps/pos/src/lib/fiscal/FiscalEventEngine.ts:2630`; PHP orphan check is at `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:1805`.

Builder check:
- `createOfflineReceipt()` uses `buildSaleReceiptV2Payload()` at `apps/pos/src/lib/offline/receiptService.ts:298` and then appends via the engine at `apps/pos/src/lib/offline/receiptService.ts:395`; the engine validates before persistence at `apps/pos/src/lib/fiscal/FiscalEventEngine.ts:853`.
- The builder always emits the three V2 keys, nulling absent metadata, at `apps/pos/src/lib/fiscal/payloads/SaleReceiptV2Payload.ts:65`.
- See HYP P2-2 for upstream bad metadata causing local checkout failure, not server-side quarantine after authoring.

### (b) V1 IMMUTABILITY

`buildSaleReceiptPayload` itself is untouched by this commit: `git diff HEAD^ HEAD -- apps/pos/src/lib/fiscal/payloads/SaleReceiptPayload.ts` is empty, and the file is not present in `git show HEAD --stat`.

Server v1 parseability is preserved in the primary parser:
- Registry current authoring version for SALE_RECEIPT is 2 at `apps/api/app/Modules/Fiscal/Application/Services/FiscalEventPayloadRegistry.php:53`.
- Registry supported versions for SALE_RECEIPT are `[1, 2]` at `apps/api/app/Modules/Fiscal/Application/Services/FiscalEventPayloadRegistry.php:105`.
- `StrictCanonicalParser` reads supported versions from the registry at `apps/api/app/Modules/Fiscal/Application/Services/StrictCanonicalParser.php:194`.
- The parser validates the envelope's version against that supported set at `apps/api/app/Modules/Fiscal/Application/Services/StrictCanonicalParser.php:621`.
- The parser passes the parsed event version to the constraint validator at `apps/api/app/Modules/Fiscal/Application/Services/StrictCanonicalParser.php:204` and `apps/api/app/Modules/Fiscal/Application/Services/StrictCanonicalParser.php:231`.

All production `validatePerEventConstraints` call sites found:
- `StrictCanonicalParser` passes event version: `apps/api/app/Modules/Fiscal/Application/Services/StrictCanonicalParser.php:231`.
- `BestEffortPayloadParser` does not pass event version: `apps/api/app/Modules/Fiscal/Application/Services/BestEffortPayloadParser.php:127` (Finding P2-1).
- `ParseFailureResolutionService` does not pass event version: `apps/api/app/Modules/Fiscal/Application/Services/ParseFailureResolutionService.php:325` (Finding P2-1).
- `TerminalRegistrySnapshotService` does not pass event version, but it authors `TERMINAL_REGISTRY_SNAPSHOT` as event_version 1, not SALE_RECEIPT, at `apps/api/app/Modules/Fiscal/Application/Services/TerminalRegistrySnapshotService.php:239` and validates at `apps/api/app/Modules/Fiscal/Application/Services/TerminalRegistrySnapshotService.php:321`.
- `VirtualAdminFiscalEventService` does not pass event version, but the observed call sites author non-SALE server/admin events as event_version 1 at `apps/api/app/Modules/POS/Application/Services/VirtualAdminFiscalEventService.php:98`, `apps/api/app/Modules/POS/Application/Services/VirtualAdminFiscalEventService.php:256`, and validate at `apps/api/app/Modules/POS/Application/Services/VirtualAdminFiscalEventService.php:358`.

Outbox:
- `OutboxIngestor` uses `StrictCanonicalParser` for canonical bytes at `apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php:166` and persists the envelope's event version at `apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php:803`.
- `FiscalEventEnvelope::assertWireShape()` does not hardcode event_version 1; it reads an int at `apps/api/app/Modules/Fiscal/Application/DTOs/FiscalEventEnvelope.php:131` and only enforces other wire shape/sequence invariants through `apps/api/app/Modules/Fiscal/Application/DTOs/FiscalEventEnvelope.php:201`.

### (c) DEVICE AUTHORING GAPS

No missed production SALE_RECEIPT builder found in `apps/pos`.

OBSERVED:
- `receiptService` imports and uses `buildSaleReceiptV2Payload()` for sale receipts at `apps/pos/src/lib/offline/receiptService.ts:12` and `apps/pos/src/lib/offline/receiptService.ts:298`.
- `rg` found no production `buildSaleReceiptPayload()` call outside the V2 builder. The remaining direct calls are tests or comments.
- Refund carts are intercepted before sale checkout. `classifyCartForCheckout()` routes all-return carts to refund and mixed carts to blocked at `apps/pos/src/lib/refundFlow/cartClassification.ts:57`. `HomePage` applies that before opening sale payment at `apps/pos/src/pages/HomePage.tsx:877` and re-checks inside cash settlement at `apps/pos/src/pages/HomePage.tsx:1032`.
- `accountChargeCartMapper` direct `buildSaleReceiptPayload()` usage seen by search is test-only; account-charge production is not a SALE_RECEIPT builder.

### (d) PROJECTION

Variant FK resolution is conservative and tenant/product anchored:
- `writeLines()` resolves the product FK first, then only attempts variant FK resolution when product FK and canonical `variantId` are non-null at `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:583`.
- Product FK resolution requires UUID shape and `products.tenant_id = event tenant` at `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:627` and `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:633`.
- Variant FK resolution requires UUID shape and `product_variants.tenant_id = event tenant`, `product_id = resolved product FK`, and `id = canonical variant snapshot` at `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:661` and `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:668`.

CHECK/FK interplay:
- The migration adds nullable `pos_receipt_lines.variant_id`, a FK to `product_variants`, and `CHECK (variant_id IS NULL OR product_id IS NOT NULL)` at `apps/api/database/migrations/tenant/2026_06_02_100010_add_variant_id_to_pos_receipt_lines.php:16`, `apps/api/database/migrations/tenant/2026_06_02_100010_add_variant_id_to_pos_receipt_lines.php:24`, and `apps/api/database/migrations/tenant/2026_06_02_100010_add_variant_id_to_pos_receipt_lines.php:27`.
- Those constraints are later validated in `apps/api/database/migrations/tenant/2026_06_15_100000_validate_t2_foreign_keys.php:57` and `apps/api/database/migrations/tenant/2026_06_15_100000_validate_t2_foreign_keys.php:65`.
- Because `writeLines()` only writes `variant_id` after product FK resolution, the CHECK is respected by construction.

Stock decrement:
- Stock decrement behavior did not change to variant-scoped decrement in this commit. `decrementStockForLines()` still calls `decrementStock()` without a variant id at `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:883`, so `decrementStock()` uses `whereNull('variant_id')` at `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:922`.
- See P3-1 for stale comments around that intentionally unchanged behavior.

### (e) GOLDEN FIXTURE

The fixture is pinned to device-encoder-authored bytes by the POS parity test:
- Fixture stores `expected_canonical_string` and `expected_sha256` for a v2 sale at `apps/api/tests/Fixtures/Fiscal/sale-receipt-v2-golden.json:2`.
- POS test builds the payload with `buildSaleReceiptV2Payload()`, encodes it with `FiscalCanonicalEncoder`, and compares both canonical string and SHA-256 to the fixture at `apps/pos/src/lib/fiscal/__tests__/saleReceiptV2CanonicalParity.test.ts:110`.

The PHP test does exercise the v2 validator branch:
- It decodes the fixture canonical string, wraps it in an envelope with `event_version => 2`, and calls `StrictCanonicalParser::parse()` at `apps/api/tests/Unit/Fiscal/SaleReceiptV2GoldenParityTest.php:51` and `apps/api/tests/Unit/Fiscal/SaleReceiptV2GoldenParityTest.php:71`.
- `StrictCanonicalParser` passes that event version into `validatePerEventConstraints()` at `apps/api/app/Modules/Fiscal/Application/Services/StrictCanonicalParser.php:231`, which selects the V2 line key set.

Hole assessment:
- PHP does not re-encode the payload. For the server's stated verify-only contract, acceptance plus SHA-256 of the exact fixture bytes is sufficient to prove the server accepts the device-authored bytes and hashes them unchanged.
- This test would not catch drift in a hypothetical PHP SALE_RECEIPT encoder, but I did not observe such an encoder in the ingest path.

### (f) Registry/version mechanics

No hardcoded device-side comparison to event_version 1 found in the production SALE_RECEIPT sync/authoring path.

OBSERVED:
- POS registry returns 2 for SALE_RECEIPT at `apps/pos/src/lib/fiscal/FiscalEventPayloadRegistry.ts:162`.
- `FiscalEventEngine.append()` resolves `eventVersion` from the registry and writes it into the canonical envelope at `apps/pos/src/lib/fiscal/FiscalEventEngine.ts:561` and `apps/pos/src/lib/fiscal/FiscalEventEngine.ts:611`.
- Local fiscal-event sync stores and forwards the row's event version, not a hardcoded 1, at `apps/pos/src/lib/db/repositories/fiscalEventRepository.ts:132` and `apps/pos/src/lib/db/repositories/fiscalEventRepository.ts:147`.
- `pushOfflineReceipts()` posts `fiscalEventToWireEnvelope(event)` to `/pos/sync/fiscal-events` at `apps/pos/src/lib/sync/syncService.ts:237`.
- Server `FiscalEventEnvelope::assertWireShape()` has no event_version==1 assertion; it requires an int at construction and validates shape/sequence at `apps/api/app/Modules/Fiscal/Application/DTOs/FiscalEventEnvelope.php:131` and `apps/api/app/Modules/Fiscal/Application/DTOs/FiscalEventEnvelope.php:201`.


## Resolution (same session)

- **P2-1 (version-blind secondary callers)** — FIXED: `ParseFailureResolutionService`
  now validates the corrected payload against `$event->event_version`;
  `BestEffortPayloadParser` threads the envelope's own `event_version` into
  `schemaDefects`. Regression test:
  `BestEffortPayloadParserTest::test_v2_sale_receipt_lines_are_validated_with_the_v2_rules`.
- **P2-2 (empty-string variant metadata)** — FIXED: `buildSaleReceiptV2Payload`
  coerces `''` → `null` for all three variant fields (test added). An empty
  `variant_id` with a surviving sku/name still fails closed via the orphan rule
  (corrupt cart state must never be signed).
- **P2-3 (no PHP re-encode in the parity test)** — ACCEPTED AS-IS: the server has
  NO SALE_RECEIPT canonical encoder (device-authority: it verifies the device's
  bytes verbatim by re-hash + parse). Acceptance + SHA-256 parity over the
  device-authored golden bytes IS the complete server-side contract. If a PHP
  re-encoder is ever introduced, it must gain its own golden byte test.
- **P3 (stale comments)** — the envelope-version comment block in
  `StrictCanonicalParser::validateEnvelopeShape` was updated with the version-set
  semantics in the same commit.
