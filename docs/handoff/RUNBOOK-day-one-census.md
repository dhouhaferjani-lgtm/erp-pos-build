# Day-one tenant census

Direct, inside an already-bound tenant context (exit code is meaningful):
`CACHE_STORE=array php artisan tenant:census-day-one --fail-on-drift`

Across local/staging tenants:
`CACHE_STORE=array php artisan tenants:run tenant:census-day-one --option='fail-on-drift=1'`

`tenants:run` discards each child exit code. Grep `DAY-ONE CENSUS` and treat every `DRIFT(n)` verdict as failure.

Remediate the failing invariant before onboarding continues:
- `units_visible_min_19`: rerun company unit provisioning; verify categories have base units.
- `tax_configurations_seeded`: rerun the country tax template and restore one default.
- `required_purposes_tagged`: repair the chart purpose assignment printed by the row.
- `refund_purposes_seeded_by_country_template`: restore SalesReturn and RefundWriteOff.
- `one_drawer_per_pos_location_and_one_safe`: provision one ACTIVE, GL-LINKED cash register per POS-enabled location and one active, GL-linked safe per company (a drawer with `gl_account_id` NULL is refused by POS/GL/refunds and reads DRIFT here); a company with no active POS-enabled location is DRIFT, not clean.
- `cash_tender_coherent`: exactly one active method flagged `is_cash_tender`, its code is `CASH`, and no other CASH-family method exists (I-1 invariant shapes A/B/C).
- `no_numbered_drafts`: investigate the writer; drafts must remain unnumbered.
- `onboarding_checklist_consistent`: rerun the provisioning step whose data is absent.

I2-F1 is closed by G-3c: a second-company repository failure is now regression drift, not an accepted gap.
I2-F2 remains open and test-only: product imports still leave `unit_id` NULL; this command cannot certify it.

Exit codes (direct invocation only — `tenants:run` discards them, read the `DAY-ONE CENSUS …` verdict lines): 0 clean or report-only, 1 drift with `--fail-on-drift` (or no company: `DAY-ONE CENSUS <tenant> -: NO-COMPANY`), 2 invalid `--company` (not a UUID).

Coverage residual (as of `e0c3c36b0`): the PHPUnit fixture exercises the Tunisia chart template only; `FranceChartOfAccountsSeeder` and `GenericChartOfAccountsSeeder` are unguarded in CI — the census command itself is country-agnostic.
