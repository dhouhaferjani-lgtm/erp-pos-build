# Codex review prompt — web.tanstack-keys batch 10 (crm pages + PartnerSelect)

**Branch:** `feat/tenant-isolation-sweep-execution`
**Fix commit:** `6f9d8687`
**Files (4 components + 1 factory edit):**
- `apps/web/src/features/crm/api/contactApi.ts` (added predicate)
- `apps/web/src/features/crm/pages/ContactDetailPage.tsx`
- `apps/web/src/features/crm/pages/ContactFormPage.tsx`
- `apps/web/src/features/crm/pages/ContactListPage.tsx`
- `apps/web/src/features/crm/components/PartnerSelect.tsx`

**Pre-existing test patched:** `apps/web/src/features/crm/pages/__tests__/ContactFormPage.test.tsx` (added `contactsInvalidationPredicate: () => () => false` to vi.mock to satisfy the new import — minimal change, no behavior assertions modified).

**New test:** `apps/web/src/features/crm/__tests__/tenantScope.test.tsx`
**Callsites:** `web.tanstack-keys.127`–`.135` (9)
**Cluster:** `web.tanstack-keys` (was 769; this batch closes 9 → 760 remaining if APPROVE)

## Context (compressed — page-level B9 mirror with sibling-component wrap)

Same B9 shape applied to crm. `contactKeys` factory in `api/contactApi.ts` (B1/B4-style); predicate is colocated next to the factory. Two namespaces in this batch:

- **`contacts`**: list + detail useQuery factory wraps; 5 invalidates (delete, link, unlink, create, update) all predicate-based.
- **`partners`**: PartnerSelect.tsx is a sibling component used inside ContactDetailPage + ContactFormPage. Wrap is `tenantScopedKey(['partners', 'search', search])`. The contacts predicate explicitly rejects this namespace; assertion in the test ensures contact mutations don't invalidate PartnerSelect cache.

The pre-existing `ContactFormPage.test.tsx` mock of `contactApi` needed a one-line patch: added `contactsInvalidationPredicate: () => () => false` so the production code can call the predicate (no-op in this test's scope; cascade behavior is covered by the new tenantScope test).

## What to verify

1. Scanner delta = 9 (`audit-tanstack-keys.mjs` count drops from 769 to 760). **Confirmed live: 760.**
2. State-value selectors used in all 3 pages + PartnerSelect.
3. `enabled` gates combine pre-existing conditions (id.length > 0 / isEditing / isOpen && search.length > 0) with `!!tenantId && !!companyId`.
4. Predicate matches `[contacts, list|detail, ...]` and rejects `[partners, ...]` (sibling-namespace assertion in test).
5. Mutations' `onSuccess` async + awaited; `navigate()` / state resets after invalidate completes.
6. Cross-tenant isolation test seeds tenant-B `contacts/list` AND `contacts/detail` entries, runs predicate-based invalidate, asserts both `state.isInvalidated === false`.
7. ContactFormPage.test.tsx mock patch is minimal (one line added; no behavior assertions changed).

## Quality gate evidence (run by main session at fix SHA)

- `pnpm vitest run src/features/crm/`: 30/30 passing across 4 files (no unhandled rejections).
- `pnpm typecheck`: clean.
- `php artisan sweep:inventory:verify-history`: 3221 events / 1205 callsites / 0 problems (post-submit).

## Verdict file

```
docs/superpowers/reviews/2026-05-10-web-tanstack-keys-batch-10-codex-review.md
```

**Critical format:** First non-empty line MUST be `Commit reviewed: 6f9d8687`. Verdict line MUST be a literal `Verdict: APPROVE` (or `Verdict: APPROVE-WITH-MINOR-EDITS-APPLIED` / `Verdict: REQUEST-CHANGES`) on its own line — NOT a `## VERDICT:` markdown heading.

**Important:** Use the Write tool to save the review file to disk in this turn.

Page-level + sibling-namespace mirror of B9; expected verdict APPROVE.
