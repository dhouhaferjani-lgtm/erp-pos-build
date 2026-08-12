## M6 Adversarial Merge Gate — Round 1

**Scope reviewed:** `git diff a874c7a16..HEAD` (M6 commits `c647f0910`, `92ac4768e`, `f43d99fd6`) against the M6 section of `docs/handoff/CODEX-DISPATCH-country-defaults-phase-a-2026-08-10.md:543-580`, plus the M6 backend wire-contract additions in `92ac4768e`.

**Lens applicability:** `frontend-conventions` — applies, applied. `tenancy-authz` — applies, applied. **Rule 19 (money/quantity)** — does not apply: M6 touches no monetary or quantity field (`sort_order` is `int`, no decimal columns, no `bcmath` surface).

---

### Findings

**1 — P1 — CONFIRMED — `apps/web/src/features/admin/country-defaults/pages/TemplateEditorPage.tsx:61-67`**
The persistent validation panel — a named M6 deliverable — issues a request the backend rejects with 422 on its default path, so it permanently reports "Changes required" for a perfectly valid template.
`publishForm` defaults `scope: ''` (`:61`); `useWatch` yields `''`; the query fires `validateTemplate(templateId, '')` (`:63-67`) → `apps/web/src/features/admin/country-defaults/api/countryDefaultsApi.ts:41` → axios emits `?scope=` (verified: `axios.getUri` → `.../validation?scope=`). Laravel's default global `ConvertEmptyStringsToNull` (`vendor/laravel/framework/src/Illuminate/Foundation/Configuration/Middleware.php:462`, not removed in `apps/api/bootstrap/app.php`) cleans the **query bag** (`TransformsRequest::clean`, `:31`), turning `scope` into `null`. `ValidateTemplateRequest::rules()` (`apps/api/app/Modules/CountryDefaults/Presentation/Requests/ValidateTemplateRequest.php:32`) is `['sometimes','string','regex:…']`; the key is *present-with-null*, so `sometimes` does not skip and `string` fails. Verified directly against the live rule set:
```
{"scope":null}    => FAIL: ["validation.string","validation.regex"]
{"scope":""}      => PASS      ← only if the middleware were disabled
{"scope":"TN, FR"}=> FAIL: ["validation.regex"]
{"scope":"TN,FR"} => PASS
[]                => PASS      ← what the backend tests exercise
```
**Failure scenario:** an admin opens any draft template. The right-hand panel shows the `tokens.alert.warning` state and the text "Changes required" with an empty error list, forever — because `validation.data` is `undefined` (`TemplateEditorPage.tsx:154-158`) and the query has no error branch. Second scenario: the operator follows the page's own hint `publish.scopeHelp` ("Enter comma-separated ISO codes") and types `TN, FR` with the natural space — axios sends `?scope=TN,+FR`, the regex rejects it, panel goes red, yet `publishTemplate` trims and would succeed. The panel and the publish gate disagree.
**Why no test caught it:** every FE test mocks `../api/countryDefaultsApi`, and the backend tests only exercise the endpoint with **no** `scope` param or `?scope=TN,FR` (`apps/api/tests/Feature/CountryDefaults/TemplateApiEndpointTest.php:163-172`). The exact shape the FE sends is untested on both sides.

**2 — P2 — CONFIRMED — `apps/web/src/features/admin/country-defaults/hooks/useCountryDefaults.ts:27-35`**
No mutation or query in the feature has any error path; failures are silent, and one of them raises an unhandled promise rejection.
`useCountryDefaultsMutation` defines only `onSuccess`. Concrete scenarios:
- `TemplateListPage.tsx:67` — the Archive button is disabled only when `status === 'archived'`, so it is **enabled for drafts**. `TemplatePublishingService::archive` throws `DomainException` for a non-published template → `TEMPLATE_CONFLICT` 409 (`TemplateController.php:173-180`). The row does not change and the user sees nothing at all.
- `TemplateEditorPage.tsx:92-96` — `submitPublish` uses `await publish.mutateAsync(...)` inside `publishForm.handleSubmit`; RHF re-throws a rejected submit handler, and the caller is `void submitPublish(event)` (`:172`) with no `.catch`. A 422 `TEMPLATE_VALIDATION_FAILED` therefore produces an **unhandled promise rejection**, the modal stays open, `setPublishedHash` never runs, and no message is rendered.
- `TemplateEditorPage.tsx:90` — a server-side row rejection (duplicate `code`, `distinct` `sort_order`, `max:50`) returns 422/409 and the grid silently keeps the unsaved state as though it had saved.
- `TemplateListPage.tsx:89` — a 403/500 on `listTemplates` renders `emptyTitle` ("No templates in this domain."), which is indistinguishable from a genuinely empty library.

**3 — P2 — CONFIRMED — `apps/web/src/features/admin/components/AdminLayout.tsx:22` and `:24`**
Moving the whole admin shell's copy into the `adminCountryDefaults` namespace regresses an already-translated Arabic string.
`useTranslation('admin')` became `useTranslation('adminCountryDefaults')` (`:24`), and the support-access nav item changed from `labelKey: 'supportAccess.navigation'` to `'shell.navigation.supportAccess'` (`:22`). `apps/web/src/locales/ar/admin.json` contains `supportAccess.navigation = "وصول الدعم"`, but `apps/web/src/lib/i18n.ts:436` registers `adminCountryDefaults: enAdminCountryDefaults` for `ar`.
**Failure scenario:** an admin whose browser resolves `ar` previously saw "وصول الدعم" in the sidebar; after this change the same item renders "Support access" in English. Secondary concern (same root): shell-wide copy (portal title, sign-in, email/password labels, every nav label) now lives in a feature-scoped namespace it does not belong to, so any future `admin` namespace work must know to look in `adminCountryDefaults`.

**4 — P2 — PLAUSIBLE — `apps/web/src/features/admin/country-defaults/pages/AssignmentsPage.tsx:41-44`**
The assignment matrix displays the *first offered* template rather than the *assigned* one whenever the assigned template is not in the option list, and the page discards the authoritative datum the backend already sends.
`AssignmentMatrixRowData` carries a full `template` summary (`apps/api/app/Modules/CountryDefaults/Application/DTOs/AssignmentMatrixRowData.php`, `AssignmentController.php:55`), but the page never reads `row.template` — it sets `<Select value={row.template_id}>` and populates options solely from `availableFor(...)`. When no `<option>` matches the value, the browser renders the first option.
**Failure scenario:** `listTemplates` is slower, errors, or 403s while `listAssignments` resolves — every country row renders a select showing a template it is not assigned to (or a blank), with the Re-point button disabled, i.e. no visual cue that the display is wrong. The M6 test itself constructs exactly this state: `AssignmentsPage.test.tsx:34` assigns TN to `old-tn-template`, which is absent from the mocked template list, and `:68` then asserts the picker shows **`PCN Tunisia 2026`** — the assertion passes for the wrong reason and enshrines the mis-display. I did verify the steady state is protected server-side (archive/delete of an assigned template are refused, `TemplatePublishingService.php:165-175`, `:214-224`; `CertificationScope::allowsAssignment` matches `availableFor`'s wildcard rule exactly, `CertificationScope.php:70-79`), so this is a load/error-window and test-fidelity defect rather than a steady-state one.

**5 — P3 — CONFIRMED — `TemplateEditorPage.tsx:171` / `AssignmentsPage.tsx:65`**
Both dialogs are hand-rolled `<dialog … open>` rather than the canonical `src/components/organisms/Modal`. A `<dialog>` with the `open` attribute (never `showModal()`) is **non-modal**: no focus trap, no Escape-to-close, no inert background. For the re-point confirmation gating a certified assignment change, that is a weaker guard than the canonical component provides. There is limited precedent for raw `tokens.modal.*` usage (5 files), so this is a note, not a blocker.

**6 — P3 — CONFIRMED — `apps/web/src/locales/en/adminCountryDefaults.json:61` and `:106`**
`templates.count_one/count_many` and `assignments.count_one/count_many` are dead keys — no call site exists. Worse, if they are ever used, `_many` is not an English CLDR plural category, so `t('templates.count', {count: 5})` in `en` would find neither `_other` nor `_many` and return the raw key. The brief's `_many` requirement is satisfied literally but not usefully.

**7 — P3 — CONFIRMED — `docs/sessions/codex-country-defaults-phase-a-report.md:1478`**
Red-first evidence for M6 is *"The RED run failed all four suites on the intentionally missing page/guard imports."* That is a module-resolution failure, not a failing behavioral assertion, and unlike M1–M5 there is no revert-replay/mutation record for M6 (M2 and M4 both recorded mutation-based re-verification). The named M6 behaviors — grid validation, locked protected rows, role-filtered nav, three-role landing/guards, re-point confirm — are all covered by real assertions in the final green suite, so this is a process note rather than a coverage gap.

**8 — P3 — CONFIRMED — `TemplateEditorPage.tsx:57`, `:77`, `:90`**
`rowEdits` is never reset after a successful save. Once the user touches the grid, `rows = rowEdits ?? …` pins the view to local state permanently: server-side `trim()` normalization (`TemplateRowController.php:91-92`), a concurrent admin's edits, and the real ids assigned to `new:`-prefixed rows are all invisible until a full page reload. No duplication results (the backend does a full delete-and-reinsert replace, `:81-100`), so this is cosmetic/staleness only.

**9 — P3 — CONFIRMED — `apps/web/src/features/admin/__tests__/adminRoleShell.test.tsx:73-86`**
The "complete protected admin route inventory" test asserts the shape of `adminRoutePolicies`, not the shape of the route tree. Nothing prevents a future `<Route path="something">` being added inside the `/admin` block in `apps/web/src/routes/index.tsx` without a `RequireAdminRole` wrapper — the FE has no analogue of the backend's `CentralAdminRouteInventoryTest`. Today's tree is fully covered (index + 14 guarded children, all driven from the policy manifest).

**10 — P3 — CONFIRMED — `TemplateEditorPage.tsx:126`**
`t(\`protection.${row.protection_source}\`)` has no `defaultValue`. `protection_source` is a free-form backend string (`TemplateAccountData.php:27`); the `protection` bundle covers only `treasury_instrument_literal` and `expense_category_demo_consumer`. A new entry in `ProtectedAccountCodeRegistry` renders the raw key `protection.<new_source>` to the operator. Contrast `:164`, where the validation-error lookup correctly supplies a `defaultValue`.

---

### Verifications that PASSED (bypasses attempted and failed)

- **tenancy-authz — FE guard vs backend matrix: mirrors exactly.** `countryDefaultsRoles = ['super_admin','defaults_editor']` (`adminRolePolicy.ts:11`) matches `central_admin_role:super_admin,defaults_editor` (`apps/api/app/Modules/CountryDefaults/Presentation/routes.php:16`); `fullAdminRoles = ['super_admin']` matches the `'super_admin'` alias on the `routes/api.php:58` admin group (`EnsureSuperAdmin` requires role `super_admin` exactly); `supportAccessRoles` matches `central_admin_role:super_admin,support_approver` (`SupportAccess/.../routes.php:21`). No FE route grants a role the backend refuses. N-A holds: `defaults_editor` cannot authenticate while the flag is off (`SuperAdminAuthController.php:40-46`, `EnsureCentralAdmin.php:25-32`).
- **`--pool=forks --poolOptions.forks.singleFork` produced 5 failures** ("multiple elements with…"). Not a product defect: disabling per-file isolation defeats RTL's auto-cleanup. Under the project's standard runner all four M6 files pass — **4 files / 22 tests**, and 8 files / 21 tests for the adjacent existing suites (`AdminLoginPage`, `i18nRawKeyCoverage`, all of `support-access`) are green, so no existing FE test was broken by the namespace/atom changes.
- **`tsc --noEmit`: clean.** **`eslint src/features/admin src/routes/index.tsx`: 0 errors** (warnings are all pre-existing `colorClasses`/untranslated-literal debt in untouched admin pages; the new `country-defaults/`, `adminRolePolicy.ts`, `AdminRoleGuard.tsx` files produce **zero** diagnostics). No `any`, tokens-only.
- **`audit:keys` Gate C: 0 violations** — plain `['admin','country-defaults',…]` keys are correct for non-tenant admin data.
- **`audit:design-system`: 735 acknowledged, 0 new, 0 stale.** The baseline legitimately shrank by 3 (AdminLoginPage now uses the canonical `Input`/`Button` atoms).
- **en/fr parity: 176 keys each, zero drift in either direction**; `i18n.ts` registration complete in all three required places (`:61`, `:227`/`:285`, `:447`) plus the `ar`→`en` fallback.
- **Generated types**: `packages/shared/types/generated.d.ts` additions correspond 1:1 to real `#[TypeScript]`-attributed DTOs (`TemplateData.php` genuinely declares `account_types`/`system_account_purposes` via `#[TypeScriptType]`); `SuperAdminRole` gained `defaults_editor`. No hand-edit signature.
- **Backend wire contracts**: `TemplateApiEndpointTest` + `AssignmentApiEndpointTest` — 12 passed, 1 PG-only skip, 82 assertions. The `{data,meta}` envelope asserted by `AssignmentApiEndpointTest:33-43` matches `adminApiGetPaginated`'s no-unwrap contract, and the `{code,parameters}` validation-error shape matches the FE consumer.

---

Findings 1 (P1) and 2–4 (P2) must be resolved before merge; 5–10 are notes.

VERDICT: CHANGES-REQUIRED
