# Subagent-driven development ledger

Base: f1d6c1d30

Task A1: complete (commits f1d6c1d..96f78ab, review clean)
Task A2: complete (commits 96f78ab..85bb134, review clean)
Task A3: complete (commits 85bb134..2bca2c3, review clean)
Task A4: complete (commits 2bca2c3..0a74f2e, review clean)
Task A5: complete (commits 0a74f2e..83b03b7, review clean)
Gate 1: complete (tag phase3-gate-1, Opus review APPROVE)
Task B1: complete (commits b4891db..d619014, review clean)
Task A5: complete (reconcile-green pin passed on first run; no production changes)
Task B1: complete (tenant notifications migration; syntax, pretend SQL, and isolated real SQLite schema verified; behavior smoke remains assigned to B2)
Task B2: complete (404 RED before provider registration; notification DB-channel smoke + ownership-scoped pagination/filter/count/idempotent-read/malformed-UUID/read-all API GREEN at 6 tests/43 assertions; phpstan 1G clean; pint applied)
Task B3: complete (missing-notification RED; real PostgreSQL two-tenant resolver harness GREEN at 4 tests/11 assertions; company deny-direction, per-tenant registrar cache flush, inactive membership, database-only stable notification type; phpstan 1G clean; pint applied)
Task B3 reviewer fix: complete (`alert_type` is now always stored from the notification's stable constructor type; conflicting caller value RED then real PostgreSQL 4 tests/11 assertions GREEN; phpstan 1G clean; pint pass)
Task B4: complete (drift/portfolio/maturity notification RED-GREEN; company deny-direction, freeze/audit/failure isolation, repository-less portfolio failure logging, one notification per user/company, and zero-count anti-spam pinned; focused suites 26 tests/117 assertions, phpstan 1G clean, pint pass)
Task C1: complete (direction filter + filtered pagination meta RED-GREEN; mixed TND/EUR payment+journal totals stay per-currency; one exact SQL aggregate covers the full filtered range despite per_page=1; focused suite 14 tests/85 assertions, phpstan 1G clean, pint pass)
Task C2: complete (absent-without-param compatibility pinned; `flows_window` 1..90 adds exact SQL in/out sums over active company-currency cash repositories using `occurred_at`; foreign currency, inactive, virtual, and out-of-window movements excluded; 0/91/x canonical 422 pinned; RED 5 tests/3 failures, GREEN 6 tests/37 assertions; phpstan 1G clean; pint pass)
