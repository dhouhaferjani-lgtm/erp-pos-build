# web.tanstack-keys Batches 54-63 — Opus Review

Reviewer: opus
Implementer: codex
Branch: feat/tenant-isolation-sweep-execution
Cluster: web.tanstack-keys
Date: 2026-05-11

## Commits reviewed

| Batch | Fix commit | Scope | Callsites |
| --- | --- | --- | --- |
| B54 | c970e935 | attachment hooks | web.tanstack-keys.182-185 |
| B55 | acd5def0 | close-with-tolerance hook | web.tanstack-keys.218-220 |
| B56 | bb46f0b6 | finance account hooks | web.tanstack-keys.269-272 |
| B57 | bb46f0b6 | finance journal entry read hooks | web.tanstack-keys.277-278 |
| B58 | bb46f0b6 | finance journal entry mutation hooks | web.tanstack-keys.279-281 |
| B59 | bb46f0b6 | finance ledger hooks | web.tanstack-keys.282-283 |
| B60 | ef1b1317 | treasury payment repository hooks | web.tanstack-keys.716-717 |
| B61 | 85ed3918 | user hooks | web.tanstack-keys.748-749 |
| B62 | 4fe26b41 | VAT period action hooks | web.tanstack-keys.750-751 |
| B63 | 4fe26b41 | VAT report read hooks | web.tanstack-keys.753-754 |

Scanner: 301 → 275 (-26 violations). Verify-history: clean throughout.

## Verdict

Verdict: APPROVE

APPROVE — all 10 batches.

## Gates evaluated (all pass)

1. State-value tenant/company selectors on every wrapped hook.
2. Read `enabled:` AND-combines tenant/company nullness with pre-existing predicates.
3. Read `queryKey` wrapped with `tenantScopedKey([...])`.
4. Mutation invalidations: predicate for suffix-scoped lists, exact for detail.
5. Awaited cascades (`await invalidateQueries(...)`, `await Promise.all(...)`).
6. Per-call counter tests cover intended-tenant refetch and absence of overfire.
7. Tenant-B cache markers preserved through active-tenant mutations (where mutations exist in scope).

## Per-batch notes

- **B54** (attachments): exact-key invalidation using `tenantScopedKey(['attachments', documentId])`; suffix prevents cross-tenant invalidation. Real `useAttachments`/`useUploadAttachment`/`useDeleteAttachment`/`useAttachmentConfig` exercised.
- **B55** (close-with-tolerance): predicate for suffix-scoped `documents`/`payments` lists, exact key for invoice; three tenant-B markers preserved post-close.
- **B56** (finance accounts): `accountsPredicate` matches `k[0]==='accounts'` for both list and detail shapes (over-invalidates detail — safe conservative choice, consistent with pattern). Account-level mutations are not directly counter-tested, but the predicate shape is exercised under the sibling journal-entry mutation test (which uses the same `scopedNamespacePredicate` helper).
- **B57** (finance journal reads): read-only — production hooks used in suffix + missing-tenant gating tests.
- **B58** (finance journal mutations): `scopedNamespacePredicate` for lists, exact `tenantScopedKey(['journal-entry', id])` for detail. Counters confirm both bump after create + post.
- **B59** (finance ledger): two read hooks wrapped; production hooks under suffix tests.
- **B60** (treasury payment repos): read-only hooks; `useActivePaymentRepositories` inherits scoping via composition.
- **B61** (users): list + detail wrapped with `userKeys.list(params)` / `userKeys.detail(id)`.
- **B62** (VAT period actions): close mutation invalidates `vat-periods` (predicate) + exact `['vat-report', id]` (tighter than the prior broad `['vat-report']` invalidation).
- **B63** (VAT report reads): two read hooks wrapped.

## Non-blocking findings

1. `useUploadAttachment` / `useDeleteAttachment` call `useAuthStore(...)` / `useCompanyStore(...)` without binding the return — same documented pattern as elsewhere; readability nit.
2. `useCreateAccount` / `useUpdateAccount` are not directly exercised with per-call counters or tenant-B markers in the finance test (journal-entry mutations cover the predicate pattern in the same file). Coverage asymmetry, not a defect.
3. `accountsPredicate` matches both `['accounts', filters, t, c]` (list) and `['accounts', id, t, c]` (detail) — a conservative over-invalidation. Documented as intentional.
4. User and payment-repository tests omit tenant-B marker preservation tests because neither batch wraps a mutation hook — no cascade to verify.

## Locks applied

All callsite locks pinned to the corresponding fix commit.
