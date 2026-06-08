# Opus Adversarial Review — Task 14 (Frontend: Location form field + conditional-required hint)

**Plan:** `docs/superpowers/plans/2026-06-04-branch-tax-id-P0.md` · Task 14
**Spec:** `docs/superpowers/specs/2026-06-04-branch-tax-id-design.md` (rev 2)
**Commit reviewed:** `79caf5ee3` — *feat(branch-tax-id): Location settings form captures branch tax id*
**Reviewer:** Opus (adversarial)
**Date:** 2026-06-05

## Scope of diff
- `apps/web/src/features/settings/LocationsPage.tsx` — `taxId`/`vatNumber` added to `LocationFormData` + `emptyForm`; edit-prefill; create/update submit mapping; two new labeled inputs (Tax ID + VAT Number) with a conditional required hint; `BRANCH_TAX_REQUIRED_COUNTRIES` constant.
- `apps/web/src/features/settings/__tests__/LocationsPage.tenantScope.test.tsx` — fixture extended with `tax_id/vat_number/legal_identifiers`; new test renders the fields, asserts shop+FR marks Tax ID required, and asserts submit payload carries `taxId/vatNumber`.
- `apps/web/src/locales/{en,fr,ar}/common.json` — `locations.form.taxId`, `taxIdRequiredHint`, `vatNumber` keys.

## Verification performed (against real code)
- **Design tokens exist & are used:** `tokens.label.base`, `tokens.input.base`, `tokens.helperText.base` all present in `lib/designTokens.ts:259-270`. New markup uses tokens exclusively — **no hardcoded Tailwind colors** introduced. The pre-existing `typeColors` map (`bg-orange-100 …`) is untouched (context only), consistent with convention #18 (migrate only touched lines). The diff adds the `tokens` import. ✓
- **i18n keys resolve:** all three keys nest under `locations.form` (en `common.json:292-307`, fr, ar). No new namespace → no `i18n.ts` change required; all three locale files updated, including RTL Arabic. **No hardcoded user-facing strings** — every label/hint via `t()`. ✓
- **Submit flows through the camel→snake mapper:** form sets `createData.taxId` / `updateData.taxId`; `location/api.ts:132-134,156-157` map `taxId→tax_id`, `vatNumber→vat_number`. `CreateLocationInput`/`UpdateLocationInput` carry `taxId?`/`vatNumber?` (Task 13, `api.ts:42-43,61-62`). Fields are not silently dropped. ✓
- **Required-hint mirrors server:** `BRANCH_TAX_REQUIRED_COUNTRIES = {FR,TN,MA}` matches the backend config set (plan Task 4: FR/TN/MA `branch_tax_id_required=true`) and the spec (§ line 103: "Initial: FR/TN/MA structural + required"). Hint copy ("France, Tunisia, and Morocco") matches. ✓
- **Hint is gating logic only, not enforcement:** `isTaxIdRequiredHint = type==='shop' && set.has(country.trim().toUpperCase())`. Default `emptyForm.type='shop'`, so a new FR location shows the marker — matching the test. Submission is **not** blocked client-side, per spec ("the server is the enforcement"). ✓
- **Accessibility:** `aria-required` + `aria-describedby` wired to the helper `<p id="taxId-required-hint">`. Label `htmlFor`/input `id` associated. ✓
- **Strict typing:** TS, no `any`; `Record<string, unknown>` upstream. No `app()`/`mixed` (frontend). ✓
- **Fiscal payload:** untouched — Phase-1 web task, no SALE_RECEIPT/version surface. ✓
- **Edit prefill:** `startEdit` populates `taxId`/`vatNumber` from the loaded location (`LocationsPage.tsx:151-152`). ✓

## Findings

### BLOCKER
None.

### MAJOR
None.

### MINOR
1. **Clearing a branch tax_id back to "inherit" is not reachable through the UI.**
   `LocationsPage.tsx:180-181,200-201` — `if (formData.taxId) updateData.taxId = …` omits the field when the input is emptied, so a user cannot null out a previously-set `tax_id`/`vat_number` to fall back to the company value; the old value persists. This matches the spec's supported "clear → inherit" path (Task 6 `test_accepts_clearing_tax_id_to_inherit`) only at the API layer, not the UI. **However** this is the *identical* truthy-guard pattern used by every other optional field in this form (`addressCity`, `addressCountry`, …), so it is a pre-existing form-wide limitation, not a regression introduced here, and is arguably outside Task 14's "capture" scope. Recommend a follow-up to send `taxId: ''`/`null` explicitly when a populated field is cleared on edit.

2. **`BRANCH_TAX_REQUIRED_COUNTRIES` duplicates backend config (drift risk).**
   `LocationsPage.tsx:29` hardcodes `{FR,TN,MA}` independently of `config/tax_identity.php`. If the server set changes, the client hint silently drifts. Plan-sanctioned ("keep client-side as a hint … a small constant"), so acceptable for P0, but worth a comment pointing at the server source of truth.

### NIT
3. **TDD red-first not provable from a single commit** — test + implementation landed together (`79caf5ee3`). The test is meaningful and would fail without the impl, but the red→green sequence isn't independently verifiable from history.
4. **`legalIdentifiers` not captured in the form** — only `taxId`/`vatNumber` are exposed. This matches the plan's Task 14 scope (form adds `taxId`, `vatNumber` only); `legal_identifiers` remains API/resolver-level. Noted for completeness, not a defect.
5. **Required marker rendered as plain `' *'` text** rather than the existing `tokens.label.required` ('text-red-500') span — purely cosmetic and consistent with the sibling `name` field (`:347`).

## Conclusion
The task is implemented cleanly and faithfully to the plan and spec: design tokens throughout, full i18n (en/fr/ar) with no hardcoded strings, correct camel→snake submit mapping, a server-mirrored conditional-required hint with proper ARIA, and a meaningful component test covering render + required-marker + submit payload. No correctness, typing, fiscal, or convention violations. The MINOR items (clear-to-inherit gap, hardcoded country set) are advisory follow-ups, both consistent with existing form-wide patterns and within the plan's stated approach.

VERDICT: APPROVE
