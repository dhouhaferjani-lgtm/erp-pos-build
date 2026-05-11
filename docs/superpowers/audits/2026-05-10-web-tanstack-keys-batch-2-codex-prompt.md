# Codex review prompt — web.tanstack-keys batch 2 (callsites .553-.564)

You are reviewing batch 2 of the `web.tanstack-keys` cluster (master plan §11). Batch 1 (categories/useCategories.ts, callsites .095-.104) locked at `24fca0a6` after a self-found prefix-match defect → predicate-based fix. **Lessons carried forward into batch 2:** cascade-aware tests written upfront with `mockApiGet` fetch-count signals, predicate-based invalidation for any callsite that needs cascade-style invalidation against tenant-scoped leaves, store subscriptions in mutation hooks for closure-capture of tenant scope.

## Scope

12 callsites across 6 files in `apps/web/src/features/products/`:

| callsite_id | line | file | symbol | pattern |
|-------------|-----:|------|--------|---------|
| web.tanstack-keys.553 | 35  | components/ProductImageGallery.tsx | onSuccess (delete) | invalidate_array_literal |
| web.tanstack-keys.554 | 36  | components/ProductImageGallery.tsx | onSuccess (delete) | invalidate_array_literal |
| web.tanstack-keys.555 | 52  | components/ProductImageGallery.tsx | onSuccess (setPrimary) | invalidate_array_literal |
| web.tanstack-keys.556 | 53  | components/ProductImageGallery.tsx | onSuccess (setPrimary) | invalidate_array_literal |
| web.tanstack-keys.557 | 16  | components/ProductImageSection.tsx | useQuery | useQuery_array_literal |
| web.tanstack-keys.558 | 26  | components/ProductImageUpload.tsx | onSuccess (upload) | invalidate_array_literal |
| web.tanstack-keys.559 | 27  | components/ProductImageUpload.tsx | onSuccess (upload) | invalidate_array_literal |
| web.tanstack-keys.560 | 19  | components/ProductPrimaryImageDisplay.tsx | useQuery | useQuery_array_literal |
| web.tanstack-keys.561 | 68  | hooks/useProductRealtime.ts | handleUpdate | invalidate_array_literal |
| web.tanstack-keys.562 | 73  | hooks/useProductRealtime.ts | handleUpdate | invalidate_array_literal |
| web.tanstack-keys.563 | 23  | hooks/useProducts.ts | useProducts | useQuery_call_expression |
| web.tanstack-keys.564 | 34  | hooks/useProducts.ts | useProduct | useQuery_call_expression |

## Fix at commit `bbba88ff` (current branch tip `551894ea`)

Three patterns combined:

1. **Bare array_literal wrap** at exact-match callsites (`['product-images', productId]`, `['product', productId]`): wrap with `tenantScopedKey([...])`. Both useQuery sites and the matching invalidate sites end up at the same `[..., t, c]` shape, so prefix-match cascade is correct (exact-match in this case — no length divergence).

2. **Factory pattern** in `useProducts.ts` (callsites .563, .564): wrap useQuery callsites with `tenantScopedKey([...productKeys.X(...)])`; subscribe to authStore + companyStore; enabled-gate by tenantId+companyId. Same shape as batch 1 categoryKeys.

3. **Predicate-based invalidation** in `useProductRealtime.ts` callsite .562 (the `['products']` plural prefix-cascade): `invalidateQueries({ predicate: productsInvalidationPredicate(tenantId, companyId) })`. Predicate matches `q.queryKey[0] === 'products' && k.at(-2) === tenantId && k.at(-1) === companyId`. Reason: a wrapped tag like `[products, t, c]` is NOT a prefix of leaf `[products, list, params, t, c]` (position 1 mismatch), so prefix-cascade is broken — predicate sidesteps the positional issue. Helper `productsInvalidationPredicate` exported from `useProducts.ts` for cross-file reuse.

   Callsite .561 (`['product', productId]`) is wrapped with tenantScopedKey, NOT predicate. Rationale: this is an exact-match invalidation against a singular `'product'` key; nothing in the tracked code uses that key as a useQuery (the productKeys factory uses plural `'products'`), so the invalidation is orphan-style. Wrap is mechanical — preserves prior intent without changing semantics.

## What you should adversarially check

1. **Cascade correctness for callsite .562.** Walk through what queries exist at runtime under the products namespace (factory leaves: `['products', 'list', params, t, c]`, `['products', 'detail', id, t, c]`). Confirm `productsInvalidationPredicate(t, c)` matches both leaf shapes AND no others. Confirm the prefix-cascade defect from batch 1 doesn't recur here.

2. **Cascade correctness for callsites .553-.556 + .558-.559.** These wrap `tenantScopedKey(['product-images', productId])` and `tenantScopedKey(['product', productId])`. ProductImageSection/PrimaryImageDisplay's useQuery wraps the same shape. So invalidate-key === leaf-key (exact match, not just prefix). Confirm the cascade actually fires — the test file's "setPrimary refetches product-images for the current tenant" and "upload refetches the product-images query" cases use mockApiGet count signals to verify.

3. **Closure capture of tenant scope.** Mutation hooks subscribe to authStore + companyStore so the closure captures fresh values. Confirm: would a tenant-switch BEFORE the mutation fires correctly route the invalidation to the new tenant's cache? (The store subscription forces re-render on switch, capturing fresh tenantId/companyId.)

4. **`enabled` defense-in-depth gate.** All 4 useQuery hooks have `enabled: !!tenantId && !!companyId`. ProductImageSection adds `&& !!productId` (preserved). ProductPrimaryImageDisplay similarly. useProduct preserves `Boolean(id)`.

5. **Test honesty.** The 13 tests in `tenantScope.test.tsx` use fetch-count signals (not vacuous spy patterns). Mentally try: would each cascade test fail if I deleted just one tenantScopedKey wrap or one predicate from the production file? E.g., the `setPrimary refetches` test asserts `mockApiGet called 2 times`. If I removed the setPrimary onSuccess invalidation, refetch wouldn't fire → mockApiGet stays at 1 → test fails. ✓ Apply the same mental check across all cascade tests.

6. **Hostile-grep.** Search the 6 files end-to-end for any other queryKey shape that might have been missed. Scanner says 12 callsites — verify by reading each file. Confirm scanner count drops by exactly 12 (839 → 827).

7. **Unrelated breakage.** `git diff 24fca0a6..bbba88ff --stat` should show only the 6 product files + 1 test file + the inventory YAML chore commits. Confirm scope.

8. **Forward-compat with batch 1.** No regression in `apps/web/src/features/categories/hooks/useCategories.ts` or its test file. Run the test:
   ```
   pnpm vitest run src/features/categories/hooks/__tests__/useCategories.tenantScope.test.tsx
   ```
   Should be 9/9 pass.

9. **Type safety.** `pnpm typecheck` — pass. The test fixtures use realistic `ProductImage` shape per `apps/web/src/features/products/types.ts` (storage_disk, created_at, updated_at fields populated; no stray invented fields).

## Quality gates the main session ran at `bbba88ff`

```text
pnpm vitest run src/features/products/__tests__/tenantScope.test.tsx → 13 tests pass
pnpm typecheck → pass
node apps/web/tools/audit-tanstack-keys.mjs --json | jq '.violations | length' → 827 (was 839; delta = 12)
pnpm vitest run src/__tests__/architecture/queryKeyNamespace.test.ts → 4 tests pass
pnpm vitest run src/lib/__tests__/tenantScopedKey.test.ts → 9 tests pass
pnpm vitest run src/features/categories/hooks/__tests__/useCategories.tenantScope.test.tsx → 9 tests pass (batch 1 regression)
php artisan sweep:inventory:verify-history → verified 2880 event(s) across 1205 callsite(s); 0 problem(s).
```

## Deliverable

Save your verdict to `docs/superpowers/reviews/2026-05-10-web-tanstack-keys-batch-2-codex-review.md` (round 1).

Verdict file format (strict — parsed by `SweepInventoryReviewCommand`):

```text
Commit reviewed: bbba88ff
Verdict: <APPROVE | APPROVE-WITH-MINOR-EDITS-APPLIED | REQUEST-CHANGES | BLOCKER>

## Findings

[F1] ...
[F2] ...

## Verification Run

(any commands you ran and their output)
```

The `Verdict:` line MUST equal one of the four exact strings; the lock CLI uses it verbatim. The `Commit reviewed:` SHA must equal the `--review-commit` flag (`bbba88ff`).

Single-round APPROVE expected this batch — main session wrote cascade tests upfront specifically to avoid the round-trip pattern that batch 1 needed. If you find a substantive defect, REQUEST-CHANGES is fine; main session will iterate and re-submit.
