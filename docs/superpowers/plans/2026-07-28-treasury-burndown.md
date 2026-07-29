# Treasury follow-up burn-down — plan (2026-07-28)

Base: `origin/dev` `32da5bd9d`, branch `chore/treasury-burndown`, worktree `../erp.treasury-burndown`.
Items: the non-blocking findings ticketed across Treasury Phase ⑤ exit reviews (treasury r6,
frontend r8, Fable exit r3) and the 2026-07-27 dev-merge reconciliation reviews.

## Global constraints (binding for every task)

1. **Fiscal-payload freeze:** `feat/pos-cash-rounding` owns SALE_RECEIPT v3. Do NOT touch
   `PosCoreReceiptProjection`, the fiscal payload registry, `StrictCanonicalParser`,
   `CanonicalPayloadReader`, `BestEffortPayloadParser`, golden vectors, fiscal drift gates,
   or `TreasuryReceiptBridge`. If a task appears to require it, STOP and report BLOCKED.
2. **Precision contract (CLAUDE.md rule 19):** no float ever touches money/quantity.
   Backend `CurrencyScale::bcformatStrict` + injected `CurrencyScaleResolverInterface`
   (constructor injection only, never `app()`). Frontend: decimal strings, `<MoneyInput>`,
   `formatCurrency`; never `parseFloat`/`Number()` on money.
3. **Module boundaries:** cross-module access only via `Shared/Contracts/` interfaces,
   events, or a module's public service.
4. **TDD:** failing test first. Backend tests run BY PATH (`php artisan test <paths>`);
   NEVER the full PHPUnit suite. Frontend: Vitest by path.
5. **Quality gates per task:** `./vendor/bin/pint --test <touched paths>`,
   `./vendor/bin/phpstan analyse <touched module paths> --no-progress`, and for web
   `pnpm typecheck` + focused vitest. Zero new ratchet findings (tenant-key,
   design-system, quantity audits run in full `pnpm lint`).
6. **i18n:** all user-facing text via `t()`; keys added to en, fr, AND ar.
7. No new migrations, no permission-catalog changes, no API contract changes in this
   program. If a task seems to need one, STOP and report BLOCKED.
8. Worktree env: `apps/api/.env` here is a copy of the repo default; backend tests use
   phpunit.xml's sqlite/pgsql config. If phpstan false-errors on model properties,
   report it rather than baseline-ing.

---

## Task 1 — SQL-bounded suggestion candidate loading

**Finding (Fable exit ⑤a→r3, carried):** `StatementSuggestionService` loads movement
candidates for Tier 1/2 matching bounded only by the matching window in PHP; for very
mature repositories it can load far more movement history than necessary. Current
behavior is CORRECT — this is a perf/robustness bound, not a bug fix.

**Requirement:** push the candidate bounds into SQL:
- Restrict the movement candidate query to the line's `value_date` ± the profile's
  `matching_window_days` (the same window the PHP filter uses at
  `StatementSuggestionService` lines ~57-97) so the window is applied in the query,
  not after hydration.
- Add a hard LIMIT sanity cap (e.g. 500, as a class constant with a comment) so a
  pathological window/volume cannot hydrate unbounded rows; if the cap is hit, the
  service must still behave correctly (suggestions are best-effort).
- Tier-2 "unique amount in window" semantics must be PRESERVED — uniqueness is judged
  within the window. Prove with tests: a movement outside the window must neither be
  suggested nor break uniqueness of one inside it.
- Do not change the suggestion DTO shape, tiers, ranking, or reason codes.

**Tests:** extend `tests/Feature/Treasury/StatementMatchingHttpTest.php` (suggestion
endpoint) or a focused new test for the service: (a) in-window movement still suggested
Tier 2; (b) out-of-window same-amount movement does not defeat uniqueness; (c) cap
respected (can be a lighter unit-style assertion on the query/service).

## Task 2 — Mechanical backend trio

**2a. Manual-allocation FormRequest decimal regex ↔ currency scale.**
Finding (⑤b exit, carried): the manual-allocation FormRequest's amount regex allows
more decimals than the repository currency's scale, so excess precision fails deep in
the domain service instead of at validation. Align per CLAUDE.md rule 19: keep
`numeric` and add the money regex ceiling `/^\d+(\.\d{1,3})?$/` — UNSIGNED (amended
2026-07-29 after Codex r1: the allocation domain requires positive amounts —
`matched_amount > 0` DB constraint, `StatementMatchingService` positive-amount guard —
and 3 of 4 sibling Treasury FormRequests use the unsigned pattern; the original `-?`
here was a mis-copy of the generic rule-19 money pattern). Non-breaking. RED test first:
posting `10.1234` to the allocation endpoint must 422 at validation (not
BUSINESS_ERROR from the domain).

**2b. Delete dead legacy reconciliation models.**
Finding (treasury r6): `BankReconciliation.php` / `BankReconciliationItem.php` retained
with zero writers after the ⑤b cutover. Verify zero references (grep app/, tests/,
database/), then delete the models and any factories/seeders exclusively theirs. If a
migration references the tables, leave migrations untouched (history is immutable) —
only remove dead model/factory code.

**2c. Fail-loud regression test.**
Finding (⑤a exit, carried): add a direct regression test for the "linked payment has no
durable issue event" guard — the outbound lifecycle path that must fail loudly (not
proceed) when an instrument's linked payment lacks its durable issue event. Locate the
guard in the outbound lifecycle service (`OutboundInstrumentService`), write a test that
constructs the inconsistent state and asserts the loud failure (exception/422), and
that NO journal entry, movement, or instrument event is written.

## Task 3 — Expense-owned read port for `SyncExpenseOnInstrumentLifecycle`

**Finding (Fable ⑤a exit, downgraded to ticket):** the Expense-module listener
`SyncExpenseOnInstrumentLifecycle` reads the Treasury `PaymentInstrument` model
directly — a module-boundary violation (rule: cross-module only via Shared/Contracts,
events, or public services).

**Requirement:** introduce a narrow read port so Expense no longer imports Treasury
models. Follow the existing pattern used by other cross-module ports in
`app/Shared/Contracts/` (e.g. the fiscal `PaymentMethodResolver`): define the interface
in `Shared/Contracts/Treasury/` exposing exactly the fields the listener needs (inspect
the listener first; likely instrument id/status/amount/expense linkage), implement it in
Treasury (Eloquent-backed), bind in `TreasuryServiceProvider`, constructor-inject into
the listener. Event payloads (`InstrumentCleared`/`InstrumentCancelled`) are immutable —
do NOT restructure events (CLAUDE.md rule 8).

**Tests:** existing expense-projection tests must stay green
(`tests/Feature/Treasury/` + `tests/Feature/Expense/` — find the ones covering
instrument clear/cancel → expense state; run by path). Add one test asserting the
listener works through the port (bind a fake implementation to prove the seam).
PHPStan on both modules must pass.

## Task 4 — Web fixes (formatDate day-shift + r8 M1/M2/M3/M5)

**4a. `lib/format.formatDate` UTC day-shift (treasury r6).** Date-ONLY strings
(`YYYY-MM-DD`) are parsed as UTC midnight then formatted in the local timezone → for
users behind UTC the displayed day shifts back by one. Fix: when the input matches
`/^\d{4}-\d{2}-\d{2}$/`, format it as a calendar date without timezone conversion
(parse into local y/m/d parts). Datetime strings keep existing behavior. This helper is
app-wide: add exhaustive unit tests (date-only, datetime, ISO with offset, invalid) and
run the full formatting-related vitest paths + typecheck. Grep call sites for
regressions in tests that assert current buggy behavior.

**4b. r8 M1 — pluralization by concatenation.** `LinePanel.tsx:92` renders
`{count} {t('statements.workspace.movementsProduced')}`. Switch to
`t(key, { count })` i18n pluralization; add plural forms to en/fr/ar for the key
(follow the `cashWidget` pluralization pattern in `locales/*/treasury.json` — ar needs
zero/one/two/few/many/other).

**4c. r8 M2 — `ManualMatchSearch` bounds + disabled.** `disabled` prop must reach the
search `Input`, the movement `Select`, and the `MoneyInput` (not just the Allocate
button). Fix the contradictory bounds: when nothing is selected/remaining, `maxAmount`
is `0.000` while `minimumAmount` is `0.001` (min > max) — clamp/disable coherently.

**4d. r8 M3 — `MODULE_PERMISSIONS` permission-shaped key.** `usePermissions.ts`
`'bank-statements.view': ['bank-statements.view']` — every other key is a module
identifier. Rename the module key to `'bank-statements'` and update the callers
(`canAccessModule('bank-statements.view')` sites — grep; includes the
`usePermissions.treasuryReconciliation.test.ts` assertion and any Sidebar/route
gating). Behavior identical.

**4e. r8 M5 — unchecked casts in `StatementUploadWizard.tsx` (~lines 225-227).**
Replace the three `as StatementParserKey / StatementDirectionConvention /
StatementDecimalFormat` casts with type guards following the file's own
`isIgnoreReason` pattern (`LinePanel.tsx:37`). This also clears the three
`no-unsafe-type-assertion` warnings.

**Tests:** focused vitest for LinePanel, ManualMatchSearch (add coverage for the
disabled/bounds fix), StatementUploadWizard, usePermissions tests, format tests.
`pnpm typecheck` + full `pnpm lint` (warning count for the three cleared warnings may
drop — never rises).

## Task 5 — Smoke hardening (r8 M4 + r6 STATEMENT_DELTA + reopen-teardown/SMOKE reaper)

File: `apps/web/e2e/smoke/treasury-phase5b-reconciliation.smoke.ts`.

**5a. M4 — selectors coupled to English strings with no locale pinned.** Pin the UI
locale for the smoke run (e.g. set the app language explicitly after login, or pass the
i18n override the app supports — inspect how the app persists language; POS/web use
i18next with localStorage) so English-string locators are used BY CONTRACT, not luck.
Do not rewrite locators to test-ids in this task.

**5b. `STATEMENT_DELTA` (smoke.ts:30) hardcoded** — derive it by summing the row
constants (`ADJUSTMENT_AMOUNT + CARD_NET + CHEQUE_AMOUNT − AGIO_AMOUNT` … derive the
actual signed composition from where the delta is asserted) using decimal-string math
consistent with the file's existing `addMoney` helper — no floats.

**5c. Reopen teardown is now dead** (2026-07-27 review): step 7 reopens the statement,
parking it `Reconciling` forever; the setup's leftover-skip then never reuses that
repository, so every cross-day run provisions a new `SMOKE-*` repo. Replace the
teardown with: unallocate all lines → void the statement (allowed once allocations are
removed; verify against `BankStatementVoidService` rules) → repository returns to the
eligible pool. Keep the reopen ASSERTION (reopen must work — it is part of step 7's
verification) but follow it with the void-based cleanup. Additionally add a
best-effort setup-time reaper: for repositories named `SMOKE-*` with zero active
statements and zero balance, skip silently (do NOT delete via API — no destructive
cleanup in a smoke).

**Verification:** typecheck + eslint on the file. A live DB-backed run is executed by
the orchestrator after review (documented recipe), not by the implementer.

## Task 6 — Drift-guard hardens: committed permissions map freshness

**Finding (2026-07-27 treasury review, Minor):**
`ExportFrontendPermissionsMapCommandTest` proves the exporter is deterministic but
never asserts the COMMITTED `apps/web/src/hooks/permissionsMap.generated.ts` is fresh
vs the seeder — a stale committed map passes CI. Add an assertion: regenerate to a temp
path and diff against the committed file (normalized), failing with a message telling
the developer to run `php artisan permissions:export-frontend-map`. RED first: prove it
fails when the committed file is stale (temporarily perturb in-test via a fixture copy —
do not actually commit a stale map).

## Task 7 — Arabic treasury i18n backfill

**Finding (2026-07-27 FE review M1 + Phase ② owe):** `locales/ar/treasury.json` is ~190
keys behind en/fr in the `instruments.*` / `repositories.*` / `statements.*` namespaces
(ar≈204 vs en/fr≈394 in those namespaces), plus 4 Phase ② AR keys still owed (find them:
diff ar vs en key sets across the treasury namespace; the 4 are in the instruments/banks
area). Backfill EVERY missing key with proper Modern Standard Arabic financial
terminology consistent with the existing ar file's register (e.g. كشف بنكي, مطابقة,
مستودع). Preserve existing translations — additive only. Structural rule from the
2026-07-27 merge: exactly ONE top-level `repositories` block — do not introduce
duplicate keys (validate with a JSON duplicate-key check). RTL: plain strings, no
direction markers needed (the app handles RTL). Verify: JSON valid, key-set parity
`ar ⊇ en` for the treasury namespace, focused i18n/vitest suites green.

## Task 8 — Scoping: productize Phase ② staging backfills as artisan commands

**Research-first task.** Phase ② ran one-off brownfield backfill SQL on staging (banks
backfill, chart seeding) that was never productized. Search `docs/handoff/`
(`treasury-phase2-deploy-checklist.md`, `bank-directory-deploy-checklist.md`),
`docs/sessions/`, and git history for the actual SQL/tinker steps. IF the source
material is recoverable: write guarded, idempotent, dry-run-capable artisan commands
following `treasury:backfill-payable-instrument-accounts` as the pattern (fail-loud
logs, tenant-aware). IF NOT recoverable: report BLOCKED with what you found — do not
invent the backfill semantics.

---

## Deferred (NOT in this program — do not touch)

- `payment_methods_hash` reprojection drift (fiscal projection surface — frozen).
- Anything in `TreasuryReceiptBridge` / fiscal projections (cash-rounding owns it).

## Execution order

1 → 2 → 3 (backend, disjoint files but sequential per SDD) → 4 → 5 → 6 (web/tooling)
→ 7 (i18n) → 8 (scoping). Every task: fresh Opus implementer, Codex adversarial review
saved to `docs/superpowers/reviews/2026-07-28-burndown-task<N>-codex.md`, fix loops
until spec ✅ + quality approved. Final whole-branch review before merge.
