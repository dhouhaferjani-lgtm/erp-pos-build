# web.tanstack-keys Cluster — Claude Review

Cluster: `web.tanstack-keys`
Cluster aggregate status (inventory): `fixed`
Verdict: **APPROVE**

Reviewer: claude (opus)
Implementer (canonical owner): codex
Branch: feat/tenant-isolation-sweep-execution
Date: 2026-05-12

## Scope

Every TanStack Query `queryKey` / `invalidateQueries` callsite in `apps/web/src/` that targets a tenant-scoped resource. Pre-sweep, the cache was keyed without a tenant/company suffix; switching tenant in the auth store left stale cache entries from the prior tenant visible until manual refetch — a cross-tenant data-leak class with no fiscal-chain exposure but high blast radius for any list/detail view.

Inventory callsite total: **849 / 849 fixed**. Cluster aggregate auto-flipped to `fixed` once the final batch (B95 at fix commit `52f111ad`, lock commit `ceb4396e`) brought the last `under_review` row across the line.

## Implementation summary

- Helper: `apps/web/src/lib/tenantScopedKey.ts` exports `tenantScopedKey(segments)`, which appends `[…, tenant_id, company_id]` read from store snapshots via `getState()` (not subscribed). Companion helper `apps/web/src/features/pos/hooks/usePosTenantScope.ts` provides `usePosTenantScope()` (subscribed via selectors) and a canonical `scopedKeyPredicate(namespace, ...)` for namespace-wide invalidations.
- Pattern enforced cluster-wide:
  1. **Factory wrap** — `tenantScopedKey([...])` for exact keys; `scopedNamespacePredicate(namespace, tenantId, companyId)` for namespace sweeps that need to invalidate multiple key shapes (e.g., `[namespace, filter1]`, `[namespace, filter2]`).
  2. **State-value selectors** — `useAuthStore((s) => s.user?.tenant_id ?? null)` and `useCompanyStore((s) => s.currentCompanyId ?? null)`. Subscribed (not `getState()`) so consumer re-renders on store change.
  3. **Enabled gate** — `tenantId !== null && companyId !== null` AND-combined with the consumer's existing predicate (`isOpen`, `!!id`, etc.).
  4. **Async invalidate** — mutation `onSuccess` handlers converted to `async/await` (or `Promise.all([…])` for multi-key cases) so close-and-callback flows are deterministic after refetch enqueues.
  5. **Cross-tenant isolation tests** — each batch ships a focused test that seeds tenant-A AND tenant-B cache entries, drives the action, asserts tenant-A flips to `isInvalidated=true` while tenant-B stays `isInvalidated=false`.

Scanner: `node apps/web/tools/audit-tanstack-keys.mjs` reports **0 violations**.

## Verification

```
node apps/web/tools/audit-tanstack-keys.mjs --json | jq '.violations | length'
→ 0

cd apps/api && php artisan sweep:inventory:verify-history
→ verified 6270 event(s) across 1205 callsite(s); 0 problem(s).
```

Inventory: 849 callsites at `status: fixed`, every entry carries `review.verdict ∈ {APPROVE, APPROVE-WITH-MINOR-EDITS-APPLIED}`, `review.review_commit` pinned to the corresponding fix SHA, and `review.review_file` resolves on disk.

## Per-batch review trail

Codex shipped the fix as ~95 incremental batches; each batch ships an Opus review markdown under `docs/superpowers/reviews/2026-05-1{0,1}-web-tanstack-keys-batch-<NN>-opus-review.md`. The 33 most recent batch reviews (B21-B95 except mid-numbered batches already locked before this session) were authored during this review pass and live at:

- `2026-05-10-web-tanstack-keys-batch-{21,22,23,24}-opus-review.md`
- `2026-05-11-web-tanstack-keys-batch-{36,37,38,70-95}-opus-review.md`

Earlier batches carry their own individual review markdowns from the original implementation rounds. Together they cover every locked callsite with explicit verdict + commit linkage.

## Gates evaluated

1. **Pattern compliance**: scanner-zero is the structural witness. Every callsite the scanner can reach is wrapped via `tenantScopedKey` or accepted by an explicit predicate path.
2. **Regression coverage**: per-batch tests assert tenant-A vs tenant-B cache isolation. The cluster-level test `apps/web/src/__tests__/tenantSwitchCacheInvalidation.test.tsx` exercises the end-to-end switch behavior. (Note: a stronger contract test for cross-tenant cache *eviction* on tenant switch is a candidate for the master PR.)
3. **Cross-agent review**: implementer = codex, reviewer = claude/opus, with `verify_review_commit_linkage: true` enforced per callsite. No row carries an unverified or self-reviewed verdict.
4. **No fiscal-chain exposure**: cluster does not touch hash chains; TanStack is presentation-layer cache only.

## Non-blocking follow-ups

1. **`scopedNamespacePredicate` duplication** — the helper is inlined across ~10 files (B70-B82, B86-B95). The canonical version exists at `apps/web/src/features/pos/hooks/usePosTenantScope.ts` as `scopedKeyPredicate`. Promote to `apps/web/src/lib/tenantScopedKey.ts` and adopt cluster-wide. **Suggested as a single follow-up PR** after the closure plan lands; out of scope here because every inlined copy is byte-equivalent and the scanner does not care about source structure.
2. **`AuthProvider` does not gate `enabled` on `tenantId`** (`AuthProvider.tsx:51`) — intentional, because `tenant_id` comes FROM the `/auth/me` response, so gating would deadlock. Documented in B91 review. The `tenantScopedKey` factory handles the `null` suffix correctly and the query re-keys after `setAuth(...)` populates the store.
3. **Test coverage gaps** flagged in individual batch reviews:
   - B91 (.027/.028/.029/.094) — auth + composite-item-form rely on the mechanical pattern match rather than direct test assertions.
   - B92 (.236/.237) — `ReturnNoteDetailPage` follows the pattern but isn't directly rendered in `DocumentTenantScope.test.tsx`.
   - Risk profile: low. The cluster-level switch test catches drift; the per-page tests cover the common cases.
4. **Conditional invalidate uses `Promise.resolve()` placeholders** (B70 `PaymentForm.tsx` save path) — cosmetic; functionally correct.

## Disposition

Cluster is APPROVE-ready for the master PR. Recommend the follow-up consolidation PR (#1 above) ship within the next development cycle so the `scopedNamespacePredicate` story is told in one place.

## Cross-references

- Master plan: `docs/superpowers/plans/2026-05-02-tenant-isolation-master-plan.md`
- Inventory entry: `docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` cluster `web.tanstack-keys` (status=fixed)
- Helper: `apps/web/src/lib/tenantScopedKey.ts`
- Scanner: `apps/web/tools/audit-tanstack-keys.mjs`
- Cluster-level test: `apps/web/src/__tests__/tenantSwitchCacheInvalidation.test.tsx`
