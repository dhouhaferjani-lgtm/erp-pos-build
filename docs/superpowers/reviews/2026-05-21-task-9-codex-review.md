# Task 9 Codex Adversarial Review — Document/B2B Facture Draft Bridge

**Implementation under review:**

- R1: `a36fb384c Phase 3.9.1: Route business account charges to facture drafts`
- R2: `a60d01184 Phase 3.9.2: Harden facture draft replay`
- R3: `f5462f26a Phase 3.9.3: Normalize facture draft payload replay`

**Scope reviewed:**

- `apps/api/app/Modules/Document/Application/DTOs/CreatePOSAccountChargeDraftCommand.php`
- `apps/api/app/Modules/Document/Application/Services/POSAccountChargeDraftService.php`
- `apps/api/app/Modules/Document/Application/Projections/DocumentAccountChargeFactureBridge.php`
- `apps/api/app/Modules/Document/Providers/DocumentServiceProvider.php`
- `apps/api/tests/Feature/Fiscal/DocumentAccountChargeFactureBridgeTest.php`
- `.github/workflows/ci.yml`

## Verdict

APPROVE.

Task 9 satisfies the Phase 3 plan after R2/R3 replay hardening: sealed `ACCOUNT_CHARGE` fiscal events for business customers with `invoice_classification = b2b_facture_draft_requested` create a Sales-gated draft invoice/facture record, stay draft-only, do not post a Tax Invoice fiscal event, and fail loud on unsafe replay or customer lookup inconsistencies.

## Verification Evidence

Fresh verification after the R3 replay-hardening change:

- RED check before implementation: focused Task 9 test failed because `DocumentAccountChargeFactureBridge` did not exist.
- Focused PHP unit/feature: `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Feature/Fiscal/DocumentAccountChargeFactureBridgeTest.php`  
  Result: 10 tests, 55 assertions.
- Touched-path PHPStan: `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpstan analyse --level=8 app/Modules/Document app/Modules/Fiscal tests/Feature/Fiscal/DocumentAccountChargeFactureBridgeTest.php --memory-limit=1G`  
  Result: no errors.
- Focused Pint: `./vendor/bin/pint --test app/Modules/Document/Application/DTOs/CreatePOSAccountChargeDraftCommand.php app/Modules/Document/Application/Services/POSAccountChargeDraftService.php app/Modules/Document/Application/Projections/DocumentAccountChargeFactureBridge.php app/Modules/Document/Providers/DocumentServiceProvider.php tests/Feature/Fiscal/DocumentAccountChargeFactureBridgeTest.php`  
  Result: pass.
- Broad backend: `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Feature/Fiscal/ tests/Unit/Fiscal/ tests/Feature/POS/ tests/Feature/Accounting/POSAccountChargeJournalEntryTest.php tests/Feature/Document/`  
  Result: 1524 tests, 5319 assertions, 49 deprecations, 114 skipped, 2 incomplete.
- Full PHPStan: `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpstan analyse --level=8 --memory-limit=1G`  
  Result: no errors across 1864 files.
- Chokepoint gate: `bash scripts/check-saleReceipt-chokepoints.sh`  
  Result: manifest receiver validator PASS, §14.3 gate PASS.
- POS gate: `pnpm test && pnpm typecheck && pnpm lint` in `apps/pos`  
  Result: 168 files / 1500 tests passed, typecheck passed, lint exited 0 with 41 existing warnings.
- Deptrac ratchet: `php tools/deptrac-ratchet.php --config=deptrac.yaml --baseline=deptrac.baseline.json`  
  Result: expected repository-level fail on pre-existing `SharedContracts on ModuleDomain` ratchet growth from 2 to 4; Task 9-owned categories held.

## Attack Vectors Checked

### D16 / Bounded Modules

PASS. The bridge is Document-owned and registered as a `FiscalEventProjector`. The Fiscal/POS path does not import Document or Treasury operational services. Module activation uses `requiresModule() === 'Sales'`, matching the plan's `Vertical::defaultModules()` note that the invoice/document operational surface is exposed as Sales.

### D8 / Tax Invoice Boundary

PASS. POS still authors only `ACCOUNT_CHARGE`. The bridge creates `DocumentType::Invoice` with `DocumentStatus::Draft` and `FiscalStatus::Draft`, no `document_number`, no fiscal hash/sequence columns, and no new Tax Invoice fiscal event. The canonical `ACCOUNT_CHARGE` event remains the only fiscal event created in the Task 9 tests.

### Cross-Tenant / Cross-Company FK Safety

PASS. The partner lookup is scoped by `tenant_id`, `company_id`, and customer-capable `PartnerType`, then keyed by the sealed payload customer id. The cross-company regression test expects a fail-loud `customer_not_found` invariant and verifies no document is created.

### Fail-Loud vs Silent Downgrade

PASS. Unsupported customer sync status, missing/wrong-company partner, non-numeric money, invalid line strings, malformed existing draft money, and duplicate draft conflicts all throw typed/runtime failures instead of silently skipping a required B2B draft. Non-B2B classifications are the only intentional skip path.

### Replay / Idempotency Safety

PASS after R2 hardening. Initial implementation idempoted by fiscal-event reference, header totals, and line count. I fixed the first self-review gap before R1 commit by validating line number, product code, description, quantity, unit price, discount, tax rate, and line total against sealed canonical line items.

Opus R1 then found two additional replay gaps: duplicate same-reference drafts were not fail-loud, and existing draft validation omitted contract-critical header/payload fields. R2 fixes both:

- PG production path now acquires a transaction-scoped advisory lock keyed by tenant/company/reference before the read/create side effect.
- The service queries all same-reference documents and throws `pos_account_charge_draft_conflict:multiple_documents_for_event` if more than one exists.
- Existing draft validation now checks `fiscal_category`, nullable `document_number`, `source_document_id`, `due_date`, `currency`, `payload.fiscal_event_id`, `payload.account_charge_uuid`, and exact `payload.canonical_payload`.
- R2 tests cover both duplicate same-reference drafts and contract header drift.

Opus R2 found one R2-specific defect: strict PHP array identity on `payload.canonical_payload` could reject a semantically identical PostgreSQL `jsonb` payload after key-order normalization. R3 fixes this by recursively sorting associative keys before comparing payload snapshots while preserving list order, and adds a regression that reorders the stored canonical payload keys before replay.

### Dead-Path Rebuild

PASS. `DocumentAccountChargeFactureBridge` is tagged in `DocumentServiceProvider` under `FiscalEventProjector`, and the provider-registration test resolves the tagged projector list and asserts the bridge name is present.

### Contract Drift

PASS. The code follows the locked plan items: `document_account_charge_facture_bridge`, `ACCOUNT_CHARGE` only, priority `170`, `Sales` activation token, business + `b2b_facture_draft_requested` predicate, draft invoice from sealed payload, canonical snapshot in document payload, and CI PG filter wiring in the same commit.

### Constructor Injection / Rule 13

PASS for production code. The new bridge uses constructor injection for `CanonicalPayloadReader` and `POSAccountChargeDraftService`. No new `app()`, `App::make`, or `resolve()` calls were added in production code. The test uses `$this->app->make()` as a test harness helper only.

### Test Matrix

PASS. Tests cover draft creation, B2C skip, idempotency, tampered replay conflict, cross-company customer fail-loud, no POS Tax Invoice authoring/posting, contract values, and provider registration.

## Residual Notes

- The draft document uses `FiscalCategory::TaxInvoice` because existing `DocumentType::Invoice` semantics classify invoices that way, but `FiscalStatus::Draft`, `DocumentStatus::Draft`, null fiscal chain columns, and the no-new-fiscal-event test keep the D8 boundary intact.
- The bridge intentionally does not write POS `line_uuid` into `document_lines.source_line_id`; that column is a self-FK to `document_lines` and rejected the initial attempt during focused tests.
