# Replenishment Requests Adjudications

## Gate C — TanStack invalidation-prefix audit

**Discrepancy:** Gate C requires bare namespace arrays for `invalidateQueries`, but `apps/web/tools/audit-tanstack-keys.mjs:301-315` rejected every unscoped array, causing the repository lint gate to flag the required invalidations in `features/replenishment/api/queries.ts`.

**Proposal:** Teach the audit to accept only bare array-literal keys passed to `invalidateQueries`; retain tenant-scope enforcement for opaque invalidation keys and all query/fetch methods. Update focused audit tests and the plan guardrail.

**Adjudicator verdict (verbatim):**

> APPROVED
>
> Amendment (precise):
>
> 1. In `apps/web/tools/audit-tanstack-keys.mjs`, add `const INVALIDATION_PREFIX_FACTORIES = new Set(['invalidateQueries']);` near the approved sets. In `checkOptionsObject`, accept a bare ARRAY LITERAL only for invalidation:
>
> ```js
> const isInvalidationBarePrefix =
>   INVALIDATION_PREFIX_FACTORIES.has(factoryName) &&
>   ts.isArrayLiteralExpression(unwrapKeyExpression(initializer));
> if (!queryKeyExpressionIsApproved(initializer) && !isInvalidationBarePrefix) { ... }
> ```
>
> Do not exempt opaque/dynamic keys or other methods.
>
> 2. Update `apps/web/tools/__tests__/audit-tanstack-keys.test.mjs`: invert the current `flags unscoped queryClient.invalidateQueries` test to approve a bare array literal; add a boundary test proving an opaque `invalidateQueries({ queryKey: dynamicKey })` still flags; add a boundary test proving `fetchQuery` with a bare array still flags; change the byte-offset disambiguation test's two `invalidateQueries` calls to `fetchQuery`, preserving its two findings and `onSuccess` assertions.
>
> 3. Append to the plan Global data-fetching guardrail: `audit-tanstack-keys.mjs recognizes an invalidation-only exception: a bare array-literal queryKey is approved for invalidateQueries (prefix filter), while useQuery/useQueries/fetchQuery/prefetchQuery keys still require tenantScopedKey([...]).`
>
> 4. The resulting reduction in TanstackKeysScanner inventory rows is expected and harmless because these calls are cache prefix filters, not tenant data reads. The branch is 107 commits behind `dev`; reconcile this narrow audit exception if `apps/web/tools/audit-tanstack-keys.mjs` changed upstream before merge.

**Commit:** `747f7ee02` (the Gate C invalidation fix that exposed the audit mismatch; the audit amendment is committed with this log).

## Gate D — preflight laptop-safety

**Discrepancy:** Task 16 requires `./scripts/preflight.sh`, while the script ran full PHPStan (`scripts/preflight.sh:53` before amendment), generated types without the locked `CACHE_STORE=array` setting (`:95`), and ran the forbidden full web Vitest suite (`:144`).

**Proposal:** Add environment-controlled PHPStan and Vitest paths analogous to the existing PHPUnit path scope, use `CACHE_STORE=array`, and run Gate D with only the complete feature-specific backend and frontend paths.

**Adjudicator verdict (verbatim):**

> REVISED:
>
> **1. Transform (unconditional bug fix, safe on VPS/CI).** At `preflight.sh:95` change `php artisan typescript:transform` → `CACHE_STORE=array php artisan typescript:transform` (cwd is already `apps/api` from `:46`). Also update the non-executed hint at `:107` to match, for consistency with Global Constraint `:28`.
>
> **2. PHPStan — opt-in override, default unchanged.** Add `PREFLIGHT_PHPSTAN_PATHS`. At `:52–54`: if set → `./vendor/bin/phpstan analyse ${PREFLIGHT_PHPSTAN_PATHS} --level=8 --memory-limit=2G`; if unset → the current full run, **unchanged** (VPS/CI and other sessions unaffected).
>
> **3. Vitest — opt-in override, default unchanged.** Add `PREFLIGHT_VITEST_PATHS` scoping the `apps/web` run only. At `:143–145`: if set → `pnpm vitest run ${PREFLIGHT_VITEST_PATHS}`; if unset → current `pnpm test`, **unchanged**. Do **not** convert the unset case to a hard-fail — that mutates the script's documented "unconditional" default for every session; if the team wants laptop-safe-by-default Vitest, raise it as a separate infra change. Update the header comment (`:13–34`) to document both new override-only vars (unset = unchanged full run; set = scoped).
>
> **4. Gate D run (this is what makes Gate D honest without ever running a full suite on the laptop):** use the existing exact replenishment/POS/Inventory PHPUnit paths, scope PHPStan to all feature-new files rather than the whole `app/Shared` directory, scope preflight Vitest to the web replenishment feature, and run the three `apps/pos` Vitest files plus POS typecheck separately.
>
> **5. Log honestly.** In the same commit, update plan Task 16 Step 3 to the scoped invocation above, and append a "Gate D — preflight laptop-safety" entry with the corrected line numbers (`:53`, `:95`, `:144`), the actual preflight output, and a note that the two overrides are additive (unset = current behavior).

**Preflight result:** PASS — Pint passed; PHPStan `[OK] No errors`; PHPUnit `59 passed (220 assertions)`; generated types in sync; web TypeScript passed; ESLint `0 errors` (8,648 baseline warnings); TanStack audit `0 new`; route manifests in sync; web Vitest `9 passed / 23 tests`; fiscal parity `2 passed / 29 tests`; chokepoint gate `8 call site(s) reconciled`; final line `✅ All preflight checks passed!`.

**Commit:** pending Task 16 commit.

## Gate D — route-manifest coherence

**Discrepancy:** Preflight's deterministic route-manifest check found the two new replenishment routes and a pre-existing `/purchases/scans/new` route missing from `routes-web.yaml`; the generator has no scoped mode.

**Proposal:** Commit the complete generated web manifest, including the pre-existing scan route as a generator-coherence artifact, without changing scan application code.

**Adjudicator verdict (verbatim):**

> APPROVED
>
> 1. Run `node scripts/factory/gen-route-manifest.mjs` without hand-editing generated files.
> 2. Confirm only `routes-web.yaml` changes, adding exactly the two replenishment routes plus pre-existing `/purchases/scans/new`; stop if `routes-pos.yaml` changes.
> 3. Commit the complete generated web manifest and record the scan row as a generator-coherence artifact, not a feature refactor.
> 4. Rerun scoped preflight and confirm the manifest drift gate is green. The alternative scoped verification does not exist and must not be invented.

**Observed generator output:** `routes-web.yaml` added exactly the three approved rows; `routes-pos.yaml` had no diff.

**Commit:** pending Task 16 commit.

## Gate D — Pint laptop-safety

**Discrepancy:** The first scoped preflight still ran repository-wide Pint (`scripts/preflight.sh:53` before amendment) and stopped on both feature files and unrelated Treasury/Product/Fiscal/Inventory baseline files, which action safety forbids editing.

**Proposal:** Add an opt-in `PREFLIGHT_PINT_PATHS` override, with the unset repository-wide default unchanged, and cover every feature PHP path derived from `git diff dev...HEAD` plus the Task 16 working tree.

**Adjudicator verdict (verbatim):**

> APPROVED
>
> **Amendment 1 — `scripts/preflight.sh:52-54`, replace the unconditional Pint call:** when `PREFLIGHT_PINT_PATHS` is set, run `./vendor/bin/pint --test ${PREFLIGHT_PINT_PATHS}`; otherwise retain `./vendor/bin/pint --test` unchanged.
>
> **Amendment 2 — `scripts/preflight.sh:35-37`, add `PREFLIGHT_PINT_PATHS` to the "Optional additive scopes" header list** (alongside `PREFLIGHT_PHPSTAN_PATHS` / `PREFLIGHT_VITEST_PATHS`), with the same "unset preserves the existing full checks" note.
>
> **Amendment 3 — Task 16 Step 3:** add `PREFLIGHT_PINT_PATHS` to the command block, scoped to feature-owned PHP only, using directory scopes only for the net-new Replenishment application and tests and individual files for cross-module changes.
>
> **Amendment 4:** log this adjudication and the Gate-D obligation below.
>
> **Binding condition (Gate D):** before merge, re-derive `PREFLIGHT_PINT_PATHS` from `git diff --name-only dev...HEAD -- '*.php'` plus working-tree PHP and confirm every changed feature PHP file is covered. Format the feature-owned files, then rerun scoped preflight green. Repo-wide Pint (`PREFLIGHT_PINT_PATHS` unset) remains the CI/VPS default; pre-existing baseline drift stays out of scope per rule 4.

**Coverage check:** all 48 committed feature PHP files plus the four Task 16 working-tree PHP paths are covered by the Replenishment directory scopes or the explicit cross-module/test paths above.

**Commit:** pending Task 16 commit.
