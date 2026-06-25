# R-8 Codex Review

Scope reviewed:

- `apps/web/src/features/documents/components/DocumentLineEditor.tsx`
- `apps/web/src/features/documents/components/__tests__/DocumentLineEditor.test.tsx`
- `apps/web/src/features/documents/components/__tests__/DocumentComponents.tenantScope.test.tsx`

Findings:

- No blocking issues found.
- The Service search tab is hidden when `hasModule('Workshop')` is false.
- The services query is disabled unless Workshop is enabled and the effective
  tab is `service`.
- The implementation keeps hooks unconditional and uses an `activeSearchTab`
  fallback to avoid stale service state during config transitions.
- The tenant-scope test mock now provides `useCompanyConfig().hasModule()` so
  real `DocumentLineEditor` renders do not crash.
- Invoice/media attachment wiring is untouched.

Verification:

- RED before implementation:
  `pnpm --filter @autoerp/web test -- DocumentLineEditor.test.tsx` failed the
  non-Workshop test because the Service tab was still rendered.
- GREEN after implementation:
  `pnpm --filter @autoerp/web test -- DocumentLineEditor.test.tsx` passed
  11 tests.
- `pnpm --filter @autoerp/web typecheck` passed.
- `pnpm --filter @autoerp/web exec eslint src/features/documents/components/DocumentLineEditor.tsx src/features/documents/components/__tests__/DocumentLineEditor.test.tsx src/features/documents/components/__tests__/DocumentComponents.tenantScope.test.tsx`
  reported no errors and 20 pre-existing warnings in the touched files.
- `npx react-doctor@latest --verbose --scope changed --base origin/dev`
  reported no issues and score 91/100.
- `pnpm --filter @autoerp/web test -- DocumentLineEditor.test.tsx DocumentComponents.tenantScope.test.tsx`
  still fails in `DocumentComponents.tenantScope.test.tsx` on unrelated
  pre-existing product-modal/add-cost test expectations; it no longer fails on
  `useCompanyConfig()`.
