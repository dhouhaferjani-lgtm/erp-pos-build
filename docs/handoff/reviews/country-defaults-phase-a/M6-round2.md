## M6 Adversarial Merge Gate — Round 2

**Scope reviewed:** `git diff 7d85232cc..HEAD`, with M6 work isolated to `c647f0910`, `92ac4768e` (round 1) and `b138a224d` (round-2 fixes), against the M6 section of the brief (`docs/handoff/CODEX-DISPATCH-country-defaults-phase-a-2026-08-10.md:543-580`).

**Lens applicability:** `frontend-conventions` — applies, applied. `tenancy-authz` — applies, applied. **Rule 19 (money/quantity)** — does not apply: M6 touches no monetary or quantity column; `sort_order` is `integer`, no decimal/bcmath surface anywhere in the diff (verified by grep over the M6 file set).

**Round-1 disposition verified in code, not taken from the report:** #1 (scope `''` → permanent 422) fixed on *both* sides — `countryDefaultsApi.ts:40-46` omits the param when empty and collapses `TN, FR` → `TN,FR`; `ValidateTemplateRequest.php:22-40` normalizes in `prepareForValidation` and adds `nullable`. #2 (silent failures) fixed — `apiError.ts` + `role="alert"` surfaces on every query and mutation; `publish` moved off `mutateAsync` (`TemplateEditorPage.tsx:102-109`) so the unhandled rejection is gone; Archive is disabled for non-published (`TemplateListPage.tsx:69`). #3 (Arabic regression) fixed — `shell.*` added to `en`/`fr`/`ar` `admin.json`, `supportAccess.navigation` back on the `admin` namespace (`AdminLayout.tsx:21,25`), asserted at `adminRoleShell.test.tsx:32-44`. #4 fixed and the misleading assertion corrected (`AssignmentsPage.tsx:32-39`, test `:113-121`). #5 fixed — both dialogs now use the canonical `Modal`. #6 fixed — `templates.count`/`assignments.count` are now live call sites with real `_one/_other/_many`. #8 fixed and tested (`:236-255`). #10 fixed and tested (`:257-266`). #7 and #9 routed to `docs/superpowers/tickets/2026-08-12-country-defaults-m6-p3-hardening.md`.

---

### Findings

**1 — P2 — CONFIRMED — `apps/web/src/features/admin/country-defaults/pages/TemplateEditorPage.tsx:63-69`**
The persistent validation panel issues one un-debounced API call per keystroke of the certification-scope field, against a 30-request/minute throttle, and retries 4xx.

`useWatch({name:'scope'})` (`:64`) re-renders on every change of the `register('scope')` input (`:195`); `scope` is part of the `queryKey` (`:66`), so each keystroke creates a *new* query with no cache and fetches immediately. `staleTime` cannot suppress it — the key differs. The route carries `throttle:admin-sensitive` (`apps/api/app/Modules/CountryDefaults/Presentation/routes.php:17`) = **`Limit::perMinute(30)`** per admin (`apps/api/app/Providers/AppServiceProvider.php:268-273`), and the production client is `retry: 1` for *all* errors including 4xx (`apps/web/src/lib/queryClient.ts:7`; the tests override this to `retry:false`, which hides it).

**Failure scenario:** an operator certifies a template for five jurisdictions and types `TN,FR,IT,MA,DZ` (14 chars). That is 15 distinct query keys; the ~7 odd-length prefixes (`T`, `TN,F`, `TN,FR,I`, …) fail `regex:/^(?:\*|[A-Za-z]{2})(?:,…)*$/` (`ValidateTemplateRequest.php:39`) → 422 → retried once → ≈22 requests from one field, on top of `getTemplate`, the initial validation, and the post-save refetches. The 30/min budget is exhausted inside a single publish attempt; the subsequent `publishTemplate` POST returns 429, which `countryDefaultsErrorKey` (`lib/apiError.ts:17-18`) maps to `errors.generic` — the operator is told "Something went wrong" and is locked out of the whole Country Defaults surface (list, editor, assignments all share the limiter) for up to a minute, with no indication why. Independently, every incomplete prefix flashes `validation.unavailable` ("Validation is temporarily unavailable. Try again.") in the panel — a transport-failure message for what is merely a half-typed input, which is the exact confusion round-1 #1 asked to remove. The codebase has established debounce precedent for this pattern (`features/inventory/hooks/useCatalogBarcodeLookup.ts`, `features/pos/hooks/useDiscountPreview.ts`).

**2 — P2 — CONFIRMED — `docs/sessions/codex-country-defaults-phase-a-report.md:1462-1488`**
No round-2 evidence of any kind was recorded, and the M6 evidence of record is now factually stale.

The M6 section still reads *"The final exact gate passes **4 files / 22 tests**"*. The actual current suite is **4 files / 33 tests** (run below). `b138a224d` changed eight user-visible behaviors — visible query/mutation errors, scope normalization at the API boundary, the `admin`/`adminCountryDefaults` namespace split, canonical `Modal` adoption, live plural counts, post-save `rowEdits` reset, localized unknown-protection fallback, archive gating — and touched neither the report nor any RED record. Every prior fix round in this wave recorded one (`:1036` "Remediation RED evidence", `:1190`, `:1285` "Round-four dual-engine RED was 4 failed / 4 assertions", `:1353` "Review-fix RED was 12 failed / 26 passed"). The standing red-first check therefore has **zero** evidence for round 2; the new assertions cannot be distinguished from tests written green against already-fixed code. This is the same defect class that produced `CHANGES-REQUIRED` on M0 round 2 and M1 round 4.

**3 — P3 — CONFIRMED — `TemplateEditorPage.tsx:71-73` and `:163`**
"Save metadata" is a dead control. No input in the page is bound to `name`, `description`, or `standard_ref`; the handler passes `template.name` and reads the other two straight back off `templateQuery.data`, so the PUT always carries values identical to what the server already holds. Each click nonetheless writes an audit row with identical `oldValues`/`newValues` (`TemplateController.php:271-289`) and consumes one of the 30/min budget in finding 1. Metadata editing is not an M6 deliverable — the honest fix is to remove the button or bind it to real fields.

**4 — P3 — CONFIRMED — `lib/apiError.ts:13-14`, consumed at `TemplateEditorPage.tsx:162`**
Backend field-level validation detail is discarded. `UpsertTemplateRowsRequest` rejects duplicate `code`, non-distinct `sort_order`, `max:50` on code, etc., and Laravel returns them under `error.errors.rows.N.code`; the FE collapses every 422 to `errors.validation` ("Review the submitted values and certification rules."). The operator gets no pointer to the offending row. Round-1 #2 asked for *visible*, which is satisfied; *actionable* is not.

**5 — P3 — CONFIRMED — `apps/web/src/features/admin/__tests__/adminRoleShell.test.tsx:63`**
The `/admin` index-redirect assertion is `expect(screen.getByText(/home$/))`, which all three landing stubs (`Dashboard home`, `Defaults home`, `Support home`) satisfy. If `AdminIndexRedirect` were changed to a hardcoded `/admin/dashboard`, this case would still pass for `defaults_editor` and `support_approver` — the redirect *target* is only asserted indirectly through `homeForAdminRole` at `:51`. Pin the specific text per role.

**6 — P3 — CONFIRMED — `TemplateEditorPage.tsx:153`, `:145/:155/:156`**
Grid affordances degrade for blank rows. The parent `<Select>` maps every other row to `<option value={candidate.code}>`, so a freshly added row with `code === ''` emits a second empty-valued option alongside the deliberate `common.none` one. Likewise `editor.grid.codeAria/systemAria/deleteAria` interpolate `row.code`, so two blank new rows produce identical accessible names ("Account code ", "Delete account ") — ambiguous for screen readers and for any future `getByRole` assertion.

**7 — P3 — CONFIRMED — `TemplateEditorPage.tsx:91` with `:145`**
`gridErrors` is only recomputed on the next "Save rows" click. Correcting the offending field leaves the red `error` border and the helper text in place until the operator clicks save again.

**8 — P3 — CONFIRMED — `TemplateController.php:121-136`**
The validation report can only ever contain **one** error: the validator throws on the first violation and the controller wraps a single `try`/`catch` around it. A template with six problems requires six save/validate cycles to discover them (each cycle also spending the budget in finding 1). The brief requires a "persistent validation panel", not an exhaustive one, so this is a note — but the panel's `errors` array and the FE's `.map` (`TemplateEditorPage.tsx:175`) both advertise a plurality that the backend never produces.

**9 — P3 — CONFIRMED — `AssignmentsPage.tsx:72`**
The `assignments.catalogVersion` info banner renders unconditionally, showing `catalog_version: —` next to the "matrix could not be loaded" alert when the query has failed. Everything else on the page is correctly gated behind `!assignments.isError`.

**10 — P3 — ACCEPTED AS DEFERRED — `docs/superpowers/tickets/2026-08-12-country-defaults-m6-p3-hardening.md:18-25`**
Round-1 #9 (a route-tree-derived inventory ratchet, the FE analogue of `CentralAdminRouteInventoryTest`) and #6 (focus containment / Escape / inert semantics on the shared `Modal`) are correctly routed to a durable owner-visible ticket with named owners, and both are genuinely outside the M6 feature boundary. No objection.

---

### Verifications that PASSED — bypasses attempted and failed

- **tenancy-authz — FE guard mirrors the backend matrix exactly, re-verified against current code.** `countryDefaultsRoles = ['super_admin','defaults_editor']` (`adminRolePolicy.ts:11`) ≡ `central_admin_role:super_admin,defaults_editor` (`CountryDefaults/Presentation/routes.php:16`); `supportAccessRoles` ≡ `central_admin_role:super_admin,support_approver` (`SupportAccess/Presentation/routes.php:21`); `fullAdminRoles = ['super_admin']` ≡ the `'super_admin'` alias on the `routes/api.php:58` group. **No FE route grants a role the backend refuses.** The nested `central_admin_role:super_admin` editors group (`routes.php:34-39`) has no FE surface at all, so it cannot be under-guarded.
- **No redirect loop and no forbidden-dashboard stranding.** `homeForAdminRole` (`adminRolePolicy.ts:55-59`) maps every role to a route that role can access, so `RequireAdminRole`'s deny-redirect (`AdminRoleGuard.tsx:13`) always terminates. Exhaustively asserted for all 3 roles × all 14 policies at `adminRoleShell.test.tsx:104-120`.
- **Stale-localStorage escalation attempt fails.** `adminAuthStore` persists only `admin` via `partialize`; `isAuthenticated` and `token` are memory-only, and `RequireAdminAuth` gates on `isAuthenticated` (`RequireAdminAuth.tsx:9-14`). Forging `admin-auth-storage` to `role: "super_admin"` yields no route access without a fresh login, and the login response is the sole source of `role`.
- **N-A (defaults_editor stays dark in Release 1) holds on both entry points:** `SuperAdminAuthController.php:40-46` and `EnsureCentralAdmin.php:25-32`, both reading `config('country_defaults.external_editors_enabled')` uncached at call time.
- **M6 suite: 4 files / 33 tests passed.** Adjacent regression surface `src/features/admin` + `src/features/support-access`: **17 files / 72 tests passed** — the `admin`/`adminCountryDefaults` namespace split broke nothing. `src/lib/i18nRawKeyCoverage.test.tsx`: 5/5 passed.
- **Backend `TemplateApiEndpointTest`: 8 passed / 62 assertions.** The added case proves `?scope=` → `data.scope: []` (200) and `?scope=TN,%20FR` now normalizes and fails for a *domain* reason (`Exact certification scope cannot mix timbre and non-timbre countries.`), not a syntax one — i.e. the round-1 P1 is closed at the boundary the FE actually uses.
- **`tsc --noEmit`: clean. `eslint`: 0 errors.** Per-file JSON output confirms the three new production directories (`country-defaults/`, `adminRolePolicy.ts`, `AdminRoleGuard.tsx`) emit **zero** diagnostics; the only warnings under them are `restrict-template-expressions` in the three test files. All 1170 repo-wide warnings are pre-existing `colorClasses`/untranslated-literal debt in untouched admin pages.
- **`audit-tanstack-keys` Gate C: 0 violations** (plain `['admin','country-defaults',…]` keys are correct for non-tenant central data, per the brief). **`audit-design-system`: 735 acknowledged, 0 new, 0 stale.**
- **i18n completeness, recomputed rather than trusted:** `en`/`fr` `adminCountryDefaults` are **189 keys each, zero drift in either direction**. Every static `t('…')` call site in the feature resolves (0 missing). All enum-driven families are exhaustive: `accountTypes` = all 5 `AccountType` cases; `purposes` = all **41** `SystemAccountPurpose` cases; `status` = all 3; **all 17** codes emitted by `TemplateController::validationError` have `en`+`fr` entries with **matching `{{placeholder}}` names** (`code`/`sort_order`/`purpose`/`parent`), none of which collide with an i18next reserved option. `ar` falls back to `en` at `lib/i18n.ts:436`, registration complete at `:61`, `:227`, `:285`, `:447`.
- **Rule 11 improved, not merely preserved:** the base `AdminLayout` shipped hardcoded English nav names (`git show 7d85232cc:…AdminLayout.tsx:9`) and `AdminLoginPage` had no `t()` at all; both are now fully translated in en/fr/ar.
- **`crypto.randomUUID` (`TemplateEditorPage.tsx:32`) is not a new risk** — 8 existing production call sites use it identically, and new-row ids never reach the server (`rowForSave` strips the `new:` id; asserted at `TemplateEditorPage.test.tsx:224`).
- **Tests are non-vacuous.** Each M6 test drives real user interaction and asserts rendered localized text or exact API payloads; none assert CSS classes. The round-1 #4 test that "passed for the wrong reason" was corrected, not deleted.

---

Findings 1 and 2 must be resolved before merge. 3–9 are notes; 10 is accepted as deferred.

VERDICT: CHANGES-REQUIRED
