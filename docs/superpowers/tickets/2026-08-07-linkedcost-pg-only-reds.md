# LinkedCostExpenseTest — 2 PG-only pre-existing reds (non-UUID reference_id) (P3, test debt)

Source: R2-G backend gate bonus finding (2026-08-07). `tests/Feature/Expense/LinkedCostExpenseTest.php`
has 2 failures that reproduce ONLY on live PostgreSQL (green on sqlite): the fixture writes
`stock_movements.reference_id = 'sale-after-receipt'` — a non-UUID literal into a uuid
column. sqlite's loose typing accepts it; PG 22P02s.

Fix: use `Str::uuid()` fixtures. Same class as the "always use valid UUIDs for FK columns in
tests/seeders" rule (CLAUDE.md rule 17) — worth a sweep for other string-literal
reference_id/uuid-column writes in tests while at it. Relevant to plan v2's T-1 PG-mode CI
leg: these 2 reds will surface the moment the PG leg gates merges — fix before or with T-1.
