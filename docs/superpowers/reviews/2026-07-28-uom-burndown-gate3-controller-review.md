# Gate 3 controller review (FINAL, pre-merge) — UoM baseline burn-down — APPROVED 2026-07-28

Controller gate on `chore/uom-baseline-burndown` @ `fe2b5eeed` (Wave 3 range `46d9dd6ac..2746f89ef`, 5 commits; whole branch ~45 commits on origin/dev `1201ba37d`). Codex report: `2026-07-28-uom-burndown-gate3-codex-report.md`. Two independent Opus lanes (fiscal-pos + frontend/guards), controller-run; Codex's internal review not relied upon.

## Verdict: APPROVE ×2 — CLEAR TO MERGE to local dev
Ticket acceptance met at final tip: quantity baseline `[]` + scanner detection re-proven live; phpstan neon whole-branch diff = EXACTLY the 9 sanctioned `precision.fixedScaluantityLiteral` removals (all sites proven fixed — level-8 re-run clean on the 5 files); all 5 guards registered and functional; generated artifacts drift-free; manifest drift check green.

## fiscal-pos lane — APPROVE (2 MINOR)
- **ZReportSyncController (the critical site) CORRECT**: currency resolved BEFORE the default; constructor-injected resolver `getScaleSafe($currencyCode, 3)` (no contextless no-arg trap); zero via `CurrencyScale::bcformatStrict` at money scale; non-default path byte-identical; `VarianceAmount`/`CashCountRecorded`/dispatch args untouched; fraud-alert listener `isZero()`-tolerant; EUR scale-2 default test present.
- PosPendingCustomer: `requireCompany()` proven equivalent resolution; strict zero balances at company-currency scale; Partner shape + transaction unchanged. Receipt/DiscountPolicy/StandaloneReceipt defaults byte-identical via `formatForUnit('0'|'1', null)`.
- Route retirement clean: routes gone, `syncCloseShift` + v3 409-guard intact, no half-dead controller, 404 test green, zero-caller proof re-run independently (tree grep + `git log -S`), obsolete tests deleted were provably route-specific.
- Fiscal parity re-run: golden-byte + fixture-integrity 19 OK; chokepoint + schema2 + pending-customer + retirement 30 tests / 8,045 assertions OK; whole-range file list touches NO projection/hash/canonical/signer code.
- **MINOR-1 FIXED in this commit**: stale doc refs to retired routes struck (`docs/tauri-pos/05-BACKEND-GAPS.md` §3/§4, `docs/pos/ROADMAP.md:360`).
- **MINOR-2 → backlog 🎫**: `CashCountValidationService.php:105` pre-existing hardcoded `'0.0000'` `VarianceAmount` zero (Application layer, out of ratchet scope; consumers `isZero()`-tolerant) — route through per-currency scale later.

## frontend/guards lane — APPROVE (2 MINOR, no action)
- Part D real and green: both RuleTester suites exercise genuine failure modes (string literal, expression-wrapped, case-insensitive, `.5`), chained into both `test:eslint-rules`, both rules still registered in the live eslint configs; chains run green.
- Whole-branch sweep CLEAN: no eslint-disable/@ts-ignore/phpstan-ignore additions in code; guard rule logic files + phpstan.neon untouched; scanner diff = only the sanctioned Wave-1 `QuantityCell` exemption with detection sets unchanged and live detection re-proven by spot-test; design-system baseline change = one honest tightening (InvoicesPage raw input → QuantityInput). tanstack baseline unchanged.
- `typescript:transform` re-run: 454 types, zero drift; `permissionsMap.generated.ts` unchanged (correct). Wave 3 touched zero FE src files → the 20 unscoped-failing web suites are definitionally not Wave-3 regressions (A/B claim verified causally).
- Merge-readiness: deletions provably route-specific; no renames/env/config/debug leakage; all gate records present; no self-approval in Codex's tip commit.
- MINOR-1 (informational): InvoicesPage `decimalPlaces={2}` literal is sanctioned (platform-admin billing, outside tenant unit-precision contract, passes lint structurally). MINOR-2: PHPUnit "warnings" in worktree = absent `.env` bootstrap notice only.

## Post-gate actions (this commit)
- Struck the retired-route doc lines (fiscal MINOR-1).
- 🎫 Backlog: `CashCountValidationService` per-currency zero; owner-dashboard test harness (`useOwnerReports`), 3 red inventory tests + tenantScope pair (multiloc debt) — all pre-existing, tracked, not owed by this branch.

## Merge plan
Merge `--no-ff` into LOCAL dev (base identical — origin/dev unmoved at `1201ba37d`); scoped re-verification at merge tip (scanner, drift check, typecheck); promote to origin/dev as clean ff on owner GO. Deploy: NO migrations, NO seeder prereqs, NO new POS sqlite version (still v62); the retired routes 404 after deploy (zero callers, soft-fail safe); POS device build still owes the v60–v62 stack from the base features.
