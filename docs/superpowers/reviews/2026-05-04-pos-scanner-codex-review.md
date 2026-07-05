# Codex contrarian review — Phase 2B.2 POS local-cache scanner

Review date: 2026-05-04
Branch reviewed: feat/tenant-isolation-pos-scanner
Commit reviewed: 99cc06b4
PR: #81
Reviewer: codex

## Verdict

BLOCK

## Findings

- **#1 — critical — Sync-envelope validation accepts any tenant_id reference as validation**
  - **Location**: `apps/web/tools/audit-pos-local-cache.mjs:382`
  - **Issue**: The handler analysis sets `tenantValidated = true` whenever a top-level statement's subtree references `envelope.tenant_id`, before checking for payload access. That means these unsafe handlers produce zero findings: `setupValidator(() => envelope.tenant_id); const payload = envelope.payload;`, `const tenantId = envelope.tenant_id; const payload = envelope.payload;`, and `const { tenant_id, payload } = envelope;`. A read, capture, deferred callback, or destructure is not a tenant check, so this lets real sync-envelope gaps through the hard gate.
  - **Fix**: Replace the "any tenant_id reference" flag with a validation recognizer that only accepts immediate top-level guard shapes before payload access: `if`/throw or early-return comparisons against active auth context, assertion/helper calls with explicit allowlisted validator names, or equivalent synchronous checks. Check payload access before marking validation for the same statement, and add coverage for destructuring `payload` together with `tenant_id`.
  - **Test**: Add negative-regression fixtures asserting that read-only tenant references, deferred callback references, and same-statement `{ tenant_id, payload } = envelope` destructuring are flagged until a real guard precedes payload access.

- **#2 — important — Default target ENOENT skip can hide renamed POS scanner surfaces**
  - **Location**: `apps/web/tools/audit-pos-local-cache.mjs:555`
  - **Issue**: In no-arg gate mode, missing default targets are silently skipped. If `apps/pos/src/lib/sync/syncService.ts` or a SQLite target is renamed, the CI gate can pass while no longer scanning one of the three master-plan surfaces.
  - **Fix**: For CI/default mode, require all `DEFAULT_TARGETS` to exist once this scanner is wired as a hard hook. If branch portability is still needed, put that behind an explicit flag such as `--allow-missing-default-targets` so silent skipping is never the default gate behavior.
  - **Test**: Add a CLI test that temporarily points the default target list at a missing file, or exposes a test-only target override, and asserts no-arg mode exits non-zero with a clear missing-target message.

## What looks good

- Stable-key generation is byte-for-byte compatible with the PHP helper for the tested synthetic input.
- The guarded table mirror exactly matches `PhpPresentationExistsScanner::DEFAULT_GUARDED_TABLES`: 37 lowercase alphabetical entries, same order.
- The CREATE TABLE rule intentionally requires both `tenant_id` and `company_id`; that stricter interpretation is sound for `expected_scope: tenant_and_company`.
- The paren-balanced SQL extractor handled nested parens in `DEFAULT (CURRENT_TIMESTAMP)` and flagged `products` as expected.

## Stable-key cross-language verification

PASS. Synthetic input:

```json
{
  "surface": "tauri",
  "scanner": "pos_sqlite_cache",
  "normalized_relative_path": "apps/pos/src/lib/db/migrations.ts",
  "symbol_fqn": "runMigrations",
  "ast_node_kind": "sqlite_create_table",
  "model_or_table": "payment_methods",
  "field_or_method": null,
  "normalized_argument_name": null,
  "statement_fingerprint": "tenant_and_company"
}
```

Both JS and PHP returned:

```text
sha256:97e00ff7a24179b37fa0505d7b55d44dafefc156ec7fd37cdf52ef32e82057d4
```

## Guarded-table list parity

PASS. JS list == PHP list, alphabetical, 37 entries.

## Verification commands run

```bash
pnpm vitest run tools/__tests__/audit-pos-local-cache.test.mjs
```

Result: PASS, 8/8 tests passed in 1 file.

```bash
pnpm test
```

Result: PASS, 181 files passed, 1648 passed, 1 skipped.

```bash
pnpm test:arch
```

Result: PASS, Gate C count = 849.

```bash
pnpm lint
```

Result: PASS, 0 errors, 10944 warnings.

```bash
git diff --stat feat/tenant-isolation-sweep-execution..feat/tenant-isolation-pos-scanner -- apps/api/
git diff --stat feat/tenant-isolation-sweep-execution..feat/tenant-isolation-pos-scanner -- docs/superpowers/plans/
git diff feat/tenant-isolation-sweep-execution..feat/tenant-isolation-pos-scanner -- apps/web/tools/audit-tanstack-keys.mjs
```

Result: PASS, all empty.

```bash
node apps/web/tools/audit-pos-local-cache.mjs apps/web/tools/__fixtures__/audit-pos-local-cache/positive/create-table-without-tenant.ts
```

Result: PASS, exited 1 and reported `payment_methods`.

```bash
node apps/web/tools/audit-pos-local-cache.mjs --emit-inventory-rows apps/web/tools/__fixtures__/audit-pos-local-cache/positive/create-table-without-tenant.ts
```

Result: PASS, exited 0 and emitted one JSON row.

```bash
node apps/web/tools/audit-pos-local-cache.mjs
```

Result: PASS, exited 1 and reported 5 real findings in `apps/pos/src/lib/db/migrations.ts`.

```bash
node apps/web/tools/audit-pos-local-cache.mjs --unknown-flag
```

Result: PASS, rejected the unknown flag with `Error: Unknown flag: --unknown-flag`.

Additional probes:

```bash
node -e 'import("./apps/web/tools/audit-pos-local-cache.mjs").then(m => { const cases = { deferred: "function handleSyncEnvelope(envelope) {\n  setupValidator(() => envelope.tenant_id);\n  const payload = envelope.payload;\n  return payload;\n}", readOnly: "function handleSyncEnvelope(envelope) {\n  const tenantId = envelope.tenant_id;\n  const payload = envelope.payload;\n  return { tenantId, payload };\n}", destructureBoth: "function handleSyncEnvelope(envelope) {\n  const { tenant_id, payload } = envelope;\n  if (tenant_id !== currentAuthContext.tenantId) throw new Error();\n  return payload;\n}" }; for (const [name, code] of Object.entries(cases)) console.log(name, JSON.stringify(m.scanCode(code, `${name}.ts`).map(v => v.pattern_type))); })'
```

Result: FAIL for scanner behavior. All three unsafe cases returned `[]`.
