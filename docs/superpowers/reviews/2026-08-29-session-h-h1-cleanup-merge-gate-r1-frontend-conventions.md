<!-- frontend-conventions-reviewer (Claude), merge-gate round 1, lane fix/h1-shape-neutral-cleanup @ 6bb800455, dispatched by Session H 2026-08-29 -->

# Merge-Gate Register — Session H lane `fix/h1-shape-neutral-cleanup`

**Branch:** `fix/h1-shape-neutral-cleanup`
**HEAD:** `6bb80045568d8045b1d7380fc233ea70c47486b2`
**Range:** `dev...HEAD` (merge-base = `cdce47570` = local `dev` tip; `dev` is an ancestor of HEAD — a true fast-forwardable diff, 37 lane commits + 1 reconcile merge, 75 files)
**Lens:** frontend-conventions + cross-cutting (09/10/11). Read-only; no PHPUnit/PG legs run; no full `pnpm lint`.

---

## Findings

### MAJOR

**1. Tax-status / exemption / certificate controls are dead on the write path — and M1 just made the silent revert visible.**
`apps/web/src/features/partners/PartnerForm.tsx:635-693` renders a **Tax Status** select, an **Exemption Reason** textarea, a **Valid Until** date input and a certificate `<input type="file">` (no `onChange` at all, `:685-691`), and `onSubmit` posts `tax_status` / `exemption_reason` / `exemption_valid_until` (`:443-444`). Neither `apps/api/.../Requests/CreatePartnerRequest.php` nor `UpdatePartnerRequest.php` declares a rule for any of those three keys (verified by grep — zero hits), and both `PartnerController::store` (`:208-219`) and `::update` (`:280-296`) spread `$request->validated()`. The keys are stripped; the user sees a success toast and nothing persists.
Before this diff the round-trip was invisible: `/partners/{id}` did not return `tax_status`, so `reset({tax_status: partner.tax_status})` got `undefined` and the select kept whatever the user last picked. M1 added `tax_status` / `exemption_reason` / `exemption_valid_until` to the DTO (`PartnerData.php:39-41,94-96`, `generated.d.ts:1852-1854`), so the form now **reloads the unchanged server value and visibly reverts the user's edit**. This is OQ-11 (dead controls are HIDDEN, not shipped) plus "UI must not overstate system guarantees", and it is not in `owes_parent`.
**Fix:** either add `tax_status` / `exemption_reason` / `exemption_valid_until` to both FormRequests (with the `EXEMPT`-conditional rules the domain already models in `Partner.php:375,399`), or hide the whole Tax Status / exemption / certificate block until the write path exists. Do not ship the read-only half.

**2. `AddPartnerModal` "Country" field is mislabelled and the address country is now silently never captured.**
`apps/web/src/components/organisms/AddPartnerModal/AddPartnerModal.tsx:353-370` — `label={t('sales:partners.country')}` (= "Country" / "Pays", the **address** country, `PartnerForm.tsx:771`) sits over a control registered to `country_code` (= "Country (VAT)" / "Pays (TVA)", `PartnerForm.tsx:599`), with the VAT-flavoured placeholder `partners.selectCountryCode` ("Select country for VAT"). The field is physically positioned in the address group, right after postal code (`:340-350`). The modal's own test now asserts `expect(payload).not.toHaveProperty('country')` (`AddPartnerModal.test.tsx`), i.e. the address country is deliberately never sent, while `CreatePartnerRequest.php:133` still accepts `country`. Two glossary-distinct nouns (address country vs tax country) collapsed onto one control under the wrong name — convention 11.
**Fix:** label the control `sales:partners.countryCode` (and keep the VAT placeholder), or register it to `country` and use a neutral placeholder. Pick one noun and name it correctly.

**3. `a3`'s "consume the generated `PartnerData`" was not completed — a DTO-shadowing `interface Partner` survives on exactly the fields M1 added.**
`apps/web/src/features/documents/components/TaxExemptionNotice.tsx:11-18` hand-rolls `interface Partner { id; name; tax_status: 'REGISTERED'|'NON_REGISTERED'|'EXEMPT'; exemption_reason?; exemption_certificate_path?; exemption_valid_until? }` — a literal duplicate of the three fields `PartnerData.php:39-41` just gained, including a field (`exemption_certificate_path`) the DTO does **not** expose. This is the named lens target and it is not in `owes_parent`. (`PaymentForm.tsx:54-57` and `PartnerPicker.tsx:53-59` are narrow `{id,name,…}` row projections, not DTO shadows — acceptable.)
**Fix:** replace with `Pick<PartnerData, 'id'|'name'|'tax_status'|'exemption_reason'|'exemption_valid_until'>` from `features/partners/types`, or add the item to `owes_parent` with a reason.

**4. M3 hardened the partner *edit* gates but left the *create* siblings on a role-name alias that diverges from the backend permission.**
`apps/web/src/routes/index.tsx:604-612` gates `/sales/customers/new` on `permission="sales.create"` and `:852-861` gates `/purchases/suppliers/new` on `purchases.create`. Both are **UI aliases resolved by role name** — `apps/web/src/hooks/uiAliasPermissions.ts:5,7` → `['admin','sales','manager']` / `['admin','purchases','manager']` — while the backend enforces `can:partners.create` (`apps/api/app/Modules/Partner/routes.php:42-43`), granted to `['admin','cashier','manager','operator']` (`permissionsMap.generated.ts:149`). Consequences: (a) `cashier` and `operator` hold the real backend permission but are bounced to `/dashboard` by the FE — the exact tenant-#1 POS persona that creates customers; (b) the alias roles `sales` / `purchases` do not exist in the generated role set at all. This is the `sales.create`-alias-vs-`can:…` class named in my standing checks, sitting three lines from the routes M3 corrected.
**Fix:** `permission="partners.create"` on both create routes (keeping `moduleKey` where present), and add a `PartnerRoutes.gates.test.tsx` case for a `partners.create`-only actor.

**5. Arabic **Nature** label is byte-identical to the adjacent **Type** label on the same required-field row.**
`apps/web/src/locales/ar/sales.json:70` `"type": "النوع"` and `:99` `"nature": {"label": "النوع"}` — `PartnerForm.tsx:519` and `:542` render both selects side-by-side in the same grid, both required on create. AR operators see two mandatory selects with the identical label. *Declared* in `docs/handoff/progress/session-h-phase1.progress.yaml` `owes_parent` (blocked on owner copy approval), so this is a parent/owner decision, not a lane defect — but it ships to AR users at promotion.
**Fix directive for the parent:** obtain the AR copy ruling before promoting to any AR-locale tenant, or temporarily use a distinct AR string (e.g. `طبيعة الشريك`, already the placeholder at `:100`).

---

### MINOR

**6.** `/crm/companies` was deleted outright (`routes/index.tsx`, ex-`:2782-2795`) rather than replaced by a redirect, so existing bookmarks now fall through to the dashboard instead of `/sales/customers` — the retired page's whole prior job. Pinned as such by `PartnerRoutes.gates.test.tsx:100-106`. **Fix:** `<Route path="companies" element={<Navigate to="/sales/customers" replace />} />`.

**7.** Gate asymmetry: the supplier list gained `permission="partners.view"` (`routes/index.tsx:846`) but the customer list (`:594-601`) and both detail routes (`:614-621`, `:863-871`) remain `moduleKey`-only. Fail-closed at the API (`can:partners.view`), so cosmetic — but inconsistent with the composition M3 just established. **Fix:** compose `partners.view` onto the customer list/detail routes too.

**8.** `apps/web/src/test/sessionHM3BrowserGateSource.test.ts:6-17` asserts the **source text** of an e2e spec (`expect(specSource).not.toMatch(/page\.route\(.*auth\/me/)`, `.toContain('loggedPage(browser, restrictedCredentials!)')`). This is a shape assertion, not a data-meaning test; it breaks on any harmless refactor of the spec and reads via `process.cwd()`. **Fix:** delete, or convert to an ESLint restriction on the e2e directory.

**9.** `apps/web/src/test/sessionHLoginHelper.test.ts:1-8` imports `../../e2e/session-h/helpers`, which imports `@playwright/test` (`e2e/session-h/helpers.ts:1`), pulling the Playwright runtime into the Vitest worker. **Fix:** extract the pure helpers (`loginRequestData`, `classifyOtospexAuthentication`, `requireOtospex*`) into a Playwright-free module both sides import.

**10.** `apps/web/e2e/session-h/m3-dead-crm-partner-vehicle-gates.spec.ts:232-253` shells `php artisan tinker --execute=<base64-embedded script>` from a Playwright spec to rewrite a user's password hash. Injection-safe (base64 alphabet), env-guarded to `local`/`testing`, and **not CI-wired** (CI runs only `playwright.smoke.config.ts`, `.github/workflows/smoke-test.yml:53`). Still a committed credential-rewrite path in the FE e2e suite. **Fix:** move the restricted-actor fixture into a seeder/artisan command owned by `apps/api`.

**11.** `apps/web/src/features/partners/__fixtures__/partner.ts:12-16` — doc comment still says "Mirrors the `Partner` interface declared in `PartnerListPage.tsx`" (that interface was deleted in this diff), and it re-declares `export type PartnerData = App.Modules…PartnerData` beside the new `features/partners/types.ts:1`. Two aliases for one concept. **Fix:** import from `../types` and update the comment.

**12.** `PartnerForm.tsx:71` / `:190` hand-roll `'REGISTERED' | 'NON_REGISTERED' | 'EXEMPT'` beside the generated `App.Modules.Taxation.Domain.Enums.PartnerTaxStatus`. **Fix:** `tax_status: PartnerData['tax_status']` / `z.enum` derived from it.

**13.** `PartnerData.php:94` `$partner->tax_status ?? PartnerTaxStatus::REGISTERED` contradicts the model's declared `@property PartnerTaxStatus $tax_status` (`Partner.php:53`, column NOT NULL DEFAULT `'REGISTERED'`, migration `2026_01_02_100001_add_tax_exemption_to_partners.php:19`). The inline comment justifies it (un-refreshed post-INSERT model) and it is harmless because no request can set `tax_status`, but the null branch is unreachable on any read. **Fix:** `$partner->refresh()` after create, or annotate the property nullable.

**14.** Partner-code uniqueness validation excludes soft-deleted rows (`CreatePartnerRequest.php:78`, `UpdatePartnerRequest.php:95`) while the DB unique index `(company_id, code)` does not (`2025_12_30_195300_fix_multi_company_unique_constraints.php:21-22`) → reusing a soft-deleted partner's code passes validation and raises a 23505 at insert. Pre-existing shape (unchanged by the scope flip), but the sibling `vat_number` rule *does* carry a `vat_held_by_deleted_partner` message (`CreatePartnerRequest.php:116-121`) and `code` has no equivalent. **Fix:** mirror that message for `code.unique`, or make the index partial on `deleted_at IS NULL`.

**15.** `showB2BFields` (`PartnerForm.tsx:259-265`) is computed from live `watch()` values, three of which live *inside* the section it gates, so clearing the last signal unmounts the focused input; scan-prefilled VAT also reveals B2B on a create form before Nature is chosen. *Declared* in `owes_parent` (M2 P3 edges), no data loss. Noted, not blocking.

**16.** `packages/shared/types/generated.d.ts` picked up five enums unrelated to this lane (`:1197 OpeningLotExpiryOutcome`, `:1811 ShiftCashMovementSource`, `:2562 CashTenderInvariantRefusalCode`, `:2585 RepositoryWriteRefusal`, `:3148 ProductTaxDefaultSource`) — evidence `dev`'s generated file was stale, not hand-editing. Flagged by the M1 register too. Parent should expect merge overlap on this file.

**17.** `PartnerPicker.tsx:215-221` renders the selected-state label as `<span id>` + `role="group"`/`aria-labelledby` rather than a real `<label htmlFor>`; and the `effectiveLabel !== ''` guard is unreachable-false because `effectiveLabel = label ?? t('partner.label')` (`:204`) is never empty.

**18.** `AddPartnerModal.tsx:358-361` mixes `{...register('country_code')}` with a controlled `value={countryCode}` from `watch` — redundant, works only because `register` supplies `onChange`. **Fix:** drop the `value` prop.

---

## Verified OK

- **a8 blast radius is exactly as briefed.** `git diff dev...HEAD -- apps/pos` touches only `pendingCustomerCreateService.ts:45` and `CustomerAttachPanel.tsx:163` (+ their two tests). `apps/pos/src/lib/fiscal/` is **empty in the diff** — `AccountPaymentPayload.ts:116` still carries its `customer_category: 'retail'` golden literal, untouched. `null` is legal for the sealed contract: `FiscalEventEngine.ts:2268,2813` use `assertOptionalNonEmptyStringAt`, and `accountChargeService.ts:378` branches on `=== 'business'`, so behaviour is unchanged.
- **Generated `PartnerData` consumed** in `PartnerListPage.tsx:24`, `PartnerForm.tsx:35,281,397,410`, `AddPartnerModal.tsx:19,211`, `VehicleForm.tsx` (hand-rolled `Partner`/`PartnersResponse` deleted), `__fixtures__/partner.ts`. All four hand-rolled interfaces named in the brief are gone; `PartnerListPage.tsx:378` now reads the real `vat_number` instead of the non-existent `tax_id`. No `as any` / `as unknown as` / `@ts-` escape in the fixture.
- **Second-of-everything (convention 09):** second company — `CreatePartnerTest.php:206` (both companies create `SHARED-001` with independent `customer_category`, list scoped to one) and `UpdatePartnerTest.php:172` (cross-company reuse allowed, cross-company update 404, both DB rows asserted); re-run/idempotency — `UpdatePartnerTest.php:128` (retains own code) and `partnerNature.test.ts:5-18` (pure/idempotent on a legacy row). Second-location: N/A, `partners` is company-scoped not location-scoped. **No new `unique(['tenant_id', …])`** is introduced; the FormRequest flip from `tenant_id` → `company_id` *aligns validation to the existing DB index* `(company_id, code)` and to G-3a's `(company_id, vat_number)` — it loosens nothing the DB enforces.
- **i18n:** `sales:partners.nature.{label,selectPlaceholder,individual,company}` and `validation.natureRequired` present in `en`/`fr`/`ar`; `pickers:common.clearField` present in all three; `sales:partners.b2b.creditLimit*` added to `ar`. Four orphaned `partners.b2b.customerCategory|selectCategory|individual|business` keys removed from `en`+`fr` with zero remaining references. `sales:partners.selectCountryCode` exists in all three (`en:46`, `fr:46`, `ar:86`). Removed keys (`common.navigation.companies`, `vehicles.noOwner`, `crm.companies.*`) have no surviving callers. No hardcoded user-facing string in any added line.
- **Money/quantity (rule 19):** zero `parseFloat` / `Number(` / `toLocaleString` in any added line across `apps/web/src` + `apps/pos/src`. `CreditLimitWarning.tsx:25-34` is a genuine conversion to `bccomp`/`bcdiv`/`bcmul` + `formatCurrency(value, true, currency)` (signature confirmed at `lib/decimal.ts:179-183`); division-by-zero unreachable behind the `bccomp(creditLimit,'0') <= 0` guard. `getCustomerCreditExposure` (`partnerNetBalance.ts:11-15`) is `bcsub` and `getNetBalance` is behaviour-identical after the extraction.
- **Design tokens:** zero raw Tailwind colour classes in added lines; token references are whole-class substitutions only — **no** `hover:${token}` / `${token}/50` variant-or-opacity interpolation anywhere in the diff.
- **`tenantScopedKey`:** new/changed keys all scoped — `AddPartnerModal.tsx:135`, `PartnerPicker.tsx:124,146`, `PartnerForm.tsx:269,279`. `VehicleForm` removed an unfiltered `/partners` query outright. `partnerDetailInvalidationPredicate` (`_invalidation.ts:9-26`) requires `length>=4` + tenant/company at the suffix and matches every partner-detail cache in the app (`PartnerForm.tsx:279`, `PartnerDetailPage.tsx:173`, `PartnerPicker.tsx:146`).
- **api unwrap:** `api.get<{data: PartnerData}>` + `response.data.data` for raw axios (`PartnerForm.tsx:281`, `PartnerPicker.tsx:141,150`); `apiPost<PartnerData>` consumed single-unwrapped (`AddPartnerModal.tsx:211-214`, `PartnerForm.tsx:397`). No double-unwrap.
- **RHF+zod:** `PartnerForm` moved from ad-hoc `register(..., {required})` to a real `zodResolver` schema with translated messages (`:161-206`) and inline `FormField error=` slots. Schema/`PartnerFormData` key parity is **25/25** and `bank_accounts` **9/9** — I re-enumerated both sides independently; no field can be silently stripped by zod's default strip, and every key is supplied by both `defaultValues` (`:219-247`) and `reset` (`:340-376`), so no unrenderable resolver error can block submit. `customer_category` is accepted by both FormRequests (`CreatePartnerRequest.php:71`, `UpdatePartnerRequest.php:140`) — Nature is a live control.
- **M2 B2B heuristic is a pure function of the row:** `partnerNature.ts:12-22` reads only its argument, no closure/IO. `credit_limit` is `nullable` with **no** DB default (`2026_03_11_600000_add_b2b_fields_to_partners.php:18`), so the heuristic does not misfire on every legacy row.
- **Route gates:** `partners.view` / `partners.update` are real generated permissions (`permissionsMap.generated.ts:151-152`) with backend parity (`Partner/routes.php:23,27,47`); `RequirePermission` composes `moduleKey` AND `permission` (`RequirePermission.tsx:49-58`); `PartnerRoutes.gates.test.tsx:39-113` denies each leg independently (non-vacuous). No `canAccessModule` with an unknown key introduced. Picker `type=customer` includes `Both` partners (`PartnerController.php:74`), so vehicle owners of type `both` remain selectable.
- **Playwright specs:** all three carry `test.use({ baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:5174' })` (`m1:6`, `m2:6`, `m3:27`) and use demo creds `owner@pharmabio.tn` / `password` (`helpers.ts:4-7`); `API_BASE` is env-overridable (`helpers.ts:3`). Not wired into CI.
- **ESLint (re-run by me):** `pnpm exec eslint src/features/partners src/features/vehicles/VehicleForm.tsx src/components/organisms/AddPartnerModal src/components/molecules/pickers/PartnerPicker.tsx src/routes/index.tsx` → **28 problems, 0 errors**. No `precision/no-parsefloat-on-money`, no `no-hardcoded-step` on lane-authored code.
- **Mechanism audit:** no alias table re-exporting tokens, no suppression comment containing a detector keyword, no baseline file touched (`tools/audit-design-system-baseline.json` is not in the diff), no `--write-baseline`. The only ratchet movement is an i18n *burn-down* of eight pre-existing entries via key removal, honestly recorded in `owes_parent`.
- **`owes_parent` honesty:** ten entries in `session-h-phase1.progress.yaml:76-86` genuinely correspond to gaps I independently confirmed in code (AddPartnerModal NULL nature, supplier credit semantics, AR label collision, 422→201 client contract, vehicle enum drift, Otospex browser skip, form-context collapse). Milestone rows are re-pinned to real commits with real verdict paths.

---

## Conditions on ACCEPT

Must land in this lane, or be written into `owes_parent` with an owner-visible reason before promotion:
1. Finding **1** — hide the Tax Status / exemption / certificate block, or wire its FormRequest rules. (Not currently declared anywhere.)
2. Finding **2** — correct the `AddPartnerModal` country label/field pairing (one-line).
3. Finding **3** — convert `TaxExemptionNotice`'s shadow interface, or declare it.
4. Finding **4** — `partners.create` on the two create routes, or declare the alias divergence.
5. Finding **5** — parent must carry the AR copy decision to the owner before any AR-locale rollout.

Not verified by me (delegated per brief): `pnpm typecheck`, `pnpm vitest`, PHPUnit/PG legs, PHPStan, and live Playwright runs. Finding 13 in particular would only surface under a live-DB PHPStan run.

VERDICT: ACCEPT-WITH-CONDITIONS
