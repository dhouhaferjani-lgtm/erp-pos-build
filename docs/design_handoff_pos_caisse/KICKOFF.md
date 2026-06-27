# Claude Code Kickoff — IZI POS "Caisse Parapharmacie" redesign

You are implementing a **visual + UX redesign** of the existing POS sell flow in
`apps/pos` of the `otospexsolutions/erp` monorepo (Windows / Tauri 2 desktop app,
React + TypeScript). A complete, interactive **HTML design reference** is included in
this bundle: `IZI POS - Caisse Parapharmacie.dc.html`. Open it in a browser to see
every screen, state, and interaction. `README.md` documents tokens, layouts,
components, and a full parity matrix.

## Your mandate

1. **Recreate the design in the real codebase** — do NOT ship the HTML. Re-implement
   it in the app's existing React/TS environment, following the project's established
   **atomic design** structure (`components/atoms`, `components/molecules`,
   `components/organisms`, `components/pos`, `components/settings`).
2. **Preserve 100% functional parity** with today's POS. The redesign changes look,
   layout, theming, and a few interaction patterns — it must not drop any existing
   capability. Use the parity matrix in `README.md` §7 and verify against the current
   organisms before/after.
3. **Reuse existing components and logic.** The repo already has organisms for almost
   every screen in the mock (`AdvancedPaymentsModal`, `CardPaymentModal`,
   `CashDrawerModal`, `CashPaymentScreen`, `CheckoutSuccessModal`, `CloseShiftModal`,
   `DiscountModal`, `LineDiscountModal`, `HeldTransactionsModal`, `PaymentSummary`,
   `ProductDetailDrawer`, `ProductGrid`, `QuantityNumpad`, `ReportsMenu`,
   `TransactionCart`, `VoidReturnModal`, `XReportModal`, `ZReportModal`). Restyle and
   re-lay-out these in place — keep their data flow, hooks, stores, and IPC intact.
   Do not rewrite business logic to match the mock's simplified demo logic.
4. **Theme via tokens, not hardcoded colors.** Implement the Light/Dark palettes and
   the accent/corner/density options from §6 as real theme tokens (CSS variables or
   the project's theming layer). The mock proves the whole UI flips cleanly from one
   variable set.

## Order of work (suggested)

1. **Theme foundation** — define the token set (§6): light + dark palettes, accent
   options, corner-radius scale (Rounded/Sharp), density. Wire a theme provider +
   persisted setting. Everything else consumes these.
2. **App shell + nav rail** — left/right rail (Caisse, Clients, Rapports, Caisse/Shift)
   that sits opposite the cart, theme toggle. (§5.0)
3. **Sell screen (Caisse)** — restyle `TransactionCart`, `ProductGrid`, `ProductCard`,
   header, search, category pills, filters drawer, skin-advice bar. (§5.1)
4. **Payment flows** — full-screen cash (`CashPaymentScreen`), advanced/mixed
   (`AdvancedPaymentsModal` w/ repository selection + per-method config + card
   sub-flow via `CardPaymentModal`), checkout success. (§5.2)
5. **Cart-adjacent modals** — line discount, cart discount, hold/recall, customer
   picker + add customer. (§5.3)
6. **Returns** — `VoidReturnModal` flow: pick past ticket → select return lines → add
   replacement items → settle. (§5.4)
7. **Reports / Shift** — `ReportsMenu`, sales history list, `XReportModal`,
   `ZReportModal` / `CloseShiftModal` reconciliation. (§5.5)
8. **Customers page** — browse / search / detail with history + edit. (§5.6) ← see
   "new surfaces" note below.

## What is NEW vs. the current app (build or confirm these exist)

The mock introduces a few things that may not exist yet — confirm against the codebase
and implement if missing (flagged in README §8):

- **Customer management as a full page** (browse, detail, purchase history, edit,
  skin-type & advice notes, store credit). Today there is customer *selection*; a full
  management surface may be new.
- **Parapharmacy merchandising**: brand/category/routine/skin **advanced filters**,
  the **skin-type advice bar**, and the product detail **Équivalents / Compléments /
  Routine** tabs. Confirm whether product data carries brand, skin-type, routine, and
  cross-sell relations; add fields/endpoints if not.
- **Dark theme** across the app (if the current build is light-only).
- **Cosmetic upsell affordances** in `ProductDetailDrawer` (equivalents + complementary
  products with one-tap add).

## Guardrails

- Match the codebase's existing libraries (state store, router, icon set, i18n) — the
  mock's inline SVGs/fonts are placeholders; map them to the project's icon system and
  Montserrat / Public Sans / IBM Plex Mono (or the app's configured fonts).
- Keep i18n: all mock copy is French (Tunisia market, cash-first). Route strings
  through the existing i18n layer; don't hardcode.
- Currency is **TND** formatted `1 234,500 DT` (3-decimal millimes, space thousands,
  comma decimal). Reuse the app's existing money formatter.
- Touch target floor 44px (terminal is 15"+ touchscreen). Payment numpads are
  full-screen by design.
- Don't regress offline-first behavior, sync, shift/session handling, or receipt
  printing — restyle around them.

## Definition of done

- Every screen in §5 re-implemented in the codebase with the new look in **both
  themes**, using atomic components.
- Parity matrix §7 fully green (every existing capability still works).
- New surfaces from §8 either implemented or explicitly ticketed with notes.
- No hardcoded theme colors; all via tokens.
- Type-checks, lints, and existing tests pass; new components have stories/tests per
  repo convention.

Start by reading `README.md` end to end, then open the HTML reference and click through
every flow before writing code.
