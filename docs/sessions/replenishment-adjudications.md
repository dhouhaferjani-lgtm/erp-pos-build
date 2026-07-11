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
