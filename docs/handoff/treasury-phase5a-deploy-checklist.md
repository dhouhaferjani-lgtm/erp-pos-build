# Treasury Phase ⑤a — deploy checklist

Date: 2026-07-19
Scope: outbound supplier/expense cheques and effects, payable instrument accounts, lifecycle idempotency, outbound reconciliation, permissions, and expense lifecycle projection.

This checklist stacks on the existing Phase ③ and Phase ④ deploy checklists. Do not omit their migrations, permission/cache work, worker restart, or staging verification when deploying a combined release.

## Before deployment

- [ ] Back up the central database and every tenant database using the normal release procedure.
- [ ] Confirm the release contains these **three tenant migrations, in this order**:
  1. `2026_07_18_100000_add_presentation_cycle_to_payment_instruments.php`
  2. `2026_07_18_100100_add_action_key_to_instrument_events.php`
  3. `2026_07_18_100200_add_payment_instrument_to_expense_metadata.php`
- [ ] Confirm chart codes `403` (effects payable) and `4035` (checks payable) are approved as the seeder-owned Phase ⑤a codes. Do not hand-edit deployed chart rows.
- [ ] Confirm no tenant has an unresolved treasury freeze or portfolio drift before changing the application revision.

## Deploy order

Run from `apps/api` on the released revision:

```bash
php artisan tenants:migrate --force
php artisan tenants:run treasury:backfill-payable-instrument-accounts --option='dry-run=1'
php artisan tenants:run treasury:backfill-payable-instrument-accounts
php artisan tenants:run db:seed --option='class=Database\Seeders\RolesAndPermissionsSeeder' --option='force=1'
php artisan tenants:run permission:cache-reset
```

The sequence is load-bearing:

1. Migrate before enabling new code paths. The action-key uniqueness and expense FK must exist before outbound lifecycle traffic is accepted.
2. Run the chart backfill dry-run across every tenant. Any missing supplier parent account or existing `403`/`4035` row with a non-liability type is a deployment stop; investigate it rather than overwriting the row.
3. Run the idempotent real chart backfill. It creates missing liability children beneath account `40` for TN/FR charts and beneath `4000` for generic charts, or promotes a valid existing row to system-managed.
4. Re-seed roles and permissions inside every tenant.
5. Reset the Spatie permission cache inside every tenant. A central-only reset is insufficient because the cache key is tenant-blind.
6. Restart long-running API, scheduler, and Horizon/queue processes so they load the released listeners, commands, and permission map.

Phase ⑤a adds `instruments.clear-outbound` and `instruments.cancel-outbound`; intended grants are admin and accountant. Require affected users to sign in again or refresh their server-authoritative permission claims after reseeding.

## Verification before traffic

For every tenant/company:

- [ ] Confirm `403` and `4035` exist, are active liability accounts, are system-managed, and have the correct supplier parent.
- [ ] Confirm the three migrations are recorded and no Phase ⑤a tenant migration remains pending.
- [ ] Confirm admin and accountant users hold both outbound permissions; confirm manager remains forbidden.
- [ ] Issue one controlled deferred-supplier cheque. Verify exactly one issue journal entry (Dr supplier payable / Cr `4035`), no bank-account line, and no repository movement at issue.
- [ ] Clear the cheque. Verify one bank-credit journal entry, one outbound repository movement, and the expected repository balance change.
- [ ] Bounce and re-present it. Verify compensating and cycle-two entries are append-only and the instrument event history contains `re_presented`.
- [ ] Settle one posted generic expense by cheque. Verify issue leaves it unpaid, clear marks it paid, and cancel of a bounced replacement reopens it.
- [ ] Open `/treasury/instruments`; verify distinct receivable/payable schedules and the outbound instrument row.
- [ ] Run reconciliation per tenant:

  ```bash
  php artisan treasury:reconcile --tenant=<tenant-uuid>
  ```

  Expected: exit 0, zero cash freezes, zero portfolio drift, and zero errors.

- [ ] Run the maturity alert command once in the established tenant-aware scheduler context and confirm outbound due instruments use the payable alert keys/deep links:

  ```bash
  php artisan treasury:instrument-maturity-alerts
  ```

## Runtime and rollback notes

- No new queue name or Horizon worker class is introduced. The Expense projection is event-driven and remains outside Treasury model writes.
- Do not roll back by editing journal entries, repository balances, movements, instrument events, or expense metadata. Use the authorized compensating lifecycle action.
- Prefer an application rollback while retaining the additive migrations and chart rows. They are backward-compatible with pre-⑤a readers.
- If a lifecycle request times out, retry with the same idempotency/action semantics. Never create a replacement solely because the first response was lost; inspect the instrument event action key first.
- A chart-backfill error, a non-zero reconcile exit, an unexpected freeze, or any portfolio drift is a deployment stop.
