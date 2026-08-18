I reviewed round 3 of M6 against the brief section (`docs/handoff/CODEX-DISPATCH-country-defaults-phase-a-2026-08-10.md:543-580`), the full `7d85232cc..HEAD` diff, and the round-1/round-2 registers.

## M6 Adversarial Merge Gate — Round 3

**Scope reviewed:** `git diff 7d85232cc..HEAD`. M6 work is `c647f0910`, `92ac4768e` (round 1), `b138a224d` (round-2 fixes), `1918e3e75` (round-3 fixes). `7217e4382` is progress-YAML only.

**Lens applicability:** `frontend-conventions` — applies, applied. `tenancy-authz` — applies, applied. **Rule 19** — does not apply: no monetary or quantity surface anywhere in the M6 diff (`sort_order` is `integer`; grep over every changed `apps/web` file returns only *label strings* like `purchase_price_variance_expense` in the locale bundles — no numeric handling, no `parseFloat`/`Number(`, no bcmath).

**Round-2 disposition verified in code, not taken from the report:**
- **#1 (throttle/keystroke storm) — closed.** `TemplateEditorPage.tsx:73-93`: an incomplete scope sets `validationScope = null` → `enabled: false` → **no query key and no request**; a complete scope is debounced 300 ms; `retry: false` is set on the validation query. The gate is a real production expression (`COMPLETE_SCOPE_PATTERN`, `:23`) whose removal is detected by `TemplateEditorPage.test.tsx:190-209` (typing `T`, waiting 400 ms, asserting `not.toHaveBeenCalled()`). I could not execute the mutation replay (read-only), but by construction relaxing the pattern to `/^.*$/` makes `T` "complete", fires at 300 ms, and fails line 202 — the report's replay claim is consistent with the code.
- **#2 (missing round-2 red-first evidence) — closed.** `docs/sessions/codex-country-defaults-phase-a-report.md:1499-1546` now records the behavioral RED (**2 files failed / 5 tests failed, 28 passed**), names the five failing behaviors, and records the post-GREEN `COMPLETE_SCOPE_PATTERN` → `/^.*$/` mutation/revert replay (1/1 fail → 1/1 pass). Final evidence **4 files / 38 tests** — which I reproduced exactly (see below).
- **#3 dead metadata button — removed** (`TemplateEditorPage.tsx:190`, asserted at test `:279-284`). **#5 vague redirect assertion — pinned per role** (`adminRoleShell.test.tsx:46-64`). **#6 blank-row identity + blank parent option — fixed** (`:168`, `:180`; test `:248-264`). **#7 stale row error — cleared on edit** (`:104-108`; test `:266-277`). **#9 catalog banner — gated** (`AssignmentsPage.tsx:72`; test `:123-129`, non-vacuous: the string really is `"Country catalog {{version}}"`). **#4 and #8 — ticketed** in `docs/superpowers/tickets/2026-08-12-country-defaults-m6-p3-hardening.md:25-33`.

---

### Findings

**1 — P2 — CONFIRMED — `apps/web/src/features/admin/country-defaults/pages/TemplateEditorPage.tsx:197`**
A permanent, domain-level rejection of the certification scope is reported to the operator as a *transient transport failure*, and the actual rule is never surfaced anywhere in the UI.

The panel renders a single fixed key for any query error: `{validation.isError && … t('validation.unavailable')}` = **"Validation is temporarily unavailable. Try again."** But a syntactically complete scope can be refused by the *domain*, not the transport: `ValidateTemplateRequest::after()` (`apps/api/app/Modules/CountryDefaults/Presentation/Requests/ValidateTemplateRequest.php:44-57`) constructs a `CertificationScope`, which throws for a timbre/non-timbre mix (`CertificationScope.php:45-47`) and for wildcard mixing (`:31-34`); the exception becomes a `scope` validation error → **422**. Confirmed by the branch's own backend test: `TemplateApiEndpointTest.php:174-178` asserts `?scope=TN,%20FR` → 422 with `error.errors.scope.0 = "Exact certification scope cannot mix timbre and non-timbre countries."`.

`TN,FR` passes the FE's `COMPLETE_SCOPE_PATTERN` (`:23`), so the debounce fires it verbatim.

**Failure scenario:** an operator certifies a chart for Tunisia and France and types `TN,FR` in Certified jurisdictions. 300 ms later the panel turns red with "Validation is temporarily unavailable. Try again." — so they retry, and retry. Submitting anyway returns 422 from publish, which `countryDefaultsErrorKey` (`lib/apiError.ts:13-14`) collapses to "Review the submitted values and certification rules." Neither message states the rule; the timbre constraint is unreachable from the UI, and the one message the operator does get asserts a temporary outage that does not exist. Same shape for `*,TN`. This is the round-1 #1 defect class (panel asserting something false about a valid-looking input), not the round-2 #4/#8 class (correct-but-coarse detail), so I do not think the existing ticket covers it.

**Minimal fix:** branch the panel on status — `t(countryDefaultsErrorStatus(validation.error) === 422 ? 'validation.scopeRejected' : 'validation.unavailable')` with a new en+fr key stating the timbre/wildcard rules. `countryDefaultsErrorStatus` already exists and is already imported in the sibling page.

**2 — P3 — CONFIRMED — `TemplateEditorPage.tsx:72-93`, `:196-200`**
While the scope is syntactically incomplete the validation panel renders **nothing** — no verdict, no "checking", no hint — and that state survives closing the publish modal. With `validationScope === null` the query is disabled under a *new* key, so `data` is `undefined`, `isError` false, and `isLoading` false (v5: `isPending && isFetching`). React Hook Form's default `shouldUnregister: false` keeps the field value after `Modal` unmounts its children, so an operator who opens Publish, types `T`, and cancels leaves the "persistent validation panel" (a named M6 deliverable) blank for the rest of the page's life until they reopen the modal and clear the field. Not wrong data — but not persistent either. Test `:203` only asserts the *absence* of the old message, so nothing pins what the panel should show here.

**3 — P3 — CONFIRMED — `apps/web/src/features/admin/country-defaults/api/countryDefaultsApi.ts:29-34`**
`updateTemplate` is now dead: removing the metadata button left no production caller (`grep` over `apps/web/src` finds only the `vi.mock` factory at `TemplateEditorPage.test.tsx:18`). The `PUT /api/v1/admin/country-defaults/templates/{id}` endpoint (`routes.php:24`, `TemplateController::update`) consequently has no frontend consumer at all — a template's `name`/`description` can never be corrected from the UI, only cloned. Metadata editing is not an M6 deliverable, so this is a note; but the unused export and the orphaned endpoint should be explicitly recorded rather than left implicit.

**4 — P3 — CONFIRMED — `hooks/useCountryDefaults.ts:31-34` with `TemplateEditorPage.tsx:119-125`**
"Save rows" issues the same GET twice. `useCountryDefaultsMutation.onSuccess` invalidates the whole `['admin','country-defaults']` prefix, which refetches the active `templateQuery`; `handleSaveRows`'s own `onSuccess` then calls `templateQuery.refetch()`, whose default `cancelRefetch: true` cancels the in-flight fetch and starts another. One save therefore spends 3 of the 30/min `throttle:admin-sensitive` budget instead of 2. Harmless at current scale, but it is the same budget round-2 #1 was about.

**5 — P3 — CONFIRMED — `TemplateEditorPage.tsx:183`**
Deleting a row does not drop its `gridErrors` entry (only `updateRow` does, `:104-108`). The stale key is never rendered (no matching row) and `handleSaveRows` recomputes from scratch, so there is no user-visible consequence today — noted only because the two mutation paths now handle the same map inconsistently.

---

### Verifications that PASSED — bypasses attempted and failed

- **M6 suite reproduced exactly: 4 files / 38 tests passed** (`adminRoleShell` 14, `TemplateEditorPage` 14, `AssignmentsPage` 5, `TemplateListPage` 5), matching the report's claim. The debounce test genuinely exercises the 300 ms boundary (836 ms runtime).
- **tenancy-authz — no unguarded admin route.** I read the whole `/admin` block of `apps/web/src/routes/index.tsx:415-540`: the index is `AdminIndexRedirect` and **all 14** children are wrapped in `RequireAdminRole` with roles taken from the manifest — no hand-written `roles` array anywhere. `RequireAdminAuth` wraps `AdminLayout` at the parent `/admin` route, so every child inherits it.
- **FE role union ≡ backend matrix, re-derived from source.** `countryDefaultsRoles = ['super_admin','defaults_editor']` (`adminRolePolicy.ts:11`) ≡ `central_admin_role:super_admin,defaults_editor` (`CountryDefaults/Presentation/routes.php:16`); `supportAccessRoles` ≡ the support-access group; `fullAdminRoles = ['super_admin']` ≡ the `super_admin` alias group. The nested `central_admin_role:super_admin` editors group (`routes.php:34-39`) has no FE surface. `CentralAdminRouteInventoryTest.php:19-84` independently ratchets all 16 backend routes *and* proves `defaults_editor` gets 403 on every other `api/v1/admin/*` route except the deliberately widened `auth/me`+`auth/logout` — which `routes/api.php:44` moved from `super_admin` to `central_admin` (a widening to self-profile/self-logout only; `EnsureCentralAdmin` still 403s a deactivated actor and a flag-disabled `defaults_editor`).
- **localStorage escalation attempt fails.** `adminAuthStore.ts:53-55` persists only `admin`; `isAuthenticated`/`token` are memory-only and `RequireAdminAuth` gates on `isAuthenticated`, which sits *above* every role guard in the tree. Forging `admin-auth-storage` to `role:"super_admin"` yields a redirect to `/admin/login`, not access.
- **No redirect loop:** `homeForAdminRole` (`adminRolePolicy.ts:55-59`) maps each role to a route that role can access, so `RequireAdminRole`'s deny-redirect always terminates; asserted for 3 roles × 14 policies at `adminRoleShell.test.tsx:104-120`.
- **`tsc --noEmit`: clean. `eslint src/features/admin src/routes/index.tsx`: 0 errors / 1170 warnings**, all pre-existing `colorClasses`/untranslated-literal debt. Per-file JSON: the entire `country-defaults/` production tree, `adminRolePolicy.ts` and `AdminRoleGuard.tsx` emit **zero** diagnostics; the only warnings under the new code are 3 `restrict-template-expressions` in test helpers. `AdminLayout.tsx`'s 15 `colorClasses` warnings are on lines the diff does not touch (Rule 18 compliant — the diff only *removes* hardcoded English).
- **i18n recomputed, not trusted:** `en`/`fr` `adminCountryDefaults` are **190 keys each, zero drift either way**; `_many` present alongside `_one`/`_other` for both live plural call sites; `shell.*` is **21/21/21 across en/fr/ar**, so the round-1 Arabic regression stays closed (asserted at `adminRoleShell.test.tsx:32-44`).
- **`audit-tanstack-keys` Gate C: 0 violations. `audit-design-system`: 735 acknowledged, 0 new, 0 stale. `i18nRawKeyCoverage`: 5/5 passed.**
- **Types flow from the backend:** `country-defaults/types.ts` is pure re-exports of generated `App.Modules.*` declarations plus one `Omit<>`; no `any`, no hand-authored domain shapes.
- **Round-3 tests are non-vacuous.** The new catalog-banner assertion targets a string that genuinely renders pre-fix; the blank-row test asserts distinct localized accessible names *and* that exactly one `None` option exists per row; the row-error test asserts clearing on edit rather than on re-save.
- **Backend behaviour re-checked at the source, and code/parent trimming is symmetric** (`TemplateRowController.php:91-94` trims both `code` and `parent_code`, matching the FE's untrimmed `<option value>` round-trip). No backend file changed since round 2, so I did not re-run PHPUnit this round — I am relying on round 2's run for the backend suite and state that explicitly rather than implying I re-verified it.

---

Finding 1 must be resolved before merge. 2–5 are notes; 2 and 3 are worth appending to the existing M6 P3 ticket rather than fixing inline.

VERDICT: CHANGES-REQUIRED
