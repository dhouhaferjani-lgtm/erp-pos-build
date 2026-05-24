# Phase 3 Task 1 Opus Review

Reviewed commits:

- `2422ea891` — `Phase 3.1.1: Add account charge payload registry`
- `d36a72198` — `Phase 3.1.2: Validate account charge append payloads`

Plan: `docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md` Task 1

Spec: `docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md`

Codex reviews:

- `docs/superpowers/reviews/2026-05-21-task-1-codex-review.md`
- `docs/superpowers/reviews/2026-05-21-task-1-r2-codex-review.md`

Verdict: REQUEST-CHANGES

## Findings

### 1. ACCOUNT_CHARGE golden fixtures lock a payload that does not match the locked nested contract

The top-level 28-key set is present in both PHP and TS (`apps/api/app/Modules/Fiscal/Domain/DTOs/AccountChargePayload.php:9`-`38`, `apps/pos/src/lib/fiscal/payloads/AccountChargePayload.ts:1`-`30`), but the Task 1 canonical fixtures and TS types already diverge from the locked spec's nested payload shape. That makes the golden canonical bytes a drift vector instead of a reliable contract gate.

Concrete mismatches:

- `invoice_classification` is typed and emitted as `B2C_ACCOUNT_CHARGE` / `B2B_FACTURE_DRAFT_SOURCE` (`apps/pos/src/lib/fiscal/payloads/AccountChargePayload.ts:148`, `:198`; PHP fixture at `apps/api/tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php:525`), while the spec locks `b2c_charge_receipt` / `b2b_facture_draft_requested` (`docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:146`, `:275`).
- `customer.account_identifier` is required by the spec (`docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:168`-`178`) but absent from the TS customer type and both golden fixtures (`apps/pos/src/lib/fiscal/payloads/AccountChargePayload.ts:56`-`65`, `:187`-`196`; PHP fixture `apps/api/tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php:514`-`523`).
- Populated `buyer` must mirror SALE_RECEIPT exactly as `address`, `codice_fiscale`, `contact_id`, `customer_id`, `name`, `tax_number` (`docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:180`-`185`), but the TS type has `email` and `phone` and no `contact_id` (`apps/pos/src/lib/fiscal/payloads/AccountChargePayload.ts:46`-`54`).
- `line_items[]` must include `line_uuid` (`docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:187`-`202`), but the TS line type and fixtures omit it (`apps/pos/src/lib/fiscal/payloads/AccountChargePayload.ts:67`-`81`, `:199`-`:214`; PHP fixture `apps/api/tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php:526`-`540`).
- `totals.grand_total_before_charge` is required by the spec (`docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:204`-`210`) but absent from TS and PHP fixtures (`apps/pos/src/lib/fiscal/payloads/AccountChargePayload.ts:116`-`121`, `:251`-`:256`; PHP fixture `apps/api/tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php:571`-`576`).
- `credit_decision` spec fields are `policy_version`, `credit_limit`, `credit_available_before`, `credit_available_after`, `limit_exceeded`, `mirror_stale_at_authoring`, `stale_policy_action`, and `warnings` (`docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:223`-`233`), but the TS/PHP fixtures use `limit_remaining_after` and `rule_code` instead (`apps/pos/src/lib/fiscal/payloads/AccountChargePayload.ts:103`-`108`, `:179`-`:184`; PHP fixture `apps/api/tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php:506`-`511`).

Because `goldenAccountChargeCanonicalBytes` is generated from that non-spec payload (`apps/pos/src/lib/fiscal/payloads/AccountChargePayload.ts:272`-`273`) and `FiscalEventEngine` now accepts any object with the right 28 top-level keys (`apps/pos/src/lib/fiscal/FiscalEventEngine.ts:1188`-`1197`), the "happy path" append test seals a payload that Task 2's strict parser should later reject (`apps/pos/src/lib/fiscal/__tests__/FiscalEventEngine.test.ts:1401`-`1408`). That is a contract-drift failure in the canonical drift gate.

Concrete fix: update the PHP fixture, TS types, `goldenAccountChargePayload()`, and `goldenAccountChargeCanonicalBytes` to match the locked nested spec now, or reduce Task 1's exported TS nested types/fixture to an explicitly temporary top-level-only test and add a failing Task 2 handoff marker. The cleaner fix is to make the golden payload spec-valid now so later strict parser work does not have to rewrite the canonical fixture.

### 2. ACCOUNT_CHARGE lacks a PHP/TS key-list drift gate

The existing engine tests byte-compare TS key lists to PHP for `SALE_RECEIPT` and `ACCOUNT_PAYMENT` (`apps/pos/src/lib/fiscal/__tests__/FiscalEventEngine.test.ts:1422`-`1447`), but Task 1 only asserts that `ACCOUNT_CHARGE_PAYLOAD_KEYS` has 28 entries (`apps/pos/src/lib/fiscal/__tests__/FiscalEventEngine.test.ts:1449`-`1451`). If PHP `AccountChargePayload::PAYLOAD_KEYS` and TS `ACCOUNT_CHARGE_PAYLOAD_KEYS` drift while staying length 28, this test will still pass.

Concrete fix: add an `ACCOUNT_CHARGE` variant of the existing PHP-key extraction drift gate and assert sorted equality with `AccountChargePayload::PAYLOAD_KEYS`. Keep the hardcoded key-list test too, but do not rely on length as the cross-language contract.

## Verified Passes

- PHP registry implements `ACCOUNT_CHARGE` at version 1 (`apps/api/app/Modules/Fiscal/Application/Services/FiscalEventPayloadRegistry.php:39`-`46`) and tests assert the DTO and version (`apps/api/tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php:85`-`92`).
- TS registry implements `ACCOUNT_CHARGE` at version 1 without changing server-only types (`apps/pos/src/lib/fiscal/FiscalEventPayloadRegistry.ts:77`-`84`, `:99`-`102`; tests at `apps/pos/src/lib/fiscal/__tests__/FiscalEventPayloadRegistry.test.ts:69`-`76`).
- The R2 fail-loud append branch exists. `FiscalEventEngine.append()` validates before version resolution and mutation (`apps/pos/src/lib/fiscal/FiscalEventEngine.ts:489`-`499`), routes `ACCOUNT_CHARGE` to `validateAccountChargePayload()` (`:749`-`:759`), rejects non-object payloads (`:1188`-`:1193`), and enforces the exact top-level key set (`:1194`-`:1197`, `:1742`-`:1772`). The extra-key test also proves no local mutation on rejection (`apps/pos/src/lib/fiscal/__tests__/FiscalEventEngine.test.ts:1410`-`1419`).
- Cross-tenant FK safety and D16 are not applicable to this task; no FK lookup, projector, or module bridge was added.
- No production `app()`, `App::make()`, or `resolve()` usage was introduced. No new class-level skip was introduced by these commits.

## Verification Commands

Commands run from `/Users/houssamr/Projects/syneriva/apps/erp.phase-3`:

- `git status --short && git branch --show-current && git log --oneline -8`
- `git show --stat --oneline 2422ea891 d36a72198`
- `nl -ba docs/superpowers/reviews/2026-05-21-task-1-codex-review.md`
- `nl -ba docs/superpowers/reviews/2026-05-21-task-1-r2-codex-review.md`
- `nl -ba apps/api/app/Modules/Fiscal/Application/Services/FiscalEventPayloadRegistry.php`
- `nl -ba apps/api/app/Modules/Fiscal/Domain/DTOs/AccountChargePayload.php`
- `nl -ba apps/pos/src/lib/fiscal/FiscalEventPayloadRegistry.ts`
- `nl -ba apps/pos/src/lib/fiscal/payloads/AccountChargePayload.ts`
- `rg -n "validateRequestPayload|validateAccountCharge|ACCOUNT_CHARGE|assertNoExtraTopLevelKeys|serverOnlyTypes|FiscalEventPayloadRegistry|event_version" apps/pos/src/lib/fiscal/FiscalEventEngine.ts apps/pos/src/lib/fiscal/__tests__/FiscalEventEngine.test.ts`
- `nl -ba apps/pos/src/lib/fiscal/FiscalEventEngine.ts | sed -n '480,765p;1180,1210p;1736,1810p'`
- `nl -ba apps/pos/src/lib/fiscal/__tests__/AccountChargePayload.test.ts`
- `nl -ba apps/pos/src/lib/fiscal/__tests__/accountChargeCanonicalParity.test.ts`
- `nl -ba apps/pos/src/lib/fiscal/__tests__/FiscalEventPayloadRegistry.test.ts`
- `nl -ba apps/api/tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php | sed -n '1,240p'`
- `nl -ba apps/api/tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php | sed -n '493,590p'`
- `nl -ba docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md | sed -n '131,248p'`
- `rg -n "readPhpAccountCharge|ACCOUNT_CHARGE_PAYLOAD_KEYS byte-mirrors|AccountChargePayload.php|PAYLOAD_KEYS" apps/pos/src/lib/fiscal/__tests__/FiscalEventEngine.test.ts apps/pos/src/lib/fiscal/__tests__ apps/pos/src/lib/fiscal/payloads/AccountChargePayload.ts`
- `rg -n "phpstan-ignore|skip|markTestSkipped|@group|@doesNotPerformAssertions|app\\(|App::make|resolve\\(" apps/api/app/Modules/Fiscal/Domain/DTOs/AccountChargePayload.php apps/api/app/Modules/Fiscal/Application/Services/FiscalEventPayloadRegistry.php apps/pos/src/lib/fiscal/FiscalEventPayloadRegistry.ts apps/pos/src/lib/fiscal/FiscalEventEngine.ts apps/pos/src/lib/fiscal/payloads/AccountChargePayload.ts apps/pos/src/lib/fiscal/__tests__/FiscalEventEngine.test.ts apps/pos/src/lib/fiscal/__tests__/AccountChargePayload.test.ts apps/pos/src/lib/fiscal/__tests__/accountChargeCanonicalParity.test.ts apps/api/tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php`

No runtime test suite was rerun for this second-pass review; I inspected the implementation and used the Codex R2 verification record for prior pass/fail evidence.
