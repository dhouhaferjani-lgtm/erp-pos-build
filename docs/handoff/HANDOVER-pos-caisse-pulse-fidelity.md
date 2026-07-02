# HANDOVER — POS "Caisse Parapharmacie" (Pulse) design-fidelity + touchscreen pass

**Executor:** Codex · **App:** `apps/pos` (Tauri touch POS) · **Created:** 2026-07-02
**Companion:** `docs/handoff/pos-caisse-pulse-gap-analysis.md` (read it — the grounded per-screen gap detail).

## Mission
The POS sell flow was built against a *partial* design export and drifted. Implement the
**authoritative Caisse design faithfully, once**, and make **every POS screen touch-first**.
No more one-off patches — build each screen to the mock and verify it visually.

## Source of truth (design)
`docs/design_handoff_pos_caisse/IZI POS - Caisse Parapharmacie.dc.html` is THE touch-POS design
(open it in a browser or read the HTML). `IZI POS Design System.dc.html` = tokens/atoms reference.
**OUT OF SCOPE (these are `apps/web` back-office, do NOT build in `apps/pos`):** `IZI POS - Add Product*`,
`IZI POS Product Page`, `Easy Pulse Dashboard`. (A separate session owns the web product-UI.)

## Owner decisions (LOCKED — do not relitigate)
1. **Scope = everything on the POS Caisse screen** (see Work Items). It's a fidelity + touch pass, not a rewrite.
2. **Skin filtering moves INTO the Filtres drawer** (the design has no skin-pill bar on the sell screen) — AND
   add a **Settings toggle** to enable/disable the parapharmacy skin feature. Filters (skin type etc.) available by default.
3. **Access model: OWNER = highest access, ABOVE manager.** The business owner must see EVERYTHING —
   all reports and every manager-gated surface. Today the owner sees only "sales" (bug — see Work Item 2).
4. **Product detail = centered two-column modal** (not the current right drawer). This is the #1 fix.
5. Executor is **Codex**; design files are frozen in the repo (Codex cannot reach the design tool — a Claude
   session must re-sync if the design changes).

## Ground rules (repo conventions — non-negotiable)
- **Tailwind v4 `@theme`** tokens only (no hardcoded colors); atoms live in `components/ui/`. Design tokens already
  cover the sell screen (`index.css`, `lib/theme.ts`).
- **Touch-first:** 48px min tap target, 64px primary actions; large hit areas; NO hover-only affordances; comfortable spacing.
- **i18n:** all user-facing text via `t()` (react-i18next). French is the launch locale.
- **Money/quantity precision contract:** values are STRINGS end-to-end; never `parseFloat`/`Number()` a price/qty;
  use `formatCurrency`/`MoneyInput`. Prices render mono + "DT" (not `text-blue-600`).
- **Branch discipline:** work in a `git worktree` off `origin/dev`; merge to LOCAL dev first; promote to `origin/dev`
  ONLY as a clean fast-forward; never force-push dev. (A `dev-push-guard` hook enforces this.)
- **Do NOT run the full PHPUnit suite** (crashes the laptop). Backend is barely touched here anyway.

## ★ MANDATORY verification loop (this is why we're redoing it)
jsdom unit tests have **no layout engine** — they let clipping/overlap/oversize bugs ship. Every screen MUST be
verified visually against the mock:
1. **Harness:** `apps/pos/src/pages/ThemePreviewPage.tsx` (DEV route `/theme-preview`, auth-free) already renders a
   **seeded real `ProductGrid`** (see `data-testid="sell-preview"`). Extend it with a seeded instance of each screen
   you touch (product-detail modal, customers, rapports, etc.).
2. **Screenshot recipe (no project dep; MCP browser may be locked):**
   ```
   mkdir -p /tmp/pw && cd /tmp/pw && npm i playwright-core
   # script: chromium.launch({channel:'chrome', headless:true})  → system Chrome, no download
   # newPage({viewport:{width:1366,height:768}, deviceScaleFactor:2})
   # goto http://localhost:<vite>/theme-preview ; screenshot the element; measure via page.evaluate + getBoundingClientRect
   ```
   Run vite on a free port if 1420 is taken: `pnpm exec vite --port 1425 --strictPort`.
   The **Tauri app serves whatever worktree it's launched from** (devUrl :1420, beforeDevCommand `pnpm dev`).
3. **Compare** the screenshot to the `.dc.html` mock; **measure** heights/widths (don't eyeball); iterate until faithful.
   Verify at **1366×768 / 1280×720 / 1024×600**, **light + dark**. Then confirm on the real authed POS.
4. Keep jsdom/vitest tests green too, but they are NOT sufficient — the screenshot is the gate.

## Work items (priority order)

### 1. Product detail → centered two-column modal (STRUCTURAL, owner #1)
Replace the right slide-over (`components/pos/ProductDetailDrawer.tsx`) with a centered modal matching the design
(gap-analysis §"#1 divergence"): ~1080×680, `z-52`, scale-in; **left hero column** (tinted hero + initials/image,
"Rupture" badge, brand eyebrow, name, stock pill + category, **Prix TTC** large mono + "DT", ref/barcode,
**full-width 54px "Ajouter au panier"**); **right tab panel** — **Détails (default)** / Routine / Équivalents / Compléments
with counts. Détails = description + "bon pour" benefit chips + ingredient chips + per-branch availability ("ICI" badge).
Wire the product-card eye + cart-line "Fiche produit" to open it. Reuse a modal shell atom if one exists; else build one.

### 2. Reports visibility + access model (owner = highest)
- **Fix:** the owner must see all reports. Root cause (gap-analysis §Reports): manager surfaces gate on the **PIN
  operator's** role (`isManagerRole(operator?.roles)`), and the demo operator is a **cashier**; also `AppShell.tsx:46`
  routes **Rapports → `/sales`** (a plain list), not a KPI screen.
- Establish an **access hierarchy: owner > manager > cashier.** Owner sees everything (all reports + manager surfaces).
  Decide the correct source of truth for POS access (authenticated owner **user** role vs PIN **operator** role) and make
  the owner top-tier. Ensure the demo owner is provisioned so they actually get owner-level access on the device.
- **Build the designed Rapports screen** (KPI cards Ventes/Transactions/Panier moyen + payment-breakdown bars +
  period/method filters + searchable ticket table) and point the Rapports nav at it (off `/sales`). Confirm whether a
  prior Rapports screen existed and was lost; restore/rebuild to the design either way.
- **Build the Shift/Z-closure screen** (X-report reading + cash-count with expected/écart + "Clôturer le service").
- Keep fiscal `ZReportListPage` reachable; keep manager/X-report gating, but owner is always above the bar.

### 3. Touchscreen optimization pass (cross-cutting, owner-flagged)
Audit EVERY POS screen for touch-first ergonomics; **`CustomersPage.tsx` first** (built web-style: small controls,
list/detail not tuned for touch). Enforce 48/64px targets, large rows, generous spacing, no hover-only UI. Bring each
screen in line with the design's density + the design-system touch rules.

### 4. Skin filtering → Filtres drawer + Settings toggle
Remove the sell-screen skin pill bar (`ProductGrid.tsx:591–619`); fold skin-type filtering into the **Filtres drawer**
("Type de peau" / "Routine conseil") per the design. Add a **Settings** toggle to enable/disable the parapharmacy skin
feature (module-gated where relevant). Keep filters available by default.

### 5. Sell-screen fidelity polish
- Cart panel → **460px fixed** (currently `min-w-[340px] flex-[4]` at `HomePage.tsx:1452`); grid `--grid-cols:6` visual /
  `--list-cols:4` list.
- Product card: verify in-cart accent bar + qty/check badge + top-left fiche eye vs design ~307–328; tap-body = add.
- Prices/totals in ink-strong mono + "DT" (not blue).

## Key file map (`apps/pos/src/`)
- Sell screen: `pages/HomePage.tsx`; `components/organisms/ProductGrid/`; `components/molecules/ProductCard/`;
  `components/organisms/TransactionCart/`, `components/molecules/CartLineItem/`.
- Product detail (to replace): `components/pos/ProductDetailDrawer.tsx`.
- Filtres: `components/organisms/FiltresDrawer/`.
- Customers: `pages/CustomersPage.tsx`.
- Reports/shift: `pages/ZReportListPage.tsx`, `components/pos/ReportsMenu.tsx`, `components/AppShell.tsx` (nav + routes),
  `lib/auth/roles.ts` (`isManagerRole`), `stores/operatorStore.ts`.
- Theme/atoms/harness: `index.css`, `lib/theme.ts`, `stores/settingsStore.ts`, `components/ui/`, `pages/ThemePreviewPage.tsx`.

## Done = 
Each POS screen matches its region of the Caisse mock, verified by screenshot at the 3 resolutions × light/dark;
owner sees all reports; Customers + all screens are touch-first; skin filtering is in Filtres with a settings toggle;
product detail is the centered modal; typecheck + lint + targeted vitest green; promoted to `origin/dev` as a clean ff.
