# Subagent-driven development ledger

Base: 94a7c08cc0f4bde729a5dd8cf4c23388641840b5
Plan: docs/superpowers/plans/2026-07-13-treasury-phase4-expense-depth.md

Task 1: complete (rebased commit 6081f8c47, review clean)
Task 2: complete (rebased commit 7d3c2af8c, review clean)
Task 3: complete (rebased commits 68bcb04fb..08c392a0b, review clean after linked-cost trio fix)
Task 4: complete (rebased commits 8a71a72bf..3b59d48b8, review clean after TVA-detail collision fix)
Task 5: complete (rebased commits ae64500ea..1b99f6e5d, review clean after persistence, isolation, accessibility, historical-rate, and explicit-null supplier-clear fixes)
Task 6: complete (commits 3928e7f7f..806fceace; review clean after correcting branch-caused Document partner PHPDoc static-analysis regression; Gate 1 ready)
Gate 1: APPROVE (Fable, rc1; final tag phase4-gate-1 at bf5144ef7; no blocker/high/medium findings)
Task 7: complete (commits 4f927654a..5a8c18b2d; review clean after real ExpenseMetadata recurrence-link persistence fix)
Task 8: complete (commit 3ad776504; review clean; 157,824 origin-cursor property checks plus focused/regression suites green)
Task 9: complete (commits 627234dfc..13388cf94; review clean after ended-boundary and lifecycle-transition fixes; BE/FE grants aligned)
Task 10: complete (commit f33fef2db; review clean; atomic generation/replay/notification/timezone/isolation contracts green)
Task 11: complete (commit 9faaa374e; review clean; recurring projection/draft/posted partitions and exact company-scale totals green)
Task 12: complete (commits 508729847..670619180; review clean after origin serialization, VAT invariant, async rejection, and scoped cache fixes; React Doctor no issues)
Gate 2: APPROVE (Opus general + tenancy/authz lanes, rc1; final tag phase4-gate-2 at dc78d567c; no blocker/high/medium findings; no Fable escalation)
Task 13: complete (commits 6ed0ebb0a..3229de35d; review clean after scoped top-vendor partner identity fix; analytics 9/65 and full Expense 105/664 green)
Task 14: complete (commit a9e0ee4ea; review clean; shared list/export filters, streamed 45-row CSV, authz/isolation and raw decimal contracts green)
Task 3: complete (initial rebased commit 68bcb04fb; review fix adds effective VAT-trio linked-cost guards)
Task 3 review-fix TDD: RED 4 failed / 17 passed (34 assertions); GREEN 21 passed (38 assertions); final Expense path 63 passed (250 assertions); scoped PHPStan and Pint clean
Task 3 review disposition: did not implement the VAT-less-total finding. The binding brief says, "When VAT is present, total and vat_amount must be ON THE CURRENCY GRID," and separately requires zero VAT to normalize to null for the VAT-less backward-compatible path. Rejecting VAT-less EUR total `119.005` would therefore add an unplanned breaking validation change outside Task 3.
