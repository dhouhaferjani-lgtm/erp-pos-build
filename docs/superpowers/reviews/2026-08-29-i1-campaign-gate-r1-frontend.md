# I-1 onboarding campaign — adversarial gate r1 (frontend / e2e conventions lens)

- **Target:** commit `008c2a199` on `feat/i1-onboarding-campaign`, worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/i1-campaign` (`git diff dev...HEAD`, 17 files, +2525).
- **Brief:** `docs/sessions/session-I-process-hardening-2026-08-29/LANE-I1-onboarding-campaign-BRIEF.md` (r4).
- **Reviewer:** Opus adversarial frontend-conventions gate. Reviewed 2026-08-29.
- **Verdict: REJECT** (3 BLOCKER, 4 MAJOR, 8 MINOR). Two blockers are ~15-line fixes; this is a "fix and re-gate", not a redesign.

> **Tree drift during review:** the working tree is NOT at the reviewed commit. `git status` shows `M apps/web/e2e/campaign/onboarding.campaign.ts` — an uncommitted `test.setTimeout(180_000)` + 2 comment lines inserted at `onboarding.campaign.ts:141-143`. All line citations below are for **commit `008c2a199`**; add +3 to every `onboarding.campaign.ts` line above 140 to locate it in the current working tree. Commit or discard that edit before merging so the merged artefact matches what was gated.

---

## Verification actually run (not reported — re-run by the reviewer)

| Check | Command | Result |
|---|---|---|
| e2e typecheck | `pnpm typecheck:e2e` | **exit 0** |
| campaign lint | `pnpm exec eslint e2e/campaign playwright.campaign.config.ts` | **0 errors, 142 warnings** |
| src typecheck unaffected | `pnpm typecheck` | **exit 0** |
| vitest unaffected | `pnpm exec vitest run tools/__tests__` | **8 files, 160/160 passed** |
| repo-wide lint count (worktree) | `pnpm exec eslint .` | **6600 problems (0 errors, 6600 warnings)** |
| repo-wide lint count (dev, main repo) | `pnpm exec eslint .` | **6458 problems (0 errors, 6458 warnings)** → delta **+142**, all campaign |
| workflow | `actionlint .github/workflows/onboarding-campaign.yml` | **clean** (actionlint present at `/opt/homebrew/bin/actionlint`) |
| wrapper script | `shellcheck scripts/campaign-onboarding.sh` | **clean** |
| whitespace hygiene | `git diff --check dev...HEAD`; EOF-newline + trailing-WS sweep | **clean** |
| vendored-file conservation | `diff apps/pos/src/lib/fiscal/{canonicalCore,FiscalEventCanonicalEncoder}.ts apps/web/e2e/campaign/fiscal/…` | **byte-identical, both** |
| ignore-negation scoping | `eslint e2e/money-campaign`, `eslint e2e/auth.spec.ts`, `eslint e2e` | only `e2e/campaign` is linted; legacy e2e still ignored |
| live-run evidence | replayed the orchestrator's ledger `ledger-run-18-green.json` (runId `20260829202913-26415`, tenant `01a04f36-6608-…`) | L0a/L1/L3/L4/L5/L6/L7/L8 PASS; L0+L2 FAIL-by-finding; L9 NOT_SCRIPTABLE; L10 FAIL (3 findings) |

---

## BLOCKERS

### B-1 — The diff breaks the CI lint-warning ratchet (+142 warnings, baseline untouched)
`apps/web/eslint.config.js:45-46` (`'e2e/*'`, `'!e2e/campaign'`) and `:49` (`'!playwright.campaign.config.ts'`) newly bring 1 796 lines of campaign code under `eslint .`. Measured: dev = **6458** warnings, this branch = **6600** (+142, 100 % attributable to `e2e/campaign`). `scripts/lint-warning-baseline.json:8` pins `@autoerp/web` at **6449**, and `.github/workflows/ci.yml:2451` runs `pnpm lint:ratchet`, which **fails when the count grows** (`scripts/lint-ratchet.mjs:118-120`). The `frontend-lint` job goes red on merge.

The 142 are almost all mechanical: `@typescript-eslint/dot-notation` on `Record<string, unknown>` API payloads (unavoidable and *correct* here — bracket access is what keeps `noPropertyAccessFromIndexSignature` happy), `restrict-template-expressions` on numeric interpolation in ledger strings, `no-unsafe-type-assertion`, `no-base-to-string`.

**Fix:** add ONE scoped override block after the `canonicalCore` block in `eslint.config.js` for `files: ['e2e/campaign/**/*.ts']` turning off `@typescript-eslint/dot-notation`, `restrict-template-expressions`, `no-unsafe-type-assertion`, `no-base-to-string` (justify in a comment: untyped API payloads), then remove the genuine dead code (MINOR-1) so the campaign contributes **0** warnings. **Do NOT run `node scripts/lint-ratchet.mjs --update-baseline`** — dev is already +9 over its recorded baseline (6458 vs 6449) and a blind regeneration would silently absorb that pre-existing drift too, which is exactly the baseline-dishonesty pattern this repo rejects.

### B-2 — The campaign emits a FALSE product finding, and the false finding permanently reddens the gate
`onboarding.campaign.ts:195-196` counts company-2 safes with `repository['type'] === 'safe' && repository['location_id'] === null`. Seeded safes are **not** location-less: the campaign's own company-1 census evidence reads `safe:SAFE-01@01a04f36-9327-7325-9b97-31174d3ffeff`. The recorded finding's own evidence block contradicts its headline:

```
"expected_location_id": "01a04f36-f5f3-70a7-98d5-180de9f20a44",
"matching_cash_count": 1,
"repositories": [ …, {"code":"CASH-01","location_id":"01a04f36-f5f3-…","type":"cash_register"},
                     …, {"code":"SAFE-01","location_id":"01a04f36-f5f3-…","type":"safe"} ],
"safe_count": 0
```

Company 2 **did** receive its own `CASH-01` and `SAFE-01` on its own location; only the `location_id === null` predicate failed. The finding text — *"Second company is not provisioned with company-owned payment repositories"* — is false as written. Because `recordProductFinding` (`journey.ts:245-257`) forces the leg to FAIL and `L10` (`onboarding.campaign.ts:664-670`) fails the run on any finding, the promotion gate is red forever on a non-bug. A gate that manufactures anti-signal is worse than no gate; note the code already guards against exactly this class at `:606-612` (the `sales_return` false-finding it deliberately avoided).

**Fix:** drop the `location_id === null` clause; mirror the day-one predicate at `:692` (`type === 'safe'`, own company) and re-run before merge. Also correct or delete the finding text.

### B-3 — Every repository assertion measures TENANT-wide data while claiming COMPANY-level meaning
`census()` (`onboarding.campaign.ts:673-681`) reads `GET /payment-repositories` (`selectors.ts:48`). That endpoint's `index()` is **tenant-scoped only** — `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentRepositoryController.php:38-43` has no `->where('company_id', $companyId)`, while `show()` at `:55-60` does and even carries the comment *"Treasury is company-scoped (api.treasury.067)"*. The live ledger proves it: with `X-Company-Id` = company 2, the list returned **4** repositories, two of them company 1's.

Consequences, all in the reviewed code:
1. Company-2 filters at `:193-196` are filtering a tenant-wide list; `matching_cash_count: 1` is coincidence (the location filter saved it), not scoping.
2. `assertDayOneCensus:693` `expect(value.repositories).toHaveLength(2)` ("only cash register and safe are provisioned") is **order-dependent** — true only because it runs before company 2 exists. It is a per-tenant count masquerading as a per-company invariant.
3. The campaign **walked past the real second-of-everything defect** (a treasury index that shows another company's drawers) and reported a fabricated one instead. This is precisely the failure mode Session I exists to eliminate (`docs/conventions/09-SECOND-OF-EVERYTHING.md`; CLAUDE.md rule 22).

**Fix:** filter both censuses by the repository's owning company (or add `?company_id=` and assert the API honours it), make `assertDayOneCensus` company-scoped rather than count-based, and record `PaymentRepositoryController::index()` missing the `company_id` predicate as the real product finding for the Treasury/Session-G owner.

---

## MAJOR

### M-1 — Reuse mode is dead on arrival and documented nowhere
`journey.ts:13-16` + `:267-278` implement `CAMPAIGN_REUSE_EMAIL` / `CAMPAIGN_REUSE_PASSWORD`. But L0 runs `assertDayOneCensus(first)` unconditionally at `onboarding.campaign.ts:158-159`, **before** the `if (reuseMode) return` at `:176`. Any tenant produced by a normal run already carries company 2, so the tenant-wide repository list has 4 entries and `:693 toHaveLength(2)` fails → L0 FAIL → serial mode skips the entire journey. The one affordance meant for staging triage cannot be used on the tenants it exists to triage. Neither `apps/web/e2e/campaign/README.md` nor `docs/qa/ONBOARDING-CAMPAIGN.md` mentions either variable.
**Fix:** skip (or company-scope) the day-one census when `reuseMode`, prove it with one reuse run against a kept tenant, and document both vars in README + operator doc.

### M-2 — `CAMPAIGN_KEEP_TENANT` is a *print* toggle, not a *retention* toggle; every run leaks a tenant DATABASE
There is no teardown anywhere in `e2e/campaign` (grep for delete/cleanup/teardown returns only the `ApiMethod` union at `journey.ts:71`). `journey.ts:230-232` only `console.log`s credentials and `:355` only stores them in the ledger. The tenant is **always** retained. `README.md:11` ("Set `CAMPAIGN_KEEP_TENANT=1` to **retain** and print") and `docs/qa/ONBOARDING-CAMPAIGN.md:22` both imply non-retention. Under database-per-tenant (CLAUDE.md tech-stack), each dispatch/push run permanently creates a `tenant_<uuid>` **database** on the target; once `ONBOARDING_CAMPAIGN_ON_PUSH` flips this becomes per-push unbounded growth on staging.
**Fix:** reword both docs to "print the credentials (the tenant is retained either way)", and state the prune policy — or add a teardown/`tenants:prune` note to the operator doc and the workflow header comment.

### M-3 — `--country` is advertised as a knob; only TN actually works
`journey.ts:145-160` maps 7 countries, `:162-164` handles scale 2, the workflow exposes a `country` input (`.github/workflows/onboarding-campaign.yml:16-19`), and `docs/qa/ONBOARDING-CAMPAIGN.md:19` shows `--country TN` as an override. But TN is hardcoded through the journey: second-company `timezone: 'Africa/Tunis'` (`onboarding.campaign.ts:181`), seller `tax_number: '1234567AM000'` / `postal_code: '1000'` (`fiscal/events.ts:283,288`), VAT `rate: '19.00'` (`fiscal/events.ts:98,169,263`), `+216…` phones and TN tax rates in `fixtures/parties.csv.template` / `fixtures/products.csv.template`, and the L6/L7 GL pins `20.000 / 3.800 / 23.800` (`onboarding.campaign.ts:540-542,614-616`). A `--country FR` run fails in a way that reads like a product bug.
**Fix:** either derive those values from the country (a small map beside `currencyForCountry`), or reject `CAMPAIGN_COUNTRY !== 'TN'` with an explicit "not yet supported" error and delete the knob from the workflow input + doc.

### M-4 — The operator doc presents a gate that is red-by-construction as a pass/fail signal
`docs/qa/ONBOARDING-CAMPAIGN.md:3` says "A red campaign blocks promotion" and the leg table (`:28-38`) reads as a list of guarantees — e.g. `:31` promises L2 "resolve unit IDs". In reality L10 (`onboarding.campaign.ts:664-670`) fails on ANY finding and the live run already records **3** (register-response-not-JSON, second-company repositories, `unit_id` never resolved). The gate ships red, with no way for an operator or the `docs/handoff/PROMOTION-CHECKLIST-2026-08-26.md` row to distinguish "expected red" from "new regression". This is the owner rule against **UI/copy overstating system guarantees**, applied to operator documentation.
**Fix:** add a "Known-red today" section listing each expected finding, its owning lane (G-3c, I2-F2, the register-JSON P0) and the date it should clear; state that `ONBOARDING_CAMPAIGN_ON_PUSH` must stay unset until the list is empty. (B-2's false finding must be removed from that list, not documented into it.)

---

## MINOR

1. **Dead code** (all three confirmed by ESLint `no-unused-vars` in the 142): `onboarding.campaign.ts:812 journalHasAccountCode`, `:821 deepContains` (its 3 grep hits are its own definition + 2 recursive self-calls — nothing calls it), `selectors.ts:75 labels.passwordConfirmation` (register step 1 has no confirm field — see `journey.ts:291`), and `journey.ts:577 booleanField` (exported, zero consumers). Delete all four.
2. **`e2e/tsconfig.json:3-9` relaxes five root-config strictness flags** (`exactOptionalPropertyTypes`, `noPropertyAccessFromIndexSignature`, `noUnusedLocals`, `noUnusedParameters`, `verbatimModuleSyntax`) against the brief's "strict". Probed: the relaxations exist only because `include: ["**/*.ts"]` (`:12`) drags in ~20 legacy `e2e/**/*.spec.ts` files. With the full strict set restored, the **campaign's own code is 100 % clean apart from the two dead functions in MINOR-1** (verified: `tsc -p e2e/tsconfig.json --exactOptionalPropertyTypes --noPropertyAccessFromIndexSignature --verbatimModuleSyntax --noUnusedLocals --noUnusedParameters` reports campaign errors ONLY at `815,10` and `824,10`). **Fix:** narrow `include` to `["campaign/**/*.ts"]` and delete all five overrides — ESLint only lints `e2e/campaign` anyway, so nothing else needs the project. That converts a silently-relaxed config into a genuinely strict one at zero cost.
3. **Quantities are compared with a money helper at scale 3.** `journey.ts:8` `MONEY_SCALE = 3`; `normalizeMoney` (`:166-181`) **throws** on a non-zero 4th decimal. It is applied to scale-4 quantities at `onboarding.campaign.ts:334,338,375,377,518,523,585` — so a real 4th-decimal stock drift surfaces as a thrown "exceeds scale 3" inside a `pollUntil` predicate rather than as a clean assertion. `:521` already works around the mismatch with a raw `=== '19.0000' || === '19.000'` string compare, which is the tell. **Fix:** add `assertQuantityEqual` / `quantityIs` at scale 4 (CLAUDE.md rule 19: quantity is `decimal(N,4)`) and use them for every quantity/lot assertion.
4. `fiscal/events.ts:61-63` and `:120-122` declare `buildSaleEnvelope`/`buildRefundEnvelope` `async` returning `Promise<…>` with no `await` inside (`authorEnvelope` is synchronous). Drop `async`/`Promise<>`, or keep them if a future signer needs it — but say so.
5. `scripts/campaign-onboarding.sh:3` sets only `set -u`, then brackets the run with `set +e` / `set -e` (`:50,53`) as though `-e` were active — the second `set -e` affects only two `echo`s. And `:51` uses the relative `apps/web`, so the script silently only works from the repo root (README:5 documents it; the script does not enforce it). **Fix:** `cd "$(dirname "$0")/.."` at the top and make the `set` line say what it means. (`shellcheck` is clean today.)
6. `.github/workflows/onboarding-campaign.yml:29` declares `PNPM_VERSION: '9'` which nothing consumes — `pnpm/action-setup@v5` reads `packageManager` from `package.json:19` (`pnpm@9.14.2`). Copied from `smoke-test.yml`; drop it.
7. `fiscal/events.ts:286` hardcodes the seller name `'AutoERP Campaign SARL'`. Owner rule OQ-1 (never bake a brand string into copy) targets user-visible product copy, and this is fake-tenant fixture data, so severity is low — but prefer `'Campaign SARL'` so the string never leaks into a screenshot handed to a customer.
8. `onboarding.campaign.ts:72-76` — `beforeEach` destructures `{ page }` for **every** leg, including the two network-free ones (L0a, L10), so both spin up a browser context they never use. The `afterEach` at `:63-64` already went to the trouble of an empty pattern for exactly this reason. Use `test.info()`-based dispatch or split the hook.

---

## What the diff got right (verified, not assumed)

- **Selector discipline: clean.** Exactly one `page.locator()` in the whole campaign — `selectors.ts:132` `input[type="file"]`, with a comment naming the reason (a file input has no ARIA role, `FileUpload.tsx` renders one hidden input). No `nth-child`, no class selectors, no XPath, no `querySelector`. Every `getBy*` lives in `selectors.ts`; the only three `page.goto` calls (`journey.ts:287,381,482`) use the `routes` table.
- **Session G testid contract honoured**: `testIdOrRole` (`selectors.ts:93-95`) is testid-first with a role/label fallback for tiles (`:121-130`), wizard steps (`:157-176`), and preview policy (`:155-156`); the two-partner-tile near-miss is documented in the file header (`:3-10`) as the brief required.
- **Vendoring is honest**: both fiscal files are **byte-identical** to `apps/pos/src/lib/fiscal/` (empty `diff`) — no silent fork, no rewritten import. The `no-irregular-whitespace` exception (`eslint.config.js:413-416`) is genuinely required and minimal: `canonicalCore.ts:99` contains literal U+2028 and U+2029 in a doc comment explaining the escaping contract.
- **ESLint scoping is correct and non-weakening**: `disableTypeChecked` + `project: false` (`eslint.config.js:417-424`) is confined to the one root config file that belongs to no tsconfig project, and that file still lints (0 problems, and it is NOT reported as ignored). The `e2e/*` + `!e2e/campaign` negation works as documented — `e2e/money-campaign` and `e2e/auth.spec.ts` remain ignored. Adding `./e2e/tsconfig.json` to the shared `project` array changed the src warning count by exactly 0 (6458 → 6458 + 142 campaign).
- **No leakage into existing gates**: root `tsconfig.json:54` includes only `src` and references only `tsconfig.node.json` (which includes only `vite.config.ts`), so neither `pnpm typecheck` nor `tsc -b`/`pnpm build` sees `e2e/`; `vitest.config.ts:12` collects only `src/**` + `tools/**`, so no campaign file runs under vitest; the base `playwright.config.ts` uses the default `*.spec.ts` match, so `*.campaign.ts` cannot be picked up by `pnpm test:e2e`. All four verified by running them.
- **Rule 19 (money) respected**: zero `parseFloat` / `toFixed` / `Math.round` / `*100` anywhere in `e2e/campaign`; the single `Number(` is bit-length arithmetic inside the vendored encoder (`FiscalEventCanonicalEncoder.ts:151`), not money. String amounts throughout (`fixtures/*.template`, `fiscal/events.ts:292-303`), and `moneySubtract` (`journey.ts:866-878`) uses `BigInt`, not floats.
- **Workflow**: `actionlint` clean; the push trigger is correctly inert at job level (`onboarding-campaign.yml:35`, `vars.ONBOARDING_CAMPAIGN_ON_PUSH`) with the reason in the file header (`:1-2`); no secrets consumed (the campaign registers its own tenant, unlike `smoke-test.yml`); artifact paths `apps/web/playwright-report/` + `apps/web/test-results/` with `if: always()` and 7-day retention match the brief and are both gitignored at `.gitignore:39-40`, so rendered fixtures/ledgers can never be committed.
- **Ledger design is sound**: L9 pre-declared `NOT_SCRIPTABLE` from initialisation (`journey.ts:132-133`), PENDING legs finalise to `SKIPPED` with a reason (`:220-224`), and a product finding pins the leg FAIL even when Playwright passes (`:200-207`) — the checklist reads correctly whatever happens.
- **`localStorage` contract verified against src**: `autoerp-auth` (`stores/authStore.ts:108`), `autoerp-company` (`stores/companyStore.ts:229`), `autoerp-company-selection` (manual key, `stores/__tests__/companyStore.test.ts:23`), `autoerp-cookie-consent` (`components/CookieConsent.tsx:8`) — all four keys used by `journey.ts:261,372-373,414-415` are real.
- Note: `dev`'s `CLAUDE.md` rule 22 already points at `scripts/campaign-onboarding.sh` and `docs/qa/ONBOARDING-CAMPAIGN.md`; this diff is what makes that reference resolve.

---

## VERDICT: REJECT — do not merge into local `dev` tonight as-is

Three blockers, two of them one-sitting fixes:

1. **B-1** — CI `frontend-lint` will go red on merge (+142 warnings vs a 6449 baseline). Fix with a scoped override to reach 0 campaign warnings; do **not** regenerate the baseline.
2. **B-2** — remove the `location_id === null` predicate that manufactures a false product finding and keeps the gate permanently red.
3. **B-3** — company-scope the repository census; report the real `PaymentRepositoryController::index()` scope gap that the campaign walked past.

Re-run required before re-gate: `pnpm exec eslint .` (expect ≤ 6458), `pnpm typecheck:e2e`, and one full `scripts/campaign-onboarding.sh` run whose ledger shows L0 with **no** repositories finding. M-1…M-4 (reuse mode, tenant retention wording, the `--country` knob, the known-red doc section) should land in the same round; MINORs may follow, except MINOR-1 and MINOR-2, which are the cheapest part of B-1's zero-warning fix.
