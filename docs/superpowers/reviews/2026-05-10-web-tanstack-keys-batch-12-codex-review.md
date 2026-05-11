Commit reviewed: 5780f96d

1. Scanner delta: PASS. Evidence: live scanner output was `736` after `[sweep-progress] Gate C - useQuery/useQueries/queryClient queryKeys without an approved tenant scope: 736`; prompt baseline says batch 12 closes `748 -> 736`.

2. State-value selectors in all 3 modals + 2 pages: PASS. Evidence: production uses `const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)` and `const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)` in `AssignAlertModal`, `DismissAlertModal`, `ResolveAlertModal`, `FraudAlertsPage`, and `FraudSettingsPage`.

3. Predicate namespace + tail gating and sibling rejection: PASS. Evidence: production predicates require exact namespaces and suffix t/c, e.g. `k[0] === 'fraud-alerts' && k[k.length - 2] === tenantId && k[k.length - 1] === companyId`, with equivalent `fraud-alert-statistics` and `fraud-settings` checks; HEAD tests include sibling negatives such as `expect(pred({ queryKey: ['fraud-alert-statistics', 'tenant-A', 'company-1'] })).toBe(false)`, `expect(pred({ queryKey: ['fraud-settings', 'tenant-A', 'company-1'] })).toBe(false)`, and `expect(pred({ queryKey: ['users', 'admin-role', 'tenant-A', 'company-1'] })).toBe(false)`.

4. Mutation invalidation cascades: PASS. Evidence: each modal `onSuccess: async () => { await Promise.all([ queryClient.invalidateQueries({ predicate: fraudAlertsInvalidationPredicate(tenantId, companyId) }), queryClient.invalidateQueries({ predicate: fraudAlertStatisticsInvalidationPredicate(tenantId, companyId) }) ]) ... }`; settings mutations each `await queryClient.invalidateQueries({ predicate: fraudSettingsInvalidationPredicate(tenantId, companyId) })`.

5. Users namespace wrapped but not cascaded: PASS. Evidence: production wraps the sibling query as `queryKey: tenantScopedKey(['users', 'admin-role'])` with `enabled: !!tenantId && !!companyId`; modal cascades only contain `fraudAlertsInvalidationPredicate` and `fraudAlertStatisticsInvalidationPredicate`, and HEAD test asserts `expect(getCounters().users()).toBe(hasUsersQuery ? 1 : usersBefore)`.

6. Cross-tenant isolation test: PASS. Evidence: HEAD test seeds `const tenantBKey = ['fraud-alerts', {}, 1, 'tenant-B', 'company-1']`, runs tenant-A predicate invalidates, then asserts `expect(tBQuery?.state.isInvalidated).toBe(false)`.

7. Test cascade drives production submit button: PASS. Evidence: HEAD test fills required fields with `fireEvent.change(...)`, computes `submitName`, then runs `fireEvent.click(screen.getByRole('button', { name: submitName }))`; the subsequent counters assert `expect(getCounters().alerts()).toBe(2)` and `expect(getCounters().stats()).toBe(2)`.

Verdict: APPROVE
