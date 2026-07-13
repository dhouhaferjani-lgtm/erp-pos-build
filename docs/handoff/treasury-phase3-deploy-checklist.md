# Treasury Phase 3 deployment checklist

Date: 2026-07-12  
Scope: inter-repository transfers, notification center, cash-movements totals/filters, and cash-position widgets.

## Before deployment

- [ ] Confirm the release contains tenant migrations `2026_07_12_100000_unique_journal_entries_source_treasury_transfer.php` and `2026_07_12_110000_create_notifications_table.php`.
- [ ] Back up the central and tenant databases using the normal release procedure.
- [ ] Confirm Horizon/queue workers are healthy. Treasury notifications use Laravel's database channel; no new queue or mail transport is required.
- [ ] Do not reseed the chart of accounts. Phase 3 uses each repository's existing GL account and adds no chart accounts.

## Deploy and migrate

Run from `apps/api` after the new application release is available:

```bash
php artisan tenants:migrate --force
php artisan tenants:run db:seed --option='class=Database\Seeders\RolesAndPermissionsSeeder' --option='force=1'
php artisan tenants:run permission:cache-reset
```

- [ ] Verify every tenant applied both Phase 3 migrations successfully.
- [ ] Verify the roles/permissions reseed added `treasury.transfer` to the intended admin, manager, and accountant roles.
- [ ] Reset permissions inside every tenant. The Spatie permissions cache is tenant-sensitive and must not be cleared only in the central database.
- [ ] Restart long-running API, scheduler, and Horizon/queue processes so they load the released code.

Tokens minted before the permission reseed do not gain `treasury.transfer` until the user's roles/claims are re-synchronized. Require affected users to sign in again (or use the established token refresh flow). The frontend role fallback is a development convenience, not a production substitute for refreshed server-authoritative permissions.

## Staging smoke

- [ ] Sign in as a user with `treasury.transfer` and open `/treasury/repositories`.
- [ ] Transfer a small amount from a cash-register repository to a bank repository. Confirm the success toast, both balance changes, two repository movement legs, and one posted balanced journal entry (Dr destination GL / Cr source GL).
- [ ] Open `/finance/cash-movements`. Confirm both transfer legs, the per-currency totals row, and repository/direction filters.
- [ ] Open `/reports` and `/dashboard` with appropriate users. Confirm the cash-position widget and its seven-day in/out values.
- [ ] Trigger or safely seed one treasury drift notification. Confirm the bell badge, panel content, repository deep link, mark-read behavior, and badge clearance.
- [ ] Run `php artisan treasury:reconcile --tenant=<tenant-id>` and confirm it exits successfully with no unexpected freezes or drift audit events.

## Rollback notes

- Prefer application rollback while retaining the additive notification table and unique transfer-source index; both are backward-compatible with the pre-Phase-3 application.
- Do not reverse a completed transfer by editing repository balances or immutable movements. Use an authorized compensating transfer.
- If a smoke transfer fails, preserve its transfer-group ID and inspect the response/audit trail before retrying; idempotent replay must reuse the same client transfer-group ID.

