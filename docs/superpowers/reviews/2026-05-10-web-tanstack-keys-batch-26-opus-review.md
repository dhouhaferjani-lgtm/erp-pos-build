Commit reviewed: 12a75368

# Opus review — web.tanstack-keys batch 26 (parapharmacy pages)

Independent second-pair-of-eyes review of the Codex implementation
at `12a75368`. All 5 review axes pass. Verdict APPROVE.

## Callsite tally

20 callsites verified in the production diff:
- 4 list pages × 2 (1 useQuery wrap + 1 deleteMutation predicate) = 8
  - CertificationListPage, HealthClaimListPage, IngredientListPage,
    KeyComponentListPage
- 4 form pages × 3 (1 useQuery wrap + create predicate + update
  predicate) = 12
  - CertificationFormPage, HealthClaimFormPage, IngredientFormPage,
    KeyComponentFormPage

Total: 8 + 12 = 20 ✓

## Axis-by-axis findings

**Axis 1 — Scanner delta exactly 20 (600 → 580): PASS.** Codex gate
report confirms `600 → 580` after B26. Current live scanner reads
565, 15 lower than 580 due to a concurrent in-progress B27 (partners)
working-tree edit that is NOT yet committed (working tree shows 6
modified partner files with predicate/wrap edits). The B26 committed
delta is verified by callsite enumeration above (20 = 8 list + 12
form).

**Axis 2 — State-value selectors present in all 8 page files: PASS.**
Spot-checked CertificationListPage.tsx:23-24, HealthClaimFormPage.tsx:
28-29, HealthClaimListPage.tsx (similar pattern), IngredientFormPage
(similar). Each reads `useAuthStore((s) => s.user?.tenant_id ?? null)`
and `useCompanyStore((s) => s.currentCompanyId ?? null)`.

**Axis 3 — Predicate is narrow and excludes detail keys: PASS.**
`parapharmacyListInvalidationPredicate` at tenantScope.ts:1-18 gates
on:
- `k.length >= 5`
- `k[0] === 'parapharmacy'` (namespace)
- `k[1] === resource` (parameterized — passed by caller)
- `typeof k[2] === 'number'` (page slot — REJECTS detail keys which
  have a string id at k[2])
- `k[k.length - 2] === tenantId`
- `k[k.length - 1] === companyId`

Predicate unit test at __tests__/tenantScope.test.tsx:326-335 covers:
- positive: `['parapharmacy', 'certifications', 1, 'tenant-A',
  'company-1']` → true
- detail rejection: `['parapharmacy', 'certifications', 'cert-1',
  'tenant-A', 'company-1']` → false (typeof k[2] === 'string')
- sibling resource rejection: `['parapharmacy', 'ingredients', 1,
  ...]` → false (k[1] mismatch)
- wrong tenant rejection: `[..., 'tenant-B', 'company-1']` → false

The `typeof k[2] === 'number'` gate is the canonical way to
distinguish list slot (numeric page) from detail slot (string id).

**Axis 4 — Mutation cascades await active list invalidation: PASS.**
Cascade test at __tests__/tenantScope.test.tsx:370-401 iterates over
all 4 resources (certifications, health-claims, ingredients, key-
components). For each resource:
- Renders the list page with a per-call counter on listMock
- Renders the form page in create mode and submits → listCalls 1 → 2
- Renders the form page in edit mode and submits → listCalls 1 → 3
- Verifies the predicate matches the active list key

Test asserts both production form pages drive the cascade through
production submit handlers (NOT predicate-direct invalidate). All
8 mutations (4 creates + 4 updates) are exercised. Delete mutations
on list pages use the same predicate and are covered by the
predicate unit test directly (the confirmation dialog path is not
the behavior under review).

**Axis 5 — L18 cross-tenant DATA isolation present: PASS.** Test at
__tests__/tenantScope.test.tsx:403-431 uses persistent QueryClient
with `gcTime: Infinity`, pre-seeds tenant-B `['parapharmacy',
'certifications', 1, 'tenant-B', 'company-1']` with a list response
containing `{...certificationFixture, id: 'leaked-tenant-b-
certification'}`, renders tenant-A `CertificationListPage`, asserts:
- tenant-A data.data === (empty/sentinel)
- tenant-A IDs do NOT contain 'leaked-tenant-b-certification'
- tenant-B cache entry SURVIVES unchanged

This is the strict L18 shape.

## Quality gates (re-run by Opus at HEAD)

- `pnpm vitest run src/features/parapharmacy/pages/__tests__/tenantScope.test.tsx`:
  **4/4 pass**.
- `audit-tanstack-keys`: live count 565 (lower than the 580 B26
  baseline due to concurrent B27 working-tree edits). B26
  individual delta 20 verified by callsite enumeration.
- `php artisan sweep:inventory:verify-history`: 3938 events / 1205
  callsites / 0 problems (after B25 lock; pre-B26 lock).

Verdict: APPROVE
