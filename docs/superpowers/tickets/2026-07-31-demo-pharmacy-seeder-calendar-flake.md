# Ticket: DemoPharmacySeederTest calendar-dependent flake (fails on days >= 29)

Found during Lane D1 review (2026-07-31), pre-existing (verified byte-identical on base 711f3d79f).
`test_seeds_recent_sales_invoices_across_last_30_days` asserts a Posted invoice dated before the
current month, but the seeder plan maxes at now()->subDays(28) (DemoPharmacySeeder.php:1588,1601) —
on any day >= the 29th, subDays(28) stays inside the current month and the test fails. Sibling
`seeds_gl_consistent_partner_balances` (SUPP-PAYABLE-01 payable_balance < 0) also red, pre-existing.
Fix shape: widen the seeder plan to subDays(35) or relax the assertion to a rolling window. Also the
web X-report button is ungated on isDeviceAuthoritative (ShiftDashboardPage.tsx:253-262) — moot
while web terminals are v2; gate it if a v3 web terminal ever exists.
