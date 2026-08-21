# M3 GATE — round 1 · lenses: fiscal-pos, general

**Range reviewed:** `9d14cb8e1..HEAD` (11 commits; M3 = `5b805657c`, one file, +244/−14).
**Amending authority applied:** `M1-ruling.md` — Option B unconditional; condition 2 (SESSION_CLOSE lockstep) checked at M2/M3; condition 4 (F-2 out of scope) honoured.

## What I verified first-hand (not from the brief)

- Ran the declared Tier-1 set by path: `saleReportingEndToEnd` (7) + `zReportService` (36) + `endOfDayPreview` (23) + `refundReportingEndToEnd` (2) + `reportApi` (1) + `reportApi.localTimestamps` (5) → **74 passed / 6 files**.
- `pnpm typecheck` (@autoerp/pos) → clean. `pnpm lint:ratchet` → **@autoerp/pos held at 84**; @autoerp/web +3 with `git diff base..HEAD -- apps/web scripts/lint-warning-baseline.json` **empty** → inherited, confirmed structurally, not asserted.
- **Non-vacuity, proved arithmetically against the pre-fix expressions in the diff** (I could not mutate files — read-only): pre-fix `lineNet = line_total`, `lineGross = bcadd(lineNet, lineVat)` gives mixed-rate rate-20 `12.00/14.00` and rate-5 `10.50/11.00`; and for the full refund, sale `+(12.00, 2.00, 14.00)` vs. corrected refund branch `−(10.00, 2.00, 12.00)` (`zReportService.ts:867-874`) = `net +2.00 / vat 0.00 / gross +2.00`. Both match the executor's claimed reds **to the cent**, so both M3 cases would have been red pre-fix. The executor's correction of the ticket/brief wording ("+2.00 VAT" → gross, VAT cancels) is **correct**.
- Required M3 coverage is present and non-vacuous: three consumers × {single-rate, mixed-rate, net-to-zero}, signed X payload asserted (`:454-459`), SESSION_CLOSE/Z byte-identity asserted on the **real** captured close input (`:606-623`), value assertions only (rule 17), no i18n surface (no locale file in the diff).
- Fixture correction is a genuine correction, not a weakening: `endOfDayPreview.test.ts` `:37 '8.40'→'10.00'`, `:50 '16.81'→'20.00'` with expectations at `:96-98` (`25.21/4.79/30.00`) **unchanged**, and both arithmetics reconcile.

## Findings

1. **P2 · CONFIRMED · `docs/sessions/codex-z-sale-branch-decomposition-report.md:327-330`** — the handback report's "Deviations and concerns" §4 is stale and now **materially false**: it states *"The whole branch diff is docs-only: …progress.yaml and …reviews/**"* and *"no refund branch changed."* At HEAD the branch touches three production files (`zReportService.ts`, `endOfDayPreview.ts`, `reportApi.ts`) plus two test files, and the refund side did receive one edit (the struck comment in `endOfDayPreview.ts`, M2 gate-r1 finding 1). **Failure scenario:** this report is the named deliverable the parent reads to close LEDGER C-2; a merge decision taken on §4 would conclude the lane shipped no production change at all — the exact opposite of its purpose. The M2 gate already raised report staleness (finding 2) and the closure claimed the report was "brought current"; this section was not. Close before merge (M4 owns the negatives).

2. **P3 · CONFIRMED · report `:301-310`, YAML `M2.fixture_corrections`** — the R-4 register's *registered-not-corrected* enumeration lists only `endOfDayPreview.test.ts` rows and omits every masking sale fixture in the other files the brief and M0 named: `zReportService.test.ts:123` (`line_total '42.00'` = NET on a 50.00/8.00 receipt — the brief's own §M2 citation), `:452` (`'84.03'` on 100.00/15.97), `reportApi.localTimestamps.test.ts:136,:143`, `zReportService.cashRounding.test.ts:166`. The register's substantive claim ("the only two sale fixtures any decomposition assertion relies on") is **true** — I confirmed `zReportService.test.ts` asserts `vat_breakdown` only at `:1045` (a refund row at rate 0) and the 36-test suite is green. **Failure scenario:** a later change adds a per-rate assertion in `zReportService.test.ts` against the surviving NET fixture and re-derives a wrong expectation, re-establishing the masking pattern this lane exists to kill.

3. **P3 · CONFIRMED · `docs/handoff/progress/z-sale-branch-decomposition.progress.yaml:376-411`** — M3 has no `gates:` entry (M2 has one), and the M3 commit message records only typecheck + Tier-1, not `pnpm lint:ratchet`, which the house rules make binding **every** milestone. No actual regression: I ran both myself (clean / pos held at 84). Record gap only.

4. **P3 · CONFIRMED · same file, `:376-380`** — the M3 milestone record, including the pre-fix red values it reports, was committed in **`0cf1c7835` (M2.2)**, *before* the M3 implementation commit `5b805657c` (verified with `git log -L 376,412`); the M3 commit touches no YAML and `commit: null` is never filled. **Failure scenario:** the "PROVEN MEANINGFUL by reverting" claim cannot be corroborated by commit order — the evidence predates the artifact it describes. It survives here only because I re-derived the numbers independently.

5. **P3 · CONFIRMED · working tree** — `git status --porcelain` at gate time shows uncommitted **M4** content in the progress YAML (`whole_branch_file_list`, `negatives_proven`, `r6_device_build_sequencing`). This is the same class the M2 gate raised as finding 4 ("the tree was dirty with in-flight M3 work while M2 was under review") and declared cured. No code is involved, so the reviewed range is unaffected.

6. **P3 · CONFIRMED · `zReportService.ts:927-932`, `endOfDayPreview.ts:303-315`, `reportApi.ts:496-505`** — R-3's explicit currency-scale arguments have **no test coverage**. Every case in the lane uses EUR (scale 2) with exact 2-dp values and one line per rate, and every emission re-formats through `bcformat(totals.*, decimals)` (`zReportService.ts:989-991` and equivalents), so reverting the scale args to `decimal.ts`'s default of 3 leaves every assertion green. **Failure scenario:** a future edit drops the scale argument (or a scale-0/1 currency arrives) and the latent drift R-3 was added to close returns undetected. Not one of M3's enumerated required tests — note, don't block.

7. **P3 · PLAUSIBLE · `saleReportingEndToEnd.test.ts:299-303` vs `zReportService.ts:924`** — the rate bucket key is the **raw `tax_rate` string**. The net-to-zero test hard-codes the refund line's rate from the same `TAX_RATE` constant as the sale instead of reading it back from the persisted sale row, so it cannot detect a `'20'` vs `'20.00'` mismatch that would leave two non-cancelling buckets. Production almost certainly derives the return line's rate from the original row, and the keying is pre-existing behaviour shared with the already-accepted refund branch — hence PLAUSIBLE, not confirmed.

8. **P3 · CONFIRMED · `saleReportingEndToEnd.test.ts:490-495`** — the mixed-rate EOD leg asserts `net_amount` / `vat_amount` only; `gross_amount` is unasserted for both rates, while the Z and X legs assert all three via `toEqual`. The gross accumulator is one of the three lines the fix changed.

## Bypasses I tried that FAILED (i.e. the implementation held)

- **"R-5/rule-20 violated — raw ISO `SHIFT_OPENED_AT` bound into SQL."** Failed: all three entry points normalize internally (`zReportService.ts:194`, `endOfDayPreview.ts:185`, `reportApi.ts:416`) and their documented parameter contract *is* ISO 8601.
- **"Consumer 3 isn't really exercised — `generateXReport` hits the server."** Failed: `opts.fiscalSessionId` short-circuits to `generateLocalXReport` (`reportApi.ts:152-154`).
- **"Net-to-zero passes because the refund row was dropped."** Failed: `sales_count 1`, `refunds_count 1`, `refunds_amount '12.00'` asserted alongside (`:531-533`).
- **"The surviving `42.00` masking fixture must now be red."** Failed: no per-rate assertion depends on it; suite green (36 tests).
- **"The EOD fixture edit weakened its assertion."** Failed: expectations unchanged, arithmetic reconciles both ways.
- **"The refund fixture is hand-authored, so the cancellation is an artifact."** Failed: it is derived from `VAT`/`GROSS`, which are guarded against the cart's own `computeTaxAmount()` (`:204-209`), and it mirrors `refundReportingEndToEnd.test.ts:163-170` verbatim in shape.
- **"The handback report is missing."** Failed: it exists (untracked — `docs/sessions/` is gitignored).
- **"+244 test lines grew the lint ratchet."** Failed: @autoerp/pos held at 84.

**Disposition:** 0 P1. The one P2 is an artifact-truth defect in the handback, not a code defect, and per the brief's gate rule P2 is close-before-merge rather than milestone-blocking — M4 (whole-lane negatives) is precisely where it must be closed.

VERDICT: ACCEPT
