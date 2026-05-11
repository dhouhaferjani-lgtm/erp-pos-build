# Codex review request — tactical foundation for web.tanstack-keys cluster (foundation chain, NOT a cluster claim)

**Target file for the review verdict:**

Save your full review to:

```
docs/superpowers/reviews/2026-05-09-tactical-foundation-tanstack-keys-scanner-integration-codex-review.md
```

Use the canonical sweep-review header format:

```
Commit reviewed: 11b082a0
Verdict: APPROVE | APPROVE-WITH-MINOR-EDITS-APPLIED | REQUEST-CHANGES | BLOCK-NOVEL
```

Match the verdict line exactly to the `Verdict:` enum the sweep CLI parses at `apps/api/app/Console/Commands/SweepInventoryReviewCommand.php` lines 40-43.

Do **not** return the review inline. Save it to the file path above and confirm the path in your response.

---

## Branch state

- Branch: `feat/tenant-isolation-sweep-execution`
- Tip: `11b082a0`
- Foundation chain (4 commits, oldest first):
  - `32f33e8a` — `feat(tenant-isolation): tenantScopedKey() helper + Vitest TDD coverage`
  - `0fbeda35` — `feat(tenant-isolation): TanstackKeysScanner + JSON output from JS scanner`
  - `9bb4643e` — `chore(tenant-isolation): seed 849 web.tanstack-keys callsites via scanner`
  - `11b082a0` — `test(tenant-isolation): TanstackKeysScanner unit + scanner-registration arch test`

- Inventory state at tip: 1205 callsites, 2784 history events, 0 verify-history problems, web.tanstack-keys cluster `status: pending` (849 callsites).

## What this review covers (foundation work, NOT a cluster claim)

Web.tanstack-keys cluster will need ~85+ batches of per-callsite fix work. THIS commit chain is the prerequisite tooling — helper + scanner integration + seed + arch tests — that unblocks per-batch claim/start cycles in subsequent sessions. The cluster is **not** claimed; no callsite is `in_progress`; no fix has shipped.

The standard cluster-lock review path doesn't apply (no fix verification, no cross-tenant exploit test). This review covers four orthogonal contracts:

### Contract 1 — `tenantScopedKey()` helper API correctness

File: `apps/web/src/lib/tenantScopedKey.ts` (commit `32f33e8a`)

- Helper composition: `tenantScopedKey(['payment-methods'])` → `[...segments, tenantId, companyId]`. Reads from store snapshots via `getState()`; does not subscribe.
- Approved by `apps/web/tools/audit-tanstack-keys.mjs` because the JS scanner whitelists `tenantScopedKey(...)` as an `APPROVED_FACTORY_CALLS` identifier.
- Test coverage: 9 Vitest cases at `apps/web/src/lib/__tests__/tenantScopedKey.test.ts` covering happy path, multi-segment ordering, null-store sentinel emission (mid-hydration), per-axis cache-invalidation invariant (different tenantId or different companyId both produce distinct keys), idempotency, empty-segment edge case.

**Critical questions for review:**
- Is `getState()` the right read pattern given that TanStack Query's queryKey is stamped at render time? The helper claims this works because subscriber components elsewhere in the tree re-render on tenant/company change, recomputing the queryKey naturally. Audit one or two real-world subscribing components (e.g., `apps/web/src/features/auth/AuthProvider.tsx`, `apps/web/src/features/location/LocationProvider.tsx`) to verify the assumption holds.
- The helper returns `[..., null, null]` when stores are mid-hydration. The doc comment recommends pairing with `{ enabled: !!user && !!currentCompanyId }` at the call site. Is that adequate, or should the helper itself return a sentinel that auto-disables the query (e.g., `__no_tenant__` magic strings)?
- The helper imports both `useAuthStore` and `useCompanyStore`. Are there callsites that would be exercised before `useAuthStore` is initialized at all (e.g., login page, AuthProvider's own first render)? If so, the helper would crash on `.getState().user` when the store is undefined — verify the store contract.

### Contract 2 — JS scanner JSON output mode

File: `apps/web/tools/audit-tanstack-keys.mjs` (commit `0fbeda35`)

- New `--json` CLI flag emits a `{violations: [...]}` payload to stdout in addition to the existing stderr Gate C count.
- Each violation now carries `factory`, `enclosing_symbol`, `resource`, `statement_fingerprint`, and `ast_kind` so downstream consumers can build a CallsiteRow without re-parsing.
- `statement_fingerprint` suffixes the queryKey expression text with its byte offset (matching `PhpAstFindScanner::start_file_pos`).
- Stdout backpressure handling: the ~200 KB JSON payload waits for drain before exit (was truncating at 64 KB pipe-buffer boundary).
- 5 new Vitest cases pin the new fields' shape at `apps/web/tools/__tests__/audit-tanstack-keys.test.mjs`.

**Critical questions for review:**
- The byte-offset disambiguator: is it stable enough across non-related edits to keep the stable_key consistent? Specifically, if a developer adds a function ABOVE a violation (shifting all subsequent offsets), the violation's stable_key changes — is that the right rename semantic? (Master plan §4 says "stable across non-related edits"; offset-shifting from edits BEFORE the violation does change the stable_key, but those edits are arguably "related" because they sit in the same file.)
- The `findEnclosingSymbol()` walker recognizes 5 parent kinds (FunctionDeclaration, MethodDeclaration, VariableDeclaration with arrow/function, PropertyAssignment with arrow/function, named FunctionExpression). Verify against ~5 representative web.tanstack-keys callsites that the symbol resolution matches what a human would call the enclosing scope.
- The `extractQueryKeyResource()` helper unwraps `as`, `satisfies`, and `<X>typed` casts to find the underlying ArrayLiteralExpression — does it handle parenthesized expressions (`(['users'] as const)`)? Test on inline.
- Stdout backpressure: the drain handler awaits a single `'drain'` event. For payloads >> 200 KB (future scaling), is one drain event enough, or could there be multiple buffered chunks?

### Contract 3 — PHP scanner architecture + JS/PHP contract

Files:
- `apps/api/app/Application/Sweep/Scanners/TanstackKeysScanner.php` (commits `0fbeda35` + `11b082a0` refactor)
- `apps/api/app/Console/Commands/SweepInventoryGenerateCommand.php` (commit `0fbeda35` — new `--scanners=` filter)

- `TanstackKeysScanner` implements the existing `Scanner` interface; shells out to Node via `Symfony\Component\Process\Process`; degrades gracefully to `[]` on Node missing, script missing, or exit !== 0.
- `parseViolationsPayload()` is a public method exposed for the unit test to pin the JS/PHP contract directly without forking a Node subprocess. `scan()` is one line of process glue around it.
- `cluster_id` is hardcoded to `'web.tanstack-keys'` (TS files don't have a module path that maps cleanly via `ClusterResolver`).
- `--scanners=` flag accepts a comma-separated list of scanner names; foundation runs use `--scanners=ts_query_key` to scope the seed without touching fixed clusters from other surfaces.

**Critical questions for review:**
- Symfony Process with a 60-second timeout — is that enough budget for the scanner walking ~700 TS/TSX files? Empirically `node apps/web/tools/audit-tanstack-keys.mjs` runs in ~3 seconds on dev hardware; 60s is comfortable for now but consider whether the timeout should be configurable.
- Process invocation security: the scanner constructor accepts `$nodeBinary` (default `'node'`) — is there a risk of PATH manipulation in CI environments? Compare to other Symfony Process invocations in the codebase (there are none in `apps/api/app`, this is the first).
- `cluster_id` hardcoding to `'web.tanstack-keys'` works today but breaks cleanly if web ever has multiple TS scanners (e.g., a future form-selectors scanner that emits to `web.form-selectors`). Should the scanner accept a cluster_id argument to keep it composable, or is hardcoding fine because each scanner targets exactly one cluster by design?
- The `--scanners=` filter precedent: other clusters could now run partial-coverage runs (e.g., `--scanners=manual` to refresh only manual rows). Is there any operational risk to that? The merge logic in `applyMerge()` only iterates over scanner-produced rows, never deleting existing rows that no scanner emitted; partial runs are safe in that direction. Confirm.

### Contract 4 — Callsite-seed integrity + verify-history chain

File: `docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` (commit `9bb4643e`, +39056 lines)

Result of `php artisan sweep:inventory:generate --scanners=ts_query_key`:
- 849 new callsites under `cluster_id: web.tanstack-keys`
- 0 unchanged, 0 needs_recheck, 0 stale_orphan (the `--scanners=` filter scoped to TS only; PHP scanners' drift not surfaced)
- `sweep:inventory:verify-history` reports 2784 events / 1205 callsites / 0 problems (was 1935 / 356 / 0)
- Each new row is `status: pending`, `owner: null`, with one `action: generate` history event anchored by the new `metadata.yaml_sha256`

Pattern type distribution:
| count | pattern_type |
| --- | --- |
| 274 | `querykey_invalidatequeries_array_literal` |
| 224 | `querykey_usequery_array_literal` |
| 140 | `querykey_invalidatequeries_call_expression` |
| 107 | `querykey_usequery_call_expression` |
| 65 | `querykey_invalidatequeries_other` |
| 29 | `querykey_invalidatequeries_identifier` |
| 3 | `querykey_useinfinitequery_call_expression` |
| 3 | `querykey_usequery_other` |
| 2 | `querykey_usequeries_queries__call_expression` |
| 1 | `querykey_removequeries_array_literal` |
| 1 | `querykey_usequery_identifier` |

**Critical questions for review:**
- The seed used `--scanners=ts_query_key` to scope to TS only. The dry-run with **all** scanners reported `4 needs_recheck + 3 stale_orphan` from the PHP scanners catching drift in fixed clusters (likely from recent api.* fix work). Those 7 rows are real findings, deferred to a separate cycle. Is that the right trade-off, or should the foundation seed have triaged the drift first to avoid an inventory that's selectively up-to-date per surface?
- Stable-key composition: 849 unique stable_keys were generated. The byte-offset disambiguator was load-bearing — without it, 118 collisions would have collapsed distinct callsites. Confirm this by sampling 3-5 callsites that share `enclosing_symbol = 'onSuccess'` in the same file (e.g., `apps/web/src/features/batches/hooks/useBatches.ts`) and check that their stable_keys differ.
- Pattern type distribution: the long tail (`identifier`, `other`) suggests some queryKeys are dynamic references like `batchKeys.all`, `categoryKeys.all`, etc. — keys exported from a separate factory module. The fix path for those is: convert the factory module's keys to use `tenantScopedKey()` internally. Confirm this is the intended shape, or flag if the cluster needs a different fix template for non-array-literal keys.
- Verify-history walked 2784 events at the tip — is that count consistent with: (1935 prior events) + (849 new generate events) + 0? Math: 2784 = 1935 + 849. ✅. Confirm the 849 events all reference the SAME `previous_yaml_sha256` (the prior tip's hash) and the SAME `new_yaml_sha256` (the generate's atomic-mutation result), per the bootstrap-orphan check semantic.

### Contract 5 — Architecture test reliability

Files:
- `apps/api/tests/Architecture/SweepScannerRegistrationTest.php` (commit `11b082a0`)
- `apps/api/tests/Unit/Application/Sweep/Scanners/TanstackKeysScannerTest.php` (commit `11b082a0`)

- `SweepScannerRegistrationTest::test_every_scanner_class_is_registered_in_the_generate_command` walks `app/Application/Sweep/Scanners/` at runtime via filesystem + `ReflectionClass`, asserts every Scanner-implementing class is referenced by `new ClassName(` in the generate command's source.
- `SweepScannerRegistrationTest::test_ts_query_key_scanner_enum_value_is_registered_in_schema` pins the JSON Schema's scanner enum.
- `TanstackKeysScannerTest` (8 tests, 30 assertions): hand-shaped JSON payloads through `parseViolationsPayload()` to pin the JS→PHP contract.

**Critical questions for review:**
- `SweepScannerRegistrationTest` uses string-search (`'new '.$shortName.'('`) to detect registration. Is that brittle? Alternative: add a method `SweepInventoryGenerateCommand::buildScannerRegistry(): array` that returns the scanner list, then assert the test against the runtime list. Trade-off: cleaner test but requires command refactor.
- `TanstackKeysScannerTest` skips the actual Symfony Process invocation. The `scan()` method's process-handling code (timeout, exit-code check, output capture) is **not** tested. Is that an acceptable gap, or should there be an integration test that fires the real Node binary against a tiny fixture file? Trade-off: integration test requires Node on CI.

## Out of scope for this review

- Per-callsite fix work for web.tanstack-keys (still 0/849 fixed; subsequent sessions handle that batch by batch).
- The 7 pre-existing drift rows from PHP scanners (4 needs_recheck + 3 stale_orphan) — those will be triaged in a separate cycle.
- Form-selectors and stores-localstorage scanners (those clusters have separate foundation scope).
- §17 PR body content (PR #93 is DRAFT and updates as foundation + batch work lands).

## How to run the verification battery yourself

```bash
cd apps/api && php artisan sweep:inventory:verify-history
cd apps/api && ./vendor/bin/phpstan analyse app/Application/Sweep/Scanners/TanstackKeysScanner.php tests/Unit/Application/Sweep/Scanners/TanstackKeysScannerTest.php tests/Architecture/SweepScannerRegistrationTest.php
cd apps/api && ./vendor/bin/phpunit tests/Unit/Application/Sweep/Scanners/TanstackKeysScannerTest.php tests/Architecture/SweepScannerRegistrationTest.php
cd apps/web && pnpm vitest run src/lib/__tests__/tenantScopedKey.test.ts tools/__tests__/audit-tanstack-keys.test.mjs
cd apps/web && pnpm typecheck
node apps/web/tools/audit-tanstack-keys.mjs --json | python3 -c 'import json, sys; d=json.loads(sys.stdin.read()); print(len(d["violations"]))'
```

Expected outputs:
- verify-history: `2784 events across 1205 callsites; 0 problems`.
- phpstan: `[OK] No errors`.
- phpunit: 10 tests / 43 assertions OK.
- vitest: 29 tests passing across the two files (9 helper + 20 scanner).
- typecheck: clean.
- node JSON output: `849`.

## Required deliverable

Save your full review to `docs/superpowers/reviews/2026-05-09-tactical-foundation-tanstack-keys-scanner-integration-codex-review.md` with the canonical header. Confirm the file path in your response.
