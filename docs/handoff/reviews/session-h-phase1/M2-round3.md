# Adversarial merge-gate register — Session H Phase 1, lane `h1-cleanup`, **M2** (round 3)

**Diff reviewed:** `ed9f69551..d9d36679d` (7 commits, 20 files) — brief §2 **M2 (`a4′`)**, lens **frontend-conventions**. No amending ruling supplied. Prior registers: `M2-round1.md` (13 findings), `M2-round2.md` (7 findings, 1 blocking).

**Independent verification I ran** (worktree `.worktrees/h1-cleanup/apps/web`, not taken from the report):
- `pnpm exec tsc --noEmit` → exit 0
- `pnpm exec vitest run src/features/partners` → **12 files / 127 tests passed**
- `pnpm exec eslint src/features/partners` → exit 0, 21 warnings, **0 errors**; no `precision/no-parsefloat-on-money`, no `no-hardcoded-step` on anything M2 authored (the surviving `step="0.01"` is `discount_percentage`, a percent, pre-existing — `B2BFieldsSection.tsx:199`)
- `pnpm audit:i18n:local` → exit 0 (8 baseline entries burned down, 2755 known gaps held at baseline) · `pnpm audit:keys` → Gate C 0 new / 0 stale
- Read `.playwright-mcp/session-h/m2/m2-legacy-company-visible.png` directly (976×1739): Nature = *"Select nature"* (legacy NULL), `Matricule fiscal FR52814777264`, **B2B Information** rendered below General Information. The screenshot genuinely evidences the branch it claims.
- `git status --porcelain` → clean; `git diff --stat` → `apps/web/src`, `apps/web/e2e`, `docs/` only.

**Round-2 disposition, verified against code rather than the report:**
- #1 (both-role payables silencing the alert) — **fixed**. `PartnerForm.tsx:702` now passes `getCustomerCreditExposure(partner)`; the helper is `bcsub(receivable_balance ?? '0', credit_balance ?? '0')` at `partnerNetBalance.ts:11-15`, and `getNetBalance` (`:17-34`) is behaviour-identical to before the extraction. Non-vacuously pinned by `PartnerForm.test.tsx` *"does not subtract supplier payables from a both-role partner credit exposure"* (fixture `receivable 5000.000 / credit 0.000 / payable 4900.000 / net_balance 100.000`, asserts `Credit limit exceeded` + `5 000,00 EUR`) — the pre-fix code returned `net_balance` and rendered nothing.
- #2 (AR `النوع` label collision) — **escalated**, `session-h-phase1.progress.yaml:74`.
- #3 (`AddPartnerModal` unguarded create surface) — **escalated**, `progress.yaml:75`.
- #4 (100 % on a below-limit balance) — **fixed** by truncation at `CreditLimitWarning.tsx:30` (`usagePercentage.split('.', 1)[0]`), pinned by `CreditLimitWarning.test.tsx:61-73` (`999.500/1000.000` → `99% used`, `not 100% used`).
- #5 (four orphaned `partners.b2b.*` keys) — **fixed**; `customerCategory`/`selectCategory`/`individual`/`business` are gone from `en`/`fr` (AR never had them) and `grep` across `src` + `e2e` finds zero remaining references.
- #6, #7 — notes, unchanged.

---

### 1. **P3 — CONFIRMED** — on the supplier route the newly-wired credit-limit control can never fire
`apps/web/src/features/partners/PartnerForm.tsx:702` · `apps/web/src/features/partners/components/B2BFieldsSection.tsx:169-183` · `apps/web/src/features/partners/components/CreditLimitWarning.tsx:25-27`

`outstandingBalance` is `getCustomerCreditExposure(partner)` = `receivable_balance − credit_balance`, passed unconditionally — including on `/purchases/suppliers/:id/edit`, where the B2B block and its **Credit Limit** field render whenever Nature is *Company* (which M2 now defaults for supplier creates, `PartnerForm.tsx:221`).

**Failure scenario:** supplier `type='supplier'`, `credit_limit='5000.000'`, `payable_balance='7000.000'`, `receivable_balance='0.000'`. Exposure is `0.000` → the `bccomp(outstandingBalance,'0') <= 0` guard returns `null`, so the operator editing that supplier sees a Credit Limit of 5 000 with no alert while we are 2 000 past it. Not a regression (the component had zero importers before M2) and fail-*silent* rather than false-positive; the brief's M2 wording covers only the customer credit case, and round 2 explicitly directed customer-only exposure. Belongs in `owes_parent`/Phase 2 alongside the existing supplier-side gaps, not in this milestone.

### 2. **P3 — CONFIRMED** — the NULL heuristic watches three fields that live *inside* the section it gates, so the section can unmount itself mid-edit
`apps/web/src/features/partners/PartnerForm.tsx:251-265` (`hasLegacyB2BSignal` over `vat_number`, `company_legal_name`, `business_registration_number`, `credit_limit`) · `B2BFieldsSection.tsx` renders `company_legal_name`, `business_registration_number` and `credit_limit`.

**Failure scenario:** legacy partner with `customer_category = NULL` whose only B2B signal is `company_legal_name`. The user opens the edit form, selects the legal-name field and clears it to retype — on the last keystroke `hasLegacyB2BSignal` flips false, `showB2BFields` flips false, and the whole B2B section (including the focused input and the bank-accounts sub-form) unmounts. `vat_number` is unaffected because it lives in the General/tax block. Cosmetic — RHF retains the values (`shouldUnregister` defaults false) so nothing is lost on save, and re-selecting *Company* restores the block.

### 3. **P3 — CONFIRMED, carried forward and accepted** — hidden-but-submitted B2B values
`apps/web/src/features/partners/PartnerForm.tsx:427-450`. Switching a legacy NULL partner to *Individual* hides the block, but `onSubmit` still posts `vat_number` / `credit_limit` / `company_legal_name` / `business_registration_number` from retained form state. Identical to the pre-M2 `business → individual` behaviour, so not introduced here; no data loss, and clearing the values is still possible before switching. Round-1 #8 reached the same conclusion.

### 4. **P3 — CONFIRMED** — a create form can show the B2B block before the now-required Nature is chosen
`apps/web/src/features/partners/PartnerForm.tsx:296-308` (`readPartnerPrefill` → `setValue('vat_number', …)`) feeding `:257-265`.

**Failure scenario:** the scan-to-document flow lands on `/sales/customers/new` with a prefilled VAT number and `customer_category` still `null` → `hasLegacyB2BSignal` is true → **B2B Information** renders on a *create* form whose Nature is blank and required; picking *Individual* then collapses it. The heuristic was specified for legacy rows, and applying it to `null` on create is a literal reading of the brief; purely cosmetic.

### 5. **P3 — CONFIRMED** — the progress YAML pins a commit one behind `HEAD`
`docs/handoff/progress/session-h-phase1.progress.yaml:41` records `commit: db3739c6593a89746dbfa3462683154fca8cfb94`, but `HEAD` is `d9d36679d` (`M2.7`, docs-only: this YAML block + the round-2 register). No code drift — `git show --stat HEAD` touches only `docs/`. Traceability only; the parent should re-pin to the accepted `HEAD` when it stamps this verdict.

---

## Bypasses I tried that FAILED (no defect found)

- **Silently drop a field from the POST via zod's default strip:** with a resolver present, `handleSubmit` submits the resolver's parsed output, so any key missing from `partnerFormSchema` would vanish from the payload. Enumerated both sides — `PartnerFormData` (`PartnerForm.tsx:50-76`) and the schema (`:160-206`) are **25/25 exact**; the `bank_accounts` item is **9/9** against `PartnerBankAccountFormData` (`:38-48`) with `id` correctly `.optional()`.
- **Silently block submit with an unrenderable resolver error** (the sharpest new risk from adding `zodResolver`, since `bank_accounts` / `tax_status` errors have no visible slot). Chased every field `reset()` writes without a `??` fallback back to the server contract: `PartnerBankAccountData.php:25,26` declare `public string $currency` / `public bool $is_primary` (migration `2026_07_12_120000_create_partner_bank_accounts_table.php:27` is `string('currency', 3)` NOT NULL); `PartnerData.php:24,39,53` declare `PartnerType $type`, `PartnerTaxStatus $tax_status` (with an explicit `?? REGISTERED` mirror at `:94`) and `bool $is_active` — all non-nullable. `useFieldArray` append (`PartnerBankAccountsSection.tsx:182-191`) supplies all eight required keys. No input can reach the resolver as `null`.
- **Submit a create with no Nature:** untouched → `null`, blank option → `''`; the refine at `PartnerForm.tsx:165-168` rejects both, `mockApiPost` is never called, and the e2e asserts `createRequestCount === 0` (`m2-partner-nature.spec.ts:120`).
- **Break the invalidation narrowing:** `partnerDetailInvalidationPredicate` (`_invalidation.ts:9-26`) requires `length >= 4` + tenant/company at the suffix, which is *narrower* than the old `queryKey: ['partner', id]` prefix match. Grepped every partner-detail **query** in the app — `PartnerForm.tsx:279`, `PartnerDetailPage.tsx:173`, `PartnerPicker.tsx:145` — all are `tenantScopedKey(['partner', id])` → length 4 → all still matched. The length-2 keys at `RecordDepositModal.tsx:105`, `usePartnerBalanceRealtime.ts:40`, `AddVehicleModal.tsx:159` are *invalidators*, not caches. No cache is orphaned.
- **Rule 19 on the rewritten warning:** `bccomp`/`bcdiv`/`bcmul` on raw strings for both comparisons (`CreditLimitWarning.tsx:29-34`); display goes through `formatCurrency` → `formatDecimalAmount` (`lib/format.ts:81-96`), which is `safeBig().toFixed(decimals)` + manual grouping — no `Number`/`parseFloat` anywhere on the path. `String(thresholdPercentage)` is a percent, explicitly outside the currency-scale rule. Division by zero is unreachable behind the `bccomp(creditLimit,'0') <= 0` guard; `'1.2.3'`/`'abc'` coerce to `0` via `safeBig` (`decimal.ts:30-37`) and trip the same guard.
- **Prove the credit tests are vacuous** (i.e. the detail endpoint never returns balances): `PartnerData.php:43,98` exposes `receivable_balance` from the model, cast `decimal:3` (`Partner.php:161`), and the generated contract declares it (`generated.d.ts:1856-1859`). The fixtures mirror the real payload.
- **Duplicate-key shadowing in `ar/sales.json`:** only one `partners.b2b` block exists (`:125`); `json.load` round-trips all three locales and `partners.nature` is present in `en`/`fr`/`ar`.
- **Trip the i18n removal ratchet with the four deleted EN/FR keys:** `audit:i18n:local` exits 0 and reports a burn-down, not a regression. (`audit:i18n` fails closed locally because `I18N_BASELINE_PROTECTED_BLOB` is an owner-set repo variable — expected, not a lane defect.)
- **Shape-neutrality (§1):** the entire diff is `apps/web/src` + `apps/web/e2e` + `docs/`. No migration, DTO, enum, FormRequest, queue, or sealed-payload change. Tenancy, constructor-injection, Horizon-queue and migration standing checks do not apply to this diff.

## Verification I could NOT perform
The Playwright gate could not be re-executed: **:5174 and :5173 are both down** (`curl` → `000`; only `:8010`/`:8011` answer `200`), and test 1 mutates the demo tenant, which is out of bounds for a read-only review. The browser gate is accepted on the committed spec (`m2-partner-nature.spec.ts`, three cases + `afterEach` `DELETE → 204` cleanup) and on the `m2-legacy-company-visible.png` I read directly and which does show the asserted branch.

## Gate summary
Round 2's single blocking finding is genuinely fixed in code, with a mutation-checked test that the pre-fix implementation provably could not pass; the two escalation items are written to `owes_parent`; the two P3 fixes (truncation, orphaned keys) are real. M2's own named acceptance criteria — Nature required on create with EN/FR/AR copy, `business` default on supplier create only, values still `individual|business`, edit form permissive for legacy NULL, the four-signal NULL heuristic, `CreditLimitWarning` wired *after* a real rule-19 conversion with all three named boundary cases, and the three-case browser gate with screenshots — are all present and non-vacuous. Nothing remaining is blocking: the five findings above are one Phase-2 scope gap, three cosmetic edges, and one YAML pin.

VERDICT: ACCEPT
