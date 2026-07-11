# Gate 5 Remediation Review — design-system unification

- **Range:** `a39a83545` (Wave 5 leg 3, the REJECTED commit) → `c729930e1` (Phase 0.1.8: Remediate Gate 5 design sweep)
- **Reviewer:** independent headless Opus, no shared context with implementor
- **Scope:** single remediation commit addressing Gate 5's REJECT (BL-1/BL-2/BL-3) + MAJORS (M1/M2/M3) + MINORS
- **Date basis:** 2026-07-11

## Verdict summary

Every Gate 5 blocker and major is fixed and independently reproduced. The two dead-CSS grep patterns return **zero** on `apps/web/src`; the new lint guard fires as an ERROR on both patterns under live probe; the 3 previously-failing tests now pass; the baseline is **byte-identical** to BASE (no laundering surface at all); and the highest-risk rewrites are pixel-exact literalizations with **no shade substitution**. This is a clean remediation.

---

## 1. Baseline replay (anti-laundering)

| | BASE `a39a83545` | HEAD `c729930e1` |
|---|---|---|
| baseline entries | 183 | 183 |

`git diff a39a83545..c729930e1 -- apps/web/tools/audit-design-system-baseline.json` → **empty** (byte-identical). No `apps/web/tools/*` files changed in the range.

**Arithmetic:** 183 ± 0 = 183. Zero removals, zero additions. The remediation adds no swept directory and touches no baseline entry, so there is no laundering surface. HONEST (trivially).

---

## 2. Evidence reproduction

| Check | Command | Result | Claim | Match |
|---|---|---|---|---|
| Design audit | `node apps/web/tools/audit-design-system.mjs` | 183 acknowledged, 0 new, 0 stale (exit 0) | 183/0/0 | ✅ |
| TanStack keys | `node apps/web/tools/audit-tanstack-keys.mjs` | 0 violations (exit 0) | 0 | ✅ |
| Typecheck | `pnpm --filter @autoerp/web typecheck` | exit 0 | pass | ✅ |
| Lint | `pnpm --filter @autoerp/web lint` | **0 errors, 6919 warnings** (exit 0); chained key + design audits pass | 0 err / 6,919 warn | ✅ |

**Warning-delta decomposition (protocol requirement).** Leg-3 base reported 6,854 warnings; HEAD = 6,919 → **+65**. Attribution: the remediation added a new `semanticColorTokens.variants` block to `src/lib/designTokens.ts` (~67 complete class literals carrying variant/opacity prefixes — the BL-1/BL-2 fix mechanism). Each literal trips the WARN-level `no-restricted-syntax` hardcoded-color rule **in its sanctioned home file** (designTokens.ts is not exempt from the WARN rule, only the ERROR ratchet). `designTokens.ts` alone now emits 543 such warnings. The rename-only edits (e.g. `bgSubtleAlphaMuted`→`bgSubtleAlphaLight`) add no warnings (identical literals). Delta fully explained; single rule ID (`no-restricted-syntax`, WARN); no unexplained warnings.

---

## 3. Blocker verification

### BL-1 — interpolated variant prefixes (dead CSS). **FIXED.**
- `rg -n '(hover|focus|focus-within|focus-visible|group-hover|disabled|placeholder|active|dark|file):\$\{' apps/web/src` → **0 matches**.
- Fix mechanism confirmed conservative on every site Gate 5 named as *dead-right-now*:
  - **Double-`hover:` (21-site bug):** `CategoriesPage` primary buttons rewrote `hover:${primary.bgStrongHover}` (where `bgStrongHover` already = `hover:bg-blue-700`) → `${primary.bgStrongHover}` = `hover:bg-blue-700`. Redundant prefix dropped. Restores the intended hover.
  - **POS product search (`ProductGrid.tsx:144-157`):** `focus:${available.ringFocus}` / `focus:${available.borderFocus}` / `placeholder:${available.textFaint}` / clear `hover:${available.text}` → `variants.focusRingEmerald500` (`focus:ring-emerald-500`) / `focusBorderEmerald500` (`focus:border-emerald-500`) / `placeholderTextEmerald400` (`placeholder:text-emerald-400`) / `hoverTextEmerald600` (`hover:text-emerald-600`). All match source token values exactly (`available.ringFocus`=`ring-emerald-500`, `borderFocus`=`border-emerald-500`, `textFaint`=`text-emerald-400`, `text`=`text-emerald-600`).
  - **Auth submit-icon group-hover (Login/Forgot/Reset):** `group-hover:${primary.textFaint}` → `variants.groupHoverTextBlue400` = `group-hover:text-blue-400` (`primary.textFaint`=`text-blue-400`). ✅
  - **Expenses disabled/dark states (highest-risk file):** every `dark:${surface/text/border.inverse*}` and `hover:`/`dark:hover:` interpolation traced to a `variants.*` literal that equals the source token value character-for-character (e.g. `surface.inverse`=`bg-gray-800`→`darkBgGray800`=`dark:bg-gray-800`; `text.inverseFaint`=`text-gray-100`→`darkTextGray100`=`dark:text-gray-100`; `danger.bgInverse`=`bg-red-900`→`darkHoverBgRed900Alpha20`=`dark:hover:bg-red-900/20`). **No shade substitution.**

### BL-2 — interpolated opacity modifiers (dead CSS). **FIXED.**
- `rg -n '\$\{[^}]+\}/\d' apps/web/src` → **0 matches**.
- `CategoriesPage` modal backdrop: `${surface.neutral}/75` → `variants.bgGray500Alpha75` = `bg-gray-500/75` (`surface.neutral`=`bg-gray-500`). The previously-transparent backdrop is restored. ✅

### Lint guard (BL-1/BL-2 recurrence prevention). **ADDED + PROVEN.**
- New rule `apps/web/eslint-rules/no-dead-tailwind-token-interpolation.js` wired global ERROR (`local/no-dead-tailwind-token-interpolation: 'error'`), plus a `no-restricted-syntax` TemplateElement selector for the variant-prefix case in the Wave 5 block.
- **Live probe** (`src/__probe_dead_tw__.tsx` with `` `hover:${token}` `` and `` `${token}/75` ``): eslint reported **2 errors** — `variantInterpolation` on line 2, `opacityInterpolation` on line 3. Probe deleted; `git status` clean afterward.

### BL-3 — 3 failing tests (`VatPeriodStatusBadge` et al.). **FIXED.**
- `pnpm vitest run src/features/vat-reporting src/features/vouchers src/features/stock-transfers` → **23 files, 105 passed, 0 failed** (Gate 5 saw 3 failed / 96 passed). Tests now assert rendered tone/semantics, not raw `bg-*-100` classes (CLAUDE.md rule 17).

---

## 4. Major verification

- **M1 (badge parity).** Focused suite `...StockBadge/TableStatusBadge/OrderStatusBadge/BatchStatusBadge/vouchers StatusBadge/VatPeriodStatusBadge` reproduces (part of the 13-file/74-test run). Spot-read `BatchStatusBadge.test.tsx`: covers all 5 `ExpiryStatus` values by rendered i18n key + days-suffix behavior; **no raw class assertions**. Rule-17 compliant. ✅
- **M2 (RHF payload identity).** Reproduced the full focused suite: **13 files, 74 tests passed** (matches claim). Spot-verified two are genuine payload-identity assertions, not vacuous: `CouponFormPage.test.tsx` asserts the complete `mockCreateMutate` payload including precision normalization (`40.500`→`40.5`) and category-id string-coercion; `parapharmacy/.../tenantScope.test.tsx` contains a `parapharmacy form payload identity` block asserting exact `updateMock` payloads for 4 resources after RHF wrapping. ✅
- **M3 (`AdvancedPaymentsModal`).** Malformed import `colors , semanticColorTokens` → `colors, semanticColorTokens`; stray `cn(' text-2xl …')` → plain `"text-2xl font-semibold min-h-[56px]"`; tone drift restored `warning.textStronger` (`text-yellow-800`) → `warning.text` (`text-yellow-600`) on both radios = the pre-drift value. ✅

---

## 5. Minor verification

- **Ladder renames (zero live consumers):** `warning.border`(500)→`borderFocus`, `warning.textSubtle`(400)→`textFaint`, `ledger.textSubtle`(400)→`textDisabled`, `neutral.text`(500)→`textSubtle` all present in the designTokens diff; **typecheck passes**, proving no dangling references to the old `as const` keys. ✅
- **Micro-variant normalization:** `bgSubtleAlphaMuted`→`bgSubtleAlphaLight`, `fileBgSoftHover`→`fileBgHoverSoft`, `fileTextStrong`→`fileText`, `groupBgHoverSoft`→`groupBgHover` — same literals, normalized suffixes. ✅
- **Harness committed:** `docs/handoff/design-sweep-gate-review-prompt.md`, `scripts/design-sweep-gate.sh`, and `docs/handoff/CODEX-continuation-gate5-2026-07-11.md` are in the range. ✅

---

## 6. Conservation table (representative swept files)

| File | Rewrites | Residual visual change | Verdict |
|---|---|---|---|
| `pos/organisms/ProductGrid` | focus ring/border/placeholder/clear-hover → emerald `variants.*` | none — exact restore of dead classes | conservative |
| `categories/CategoriesPage` | 2× double-`hover:` drop, `focus:ring-blue-500`, `/75` backdrop | none — restores lost hover + backdrop opacity | conservative |
| `auth/LoginPage` (+Forgot/Reset/Register) | focus border/ring, group-hover icon, org-button hover | none — exact | conservative |
| `expenses/ExpenseCategoryPage` | ~40 dark/hover/focus interpolations → `variants.*` | none — every inverse token = identical gray literal | conservative |
| `pos/AdvancedPaymentsModal` | import + `cn()` cleanup + radio tone | tone **restored** to yellow-600 (documented) | conservative |
| `lib/designTokens.ts` | +`variants` block (67 literals), 8 key renames | none (declaration only) | conservative |

No dropped columns/links/actions/statuses/filters. The only behavioral changes are the RHF submit wrappers (M2), each locked by a passing payload-identity test.

---

## Findings

**None blocking.** No BLOCKER, MAJOR, or MINOR findings survive verification.

Informational (no action required):
- `src/lib/designTokens.ts` now carries 543 WARN-level hardcoded-color warnings (incl. the new `variants` block). This is by design — it is the sanctioned literal home and the rule is WARN, not ERROR. Not debt.
- M2 payload tests lock *current* (post-RHF) payloads rather than diffing against pre-leg-3 construction. The Gate 5 directive ("add/extend a payload assertion test") is fully met and the assertions are comprehensive; a strict old-vs-new payload diff was not independently reproduced (pre-leg-3 archaeology out of scope). Called out for transparency, not as a required fix.

No ESCALATE-TO-OWNER items in this range (owner-reserved decisions — PageHeader adoption, visual device pass, merge — remain downstream per the Gate 5 autonomous-loop plan).

Per the Gate 5 autonomous-loop instructions: on APPROVE, proceed to **Gate 6 scope** (`src/components/`, `src/pages/`, `src/lib` residue, test/story color tail, `statusMapper.ts`, Wave 6 dedup/orphans, deferred PDF template test) under the same per-directory protocol and the same BL-1 static-literal rules.

VERDICT: APPROVE
