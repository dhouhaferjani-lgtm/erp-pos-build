# Adversarial merge-gate register — Session H Phase 1, lane `h1-cleanup`, **M2** (round 2)

**Diff reviewed:** `ed9f69551..99bf87eeb` (5 commits, 18 files) — brief §2 **M2 (`a4′`)**, lens **frontend-conventions**. No amending ruling supplied. Prior register: `docs/handoff/reviews/session-h-phase1/M2-round1.md` (CHANGES-REQUIRED, 13 findings).

**Independent verification I ran** (worktree `.worktrees/h1-cleanup/apps/web`):
- `pnpm exec tsc --noEmit` → exit 0
- `pnpm exec vitest run src/features/partners` → **12 files / 125 tests passed**
- `pnpm exec eslint src/features/partners` → exit 0, **no output** (the two `precision/no-parsefloat-on-money` warnings from round 1 are gone)
- `pnpm audit:i18n:local` → OK (55 ns; 4 baseline entries newly translated) · `pnpm audit:keys` → Gate C 0 new / 0 stale
- Read both committed PNGs in `.playwright-mcp/session-h/m2/` directly.

**Round-1 disposition (verified against code, not the report):** #1 ✅ (`CreditLimitWarning.tsx:4,26,30-34,63-65` — `bccomp`/`bcdiv`/`bcmul` + `formatCurrency`, no `parseFloat`), #2 ✅, #3 ✅ (all three named cases exist: `CreditLimitWarning.test.tsx:16,32,47`), #4 ✅ (`PartnerForm.tsx:702`), #5 ➖ (see #6 below), #6 ⚠️ partially (see #2 below), #7 ✅ (`PartnerForm.tsx:548`), #8 ✅ (`PartnerForm.tsx:265`), #9 ✅ (`PartnerForm.tsx:221`), #10 ✅ (verified visually), #11 ✅ (`ar/sales.json` `partners.b2b`), #12 ➖ still out-of-scope, still non-regressive, #13 ✅ (`m2-partner-nature.spec.ts:87-98`; `PartnerController::destroy` does return 204).

---

### 1. **P2 — CONFIRMED** — for a `type: 'both'` partner the credit-limit alert is silenced by the partner's own **payables**
`apps/web/src/features/partners/PartnerForm.tsx:702` · `apps/web/src/features/partners/partnerNetBalance.ts:12-14` · `apps/api/app/Modules/Partner/Domain/Partner.php` (`netBalanceSqlExpression`)

The round-1 fix passes `getNetBalance(partner, isCustomerContext)` into the warning. `getNetBalance` short-circuits on `partner.net_balance` whenever it is non-null — and the generated contract declares it **non-nullable** (`packages/shared/types/generated.d.ts:1857` `net_balance: string`), so the fallback branch and the `isCustomerView` argument are dead for every real API partner. The server value is:

```
type='supplier' → payable_balance
type='both'     → receivable_balance - credit_balance - payable_balance
else            → receivable_balance - credit_balance
```

`getNetBalance` is the app's **display** net across both roles; it is not a customer-exposure figure. Feeding it to a *credit limit* control mixes in what **we owe the partner**.

**Failure scenario:** a garage that is both customer and supplier — `type='both'`, `credit_limit='1000.000'`, `receivable_balance='5000.000'`, `credit_balance='0.000'`, `payable_balance='4900.000'`. Server `net_balance = 100.000` → `100/1000 = 10%` → `isExceeded` false, `isApproaching` false → **`CreditLimitWarning` returns null** (`CreditLimitWarning.tsx:36-38`). The B2B block shows a 1 000 TND credit limit with no alert while the customer side is 5 000 TND over it. Round 1's finding #4 was a false *positive*; this is the mirror-image false *negative*, and it is not covered by any of the new tests (the two credit tests both use `type: 'customer'` fixtures). The customer-exposure figure the control actually wants is `bcsub(receivable_balance, credit_balance)` — `partnerNetBalance.ts:26` already computes exactly that; the fix is to stop routing a `both` partner through the full net.

*Fairness note for the parent:* this is a direct consequence of round-1 #4 prescribing `getNetBalance()`, which the lane applied literally. It is a one-line change at `PartnerForm.tsx:702`, not a design relitigation.

### 2. **P3 — CONFIRMED** — Arabic: `Type` and `Nature` are two adjacent selects with the **byte-identical** label `النوع`
`apps/web/src/locales/ar/sales.json` (`partners.type` = `"النوع"`, `partners.nature.label` = `"النوع"`) rendered at `PartnerForm.tsx:519` and `PartnerForm.tsx:537`, side by side in the same grid row.

The round-1 fix disambiguated the *error* (`natureRequired` = `"طبيعة الشريك مطلوبة"`, verified distinct from `typeRequired` = `"النوع مطلوب"`) and the *placeholder* (`"اختر طبيعة الشريك"`), and those are tested (`PartnerForm.test.tsx:180-195`). The **label collision remains**: an `ar` user sees two required-looking selects both titled النوع, and `getByLabel`-style resolution (screen readers included) cannot tell them apart. `"النوع"` was prescribed by the brief, so keeping it is defensible — but round 1 said *"escalate if the owner wants it kept"* and it was **not** written to `owes_parent` in `docs/handoff/progress/session-h-phase1.progress.yaml:80-82`. It is only mentioned in passing in `M2-implementation-evidence.md`.

### 3. **P3 — CONFIRMED** — the "nature required on create" invariant is enforced in exactly one of two create surfaces
`apps/web/src/components/organisms/AddPartnerModal/AddPartnerModal.tsx:24,211` declares its **own local** `PartnerFormData` with no `customer_category` and posts to `/partners` unguarded. Server-side `CreatePartnerRequest` keeps `customer_category` `nullable` (unchanged — correct, M2 is shape-neutral by design).

**Failure scenario:** a user creating a customer from the quick-add modal (POS/document flows) keeps minting fresh `customer_category = NULL` rows, i.e. new rows that the very NULL-heuristic M2 just built for *legacy* data will have to absorb. Out of M2's stated scope (the brief names `PartnerForm.tsx` only) — but it belongs in `owes_parent`, and it is not there.

### 4. **P3 — CONFIRMED** — usage percentage rounds half-up, so "Approaching" can render as "100% used"
`apps/web/src/features/partners/components/CreditLimitWarning.tsx:31` — `bcmul(usagePercentage, '1', 0)` with `Big.RM = 1` (`apps/web/src/lib/decimal.ts:15`).

`outstanding = 999.500`, `limit = 1000.000` → `isExceeded` false (correctly: `bccomp` on the raw strings), but the copy reads **"Approaching credit limit — Outstanding: 999,500 TND / Limit: 1 000,000 TND (100% used)"**. Behaviourally identical to the pre-M2 `Math.round`, so not a regression — but M2 is what puts it on screen for the first time. `Big.round(0, 0)` (truncate) would match the comparison.

### 5. **P3 — CONFIRMED** — four i18n keys orphaned by the rename, left in all three locales
`sales:partners.b2b.customerCategory`, `.selectCategory`, `.individual`, `.business` now have **zero** references in `apps/web/src` (verified by grep). The lane is literally "shape-neutral cleanup" and M3 is "dead surfaces"; leaving four dead keys behind in `en`/`fr`/`ar` is the same class of debt. `audit:i18n:local` does not flag orphans, so nothing catches it.

### 6. **P3 — CONFIRMED** — red-first evidence is a retroactive narrative, not a reproducible artifact
`docs/handoff/reviews/session-h-phase1/M2-implementation-evidence.md` now documents RED→GREEN per test, including a genuine mutation check for the net-exposure assertion. That satisfies the harness. It does **not** change the underlying fact from round-1 #5: `a861f7dbe` still ships implementation + all five tests in one commit with an empty body, and the evidence file was authored after the review. I spot-checked non-vacuity by inspection instead and it holds — every new assertion pins a string the pre-fix code provably could not produce (`'1 000,000 TND'` vs `'1,000.00'`; `toHaveValue('')` vs `'business'`; `'اختر طبيعة الشريك'` vs the EN fallback). No test is vacuous.

### 7. **P3 — CONFIRMED, non-regressive** — out-of-scope drive-bys carried forward from round 1
`partnerDetailInvalidationPredicate` (`apps/web/src/features/partners/_invalidation.ts:9-26`) and the M1 e2e helper extraction (`e2e/session-h/helpers.ts`) are still not M2 scope. Re-verified: every partner-detail key is `tenantScopedKey(['partner', id])` → length 4, so the `k.length >= 4` guard matches all of them; `audit:keys` Gate C is 0/0.

---

## Bypasses I tried that FAILED (no defect found)

- **Drop a field from the POST by exploiting zod's default strip:** compared all 25 keys of the new `partnerFormSchema` (`PartnerForm.tsx:160-206`) against `PartnerFormData` (`PartnerForm.tsx:50-76`) — exact 1:1 match, nothing is stripped from `handleSubmit`'s parsed output.
- **Silently block submit via a `tax_status` enum mismatch** (a resolver error on a field with no visible error slot): generated `PartnerTaxStatus = 'REGISTERED' | 'NON_REGISTERED' | 'EXEMPT'` (`generated.d.ts:2463`) matches the zod enum exactly.
- **Silently block submit via a `bank_accounts` shape mismatch:** `reset` maps all nine keys (`PartnerForm.tsx:365-375`), `id` is `.optional()`; `PartnerForm.test.tsx` "adds a bank account on the edit page… and submits without blocking" passes.
- **Submit create with no nature:** `''` and `null` are both rejected by the refine at `PartnerForm.tsx:165-168`; e2e asserts `createRequestCount === 0` (`m2-partner-nature.spec.ts:120`).
- **Crash / divide-by-zero in the rewritten warning:** `bcdiv` throws on a zero divisor, but `bccomp(creditLimit,'0') <= 0` returns `null` first (`CreditLimitWarning.tsx:26`); malformed input (`'1.2.3'`, `'abc'`) is coerced to `0` by `safeBig` (`decimal.ts:30-37`) and trips the same guard. Negative balances also return `null`.
- **Break the millime boundary:** comparison is `bccomp` on raw strings, display is `formatCurrency` with `getDecimals('TND') = 3`; committed tests pin `1 000,001 TND`.
- **Prove the screenshots still don't evidence the claim (round-1 #10):** read both PNGs. `m2-legacy-company-visible.png` (976×1739) shows Nature = *"Select nature"* (the legacy NULL), `Matricule fiscal FR52814777264`, and **B2B Information** rendered below General Information. Genuinely fixed.
- **Find a shape-neutrality violation (§1):** the whole diff is `apps/web/src` + `apps/web/e2e` + `docs/` — no migration, DTO, enum, request, or sealed-payload change. Backend, precision-resolver, queue and tenancy standing checks do not apply to this diff.
- **Leak the supplier-create default onto supplier edit:** `!isEditing && isSupplierContext` (`PartnerForm.tsx:221`), covered by a failed-detail-request test (`PartnerForm.test.tsx:165-175`).

## Verification I could NOT perform
The Playwright gate could not be re-executed: **`:5174` and `:5173` are both down** (`curl` → `000`; only the APIs on `:8010`/`:8011` respond), and test 1 mutates the demo tenant, which is out of bounds for a read-only review. The browser gate is accepted on the committed spec, the `DELETE → 204` contract I verified in `PartnerController::destroy`, and the two screenshots I read directly.

## Gate summary
Every one of round 1's blocking findings is genuinely fixed in code, not just in the report — the rule-19 conversion is real, all three named boundary cases are tested, the screenshots now prove the branch they claim, and the e2e cleans up after itself. The milestone's own named acceptance criteria (nature required on create, supplier default, NULL+signal heuristic, warning wired, three browser cases) are all met and non-vacuously tested. What remains is one correctness defect inside the newly-wired warning: routing `type: 'both'` partners through the full display-net silences the credit alert with the partner's own payables. It is a single line at `PartnerForm.tsx:702` and it is the only item standing between this milestone and ACCEPT; findings 2–7 are notes, of which #2 and #3 should be written to `owes_parent` rather than fixed here.

VERDICT: CHANGES-REQUIRED
