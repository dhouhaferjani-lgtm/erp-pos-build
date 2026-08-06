# Merge gate — BUG-005 media/onboarding lane, WEB half

- **Branch:** `fix/client-bugs-media-onboarding` @ 9 commits on `origin/dev` `fe0df479e`
- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp.fix-l6-media`
- **Scope reviewed:** `git diff fe0df479e..HEAD -- apps/web` (15 files, +319/-23). `apps/api` covered in parallel by the tenancy-authz reviewer; API files are cited here only where they define a contract the web half consumes.
- **Reviewer:** frontend-conventions (adversarial merge gate). Does not merge, does not modify.
- **Verdict: APPROVE-WITH-FIXES** — 0 BLOCKER, 2 MAJOR, 4 MINOR.

---

## 1. Guardrails re-run (not taken on report)

| Gate | Command | Result |
|---|---|---|
| ESLint + audits | `pnpm --filter @autoerp/web lint` | **exit 0** — 6516 problems, **0 errors**, 6516 warnings |
| audit:keys (Gate C) | (in lint) | 0 unscoped tenant query keys; 0 new, 0 stale |
| audit:design-system | (in lint) | 743 acknowledged, **0 new, 0 stale** |
| audit:quantity | (in lint) | 0 total, 0 new |
| eslint-rules RuleTester | (in lint) | 3 rules, all cases pass |
| Typecheck | `pnpm --filter @autoerp/web typecheck` | clean |
| Tests BY PATH (default pool) | `pnpm vitest run src/features/products src/features/settings src/lib/api.test.ts src/features/inventory/__tests__/ProductForm.opening.test.tsx` | **58 files / 260 tests passed**, 14.43s |
| react-doctor | n/a | not available in this repo (no binary, no agent definition) |

**Baseline honesty:** `apps/web/tools/audit-design-system-baseline.json` is **not** in the branch diff (`git diff --name-only fe0df479e..HEAD | grep baseline` → empty). No `--write-baseline` absorption. **Mechanism audit:** no `eslint-disable`, no `@ts-expect-error`, no `any`, no alias/indirection introduced in the diff. Metrics did not "improve" — they are unchanged, which is the honest outcome for a diff of this size.

---

## 2. Item-by-item verification of the claims

### 2.1 `productHeroImage` — `?variant=` surgery removed (A1) — VERIFIED
- `apps/web/src/features/products/productHeroImage.ts:15-19` is now identity + null/empty normalisation. Both URL classes pass through verbatim: signed relative `media.serve` URLs (HMAC covers path+query) and `ExternalUrl` CDN URLs, which the old code also mangled with a meaningless `?variant=md`.
- Coverage is real, not incidental: `productHeroImage.test.ts:16-38` covers signed-with-variant, signed-without-variant, external CDN, and null/undefined/empty.
- Rename `withProductHeroImageVariant` → `productHeroImageSrc`: **zero stale references repo-wide** (grep across `*.ts`/`*.tsx`/`*.md`, node_modules excluded, returns nothing). Both consumers updated — `editor/components/ProductHero.tsx:6,24`, `editor/components/ProductEditHero.tsx:9,68` — and the barrel `sections/index.ts:14`.
- Conservation: the `md` variant is not lost, it moved into the resolver — `apps/api/.../CatalogMediaQuery.php:56-70` mints `forAttachment($a, 'md')`. Three assertion updates (`ProductHero.test.tsx:42`, `ProductHeroSection.test.tsx:99`, `ProductForm.opening.test.tsx:406`) mirror the new contract rather than weakening it.

### 2.2 `getErrorMessage()` hardening (B3) — VERIFIED, their analysis is correct
- Their claim that `isApiError()` already guarded the deref is **correct**: the old guard required `data?.error !== undefined` (`api.ts:52-54`), so `data.error.message` could not TypeError. The two real defects were (a) `undefined` returned for a message-less envelope despite the `string` return type, and (b) a bare `{"message": …}` body failing the guard entirely and losing the server text.
- The real unconditional derefs were in the **interceptor** (pre-fix `response.data.error.message` at the 403 and 5xx branches); both now route through `getErrorMessage(error)` — `api.ts:210, 231`.
- Fallback chain implemented exactly as claimed (`api.ts:82-91`), with `readString` rejecting non-strings and empty strings.
- `api.test.ts:59-95` covers the three shapes plus two null-safety cases. Red-first is credible on inspection: the bare-message case returned the axios string and the message-less envelope returned `undefined` under the old implementation.

### 2.3 SetupChecklist error state (B1/B3) — VERIFIED with residuals (see MAJOR-1, MAJOR-2)
- `SetupChecklist.tsx:46-68` renders a token-styled `role="alert"` block plus a `Button` atom Retry wired to `refetch()`. Tokens only (`tokens.alert.base/error`, `tokens.heading.section`, `textColors.tertiary`) — no raw color classes, no raw `<button>`, no interpolated token/opacity. Rule 18 clean.
- Test discrimination: the success path renders no element with an `alert` role (the "required steps" block at `SetupChecklist.tsx:90` has no `role`), so `findByRole('alert')` + the Retry button genuinely separate error from empty. Pre-fix the error path fell through to the item list with `items = []` → no alert → red. Confirmed by reading `git show fe0df479e:…/SetupChecklist.tsx`.
- i18n: `en/settings.json:392-393` and `fr/settings.json:392-393` add `onboarding.loadErrorTitle`/`loadErrorBody`; `common:actions.retry` exists in **en, fr and ar**. `useTranslation(['settings','common'])` correctly widened.
- `ar/settings.json` has no `onboarding` block — their "falls back to en" claim is **true**, but via the shallow merge at `lib/i18n.ts:367-373` (`settings: { ...enSettings, ...arSettings }`), not runtime `fallbackLng`. Pre-existing, not regressed. See MINOR-3.
- `degraded: true` is emitted by the API but never surfaced. Not ticketed anywhere. See MAJOR-2.

### 2.4 `OnboardingItem.degraded` vs rule 7 (types flow from backend) — NO VIOLATION
- `OnboardingItem` lives at `apps/web/src/features/settings/api/onboardingApi.ts:3-15`, a **feature-local response interface**. `grep -rn OnboardingItem packages/shared` → nothing; the backend returns an array literal from `OnboardingChecklistService::getStatus()` with no `#[TypeScript]` DTO. So nothing generated was hand-edited and `typescript:transform` was not owed.
- The field is contract-honest: `OnboardingChecklistService.php` sets `'degraded' => $completed === null` for **every** row unconditionally, so the non-optional `degraded: boolean` cannot be absent (and API+web ship on the same branch).
- Observation (no action required for this merge): the canonical path would be an `OnboardingItemData` DTO + `typescript:transform`; this interface is drift-prone by hand.

### 2.5 Cross-cutting rules
- **Rule 14 (no double-unwrap):** `onboardingApi.ts:18` — `apiGet<OnboardingItem[]>('/onboarding/status')` against a controller returning `{ data: [...] }`. `apiGet` unwraps `response.data.data`; the payload is non-paginated with no `meta` consumers. Correct.
- **tenantScopedKey:** `SetupChecklist.tsx:23` unchanged and already scoped; Gate C reports 0.
- **Rule 11 (i18n):** every new user-facing string goes through `t()`.
- **Components:** `SetupChecklistPage.tsx:10` already uses `PageHeader`; the new branch uses the `Button` atom and `StatusBadge` remains for badges. No raw form controls, no raw `<table>`, no parallel picker.
- **Precision:** no money/quantity surfaces touched; `parseFloat` count unchanged.

---

## 3. Findings

### MAJOR-1 — the "0 of 0" dishonesty survives the disabled/pending window
`apps/web/src/features/settings/components/SetupChecklist.tsx:22-40`
The fix branches on `isError`, and the loading guard uses `isLoading`. In TanStack Query 5.90.11, `isLoading = isPending && isFetching` (`@tanstack/query-core/build/modern/queryObserver.js:310`). With `enabled: tenantId !== null && companyId !== null` false (company store not yet hydrated, or a principal with no company), the query is `pending` + `idle` → `isLoading === false`, `isError === false` → the component falls straight through to the item list with `items = []` and renders the exact "0 of N=0 completed" progress bar the commit set out to eliminate.
**Fix:** gate on `isPending` (or add an explicit `!enabled` branch) instead of `isLoading`.

### MAJOR-2 — `degraded` is plumbed into the type, emitted by the API, and dropped on the floor
`apps/web/src/features/settings/api/onboardingApi.ts:14` (declared) vs `apps/web/src/features/settings/components/SetupChecklist.tsx:105-138` (never read)
The API sets `degraded: true` for any step whose probe threw. The UI renders such a step identically to a genuinely-incomplete required step: red `AlertTriangle` + a red "Required" badge, sending the user to a settings page where nothing may be wrong — and `onboarding.allDone` can never appear while a step is degraded. That is the same silent-partial-failure class B3 just fixed, one layer down. `grep -rn degraded docs/` finds only the bug report itself; the branch changes no docs, so **it is not ticketed** — the mid-flight requirement to confirm ticketing is not met.
**Fix:** render a degraded state (neutral icon + `onboarding.badges.degraded` / tooltip, en+fr) **or** file `docs/superpowers/tickets/…` and reference it from the docblock at `onboardingApi.ts:9-14`.

### MINOR-1 — two of the new test's assertions are vacuous, and its comment misstates the old behaviour
`apps/web/src/features/settings/components/SetupChecklist.test.tsx:35-40, 88-89`
The `react-i18next` mock (`:13-28`) returns the key for unmapped keys and ignores interpolation, so `queryByText('0 of 0 completed')` can never match in **any** version of the component — the assertion is a no-op. Likewise `queryByText(/Setup complete/i)`: the "Setup complete!" banner was already guarded by `totalCount > 0` (pre-fix file, allDone block), so the comment's claim that the broken page also showed that banner is false. Only `findByRole('alert')` + the Retry query actually discriminate.
**Fix:** correct the comment and either drop the two assertions or re-target them at the rendered key text (`'onboarding.progressLabel'`).

### MINOR-2 — the interceptor's error branch is still gated on the typed envelope (pre-existing, in touched code)
`apps/web/src/lib/api.ts:188`
`if (isApiError(error))` requires a `{error:{…}}` body. The API half deliberately leaves `HttpExceptionInterface` untyped (`apps/api/bootstrap/app.php:410-419`), so a 419 CSRF response is Laravel's bare `{"message":"CSRF token mismatch."}` → `isApiError` false → the CSRF auto-refresh-and-retry at `api.ts:213-224` is unreachable. Pre-existing and not regressed by this diff, but it sits inside the exact block the diff hardened.
**Fix:** widen the gate to `axios.isAxiosError` (the per-status checks below already discriminate), or ticket it explicitly.

### MINOR-3 — Arabic onboarding copy is English by construction
`apps/web/src/locales/ar/settings.json` (no `onboarding` block) + `apps/web/src/lib/i18n.ts:367-373`
Their fallback claim holds, but through a build-time shallow merge over `enSettings`, so RTL users get an English alert title/body alongside an Arabic Retry button. Pre-existing gap that the two new keys extend.
**Fix (optional, out of scope):** add the `onboarding` block to `ar/settings.json`, or ticket the namespace gap.

### MINOR-4 — the `primary_image_url` switch changes bandwidth on list surfaces, not just the hero
`apps/web/src/components/molecules/line-items/ProductCell.tsx:35-37`, `apps/web/src/features/pos/api/productApi.ts:39-42`
`primary_image_url` has a single producer (`CatalogMediaQuery.php:56-70`), so moving it to `forAttachment($a,'md')` also changes every other SPA consumer: ~40px line-item thumbnails and the web-POS product cards now pull the **md** rendition instead of the previous (401-broken) download URL. Net improvement — they were broken before — but it is an unreviewed payload-size change on high-cardinality list surfaces. Verified that the Tauri POS is unaffected: `grep -rn primary_image_url apps/pos/src` → no consumers (it reads `media[].image_url`, whose `forPosSync` shape is untouched).
**Fix (follow-up):** consider a `sm` variant for `ProductCell`, or accept knowingly.

---

## 4. Notes (no action)
- `productHeroImage.ts` is now an identity function with a 14-line docblock; keeping it as a documented seam (rather than inlining `?? null` at the two call sites) is defensible and keeps the regression test addressable.
- `SetupChecklistPage.tsx:10` and `SetupChecklist.tsx:50-51` both render the onboarding title/subtitle, so the page shows it twice; the new error branch faithfully reproduces the pre-existing duplication rather than introducing it.

---

# Round 2 — narrow re-verification (2026-08-06)

- **Commits under review:** `9e348f1cb` (MAJOR-1 + MAJOR-2 + MINOR-1), `87044e76b` (MINOR-2 / 419 interceptor), `02893a626` (docs only — gate records + POS-image ticket; contains no code).
- **Verdict: CLEAR TO MERGE (FE half).** All four round-1 findings closed at the root. 0 BLOCKER, 0 MAJOR. Three new MINOR residuals below, none merge-blocking.

## Gates re-run (round 2)

| Gate | Result |
|---|---|
| `pnpm --filter @autoerp/web lint` | exit 0 — **0 errors**, 6517 warnings (was 6516; the +1 is `@typescript-eslint/no-unsafe-type-assertion` ×2 on the new `api.csrfRetry.test.ts:24,36` test scaffolding, minus one elsewhere — warning class only) |
| audit:keys / design-system / quantity | Gate C 0 new; **design-system 743 acknowledged, 0 new, 0 stale**; quantity 0. Baseline file still absent from the diff — no absorption. |
| eslint-rules RuleTester | 3 rules, all cases pass |
| `pnpm --filter @autoerp/web typecheck` | clean |
| `pnpm vitest run src/features/settings src/lib` (default pool, BY PATH) | **49 files / 248 tests passed**, 19.06s |
| `pnpm vitest run …/SetupChecklist.test.tsx` | 5 tests passed (2 → 5) |
| `npx react-doctor@latest --verbose --diff` | ran (skill lives at `.agents/skills/react-doctor/SKILL.md` — **my round-1 "not available" was wrong; correction recorded**). Score 49, 203 diagnostics (10 errors / 193 warnings) across 132 files. `--diff` did **not** restrict to the diff — this is effectively a full scan, i.e. a stronger check. Machine-checked `diagnostics.json`: **0 hits on any of the 14 changed web files** (grep over `SetupChecklist`, `productHeroImage`, `onboardingApi`, `lib/api`, `ProductHero`, `csrfRetry`). Their claim verified; the 203 pre-existing findings are unrelated code (stock-transfers, placement, purchases, pos). |

## Findings closed

**MAJOR-1 — CLOSED.** `SetupChecklist.tsx:22,43` now destructures and gates on `isPending`. The triad is complete and mutually exclusive: pending (incl. the **disabled** window) → `Spinner`; `isError` → alert + Retry; otherwise → body. New test `SetupChecklist.test.tsx` ("shows the loading state … while the query is disabled") sets `companyState.currentCompanyId = null` via `vi.hoisted` mutable state reset in `beforeEach`, and asserts `mockFetchOnboardingStatus` was never called **and** `queryByText('onboarding.progressLabel')` is absent. Red-first is structurally verifiable: under the old `isLoading` gate a disabled query is pending+idle → falls through → renders the progress label → that assertion fails.

**MAJOR-2 — CLOSED, surfaced (preferred option taken).** `SetupChecklist.tsx:128` renders `HelpCircle` + `textColors.tertiary` for a degraded row instead of the red triangle; `:145-156` the `item.degraded` badge branch **precedes** required/optional, so a degraded step can never read "Required"; `:150` uses `StatusBadge tone="warning"`, a first-class tone backed by `tokens.alert.warning` (`StatusBadge.tsx:19,40`) — **rule 18 clean, no new literals, no interpolated token**; `:149` tooltip is `t('onboarding.badges.unavailableHint')` — translated, and the wrapping `<span title>` is the right call over widening a shared atom; `:166` `allDone` now requires `!hasDegraded`, so completion is never claimed on unknown state. Keys added to `en/settings.json:405-406` and `fr/settings.json:405-406`. Two new tests assert the discrimination (exactly one "Unavailable", the healthy required row keeps "Required") and the `allDone` suppression. **`ar/settings.json` confirmed untouched** — `git diff --name-only fe0df479e..HEAD -- apps/web/src/locales` lists only en + fr, so MINOR-3 stands unchanged as a pre-existing gap.

**MINOR-1 — CLOSED.** Assertions re-targeted at the rendered key `'onboarding.progressLabel'` (the mock returns unmapped keys verbatim, so this genuinely discriminates the empty body from the error/loading branches), and a positive assertion added to the success test. The false "Setup complete! banner" claim is dropped from **both** the test comment and the component comment (`SetupChecklist.tsx:51-54`), with the `totalCount > 0` guard correctly named as the reason.

**MINOR-2 (419) — CLOSED at the root.** `api.ts:198-214`: the branch now runs **before** the `isApiError` gate and keys on `axios.isAxiosError(error) && error.response?.status === 419`, so Laravel's untyped `{"message":"CSRF token mismatch."}` reaches it. Loop-safety is sound: `_csrfRetried` is stamped on the *config object*, which axios reuses for the replay, so the replay's own 419 hits the guard and falls through — verified by their test ("does not retry forever", `calls === 2`). Non-419 paths are byte-untouched: the diff only deletes the old inner block and leaves 401/403/5xx/network handling identical; their third test proves a 500 makes exactly 1 adapter call and never touches `/sanctum/csrf-cookie`. 4/4 tests pass on my run.

## New residuals (MINOR, non-blocking)

### MINOR-R2-1 — a failed *replay* is mislabelled and masks the real error
`apps/web/src/lib/api.ts:206-213`
`return await client.request(config)` sits **inside** the `try`, so a rejection of the replayed request is caught by `catch (csrfError)`, logged as `'Failed to refresh CSRF token:'` (it wasn't), swallowed, and the caller is then rejected with the **original 419** rather than the replay's real error — a 422/500 on the replay would surface as a CSRF error. Visible in their own suite: the "does not retry forever" test prints a 419 object under that message. The pre-fix code returned the replay un-awaited, so it propagated.
**Fix:** keep only `await ensureCsrfCookie()` in the `try`; put `return await client.request(config)` after the `catch`.

### MINOR-R2-2 — the top "required steps" alert still fires for a degraded step
`apps/web/src/features/settings/components/SetupChecklist.tsx:34, 98-101`
`hasIncompleteRequired = items.some(i => i.required && !i.completed)` still counts a degraded required step (it is reported `completed: false`), so the red `onboarding.requiredStepsAlert` banner tells the user they have work to do on the very row the list labels "Unavailable". `allDone` was correctly taught about `degraded`; this sibling condition was not — the same mixed message MAJOR-2 removed from the badge, one element up.
**Fix:** `items.some(i => i.required && !i.completed && !i.degraded)`, and/or add a dedicated "some steps could not be checked" line.

### MINOR-R2-3 — indefinite spinner for a permanently disabled query
`apps/web/src/features/settings/components/SetupChecklist.tsx:43-49`
Correctly preferred over the false "0 of 0", but for a principal that never gets a `currentCompanyId` the query stays disabled forever and the page spins indefinitely — "loading" is itself a claim that will never resolve.
**Fix (follow-up):** branch the disabled case explicitly (`enabled === false && !isFetched`) to an "unavailable / select a company" state.

### Nit (no action)
`SetupChecklist.test.tsx` disabled-window test asserts the spinner with `container.querySelector('[data-testid="spinner"], svg')` — any `<svg>` satisfies it. Harmless here because the co-located `queryByText('onboarding.progressLabel')` assertion carries the discrimination, but the selector proves less than it appears to.
