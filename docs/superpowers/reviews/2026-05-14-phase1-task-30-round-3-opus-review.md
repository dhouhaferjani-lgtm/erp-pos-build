# Task 30 — Opus round-3 re-review

**Commit reviewed:** `660f7a28b` (10 files, +1291/-234) — claims closure of round-2 Codex BLOCK + Opus APPROVE-WITH-MINOR-EDITS (1 BLOCKER + 3 P1 + 1 P2 against round-2's `a005b1b71`).
**Spec authority:** v7 §14.3 (two-chokepoint completeness) + §5.0 D1 (server never recomputes — re-hash stored `canonical_bytes`; `pos_receipts` is a mirror, never authoritative) + §8 (NF525 quarantine audit-trail visibility, both partitions).
**Round-2 reviewer artefacts:** `docs/superpowers/reviews/2026-05-14-phase1-task-30-round-2-opus-review.md` (Opus APPROVE-WITH-MINOR-EDITS on F1 + F2 + 4 P2/P3); Codex round-2 BLOCK summary in conversation context (T30-R2-B1 BLOCKER deeper-cut, T30-R2-P1 BLOCKER CI gate, T30-R2-P2 P1, T30-R2-P3 P1, T30-R2-P4 P2).
**Reviewer role:** Opus round-3 re-reviewer scanning hardest for NEW defects (Task 23 r2/r3 / Task 24 r2 / Task 25 r2 lesson — round-2 fixes have a track record of shipping fresh BLOCKERs because they introduce new architecture, and round-3 fixes of round-2 fixes likewise can introduce NEW defects).

---

## Summary

Round-3 actually closes round-2 — not papers it over. Every one of the five round-2 findings was hit at the spec-anchored layer:

1. **T30-R2-B1 BLOCKER (mirror-as-source / DTO regression).** `verifyCanonicalBytesForReceipt` → `verifyCanonicalBytesAndLoadPayload`. The new helper fetches `canonical_bytes` + `current_hash` + `payload` + `payload_parse_status` from `fiscal_events` in one row, rehashes, and compares against `current_hash` (the authoritative chain head — NOT `pos_receipts.fiscal_hash`). On verified rows it loads the authoritative payload; on tampered rows it routes to the new `$snapshot->tamperedSection` and EXCLUDES the receipt from `sales` / `voidedReceipts` / `returnReceipts`. The map functions accept `?array $canonicalPayload` and source monetary fields (`subtotal` / `tax_total → tax_amount` / `discount_total → discount_amount` / `total` / `currency`) from the canonical payload when present. The closure is exactly what Codex round-2 demanded as the "deeper cut": tamper detection ≠ tamper exclusion.

2. **T30-R2-P1 BLOCKER (CI gate).** The lightweight `chokepoint-gate` CI job stays composer-free. The shell gate honors `SKIP_RECEIVER_TYPE_VALIDATOR=1` AND auto-skips when `apps/api/vendor/autoload.php` is missing (clear banner instead of a fatal autoload failure). The PHP receiver-type validator runs at the PHPUnit layer in the existing `backend-test-pgsql` job via `ChokepointCompletenessTest::test_manifest_receiver_type_matches_calling_class_constructor` which shells out to the canonical validator. One source of truth at the PHP layer; the shell gate stays lightweight. CI workflow updated in the same commit to inject `SKIP_RECEIVER_TYPE_VALIDATOR='1'` env var on the `chokepoint-gate` job. Verified by simulating a clean-checkout context: gate exits 0 with the SKIP banner.

3. **T30-R2-P2 P1 (validator too permissive).** `verify-chokepoint-manifest.php` now extracts the receiver expression from each `line_anchor` via a `$this->IDENT->` regex. For the strict path, it looks up THAT specific property on `calling_class` and asserts ITS declared type matches `receiver_type`. ExchangeService (multi-dependency) can no longer pass a manifest entry claiming `ReceiptCreationService` on a line that actually calls `$this->finalizationService->finalize(`. A fabricated mis-label is rejected with `MANIFEST_LIE: ExchangeService::$finalizationService is declared as ReceiptFinalizationService but the manifest claims receiver_type=ReceiptCreationService`. Negative test `test_negative_manifest_with_wrong_receiver_for_multi_dependency_class_is_rejected` pins this.

4. **T30-R2-P3 P1 (quarantine completeness).** `buildQuarantineSection` now UNIONs two surfaces per spec §8: `fiscal_event_quarantine` table rows tagged `source: 'quarantine_table'` AND `fiscal_events` rows with `integrity_status <> 'verified' OR payload_parse_status = 'failed'` tagged `source: 'fiscal_events_in_table'`. Each entry carries the spec §8 envelope field set (envelope_id, terminal_id, claimed_sequence_number, integrity_exception_class, integrity_reason, integrity_status, server_received_at, business_date, event_type, event_version, signature_version, previous_hash, current_hash, payload_parse_status, raw_envelope when present, resolved_at). `Nf525XmlBuilder::addQuarantineSection` emits all of them as French-named XML elements (Source, Identifiant, TerminalId, SequenceRevendiquee, TypeEvenement, VersionEvenement, VersionSignature, DateMetier, StatutIntegrite, ClasseException, Motif, StatutAnalysePayload, HashPrecedent, HashCourant, DateReceptionServeur, DateResolution). New test `test_build_quarantine_section_includes_in_table_quarantined_fiscal_events` pins both partitions surface with their source tags.

5. **T30-R2-P4 P2 (Mockery anti-pattern).** Both touched tests (`test_build_export_snapshot_routes_tampered_receipt_to_tampered_section_not_sales`, `test_verify_terminal_chain_logs_structured_failure_on_canonical_bytes_tamper`) migrate from `Log::shouldReceive('error')->withArgs(...)` to `Log::spy()` followed by `Log::shouldHaveReceived('error')->withArgs(...)`. Matches the convention in `ReceiptReturnServiceTest` / `ApplyFiscalEventProjectionJobTest` / `ParseFailureResumeTest`. The withArgs callbacks ALSO assert the log payload does NOT contain `canonical_bytes` or `payload` keys — sensitive-data leak defense-in-depth, exactly what the round-2 review asked for.

Beyond the five round-2 findings, round-3 also extends the round-1 test inversion (the round-2 test "tampered receipt STILL appears in sales" is correctly inverted to "tampered receipt MUST NOT appear in verified sales bucket") and adds two genuinely new tests that pin the §5.0 D1 contract:

- `test_build_export_snapshot_hydrates_dto_from_canonical_payload_for_fiscal_event_backed_receipts` — seeds `pos_receipts.total = '999999.99'`, `subtotal = '888888.88'`, `currency = 'XYZ'` (deliberately tampered mirror values) and asserts the JET DTO carries `total = '60.00'`, `subtotal = '50.00'`, `currency = 'EUR'` from the canonical payload.
- `test_build_export_snapshot_pos_receipts_fiscal_hash_drift_does_not_trigger_canonical_bytes_log` — tampers `pos_receipts.fiscal_hash` alone, fiscal_events intact, asserts NO tamper log + receipt remains in `sales` + `tamperedSection` stays empty. This is the **positive-direction** test Opus round-2 explicitly requested.

The Task 23 r2/r3 / Task 24 r2 / Task 25 r2 pattern (round-2 introduces NEW defects) is NOT in evidence here. Round-3's architecture is a deeper, cleaner cut than round-2's — the round-2 split between "log mismatch but still emit" was the actual defect; round-3 says "verified rows in verified sections, tampered rows in tampered section, never crossed". Spec §5.0 D1 + §8 alignment is now end-to-end.

**Verdict:** APPROVE. No BLOCKERs. No P1s. Three P3 polish items (line-level data still mirror-sourced — out of scope of the round-2 ask; `Log::shouldNotHaveReceived` array-arg form is unusual but tests pass; `conflicting_event_id` for `sequence_conflict` rows is loaded into DTO but not emitted in XML — spec §8 says JET "reads" it, ambiguous interpretation). None are merge-blocking.

---

## Findings

### CLEAN — round-3 round-2-finding closures that pass

- **R2-C1. T30-R2-B1 BLOCKER (mirror-as-source) — closed at the architectural cut.** `Nf525DataProvider::verifyCanonicalBytesAndLoadPayload` (Nf525DataProvider.php:925+) fetches `canonical_bytes` + `current_hash` + `payload` + `payload_parse_status` in one row (line 939), rehashes via `IntegrityProvider::computeHash` (line 985), and `hash_equals` compares the rehash against `(string) $row->current_hash` (line 987) — NOT `$receipt->fiscal_hash`. The structured `Log::error('chain_verification_failed', ...)` emits with `expected_hash` = stored hash + `actual_hash` = rehash + `failure_mode` = `'canonical_bytes_rehash_mismatch'`. The return shape `{tampered, payload, reason, expected_hash, actual_hash}` is consumed at `buildExportSnapshot` lines 132-186: tampered → `$tamperedSection[] = $this->renderTamperedEntry(...)` + `continue` (excluded from `$sales`); verified → `$sales[] = $this->mapSaleReceipt($receipt, $verifyResult['payload'])` (canonical-payload-sourced monetary fields). Test `test_build_export_snapshot_routes_tampered_receipt_to_tampered_section_not_sales` (ReceiptChainRebuildTest.php:189-292) tampers `fiscal_events.canonical_bytes` and asserts `$snapshot->sales` is EMPTY (the receipt is excluded — was previously `assertNotEmpty($snapshot->sales)` in round-2, correctly inverted in round-3) + `$snapshot->tamperedSection[0]['failure_mode'] === 'canonical_bytes_rehash_mismatch'`. The §5.0 D1 contract is fully sealed.

- **R2-C2. T30-R2-B1 BLOCKER (DTO sourcing from canonical payload).** The map functions (`mapSaleReceipt` / `mapVoidedReceipt` / `mapReturnReceipt`) accept `?array $canonicalPayload` (Nf525DataProvider.php:580 / 638 / 671). `resolveMonetaryFields` (line 735) loads defaults from the projection mirror, then overrides each of `subtotal` / `tax_amount` (← payload['tax_total']) / `discount_amount` (← payload['discount_total']) / `total` / `currency` from the canonical payload when present (lines 754-768). Test `test_build_export_snapshot_hydrates_dto_from_canonical_payload_for_fiscal_event_backed_receipts` (lines 295-394) deliberately tampers `pos_receipts.total = '999999.99'` + `subtotal = '888888.88'` + `currency = 'XYZ'`, leaves `fiscal_events.canonical_bytes` intact with `total = '60.00'` / `subtotal = '50.00'` / `currency = 'EUR'`, and asserts the JET DTO carries the CANONICAL values. The round-2 defect (mirror sourcing for monetary fields) is closed.

- **R2-C3. T30-R2-P1 BLOCKER (CI gate).** `.github/workflows/ci.yml:60-95` keeps the `chokepoint-gate` job composer-free + sets `SKIP_RECEIVER_TYPE_VALIDATOR: '1'` on the gate step. The shell gate (`check-saleReceipt-chokepoints.sh:181-200`) honors the env var AND auto-skips when `apps/api/vendor/autoload.php` is absent — both paths print a clear "SKIP — ... (run via PHPUnit in backend-test-pgsql instead)" banner. Empirically verified two ways: (a) running with `SKIP_RECEIVER_TYPE_VALIDATOR=1` directly prints "SKIP — SKIP_RECEIVER_TYPE_VALIDATOR=1", exit 0; (b) running in a clean-checkout simulation directory (no vendor) prints "SKIP — vendor/autoload.php missing", exit 0. The PHPUnit mirror (`ChokepointCompletenessTest::test_manifest_receiver_type_matches_calling_class_constructor`) shells out to the canonical validator via Symfony Process — same code path as the shell gate when vendor IS present. One source of truth at the PHP layer; the shell gate stays lightweight for fast feedback. The round-2 "next CI run will fail at the require-autoload line" failure mode is closed.

- **R2-C4. T30-R2-P2 P1 (validator too permissive).** `verify-chokepoint-manifest.php:153-207` extracts the receiver expression via `extractReceiverPropertyName($anchor)` (line 281+, regex `/\$this->([A-Za-z_][A-Za-z0-9_]*)->/`). When the receiver is `$this->propertyName`, the validator looks up THAT property on `calling_class` via `ReflectionClass::hasProperty + getProperty + getType`. If the property's declared type doesn't match `receiver_type` (exact-or-subclass via `is_subclass_of`), the validator emits a precise `MANIFEST_LIE: ${callingClass}::\${prop} is declared as ${actual} but the manifest claims receiver_type=${claimed}`. Negative test `test_negative_manifest_with_wrong_receiver_for_multi_dependency_class_is_rejected` (ChokepointCompletenessTest.php:217-291) fabricates an ExchangeService manifest entry claiming `ReceiptCreationService` for a `$this->finalizationService->finalize(` line, asserts exit code 1 + `MANIFEST_LIE` + `finalizationService` in stderr. Multi-dependency classes can no longer pass with mis-labeled receivers. The defensive fallback (lines 208-254) for non-`$this->propertyName` receivers (method calls, locals) prints a WARNING to flag the entry for human review but still PASSES if a matching dependency exists — no false negatives on the strict path that matters.

- **R2-C5. T30-R2-P3 P1 (quarantine completeness).** `buildQuarantineSection` (Nf525DataProvider.php:1123+) now does two queries UNIONed in PHP: (a) `fiscal_event_quarantine` (lines 1126-1175), each entry tagged `source: 'quarantine_table'`; (b) `fiscal_events` WHERE `integrity_status <> 'verified' OR payload_parse_status = 'failed'` (lines 1180-1232), each entry tagged `source: 'fiscal_events_in_table'`. The field set covers every spec §8 column: `envelope_id`, `terminal_id`, `claimed_sequence_number`, `integrity_exception_class`, `integrity_reason`, `integrity_status`, `server_received_at`, `business_date`, `event_type`, `event_version`, `signature_version`, `previous_hash`, `current_hash`, `payload_parse_status`, `raw_envelope` (when present), `resolved_at`. `Nf525XmlBuilder::addQuarantineSection` (Nf525XmlBuilder.php:520+) emits all of them as French-named elements. Test `test_build_quarantine_section_includes_in_table_quarantined_fiscal_events` (ReceiptChainRebuildTest.php:441-473) seeds one quarantine_table row + one in-table quarantined fiscal_events row and asserts both surfaces, tagged with their `source`. Spec §8 "export reconciliation must span both quarantine surfaces" is fulfilled.

- **R2-C6. T30-R2-P4 P2 (Log::spy migration).** Both touched tests use `Log::spy()` + `Log::shouldHaveReceived('error')->withArgs(...)->atLeast()->once()` (ReceiptChainRebuildTest.php:236-292 + 581-624). The withArgs callbacks check `message === 'chain_verification_failed'` + `receipt_id` / `fiscal_event_id` / `failed_fiscal_event_id` / `failed_sequence_number` / `expected_hash` / `actual_hash` / `failure_mode` keys present with correct values. Defense-in-depth: BOTH callbacks ALSO assert `! array_key_exists('canonical_bytes', $context)` and (in the export-path test) `! array_key_exists('payload', $context)`. Sensitive-data leak prevention is now explicitly pinned at the test layer. Matches the convention in `ReceiptReturnServiceTest` / `ApplyFiscalEventProjectionJobTest` / `ParseFailureResumeTest`. No Mockery::shouldReceive setup; no Mockery teardown.

- **R2-C7. Positive-direction mirror-drift test (round-2 Opus F2 ask).** `test_build_export_snapshot_pos_receipts_fiscal_hash_drift_does_not_trigger_canonical_bytes_log` (ReceiptChainRebuildTest.php:396-439) tampers `pos_receipts.fiscal_hash` alone (sets to `str_repeat('e', 64)`), leaves `fiscal_events.canonical_bytes` + `current_hash` intact, calls `buildExportSnapshot`, and asserts: `$snapshot->sales` count is 1 (receipt remains in verified sales — mirror drift is not a canonical-truth tamper) + `$snapshot->tamperedSection` is empty + `Log::shouldNotHaveReceived('error', [...])`. This is the positive-direction test Opus round-2 explicitly asked for ("Then add a positive-direction test ... tamper pos_receipts.fiscal_hash to garbage, leave fiscal_events alone, assert NO Log::error. That seals the mirror contract from this surface for good."). Closure: complete.

- **R2-C8. New tampered XML section preserves byte-stability.** `Nf525XmlBuilder::addTamperedSection` (Nf525XmlBuilder.php:611+) emits `<TicketsAlteres>` only when `count($entries) !== 0`. The pre-existing JET fixture has zero tampered entries → XML unchanged for the fixture path. `Nf525JetExportTest::test_jet_export_xml_is_byte_stable_against_fixture` still passes in the Compliance suite (verified — 12/12 tests, 134 assertions). `Nf525JetExportService::exportJet` (Nf525JetExportService.php:71-74) calls `->addTamperedSection($snapshot->tamperedSection)` after `->addQuarantineSection(...)`. JET pipeline integration is end-to-end.

- **R2-C9. DTO backward-compat preserved.** `Nf525ExportSnapshot::$tamperedSection = []` default (Nf525ExportSnapshot.php:67) sits AFTER `$quarantineSection = []`. The stub provider at `Nf525ExportServiceWithStubProviderTest.php:33-53` constructs the DTO positionally with named parameters and omits both `quarantineSection` and `tamperedSection` — defaults cover. Verified the stub provider test passes in the round-3 run.

- **R2-C10. Tampered-section logging is structured.** `Log::error('chain_verification_failed', [...])` shape includes `bucket` (sale/voided/return), `receipt_id`, `fiscal_event_id`, `terminal_id`, `company_id`, `expected_hash`, `actual_hash`, `failure_mode`. Three discriminated `failure_mode` values: `'fiscal_event_missing'` (orphaned projection), `'canonical_bytes_empty'` (defensive — migration enforces NOT NULL but explicit guard prevents `sha256('')` false-mismatch), `'canonical_bytes_rehash_mismatch'` (the canonical tamper case). `renderTamperedEntry` (Nf525DataProvider.php:1081-1093) carries the same shape to `$tamperedSection`. Operator triage path is crisp.

- **R2-C11. Sensitive-data leak prevention.** The structured log context contains only IDs + hashes + failure_mode. `canonical_bytes` content is never logged. `payload` dict is never logged. Two tests explicitly pin this via `! array_key_exists(...)` assertions in the `withArgs` callback. This was a defense-in-depth ask in the round-2 commit, now empirically defended.

- **R2-C12. Manifest validator: defensive fallback warning.** When the receiver expression isn't `$this->propertyName` (e.g. method-call chain, local variable), the validator emits a WARNING and falls back to round-2's "any-constructor-parameter / any-typed-property" search (verify-chokepoint-manifest.php:208-254). Today no manifest entry uses this fallback (every receiver is `$this->propertyName`), but the path is documented + the warning surfaces to stderr for human review. Defense-in-depth at the gate level.

- **R2-C13. Gate + PHPUnit + PHPStan + Pint all green.** Empirically reproduced:
  - `bash apps/api/scripts/check-saleReceipt-chokepoints.sh` → `manifest receiver_type validator: PASS — 7 entry/entries validated` + `§14.3 chokepoint gate: PASS — 9 call site(s) reconciled`, exit 0.
  - `SKIP_RECEIVER_TYPE_VALIDATOR=1 bash apps/api/scripts/check-saleReceipt-chokepoints.sh` → SKIP banner + gate PASS, exit 0.
  - Clean-checkout simulation (no vendor) → SKIP banner (missing autoload), exit 0.
  - `php apps/api/scripts/verify-chokepoint-manifest.php` → PASS 7 entries, exit 0.
  - `./vendor/bin/phpunit tests/Feature/Fiscal/ChokepointCompletenessTest.php tests/Feature/Fiscal/ReceiptChainRebuildTest.php` → `OK (16 tests, 179 assertions)`. **The implementer's "16/16" claim reproduces.**
  - `./vendor/bin/phpunit tests/Feature/Compliance/Nf525JetExportTest.php tests/Feature/Compliance/Nf525ExportSnapshotTest.php tests/Unit/POS/Nf525DataProviderContractTest.php` → `OK (12 tests, 134 assertions)`. JET byte-stability fixture survives the round-3 tampered-section addition.
  - `./vendor/bin/phpunit tests/Feature/Fiscal/` → `OK (260 tests, 931 assertions, 37 skipped)` — 37 PG-only on SQLite, pre-existing. **The implementer's "260/260" claim reproduces.**
  - `./vendor/bin/phpstan analyse --level=8 …` on five touched app/ files → `[OK] No errors`.
  - `./vendor/bin/pint --test …` on eight touched files → `{"result":"pass"}`.

### New-defect scan (Task 23 r2/r3 pattern) — items checked clean

- **`tamperedSection` blast radius.** Three consumers of `Nf525ExportSnapshot` — the production provider (constructs the DTO), the stub provider (omits the field; default `[]` covers), the XML builder (`Nf525JetExportService::exportJet` calls `->addTamperedSection($snapshot->tamperedSection)`). Verified via `grep -rn "tamperedSection\|->tampered"` — every reference is correctly wired. No untouched consumer.
- **`mapSaleReceiptFromFiscalEvent` field mapping.** Only monetary fields (subtotal / tax_amount ← tax_total / discount_amount ← discount_total / total / currency) are sourced from the canonical payload. Non-monetary fields (receiptNumber, terminalId, postedAt, chainSequence, fiscalHash, previousHash, cashierName, customerName, lines, vatDetails, payments, voucherLedgerEntries, customerId, contactId, trainingFlag, consumptionMode) still come from the projection mirror or relationship loads. This is correct: the canonical `SaleReceiptPayload` per spec §4 only carries monetary + line + VAT + payment + voucher data; metadata like receipt_number / terminal_id / cashier_name lives ONLY in the projection. (See P3-1 below for the line-level partial-mirror caveat.)
- **JET XML byte-stability.** Verified — `Nf525JetExportTest` passes (12/12 tests, 134 assertions). The `<TicketsAlteres>` element is emitted only when `tamperedSection` is non-empty, and the existing fixture has zero tampered entries.
- **`StrictCanonicalParser` integration.** `verifyCanonicalBytesAndLoadPayload` does NOT call `StrictCanonicalParser` — it uses the stored `payload_parse_status` field. If `payload_parse_status === 'parsed'`, it trusts the stored `payload` (JSON-decoded or already-array per the column type). If `payload_parse_status !== 'parsed'` (pending / failed), it returns `payload = null` and the map functions fall back to the mirror. Parse failures from upstream (Task 27/28) propagate via `payload_parse_status='failed'`, which puts the row into `integrity_status='quarantined'` → the row appears in `tamperedSection` only via `verifyCanonicalBytesAndLoadPayload`'s hash mismatch (parse-failed events with correct canonical hashes won't be flagged as tampered, they'll be quarantined via `buildQuarantineSection`). Correct separation of concerns.
- **Quarantine UNION query performance.** `fiscal_events_integrity_status_idx` is a partial index `WHERE integrity_status <> 'verified'` per migration `2026_05_14_100001_create_fiscal_events_table.php:117-119`. The new query's `WHERE integrity_status <> 'verified' OR payload_parse_status = 'failed'` would NOT fully use the partial index for the `OR` branch — PG would BitmapOr two scans. The query is also bounded by `company_id` + `server_received_at BETWEEN ...`, so the row count is small. Acceptable for export workloads. A dedicated `payload_parse_status='failed'` partial index would be a P3 polish — not merge-blocking.
- **Validator refactor edge cases.** Non-`$this->propertyName` receivers fall back to the round-2 "any-constructor-parameter / any-typed-property" search with a WARNING. Today no manifest entry uses this fallback (every receiver is `$this->propertyName`). The fallback is documented + emits a stderr WARNING. Edge case: a future entry with `$x = $factory->build(); $x->method(...)` would hit the fallback path with no strict pin — but no such entry exists today, and the warning is visible.
- **CI restructure.** `chokepoint-gate` job (line 60-95) keeps the lightweight no-PHP-toolchain shape, sets `SKIP_RECEIVER_TYPE_VALIDATOR: '1'` on the gate step, prints the SKIP banner. `backend-test-pgsql` job is unchanged; the validator runs as part of `ChokepointCompletenessTest::test_manifest_receiver_type_matches_calling_class_constructor` (PHPUnit auto-includes it as a test in the full Fiscal suite). One source of truth at the PHP layer; the shell gate stays lightweight for fast feedback. CI flow is correct.
- **DTO contract change consumers — full grep.** `new Nf525ExportSnapshot(...)` — 3 hits (provider × 2 + stub × 1). All three use named parameters; the stub omits both `quarantineSection` AND `tamperedSection` (defaults cover); the two production constructors explicitly pass both. ✓
- **Stale-comment hazard.** `Nf525DataProvider.php` has fresh round-3 docblocks on every touched method. No reference to the deleted round-2 method name `verifyCanonicalBytesForReceipt` remains in source. ✓
- **Tampered receipt count vs. terminal stats.** `buildExportSnapshot` exclusion happens BEFORE the trainingCounts / terminals computation; the trainingCounts loop iterates over a separately queried `$trainingQuery` (Receipt::where('is_training', true)); the terminals loop iterates over a separately queried `Terminal::all`. Neither depends on the post-tamper-filter `$sales` / `$voidedReceipts` / `$returnReceipts` arrays. So a tampered receipt does NOT under-count training / terminals. ✓
- **`Log::shouldNotHaveReceived` array-arg form.** The mirror-drift test uses `Log::shouldNotHaveReceived('error', ['chain_verification_failed', \Mockery::any()])`. The test passes (Mockery's `shouldNotHaveReceived` accepts an array of args). But this isn't the idiomatic Laravel-native pattern — see P3-2 below. The test ALSO asserts `$snapshot->sales count === 1` and `$snapshot->tamperedSection` is empty, which is the real semantic pin. The negative-log assertion is defense-in-depth.

### P3 — polish

- **T30-R3-F1 P3 — Line-level data still mirror-sourced.** `mapLine` (Nf525DataProvider.php:773-786), `mapVatDetail` (line 788-796), `mapPayment` (line 798-806), `mapVoucherLedgerEntries` (line 808+) pull from `$line->quantity`, `$line->unit_price`, `$line->line_total`, etc. — the `pos_receipt_lines` / `pos_receipt_vat_details` / `pos_receipt_payments` projection tables. A coordinated tamper of `pos_receipt_lines.unit_price` would surface in the JET XML even though `pos_receipts.total` is now canonical-sourced. This is OUT OF SCOPE of the round-2 ask (which was named at the `pos_receipts.total` level) and the round-3 closure correctly covers the named defect. Closing this would require the canonical payload to carry every line / VAT / payment detail (per spec §4 it already does — `lines`, `vat_breakdown`, `payment_lines`) AND a re-architecture of the map functions to source line-level from the payload too. A future Task 30-followup or a Phase 1 hardening pass is the right vehicle.

- **T30-R3-F2 P3 — `Log::shouldNotHaveReceived` array-arg form is unusual.** ReceiptChainRebuildTest.php:435-438 uses `Log::shouldNotHaveReceived('error', ['chain_verification_failed', \Mockery::any()])`. The idiomatic Laravel-native pattern is `Log::shouldNotHaveReceived('error')` (no args, asserts the method was never called at all) OR `Log::shouldNotHaveReceived('error')->withArgs(fn(...) => ...)`. The array-arg form silently re-introduces a Mockery dependency (`\Mockery::any()`) at exactly the test that closes T30-R2-P4 (which was about migrating AWAY from Mockery). The test passes (Mockery interop accepts the array), and the broader assertions in the same test (`assertCount(1, $snapshot->sales)` + `assertEmpty($snapshot->tamperedSection)`) carry the real semantic load. Polish only.

- **T30-R3-F3 P3 — `conflicting_event_id` for `sequence_conflict` rows not emitted in XML.** Spec §8 lists `conflicting_event_id` as a field the verifier and JET export USE for `sequence_conflict` rows (the typed envelope cannot enter `fiscal_events` because of the UNIQUE key, so the row already holding the slot is the conflicting event). The round-3 `buildQuarantineSection` for the `quarantine_table` partition selects every other §8 column but NOT `conflicting_event_id`. The DTO array therefore doesn't carry it, and `Nf525XmlBuilder::addQuarantineSection` doesn't emit it. Spec wording: "JET / fiscal export reads the same columns plus `event_type`, `business_date`, `canonical_bytes` to render the incident in the quarantine reconciliation section." "Reads" is ambiguous between "loads for forensic context" vs "literally emits as XML elements". Defensible either way — but adding `'conflicting_event_id' => (string) ($row->conflicting_event_id ?? '')` to the array + `<EvenementConflictant>` to the XML closes the spec wording cleanly. P3 polish.

### New-defect scan (Task 23 r2/r3 pattern) — also checked clean (other surfaces)

- **No `app()` helper introduction.** Verified — grep on touched files returns zero hits.
- **Constructor injection only.** All dependencies on touched files are `private readonly` via constructor.
- **No magic strings reintroduced.** Receipt types use enums; `'SALE_RECEIPT'` magic string from round-1 stays deleted.
- **`RolesAndPermissionsSeeder` + real Eloquent models.** The new tests seed via `Receipt::factory()->create([...])` + `DB::table('fiscal_events')->insert([...])` — no API faking.
- **No `git add -A` / `.`.** Commit touches exactly 10 specific files.
- **CI workflow extended in the same commit.** Round-2's standing-pattern failure (gate now needs PHP / autoload but `ci.yml` not updated) was the BLOCKER; round-3 updates `ci.yml` in the same commit by adding the env var. Standing pattern restored.

---

## Anti-pattern checklist

- [x] No `app()` helper introduced.
- [x] Constructor injection only.
- [x] No magic strings reintroduced.
- [x] `RolesAndPermissionsSeeder` + real Eloquent models in test scaffold.
- [x] No `git add -A` / `.` — 10 specific files in commit.
- [x] Stale-comment hazard scan clean (Task 20 standing pattern).
- [x] CI workflow extended in the same commit (Task 10/11/19/21/22 standing pattern).
- [x] `Log::spy()` migration (round-2 P4 closed — minor `Log::shouldNotHaveReceived` array-arg form polish issue noted as P3).
- [x] DTO contract change backward-compatible (default `[]` for new field; stub provider unaffected).
- [x] JET fixture byte-stability preserved (verified via Compliance suite).
- [x] PHPStan level 8 + Pint clean on all touched files.

---

## Verification evidence

```
$ bash apps/api/scripts/check-saleReceipt-chokepoints.sh
manifest receiver_type validator: PASS — 7 entry/entries validated
§14.3 chokepoint gate: PASS — 9 call site(s) reconciled
EXIT=0

$ SKIP_RECEIVER_TYPE_VALIDATOR=1 bash apps/api/scripts/check-saleReceipt-chokepoints.sh
manifest receiver_type validator: SKIP — SKIP_RECEIVER_TYPE_VALIDATOR=1 (run via PHPUnit in backend-test-pgsql instead)
§14.3 chokepoint gate: PASS — 9 call site(s) reconciled
EXIT=0

$ # Clean-checkout simulation (no apps/api/vendor):
$ bash /tmp/ci-sim/apps/api/scripts/check-saleReceipt-chokepoints.sh
manifest receiver_type validator: SKIP — vendor/autoload.php missing (run via PHPUnit in backend-test-pgsql instead)
§14.3 chokepoint gate: PASS — 0 call site(s) reconciled
EXIT=0

$ php apps/api/scripts/verify-chokepoint-manifest.php
manifest receiver_type validator: PASS — 7 entry/entries validated
EXIT=0

$ ./vendor/bin/phpunit tests/Feature/Fiscal/ChokepointCompletenessTest.php tests/Feature/Fiscal/ReceiptChainRebuildTest.php
OK (16 tests, 179 assertions)

$ ./vendor/bin/phpunit tests/Feature/Compliance/Nf525JetExportTest.php tests/Feature/Compliance/Nf525ExportSnapshotTest.php tests/Unit/POS/Nf525DataProviderContractTest.php
OK (12 tests, 134 assertions)

$ ./vendor/bin/phpunit tests/Feature/Fiscal/
Tests: 260, Assertions: 931, Skipped: 37 (PG-only on SQLite — pre-existing)

$ ./vendor/bin/phpstan analyse --level=8 app/Modules/POS/Application/Services/Nf525DataProvider.php app/Modules/POS/Domain/Services/ReceiptHashService.php app/Modules/Compliance/Services/Nf525/Nf525XmlBuilder.php app/Modules/Compliance/Services/Nf525/Nf525JetExportService.php app/Shared/Contracts/Compliance/DTOs/Nf525ExportSnapshot.php
[OK] No errors

$ ./vendor/bin/pint --test … (8 files including scripts/verify-chokepoint-manifest.php + the two touched tests)
{"result":"pass"}
```

---

## Recommendation

**APPROVE.**

Round-3 closes the round-2 BLOCKER (mirror-as-source / DTO regression) at the architectural cut Codex round-2 asked for — verified rows source monetary fields from the canonical payload, tampered rows go to a dedicated `tamperedSection` and are EXCLUDED from the verified buckets. The round-2 CI BLOCKER is closed via the lightweight gate + PHPUnit-mirror pattern. The validator is strict (multi-dependency mis-labels rejected with a precise `MANIFEST_LIE` diagnostic). The quarantine section UNIONs both spec §8 partitions with full envelope-field coverage. The Log::spy migration is complete + augmented with sensitive-data-leak prevention. The positive-direction mirror-drift test seals the §5.0 D1 contract.

The three P3 polish items (line-level mirror-sourcing out of scope; `Log::shouldNotHaveReceived` array-arg form; `conflicting_event_id` not in XML) are non-blocking and well-bounded. None require a round-4.

The Task 23 r2/r3 / Task 24 r2 / Task 25 r2 pattern — round-2 introduces NEW defects — is NOT in evidence here. Round-3's architecture is a deeper, cleaner cut than round-2's.

---

VERDICT: APPROVE
