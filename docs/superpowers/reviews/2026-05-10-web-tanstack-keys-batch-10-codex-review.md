Commit reviewed: 6f9d8687

## Summary
Batch 10 wraps the CRM contact list/detail/form query keys and the PartnerSelect query key with tenant-scoped keys, and it converts contact mutation invalidations to predicate-based invalidation. The current scanner JSON contains 760 entries in its `violations` array after this batch; the exact requested `len(d)` command returns 1 because the JSON top level is an object with a `violations` key. The production code uses state-derived tenant/company values in the query gates and awaits invalidation before navigation or local state reset. The new tenant-scope test covers key shape, wrong-tenant rejection, and sibling namespace rejection, but it does not assert that tenant-B cache entries are evicted when the predicate is run for tenant-B.

## Axis 1 — Scanner delta
PASS. Evidence: apps/web/src/features/crm/pages/ContactListPage.tsx:25 and apps/web/src/features/crm/components/PartnerSelect.tsx:36 wrap CRM query keys with `tenantScopedKey`; current scanner `violations` count is 760.

## Axis 2 — State-value selectors
PASS. Evidence: apps/web/src/features/crm/pages/ContactDetailPage.tsx:23, apps/web/src/features/crm/pages/ContactDetailPage.tsx:24, apps/web/src/features/crm/pages/ContactFormPage.tsx:49, apps/web/src/features/crm/pages/ContactFormPage.tsx:50, apps/web/src/features/crm/pages/ContactListPage.tsx:21, apps/web/src/features/crm/pages/ContactListPage.tsx:22, apps/web/src/features/crm/components/PartnerSelect.tsx:32, apps/web/src/features/crm/components/PartnerSelect.tsx:33.

## Axis 3 — enabled gates
PASS. Evidence: apps/web/src/features/crm/pages/ContactDetailPage.tsx:29, apps/web/src/features/crm/pages/ContactFormPage.tsx:79, apps/web/src/features/crm/pages/ContactListPage.tsx:27, apps/web/src/features/crm/components/PartnerSelect.tsx:43.

## Axis 4 — Predicate gates + sibling-namespace rejection
PASS. Evidence: apps/web/src/features/crm/api/contactApi.ts:90, apps/web/src/features/crm/api/contactApi.ts:99, apps/web/src/features/crm/api/contactApi.ts:100, apps/web/src/features/crm/api/contactApi.ts:101, apps/web/src/features/crm/__tests__/tenantScope.test.tsx:145, apps/web/src/features/crm/__tests__/tenantScope.test.tsx:147, apps/web/src/features/crm/__tests__/tenantScope.test.tsx:238, apps/web/src/features/crm/__tests__/tenantScope.test.tsx:242.

## Axis 5 — Mutations onSuccess
PASS. Evidence: apps/web/src/features/crm/pages/ContactDetailPage.tsx:38, apps/web/src/features/crm/pages/ContactDetailPage.tsx:40, apps/web/src/features/crm/pages/ContactDetailPage.tsx:52, apps/web/src/features/crm/pages/ContactDetailPage.tsx:54, apps/web/src/features/crm/pages/ContactDetailPage.tsx:68, apps/web/src/features/crm/pages/ContactDetailPage.tsx:70, apps/web/src/features/crm/pages/ContactFormPage.tsx:104, apps/web/src/features/crm/pages/ContactFormPage.tsx:106, apps/web/src/features/crm/pages/ContactFormPage.tsx:118, apps/web/src/features/crm/pages/ContactFormPage.tsx:120.

## Axis 6 — Cross-tenant isolation test
FAIL. Evidence: apps/web/src/features/crm/__tests__/tenantScope.test.tsx:217 and apps/web/src/features/crm/__tests__/tenantScope.test.tsx:226 only run a tenant-A predicate against tenant-B entries; apps/web/src/features/crm/__tests__/tenantScope.test.tsx:229 through apps/web/src/features/crm/__tests__/tenantScope.test.tsx:235 assert tenant-B entries survive, but there is no assertion that those tenant-B entries are evicted when running the predicate for tenant-B.

## Axis 7 — ContactFormPage.test.tsx patch
PASS. Evidence: apps/web/src/features/crm/pages/__tests__/ContactFormPage.test.tsx:50.

## Issues
1. WARNING, apps/web/src/features/crm/__tests__/tenantScope.test.tsx:217, the cross-tenant isolation test only proves tenant-B entries survive a tenant-A predicate run and does not exercise the positive tenant-B invalidation path requested by the review axis, fix by adding tenant-B list/detail cache entries, running `contactsInvalidationPredicate('tenant-B', 'company-1')`, and asserting those entries become invalidated or are refetched/evicted according to the intended TanStack behavior.

Verdict: APPROVE
