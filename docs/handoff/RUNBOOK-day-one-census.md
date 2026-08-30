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
- `one_drawer_per_pos_location_and_one_safe`: provision one drawer per POS location and one safe.
- `payment_methods_seeded`: provision active methods with exactly one cash tender.
- `no_numbered_drafts`: investigate the writer; drafts must remain unnumbered.
- `onboarding_checklist_consistent`: rerun the provisioning step whose data is absent.

I2-F1 is closed by G-3c: a second-company repository failure is now regression drift, not an accepted gap.
I2-F2 remains open and test-only: product imports still leave `unit_id` NULL; this command cannot certify it.
