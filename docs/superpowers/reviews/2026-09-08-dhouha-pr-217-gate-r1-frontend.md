# Gate r1 — PR #217 `fix(counting): product_location wizard scope omits location_id (422) + raw axios toast`

- Reviewer: frontend-conventions-reviewer (adversarial gate, r1)
- Date: 2026-09-08
- Checkout: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/pr-217` — branch `gate/pr-217`, HEAD `64fabaadd`
- Base: `origin/dev` `cdedc2830` (1 commit on top)
- Scope: frontend-only, 8 files (`git diff cdedc2830..HEAD --stat`)

**VERDICT: MERGE-WITH-FIXES** — 0 Blocker, 1 Major, 5 Minor. The P1 fix itself is
correct, minimal, convention-clean and independently proven red-then-green. The
Major is a one-line residual in the same file whose absence makes one of the
PR's own stated regression guarantees false.

---

## 1. Guardrails re-run by the reviewer (nothing accepted as reported)

The worktree had **no `node_modules`**; installed with
`pnpm install --frozen-lockfile --filter "@autoerp/web..."` before running anything.

| Gate | Command | Result |
|---|---|---|
| Feature unit tests | `pnpm vitest run src/features/inventory-counting --reporter=dot` | **11 files / 74 tests PASS** — matches the PR's 74/74 claim |
| Typecheck | `pnpm typecheck` (`tsc --noEmit`) | **clean**, 40 s |
| ESLint (feature dir) | `pnpm eslint src/features/inventory-counting` | **0 errors**, 35 warnings (all pre-existing rules on untouched lines) |
| ESLint (whole web) | `pnpm lint:eslint` | **0 errors**, 6409 warnings (dev-wide baseline) |
| TanStack keys | `pnpm audit:keys` | Gate C: **0 violations, 0 new, 0 stale baseline entries** |
| Design system | `pnpm audit:design-system` | **802 acknowledged, 0 new, 0 stale** |
| Quantity display | `pnpm audit:quantity` | **0 total, 0 new, 0 stale** |
| ESLint rule RuleTester | `pnpm test:eslint-rules` | all rules pass (incl. `no-dead-tailwind-token-interpolation`, `no-untranslated-literal`) |
| Tools suites | `pnpm test:tools` | 9 files / 213 tests PASS |
| i18n completeness | `pnpm audit:i18n:local` | **1 NEW gap: `ar\|sales\|missing\|documents.dueDateBeforeIssue`** — see §2 |

Vitest workers reaped after each run (`pkill -f 'node .vitest'`; `ps aux | grep vitest` → empty).

### Baseline honesty (protocol §2) — PASS
The diff touches **no baseline file**. `apps/web/tools/audit-design-system-baseline.json`
is not in the 8 changed files, and all three audits report **`0 stale baseline entries`**,
i.e. no entry was absorbed or padded. No `--write-baseline` laundering.

### Mechanism audit (protocol §3) — PASS
No alias table, no suppression comment containing a detector keyword, no renamed
literal. The only new classes are `className="mt-6"` and the reused
`LocationSelectorMulti` — no token interpolation, no composed-at-runtime Tailwind
class (`no-dead-tailwind-token-interpolation` RuleTester green).

### Red-first claim verified (protocol §5) — PASS
I reverted **only** `CreateCountingPage.tsx` + `queries.ts` to `cdedc2830`
(`git checkout cdedc2830 -- …`), re-ran the two new/extended vitest files, then restored:

```
Tests  5 failed | 4 passed (9)
… expected 'counting.messages.createFailed:{"error":"Request failed with status code 422"}'
   to contain 'Location is required for this scope'
```

The tests genuinely fail on base and pass on HEAD. Worktree restored to a clean
`git status` afterwards (verified).

---

## 2. Attribution of the one red gate

`audit:i18n:local` reports one new gap, `ar|sales|missing|documents.dueDateBeforeIssue`.
Proven **pre-existing on the base commit**, not attributable to this PR:

```
git show cdedc2830:apps/web/src/locales/ar/sales.json  → 'dueDateBeforeIssue' in documents: False
git show cdedc2830:apps/web/src/locales/en/sales.json  → 'dueDateBeforeIssue' in documents: True
```

This diff touches `locales/*/inventory.json` only. PR-body claim (gap carried by
PR #219) is consistent with the evidence. Not a finding against #217.

---

## 3. Correctness of the fix

### Payload shape matches the server rule — VERIFIED
`apps/api/app/Modules/Inventory/Presentation/Requests/CreateCountingRequest.php:126-133`
requires both `scope_filters.product_ids` and `scope_filters.location_id` for
`product_location`; `:51` validates `scope_filters.location_id` as a
company-scoped existing location. The wizard now emits exactly
`{ product_ids: [...], location_id: '…' }`
(`.../pages/CreateCountingPage.tsx:376-383`, `:467-478`), asserted by
`src/features/inventory-counting/__tests__/CreateCountingProductLocationScope.test.tsx:171-176`
with a `toEqual` on the whole `scope_filters` object (data-meaning assertion, not a status code).

### Clears `location_id` on deselect — VERIFIED
`LocationSelectorMulti` emits `onChange(value.filter(...))`
(`src/features/locations/components/LocationSelectorMulti.tsx:107,117,121`), so a
deselect yields `[]` → `locationIds[0] === undefined`
(`CreateCountingPage.tsx:380`) → `canProceed()` returns false
(`CreateCountingPage.tsx:82-88`). Covered by
`CreateCountingProductLocationScope.test.tsx:189-203`.
Note: the key is set to `undefined` rather than deleted; `JSON.stringify` drops it,
so the wire payload is correct.

### `getErrorMessage` is the right helper — VERIFIED
Defined at `src/lib/api.ts:90`; already imported at
`src/features/inventory-counting/api/queries.ts:9` and used by the neighbouring
activate / finalize / manual-override handlers (`queries.ts:140`, `:208`, `:263`).
The create handler now matches them (`queries.ts:111-118`).

I also verified the **real** envelope, not just the test fixture:
`apps/api/bootstrap/app.php:325-334` renders `ValidationException` as
`{error:{code:'VALIDATION_ERROR', message: $e->getMessage(), errors: …}}`, and
Laravel's `ValidationException::summarize()` sets `getMessage()` to the FIRST
validator message — i.e. literally `"Location is required for this scope"`.
`getErrorMessage` reads `data.error.message` first (`api.ts:92-99`), so the toast
shows the real refusal. The test fixture is faithful to production.

### Conventions — PASS
- No hardcoded Tailwind colour added; new markup is `mt-6` only. Existing lines use
  `colorTokens` / `textColors` / `borderColors`.
- No new query key → `tenantScopedKey` N/A; the file's only `useQuery`
  (`CreateCountingPage.tsx:545`) already uses it and is untouched.
- No form introduced → RHF/zod N/A (this is a stepper with local state, pre-existing shape).
- No raw `<table>`, no raw form control added, no parallel picker — reuses the
  canonical `components`-level `LocationSelectorMulti`, same call shape as the
  `zone` scope (`CreateCountingPage.tsx:554-560`).
- Owner UI rulings: no competing accent/badge/colour added; no enrichment band; no
  disabled dead control or coming-soon toast; no brand literal; nothing touching
  refunds, blind counting, module gates or routing.
- Cross-cutting (`09/10/11`): no catalogue entity, no unique key, no new noun, no
  hand-rolled FE type beside a generated DTO. N/A across the board.

### Nothing else in `apps/web` posts a `product_location` without `location_id` — VERIFIED
`grep -rn "scope_filters" apps/web/src` (non-test) returns writers **only** in
`CreateCountingPage.tsx` (`:51,345,365,378,494,532,547,556`) plus the type
declaration at `types.ts:371-377`. `CreateCountingPage` is the sole web writer of a
counting scope payload. Check 5 of the brief: clear.

---

## 4. Findings

### MAJOR

**M-1 — `product` scope still posts a stale `location_id` after a scope switch; the PR's stated `product`-scope regression guarantee is false.**
`apps/web/src/features/inventory-counting/pages/CreateCountingPage.tsx:223`
```tsx
onChange={(scope_type) => { setFormData({ ...formData, scope_type }); }}
```
`scope_filters` is never reset when the scope changes. Because this PR makes
`product_location` the second writer of `location_id`, the wizard can now emit a
`product`-scope create carrying a location it is not scoped to.

**Empirically proven**, not inferred — I ran a throwaway vitest driving the real
component (select `product_location` → add product → select location → `previous`
→ switch to `product` → submit), then deleted it and re-confirmed a clean
`git status`:
```
SCRATCH PAYLOAD {"scope_type":"product","scope_filters":{"product_ids":["p-1"],"location_id":"loc-1"}}
```
The PR body claims "*régression périmètre `product` (pas de sélecteur, pas de `location_id`)*",
and both guards for it —
`CreateCountingProductLocationScope.test.tsx:205-232` and
`e2e/counting-wizard-scopes.spec.ts:437-467` — start from a **fresh render**, so
neither covers the switch path. The claim is broader than the evidence.

Blast radius today is contained (not a data-loss bug): `getStockLevelsForScope`
`case Product` ignores `location_id`
(`apps/api/…/InventoryCountingService.php:530-535`),
`CountingBlockService::scopeCoversLocation` returns `default => false` for
`Product` (`…/CountingBlockService.php:147-160`), and
`CreateCountingRequest.php:51` accepts the extra key (`sometimes`). So the row
persists a `scope_filters` that *reads* as location-scoped while nothing enforces
it — a data-meaning mismatch that the next reader of `scope_filters` (report,
projection, mobile) will trip over.

*Fix directive:* reset the filters on scope change —
`onChange={(scope_type) => { setFormData({ ...formData, scope_type, scope_filters: {} }); }}`
at `CreateCountingPage.tsx:223`, plus one unit test covering the switch path.
(Acceptable alternative if the owner wants the diff kept surgical: drop the
`product`-scope regression claim from the PR body and file the ticket — but the
one-line fix is cheaper than the ticket.)

### MINOR

**m-1 — the modified `queries.ts` is excluded from ESLint.**
`apps/web/eslint.config.js:58` ignores `**/src/features/inventory-counting/api/queries.ts`
(pre-existing "not in tsconfig project" debt list). The `onError` change is
therefore not lint-gated at all. No action required in this PR; note it so the
green lint result is not over-read as covering this file.

**m-2 — the wizard renders raw i18n keys, and the new e2e spec pins them.**
`CreateCountingPage.tsx:170 t('back')`, `:261 t('previous')`, `:272 t('creating')`,
`:282 t('next')` resolve in the `inventory` namespace, where none of these keys
exist; `src/lib/i18n.ts:491-495` sets `defaultNS: 'common'` but **no `fallbackNS`**,
so the buttons literally display `next` / `previous` / `back` / `creating`
(the strings do exist in `locales/en/common.json`). Pre-existing, not introduced
here — but `e2e/counting-wizard-scopes.spec.ts:303` now hard-codes
`getByRole('button', { name: 'next', exact: true })`, so fixing the i18n bug will
break the spec. Ticket the `common:` prefix fix; prefer a `data-testid` locator in
the spec.

**m-3 — the new Playwright spec is neither type-checked nor run anywhere.**
`e2e/tsconfig.json` `include` lists only `campaign/**`, `request-hygiene/**` and two
configs — `counting-wizard-scopes.spec.ts` is outside it, and the root
`tsconfig.json` includes only `src`, so `pnpm typecheck:e2e` does not see it.
CI runs Playwright only via `.github/workflows/smoke-test.yml:53`
(`playwright.smoke.config.ts` → `testDir: './e2e/smoke'`, `testMatch: /.*\.smoke\.ts$/`).
Placement is *consistent* with the other root specs (`auth.spec.ts` etc.), so this is
not a new inconsistency — but the "7/7 green" claim is local-only and unratcheted;
the spec can rot silently. Fine to merge; do not count it as a standing guard.

**m-4 — `maxSelection={1}` makes picking a different location a silent no-op.**
`src/features/locations/components/LocationSelectorMulti.tsx:109-111` returns
early once the cap is reached, so on the new `product_location` step an operator
who picked the wrong shop must first remove the chip; clicking another option does
nothing (only the `1 / 1` counter hints at why). Pre-existing behaviour inherited
from the `zone` scope, now on a P1-visible surface. Consider swap-on-select when
`maxSelection === 1`.

**m-5 — deselect test asserts the guard, not the payload.**
`CreateCountingProductLocationScope.test.tsx:189-203` asserts only that Next
re-disables. It cannot assert the payload (submission is blocked), which is fine,
but note `location_id` is set to `undefined` rather than deleted
(`CreateCountingPage.tsx:380`); any future consumer using `Object.keys` /
`'location_id' in filters` on the form state would see the key. Harmless on the
wire today.

---

## 5. What the PR body claims vs what I verified

| Claim | Verdict |
|---|---|
| Suite counting 74/74 | **TRUE** (re-run) |
| TDD red→green | **TRUE** (re-ran new tests against reverted source: 5 failed / 4 passed) |
| `getErrorMessage` matches the neighbouring handlers | **TRUE** (`queries.ts:9,140,208,263`) |
| Server rule unmodified, correct | **TRUE** (`CreateCountingRequest.php:126-133`, backend untouched by the diff) |
| ar/fr/en parity for the two new keys | **TRUE** (all three `inventory.json` diffs) |
| The red `audit:i18n` gate is pre-existing/dev-wide | **TRUE** (proven at `cdedc2830`) |
| Regression: `product` scope posts no `location_id` | **FALSE on the scope-switch path** → M-1 |
| Playwright 7/7 on the local mock stack | **NOT VERIFIED** (not run per instruction); asserted testids/strings do exist (`CountingDetailPage.tsx:299`, `en/inventory.json:709,778,780`) |

---

## VERDICT: MERGE-WITH-FIXES

Required before merge: **M-1** (one line at `CreateCountingPage.tsx:223` + a
switch-path test), or an owner decision to accept it as a ticket with the PR body
claim corrected. m-1…m-5 are notes/tickets and do not block.
