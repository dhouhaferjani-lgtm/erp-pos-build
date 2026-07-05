# Codex contrarian round-2 review — Phase 2B.2 POS local-cache scanner

Review date: 2026-05-04
Branch reviewed: feat/tenant-isolation-pos-scanner
Round-1 verdict: BLOCK (2 findings)
Round-2 base commit: 99cc06b4
Round-2 remediation commit: fc898dc9
PR: #81
Reviewer: codex

## Verdict

REQUEST-CHANGES

## Round-1 Finding 1 status

- Status: PARTIALLY-CLOSED
- Notes: The three round-1 demonstrations are closed: deferred arrow callback, read-only assignment, and same-statement `{ tenant_id, payload }` destructure all now flag. The loop also checks payload access before guard status, as claimed. However, adversarial probing found two remaining bypasses that still let non-validation be credited as validation:
  - `if (class Hidden { constructor() { void envelope.tenant_id; } }) {}` before `const payload = envelope.payload` produces 0 findings. `subtreeReferencesEnvelopePropAtTopLevel()` stops at functions, methods, and accessors, but not `ConstructorDeclaration`, so a nested constructor body is treated as a top-level tenant reference.
  - `tenant.assertEnvelopeTenant(envelope); const payload = envelope.payload` produces 0 findings even when `tenant.assertEnvelopeTenant` is a local no-op object method. `isTenantGuardStatement()` accepts `PropertyAccessExpression` callees by terminal method name only, so any object with an allowlisted method name can satisfy the scanner.

Other closure observations:

- Object-literal method shorthand `{ foo() { envelope.tenant_id } }` inside an `if` did flag, so `ts.isMethodDeclaration()` covers that shape.
- `assertEnvelopeTenant({ tenant_id: 'attacker-controlled' })` correctly flagged; the synthetic object literal does not contain `envelope.tenant_id`.
- A real guard nested in `try { if (...) throw ... } finally {}` or inside a labeled block does not count because the loop only checks immediate `body.statements`. That is a false positive risk for defensive code, not a safety bypass.
- I found no current real POS sync helper under `apps/pos/src/lib/sync/syncService.ts` using a validator name outside the four-name allowlist. If POS adds a helper, prefer one canonical bare imported helper name rather than growing arbitrary method shapes.

## Round-1 Finding 2 status

- Status: CLOSED
- Notes: Default-target ENOENT now exits 2 unless `--allow-missing-default-targets` is present and the scanner is in no-positional-args mode. Missing explicit positional args still exit 2 even with the flag. The env override is implemented as comma split plus trim/filter, which is acceptable for this POS repo path use case.

## New findings

### Important — `apps/web/tools/audit-pos-local-cache.mjs:388` — constructor bodies bypass the top-level guard boundary

`subtreeReferencesEnvelopePropAtTopLevel()` does not stop at `ts.isConstructorDeclaration`. A guard hidden in a class constructor inside an `if` condition is incorrectly credited as tenant validation:

```ts
export function handleSyncEnvelope(envelope) {
  if (class Hidden {
    constructor() {
      void envelope.tenant_id;
    }
  }) {}
  const payload = envelope.payload;
  return payload;
}
```

Observed scanner result: 0 findings.

Fix: include `ts.isConstructorDeclaration(node)` in the stop list. Consider stopping at class declarations/expressions as a broader boundary, and add this fixture to the negative-flag coverage.

### Important — `apps/web/tools/audit-pos-local-cache.mjs:482` — member-call helper recognition accepts no-op impostors

`isTenantGuardStatement()` accepts a `PropertyAccessExpression` callee by final property name, so `tenant.assertEnvelopeTenant(envelope)` is treated the same as a canonical imported helper call. This can hide an unvalidated payload read:

```ts
export function handleSyncEnvelope(envelope) {
  const tenant = {
    assertEnvelopeTenant(_envelope) {
      // no-op
    },
  };
  tenant.assertEnvelopeTenant(envelope);
  const payload = envelope.payload;
  return payload;
}
```

Observed scanner result: 0 findings.

Fix: require the allowlisted helper callee to be a bare `Identifier`, or make the recognizer import/binding-aware and accept only the canonical validator module.

## What looks good

- The original positive/negative sync-envelope fixtures still behave correctly: missing tenant check flags, tenant check before payload does not.
- The new round-2 fixtures all behave as intended, including same-statement destructure and helper-call acceptance.
- Real-world scanner output is unchanged: no-arg scan reports the same 5 SQLite CREATE TABLE findings in `apps/pos/src/lib/db/migrations.ts`.
- ENOENT behavior is now explicit and tested; missing default targets fail closed by default.

## Stable-key cross-language parity

PASS — same synthetic input as round-1.

Both JS and PHP returned:

```text
sha256:97e00ff7a24179b37fa0505d7b55d44dafefc156ec7fd37cdf52ef32e82057d4
```

## Guarded-table list parity

PASS — the guarded table list was not touched in `99cc06b4..fc898dc9`, and visual comparison against `PhpPresentationExistsScanner::DEFAULT_GUARDED_TABLES` and `TenantScopedExistsRulesTest::GUARDED_TABLES` still matches.

## Verification commands run

- `git worktree add /private/tmp/erp-pos-scanner-review fc898dc9` — created isolated review worktree.
- `pnpm vitest run tools/__tests__/audit-pos-local-cache.test.mjs` from `apps/web` — PASS, 14/14 tests.
- `pnpm test` from `apps/web` — PASS, 181 files, 1654 passed, 1 skipped.
- `pnpm test:arch` from `apps/web` — PASS, Gate C count = 849.
- `pnpm lint` from `apps/web` — PASS, 0 errors, 10944 warnings.
- `git diff --stat 99cc06b4..fc898dc9` — PASS, exactly scanner, scanner test, and 4 fixtures.
- `git diff 99cc06b4..fc898dc9 -- apps/web/tools/audit-pos-local-cache.mjs` — PASS for scope; changes are recognizer and CLI/default-target handling.
- `git diff --stat feat/tenant-isolation-sweep-execution..feat/tenant-isolation-pos-scanner -- apps/api/` — FAIL versus prompt expectation; branch-wide diff is non-empty under `apps/api/`. This is outside the round-2 remediation commit but does not match the stated verification expectation.
- `git diff --stat feat/tenant-isolation-sweep-execution..feat/tenant-isolation-pos-scanner -- docs/superpowers/plans/` — FAIL versus prompt expectation; branch-wide diff touches `docs/superpowers/plans/tenant-isolation-sweep-inventory.yml`.
- `git diff feat/tenant-isolation-sweep-execution..feat/tenant-isolation-pos-scanner -- apps/web/tools/audit-tanstack-keys.mjs` — PASS, empty.
- `node apps/web/tools/audit-pos-local-cache.mjs apps/web/tools/__fixtures__/audit-pos-local-cache/edge/sync-envelope-deferred-callback.ts` — PASS, 1 finding, exit 1.
- `node apps/web/tools/audit-pos-local-cache.mjs apps/web/tools/__fixtures__/audit-pos-local-cache/edge/sync-envelope-read-only-assignment.ts` — PASS, 1 finding, exit 1.
- `node apps/web/tools/audit-pos-local-cache.mjs apps/web/tools/__fixtures__/audit-pos-local-cache/edge/sync-envelope-destructure-both.ts` — PASS, 1 finding, exit 1.
- `node apps/web/tools/audit-pos-local-cache.mjs apps/web/tools/__fixtures__/audit-pos-local-cache/edge/sync-envelope-with-helper-call.ts` — PASS, 0 findings, exit 0.
- `node apps/web/tools/audit-pos-local-cache.mjs apps/web/tools/__fixtures__/audit-pos-local-cache/edge/sync-envelope-without-tenant-check.ts` — PASS, 1 finding, exit 1.
- `node apps/web/tools/audit-pos-local-cache.mjs apps/web/tools/__fixtures__/audit-pos-local-cache/edge/sync-envelope-with-tenant-check.ts` — PASS, 0 findings, exit 0.
- `AUDIT_POS_LOCAL_CACHE_DEFAULT_TARGETS=/tmp/does-not-exist-xyz.ts node apps/web/tools/audit-pos-local-cache.mjs` — PASS, `cannot read`, exit 2.
- `AUDIT_POS_LOCAL_CACHE_DEFAULT_TARGETS=/tmp/does-not-exist-xyz.ts node apps/web/tools/audit-pos-local-cache.mjs --allow-missing-default-targets` — PASS, `skipping`, exit 0.
- `AUDIT_POS_LOCAL_CACHE_DEFAULT_TARGETS=/tmp/does-not-exist-xyz.ts node apps/web/tools/audit-pos-local-cache.mjs --emit-inventory-rows --allow-missing-default-targets` — observed `skipping`, no rows, exit 0. Acceptable only because the permissive flag is explicit.
- `node apps/web/tools/audit-pos-local-cache.mjs --allow-missing-default-targets /tmp/does-not-exist-xyz.ts` — PASS, explicit positional arg still exits 2.
- `node apps/web/tools/audit-pos-local-cache.mjs` — PASS, 5 real findings: `products`, `payment_methods`, `payment_repositories`, `vouchers`, `voucher_ledger`.
- `node apps/web/tools/audit-pos-local-cache.mjs apps/pos/src/lib/sync/syncService.ts` — PASS, 0 findings.
- `/private/tmp/erp-pos-scanner-adversarial.mjs` synthetic probe — FAIL for constructor-hidden guard and fake member helper; PASS for synthetic object helper arg and object-literal method shorthand.
