Commit reviewed: c39bde74

# Opus review — web.tanstack-keys batch 25 (opening balances)

Independent second-pair-of-eyes review of the Codex implementation
at `c39bde74`. All 6 review axes pass. Verdict APPROVE.

## Callsite tally

19 callsites verified in the production diff:
- 6 useQuery wraps: types, status, batches, batch (detail), rows, preview
- 6 useCreateOpeningBatch invalidates (list + status) = 2
- 6 useDeleteOpeningBatch invalidates (list + status) = 2
- 6 useImportOpeningRows invalidates (detail exact-match + rows predicate) = 2
- 6 useValidateOpeningBatch invalidates (detail exact-match + rows predicate) = 2
- 6 usePostOpeningBatch invalidates (detail + status) = 2
- 6 useLockOpeningBatch invalidates (detail + status + list) = 3

Total: 6 + 2 + 2 + 2 + 2 + 2 + 3 = 19 ✓

## Axis-by-axis findings

**Axis 1 — Scanner delta exactly 19 (619 → 600): PASS.** Live audit
count is 580 (post-B26 cumulative). 580 + 20 (B26 delta) = 600
matches B25 expected post-batch count. Per-batch delta verified
logically and by callsite enumeration above.

**Axis 2 — State-value selectors in all query + mutation hooks: PASS.**
Every hook (6 queries + 6 mutations) reads `useAuthStore((s) =>
s.user?.tenant_id ?? null)` and `useCompanyStore((s) =>
s.currentCompanyId ?? null)`. Pattern at queries.ts:55-56, :70-71,
:84-85, :98-99, :115-116, :129-130, :147-148, :173-174, :199-200,
:229-230, :263-264, :291-292.

**Axis 3 — Rows predicate is narrow: PASS.** At queries.ts:29-47,
`openingBalanceRowsInvalidationPredicate` gates on:
- `k.length >= 6` (narrowest length floor for rows shape)
- `k[0] === 'opening-balances'` (namespace)
- `k[1] === 'rows'` (segment)
- `k[2] === companyId` (company slot, fixed-position)
- `k[3] === batchId` (batch slot, fixed-position)
- `k[k.length - 2] === tenantId` (tenant suffix)
- `k[k.length - 1] === companyId` (company suffix)

This rejects any other namespace, any other segment within
'opening-balances', any other company, any other batch, and any
other tenant/company suffix combination. The predicate is used
ONLY for rows queries which include optional params after the
batch slot (`{page, per_page, status}`) — fixed-key invalidation
wouldn't prefix-match.

**Axis 4 — Mutation cascades close intended active queries without
over-refetching: PASS.** Cascade test `create/delete refetch only
the active list and status keys` (.394-.397) at
queries.tenantScope.test.tsx:294-324 mounts list + status + detail
+ rows + preview, drives create then delete, asserts:
- listCalls 1 → 2 → 3 ✓
- statusCalls 1 → 2 → 3 ✓
- detailCalls stays at 1 (NOT cascaded) ✓
- rows/preview unaffected ✓

Test `row import/validate refetch only active detail and rows keys`
(.398-.401) at :326-356:
- detailCalls 1 → 2 → 3 ✓
- rowCalls 1 → 2 → 3 ✓
- listCalls stays at 1 ✓

Test `post/lock refetch the active exact keys they mutate`
(.402-.406) at :358-393:
- post: detailCalls + statusCalls go 1→2 each; listCalls + rowCalls
  unchanged ✓
- lock: detailCalls + statusCalls 1→3 (cumulative), listCalls 1→2;
  rowCalls unchanged ✓

These tests are exceptionally rigorous — they verify the predicate
is BOTH correct (refetches what it should) AND narrow (doesn't
refetch what it shouldn't). An over-broad predicate would inflate
the "should-stay-flat" counters and fail the test.

**Axis 5 — L18 cross-tenant DATA isolation present: PASS.** Test
at queries.tenantScope.test.tsx:396-433 uses persistent QueryClient
with `gcTime: Infinity`, pre-seeds tenant-B `['opening-balances',
'list', 'company-1', 'tenant-B', 'company-1']` with
`[batchFixture('leaked-tenant-b-batch')]` AND tenant-B rows with
`[rowFixture('leaked-tenant-b-row')]`, renders tenant-A
`useOpeningBatches()` and `useOpeningBatchRows('batch-1')`, asserts:
- tenant-A list === []
- tenant-A list IDs do NOT contain 'leaked-tenant-b-batch'
- tenant-A rows.data === []
- tenant-A rows IDs do NOT contain 'leaked-tenant-b-row'
- tenant-B cache entries SURVIVE unchanged

This is the strict L18 shape applied to BOTH namespaces in the
opening-balances feature.

**Axis 6 — Per-call counters verify active refetches: PASS.** All
three cascade tests use per-call counters (`listCalls`, `statusCalls`,
`detailCalls`, `rowCalls`) incremented via `mockImpl.mockImplementation
(async () => { counter += 1; return ... })`. Vacuous wraps or
always-false predicates would leave the counters at 1 and fail.

## Quality gates (re-run by Opus at HEAD)

- `pnpm vitest run src/features/opening-balances/api/__tests__/queries.tenantScope.test.tsx`:
  **6/6 pass**.
- `audit-tanstack-keys`: live count 580 (post-B26 cumulative);
  B25 individual delta 19 verified by callsite enumeration.
- `php artisan sweep:inventory:verify-history`: 3919 events / 1205
  callsites / 0 problems (pre-lock).

Verdict: APPROVE
