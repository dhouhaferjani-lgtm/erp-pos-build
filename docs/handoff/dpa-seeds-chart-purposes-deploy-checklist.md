# Deploy checklist — DPA SEEDS lane (country chart purposes + expense categories)

Branch: `fix/dpa-seeder-gaps-accounting` · Register entries: E-1/H-5, G-3, G-4, G-5, G-8, G-10.

## What ships

| Change | Effect on an EXISTING tenant | Effect on a NEW company |
|---|---|---|
| FR chart maps `CostOfGoodsSold → 603`, `GeneralExpense → 628`, `SupplierAdvance → 409`, `CustomerAdvance → 419`, `UninvoicedRevenue → 418`, `SalesDiscount → 7097` (+ parity accounts) | none by itself — seeders never rewrite an existing chart. Repaired by the migration below. | seeded at provisioning |
| TN chart gains the `606` family, `418`, `7097`, `6251`/`6257`; `43666` reparented to `44` | none by itself | seeded at provisioning |
| `SystemAccountPurpose::requiredPurposes()` gains `CostOfGoodsSold` + `GeneralExpense` | `GET /companies/{id}/accounts/validate` reports these as missing until the backfill runs. **Nothing in provisioning or posting gates on this endpoint** — no flow is blocked by the widening itself. | — |
| Country-aware default expense categories, wired into BOTH provisioning paths (registration + `POST /companies`) | none retroactively (existing companies keep whatever categories they have) | seeded at provisioning |
| TN `TRANSFER` payment method; FR tax configs drop the two non-`FiscalCategory` tokens | payment methods: `updateOrCreate` at provisioning only. Tax configs are global rows, refreshed by the seeder on the next run. | seeded |

## Automatic (no operator action)

`database/migrations/tenant/2026_08_10_090000_backfill_chart_purposes.php` runs on `tenants:migrate`,
which staging auto-runs on push to `origin/dev`. It delegates to
`accounting:backfill-chart-purposes`, is idempotent, and **never throws** — a chart it cannot place is
logged, not fatal. The repair runs inside a **savepoint on the migration's own connection**, which is
what makes that promise true on PostgreSQL: a failed statement aborts the enclosing migration
transaction (SQLSTATE 25P02), so a merely-caught exception would still kill this tenant's run at the
next statement. One broken tenant cannot abort the run for the others.

**Post-deploy log check.** The gate line for this path uses its own token,
`CHART-PURPOSE BACKFILL MIGRATION:`, emitted at **warning** level (`error` on the exception path) —
NOT at `info`, because production runs `LOG_LEVEL=warning` (`apps/api/.env.production.example:33`) and
would drop an info-level line, leaving both greps below to pass on an empty log. Exactly one line per
tenant, written once (a migration runs once per tenant database):

```bash
# BEFORE running tenants:migrate: mark the current end of the log, so the gates below
# read ONLY this deploy's window (laravel.log is cumulative — an exact count against the
# whole file false-fails on every deploy after the first).
LOG_MARK=$(wc -l < storage/logs/laravel.log)

# (a) EVERY tenant reported — absence is a FAILURE, not a pass. Set TENANT_COUNT first.
#     tenants:list prints a "Listing all tenants." header line — count only tenant rows:
TENANT_COUNT=$(php artisan tenants:list | grep -c '^\[Tenant\]')
test "$(tail -n +$((LOG_MARK+1)) storage/logs/laravel.log | grep -c 'CHART-PURPOSE BACKFILL MIGRATION:')" -eq "$TENANT_COUNT"

# (b) no tenant failed. Covers BOTH the command-failure path (warning) and the
#     exception path (error) — they share the token and the status word.
! tail -n +$((LOG_MARK+1)) storage/logs/laravel.log | grep -q 'CHART-PURPOSE BACKFILL MIGRATION:.*status=FAILED'
```

Each line carries `tenant=<key>`, so a failure is attributable in a shared `laravel.log`. The **per-company**
reasons are logged at `info` level and are therefore visible only where `LOG_LEVEL` admits them; on
production, get them by re-running the manual path below, which prints every reason to stdout.

## Manual re-run (or a pre-flight dry run)

```bash
# 1. Dry run first — writes nothing, reports what it would create/promote.
php artisan tenants:run accounting:backfill-chart-purposes --option='dry-run=1' \
  | tee /tmp/chart-backfill.log

# 2. Apply.
php artisan tenants:run accounting:backfill-chart-purposes | tee /tmp/chart-backfill.log

# 3. GATE — both halves are required.
#    (a) no tenant reported a failure. NEVER `grep -q '… : 0'`, which passes as
#        soon as ANY ONE tenant is clean and lets a "FAILURES: 3" through:
! grep -qE 'CHART-PURPOSE BACKFILL FAILURES: [1-9]' /tmp/chart-backfill.log

#    (b) every tenant actually reported — ABSENCE of the token means the command
#        aborted before finishing (e.g. the Schema guard tripped) and is a FAILURE:
test "$(grep -c 'CHART-PURPOSE BACKFILL FAILURES:' /tmp/chart-backfill.log)" -eq "$TENANT_COUNT"
```

`tenants:run` swallows the child exit code, which is why the token — not `$?` — is the gate. Boolean
flags must be passed as `--option='dry-run=1'`; there is no `--` passthrough.

## If the gate reports failures

The command names the company and the reason on stderr. The four causes, and their remedies:

| Reported | Meaning | Remedy |
|---|---|---|
| `missing parent account <code>` | the chart has no `60` / `62` / `41` / `70` class header | add the header in Settings → Chart of Accounts, re-run |
| `has wrong type X; expected Y` | an account already sits at the canonical code with an incompatible type | rename/move that account, or assign the purpose manually to a correct one |
| `is inactive` | the canonical-code account exists but is deactivated | reactivate it, re-run |
| `already carries system_purpose X; refusing to repurpose it` | an operator mapped that code to something else | assign the purpose to another account manually |

A chart that already resolves a purpose **on a different code** is reported and left alone — that is
correct, not a failure: resolution is purpose-first, so an expert accountant may legitimately hold
`CostOfGoodsSold` on `6037` instead of `603`.

## No other deploy step

No permission changes (so no `permission:cache-reset`), no queue/Horizon changes, no seeder that must
be re-run by hand. `DemoPharmacySeeder` / the parapharmacy seeders pick up the new expense categories
on their next run; re-running them is optional.
