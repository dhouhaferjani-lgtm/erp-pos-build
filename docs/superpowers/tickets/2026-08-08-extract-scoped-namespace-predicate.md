# Chore — extract the 26 copies of `scopedNamespacePredicate`

**Raised by:** plan CF §6 (deferred minor, frontend gate M-3). **Status:** OPEN.
**Size:** small but WIDE — 27 files.

## What

`scopedNamespacePredicate(namespace, tenantId, companyId)` is copy-pasted, byte for
byte, across `apps/web/src`:

```
grep -rl scopedNamespacePredicate apps/web/src   # 26 files at the lane head, 27 after plan CF
```

Every copy is the same six-line body — the canonical one lives at
`apps/web/src/features/documents/CreateReturnNotePage.tsx`. It builds the cache-FILTER
predicate that bounds `invalidateQueries` to the active tenant + company.

## Why it was deferred rather than done in lane CF

Extracting a helper used by 26 files inside a cancel-flow lane is exactly the rule-4
scope creep that lane forbids: it would put 26 unrelated files into the lane's diff and
into its revert blast radius, and a revert of the cancel flow would then also revert a
cross-cutting refactor.

The FE gate accepted the deferral on two binding conditions, both met by lane CF:

1. **The 27th copy is byte-identical** to the canonical body. A drifting copy turns this
   future extraction from a delete into a merge exercise. The new copy is at
   `apps/web/src/features/documents/invoices/hooks/useCancelInvoice.ts`.
2. **No `// TODO` marker.** `apps/web/eslint.config.js` has no `no-warning-comments`
   rule, so a TODO would not fail CI but WOULD collide with CLAUDE.md agent rule 1 ("no
   placeholder code"). The marker used is `// NOTE(chore: tanstack-helpers)` and it
   references this ticket — the deferral is a ticket, not a comment.

## The work

1. Add the helper to `apps/web/src/lib/tenantScopedKey.ts` (or a sibling
   `lib/tanstackFilters.ts` — it is a FILTER factory, not a key factory, and the
   distinction is load-bearing: storage keys get `tenantScopedKey`, cache filters stay
   bare literal prefixes).
2. Replace all 27 copies with an import.
3. Verify `pnpm lint` still reports `Gate C baseline: 0 acknowledged, 0 new, 0 stale`
   — `audit-tanstack-keys.mjs` must keep recognising the predicate form.

## Acceptance

- `grep -rc 'function scopedNamespacePredicate' apps/web/src` → exactly 1.
- `pnpm typecheck`, `pnpm lint` and `pnpm vitest run` all clean.
- No behaviour change: the extraction is a pure de-duplication.
