# I-1 onboarding campaign — adversarial gate r2 (frontend / e2e conventions lens)

- **Target:** commit `43a8993f9` on `feat/i1-onboarding-campaign` (fix round for r1), worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/i1-campaign`. Fix diff `008c2a199..43a8993f9` = 6 files, +101/−21. Interim commit `264474cab` (the uncommitted `test.setTimeout` r1 flagged) is now committed and in scope.
- **r1 record:** `docs/superpowers/reviews/2026-08-29-i1-campaign-gate-r1-frontend.md` (REJECT — 3 BLOCKER, 4 MAJOR, 8 MINOR).
- **Reviewer:** Opus adversarial frontend-conventions gate. Reviewed 2026-08-29 22:10–22:45.
- **Verdict: APPROVE-WITH-FIXES** — all three r1 BLOCKERS are resolved and independently re-measured. Two MAJORs remain open (one deferred-with-doc, one only half-reworded), plus 8 new/carried MINORs. Merge condition C-0 below is not optional.

---

## ⚠ Tree drift DURING this review — read before acting on the verdict

The worktree HEAD moved off the gate target while I was measuring. Reflog:

```
43a8993f9  22:14:49  fix(i1): gate r1 fix round …            ← THIS REVIEW'S TARGET
dc0ba1ad0  22:32:43  Merge branch 'dev' into feat/i1-onboarding-campaign
d1eb28426  22:34:38  fix(i1): gate r2 (fiscal) N-1..N-5 …
42fdde50d  22:36:15  (amend of the above)                    ← CURRENT HEAD, NOT GATED HERE
```

Every citation below was re-anchored to the target with `git show 43a8993f9:<path>` after the drift was detected; three earlier file reads that had picked up the successor commit were discarded and redone. My guardrail runs land as follows:

| Measurement | Wall clock | HEAD at that moment |
|---|---|---|
| `eslint e2e/campaign playwright.campaign.config.ts` | 22:18 | `43a8993f9` ✔ target |
| `pnpm lint:eslint` (worktree) | 22:23 | `43a8993f9` ✔ target |
| `pnpm lint:eslint` (main repo, `dev` = `2cfeed2e8`) | 22:29 | dev ✔ |
| `pnpm typecheck:e2e` + `pnpm typecheck` | ~22:30 | `43a8993f9` ✔ target |
| `audit:keys` `audit:design-system` `audit:quantity` `audit:i18n:local` `test:eslint-rules` `test:tools` | 22:35:58 | **`42fdde50d`** — successor, not the target |

**C-0 (merge mechanics, blocking):** what is on the branch tip tonight is `42fdde50d`, which I did **not** gate. It is a dev merge plus a 21-line campaign delta answering a *different* reviewer's (fiscal) r2. Either merge exactly `43a8993f9`, or run an r3 delta pass on `dc0ba1ad0..42fdde50d` first. See "Successor commit" at the end — it materially changes three of my findings, and it is where the "staging use is gated" statement the dispatch brief assumes actually lives (it is **not** in the gated commit).

---

## Verification actually run (re-run by the reviewer, nothing reported accepted)

| Check | Command | Result |
|---|---|---|
| campaign lint (r1 exact command) | `cd apps/web && pnpm exec eslint e2e/campaign playwright.campaign.config.ts` | **exit 0, zero output — 0 errors / 0 warnings** (r1: 0/142) |
| campaign files really linted | `pnpm exec eslint . -f json` filtered on `/e2e/campaign/` | **6 files linted, 0 warnings, 0 errors** — not silently ignored |
| repo-wide count, worktree @ target | `pnpm lint:eslint` | **6458 problems (0 errors, 6458 warnings)** |
| repo-wide count, `dev` @ `2cfeed2e8` | `pnpm lint:eslint` | **6458 problems (0 errors, 6458 warnings)** → **delta = 0** |
| override leakage into `src` | `pnpm exec eslint --print-config src/main.tsx` | all 9 disabled rules still **`warn` (level 1)** for `src/**` — no leak |
| e2e typecheck | `pnpm typecheck:e2e` | **exit 0** |
| src typecheck | `pnpm typecheck` | **exit 0** |
| remaining lint guardrails | `audit:keys && audit:design-system && audit:quantity && audit:i18n:local && test:eslint-rules && test:tools` | **exit 0; 8 files / 160 tests passed** (ran at `42fdde50d` — see drift table) |
| baseline honesty | `git diff dev...HEAD -- scripts/lint-warning-baseline.json apps/web/tools/audit-design-system-baseline.json` | **empty — no baseline touched**, no `--update-baseline` |
| suppression audit | `grep -rn "eslint-disable\|@ts-ignore\|@ts-expect-error\|ts-nocheck" apps/web/e2e/campaign/` | **one** hit, pre-existing and justified (`onboarding.campaign.ts:63`, `no-empty-pattern` on the `afterEach`) |
| per-rule cost of the override | `eslint e2e/campaign --rule '{"@typescript-eslint/<r>":"warn"}'` ×9 | dot-notation 103 · require-await 11 · restrict-template-expressions 11 · no-unsafe-type-assertion 7 · prefer-nullish-coalescing 5 · array-type 4 · no-unnecessary-condition 4 · no-base-to-string 2 · prefer-regexp-exec 1 |
| backend claim behind B-3 | read `PaymentRepositoryController.php:34-47` / `:50-66` / `:419-441`, `LocationController.php:81-92` | **confirmed** — `index()` has no `company_id` predicate, `show()` does, `formatRepository()` emits no `company_id`, `/locations` **is** company-scoped |
| live run | `apps/web/test-results/campaign-20260829213028-24693/ledger.json` (runId `20260829213028-24693`, tenant `01a04f6e-82a1-71f0-a329-8366643219f7`), started 21:30:30Z, files written 21:31–21:33Z → **ran against target code** | L0a PASS · L0 FAIL (leak finding) · L1 PASS · L2 FAIL (unit_id) · L3 PASS · L4 PASS · **L5–L8, L10 PENDING, `finishedAt: null`** |

---

## r1 findings — disposition

### BLOCKERS

#### B-1 — CI lint-warning ratchet (+142) → **RESOLVED**
`apps/web/eslint.config.js:417-431` adds one scoped override block for `files: ['e2e/campaign/**/*.ts']` disabling 9 rules. Measured, not reported: scoped run **0 errors / 0 warnings**; repo-wide worktree **6458** vs `dev` **6458** → **delta exactly 0**, and all 6 campaign files are confirmed *linted* (not ignored) contributing 0. `scripts/lint-warning-baseline.json` is untouched — the r1 instruction not to regenerate it was honoured, and nothing in the diff absorbs its own violations.

Leakage check as requested: `--print-config src/main.tsx` shows all 9 rules still at level 1 for `src/**`; the pattern `e2e/campaign/**/*.ts` cannot match `src/**`, and the only later block (`eslint.config.js:432-438`) targets `playwright.campaign.config.ts`. **The override cannot leak to `src/**`.**

Residual, not this lane's: `dev` itself is **6458 vs a 6449 baseline**, so `pnpm lint:ratchet` (`scripts/lint-ratchet.mjs:117-120`, "warnings rose") fails on `dev` *today*, identically with or without this branch. Merging I-1 neither causes nor fixes that red. It needs a separate owner and must **not** be absorbed by this lane. → routed as O-row, not a finding against I-1.

#### B-2 — false product finding (`location_id === null` on safes) → **RESOLVED**
`onboarding.campaign.ts:198` is now `second.repositories.filter((r) => r['type'] === 'safe')`; the null-location clause is gone. Confirmed empirically, not by reading: the post-fix ledger records the *positive* branch —
`company 2 repositories: 1 cash register on 01a04f6f-44d3-7208-ace4-940f24759abf + 1 safe` — and the census line shows company 2 owning `cash_register:CASH-01@…44d3…, safe:SAFE-01@…44d3…` on its own location. The "Second company is not provisioned…" finding no longer appears anywhere in `ledger.findings`. The gate is no longer red on a non-bug.

#### B-3 — tenant-wide list claimed as company-level → **RESOLVED (with two residuals, N-4)**
`census()` at `onboarding.campaign.ts:682-712` now (a) builds `ownLocationIds` from the company-scoped `/locations` response, (b) records the **real** defect as a product finding — *"GET /payment-repositories is tenant-scoped: company B sees company A's drawers and safes (index() lacks the company_id filter show() has)"* @ `apps/api PaymentRepositoryController::index()` — and (c) filters the census to the company's own rows. I verified the backend claim rather than trusting the comment: `PaymentRepositoryController.php:38-46` selects `where('tenant_id', …)` only, `:56-61` adds `->where('company_id', $companyId)` with the *"Treasury is company-scoped (api.treasury.067)"* comment, and `formatRepository()` at `:419-441` emits no `company_id` — so location-based scoping really is the only client-side option. `LocationController::index()` (`LocationController.php:81-92`) **is** `where('company_id', $companyId)`, so the scoping key is sound. The finding is recorded once (`:713 reportedRepositoryLeak`) and the live ledger shows it firing exactly once, on the company-2 census.

The campaign now reports the real second-of-everything defect it walked past in r1. `dev` `2cfeed2e8` already carries it as LEDGER D-J0-8 (P1, micro-lane) — the routing r1 asked for exists.

### MAJOR

#### M-1 — reuse mode dead on arrival → **PARTIAL (code NOT-ADDRESSED; vars documented)**
Ordering, checked exactly as asked: `assertDayOneCensus(first)` is at `onboarding.campaign.ts:159`, the reuse return is at `:176`. **The census still runs BEFORE the return, unguarded** (`:726` `expect(value.repositories, …).toHaveLength(2)` with no `reuseMode` condition at this commit). What changed is that the census is now company-scoped, so a reused tenant no longer trips on company 2's rows.

It is still dead for the tenants it exists to triage: **L4 creates a `bank_account` repository with no `location_id`** (`:405-411`), and the census's own keep-predicate (`:711-712`) treats `typeof location_id !== 'string'` as *own* — so any tenant past L4 censuses **3** repositories against `toHaveLength(2)` and L0 fails. `test.describe.configure({ mode: 'serial' })` (`:56`) then skips L1–L10. Reuse works only for a tenant that never reached L4, i.e. exactly the window in which you would not need it. No reuse run was performed; r1 asked for one.

Documentation half of M-1 is done: `README.md:22` and `docs/qa/ONBOARDING-CAMPAIGN.md:55-57` now name both env vars. **Fix:** guard the repositories count on `reuseMode` (or attribute the L4 bank repository to the location) and prove it with one reuse run against a post-L4 tenant. *(The successor commit does both — see below.)*

#### M-2 — `CAMPAIGN_KEEP_TENANT` wording / tenant-database leak → **PARTIAL**
The new bullet at `docs/qa/ONBOARDING-CAMPAIGN.md:52` is exactly right ("There is **no teardown**… `CAMPAIGN_KEEP_TENANT=1` only *prints*… operator task… Do not arm the push→dev trigger on a target you cannot clean"), and `README.md:22` repeats it. But r1's directive was "reword **both** docs", and the misleading sentences r1 quoted are **still present at this commit**:
- `README.md:11` — "Set `CAMPAIGN_KEEP_TENANT=1` to **retain** and print…" — contradicts `README.md:22` eleven lines below, in the same file.
- `docs/qa/ONBOARDING-CAMPAIGN.md:22` — "…`CAMPAIGN_KEEP_TENANT=1 scripts/campaign-onboarding.sh` **retains** and prints the generated credentials…" — contradicts `:52`.
- `README.md:13` — "Every run creates a unique tenant and **never reads or mutates an existing one**" — now factually **false**: reuse mode logs into and mutates an existing tenant. Adding a feature without correcting the sentence it falsifies is the owner's *"copy must not overstate/misstate system behaviour"* rule, applied to operator docs.

**Fix:** delete "retain and" from `README.md:11`, rewrite `docs/qa:22`'s last sentence, and qualify `README.md:13`. *(The successor commit fixes all three.)*

#### M-3 — `--country` advertised, only TN works → **DEFERRED-WITH-DOC (partial)**
`docs/qa/ONBOARDING-CAMPAIGN.md:50` now states it plainly: fixtures, tax number, VAT 19.00, `Africa/Tunis` and the GL pins are **"Tunisia-only tonight; `--country` is reserved and any other value is unsupported"**. That is an honest deferral and satisfies the disclosure half.

Neither enforcement branch of r1's directive was taken: `journey.ts:145-164` still maps 7 countries and accepts any value, and `.github/workflows/onboarding-campaign.yml:16-19` + `:61` still expose a `country` dispatch input defaulting to TN with no guard. `README.md:7` still presents `--country TN` as an override knob with no caveat. A `workflow_dispatch` with `country: FR` still runs and fails as a fake product bug — the exact anti-signal M-3 was about, now only mitigated by a doc a dispatcher may not read. **Fix (cheap, 3 lines):** throw in `campaignCountry()` when `CAMPAIGN_COUNTRY !== 'TN'`, and drop the workflow input.

#### M-4 — operator doc presents a red-by-construction gate as pass/fail → **PARTIAL**
The "Known red (as of 2026-08-29)" section (`docs/qa/ONBOARDING-CAMPAIGN.md:59-71`) exists, with a 5-row owner table, and the "Preconditions and limits" section (`:46-53`) tells the reader how to read a red. Cross-checked against the live ledger: the two findings actually recorded (leak @ L0, `unit_id` @ L2) are both in the table, the P0 row is annotated "fixed on dev `5656c9899`", and B-2's false finding is **not** documented into the list. That is the honest shape r1 asked for.

Two pieces of the directive are missing at this commit:
- r1 asked to "state that `ONBOARDING_CAMPAIGN_ON_PUSH` must stay unset until the list is empty". **No such statement exists at `43a8993f9`.** The dispatch premise that "staging use is explicitly gated on the deferred items" is therefore **not true of the commit I gated** — it is true only of the successor.
- `docs/qa/ONBOARDING-CAMPAIGN.md:3` still reads, unqualified, *"A red campaign blocks promotion; the orchestrator links this document from the applicable `docs/handoff/PROMOTION-CHECKLIST-*` row."* Read literally against a gate that is red by construction (L10, `onboarding.campaign.ts:673-679`, fails on **any** finding), and against CLAUDE.md rule 22's "a **green** run … is a promotion precondition", promotion is blocked forever. **Fix:** amend `:3` to "…blocks promotion **once the Known-red list is empty**; until then compare each finding against that table and block only on a finding not in it."
- `:67` credits the leak finding to *"Treasury — owner routing owed"*; it **is** routed, on `dev` `2cfeed2e8` as LEDGER D-J0-8. Stale on arrival.

### MINOR

| # | r1 finding | Status @ `43a8993f9` |
|---|---|---|
| 1 | Dead code ×4 | **PARTIAL** — `journalHasAccountCode` and `deepContains` deleted (confirmed absent). `selectors.ts:75 labels.passwordConfirmation` and `journey.ts:590 booleanField` (exported, still zero consumers) **remain**; neither is ESLint-visible, so the 0/0 lint does not cover them. |
| 2 | `e2e/tsconfig.json` relaxes 5 strictness flags | **NOT-ADDRESSED** — file byte-identical, `include` still `["**/*.ts"]` with all five overrides. r1 proved the campaign's own code is clean under the full strict set once `include` is narrowed. *(Fixed in the successor.)* |
| 3 | Quantities compared with the scale-3 money helper | **NOT-ADDRESSED** — `journey.ts:8 MONEY_SCALE = 3`; `assertMoneyEqual` still applied to quantities at `onboarding.campaign.ts:338,342,379,381,523,527,592`, and the tell-tale workaround `defaultLotQuantity(...) === '19.0000' \|\| === '19.000'` survives verbatim at `:525`. A genuine scale-4 stock drift still surfaces as a thrown "exceeds scale 3" inside a `pollUntil` predicate. CLAUDE.md rule 19: quantity is `decimal(N,4)`. |
| 4 | `async` envelope builders with no `await` | **NOT-ADDRESSED — and now suppressed.** `fiscal/events.ts:61` and `:120` unchanged; the new override disables `@typescript-eslint/require-await` for `e2e/campaign/**`, which is the rule that flagged it (11 hits). The finding was silenced, not fixed. |
| 5 | `campaign-onboarding.sh` `set -u` only + relative `apps/web` | **NOT-ADDRESSED** — `:3 set -u`, `:50 set +e`, `:53 set -e`, `:51 pnpm --dir apps/web`; no `cd "$(dirname "$0")/.."`. |
| 6 | Unused `PNPM_VERSION` | **NOT-ADDRESSED** — `.github/workflows/onboarding-campaign.yml:29`. |
| 7 | Hardcoded `'AutoERP Campaign SARL'` | **NOT-ADDRESSED** — `fiscal/events.ts:286`. Owner rule OQ-1: no baked brand string; this one is signed into a fiscal envelope, so it can surface in a receipt screenshot. Prefer `'Campaign SARL'`. |
| 8 | `beforeEach` destructures `page` for network-free legs | **NOT-ADDRESSED** — `onboarding.campaign.ts:72` still `async ({ page }, testInfo)`; Playwright instantiates the fixture before the `:74` early return, so L0a and L10 still spin up a browser context they never touch. |

---

## New findings (r2)

### N-1 (MAJOR) — the fix round's own new assertions have never executed
The only post-fix run (`apps/web/test-results/campaign-20260829213028-24693/ledger.json`) terminated after L4: **L5, L6, L7, L8 and L10 are `PENDING` and `finishedAt` is `null`**. The two assertions this commit adds beyond L4 —
- `onboarding.campaign.ts:553-554` drawer-after-sale pinned to `1023.800`, and
- `:626-627` `trial balance after sale + refund` `is_balanced === true`

— have therefore **never run**, and L10 was never evaluated against the corrected finding set. r1's re-gate condition was "one **full** run whose ledger shows L0 with no repositories finding"; the L0 half is met (B-2 confirmed by ledger evidence), the *full* half is not.

Mitigating, and why this is MAJOR and not BLOCKER: the `1023.800` pin is arithmetically forced by data r1 already verified — L4 sets the drawer to `1000.000`, L6 posts cash `Dr 23.800`, and the pre-existing `:625` post-refund pin of `1000.000` passed in r1's run-18 ledger, which is only possible if both the sale and the refund moved the drawer. The trial-balance call reuses the endpoint L4 exercised and passed. Risk is a self-inflicted red on an already-known-red, un-armed gate, not a wrong assertion. **Fix:** one full local run before the campaign is used as anyone's promotion signal; attach the ledger to the lane record.

### N-2 (MINOR) — stale `KNOWN GAP I2-F1` comment contradicted by the lane's own evidence
`onboarding.campaign.ts:202-207` still asserts *"CompanyController::store() never calls PaymentRepositorySeeder, so a second company cannot take cash"*. The lane's own ledger disproves it (company 2 got `CASH-01` + `SAFE-01` on its own location), and `dev` landed G-3c (`PaymentRepositoryProvisioningService`, `CompanyPaymentRepositoryProvisionerInterface`, `backfill_company_payment_repositories`). A comment describing a fixed gap as current is how the next reader re-opens a closed bug. **Fix:** reword to "kept as a regression guard — G-3c fixed this on dev `9badbe294`". *(The successor updates `docs/glossary.md` for this but not the code comment.)*

### N-3 (MINOR) — `census()` keeps location-less foreign rows, and mis-scopes for a restricted user
Two residuals of the B-3 fix, both in `onboarding.campaign.ts:707-712`:
1. The keep-predicate is `typeof location_id !== 'string' || ownLocationIds.has(location_id)` — a **company-level row with no location** (a bank account, a safe not attributed to a location) belonging to company A is counted as company B's. The campaign creates exactly such a row itself at `:405-411`. So the scoping is location-attributed-rows-only, which the comment does not say.
2. `/locations` is not merely company-scoped, it is **also user-location-scoped** (`LocationController.php:88` `whereIn('id', $this->scopeResolver->resolve($user))`). Under a user with restricted location access, own repositories at unseen locations are classified `foreign` → the campaign emits a **false** tenant-leak finding. Harmless for the fresh-tenant owner user; a trap the moment reuse mode is pointed at a real staging tenant.
**Fix:** state both limits in the comment, and gate the leak finding on `reuseMode === false` (or on the census being the owner's).

### N-4 (MINOR) — the lint override is broader than its own justification, and absorbs r1 MINOR-4
`eslint.config.js:418-419` justifies the block as *"Campaign e2e code walks untyped API payloads (`Record<string, unknown>`) by design; these src-oriented style rules would only add noise."* Measured per rule, that justification covers 123 of 148 warnings (dot-notation 103, restrict-template-expressions 11, no-unsafe-type-assertion 7, no-base-to-string 2). The other **25 have nothing to do with untyped payloads**: `require-await` 11, `prefer-nullish-coalescing` 5, `array-type` 4, `no-unnecessary-condition` 4, `prefer-regexp-exec` 1 — ordinary, individually fixable style hits, one of which (`require-await`) is precisely r1 MINOR-4. This is not the detector-evasion class my protocol rejects (scoped to a test harness, zero effect on `src`, baseline untouched, honest comment) — but it is a suppression that closes a finding by silencing its detector. **Fix:** fix the 25 and drop those 5 rules from the block, or narrow the comment to say plainly that 5 of the 9 are convenience.

### N-5 (MINOR) — `docs/qa/ONBOARDING-CAMPAIGN.md:67` owner column already stale
Says "Treasury — owner routing owed" for the leak finding; `dev` `2cfeed2e8` routes it as LEDGER **D-J0-8** (P1, micro-lane tonight). Point the row at D-J0-8.

### N-6 (MINOR, cross-cutting: `docs/conventions/09-SECOND-OF-EVERYTHING.md`) — the journey gate itself is 2/3 on the triad
Positive: the fix round **adds** the missing re-run leg for partners (`onboarding.campaign.ts:287-288`, `'re-run creates no duplicate partners'` → `toHaveLength(4)`, exercised and green in the live ledger — L1 PASS), joining the existing product re-run check at `:340`. Second **company** is covered (`:177-208`). But the campaign never provisions or asserts a **second location** — every leg runs on `journeyState.locationId`. Since I-1 is the artefact the whole convention leans on as a promotion gate, the missing third of its own triad should be a declared backlog line (lane I-3 or a follow-up), not an unstated gap.

---

## Successor commit `42fdde50d` — observed, NOT gated (bearing on C-0)

`dc0ba1ad0..42fdde50d` is 6 files / +21−9 and demonstrably closes several residuals above. Read, not verified by execution:
- `onboarding.campaign.ts:727` → `if (!reuseMode) expect(value.repositories, …).toHaveLength(2)` and `:409` → the L4 bank repository now carries `location_id` — together these close **M-1**'s functional half.
- `README.md:11,13` and `docs/qa:22` reworded — closes **M-2**.
- `docs/qa:51` adds *"**Push trigger stays INERT.** Do not set `ONBOARDING_CAMPAIGN_ON_PUSH=true` until three items close: fixtures parameterised per country, reuse mode hardened for post-L4 tenants, and a tenant teardown"* — closes **M-4**'s missing sentence and is the only place the dispatch brief's "staging use is gated" claim actually exists.
- `e2e/tsconfig.json` `include` narrowed to `["campaign/**/*.ts", "../playwright.campaign.config.ts"]` — the mechanical half of **MINOR-2**; the five strictness relaxations are still there, so re-run `pnpm typecheck:e2e` and try deleting them.
- `journey.ts:317` register wait 120 s → 300 s, `:326-335` a new P0-2 finding on the time-limit 500, `onboarding.campaign.ts:143` leg timeout 180 s → 360 s.
- `docs/glossary.md:51` Repository row corrected post-G-3c (but **N-2**'s code comment at `onboarding.campaign.ts:202-207` was not).

Unmeasured at `42fdde50d`: `eslint`, `typecheck`, `typecheck:e2e`. Only the audit/tools guardrails were run there (exit 0, 160/160). `docs/qa:3` and `README:7`/workflow `country` remain untouched, so **M-3**, **M-4/`:3`**, **N-2**, **N-3**, **N-4**, **N-5**, **N-6** and MINORs 1/3/4/5/6/7/8 all survive.

---

## VERDICT: APPROVE-WITH-FIXES — mergeable into **local** `dev` tonight

All three r1 BLOCKERS are genuinely fixed, and the fixes were re-measured rather than accepted: lint delta is **exactly 0** against `dev` with the baseline untouched and no detector-defeating indirection in `src`; the false product finding is gone with positive ledger evidence in its place; and the census now reports the **real** `PaymentRepositoryController::index()` tenant-scope leak that r1 said the campaign had walked past — a finding `dev` has since routed as D-J0-8. The lane is additionally net-positive on convention 09 (a partner re-run assertion that did not exist in r1, green in the live ledger).

Merge conditions:

- **C-0 (blocking, merge mechanics)** — merge `43a8993f9` exactly, or run an r3 delta pass on `dc0ba1ad0..42fdde50d` (6 files, 21 lines) with `eslint`/`typecheck`/`typecheck:e2e` re-run at that tip. The branch tip tonight is not the commit this record gates.
- **C-1 (before the campaign is cited as anyone's promotion signal)** — one **full** run to green-or-known-red; **N-1**: L5–L8 and L10 have never executed against this code.
- **C-2 (before any staging/dispatch use)** — **M-3**: reject `CAMPAIGN_COUNTRY !== 'TN'` in code and delete the workflow `country` input; **M-4**: amend `docs/qa/ONBOARDING-CAMPAIGN.md:3` so "a red campaign blocks promotion" is reconciled with the Known-red list. If merging `43a8993f9`, the "push trigger stays INERT" sentence must also land (it is only in the successor).
- **C-3 (next fix round, non-blocking)** — N-2, N-3, N-4, N-5, N-6 and MINORs 1, 3, 4, 5, 6, 7, 8. MINOR-3 (quantity at scale 4) and MINOR-7 (baked brand string) are the two with product-rule weight (CLAUDE.md rule 19; owner rule OQ-1).

The push→dev trigger must remain inert (`onboarding-campaign.yml:35`, `vars.ONBOARDING_CAMPAIGN_ON_PUSH`) until M-1, M-3 and a tenant teardown all close — every armed run permanently creates a `tenant_<uuid>` database on the target.

Separately routed, **not** a finding against this lane: `dev` sits at 6458 warnings against a 6449 `scripts/lint-warning-baseline.json`, so `pnpm lint:ratchet` is red on `dev` today independently of I-1. Owner item; must not be absorbed into this lane's baseline.
