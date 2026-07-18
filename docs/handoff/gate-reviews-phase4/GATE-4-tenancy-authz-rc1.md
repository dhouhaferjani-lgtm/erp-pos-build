# Treasury Phase 4 — GATE 4 tenancy/authz lane (rc1)

**Scope:** permissions/seeder and FE map, company scoping, Spatie team IDs, cross-company isolation, and route authorization over `git diff origin/dev..HEAD`.

## Verified

1. BE permission grants match spec §8.5: recurrence view is granted to all six roles; recurrence create/update/delete and `expenses.export` are limited to admin/manager/accountant.
2. `usePermissions.ts` matches the seeder and spec, and the expense module gate is registered.
3. Analytics, export, and recurrence routes are permission-gated; analytics/export precede `expenses/{id}`. FE routes and Sidebar navigation are gated.
4. Company scope is server-derived and enforced on expense/recurrence mutations, request rules, scoped foreign keys, analytics queries, and export queries.
5. Spatie team IDs use tenant IDs, never company IDs, including fallback actors and alert recipients.
6. Expense create/update and recurring generation are console-safe and do not read CompanyContext; actors remain same-tenant.
7. Sibling-company, cross-tenant, FK-rejection, deny-path, and two-company stamping tests are present and recorded green.

## Findings (INFO only)

1. Pre-existing expense show/reverse actions use in-controller Gate authorization; no new regression.
2. `fallbackActor` does not re-forget cache on restore; the recipients resolver owns its team-context cleanup and no impact was found.
3. Recurrence create spread ordering is safe under current request rules; document the override convention if the request evolves.

The lane could not independently rerun PHPUnit because the review harness blocked command approval; recorded green counts and source inspection were used.

**VERDICT: APPROVE**
