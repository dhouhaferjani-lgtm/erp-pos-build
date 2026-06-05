# Opus Adversarial Review — Task 17 (Device seller sourcing: prefer branch tax id)

**Branch:** `feat/branch-tax-id-spec`
**Commit reviewed:** `762476d16` — *feat(branch-tax-id): POS fiscal sellers prefer branch tax id*
**Plan:** `docs/superpowers/plans/2026-06-04-branch-tax-id-P0.md` §Task 17
**Spec:** `docs/superpowers/specs/2026-06-04-branch-tax-id-design.md` (D6, §7, §5)
**Reviewer scope:** the Task 17 diff only — `apps/pos/src/stores/paymentStore.ts` (+13/-2) and the new `apps/pos/src/stores/__tests__/paymentStore.branchSeller.test.ts` (+228).

> Note: the supplied `/tmp/branch-tax-id-task-17.diff` is outside the session's allowed roots and was unreadable. I reconstructed the diff from commit `762476d16` (matches the Task 17 file list exactly) and verified every claim against the live working tree.

---

## Verdict summary

The implementation is **correct and shippable**. Both live device seller-authoring sites (SALE_RECEIPT, ACCOUNT_PAYMENT) now prefer the branch tax id with company fallback, exactly as spec §7 / D6 prescribe; it is a pure value-source change with no fiscal payload schema/version touched; ACCOUNT_CHARGE is correctly left untouched (fixture-only, no live caller). The one substantive gap is in **test coverage**, not in behavior: the company-fallback path — the most common production path for branches with no override — is never asserted.

---

## What was verified against real code

| Check | Result |
|---|---|
| Both live seller blocks patched (`paymentStore.ts:553`, `:665`) | ✅ Exactly two `taxNumber:` sites in the file; both use `branchTaxNumber ?? companyField(...)`. |
| Fallback direction is **branch → company** (not reversed) | ✅ `branchTaxNumber ?? companyField(company,'taxId','tax_id')`. Spec §7 contract `terminal.location.tax_id ?? company`. |
| `terminal` non-null at helper callsites | ✅ Guarded by `if (!terminal || terminal.id !== terminalId || !shift) throw` at `:534` and `:646` before `branchTaxNumberFromTerminal(terminal)`. No `!`/non-null assertion needed. |
| Helper type-safety (no `any`/`mixed`) | ✅ `branchTaxNumberFromTerminal(terminal: { location: { tax_id: string \| null } }): string \| null`. `Terminal.location` is non-nullable with `tax_id: string \| null` (`terminalStore.ts:46-53`), so the structural param is satisfied. |
| No fiscal payload schema/version change (D6, §7) | ✅ Only the `taxNumber` value line + a pure helper. No key added/removed, no `fiscal_schema_version`/`event_version` touched. Golden fixtures untouched (Task 18 verifies parity). |
| ACCOUNT_CHARGE untouched (out of P0) | ✅ No ACCOUNT_CHARGE seller authoring in `paymentStore.ts`; `AccountChargePayload.ts:256` remains a fixture, not modified. |
| No other live device seller-authoring site missed | ✅ Z-session open (`terminalStore.ts:186` → `authorZSessionOpenWithOpeningFloat`) passes **no** `seller` (optional `Record<…>\|null`, omitted) — correctly not a branch-tax site. Payload builders receive `input.seller` verbatim from the two patched stores. |
| i18n / hardcoded Tailwind / `app()` | ✅ N/A — no user-facing strings, no JSX, TS store code; no `app()` (frontend). |
| TDD red→green validity | ✅ Pre-change line returned `companyField(... 'tax_id')` = `'COMPANY-FR-TAX'`; the two tests assert `'BRANCH-FR-TAX'`, so they genuinely fail before the change and pass after. |
| Test infra real (not stubbed-to-pass) | ✅ `makeCartItem/makePaymentMethod/makePaymentRepository` exist in `apps/pos/src/test/helpers.ts`; `processCashCheckout`, `processAccountPayment`, `attachCustomer` are real store actions. |

> Test execution: `pnpm vitest run` is blocked by the review sandbox (no exec/network), so I could not produce a green run here. The red→green logic and all referenced symbols were verified statically; the executor's own Step 4 run should confirm.

---

## Findings

### MAJOR — Company-fallback path is never asserted (plan Step 1 not fully met)
**File:** `apps/pos/src/stores/__tests__/paymentStore.branchSeller.test.ts:197,212`

Plan Task 17 Step 1 explicitly requires: *"when the active terminal's `location.tax_id` is set, the built `seller.taxNumber` equals the branch value; **when null, it falls back to the company value.**"* The test fixture even seeds `company.tax_id = 'COMPANY-FR-TAX'` (`:146`) — but **no test ever nulls `location.tax_id` and asserts `taxNumber === 'COMPANY-FR-TAX'`.** Both `it()` blocks assert only the branch value.

Consequence: the `?? companyField(...)` fallback is **dead-tested**. A regression that drops the fallback (e.g. `taxNumber: branchTaxNumber`) would still pass CI, silently emitting `tax_number: null` for every branch *without* an override — i.e. the majority of branches, on the **hash-covered signed SALE_RECEIPT / ACCOUNT_PAYMENT bytes**. This is exactly the branch-vs-company fallback regression class this feature is most exposed to, and the spec frames company fallback as the default (D1/D2/D6). The code is correct today; the safety net for it is not locked.

**Fix:** add two assertions (or two `it` blocks) — set `location.tax_id = null` (and ideally a third with `'   '` whitespace) and assert `seller.taxNumber === 'COMPANY-FR-TAX'` for both SALE_RECEIPT and ACCOUNT_PAYMENT. Cheap; reuses the existing `useTerminalStore.setState` arrange block.

### MINOR — Helper widens fallback trigger beyond the spec's `??` (empty/whitespace → company)
**File:** `apps/pos/src/stores/paymentStore.ts:446-451`

Spec §7's literal contract is `terminal.location?.tax_id ?? company` — a **null/undefined-only** fallback. The helper instead falls back when the value is empty or whitespace (`value.trim() !== ''`). This is a deliberate, defensible hardening (mirrors `companyField`'s own `trim()` guard and avoids signing a blank `tax_number`), and it is strictly safer. Flagging only because it is an intentional divergence from the spec wording that isn't documented in the diff, and — per the MAJOR above — the empty/whitespace branch is itself untested. Recommend a one-line comment and a whitespace test case; no code change required.

### NIT — Unit seam asserts the seller *argument*, not the canonical signed bytes
**File:** `paymentStore.branchSeller.test.ts:21-37,202-221`

`createOfflineReceipt` / `createAccountPayment` are fully `vi.mock`ed, so the tests prove the store *passes* `seller.taxNumber` correctly but not that the branch value survives into the canonical/hash-covered payload. That end of the contract lives in the fiscal payload-builder tests and the PHP validator-acceptance test (Task 18, spec §7 "cross-language source-path tests"). Appropriate seam for a store-level unit test; noted only so the gate doesn't mistake this for full fiscal-byte coverage. No action for Task 17.

---

## Scope / discipline checks
- One-task scope respected: only the two Task 17 files changed.
- Commit bundles test + impl together (plan Step 5) — acceptable; the review-commit cadence is separate.
- No `// TODO`/placeholder, no `app()`, no `any`/`mixed`, strict-typed helper.
- ACCOUNT_CHARGE documented-not-patched, matching plan/spec §8.

## Recommendation
Land the implementation; **before the Phase-2 gate, add the company-fallback (and whitespace) assertions** so the default branch path is regression-locked. The MAJOR is additive test coverage on correct code, not a behavioral defect — hence edits, not rework.

---

VERDICT: APPROVE-WITH-EDITS
