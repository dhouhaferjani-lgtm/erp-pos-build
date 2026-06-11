# Runbook — verify all fiscal hash chains (per tenant, nightly)

> Launch-blocker **M2** (2026-06-09 Z-report fiscal audit). The verifiers exist but are
> fragmented and none is db-per-tenant-orchestrated. This runbook is the wrapper: it runs
> every chain verifier across every tenant DB and is the basis for the nightly schedule.

## The three verifiers (what each covers)

| Command | Scope | Key options |
|---|---|---|
| `fiscal:verify-chains` | **Documents** — invoice / credit-note fiscal hash chains | `--company=` `--type=invoice\|credit_note` `--fix` (dangerous) |
| `pos:verify-chains` | **POS** — legacy receipt chain **and** `pos_z_reports` Z chain | `--company=` `--terminal=` `--type=all\|receipts\|z-reports` |
| `fiscal:verify-event-chain` | **Canonical fiscal events** (v3/v4 device-authority, spec §12) — per terminal | `--tenant=` `--terminal=` `--chain-context=operational` `--from-sequence=` `--actor-id=` |

Coverage note: `fiscal:verify-event-chain` is the authoritative verifier for cutover (v3)
terminals (device-authored Z_REPORT / SALE_RECEIPT events). `pos:verify-chains` covers the
**legacy** receipt + Z mirror chains (coffee-shop / pre-cutover). Run **both** — a v3 terminal
has canonical events *and* a legacy `pos_z_reports` mirror row.

## Why a wrapper is needed (db-per-tenant)

As of 2026-05-28 the platform is **database-per-tenant** (one physical `tenant_<uuid>` DB each).
Every verifier above runs inside a single tenant's DB context. Running them once on the central
connection verifies nothing. The wrapper must bind each tenant in turn (Stancl tenancy) and run
each verifier under that binding — exactly the `TenantScopedCommand::forEachTenant()` pattern
(see `apps/api/app/Console/TenantScopedCommand.php` §14, mode **a-per-tenant-iter**).

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

A non-zero exit from any verifier = a broken chain → page the on-call + freeze the affected
terminal. **Never** pass `--fix` unattended (it rewrites chain links).

## All tenants (the wrapper recipe)

Until a dedicated `fiscal:verify-all-chains` command is shipped, drive the per-tenant loop with
the tenancy CLI. Pseudocode for the wrapper (extends `TenantScopedCommand`, calls `forEachTenant`):

```
forEachTenant(function (Tenant $tenant) {
    foreach ($tenant->companies as $company) {
        Artisan::call('fiscal:verify-chains', ['--company' => $company->id]);
        Artisan::call('pos:verify-chains',   ['--company' => $company->id, '--type' => 'all']);
    }
    foreach ($tenant->terminals as $terminal) {
        Artisan::call('fiscal:verify-event-chain', [
            '--tenant'   => $tenant->id,
            '--terminal' => $terminal->id,
            '--actor-id' => systemActorId(),
        ]);
    }
});
```

Aggregate every non-zero sub-exit into one summary; the wrapper exits non-zero if ANY chain failed.

## Nightly schedule

Add to `apps/api/app/Console/Kernel.php` (`schedule()`), low-traffic window, after the daily
business-date rollover:

```php
$schedule->command('fiscal:verify-all-chains')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->onFailure(fn () => /* alert: PostHog event + on-call page */ null);
```

## Known gaps (tracked, not closed by this runbook)

- **No TimescaleDB audit-tier verifier.** The two-tier model (fiscal SHA-256 chain + per-event
  audit hashes in TimescaleDB) has no verifier for the audit tier yet. The commands above cover
  the fiscal tier only. (Follow-up ticket.)
- **Wrapper command not yet shipped** — this runbook documents the recipe; the
  `fiscal:verify-all-chains` Artisan command is the next increment (extend `TenantScopedCommand`).
