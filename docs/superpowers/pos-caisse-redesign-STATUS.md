# POS Caisse Redesign — STATUS / Resume (crash-recovery anchor)

> Single source for "where are we, how to resume." Updated each work session. If the
> laptop restarts, read this first, then the tracker + spec.

**Last updated:** 2026-06-28
**Branch:** `feat/pos-caisse-redesign` · **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp.pos-caisse` (off `origin/dev`) · **Not pushed** (local-first per branch discipline).

## How to resume
```bash
cd /Users/houssamr/Projects/syneriva/apps/erp.pos-caisse
git log --oneline origin/dev..HEAD     # what's done on this branch
git status                              # clean?
cd apps/pos && pnpm install             # if node_modules missing after restart
pnpm typecheck && pnpm vitest run src/lib/__tests__/theme.test.ts src/components/ui/__tests__
pnpm dev                                # http://localhost:1420/theme-preview  (DEV gallery, no auth)
```
Authed POS screens need online + login (offline gate blocks); **`/theme-preview` is the auth-free visual harness** for tokens/atoms in both themes.

## Key docs (all committed on this branch)
- Spec: `docs/superpowers/specs/2026-06-27-pos-caisse-redesign-design.md`
- Per-screen tracker (SoT): `docs/superpowers/pos-caisse-redesign-tracker.yaml`
- Reviews: `docs/superpowers/audits/2026-06-28-pos-caisse-p1-theme-codex-review.md`
- Handovers (full-stack feature plans, await owner review): `docs/superpowers/specs/2026-06-28-parapharmacy-merchandising-handover.md`, `...-loyalty-pos-gating-offline-handover.md`
- New-session kickoff prompts: `docs/superpowers/kickoffs/2026-06-28-parapharmacy-merchandising-kickoff.md`, `...-loyalty-pos-kickoff.md`
- Project memory: `~/.claude/.../memory/project_pos_caisse_redesign.md`

## DONE (committed)
- **P1 theme foundation** — light/dark + accent/corner/density tokens (Tailwind v4 `@theme inline`, runtime-verified), self-hosted fonts (Montserrat/Public Sans/IBM Plex Mono), ergonomic type/touch scale, ThemeProvider + Appearance settings, stock-token exception. Codex-reviewed + fixed.
- **Atom library COMPLETE** (TDD, both themes verified in /theme-preview): `StockBadge`, `ProductThumb`, `Avatar`, `Stepper`, `Pill`, `Tabs`, `Toggle`, `KpiCard`, `BreakdownBar`, `Divider` (+ reuse existing `Button`/`IconButton`/`Badge`/`SegmentedControl`/`StatusPill`/`MoneyInput`). Only shells (`ModalShell`/`Drawer`/`Toast`) remain — build during the modal phases. DEV `/theme-preview` gallery shows them all.
- **Owner decisions applied:** CTA stays **blue** (`action`=blue; `accent`=separate swappable highlight); cart-line uses **mock collapse/expand**.
- **Two feature handovers + kickoff prompts** written (parapharmacy, loyalty).

## IN PROGRESS / NEXT (this session continues the redesign UI; data features go to new sessions)
- **P2 NavRail** — component BUILT + verified (TDD + /theme-preview sell-layout). **Pending: wire into AppShell** (replace flex-col layout with rail + header + main; route Caisse/Clients/Rapports/Caisse-Shift) — needs a logged-in visual pass.
- **P3 cart-line collapse/expand** — DONE: `CartLineItem` collapse/expand (mock pattern) + `TransactionCart` accordion state. Verified in /theme-preview. Remaining P3: full `TransactionCart` panel restyle (header/quick-actions/summary), and `ProductGrid`/`ProductCard` + displayMode/density reconcile — **ProductCard/ProductGrid deferred to coordinate with the parapharmacy session** (they render brand/skin and would conflict).
- **Header restyle** (P2) — NOT started (62px, dividers, operator Avatar, ghost actions); store-coupled, needs logged-in verification. Keep the B3 single status-pill.
- **Still queued:** P4 payments, P5 modals (+ ModalShell/Drawer/Toast shells), P6 returns, P7 reports. Leave **seams** for parapharmacy (Filtres/skin-advice/detail-tabs) + loyalty (points display) — gated/empty until those sessions land the data.
- **Conflict-avoidance with the 2 live data sessions:** stay on shell/cart/nav (redesign-only) files; avoid `product.ts`/`customerTypes`/`syncService`/`productRepository`/`migrations.ts`/`companyConfig`/customer pages and `ProductCard`/`ProductGrid` (parapharmacy owns data rendering there). Rebase onto dev as they land.
- **New session A (parapharmacy):** kickoff prompt above. Owns data model + seed + offline sync + data-bound UI.
- **New session B (loyalty):** kickoff prompt above. Land `feat/loyalty-earn-per-product` first, then POS gating + offline balance mirror + earn estimate.
- **Shared prereq (one owner):** wire orphaned `pullCustomers()` into `runFullSync()` — needed by both A (customer skin_type) and B (loyalty balance). First to land owns it; other rebases.

## Session pause 2026-06-28 (resume here)
Done since last note (all committed, tree clean, tests/typecheck/lint green):
- **P2 NavRail wired into AppShell** (rail opposite cart; `/customers` route + placeholder `CustomersPage` — parapharmacy/loyalty build out P8 in place).
- **P3 cart-line collapse/expand** (`CartLineItem` + `TransactionCart` accordion) — verified in `/theme-preview`.
- **Owner access constraints (points 1/2):** nav `Caisse-Shift` (Z-report history) = managers only (`isManagerRole`) + `/reports/z` route guarded; **SECURITY fix:** `EndOfDayPreviewModal` no longer leaks `expected_cash` via the legacy card during a blind count (+2 regression tests).
- **Owner point 3:** read-only **`SaleDetailModal`** + "Voir" action in `TodaySalesPanel` (view a past sale's lines/totals/payments without a DUPLICATA reprint).
- **Coordination handoff** for `ProductCard`/`ProductGrid` → parapharmacy session: `docs/superpowers/kickoffs/2026-06-28-coordination-productcard-grid-handoff.md` (reachable via `git checkout feat/pos-caisse-redesign -- <path>`).

NEXT on resume:
- **Header restyle (P2)** — NOT started (62px, group `Divider`s, operator `Avatar`, ghost actions; keep the B3 single status-pill + all EOD/reports/manager-PIN logic). Also gate the **X-report / manager-only items in the Header `ReportsMenu`** (X-report payload is safe but should still be manager-gated per point 1).
- Full `TransactionCart` panel restyle; P4 payments; P5 modals (+ shell atoms); P7 reports restyle (tokenize `TodaySalesPanel`/`SaleDetailModal` already tokenized).
- `ProductCard`/`ProductGrid` → owned by the parapharmacy session (handoff sent).
- **Verify all authed-screen layouts in a logged-in session** (offline gate blocks them here): nav-rail layout, header, cart, sale-detail, blind-close.
- React-doctor `--diff` warnings (button-type, only-export-components, untokenized `TodaySalesPanel`) — triage during the P7 restyle.

## Deferred touch-ergonomics fixes (owner feedback 2026-06-28, live `pnpm tauri dev`)
- ✅ **DONE (commit 834d73a33 / ref cleanup 791209cc5):** Cart-line remove ✕ moved OUT of the collapsed header (was next to the expand chevron → mis-tap) INTO the expanded controls (left, separated from the +/- · discount cluster). The whole collapsed header row is now a single `min-h-[48px]` expand/collapse `<button>` — tapping to expand can no longer delete the line.
- ✅ **DONE:** Configurable confirm-on-delete — `settingsStore.confirmLineDelete` (default ON) + a **Touch & Display** toggle in `SettingsPage`. When ON, the delete control arms on the first tap and removes on a confirming second tap (auto-disarms after 3s / on collapse). i18n `cart.confirmRemoveItem`, `settings.confirmLineDelete*` (en+fr). Chose two-tap-arm over optimistic-delete+Undo because the Toast shell isn't built yet (P5).
- ✅ **DONE (partial):** 48px floor applied to the new cart-line expanded controls (delete/discount/modifier) + the collapsed row min-height.
- ⏳ **REMAINING:** `ReturnLineItem` (refund section in `TransactionCart.tsx`) still uses `h-7`/28px qty+remove buttons — left for the **P6 returns restyle** (deferring to avoid unverified layout regressions in the unrestyled returns flow).
- (owner: "some other stuff" — capture more as they surface during the visual passes.)

## Session pause 2026-06-28 (cont. — resume here)
Done since the last pause (all committed on `feat/pos-caisse-redesign`, tree clean, typecheck + lint + react-doctor + targeted tests green):
- **Cart-line touch-ergonomics** (834d73a33, 791209cc5) — see the updated "Deferred touch-ergonomics fixes" block above (remove relocated, configurable confirm-on-delete). 9 CartLineItem tests + 2 settingsStore tests; TransactionCart test mocks the settings hook.
- **Header restyle (P2 §5.0)** (16664cec8) — 62px bar, `font-display` wordmark, decorative `aria-hidden` online dot, vertical `Divider` atoms between right-zone clusters (shift · operator · actions), operator `Avatar`. ALL logic preserved (B3 StatusPill, sync, StockFreshness, shift→EOD, manager-PIN, every modal/handler). Header.test.tsx green. Tokens-only (passes the ESLint token guard).
- **ReportsMenu manager-gating** (db2936fb3) — X-report + cash-drawer ops + Z-report history are manager-only (`isManagerRole(operator?.roles)`); transaction-history + today-sales stay open. New ReportsMenu.test.tsx (3 tests). NOTE: ReportsMenu still has hardcoded colors (bg-white/text-gray-*) — deliberately NOT tokenized here (gating-only scope); **P7 reports restyle owns the tokenization**.
- **P5 modal-shell foundation** (7aa7dfca7) — started the P5 shell consolidation on the canonical `components/pos/Modal.tsx` (13 consumers inherit): reduced-motion-aware entrance keyframes in `index.css` (`ez-fade-in` 160ms / `ez-sheet-rise` 200ms `cubic-bezier(.2,.8,.3,1)`, per spec §5 timings) applied to backdrop+content (ENTRANCE-only; exit transitions need mount-persistence → deferred to per-modal restyle); close ✕ 40px→48px (§6 floor). Additive — 38 modal-consumer tests green. **ProductCard/ProductGrid + product-detail fiche stay owned by the parapharmacy session (Phase D, rebase-gated on this branch) — do NOT touch.**
- **Code review FOLDED** (commit e49e34f9c): Codex detached-runtime produced NO output again (known-unreliable) → used a Claude reviewer agent per project precedent; findings → `docs/superpowers/audits/2026-06-28-pos-cart-line-header-review.md`. Verdict: 0 Blocking. Folded I1 (cleaner than the suggested revert — extracted a self-contained `CartLineRemoveButton` child so collapse-unmount resets the guard; eslint + react-doctor both clean) + M4 (operator Divider no longer orphans). Deferred to owner judgment: M2 (decorative online dot vs B3 single-pill — keep, aria-hidden) + M3 (decrement-to-zero bypasses confirm — by-design quantity gesture).

- **✅ Owner visual pass PASSED** (2026-06-28, live tauri, both screenshots) — Header/cart/collapse-expand/relocated-delete all confirmed correct. Online dot + decrement-to-zero accepted as-is.
- **✅ BATCH PROMOTED to origin/dev** (`6ad6114ca..e95171040`, clean ff, 31 commits). origin/dev == local dev == feat/pos-caisse-redesign. Parapharmacy/loyalty sessions can now rebase.
- **✅ TransactionCart panel restyle + PROMOTED** (`e95171040..0c2d9e98c` on origin/dev): quick-actions decluttered to the mock §5.1 THREE labelled actions + "Reprendre+count"; Returns/Clear as compact icons (fixed the "Retour/Échange" truncation); mono summary typography; deleted the dead legacy `pos/QuickActions.tsx`. Review clean (0 Blocking/Important). `docs/superpowers/audits/2026-06-28-pos-cart-panel-restyle-review.md`.
- **✅ P4 payment-modal TOKEN DETOX set complete** (1ee3854d4, 0d424cf7b, d0dab0155; **+3 ahead of origin/dev, NOT yet promoted**): all four payment modals tokenized → dark-mode ready + added to the ESLint token guard. `CashPaymentScreen` ALSO restyled to the mock §5.2 **navy summary panel** (`--pay-navy`). `AdvancedPaymentsModal` (916L) full detox incl. spec-§65 `primary-*` sweep (selected method/repo → accent; CTA → action; input focus → accent) — fork-migrated per my mapping, I swept primary-* + reviewed the full diff; 35 tests green. `CardPaymentModal` + `CheckoutSuccessModal` detoxed (no dedicated tests; guard is the gate). NOTE: pre-existing `parseFloat`-on-money in `CashPaymentScreen` left for the precision-remediation track (out of visual-restyle scope).

NEXT on resume:
- **✅ P4 visual pass — owner signed off** (2026-06-28, CashPaymentScreen light screenshot: navy panel + mono amounts confirmed). **Deferred polish (owner: "use the space a bit better, but fine for now"):** the full-screen payment layouts have dead space — the navy summary panel has empty vertical room (content centred) and there's a gap between the numpad and the Valider button. Fold into the payment-screen LAYOUT phase (distribute the navy panel content; let the numpad fill / anchor Valider better). NOT urgent. (Dark mode + Advanced/Card/Success modals not individually screenshotted — flag if anything looks off there.)
- **AdvancedPaymentsModal 3-column LAYOUT restyle** (deferred — detox shipped first): method+repo / amount+numpad / total+lines+Finaliser per mock §5.2. Authed-verify-heavy. (Address the space-usage feedback here too.)
- **PaymentSummary mock §5.1 breakdown — NEEDS A DECISION (deferred):** split discount into **Remises produits** (line) vs **Remise panier** (transaction) — needs HomePage to plumb both; and the **"dont TVA 19%"** framing — HT/TTC fiscal-display + mixed VAT rates (current generic "TVA" line is safe). Don't change money display unilaterally; confirm with owner.
- Customer/loyalty card slot in the cart = seam owned by the loyalty session.
- `ProductCard`/`ProductGrid` + product-detail fiche → parapharmacy session Phase D (do not touch).

## ✅ PAGE-CONSISTENCY + TOUCH-ERGONOMY BATCH (2026-06-29; owner feedback + audit)
Owner: inconsistent sub-page headers (Settings back centred + narrow vs Ventes-du-jour left + full-width), Settings should use the full width + section nav + (maybe) a Save button, and "do a full touch-screen ergonomy audit — some elements deserve to be clear buttons with clear contrast." (+5 ahead of origin/dev when written.)
- **Shared `components/PageHeader`** (left back @48px, title, actions slot, full-width, tokenised) → applied to TodaySalesPanel, **ZReportListPage** (also fully tokenised + gained a back button + guard), and **SettingsPage**.
- **SettingsPage redesign**: full-width two-column layout — sticky left **section nav** (scrolls to + highlights each section) + wider max-w-3xl content; section anchor ids; redundant bottom back button removed. 14 tests green.
- **Touch-ergonomy** (audit `docs/superpowers/audits/2026-06-29-pos-touch-ergonomy-audit.md`): ROOT-CAUSE fix — `IconButton`/`Button`/`SegmentedControl` + the segmented token `md` were 44px (4px under §6 floor) → **48px** (clears the bulk app-wide). Then the custom non-atom controls: **21 raw <button> Cancel/Confirm/submit/menu-row** targets across EOD/Refund*/Voucher/CloseShift/CashDrawer/ReceiptLocator/OpenShift/ReportsMenu → `min-h-[48px]`; PaymentSummary remove-discount sm→md; **TodaySales Voir/Réimprimer** faint text → clear bordered Buttons; **Header shift chip** tappable-Badge → 48px button w/ hover. (Fork-assisted for the mechanical bumps; combined diff verified sizing-only.)
- **DEFERRED (genuine design fork — flagged, not guessed): the Settings explicit Save button.** The page mixes preference toggles (draftable) with async actions (printer scan, device unbind — not draftable) + live-preview appearance settings; a clean draft/Save needs the owner's call on which settings are pending-until-saved vs immediate.
- **Audit minor remainder (taste/low): Header ghost action icons** are now 48px but still ghost (no border/fill) — left as the deliberate clean-header design; owner can opt for bordered. Modal/ReportsMenu faint-icon contrast is minor.

## ✅ FOLLOW-UP BATCH — layout/breakdown/shells (2026-06-29; owner "fold in as much as possible")
- **✅ Dark-mode visual pass PASSED** (owner, 2026-06-29 — sell/cash/success/returns screenshots in dark): token mapping held, no contrast/semantic issues.
- **CashPaymentScreen space-usage** (owner-flagged): numpad fills its column (`h-full auto-rows-fr`, no dead gap above Valider), denominations at the 56px floor, navy amounts bigger (text-5xl/text-4xl).
- **PaymentSummary §5.1 discount split**: new bc-math cartStore selectors `grossSubtotal*`/`lineDiscountTotal*` (no float; TDD) → Sous-total (GROSS) · Remises produits · Remise panier (removable) · dont TVA · Total. **Kept generic "TVA" label** (owner decision — "dont TVA 19%" wrong on mixed rates; VAT is TTC/"of which"). Non-discount sales unchanged. en+fr keys.
- **AdvancedPaymentsModal**: 3-column layout was already present (detox tokenized it); polished — amount-column numpad fills + mono amount (matches cash screen).
- **P5 Drawer shell** (`components/ui/Drawer`): slide-in panel (backdrop fade + 240ms `ez-slide-in-*`, focus-trap, Esc/backdrop close, 48px close, i18n close label, footer) for the parapharmacy Filtres/fiche + future use. TDD (5) + DEV /theme-preview demo. **Toast: intentionally NOT built** — sonner is already the app's toast system (a parallel atom would fragment it); a tokenised sonner theming pass is an optional follow-up.
- All green (typecheck + eslint-guarded + tests + react-doctor); **+4 ahead of origin/dev** (CashPaymentScreen space, PaymentSummary split, AdvancedPayments polish, Drawer).

## ✅ DARK-MODE TOKEN-DETOX SWEEP — P4-P7 (2026-06-28, batched; PROMOTED to origin/dev)
Owner asked to batch a lot then check. Detoxed **14 surfaces** → semantic tokens, ALL added to the ESLint token guard (dark-mode ready + regression-proof). Every file's own tests pass; big files fork-migrated per my mapping table, combined diffs verified COLOR-ONLY (no logic/JSX/testid/aria); typecheck + eslint(guarded) + react-doctor clean throughout.
- **P4 payments** (1ee3854d4, 0d424cf7b, d0dab0155): CashPaymentScreen (+navy restyle), AdvancedPaymentsModal (916L), CardPaymentModal, CheckoutSuccessModal.
- **P5 modals** (7671bc8d6): LineDiscountModal, DiscountModal, HeldTransactionsModal.
- **P6 returns** (7671bc8d6): RefundCheckoutFlow + ReturnLineItem 48px floor.
- **P7 reports/shift** (b3f752afe, 074df5294): TodaySalesPanel, ReportsMenu, ZReportModal, EndOfDayPreviewModal, CashDrawerModal, XReportModal, CloseShiftModal.
- Consistent fork conventions: selection-highlight→accent, segmented-control tabs→surface-raised active, info boxes→action, CTAs→action, confirm-green→success, destructive→danger, warnings→warning, high-value money amounts kept high-contrast text-ink.
- **⚠️ The CloseShift fork accidentally ran the FULL vitest suite** (no-full-suite rule): reported 6 pre-existing failing files (2505/2511 pass) — NOT from these color-only changes (all touched-file tests pass; diffs color-only; repo has a `triage/pg-suite-98-failures` worktree of known fails). Confirm via CI/triage.
- **NEEDS: a DARK-MODE visual pass** on the detoxed payment/discount/returns/reports/shift screens (the detox's payoff; not yet eyeballed in dark).
- **✅ TAIL DONE** (53e86ea7c) — the remaining 16 mine-lane surfaces detoxed + guarded: VoucherTenderModal, CashTenderedModal, ReceiptLocatorScreen, ReceiptScanConfirmationSheet, RefundConfirmModal, RefundDestinationPicker, ResumeRefundDraftBanner, QuantityNumpad, CurrencyNumpad, ModifierSelectionModal, CashReconciliationSection, CashCountTable, OpenShiftScreen, ManagerPinPanel, VariantPickerModal, ToleranceDrillDown. **POS dark-mode detox COMPLETE across sell/payment/discount/returns/reports/shift (~30 surfaces this session).** (CashCountTable.test.tsx: 2 class-string assertions updated to tokens.)
- **HANDOFF to parapharmacy session** (do NOT detox here — they own the dark-token decisions): `ToastSmartPrompts` (indigo AI-suggestion identity + `bg-gray-900/95` dark-glass panel), `ProductDetailDrawer`, `ProductVariantStockView`, `CrossLocationStockSection`.

## Other NEXT (deferred, need decisions / authed-verify)
- AdvancedPaymentsModal 3-column LAYOUT restyle (detox shipped; layout deferred — also fold the CashPaymentScreen space-usage feedback here).
- PaymentSummary §5.1 breakdown — NEEDS owner decision (discount split + "dont TVA 19%" framing).

## Guardrails (always)
Worktree off dev; merge to LOCAL dev first, promote to origin/dev as clean fast-forwards; never force-push dev. NEVER run the full PHPUnit suite (crashes laptop) — run by path. Tokens only in `apps/pos/src` (ESLint guard); i18n all strings; TDD; Codex for CODE review (Claude agent for DOC review). Commit frequently (crash-safety).
