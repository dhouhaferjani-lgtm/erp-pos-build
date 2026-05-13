# Opus Review Prompt: web.tanstack-keys Batch 47

You are Opus reviewing Codex implementation work for the web.tanstack-keys tenant-scope sweep.

## Scope

- Batch: B47 treasury reconciliation hooks
- Callsites: `web.tanstack-keys.718` through `web.tanstack-keys.734`
- Fix commit: `0e5e4a6c`
- Scanner delta: `363 -> 346`
- Review output target: `docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batch-47-opus-review.md`

## Changed Files

- `apps/web/src/features/treasury/hooks/useReconciliation.ts`
- `apps/web/src/features/treasury/hooks/__tests__/tenantScope.test.tsx`

## Gates To Check

Use the same review gates as the prior approved batches:

1. Scanner delta matches exactly 17 callsites removed.
2. Read hooks use state-value selectors for tenant/company and gate fetches until both are present.
3. Read query keys are wrapped in `tenantScopedKey(...)`.
4. List invalidations for `reconciliations`, `payment-repositories`, and cross-namespace `payments` use active tenant/company predicates.
5. Detail invalidations for `reconciliation` and `reconciliation-summary` use exact active-tenant `tenantScopedKey(...)` keys.
6. Mutation invalidations are `async` and awaited with `Promise.all` for cascades.
7. Test covers tenant-B cache preservation for reconciliations list, reconciliation detail, summary, repositories, and payments.
8. Test uses per-call counters to prove each mutation refetches only the intended active-tenant queries.
9. `php artisan sweep:inventory:verify-history` remains clean.

## Verification Already Run By Codex

From `apps/web`:

```bash
pnpm vitest run src/features/treasury/hooks/__tests__/tenantScope.test.tsx
pnpm typecheck
```

From repo root:

```bash
node apps/web/tools/audit-tanstack-keys.mjs --json | jq '.violations | length'
```

Result: `346`.

From `apps/api`:

```bash
php artisan sweep:inventory:verify-history
```

Result before submit: `verified 4759 event(s) across 1205 callsite(s); 0 problem(s).`

## Expected Verdict Action

If all gates pass, write the review file with verdict `APPROVE` and lock:

```bash
cd apps/api
for id in $(seq 718 734); do
  php artisan sweep:inventory:lock \
    --callsite-id="web.tanstack-keys.$id" \
    --actor=opus \
    --review-commit=0e5e4a6c
done
php artisan sweep:inventory:verify-history
```

If any gate fails, write `REQUEST-CHANGES` with exact file/line findings and do not lock.
