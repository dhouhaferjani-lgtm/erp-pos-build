# LinkedCostExpenseTest — 2 PG-only pre-existing reds (non-UUID reference_id) (P3, test debt)

Source: R2-G backend gate bonus finding (2026-08-07). `tests/Feature/Expense/LinkedCostExpenseTest.php`
has 2 failures that reproduce ONLY on live PostgreSQL (green on sqlite): the fixture writes
`stock_movements.reference_id = 'sale-after-receipt'` — a non-UUID literal into a uuid
column. sqlite's loose typing accepts it; PG 22P02s.

Fix: use `Str::uuid()` fixtures. Same class as the "always use valid UUIDs for FK columns in
tests/seeders" rule (CLAUDE.md rule 17) — worth a sweep for other string-literal
reference_id/uuid-column writes in tests while at it. Relevant to plan v2's T-1 PG-mode CI
leg: these 2 reds will surface the moment the PG leg gates merges — fix before or with T-1.

---

# APPENDED 2026-08-07 (R2-I lane find): T2MigrationRollbackTest red on dev — same hardcoded-list class

`T2MigrationRollbackTest` hardcodes a 16-file list; `2026_06_09_…add_variant_id_to_stock_transfer_lines`
and `2026_07_10_…create_replenishment_requests_table` later added FKs to `product_variants`,
so its `DROP TABLE product_variants` fails ("Dependent objects still exist") on the current
tree. Pre-existing dev red (gate to confirm at base). Fix = convert it to the NEW
`ProvesTenantMigrationRoundTrip` harness from lane R2-I — the intended first real-world
consumer. Same test-debt family as the LinkedCost PG reds above; both bite at T-1.
