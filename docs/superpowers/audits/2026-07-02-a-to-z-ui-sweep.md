# A-to-Z UI Sweep — Pre-Demo (2026-07-02)

- **App:** Web (http://localhost:5173), tenant **PharmaBio Tunisie SARL** (Tunisia parapharmacy, IziPOS edition), user `owner@pharmabio.tn` / Jean-Baptiste Mercier.
- **Method:** Driven with an isolated headless Chrome via a Playwright script (the shared MCP Chrome profile `mcp-chrome-f67121c` was locked by the other agent's smoke-test session, so both MCP Playwright servers refused to start — worked around with a separate profile). Every sidebar route crawled twice: once EN, once FR (`?lang=fr` / `localStorage['autoerp-language']='fr'`) to diff untranslated fallbacks. Per page: console errors, full innerText, screenshot.
- **Coverage:** 58 top/list routes + 4 entity detail pages (product, customer, supplier, purchase order) + privacy/login = **~63 pages**. Screenshots and per-page text dumps in the session scratchpad (`sweep-*.png`, `frpage-*.png`, `detail-*.png`, `pagetext-fr/*.txt`).
- **Note on language:** the demo tenant defaults to **English** on a fresh session (localStorage key is `autoerp-language`, `fallbackLng: 'en'`). When switched to FR the UI is ~95% French; findings below are for the **FR** render (the demo language). Because `fallbackLng: 'en'`, any missing FR key renders English text silently rather than a raw key — those are the "English on FR UI" findings.

---

## Global / Chrome (appears on every page)

- **P1 — Email-verification nag banner on all 59 pages.** Yellow banner "Veuillez vérifier votre adresse e-mail… renvoyer l'e-mail de vérification" is pinned to the top of every screen for the demo owner. Screenshot `sweep-dashboard-1.png`. Fix: mark `owner@pharmabio.tn` email as verified in the demo seeder (`email_verified_at`).
- **P2 — Cookie-consent bar overlaps content on every page until accepted.** "Ce site utilise des cookies essentiels…" sits over the bottom of each page. `sweep-dashboard-1.png`. Fix: pre-accept in the demo profile, or dismiss once before demoing.
- **P1 — Dates render in English/US format on the FR UI.** e.g. product "CRÉÉ July 2, 2026"; supplier/PO dates "7/2/2026", "8/1/2026" (M/D/Y). Should be French `02/07/2026` / `2 juillet 2026`. Pervasive on detail pages. `sweep-product-detail-date-1.png`, `sweep-supplier-usdata-1.png`. Fix: route date formatting through the i18n/locale formatter (`toLocaleDateString('fr')`), not a hardcoded/US format.
- **P2 — Decimal-separator inconsistency.** Money uses correct French `0,000 TND` / `3 200,000 TND`, but percentages and some POS figures use dots: product "TAUX DE TAXE 19.00%", "MARGE …(127.3%)"; POS analytics "Ventes brutes 0.00"; owner report "Articles vendus 0.0000". Fix: format percentages/quantities with the same locale-aware formatter.

---

## Dashboard (`/dashboard`)

- **P0 — Recent-documents PO totals show `0,000 TND` while the POs actually have line totals.** Dashboard "Documents récents" lists DEMO-PO-0001…0004 all at **0,000 TND**, but opening DEMO-PO-0004 shows a real total of **850,000 TND** (2 line items, 500,000 + 350,000). The dashboard widget is reading a zero/empty total field. `sweep-dashboard-1.png`. Fix: point the recent-docs widget at the computed document total, not the (unset) header total.
- **P1 — Top-line KPIs are all zero.** Chiffre d'affaires 0,000 TND, Factures 0, Paiements reçus 0,000 TND — yet the GL trial balance shows 1 401,000 TND of sales (707 Ventes) and 182 partners exist. The first demo screen looks empty/broken. `sweep-dashboard-1.png`. Fix: seed a few priced invoices + payments so the dashboard shows life (and reconcile the KPI source with the GL — revenue 0 vs GL 1401 TND is itself an inconsistency).
- **P1 — Document statuses in English on the FR UI.** Recent docs show "Received", "Draft", "Confirmed" instead of "Reçu / Brouillon / Confirmé". Fix: add the `documents.statuses.*` keys to the FR `documents` namespace (see P0 below — some are missing entirely).
- **P2 — Green `$` (dollar) icon on the TND "Chiffre d'affaires" card.** Wrong currency glyph for a dinar tenant. `sweep-dashboard-1.png`. Fix: use a neutral/coins icon or the tenant currency symbol.

---

## Point of Sale (`/pos/...`)

- **P0 — `/pos/transactions` ("Point de Vente → Open POS") shows a full-screen English "POS new-sale flow temporarily disabled" message.** Body: "New-sale receipt authoring is being rebuilt on a device-authoritative fiscal chain…". It is English-only, reveals internal WIP status, and takes over the whole viewport (no chrome). `sweep-pos-transactions-1.png`. The real POS demo is the Tauri desktop app (web POS is retired), but this item is still in the primary nav — a prospect clicking it sees a "disabled" English screen. Fix: translate the message to FR **and/or** hide the "Open POS" nav item in the web build.
- Other POS pages (orders, terminals, shift-history, z-reports, vouchers, analytics) render fine in FR but are all empty (0 tickets / 0.00). Analytics uses dot-decimals (see global P2).

## Purchases (`/purchases/...`, `/inventory/return-notes`)

- **P0 — Raw i18n key on Purchase Order detail.** `/purchases/orders/:id` renders the status badge as the literal string **`documents.statuses.received`** instead of "Reçu". `sweep-po-detail-rawkey-1.png`. This is the only raw-key leak found anywhere. Fix: add `statuses.received` (and audit the whole `statuses.*` set) to `locales/fr/documents.json` and `locales/en/documents.json`.
- PO detail data is otherwise healthy (line items, quantities, unit prices, sous-total = total = 850,000 TND, "Impayée", "Montant restant dû").
- Supplier list/detail render fine (French). See data-quality P2 below.

## Sales (`/sales/...`)

- All list pages (customers, quotes, orders, invoices, credit-notes) and delivery-notes load clean in FR with proper empty states. Invoices/quotes lists are empty (0 rows) — consistent with the empty-dashboard seeding gap (P1).
- Customer detail (`/sales/customers/:id`) renders fully in FR (Aperçu/Documents/Paiements/Acomptes tabs, solde, contact, identifiant fiscal). Data-quality P2 below.

## Catalog & Inventory (`/inventory/...`, `/catalog/...`, `/pricing/...`)

- **Products page is the strongest screen:** 1000 products, "Prix moyen 34,614 TND", real per-row prices, status "Actif". Product detail shows price 44,350 TND, cost (CMP) 19,514 TND, margin 24,836 TND (127,3%), stock 131, stock value 2 556,334 TND — all sane. (Only nits: percentage dot-decimals + English created date, global P1/P2.)
- **P1 — "Price Lists" (`/pricing/price-lists`) is in the sidebar but returns 403 "Access denied".** The owner role lacks the `pricing` permission, yet the nav item renders and lands on an access-denied error. `sweep-price-lists-403-1.png`. Fix: either grant `pricing` to the owner role or gate the nav item behind the permission so it isn't shown.
- **P2 — `catalog/composite-items` nav → redirects to `/dashboard`** (module gated off for this tenant). A nav item that silently bounces to the dashboard is a dead link; hide it when the module is off.
- Categories, attributes, stock, movements, stock-transfers, counting, batches, expiry-write-off, enrichment: all load clean in FR.

## Treasury / Banking (`/treasury/...`, `/expenses`)

- Payments, repositories, instruments, reconciliation, withholding-certs: clean FR, proper empty states ("Aucun paiement", "Les paiements apparaîtront ici…").
- **P2 — Expenses page (`/expenses`) has a duplicated "Catégorie" label** above the category filter dropdown. `sweep-expenses-dupe-label-1.png`. Fix: remove the double `<label>`. (The one-off "spinner" flag on this page in the EN pass was a transient empty-list loader, not stuck.)

## Accounting & Reports (`/finance/...`, `/reports`)

- **Trial balance is a highlight** — real French account names (401 Fournisseurs, 411 Clients, 4457 TVA collectée, 607/707…), balanced totals 6 310,000 = 6 310,000 TND, correct French number format. Good demo material.
- **P2 — "Balance generale" heading missing accent** (should be "Balance générale"). Uppercase column headers "DEBIT/CREDIT" also drop accents (likely intentional).
- Ledger, journal-entries, P&L, balance-sheet, aged-receivables/payables, VAT periods: load clean in FR.
- Owner report (`/reports`) is per-site with correct site list; all metrics zero (no POS sales seeded) → ties to the empty-demo-data P1.

## Parapharmacy (`/parapharmacy/...`)

- Ingredients, certifications, health-claims, key-components: all load clean in FR. No issues found.

## CRM / Marketing / Loyalty / E-commerce

- crm-contacts, loyalty programs/members, promotions, coupons, channels, ecommerce-orders: clean FR.
- **P2 — "Companies" (`/crm/companies`) redirects to `/sales/customers`.** The CRM "Companies" nav item and the Sales "Customers" item resolve to the same screen — likely a duplicate/aliased nav entry worth de-duplicating.

## Settings (`/settings`)

- Fully translated FR settings hub (users, roles, company info, tax, inventory, UoM, import…). No issues.

## Demo data quality (cross-cutting, P2)

- **US phone numbers on a Tunisia tenant:** customer `+1-432-550-2217`, supplier `+1 (267) 955-6823`.
- **Lorem-ipsum text:** supplier notes are Latin filler ("Suscipit quas rerum aliquid eum velit…").
- **Double legal suffixes / mixed languages:** "Considine LLC SARL", "Abernathy, Hayes and Brekke Auto-Entrepreneur"; product names are English ("Anti-Aging Serum Hyaluronic Acid 30ml"). On a French parapharmacy these read as obviously-fake seed data.
- `sweep-supplier-usdata-1.png`. Fix: regenerate demo partners/products with a French (`fr_FR`) + Tunisia faker locale and real-looking parapharmacy product names.
- **P2 — Privacy page still branded "AutoERP"** and lists placeholder contact `privacy@company.com` (product was renamed to Synerivia ERP / IziPOS). `/privacy`.

---

## Top-10 priority list (fix before demo)

1. **P0** `/pos/transactions` full-screen **English "POS temporarily disabled"** — translate or hide the "Open POS" web nav item. (`sweep-pos-transactions-1.png`)
2. **P0** **Raw key `documents.statuses.received`** on PO detail — add missing `documents.statuses.*` FR/EN keys. (`sweep-po-detail-rawkey-1.png`)
3. **P0** Dashboard recent-docs **PO totals show 0,000 TND** despite real 850,000 TND totals — fix the widget's total field. (`sweep-dashboard-1.png`)
4. **P1** **Email-verification banner on every page** for the demo owner — verify the seeded account.
5. **P1** **Dashboard KPIs all zero** (revenue/invoices/payments) & contradict the GL — seed priced invoices + payments.
6. **P1** **Dates in English/US format** on FR UI ("July 2, 2026", "7/2/2026") — locale-format all dates.
7. **P1** **Document statuses in English** on FR (Received/Draft/Confirmed) — complete the `documents.statuses` FR namespace.
8. **P1** **"Price Lists" nav → 403 Access denied** — grant the permission or hide the item.
9. **P2** **Number-format inconsistency** — percentages/POS amounts use dot decimals instead of French comma.
10. **P2** **Demo data quality** — US phone numbers, lorem-ipsum notes, English/duplicated-suffix company & product names; regenerate with fr_FR/Tunisia faker. Plus: expenses duplicate "Catégorie" label, `$` icon on TND card, "Balance generale" accent, privacy page "AutoERP" branding.

## Environment note

Both Playwright MCP servers (`mcp__playwright__*` and `mcp__plugin_playwright_playwright__*`) resolve to the **same** Chrome profile dir (`ms-playwright-mcp/mcp-chrome-f67121c`), so they cannot run concurrently — the second one errors "Browser is already in use … use --isolated". For parallel browser agents, one server needs an `--isolated` / distinct-profile launch config. This sweep used a standalone Playwright script with its own profile as a workaround.
