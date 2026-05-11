Commit reviewed: 5090b4f8
Verdict: APPROVE

## Findings

No blocking findings.

## Round-1 Closure

F1 is closed. `apps/web/tools/audit-tanstack-keys.mjs` now has a shared `unwrapKeyExpression()` helper that strips `ParenthesizedExpression`, `as`, type assertions, and `satisfies` wrappers. The scanner uses it in all three shape-sensitive paths: approval, resource extraction, and AST-kind classification.

Direct inline probe:

```text
scoped parenthesized key: []
unscoped parenthesized key: resource="users", ast_kind="array_literal"
parenthesized tenantScopedKey(): []
```

The added Vitest cases cover parenthesized scoped arrays, unscoped arrays with metadata, parenthesized `tenantScopedKey()`, and nested parentheses.

The `tenantScopedKey()` doc comment is also tightened. It no longer overstates ancestor provider re-render guarantees and now states that the helper reads `getState()` without subscribing; callers need a host subscription or an `enabled` guard tied to subscribed auth/company values for causal recomputation.

## Contracts Rechecked

- PHP scanner architecture remains unchanged and covered by PHPStan plus scanner unit tests.
- Scanner registration architecture test remains green.
- Byte-offset discriminator remains intact; the current seed still has 849 unique stable keys.
- Seed integrity remains stable: 849 `web.tanstack-keys` rows, all `pending`, all `owner: null`, 849 generate events, one shared previous YAML hash, and one shared new YAML hash.
- Pattern-type distribution still matches round 1.
- TS-only dry-run remains stable: `0 new, 849 unchanged, 0 needs_recheck, 0 stale_orphan`.

## Verification Run

```text
php artisan sweep:inventory:verify-history
verified 2784 event(s) across 1205 callsite(s); 0 problem(s).

./vendor/bin/phpstan analyse app/Application/Sweep/Scanners/TanstackKeysScanner.php tests/Unit/Application/Sweep/Scanners/TanstackKeysScannerTest.php tests/Architecture/SweepScannerRegistrationTest.php
[OK] No errors

./vendor/bin/phpunit tests/Unit/Application/Sweep/Scanners/TanstackKeysScannerTest.php tests/Architecture/SweepScannerRegistrationTest.php
OK (10 tests, 43 assertions)

pnpm vitest run src/lib/__tests__/tenantScopedKey.test.ts tools/__tests__/audit-tanstack-keys.test.mjs
2 files passed; 33 tests passed

pnpm typecheck
passed

node apps/web/tools/audit-tanstack-keys.mjs --json | python3 -c 'import json, sys; d=json.loads(sys.stdin.read()); print(len(d["violations"]))'
849

php artisan sweep:inventory:generate --scanners=ts_query_key --dry-run
[dry-run] sweep:inventory:generate - 0 new, 849 unchanged, 0 needs_recheck, 0 stale_orphan
```
