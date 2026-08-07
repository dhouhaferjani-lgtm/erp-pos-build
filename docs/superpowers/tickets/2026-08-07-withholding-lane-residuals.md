# R2-H withholding lane — gate residuals (2026-08-07, non-blocking)

Source: docs/superpowers/reviews/2026-08-07-r2h-{zerorate,routes}-gate.md (travel with branch).

1. **0% withholding RULES still creatable** (zerorate gate §4): CreateWithholdingRuleRequest
   rate min:0 — a 0% rule now silently yields no certificate on the payment path (guard
   refuses). Decide: forbid rate 0 at rule creation, or keep + document as deliberate no-op.
2. **`POST /withholding/preview` ungated** (routes gate observation): cashier-facing
   PaymentForm dependency — gating it is a product decision (cashiers need previews to ring
   withheld payments). Decide with the POS-permission owner.
3. **uuid-500 (F7, pre-existing)**: no Str::isUuid() before uuid-PK findOrFail in both
   withholding controllers → PG 500 on junk id. Same class as the CLAUDE.md pitfall; sweep.
4. **TaxationTenantIsolationTest.php:596** — identical decimal(5,4) fraction-vs-percent
   fixture overflow as the lane's F2 (pre-existing, PG-only). Fix with the T-1 PG-leg debt
   pile (joins linkedcost/T2 ticket family).
5. **Guard scale question** (zerorate gate §3): guard compares at resolved CURRENCY scale
   while storage is decimal(15,3) — a storable nonzero 0.004 EUR (scale-2 currency) is
   refused as zero. Conservative direction; revisit if a scale-2-currency tenant ever needs
   withholding certificates.
6. **PermissionSeeder wording** (F4): it IS called by ProductionSeeder:68 but never on
   tenant DBs (TenantInitializationService runs only RolesAndPermissionsSeeder) — dead for
   tenants, alive centrally; candidate for deletion/merge to end the trap that produced this
   lane's bug.
