# POS UI Remediation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking. This plan is the contract for a parallel Opus swarm — each "B-stream" is dispatched to its own subagent working on a **disjoint file set**.

**Goal:** Turn IziPOS (apps/pos, Tauri desktop) from un-designed Tailwind defaults into a production-ready POS with one consistent design language, driven by a semantic token system and protected by CI guardrails.

**Architecture:** A single semantic-token layer (Tailwind v4 `@theme` in `index.css`, plus composite recipes in `designTokens.ts`) becomes the source of truth. ~1,535 hardcoded palette classes are migrated to semantic tokens. An ESLint `no-restricted-syntax` rule prevents regression. Layout/hierarchy and per-screen fixes follow, each scoped to disjoint files so they can run as a parallel swarm. Reports components are explicitly OUT of scope (concurrent external work).

**Tech Stack:** React 19 + Vite + TypeScript strict, Tailwind CSS 4 (`@theme`), TanStack Query/Virtual, Zustand, react-i18next, Vitest + Testing Library, Tauri 2.

---

## Theming decision (owner-approved 2026-06-13, iterable later)

- **action-primary = Ocean Blue (`primary-600`)** — keeps the existing interactive color; least disruptive. Copper stays the **brand accent** (`secondary-*`) for wordmark/selected-brand chrome, not for generic buttons.
- **Money/price text = `ink` (neutral-900), NOT accent blue.** Prices are data, not actions.
- **Color grammar (semantic, enforced):**
  - `danger` (red) → errors + destructive actions ONLY. Out-of-stock is NOT an error.
  - `success` (green) → confirmed money/sync events ONLY (completed sale, "synced").
  - `warning` (amber) → warnings, low stock.
  - `action` (blue) → interactive/primary actions + selected state.
  - Out-of-stock → desaturated surface + neutral badge. Low-stock → amber dot.
- **Surface scale (the "separation" fix):** `surface-canvas` (app bg, slightly tinted) < `surface-raised` (white panels + soft shadow) < `surface-overlay` (modals + scrim). The cart (money zone) reads as a raised surface against the canvas.

---

## Coordination & scope guards (swarm safety)

- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp.pos-ui`, branch `feat/pos-ui-remediation` (off `origin/dev`). All work here.
- **OUT OF SCOPE this pass (concurrent reports-error work — do NOT touch):** `components/pos/ReportsMenu.tsx`, `XReportModal.tsx`, `ZReportModal.tsx`, `TodaySalesPanel*.tsx`, `EndOfDayPreviewModal*.tsx`, `CashReconciliationSection*.tsx`, `pages/ZReportListPage*.tsx`, `ReceiptLocator*`, `ReceiptScan*`. Their color migration is **deferred** to a later pass to avoid merge conflicts.
- **Screenshots lag the code.** The provided screenshots are from an older build. Each agent MUST verify a finding still exists in the current worktree code before "fixing" it; if already fixed, note it and move on.
- **Disjoint files = no content conflicts.** Each B-stream owns its file set exclusively. Only **B5 edits locale JSON** (`src/locales/{fr,en}/pos.json`). Only **A1 edits `index.css`** and creates `designTokens.ts`. Only **A2 edits `eslint.config.js`**. If a B agent needs a token or key that doesn't exist, it STOPS and reports rather than editing a shared file.
- **Per-agent verification:** run ONLY your own component's vitest (`pnpm vitest run <path>`). Do NOT run global `tsc`/`lint` (cross-talk with other in-flight agents). Global typecheck/lint happens in Wave C.

---

## File Structure

**Created:**
- `apps/pos/src/lib/designTokens.ts` — composite component recipes (button/badge/segmented/statusPill) with all interaction states.
- `apps/pos/docs/design-language.md` — the locked design-language spec (Wave C).

**Modified (owner per file — exclusive):**
- A1: `apps/pos/src/index.css`
- A2: `apps/pos/eslint.config.js`
- B1: `apps/pos/src/components/molecules/ProductCard/*`
- B2: `apps/pos/src/components/organisms/ProductGrid/*`
- B3: `apps/pos/src/components/Header.tsx`
- B4: `apps/pos/src/components/AppShell.tsx`, `apps/pos/src/components/fiscal/UnsyncedRiskIndicator.tsx`
- B5: `apps/pos/src/components/customers/{CustomerSearchInput,CustomerAttachPanel,CustomerSearchModal}.tsx`, `apps/pos/src/components/pos/Modal.tsx`, `apps/pos/src/locales/{fr,en}/pos.json`
- B6: `apps/pos/src/components/organisms/CashPaymentScreen/*`, `apps/pos/src/components/molecules/NumPad/*`, `apps/pos/src/lib/denominations.ts`
- B7: `apps/pos/src/pages/SettingsPage.tsx`

---

## Canonical token vocabulary (defined by A1 — every agent uses these names)

Semantic Tailwind classes (backed by `@theme` tokens). **Use these, never raw palette classes.**

| Semantic class | Role | Backing value |
|---|---|---|
| `bg-surface-canvas` | App background | neutral-100 `#EBEDF2` |
| `bg-surface-raised` | Panels, cards | `#FFFFFF` |
| `bg-surface-overlay` | Modal body | `#FFFFFF` |
| `bg-surface-sunken` | Inset boxes (amount-due) | neutral-50 `#F7F8FA` |
| `text-ink` | Primary text + money | neutral-900 `#242B35` |
| `text-ink-muted` | Secondary text | neutral-600 `#556275` |
| `text-ink-faint` | Tertiary / placeholder / disabled text | neutral-400 `#929DAD` |
| `text-ink-inverse` / `bg-ink-inverse` | Text on dark / white | `#FFFFFF` |
| `border-subtle` | Default borders | neutral-200 `#D5D9E2` |
| `border-strong` | Emphasized borders | neutral-300 `#B8BFC9` |
| `bg-action` / `text-action` / `border-action` | Primary action + selected | primary-600 `#1A6FB5` |
| `bg-action-hover` | Action hover | primary-700 `#155C9A` |
| `bg-action-subtle` / `text-action-strong` | Soft action / selected bg | primary-50 / primary-700 |
| `bg-brand` / `text-brand` | Brand accent (copper) | secondary-600 `#A85E33` |
| `bg-success` / `text-success` / `bg-success-surface` / `text-success-strong` / `border-success-subtle` | Confirmed money/sync | `#1B7F4E` / surfaces below |
| `bg-warning` / `text-warning` / `bg-warning-surface` / `text-warning-strong` / `border-warning-subtle` | Warnings, low stock | `#D97706` / surfaces below |
| `bg-danger` / `text-danger` / `bg-danger-surface` / `text-danger-strong` / `border-danger-subtle` | Errors, destructive | `#C53030` / surfaces below |

Status surface/border values (A1 defines): success-surface `#E7F4EC` / success-strong `#176B42` / success-subtle border `#B8DEC8`; warning-surface `#FBF0DD` / warning-strong `#8A4B06` / warning-subtle border `#F0D49A`; danger-surface `#FBEAEA` / danger-strong `#9B2C2C` / danger-subtle border `#EFC2C2`.

### Migration mapping (raw class → semantic class)

Apply mechanically, then visually verify. When a raw class has no exact rung, pick the nearest semantic role by **intent**, not by shade.

```
bg-white                  → bg-surface-raised   (panels/cards)  | bg-surface-overlay (modal body)
bg-gray-50                → bg-surface-canvas    (page/grid bg)  | bg-surface-sunken (inset box)
bg-gray-100               → bg-surface-sunken    | bg-surface-canvas (whichever the element is)
bg-gray-200 / -300        → (rare fills) bg-surface-sunken / neutral keypad: see B6
text-gray-900             → text-ink
text-gray-700 / -600      → text-ink-muted
text-gray-500 / -400      → text-ink-faint
border-gray-200 / -100    → border-subtle
border-gray-300           → border-strong
bg-blue-600 / primary-600 → bg-action
bg-blue-700 / primary-700 → bg-action-hover
text-blue-600 / -700      → text-action  / text-action-strong
border-blue-500 / primary-500 → border-action
bg-blue-50 / primary-50   → bg-action-subtle
bg-red-50 / -100          → bg-danger-surface
text-red-600/-700/-500    → text-danger / text-danger-strong
border-red-200/-300       → border-danger-subtle
bg-green-50 / -100        → bg-success-surface
bg-green-600 / -700       → bg-success / (hover) bg-success-hover  [A1 adds success-hover #15673E]
text-green-600/-700       → text-success / text-success-strong
border-green-200/-300     → border-success-subtle
bg-amber-50 / text-amber-600/-700 / border-amber-* → warning-* equivalents
```

Prices/amounts: ensure the container has `tabular-nums` (utility class `tabular-nums` works in Tailwind v4) and price text is `text-ink`.

---

## Wave A — Foundation (I author A1 + A2 directly, commit, THEN dispatch B-wave)

### Task A1: Semantic token foundation

**Files:**
- Modify: `apps/pos/src/index.css` (add `--theme-*` semantic vars in `:root`; add `--color-*` semantic mappings in `@theme`)
- Create: `apps/pos/src/lib/designTokens.ts`

- [ ] **Step 1:** In `:root` (after the existing `--pos-*` block), add semantic source vars (status surfaces + success-hover) using the hex values in the vocabulary table above.
- [ ] **Step 2:** In `@theme`, add `--color-surface-canvas`, `--color-surface-raised`, `--color-surface-overlay`, `--color-surface-sunken`, `--color-ink`, `--color-ink-muted`, `--color-ink-faint`, `--color-ink-inverse`, `--color-border-subtle`, `--color-border-strong`, `--color-action`, `--color-action-hover`, `--color-action-subtle`, `--color-action-strong`, `--color-brand`, `--color-success`, `--color-success-hover`, `--color-success-surface`, `--color-success-strong`, `--color-success-subtle`, `--color-warning`, `--color-warning-surface`, `--color-warning-strong`, `--color-warning-subtle`, `--color-danger`, `--color-danger-surface`, `--color-danger-strong`, `--color-danger-subtle`, mapping each to the corresponding `--theme-*` / `--theme-primary-*` / `--theme-secondary-*` / `--theme-neutral-*` var. (In Tailwind v4, `--color-foo` auto-generates `bg-foo`/`text-foo`/`border-foo`.)
- [ ] **Step 3:** Create `designTokens.ts` exporting composite recipes built from the semantic classes:
  - `button.primary` (default/hover/active/disabled), `button.secondary`, `button.ghost`, `button.destructive`
  - `badge.{neutral,success,warning,danger}` (pill: surface + strong text + subtle border)
  - `segmented` (`{root, item, itemActive, itemInactive}`) — the ONE segmented-control voice
  - `statusPill` (header sync pill)
  - `disabledReason` (helper: disabled control look = `bg-surface-sunken text-ink-faint cursor-not-allowed`)
- [ ] **Step 4:** Verify build compiles: `cd apps/pos && pnpm vitest run src/components/molecules/ProductCard` (any existing test that imports a token-using component) — expect PASS. Then visually confirm `index.css` has no syntax errors via `pnpm build` dry check is optional.
- [ ] **Step 5:** Commit `feat(pos): semantic design-token foundation (index.css + designTokens.ts)`.

### Task A2: ESLint no-hardcoded-color guardrail

**Files:** Modify `apps/pos/eslint.config.js`

- [ ] **Step 1:** Add a global rule object (alongside existing rules) with `'no-restricted-syntax': ['warn', { selector: 'Literal[value=/\\b(bg|text|border|ring)-(red|blue|green|yellow|gray|purple|pink|indigo|slate|sky|amber|violet|emerald|stone|rose|zinc|teal|cyan|lime|orange|fuchsia|neutral)-(\\d{2,3})\\b/]', message: 'Avoid hardcoded Tailwind color classes. Use semantic tokens (bg-surface-*, text-ink*, bg-action, bg-success/warning/danger*) — see src/index.css @theme and lib/designTokens.ts.' }]`. **Keep the FU-2 cart-mutator rule intact** (don't drop the existing `no-restricted-syntax` for stores — merge: ESLint allows only one `no-restricted-syntax` key per config object, so put the color selector in a SEPARATE config object/files-block to avoid clobbering the cart rule).
- [ ] **Step 2:** Add a `files`-scoped override block (initially `files: []` placeholder or the dirs already known-clean) that sets the color rule to `'error'`. Wave C ratchets the migrated dirs into this list.
- [ ] **Step 3:** Verify config parses: `cd apps/pos && pnpm lint 2>&1 | tail -20` — expect it RUNS (warnings OK, no config crash).
- [ ] **Step 4:** Commit `chore(pos): add hardcoded-color ESLint guardrail (warn legacy)`.

---

## Wave B — Parallel swarm (dispatch after A1+A2 committed; all depend on A1 tokens)

Each task below is one subagent. Shared rules for every B agent:
1. Read `apps/pos/src/index.css` `@theme` block + `apps/pos/src/lib/designTokens.ts` first — use those token names.
2. Migrate raw palette classes in YOUR files per the mapping table. Do not touch files outside your set.
3. Verify each audit finding still exists before changing it (screenshots lag code).
4. Run ONLY your component's vitest. Do not run global tsc/lint.
5. Preserve all behavior, i18n keys (except B5), `t()` usage, gated-cart calls, fiscal logic. UI/visual layer only.
6. Commit your stream with a scoped message. If `git commit` hits `index.lock`, wait 2s and retry.
7. Return a report: what you changed, findings already-fixed, any token/key you needed but didn't have (do NOT add it yourself).

### Task B1: ProductCard
**Files:** `apps/pos/src/components/molecules/ProductCard/{ProductCard.tsx,cardSizing.ts}` (+ its `__tests__`)
- [ ] Fix product-name clipping: the `line-clamp-2` name leaks a sliced 3rd line. Give the name a fixed two-line slot — `line-clamp-2` + `overflow-hidden` + a deterministic min-height for the text region (use/extend `cardSizing.ts`), so the price/stock row never overlaps the name. Keep `title={product.name}` for full text.
- [ ] Replace the in-cart **side-stripe** (`border-l-4 border-l-primary-500 ...`) with a non-stripe signal: a small corner quantity/check badge (`badge`-style, `bg-action-subtle text-action-strong`) + `border-action` full border. No asymmetric thick stripe.
- [ ] Out-of-stock card: desaturate (`bg-surface-sunken`, `text-ink-faint`, reduced opacity) + neutral "Rupture" badge (`badge.neutral`), NOT red. Low-stock: amber dot + `text-warning-strong`. In-stock count: `text-ink-muted` (not green — reserve green for money/sync).
- [ ] Migrate all colors to tokens. Price = `text-ink` + `tabular-nums`.
- [ ] Update/extend tests if assertions reference removed classes. Run `pnpm vitest run src/components/molecules/ProductCard`.
- [ ] Commit `feat(pos): ProductCard — fix clip, tokenize, badge in-cart state`.

### Task B2: ProductGrid
**Files:** `apps/pos/src/components/organisms/ProductGrid/*` (+ `__tests__`)
- [ ] Toolbar: unify search / sort / view-toggle into one control voice. View-toggle uses `segmented` recipe from designTokens. Sort control styled consistently (if it's a dropdown trigger, style as `button.secondary`, not a saturated block).
- [ ] **Investigate the floating gray pill** at bottom-center (visible across screens): most likely a macOS overlay scrollbar from horizontal overflow OR a stray element. Find the source — check the grid/scroll container for `overflow-x` content exceeding width, and any absolutely-positioned element. Eliminate horizontal overflow (grid uses `overflow-y-auto` only; ensure `min-w-0` on flex children, no `100vw`). If you cannot reproduce/locate it in current code, report that clearly.
- [ ] Sellable-first ordering: when sorting (esp. "most sold"), out-of-stock items must not sort above sellable ones. Add a stable secondary sort that pushes `stock_quantity <= 0` to the end. Keep `inStockOnly` filter behavior.
- [ ] Migrate colors to tokens. Empty/not-found state uses `text-ink-muted`/`text-ink-faint`.
- [ ] Run `pnpm vitest run src/components/organisms/ProductGrid`.
- [ ] Commit `feat(pos): ProductGrid — unify toolbar, fix overflow, sellable-first`.

### Task B3: Header
**Files:** `apps/pos/src/components/Header.tsx`
- [ ] Regroup into three zones: **left** = wordmark + terminal badge (identity); **center** = ONE session-status pill (combine connectivity dot + sync + stock-age into a single `statusPill`); **right** = operator name + icon-only action buttons (Switch/Lock/Reports/Settings) with tooltips. Demote low-frequency items to icon buttons.
- [ ] Shift badge: keep info but use tokens (`badge.neutral` or `badge.success` only if it represents an open/confirmed state). Don't let everything be equal weight — primary identity largest, actions smallest.
- [ ] Migrate all colors to tokens.
- [ ] Do NOT change Reports button behavior/routing (reports work is concurrent) — only its visual treatment.
- [ ] Run `pnpm vitest run src/components` for any Header test (`pnpm vitest run -t Header` if present).
- [ ] Commit `feat(pos): Header — three-zone hierarchy + status pill + tokens`.

### Task B4: Sync banner → exception-only
**Files:** `apps/pos/src/components/AppShell.tsx`, `apps/pos/src/components/fiscal/UnsyncedRiskIndicator.tsx`
- [ ] Healthy state (`riskLevel === 'normal'` or `null`): render NOTHING as a full-width banner (the permanent green "Tout est synchronisé / La durabilité hors-appareil est saine" bar is removed). The healthy signal lives as the header status pill (B3) — coordinate by leaving the green pill to the Header; UnsyncedRiskIndicator returns `null` for normal.
- [ ] `elevated` / `escalated`: KEEP the banner (it's a real warning) but tokenize (`warning-*` / `danger-*`). This matches the fail-closed philosophy — surface only when something needs attention.
- [ ] Do NOT change durability/risk computation or fiscal store logic — presentation only. Keep i18n keys.
- [ ] Run `pnpm vitest run src/components/fiscal`.
- [ ] Commit `feat(pos): sync status is exception-only (no permanent healthy banner)`.

### Task B5: Customer modal — i18n + dedupe + tokens (LOCALE-JSON OWNER)
**Files:** `apps/pos/src/components/customers/{CustomerSearchInput,CustomerAttachPanel,CustomerSearchModal}.tsx`, `apps/pos/src/components/pos/Modal.tsx`, `apps/pos/src/locales/{fr,en}/pos.json`
- [ ] Extract hardcoded English to the `pos` namespace (`customer.*` section): "Customer search", "Name, phone, tax number", "Searching...", "Customer", "Attached", "Record", "Name"/"Phone"/"Email" placeholders, "Creating...", and the hardcoded error strings (CustomerSearchInput L53; CustomerAttachPanel L74/93/146/169). Add keys to BOTH `en/pos.json` and `fr/pos.json`. Wire `useTranslation('pos')` into both components (they currently import none).
- [ ] Dedupe the "Client" heading (modal title bar shows "Client", body repeats icon + "Client") — keep one.
- [ ] "Créer un client local" → "Créer le client" (drop sync-jargon "local"): change the `customer.createLocal` value in `fr/pos.json` (and en equivalent).
- [ ] Modal.tsx already has scrim (`bg-black/50`) + focus trap — verify, tokenize colors, ensure fixed sizing per the modal-fixed-size rule. Don't regress the scrim.
- [ ] Migrate colors to tokens in all three customer components.
- [ ] Run `pnpm vitest run src/components/customers`.
- [ ] Commit `feat(pos): customer modal i18n + dedupe heading + tokens`.

### Task B6: Cash payment screen
**Files:** `apps/pos/src/components/organisms/CashPaymentScreen/*`, `apps/pos/src/components/molecules/NumPad/*`, `apps/pos/src/lib/denominations.ts`
- [ ] NumPad: neutralize keypad semantic tints — backspace (`bg-red-50 text-red-700`) and clear (`bg-orange-50 text-orange-700`) become neutral keys (`bg-surface-sunken text-ink`) differentiated by **icon** (backspace = Delete icon, clear = label "C"), not by ad-hoc color. Add a **`000`** key (TND is 3-decimal; cashiers type thousands). Rework the grid to fit it (e.g. keep 4 cols; place `000` where sensible). Keep `value`/`onChange` contract and the preset-overwrite behavior intact.
- [ ] Change-due box: only render when `changeDue > 0` (currently always shows "0,000 DT" in green before tendering). Use `success-*` tokens.
- [ ] Confirm CTA disabled state: replace opacity-only disabled with a distinct neutral look (`disabledReason` recipe) AND show the reason (e.g. `t('cashPayment.enterAmount')`) when `!isValid`. Enabled = `bg-success` at full strength. Add the i18n key need to your report (B5 owns JSON — if the key is missing, report it; do not edit pos.json).  *(If a "enter amount" key already exists, use it.)*
- [ ] `denominations.ts`: when `getDenominations` returns empty because total exceeds the largest bill, return round-up suggestions instead (e.g. next multiples above total of the largest bill / 50 / 100) so the cashier always gets quick-tenders. Keep the ≤3 cap and the "≥ total" intent.
- [ ] All amounts `tabular-nums` + `text-ink` (except change = success, due = ink).
- [ ] Migrate colors to tokens.
- [ ] Run `pnpm vitest run src/components/organisms/CashPaymentScreen src/components/molecules/NumPad`.
- [ ] Commit `feat(pos): cash payment — neutral keypad, 000 key, conditional change, disabled reason, round-up tenders`.

### Task B7: SettingsPage
**Files:** `apps/pos/src/pages/SettingsPage.tsx`
- [ ] Use the ONE `segmented` recipe for the display-mode control. The auto-lock delay chip-group should adopt the same segmented voice (or a consistent chip token) — pick one and apply to both choose-one controls.
- [ ] Fix wrapped button label "Forcer le plein écran" (wraps inside its button): `whitespace-nowrap` + adequate width, or shorten the label.
- [ ] Toggle switches: tokenize (`bg-action` active, `bg-border-strong` inactive).
- [ ] Align the settings column with the page header; ensure bottom rows aren't clipped (scroll padding).
- [ ] Migrate colors to tokens.
- [ ] Run `pnpm vitest run src/pages` for any SettingsPage test.
- [ ] Commit `feat(pos): SettingsPage — unify controls, fix wrap, tokens`.

---

## Wave C — Consolidation (I drive this after merging all B streams)

### Task C: Spec, ratchet, verify
- [ ] Write `apps/pos/docs/design-language.md`: color grammar, surface scale, the button/badge/segmented/statusPill recipes, type roles + `tabular-nums` rule, touch-target minimums (≥48px tactile / ≥40px desktop), and a PR checklist (contrast 4.5:1 text / 3:1 UI; tokens only).
- [ ] Add all migrated dirs (ProductCard, ProductGrid, Header, AppShell, fiscal/UnsyncedRiskIndicator, customers, CashPaymentScreen, NumPad, SettingsPage, Modal) to the ESLint **error** override block in `eslint.config.js`.
- [ ] Run scoped checks: `cd apps/pos && pnpm typecheck` (global tsc — now safe, all agents done) and `pnpm lint 2>&1 | tail -40`. Fix any token/typing fallout. Do NOT run the full backend PHPUnit suite (laptop-crash rule); `pnpm vitest run` for pos is acceptable but run targeted first.
- [ ] Run `pnpm vitest run` (full pos JS suite) once; fix regressions.
- [ ] Commit `chore(pos): design-language spec + ratchet color guardrail to error on migrated dirs`.
- [ ] Summarize remaining deferred work (report components migration; visual-regression harness) for the owner.

---

## Self-review notes
- Spec coverage: every Hallmark audit finding (7 critical / 12 major / 5 minor) maps to A1/A2 (tokens+guardrail+surface scale), B1 (clip, side-stripe, stock colors), B2 (toolbar, pill, sellable-first), B3 (header hierarchy), B4 (banner, success-color discipline), B5 (i18n, scrim, jargon), B6 (CTA disabled, keypad, change, denominations, 000, tabular-nums), B7 (segmented control, wrap), C (spec, alarm-fatigue grammar codified).
- Deferred (explicit): report-component color migration; grid virtualization already present (no change); visual-regression harness (noted for owner).
- Type consistency: token names are fixed in the vocabulary table and reused verbatim across all tasks.
