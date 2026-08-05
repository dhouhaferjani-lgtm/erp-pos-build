# Runbook — verify all fiscal hash chains (per tenant, nightly)

> Launch-blocker **M2** (2026-06-09 Z-report fiscal audit). The verifiers exist but are
> fragmented and none is db-per-tenant-orchestrated. This runbook is the wrapper: it runs
> every chain verifier across every tenant DB and is the basis for the nightly schedule.
>
> **Updated 2026-08-05 (cat-(b) wave 2).** All three verifiers now drive their OWN
> per-tenant iteration. The "wrapper recipe" below is therefore no longer a loop around
> them — see [All tenants](#all-tenants-the-fleet-run) — and `fiscal:verify-chains --fix`
> no longer exists.

## The three verifiers (what each covers)

| Command | Scope | Key options |
|---|---|---|
| `fiscal:verify-chains` | **Documents** — invoice / credit-note fiscal hash chains | `--tenant=` `--company=` `--type=invoice\|credit_note` |
| `pos:verify-chains` | **POS** — legacy receipt chain **and** `pos_z_reports` Z chain | `--tenant=` `--company=` `--terminal=` `--type=all\|receipts\|z-reports` |
| `fiscal:verify-event-chain` | **Canonical fiscal events** (v3/v4 device-authority, spec §12) — per terminal | `--tenant=` `--terminal=` `--chain-context=operational` `--from-sequence=` `--actor-id=` |

Coverage note: `fiscal:verify-event-chain` is the authoritative verifier for cutover (v3)
terminals (device-authored Z_REPORT / SALE_RECEIPT events). `pos:verify-chains` covers the
**legacy** receipt + Z mirror chains (coffee-shop / pre-cutover). Run **both** — a v3 terminal
has canonical events *and* a legacy `pos_z_reports` mirror row.

## Why a wrapper is needed (db-per-tenant)

As of 2026-05-28 the platform is **database-per-tenant** (one physical `tenant_<uuid>` DB each).
Every verifier above runs inside a single tenant's DB context. Running them once on the central
connection verifies nothing.

**As of the 2026-08-05 cat-(b) wave-2 conversion each verifier does this itself**: all three
extend `TenantScopedCommand` and drive their own `forEachTenant()` loop
(`apps/api/app/Console/TenantScopedCommand.php` §14, mode **a-per-tenant-iter**), binding each
tenant database in turn. No outer loop is needed, and adding one is now actively harmful — see
the warning under [All tenants](#all-tenants-the-fleet-run).

## Manual run (single tenant)

```bash
cd apps/api
# Documents
php artisan fiscal:verify-chains --company=<COMPANY_UUID>
# POS receipts + Z mirror
php artisan pos:verify-chains --company=<COMPANY_UUID> --type=all
# Canonical fiscal events, per terminal (repeat per terminal_id)
php artisan fiscal:verify-event-chain \
  --tenant=<TENANT_UUID> --terminal=<TERMINAL_UUID> \
  --chain-context=operational --actor-id=<SYSTEM_USER_UUID>
```

A non-zero exit from any verifier = a chain that is broken **or was never verified** → page the
on-call + freeze the affected terminal. Read the `TENANT COVERAGE:` block at the end of the
document and POS verifier output: it carries one line per tenant
(`verified` / `FAILED` / `NO-DATA` / `SKIPPED` / `ERRORED`), and a `SKIPPED` or `ERRORED` line
means nothing was verified for that tenant however green the rest of the run looks.

`fiscal:verify-chains --fix` was removed on 2026-08-05: the flag was declared and dangerous but
read by nothing, and a server-side "fix" of a device-authored chain would itself be a
fiscal-integrity defect. Passing it now fails with an unknown-option error.

## All tenants (the fleet run)

The two fleet-default verifiers already ARE the fleet run. Omit `--tenant`:

```bash
cd apps/api
php artisan fiscal:verify-chains              # every tenant, every company
php artisan pos:verify-chains --type=all      # every tenant, every active terminal
```

Each iterates the central tenant directory, opens every tenant database whose file/DB exists,
emits `TENANT <id> (<slug>): …` verdicts as it goes, and closes with the `TENANT COVERAGE:`
block. The aggregate exit is non-zero if ANY tenant reports a break, was skipped, or threw — the
PASS banners (`Status: ALL CHAINS VALID ✓`, `All chains verified successfully.`) are unreachable
otherwise.

`fiscal:verify-event-chain` has no fleet mode by design: its permission gate is anchored on an
`--actor-id` that resolves in exactly one tenant's `users` table. Run it once per
(tenant, terminal) with that tenant's actor.

> **Do NOT wrap these in an outer `forEachTenant`.** The pre-2026-08-05 recipe in this runbook
> called `Artisan::call('fiscal:verify-chains' | 'pos:verify-chains' | 'fiscal:verify-event-chain')`
> INSIDE a `forEachTenant` closure. Post-conversion that is doubly wrong: each inner command
> drives its own full-fleet loop (so the work becomes O(N²)), and the inner loop's
> `tenancy()->end()` (`TenantScopedCommand.php`, the `finally` in `forEachTenantNarrowed`) tears
> down the OUTER binding, leaving the remaining outer iterations running against whatever
> connection is default at that point.

## Nightly schedule

Schedule the two fleet-default verifiers directly (low-traffic window, after the daily
business-date rollover). A dedicated `fiscal:verify-all-chains` umbrella is no longer required
for tenant iteration; it would only add the fiscal-events leg, which needs a per-tenant actor.

```php
$schedule->command('fiscal:verify-chains')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->onFailure(fn () => /* alert: PostHog event + on-call page */ null);

$schedule->command('pos:verify-chains --type=all')
    ->dailyAt('03:45')
    ->withoutOverlapping()
    ->onFailure(fn () => /* alert: PostHog event + on-call page */ null);
```

## Known gaps (tracked, not closed by this runbook)

- **No TimescaleDB audit-tier verifier.** The two-tier model (fiscal SHA-256 chain + per-event
  audit hashes in TimescaleDB) has no verifier for the audit tier yet. The commands above cover
  the fiscal tier only. (Follow-up ticket.)
- **No fleet mode for `fiscal:verify-event-chain`** — deliberate (the `--actor-id` permission
  gate resolves in exactly one tenant), so the fiscal-events leg is still driven per
  (tenant, terminal) by the operator or by a wrapper that supplies the right actor per tenant.
  The document and POS legs no longer need one.
