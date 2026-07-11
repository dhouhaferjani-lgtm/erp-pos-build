# Track B — IziPOS Caisse visual redesign (handover, 2026-07-08)

Paste the block below into a fresh `claude` session started from `apps/erp/apps/pos`. Track A (backend enrichment→product-image feature) runs in parallel in the `apps/erp.enrichment-images` worktree — do NOT touch `apps/api`.

---

You are picking up **Track B — the IziPOS Caisse (checkout) visual redesign** for the AutoERP/IziPOS Tauri POS at `apps/erp/apps/pos`. A parallel session is separately building the backend enrichment→product-image feature (Track A, in the `apps/erp.enrichment-images` worktree on `apps/api`) — DO NOT touch `apps/api` or that worktree; they don't overlap with this frontend work.

## What this is
The owner audited the caisse screen and found it "rough, cramped, not contrast-friendly for a 15" touchscreen POS." Primary target = **15" touchscreen** (retail lighting, glare); secondary = normal desktop + mouse. Tenant in the screenshots is a **Tunisia parapharmacy** (PharmaBio), French UI, cash-first, running a **green accent**. The owner explicitly chose to **RE-OPEN the visual direction** — this is a genuine design exploration, NOT just enforcing the prior caisse-redesign spec. Treat the existing track's locked decisions as *inputs to challenge*, not gospel.

## FIRST STEP (required)
Invoke the **superpowers:brainstorming** skill before any code or plan. This is design work — establish the visual direction with the owner (offer the visual companion when a question is better shown than told), then spec → writing-plans → execute. Also read the memory files `project_pos_design_polish_and_enrichment_images` (the audit + decisions) and `project_pos_caisse_redesign` (the existing track, its `/theme-preview` harness, and locked decisions).

## Audit findings to design against (code-grounded, Tauri POS = apps/erp/apps/pos)
1. **Customer chip cramps** when a customer has points + balance: `src/components/customers/CartCustomerControl.tsx:33` — single non-wrapping flex row `gap-1`, only the name is `min-w-0 truncate`; loyalty badges (`CustomerLoyaltyBadge.tsx`), wallet, and detach are all `shrink-0`, and the wrapper (`TransactionCart.tsx:137`) is `shrink-0`, so they collide. The cart icon is also clipped at the left screen edge.
2. **"Rappeler" clips** to "Rapp": `src/components/molecules/QuickActions/QuickActions.tsx` — three `flex-1` buttons with no `min-w-0`, base `Button` (`src/components/ui/Button.tsx`) has no `whitespace-nowrap`/`truncate` and fixed `px-4`, and `overflow-x-auto` hard-clips.
3. **Green overload (biggest issue):** this pharmacy tenant's *accent* is green, and green ALSO = success AND stock-ok (`--success` and `--stock-ok` are the same hex `#1f8a5b`). So prices, selected-card tint, "Stock: 12", "Service #1", and the "Paiement en espèces" button are all near-identical greens doing 4 different jobs. Root cause: **prices are drawn in `text-accent-strong`** (`src/components/molecules/ProductCard/ProductCard.tsx:409`), which VIOLATES the codebase's own documented rule (`src/lib/designTokens.ts:14` "prices use text-ink, never accent"). Fixing prices→`text-ink` is the single highest-leverage change and also improves contrast (black ~15:1 vs green ~4:1).
4. **Inconsistent stock badge:** "Stock : 12" (green, with number) vs "Stock faible" (amber, no number) — two formats for one concept (`src/components/ui/StockBadge.tsx`).
5. **Duplicate "Caisse" nav labels** — top nav item and bottom wallet item both "Caisse" (`src/components/NavRail.tsx`).
6. **Low-contrast / inconsistent touch targets:** 10px uppercase brand labels (`ProductThumb`/`ProductCard`), "Stock au <1m" freshness, and the lavender initials placeholder (`src/components/ui/ProductThumb.tsx:60` hardcodes `bg-[#eef3f8] text-[#5e6670]`, bypassing tokens) all read washed-out; header icon buttons + the per-card eye overlay are well under a comfortable touch target while the 72px nav and `lg` pay button are large — inconsistent sizing.

## Design system context
- Tokens: `src/lib/designTokens.ts` + raw vars in `src/index.css` (Tailwind v4 `@theme inline`; `[data-theme]`, `[data-accent=orange|green|blue|teal]`). CTA/`--action` is ocean-blue `#1a6fb5`; accent is a separate swappable highlight (green for this tenant). Stock has its own `--stock-*` family. Header `src/components/Header.tsx`, footer `src/components/pos/PaymentSummary.tsx`, screen assembled in `src/pages/HomePage.tsx`.
- Visual verification: run `pnpm dev` (Tauri devUrl :1420) and open `/theme-preview` (auth-free gallery with a seeded ProductGrid), or drive real Tauri. The authed shell needs online+login (offline gate blocks headless). Playwright recipe: `npm i playwright-core` in a scratch dir + `chromium.launch({channel:'chrome'})`.
- **Real product images are coming** from Track A (parapharmacy products with photos), so design the product card for real images, and validate against them once Track A lands — the current "AS/A6" initials are just empty-data placeholders.

## Constraints
- No hardcoded strings — all text via `t()` (react-i18next, `src/locales/fr/pos.json`).
- Design tokens only — no hardcoded Tailwind color classes (`designTokens.ts` tokens, ESLint-enforced in new dirs).
- Branch discipline: base a worktree on `origin/dev` (do NOT resume the stale `feat/pos-caisse-redesign` branch, it's 100+ commits behind); TDD where testable (Vitest); verify visually before claiming done (jsdom misses layout).
- 15" touchscreen first: aim for the prior track's ergonomics research (48px touch floor, AAA 7:1 contrast for glare) but feel free to revisit.

Start by invoking superpowers:brainstorming and talking through the visual direction with me.
