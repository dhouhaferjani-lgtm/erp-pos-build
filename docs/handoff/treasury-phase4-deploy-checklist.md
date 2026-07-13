# Treasury Phase ④ — Deploy Checklist

The following deploy owes are copied verbatim from the Phase ④ specification §10.

## 10. Deploy owes (stack on existing owes)

1. `tenants:migrate` — expense_metadata columns + `expense_recurrence_templates` (stacks with Phase ③ / banks / location-hierarchy owes).
2. Perm reseed + `permission:cache-reset` — `expenses.export`, `expense-recurrences.*` (tenant-blind cache bug).
3. **FE permission map** updated in the same merge (§8.5 — not a deploy step, a merge-completeness check).
4. Scheduler picks up `expenses:generate-recurring` automatically — verify once on staging.
5. No CoA action: `VatDeductible` (4456) seeded in all charts and present on the 5 staging tenants (Phase ② chart-seed remediation). Plan adds a verification step, not a backfill.
6. No Horizon change: `TreasuryAlertNotification` is database-channel, not queued (verified).

## Operator verification

Run the migration and permission commands in the tenant-aware deployment context, then verify the scheduler registration and the existing VAT account before enabling traffic. The VAT-presence check should confirm the `VatDeductible` purpose resolves to account code `4456` for each of the five staging tenants; this is a verification only, not a CoA backfill. Do not add a Horizon worker or queue configuration change for this phase.
