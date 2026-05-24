# Task 04 R2 Codex Self-Adversarial Review — Page-Safe Customer Mirror Sync

## Scope Reviewed

- R2 implementation commit: `1b3610987 Phase 2.4.2: Page POS customer mirror sync`
- Original implementation commit: `f9023476c Phase 2.4.1: Sync POS customer mirror`
- Opus-equivalent R1 review: `docs/superpowers/reviews/2026-05-21-task-04-opus-review.md`

## Verdict

APPROVE.

The R2 fix resolves the capped-page cursor bug without weakening tenant/company isolation or turning server contract drift into a silent success.

## Finding Resolution

### R1 P1 — Cursor advances after a capped page, permanently skipping remaining customers

RESOLVED. The backend endpoint now fetches `limit + 1`, returns `has_more`, and emits a composite continuation cursor (`next_updated_since`, `next_updated_since_id`) from the last returned row when another page exists. The POS pull service loops with that composite cursor until `has_more` is false, then persists the final server `synced_at` into `customers.updated_since`.

The fix avoids the equal-timestamp boundary skip: the next page query includes `updated_at > cursor_timestamp OR (updated_at = cursor_timestamp AND id > cursor_id)`.

## R2 Regression Attack Vectors

### Cross-tenant/company safety

PASS. The POS service still validates every row in a page before any upsert. R2 added a mixed-page test with one valid row followed by a foreign-tenant row; no row is written and no cursor is advanced.

### Fail-loud vs silent-downgrade

PASS. The POS service now requires `has_more`; when `has_more` is true, it also requires both continuation cursor fields. Missing continuation state throws `CustomerSyncResponseError` before any upsert/cursor write.

### Cursor correctness

PASS. First page sends `limit=100` and the existing stored `updated_since` when present. Intermediate pages use the composite continuation cursor but do not persist it, so a crash during a multi-page pull repeats idempotent pages instead of advancing past missing data. The final persisted cursor remains the server `synced_at` from the exhausted page.

### Backend pagination contract

PASS. Endpoint tests cover same-`updated_at` rows split across a small page size and prove the second page returns the remaining row rather than skipping it. Malformed `updated_since_id` cases return 422, including present-without-`updated_since`, array-shaped input, and non-UUID input.

### D16 bounded-module guard

PASS. The R2 files remain inside POS presentation and POS local TypeScript sync code. The D16 grep over touched files returned no forbidden Treasury/Customers/B2B/Accounting imports.

### R2-fix risk

PASS. The mutable params-object defect surfaced by the new multi-page test was fixed by passing a fresh object to `apiGet()` for each page. Focused tests cover the regression.

## Verification Evidence

- Backend focused endpoint test: `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Feature/POS/PosCustomerSyncControllerTest.php` — 7 tests, 43 assertions.
- POS focused sync test: `pnpm test -- customerSyncService.test.ts` — 1 file, 8 tests.
- PHPStan L8 on touched backend files — no errors.
- Pint on touched backend files — pass.
- POS typecheck: `pnpm typecheck` — exit 0.
- POS lint: `pnpm lint` — exit 0 with the existing 42 warnings outside Task 4.
- Full POS suite: `pnpm test` — 155 files, 1405 tests.
- Full backend Fiscal/POS suite: `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Feature/Fiscal/ tests/Unit/Fiscal/ tests/Feature/POS/` — 1094 tests, 3668 assertions, 16 PHPUnit deprecations, 107 skipped, 2 incomplete.
- Chokepoint/pass2b/diff gate: `bash apps/api/scripts/check-saleReceipt-chokepoints.sh && bash apps/pos/scripts/check-pass-2b-pending.sh && git diff --check` — pass.
- D16 grep on R2 touched files — no matches.

## Residual Risk

The Task 4 service is still not wired into the scheduler/UI. That remains expected sequencing for later Phase 2 tasks; the pull primitive and cursor contract are now page-safe.
