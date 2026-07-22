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

---

# Multi-location management ledger

Base: de5983c81c23aaeb8f359c9593339e88c9b41861
Branch: feat/multi-location
Plan 1: docs/superpowers/plans/2026-07-16-multiloc-1-scope-foundation.md
Plan 2: docs/superpowers/plans/2026-07-16-multiloc-2-inventory-visibility.md
Plan 3: docs/superpowers/plans/2026-07-16-multiloc-3-financial-location-dimension.md
Plan 4: docs/superpowers/plans/2026-07-16-multiloc-4-analytics-dashboards.md

Setup: complete (fresh origin/dev worktree; pnpm install; real composer install; scoped baseline 42 backend tests / 138 assertions and 46 frontend tests green)
Wave 1 Task 1: complete (commits cfaab904d..13d3db926; review clean after late-resolved tenant connection + wrapper-safe named-user fix; controller verification 16 tests / 53 assertions, PHPStan/Pint clean)
Wave 1 Task 2: complete (commit 887815245; review clean; controller verification 19 tests / 67 assertions, PHPStan/Pint clean)
Wave 1 Task 3: complete (commit f0da8796b; fresh review approved; controller and reviewer verification 6 tests / 6 assertions, PHPStan/Pint/diff clean; residual regression-strength gaps only for foreign-company NULL scope, unheld bypass, and inactive membership boundaries)
Wave 1 Task 4: complete (commits 089c31743..671523dd3; final fresh review approved after pre-validation permission, target-membership/list-shape, and PostgreSQL canonical UUID self-grant fixes; controller verification 56 SQLite tests / 190 assertions plus 1 physical PostgreSQL test / 3 assertions; PHPStan/Pint/diff clean; exact temporary DB removed)
Wave 1 Task 5: complete (commits f37ca641b..dbcd54e22; fresh review approved after narrowing the 403 adapter to sole source-access validation errors; combined focused/scope 17 tests/33 assertions, PHPStan/Pint/diff clean)
Wave 1 Task 6: complete (commit 107387ba5; fresh review approved; new endpoint suite 8 tests/32 assertions; combined location/resolver regressions independently 39 tests/117 assertions; route:list/middleware verified; PHPStan/Pint/diff clean)
Wave 1 Task 7: complete (commit 18c10d23c; fresh review approved; focused Vitest 4 tests, web typecheck/full lint/scoped ESLint clean; React Doctor score 49/100 with 149 pre-existing findings, none in changed files)
Wave 1 Task 8: complete (commit a0ef2191d; fresh review approved; focused Vitest 51 tests, audit:keys 0 violations, typecheck pass; scoped ESLint 0 errors/3 non-blocking unsafe-assertion warnings; full ESLint stalled due duplicate processes, React Doctor pre-existing diagnostics only)
Wave 1 Task 9: complete (commits 9b6282067, 8ea4f5424, dadd8a008; fresh FE/backend reviews approved; 23 focused FE tests plus owner regression, 43 API tests/135 assertions, FEFO 9 tests/15 assertions; typecheck/audit:keys/ESLint/PHPStan/Pint clean)
Wave 1 Task 10: complete (commits 03f5e1615, 758553d23, 271d41067; fresh review findings fixed for TS scope narrowing and assignment-preserving user list payload; 9 focused FE tests plus UsersPage 3 tests, ListUsersTest 11 tests/62 assertions; typecheck/audit:keys/ESLint/PHPStan/Pint clean)
Wave 1 Task 11: complete (commit 2ccd57b1b; fresh review approved; combined focused/regression 18 tests/66 assertions, PHPStan/Pint/diff clean)
Wave 2 Task 1: complete (commits e9d22fcba, 87d0e51d6; stock-matrix endpoint tests 4/23, SQLite portability fix, PHPStan/Pint clean)
Wave 2 Task 2: complete (commits 9393770ec, 3da06a267; threshold endpoint 8 tests/24 assertions, PHPStan/Pint clean; absent-membership remains middleware-level 403 rather than FormRequest 422)
Wave 2 Task 3: complete (commit ded837f4c; focused Vitest 3 tests, typecheck/audit:keys pass, scoped ESLint 0 errors/5 warnings, React Doctor pre-existing diagnostics only)
Wave 2 Task 4: complete (commits a996787b2, 5171c8d60; suggested header source, NULL-threshold fallback, variant-aware stock lookup; typecheck and focused transfer tests pass)
Wave 2 Task 5: complete (included in d20041972; always-visible product-location stock section, reserved/min-max fields, permission-gated transfer CTA, threshold editing; focused tests/typecheck pass; source prefill follow-up 2f895acbd)
Wave 2 Task 6: complete (commits acda986cf, 21a4d125d; explicit receiving destination persisted on receipts and PO lines, incoming projection reconciled, ReceiveGoodsDialog 5 tests, PHPUnit GoodsReceiptTest 25/88, PHPStan/Pint clean; dedicated A-then-B Postgres test remains)
Wave 2 Task 7: complete (commits d20041972, d0fbc4e7c, 1aac02b93, 94cc0c1e2; server-side rebalance endpoint/view and pure pairing tests; PostgreSQL endpoint tests 2/8, PHPStan/Pint/typecheck pass)
Wave 2 Task 8: complete (commits d8c489854, 94cc0c1e2 plus backend scope support in dadd8a008; server-side location filter, multi-id request, bccomp sign handling; PostgreSQL filter tests 2/6, PHPStan/Pint clean)
Wave 3 Task 1: complete (commits 40c7e6567, 709e99e76; payment repository location relation, company-scoped validation, FE assignment/list display; PostgreSQL repository-location tests 3/8, typecheck/PHPStan/Pint pass; locations table has company_id only, so validation correctly uses company scope)
Wave 3 Task 2: complete (commit 34c522443; guarded nullable payment/payment-instrument location migration, model fillables/docs, schema test 2/4 assertions, PHPStan/Pint clean)
Wave 3 Task 3: complete (commits dc818e945, 709e99e76; shared terminal resolver across receipt/account/deposit bridges, maturity instrument propagation, dedicated resolver tests 2/4 plus bridge/sibling regressions 24/105, PHPStan/Pint clean)
