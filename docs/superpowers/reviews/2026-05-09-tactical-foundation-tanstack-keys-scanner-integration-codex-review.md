Commit reviewed: 11b082a0
Verdict: REQUEST-CHANGES

## Findings

### F1 - JS scanner does not unwrap parenthesized queryKey expressions

`apps/web/tools/audit-tanstack-keys.mjs:120`, `apps/web/tools/audit-tanstack-keys.mjs:196`, and `apps/web/tools/audit-tanstack-keys.mjs:286` unwrap `as`, `<T>`, and `satisfies` expressions, but not `ParenthesizedExpression`.

The brief explicitly calls out this edge: `(['users'] as const)`. I verified inline that the scanner currently flags even a scoped parenthesized key:

```ts
useQuery({
  queryKey: (['users', currentCompanyId] as const),
  queryFn: () => f(),
})
```

Observed JSON result:

```json
{
  "reason": "useQuery({ queryKey: ... }) lacks an approved tenant scope",
  "resource": null,
  "statement_fingerprint": "(['users', currentCompanyId] as const)@11",
  "ast_kind": "other"
}
```

That is a false positive against an approved scoped key, and it also corrupts the metadata contract (`resource` should be `users`; `ast_kind` should be `array_literal`). The current tests only cover unparenthesized `as const` and `satisfies` at `apps/web/tools/__tests__/audit-tanstack-keys.test.mjs:91` and `apps/web/tools/__tests__/audit-tanstack-keys.test.mjs:101`, so the regression is not pinned.

Requested fix: add a shared expression-unwrapper that strips parentheses as well as `as`, type assertions, and `satisfies`, then use it consistently in approval, resource extraction, and AST-kind classification. Add Vitest coverage for at least:

- `queryKey: (['users', currentCompanyId] as const)` is approved.
- `queryKey: (['users'] as const)` is flagged with `resource: "users"` and `ast_kind: "array_literal"`.
- Optional: parenthesized `tenantScopedKey([...])` is approved if that style appears during batch fixes.

## Notes

- The `tenantScopedKey()` helper reads initialized Zustand store exports safely; `useAuthStore` and `useCompanyStore` are module-level store functions, so `.getState()` is available even before login/hydration.
- The null-sentinel behavior is acceptable as a helper contract only when fix batches also add/retain an `enabled` guard that subscribes to the relevant auth/company state. The helper by itself does not subscribe, so relying only on ancestor provider re-renders is weaker than the doc comment implies.
- `AuthProvider`, `CompanyProvider`, and `LocationProvider` have query keys that are either pre-auth/bootstrap keys or already subscribe to the state they include. These need per-callsite decisions during later batch work; they are not foundation blockers.
- The PHP scanner architecture is acceptable for this foundation pass: the process wrapper degrades to `[]`, partial `--scanners=` runs are merge-safe because `applyMerge()` only iterates scanner-produced rows, and hardcoding `web.tanstack-keys` is consistent with one scanner per cluster.
- The byte-offset discriminator is load-bearing and works for the current seed. In `apps/web/src/features/batches/hooks/useBatches.ts`, multiple `onSuccess` callsites in the same file/symbol have distinct stable keys.
- Current branch tip is `9afd42a2`, a docs-only review prompt commit. This review follows the required header and reviews foundation commit `11b082a0`.

## Seed Integrity Checked

- `web.tanstack-keys` callsites: 849.
- Status distribution: 849 `pending`.
- Owners: 849 `null`.
- Stable keys: 849 unique.
- Generate events: 849.
- All 849 generate events share `previous_yaml_sha256: a563ac83f041735ddf4876b53795192a7344504125aa76f9a52edfdb84093054`.
- All 849 generate events share `new_yaml_sha256: f48e439764ae67a48e5c3980d31427cabb8830ef6adda73daa2a44c21e10b2b4`.
- Pattern-type distribution matches the brief exactly.

## Verification Run

```text
php artisan sweep:inventory:verify-history
verified 2784 event(s) across 1205 callsite(s); 0 problem(s).

./vendor/bin/phpstan analyse app/Application/Sweep/Scanners/TanstackKeysScanner.php tests/Unit/Application/Sweep/Scanners/TanstackKeysScannerTest.php tests/Architecture/SweepScannerRegistrationTest.php
[OK] No errors

./vendor/bin/phpunit tests/Unit/Application/Sweep/Scanners/TanstackKeysScannerTest.php tests/Architecture/SweepScannerRegistrationTest.php
OK (10 tests, 43 assertions)

pnpm vitest run src/lib/__tests__/tenantScopedKey.test.ts tools/__tests__/audit-tanstack-keys.test.mjs
2 files passed; 29 tests passed

pnpm typecheck
passed

node apps/web/tools/audit-tanstack-keys.mjs --json | python3 -c 'import json, sys; d=json.loads(sys.stdin.read()); print(len(d["violations"]))'
849
```

Additional dry-runs:

```text
php artisan sweep:inventory:generate --scanners=ts_query_key --dry-run
[dry-run] sweep:inventory:generate - 0 new, 849 unchanged, 0 needs_recheck, 0 stale_orphan

php artisan sweep:inventory:generate --dry-run
[dry-run] sweep:inventory:generate - 1 new, 935 unchanged, 4 needs_recheck, 3 stale_orphan
```

The full-scanner drift remains outside this foundation review, but the count has moved from the brief's `4 needs_recheck + 3 stale_orphan` to `1 new + 4 needs_recheck + 3 stale_orphan` in the current worktree.
