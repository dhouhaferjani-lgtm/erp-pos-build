# Gate 5 Result + Remediation Brief — design-system unification

> Gate 5 review of 26f0d278a (gate-4 fixes) + a39a83545 (Wave 5 leg 3), 2026-07-11. Three lanes.
> **Overall verdict: REJECT — leg 3 must be remediated before Gate 6.**
> Gate-4 fix commit: APPROVE-WITH-FIXES (8/8 addressed; two small carries below). Baseline replay: HONEST for the 6th time (423 −247 +7 re-fingerprints = 183; residual-183 composition fully mapped). Evidence: all counts reproduce EXCEPT a real test regression Codex's narrower suite dodged (below).
> **Why the REJECT matters:** the design audit (183, 0 new), lint (0 errors), typecheck, and 499 green tests were ALL blind to this defect. The metric improved partly BY the mechanism that broke the UI. Read BL-1 carefully before touching anything.

## BLOCKERS

**BL-1 — Interpolated variant prefixes produce dead CSS (357 occurrences, 64 files, branch-wide).**
The leg-3 sweep pervasively wrote `` `hover:${colorTokens...}` ``, `` `focus:${...}` ``, `` `group-hover:${...}` ``, `` `disabled:${...}` ``, `` `placeholder:${...}` ``, `` `dark:${...}` ``. Tailwind v4 (CSS-first; this repo has NO safelist, NO tailwind.config, NO @source) only emits a variant utility when the fully-composed literal (`hover:bg-gray-50`) appears verbatim somewhere in scanned source. A variant prefix glued onto an interpolated value never produces that literal → the rule is never generated.
- **42 class-applications are dead RIGHT NOW**, including: `hover:hover:bg-blue-700` double-prefix (21 sites — `bgStrongHover` ALREADY contains `hover:`; every primary button in batches/categories/dashboard lost hover darkening), the POS product-search input's focus ring/border/placeholder/clear-hover (`ProductGrid.tsx:144-157`), auth submit-icon `group-hover` (Login/Forgot/Reset), `disabled:` states in batches/expenses, and more (full table in the gate-5 review lane output — reproduce with the grep below).
- **~315 more render only by coincidence**: the identical composed literal happens to exist elsewhere — mostly inside the `@deprecated colorClasses` quarantine table, whose deletion is the planned burn-down. Deleting it would break dozens of swept features. Not conservation — a time bomb.

**BL-2 — Interpolated opacity modifiers, same defect (7 occurrences).** `` `${token}/75` `` etc. Worst: `categories/CategoriesPage` modal backdrop `bg-gray-500/75` → **fully transparent backdrop**; also auth RegisterBrandPanel `/20`, VerticalCard double-dead hover, pos HeldOrderCard near-expiry highlight `/50`, ProductEditHero `/20`, expenses dark deletes `/20` ×2.

**Fix directive for BL-1/BL-2 (whole BRANCH, not just leg 3 — the pattern leaked into earlier-swept files too, e.g. pos/ActiveOrdersBoard):**
1. Find every site: `rg -n '(hover|focus|focus-within|focus-visible|group-hover|disabled|placeholder|active|dark|file):\$\{' apps/web/src` and `rg -n '\$\{[^}]+\}/\d' apps/web/src`.
2. Rewrite each so the composed class is STATICALLY present: add variant-carrying entries to `semanticColorTokens` (e.g. `bgHover: 'hover:bg-gray-50'`, `ringFocus: 'focus:ring-blue-500'`, opacity variants as complete literals like `backdrop: 'bg-gray-500/75'`) and reference them WITHOUT any prefix. Fix the 21 double-`hover:` sites the same way (drop the redundant prefix). designTokens.ts is the sanctioned literal home — full classes with variants/opacity live THERE.
3. **Add the lint guard so this can never recur** (the existing color rule only sees complete literals): a `no-restricted-syntax` ERROR (global, all of src/) banning TemplateElement raw text matching `(hover|focus|focus-within|focus-visible|group-hover|disabled|placeholder|active|dark|file):$` immediately before `${`, and `}/\d` immediately after an interpolation, in className contexts. Verify with throwaway probes (both patterns) that it fires, then delete the probes.
4. Acceptance: both rg patterns return ZERO on apps/web/src; the two probe patterns error under lint; design audit still 0 new/0 stale.

**BL-3 — 3 failing tests shipped: `VatPeriodStatusBadge.test.tsx`.** Leg 3 migrated the component to shared `StatusBadge`/`statusTone` (renders `bg-green-50 text-green-700`) but never updated the test (asserts old `bg-*-100 text-*-800`). `pnpm vitest run src/features/vouchers src/features/vat-reporting src/features/stock-transfers` = 3 failed / 96 passed. The reported "31 passed" suite was narrower and dodged it. Fix: update the test to assert rendered semantics/tone (CLAUDE.md rule 17 — never assert raw class strings), and from now on the evidence set must include full per-directory path runs for every swept dir, not a hand-picked subset.

## MAJORS

- **M1** — Badge consolidations verified line-by-line only for `vouchers/StatusBadge` (clean: 5/5 statuses, i18n intact, tone shifts documented). NOT individually verified: `pos/OrderStatusBadge`, `pos/TableStatusBadge`, `pos/StockBadge`, `batches/BatchStatusBadge`. Verify each (status-count/tone/i18n parity), and fix any other class-asserting tests of theirs (same rule-17 trap as BL-3).
- **M2** — Any leg-3 RHF conversions (parapharmacy form pages, settings/UserEditModal, coupons/promotions forms, catalog form pages) were NOT payload-identity-verified by review. For each converted form: diff the submit payload construction old vs new and add/extend a payload assertion test.
- **M3** — `pos/AdvancedPaymentsModal`: unlisted tone drift `text-yellow-600`→`text-yellow-800` (restore or list in progress doc); fix the malformed import (`colors , semanticColorTokens as colorTokens`) and the stray dead `cn(' text-2xl ...')`.

## MINORS (carry-overs, one commit)

- Rename the 3 ladder-violating suffixes in semanticColorTokens (`warning.border`=500 vs ladder 300, `neutral.text`=500 vs 600, `warning/ledger.textSubtle`=400 vs 500) — currently zero live consumers, so pure renames.
- Prune/generalize the 4 surviving single-use micro-variants (`bgSubtleAlphaMuted`, `fileBgSoftHover`, `fileTextStrong`, `groupBgHoverSoft`).
- Commit the gate-review harness (`docs/handoff/design-sweep-gate-review-prompt.md`, `scripts/design-sweep-gate.sh`) and this brief.

## AUTONOMOUS LOOP — pilot starts now (owner-approved)

After the remediation commits, DO NOT stop and wait for the owner. Instead:
1. Run `scripts/design-sweep-gate.sh gate5-remediation a39a83545` (fresh headless Opus reviewer, no shared context; review lands in `docs/handoff/gate-reviews/`). Non-APPROVE → read the review, fix, commit, re-run. **Two consecutive non-APPROVE rounds → STOP and escalate to the owner.**
2. On APPROVE: proceed to **Gate 6 scope**: `src/components/` (~545 color occurrences incl. 26 template hits), `src/pages/` (~30), `src/lib` residue, test/story-file color tail in swept dirs (~120), `src/utils/statusMapper.ts` (12), Wave 6 dedup/orphans (ProductSelector→ProductPicker; location/locations family; CategorySelect vs CategorySelector; UserPicker vs UserSelector; delete orphans DocumentLineVariantSelector/ProductDetailVariantPicker/TerminalSelector; move ui/ pickers to molecules/pickers; StickyFormFooter index.ts; dead `default_tax_configuration_id` family), and the deferred full-template PDF render test. Same per-directory protocol; same BL-1 rules.
3. Run `scripts/design-sweep-gate.sh gate6-final <base-commit-of-gate6-work>` and iterate the same way.
4. On APPROVE: STOP. Remaining items are owner-side (visual device pass incl. IziPOS radius + tone shifts; PageHeader adoption decision for the 23 deferred headers; merge/promotion).

Standing rules unchanged: never launder the baseline; log every deferral/visual change; targeted tests BY FULL DIRECTORY PATH per swept dir; pixel-conservative; no push/merge ever.
