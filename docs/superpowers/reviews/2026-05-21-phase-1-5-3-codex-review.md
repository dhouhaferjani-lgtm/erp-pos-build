# Phase 1.5.3 Codex Self-Adversarial Review

**Commit reviewed:** `250150211` (`Phase 1.5.3: Add fiscal quarantine parse assist`)

**Scope reviewed:**

- Backend best-effort parser DTOs/service/resource/controller.
- Fiscal route registration for `POST /api/v1/fiscal/quarantine/{id}/best-effort-parse`.
- Backend unit and feature coverage for parser behavior, authorization, tenant isolation, and quarantine/fiscal-event source handling.
- Frontend API wrapper, protected route, operator assist page, permission map, and focused Vitest coverage.

## Verdict

APPROVE.

No BLOCKER or REQUEST-CHANGES findings.

## Findings

None.

## Standing-Pattern Attack Vectors Checked

1. **Cross-tenant FK safety:** The controller scopes both `FiscalEventQuarantine` and `FiscalEvent` lookups by the authenticated user's `tenant_id`. The feature does not add any projection FK writes, so the Task 21 / Pass 2A PHP cross-tenant class of defect is not reopened.
2. **Fail-loud vs silent downgrade:** Strict canonical parsing is attempted first. Strict failures become explicit parse defects, invalid JSON returns an explicit `canonical_bytes` defect, and non-parse-failure fiscal events return HTTP 409. The assist path does not silently mark a failed event as resolved.
3. **Dead-path rebuild:** The parser has live callers through the controller route, the protected frontend route, and the operator page. Backend and frontend tests exercise the new path.
4. **Discriminated-union matrix completeness:** No new discriminated-union DTO contract is introduced. The parser result variants covered are strict-success/no-defect, lenient partial-success/defects, invalid JSON, quarantine-table source, in-table fiscal-event source, forbidden tenant access, and non-parse-failure conflict.
5. **Contract drift:** The parser derives canonical top-level keys from `FiscalPayloadConstraintValidator::PAYLOAD_KEYS` and validates through the existing strict parser, payload registry, and constraint validator. It does not duplicate or redefine the SALE_RECEIPT 27-key contract in the operator UX.
6. **Per-method skips only:** No new skipped tests were added.
7. **Skip-citation accuracy:** No skip citations were added or modified.
8. **Constructor injection only:** Touched production PHP files contain no `app()`, `App::make`, or `resolve()` service-location calls. Dependencies are constructor-injected.
9. **D16 bounded-module guard:** No Treasury, Customer, B2B, or Accounting module dependency is introduced. This is a Fiscal operator-assist surface only.
10. **R2-fix risk pattern:** The late adjustment to prefer `fiscal_event_quarantine.raw_envelope` for quarantine rows is covered by the feature test asserting raw-envelope canonical bytes are used.
11. **Resolution boundary:** `fiscal_event_quarantine` rows are parse-assist only and return `fiscal_event_id: null`; the implementation does not fabricate a resolution path because the existing `ParseFailureResolutionService` resolves in-table `fiscal_events` parse failures. This preserves the existing validation and immutability boundary.

## Verification Reviewed

- `./vendor/bin/phpunit tests/Unit/Fiscal/BestEffortPayloadParserTest.php tests/Feature/Fiscal/QuarantineBestEffortParseControllerTest.php` passed: 7 tests, 28 assertions.
- `./vendor/bin/phpstan analyse --level=8 app/Modules/Fiscal/` passed.
- `./vendor/bin/pint --test app/Modules/Fiscal/ tests/Unit/Fiscal/BestEffortPayloadParserTest.php tests/Feature/Fiscal/QuarantineBestEffortParseControllerTest.php` passed.
- `./vendor/bin/phpunit tests/Feature/Fiscal/ tests/Unit/Fiscal/` passed: 554 tests, 2092 assertions, 45 skipped.
- `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Feature/POS/` passed: 583 tests, 1767 assertions, 62 skipped, 2 incomplete.
- `bash apps/pos/scripts/check-pass-2b-pending.sh` passed.
- `bash apps/api/scripts/check-saleReceipt-chokepoints.sh` passed.
- `pnpm test src/features/compliance/pages/__tests__/QuarantineResolveAssistPage.test.tsx` passed.
- `pnpm typecheck` passed.
- `pnpm exec eslint src/features/compliance/api/quarantineResolutionApi.ts src/features/compliance/pages/QuarantineResolveAssistPage.tsx src/features/compliance/pages/__tests__/QuarantineResolveAssistPage.test.tsx src/routes/index.tsx src/hooks/usePermissions.ts` passed with warnings only.
- `pnpm test` passed: 2131 passed, 1 skipped.
- `pnpm lint` exited 0 with existing warning-heavy output.
