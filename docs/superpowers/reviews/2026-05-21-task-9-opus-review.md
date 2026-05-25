# Task 9 Opus Second-Pass Adversarial Review — Document/B2B Facture Draft Bridge

**Implementation reviewed:** `a36fb384c Phase 3.9.1: Route business account charges to facture drafts`

**Codex self-review reviewed:** `docs/superpowers/reviews/2026-05-21-task-9-codex-review.md`

## Verdict

REQUEST-CHANGES

The D16/D8 placement is broadly correct: the new bridge is Document-owned, registered through the `FiscalEventProjector` seam, gated by `requiresModule() === 'Sales'`, reads sealed `ACCOUNT_CHARGE` canonical payloads, and creates a draft Document aggregate without creating POS/Treasury payment rows.

The remaining blocker is replay/idempotency hardness. The draft service can silently accept duplicate or contract-drifted existing drafts instead of failing loud, which is the same class of issue Task 8 had to harden after first review.

## Findings

### P1 — Duplicate existing drafts are not detected, and the write path has no production idempotency lock

`apps/api/app/Modules/Document/Application/Services/POSAccountChargeDraftService.php:21-33`

`createDraft()` looks up an existing draft by tenant/company/reference with `first()`. If two `documents` rows already exist for the same `POS-ACCOUNT-CHARGE:{fiscalEventId}` reference, replay validates whichever row the database returns first and can mark the projection successful while a duplicate remains. That violates the requested fail-loud behavior for existing duplicate/replay conflicts.

The same path also has no PG advisory lock or unique database anchor around the "find none, create document + lines" section. The projection job normally serializes a single projection row, but this service is still the side-effect boundary and should not depend only on queue delivery discipline for exactly-once writes.

Smallest fix:

- Inside the transaction, acquire a PG advisory transaction lock keyed by tenant/company/reference, mirroring the Treasury charge bridge's event-scoped lock pattern.
- Query matching documents with `get()` instead of `first()`; if count is greater than one, throw a typed/runtime conflict such as `pos_account_charge_draft_conflict:multiple_documents_for_event`.
- Add a regression that seeds two same-tenant/company same-reference documents and expects fail-loud replay instead of returning one.

### P1 — Existing draft validation omits contract-critical header fields

`apps/api/app/Modules/Document/Application/Services/POSAccountChargeDraftService.php:36-60` creates the required draft shape, but `assertExistingDraftMatches()` at `apps/api/app/Modules/Document/Application/Services/POSAccountChargeDraftService.php:88-145` only revalidates partner, type, status, fiscal status, date, totals, and lines.

It does not revalidate fields that are part of the Task 9 contract and D8 boundary:

- `fiscal_category === FiscalCategory::TaxInvoice`
- `document_number === null`
- `source_document_id === null`
- `currency === $command->currencyCode`
- `due_date === $command->dueDate`
- `payload.fiscal_event_id`, `payload.account_charge_uuid`, and the canonical payload snapshot

A header-matching existing row with `fiscal_category = NON_FISCAL`, a non-null `source_document_id`, or wrong payload metadata can currently be accepted as idempotent if its totals and lines match. That is a silent downgrade from the intended draft facture shape.

Smallest fix:

- Extend `assertExistingDraftMatches()` to compare the omitted header/payload fields and include mismatch names in `pos_account_charge_draft_conflict:*`.
- Add at least one regression that tampers an existing same-reference draft's `fiscal_category` or `source_document_id` and expects replay to fail loud.

## Attack Vectors Checked

- **D16 bounded seam:** PASS. The new code lives under Document and is tagged as a `FiscalEventProjector`; the Task 9 commit does not add POS/Fiscal imports of Document/Treasury/Accounting operational services.
- **D8 Tax Invoice boundary:** PASS for the create path. POS still authors only `ACCOUNT_CHARGE`; the bridge creates `DocumentType::Invoice` with `DocumentStatus::Draft` and `FiscalStatus::Draft`, and no fiscal hash/sequence is written.
- **Module gate/dead path:** PASS. `DocumentServiceProvider` tags `DocumentAccountChargeFactureBridge`; contract test pins name, `ACCOUNT_CHARGE`, `Sales`, and priority `170`.
- **Canonical handoff:** PASS. The bridge uses `CanonicalPayloadReader::forAccountCharge($event)` and passes sealed line items into the draft command. The full payload snapshot includes `vat_breakdown`.
- **Cross-tenant/company FK safety:** PASS on partner resolution and document writes. Partner lookup scopes tenant, company, customer-capable type, and sealed customer id before writing `partner_id`.
- **Fail-loud checks:** PARTIAL. Missing/wrong-company partner, unsupported synced state, and non-numeric money paths throw; duplicate and contract-drifted existing drafts need the fixes above.
- **No payment side effects:** PASS. The new Document bridge/service do not create Treasury `Payment`, POS `ReceiptPayment`, or call `createPOSPaymentEntry()`.
- **Rule 13:** PASS for new production code. No new `app()`, `App::make`, or `resolve()` calls appear in the production files under review.
- **CI PG sentinel:** PASS. `.github/workflows/ci.yml` includes `DocumentAccountChargeFactureBridgeTest` in the PG fiscal filter with an explanatory comment.

## Verification Run

- `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Feature/Fiscal/DocumentAccountChargeFactureBridgeTest.php` — PASS, 7 tests / 47 assertions.
- `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpstan analyse --level=8 app/Modules/Document app/Modules/Fiscal tests/Feature/Fiscal/DocumentAccountChargeFactureBridgeTest.php --memory-limit=1G` — PASS.
- `./vendor/bin/pint --test app/Modules/Document/Application/DTOs/CreatePOSAccountChargeDraftCommand.php app/Modules/Document/Application/Projections/DocumentAccountChargeFactureBridge.php app/Modules/Document/Application/Services/POSAccountChargeDraftService.php app/Modules/Document/Providers/DocumentServiceProvider.php tests/Feature/Fiscal/DocumentAccountChargeFactureBridgeTest.php` — PASS.
- `php tools/deptrac-ratchet.php --config=deptrac.yaml --baseline=deptrac.baseline.json` from `apps/api` — FAIL only on existing `SharedContracts on ModuleDomain` ratchet growth from 2 to 4; Task 9-touched categories held.

---

# R2 Re-Review — `a60d01184 Phase 3.9.2: Harden facture draft replay`

**R2 scope reviewed:** `a36fb384c..a60d01184`, updated Codex self-review, `POSAccountChargeDraftService`, and `DocumentAccountChargeFactureBridgeTest`.

## Final Verdict

REQUEST-CHANGES

R2 closes the two R1 issues in the intended direction: same-reference duplicate drafts now fail loud, the PG path now takes a transaction-scoped advisory lock, and existing draft replay validation now covers the previously omitted contract headers.

However, R2 introduced a PostgreSQL-specific false-conflict risk in the new payload snapshot validation. Because `documents.payload` is `jsonb`, object key order is not a stable storage contract. The code compares the nested `canonical_payload` array with PHP strict identity, so a legitimate replay can fail on PostgreSQL purely because `jsonb` reorders associative object keys.

## R2 Findings

### P1 — Strict array identity on `jsonb` payload can break legitimate replay on PostgreSQL

`apps/api/app/Modules/Document/Application/Services/POSAccountChargeDraftService.php:145-159`

R2 added:

```php
if (($payload['canonical_payload'] ?? null) !== $command->payloadSnapshot) {
    $mismatches[] = 'payload.canonical_payload';
}
```

That is too strict for data round-tripped through `documents.payload`, which is a `jsonb` column. PostgreSQL `jsonb` preserves array order but not object key insertion order. PHP array `!==` is order-sensitive for associative arrays, so the same semantic JSON object can compare unequal after a PostgreSQL round trip.

Why this matters here:

- The bridge stores `canonical_payload` as a nested JSON object at `POSAccountChargeDraftService.php:68-72`.
- `test_document_bridge_is_idempotent_by_fiscal_event_id()` replays the same event, but local PHPUnit is pinned to SQLite in `apps/api/phpunit.xml`; SQLite preserves the inserted JSON text order and does not catch the PG behavior.
- The CI PG sentinel includes `DocumentAccountChargeFactureBridgeTest`, so this can become a production/PG-only idempotency failure or a CI-only failure depending on driver coverage.

Smallest required fix:

- Compare `payload.canonical_payload` semantically with recursive canonicalization before strict comparison. Preserve list order, but sort associative object keys recursively on both sides before comparing.
- Alternatively compare a deterministic canonical JSON string/hash generated from both arrays with the same key-sorting encoder used for fiscal canonical bytes.
- Add a regression that reorders associative keys inside `payload.canonical_payload` on an existing draft and verifies replay still idempotently succeeds when the data is otherwise identical. If a true value changes, replay must still fail loud.

## R2 Checks

- **Duplicate replay:** PASS. `createDraft()` now loads all same-tenant/company/reference documents and throws `pos_account_charge_draft_conflict:multiple_documents_for_event` when count is greater than one.
- **Production lock:** PASS. The PG path now takes a transaction-scoped advisory lock before read/create.
- **Header validation:** PASS for the intended fields. Replay now checks fiscal category, nullable document number, source document id, due date, currency, fiscal event id, account charge uuid, and canonical payload.
- **D16/D8:** PASS. R2 did not move boundaries; the bridge remains Document-owned and draft-only.
- **No payment side effects:** PASS. R2 did not add Treasury `Payment`, POS `ReceiptPayment`, or `createPOSPaymentEntry()` usage.
- **Rule 13:** PASS for new production changes.

## R2 Verification Run

- `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Feature/Fiscal/DocumentAccountChargeFactureBridgeTest.php` — PASS, 9 tests / 51 assertions.
- `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpstan analyse --level=8 app/Modules/Document app/Modules/Fiscal tests/Feature/Fiscal/DocumentAccountChargeFactureBridgeTest.php --memory-limit=1G` — PASS.
- `./vendor/bin/pint --test app/Modules/Document/Application/DTOs/CreatePOSAccountChargeDraftCommand.php app/Modules/Document/Application/Projections/DocumentAccountChargeFactureBridge.php app/Modules/Document/Application/Services/POSAccountChargeDraftService.php app/Modules/Document/Providers/DocumentServiceProvider.php tests/Feature/Fiscal/DocumentAccountChargeFactureBridgeTest.php` — PASS.
- `php tools/deptrac-ratchet.php --config=deptrac.yaml --baseline=deptrac.baseline.json` from `apps/api` — FAIL only on the known unrelated `SharedContracts on ModuleDomain` ratchet growth from 2 to 4; Task 9-touched categories held.
- Sanity check: PHP strict array identity is order-sensitive while semantic equality is not, so the new `!==` comparison is brittle for `jsonb` object round trips.

---

# R3 Re-Review — `f5462f26a Phase 3.9.3: Normalize facture draft payload replay`

**R3 scope reviewed:** `a60d01184..f5462f26a`, updated Codex self-review, `POSAccountChargeDraftService`, and `DocumentAccountChargeFactureBridgeTest`.

## Final R3 Verdict

APPROVE

R3 fixes the R2 PostgreSQL `jsonb` replay concern. The payload comparison now recursively sorts associative object keys before strict comparison while preserving list order, which is the correct contract for a JSON payload snapshot: object key order is ignored, but ordered arrays such as line items and VAT breakdown rows remain order-sensitive.

## R3 Checks

- **Payload replay normalization:** PASS. `payloadSnapshotsMatch()` rejects non-array stored payloads, normalizes both actual and expected snapshots through `sortPayloadSnapshot()`, and then uses strict comparison. This preserves type/value strictness after removing only associative key-order noise.
- **List order preservation:** PASS. `array_is_list()` branches map list entries in their original order, so reordered `line_items` or `vat_breakdown` entries would still conflict.
- **Regression coverage:** PASS. The new test reorders associative keys inside the stored `canonical_payload` and verifies replay remains idempotent without creating a second draft or line.
- **R1/R2 closure:** PASS. Duplicate same-reference drafts still fail loud; header contract validation remains in place; PG advisory lock remains before read/create.
- **D16/D8/no-payment side effects:** PASS. R3 only changes replay comparison logic and test coverage; it does not alter the Document-owned projector seam, draft-only status, or payment side effects.
- **Rule 13:** PASS. No new production `app()`, `App::make`, or `resolve()` usage.

## R3 Verification Run

- `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Feature/Fiscal/DocumentAccountChargeFactureBridgeTest.php` — PASS, 10 tests / 55 assertions.
- `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpstan analyse --level=8 app/Modules/Document app/Modules/Fiscal tests/Feature/Fiscal/DocumentAccountChargeFactureBridgeTest.php --memory-limit=1G` — PASS.
- `./vendor/bin/pint --test app/Modules/Document/Application/DTOs/CreatePOSAccountChargeDraftCommand.php app/Modules/Document/Application/Projections/DocumentAccountChargeFactureBridge.php app/Modules/Document/Application/Services/POSAccountChargeDraftService.php app/Modules/Document/Providers/DocumentServiceProvider.php tests/Feature/Fiscal/DocumentAccountChargeFactureBridgeTest.php` — PASS.
- `php tools/deptrac-ratchet.php --config=deptrac.yaml --baseline=deptrac.baseline.json` from `apps/api` — FAIL only on the known unrelated `SharedContracts on ModuleDomain` ratchet growth from 2 to 4; Task 9-touched categories held.

No remaining Task 9-owned issues found in R3.
