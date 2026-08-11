# Owner Decisions — UI/Presentation Audit (2026-08-10)

Rulings given verbally by the owner against the §6 questions of `docs/sessions/UI-PRESENTATION-AUDIT-2026-08-10/00-EXECUTIVE-REPORT.md` (rev 5, Codex-gated R5 PASS). This file is the ruling of record for the act-on waves. Items marked **VERIFY** are conditionally ruled: an investigation must establish the facts, then the stated rule applies.

## Ruled outright

| OQ | Ruling |
|---|---|
| OQ-1 (brand) | **Synerivia** is canonical for the data platform (enrichment/catalog). ERP product names are NOT final — Otospex and IziPOS are current names but may change. Therefore: the app name in privacy/support copy must become a **placeholder/config value**, not a hardcoded string. Do not bake any brand string into copy. |
| OQ-3 (Arabic scope) | **Not launch scope, but bring to parity anyway** — Arabic market may follow right after launch. Runs as an own-pace **parallel Codex lane**. |
| OQ-A1 (Arabic phasing) | Crucial/launch-critical namespaces first (pos, sales, documents, settings, common), then full parity — own pace. |
| OQ-A2 (Arabic register) | **Modern Standard Arabic everywhere** for now. Tunisian derja possibly later — not now. |
| OQ-5 (hero band) | Must **not pop out** — gray treatment per best practices (audit's `bg-gray-50` + white image slot direction fits). Executor uses common sense within "blend in". |
| OQ-4 (Rafiq skin) | **PARKED** — stabilize everything else first; the skin is bigger work for later. (Hero band restyle proceeds independently per OQ-5.) |
| OQ-14 (Ecommerce) | **Keep and finish** — must at least connect to PrestaShop and WooCommerce at launch. Must be its **own gated module**, distinct from the B2B Sales module. Not pulled from sale. Frontend gating-parity work proceeds. |
| OQ-11 (dead buttons) | **Hide until real** — price-list Add Item / Assign Partner, Pause Shift, and by extension any control whose backend doesn't exist. |
| OQ-12 (return notes) | **Two separate flows CONFIRMED** — customer return notes (sales) vs supplier return notes (inventory) are different documents with different lifecycles. Both need proper surfacing and clearer labels. Wherever return notes surface anywhere in the app, the two flows must be kept distinct. |
| OQ-6 (listing program) | Owner agrees with recommendation: **write the canon + ratchet now; budget the sweep after the CX-4 re-census.** Act on trivially decidable pieces; defer the rest. |
| OQ-7 (pagination) | Same posture: document post-re-census, act on trivial cases, defer the broader ruling. |
| OQ-10 (compliance pages) | **Yes** — add a Compliance section to the Settings hub. Fraud alerts/settings/export: make reachable, apply best practices. (Daily-obligation severity sub-question: not answered explicitly; treat quarantine resolution as P1, rest P2 unless investigation shows daily use.) |
| OQ-8 partial (growth) | **`/growth` and `/growth/modules`: KEEP — will be used. Do not delete.** Link them. |

## VERIFY-then-act (investigation owed; general rule: anything that works must be reachable; anything duplicated → keep the more advanced/canonical one, remove the other; web-POS-era leftovers → delete)

| Item | What to verify | Ruling once verified |
|---|---|---|
| `/pos/shifts` (UI-34) | Is it a web-POS-era remnant? (The product moved web POS → offline-first POS.) Is anything real using it vs the linked `/pos/shift-history`? | Leftover → delete. Real/canonical → link it. |
| `/scheduling/capacity`, `/treasury/payment-methods` | Working feature or remnant? | Works → reachable. Remnant → delete. |
| `/treasury/sales-withholding-tracking` | Likely NEEDED (withholding — Tunisia esp., other countries too). Verify it works. | Works → link. |
| `/settings/chart-of-accounts` duplicate mount | Which mount is canonical? | Keep canonical, remove duplicate. |
| `/finance`, `/marketing` hubs (UI-33) | Do they show anything real beyond duplicating sidebar groups? | Working content → reachable (group-header hrefs). Pure duplication → delete. |
| `/pos/transactions` (UI-36) | There may have been a duplication at some point — which transactions surface is canonical and works properly? | Align sidebar + command palette + Quick-Create on the canonical one. |
| Delivery-note consolidation (CX-1 / OQ-15) | **Needs to be live** — but may hide caveats. Verify the flow end-to-end in code, and check for duplicate/competing consolidation flows. | ONE canonical flow; remove duplicates; then give it an entry point. |

## VERIFY items — RESOLVED by investigation (2026-08-10, evidence: `docs/sessions/UI-PRESENTATION-AUDIT-2026-08-10/10-verification-orphans-duplicates.md`)

| Item | Verdict (owner rule applied) |
|---|---|
| `/pos/shifts` | **DELETE — confirmed web-POS-era remnant.** Mutating actions double-blocked server-side (demo-tenant 403 + `SHIFT_DEVICE_AUTHORITY_REQUIRED` 409 on fiscal schema ≥3, now default). `/pos/shift-history` is canonical and already linked. |
| `/scheduling/capacity` | **LINK — functional, never-wired** (not decayed). Also add the missing `module:Workshop` FE guard. |
| `/treasury/payment-methods` | **LINK — canonical, NOT a duplicate.** Only payment-method config UI; six features consume its list. |
| `/treasury/sales-withholding-tracking` | **KEEP per owner (TN withholding) but BLOCKED from linking**: no web code calls the only write path (`POST /documents/{id}/record-withholding`) → would render an empty table forever; plus FE/BE permission mismatch (`withholding.view` vs `invoices.view`). Needs a small implementation lane before linking. |
| `/settings/chart-of-accounts` | **Duplicate mount deleted in favour of `/finance/chart-of-accounts`** (identical component+gate, zero inbound refs to the settings mount). |
| `/marketing` hub | **DELETE — pure duplicate** (six hrefs set-identical to the sidebar group; route also unguarded). |
| `/pos/transactions` | **Keep as deliberate retirement notice; realign the CommandPalette + QuickCreate "Open POS" CTAs.** See NEW-Q2 below for the real gap found behind it. |
| Delivery-note consolidation | **Canonical flow = `POST /delivery-notes/consolidate-to-invoice`; no duplicate implementation exists.** Precision/tax handling verified CORRECT — do not touch. Two P1 blockers before wiring the entry point: (1) FE gates on a `sales.create` role-alias heuristic vs backend `can:invoices.create`; (2) eligibility picker client-filters a single 25-row cursor page → silently hides older eligible DNs. Also: partner `consolidation_frequency` is configurable end-to-end but no scheduler reads it (dead setting). See NEW-Q3. |

## NEW questions — owner rulings 2026-08-11

1. **NEW-Q1 — `/finance` hub:** RESEARCH FIRST (owner-directed). Dispatch design research on what such a page should be before deciding delete-vs-refresh. → `11-research-finance-hub.md`
2. **NEW-Q2 — receipts web view:** **RULED: BUILD.** The web app definitely needs a receipts view **and the analytics related to it**. Scope research dispatched → `12-research-receipts-web-view.md`, then spec, then Codex build.
3. **NEW-Q3 — DN-consolidation billing UX:** RESEARCH + ADVISE (owner-directed). Owner lean: the **partner page should have a way to filter un-invoiced delivery notes** (verify whether it exists; if not, plan it). Open question: what other views does the user need, from a user perspective — "let's build this properly." → `13-research-dn-consolidation-ux.md`

## Standing owner directions captured in the same session
- Post-gate implementation sessions run **in Codex** (Claude orchestrates + gates).
- en/fr/ar run **in parallel** for cheap work (translation files etc.).
- Act immediately on whatever is trivially decidable; defer what needs more consideration.
