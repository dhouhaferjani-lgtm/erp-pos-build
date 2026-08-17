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

## Research-round rulings (owner, 2026-08-11)

| Lane | Ruling |
|---|---|
| Finance hub (11) | **DELETE outright — no redirect.** Greenfield; nothing links to `/finance`, so no broken-bookmark risk. (Research had proposed delete+redirect; owner simplified.) |
| Receipts (12) | **Build properly.** List receipts the way they should be listed; **refunds are listed SEPARATELY** (gross + separate refunds register — this is the returns-convention ruling). Receipt detail cross-links to its related refund(s) and vice versa. **POS scope only** — B2B-flow refunds (credit notes) are explicitly out of scope here. **Additional research ordered**: POS is going live as a module for the FIRST TENANT — research what reporting/functionality is expected in that specific scenario before spec-writing. → `14-research-pos-module-first-tenant-reporting.md` |
| DN-consolidation (13) | **APPROVED as proposed**: partner DN tab + global "To bill" queue + link lane-separation + retire old page; DN-billed-once coexistence rule with `invoiced_via` badge; no scheduler — retire frequency selector, repurpose boolean; P0 eligibility-filter fix + role-matrix repair + DB-level double-invoice guard as prerequisites. |

## First-tenant POS research rulings (owner, 2026-08-11, on `14-research-pos-module-first-tenant-reporting.md`)
- **A-1 accountant access: APPROVED** — re-gate `/settings/compliance/export` off the `pos` moduleKey and grant the accountant role what it needs (seeder + cache-reset at deploy).
- **A-9 POS count semantics: research delivered (`15-…`, recommendation = whole-drawer, industry unanimous, ratifies current device/server behavior).** Owner follow-ups before final confirm: (1) explain the takings-only formula — origin, why it was conceived, any real-life use case → archaeology dispatched, `16-takings-formula-archaeology.md`; (2) **blind counting: RULED — ON everywhere** (not just first tenant); (3) the 3 GL-flag blockers (float/DEPOSIT/PAYOUT unbooked in Treasury; v3 emits no CashCountRecorded; count-screen copy) — determine whether the parallel document-per-action session already owns them; owner lean = hand them to that session so it owns ALL v3 fixes. **RULED: cash-count events must be emitted on v3.**
- **NEW PROGRAM (owner, 2026-08-11): whole-application event-sourcing coverage audit** — "check whether events are being sourced everywhere they should be; I think we'll find gaps." Multi-agent audit (Opus/Sonnet/Haiku) runs in THIS session; **fixes belong to a SEPARATE dedicated session** → audit output `docs/sessions/EVENT-SOURCING-AUDIT-2026-08-11/` + handover brief.
- Launch-required list A-2..A-8: accepted into the receipts/POS-reporting spec scope.
- Escalations acknowledged: TN VAT refund-netting gap → refund-enable E1 block (launch program lane); "no POS module exists" → separate packaging lane; pages ship permission-gated meanwhile.

## Final A-9 + remediation-packaging rulings (owner, 2026-08-11)
- **A-9 CONFIRMED: WHOLE-DRAWER counting.** Takings-only formula is dead code born of a missing float join (see `16-takings-formula-archaeology.md`); bury it and correct the config/docblock comments that describe it as policy. Blind counting stays RULED ON everywhere.
- **Remediation packaging: THREE findings dossiers, folded into the EXISTING fixes session** (not a new dedicated session): (1) events-specific → `docs/sessions/EVENT-SOURCING-AUDIT-2026-08-11/00-CONSOLIDATED-REGISTER.md` + handover `docs/handoff/HANDOVER-event-sourcing-remediation-2026-08-11.md`; (2) shift-variance GL + everything related → `docs/handoff/FINDINGS-shift-variance-gl-2026-08-11.md`; (3) all other problems discovered this session not owned by the UI wave plan or the events register → `docs/handoff/FINDINGS-other-problems-2026-08-11.md`.

## Wave 0 brief — parent (orchestrator) assignments, 2026-08-11 (per gate-r2 flags; brief PASS at r3)
- **F-2b (`touchOptimized` ownership):** ASSIGNED to the **Wave 1 dispatch brief backlog** (to be drafted next). Reassignment recorded here so Wave 0 / UI-43 can be reported complete with the gap owned, per the brief's completeness rule.
- **F-6 (commit phase number):** RECORDED EXCEPTION per `AGENTS.md` — Wave 0 commits use `Phase 0.<task#>.<seq>:` (e.g. `Phase 0.4.1: fail-closed canAccessModule`), where task# is the brief's T-number.
- **F-1 (successDot removal + BarcodeHero deletion inside the Rafiq-parked `features/products/editor/`):** REMAINS OWNER-OWED. Gate assessment on file: the Rafiq worktree is clean with no branch-unique changes under that directory; the real reconciliation risk is the design-system baseline, and any authorisation must name who reconciles it.

## Post-gate rulings round (owner, 2026-08-12)
- **Dispatch model (standing):** fully-autonomous long-running Codex tasks are dispatched **by the owner via Codex Desktop** from a handover brief — not by the orchestrator through the CLI. Handover briefs in `docs/handoff/` are the interface.
- **OI-8 (SO losing-path): provisional REFUSE (batch-atomic)** — owner's hunch, matching the spec default ("more conservative and reliable"); best-practice research ordered to confirm before it becomes final. → `17-research-open-question-best-practices.md`
- **OI-10: ACKNOWLEDGED via principle** — two lanes (inventory / finance) interact, nothing drops silently, minimal user interaction; the SO converter's committed-outside-transaction side effects violate this and the spec's fix is in scope. "It needs to work."
- **F-1 / BarcodeHero: the Rafiq-parked editor directory stays parked in full** — including the dead-code deletions. The owner will return to that directory soon; Wave 0's T6/T8-b remain non-executable escalation records (already how the gated brief treats them — no change needed).
- **Permissions (A-2, OI-1a, and generally): principle ruled** — every role gets access to everything it needs to do its job (accountant included); finer grant/revoke tuning comes later. Research ordered to derive the concrete accountant grant list from that principle.
- **OI-17 clarification owed back to owner:** OI-17 is about **unit of measure** (pc/kg on receipt lines), NOT currency. The owner's currency point is a separate, valid question: verify whether the fiscal schema/canonical payload already emits currency (single-currency today), and ensure the receipts feature handles currency in a way that can evolve to multi-currency without another fiscal-schema break. Research ordered.

## OI-17 reframed (owner, 2026-08-12) — fiscal chain vs stored receipts
Owner direction: **distinguish the fiscal chain (signed, inalterable, NF525-scoped) from receipts as stored in our system.** Money-related data belongs in the chain; units (kg/pc) may not be required there — but they matter for cross-referencing stock (inventory lane) against money (finance lane). We may store MORE data than the chain requires, outside the signed bytes, synced from the device through our system. Possible B2B-vs-retail distinction. **Research ordered** (`18-research-nf525-chain-vs-sidecar.md`): NF525 minimum signed-content requirements; our canonical_bytes vs sidecar structure; whether truthful UoM can ride the sync envelope outside the chain; the projection-rebuild caveat (sidecar data must survive rebuilds from canonical events); B2B/retail differences. OI-17's "no unit symbol" ruling stands as the spec default until this research reports.

## Research round 3 outcomes (2026-08-12, research 17 + 18) — closing the open sheet
- **OI-8: CLOSED — REFUSE (batch-atomic)**, owner hunch confirmed by industry precedent + code (bill-the-remainder is barely computable: converter copies order lines, partial mechanism is whole-line-only). UI conditions binding: persistent inline error, lost DNs named with taker, "no invoice was created, no number used", no bare Retry — "Open INV-XXXX" + "Invoice remaining lines…". → DN spec has NO dispatch blockers left (OI-10 acknowledged 2026-08-12).
- **Accountant grants: CLOSED — add exactly `pos.view_receipts`, `pos.view_reports`, `deliveries.view`; REFUSE `dashboard.owner`.** Corrections for the handovers: lane-separation needs no grant (`reports.financial` already held); OI-1a alone insufficient (delivery-notes route gated `moduleKey="inventory"` — same fix pattern as A-1); OP-23 second dead-permission bug (fraud-alerts/settings) folds into A-1 work; A-1 needs a NAMED composite compliance gate (no `compliance` moduleKey exists).
- **Currency: CLOSED — already canonical + hash-covered** (`currency_code`/`currency_scale` required at v3/v4, stored, emitted). Multi-currency forward-compat = two binding rules for the builds: never format with company-default currency; never SUM across receipts without grouping by currency.
- **OI-17/UoM: superseding path adopted as a QUEUED LANE — canonical `unit` at event_version 5** (device-authored, canonical-only, ~9-10 gated edits; variant-V2 precedent; sidecar rejected: costs more, no integrity, and projections never rebuild so a projection column would freeze wrong forever). Receipts v1 ships unit-less per OI-17; pre-v5 sealed receipts stay unit-less permanently. Related legal finding OP-22 (unit-designation print obligation; B2B document_lines has no unit column).

## Unit-of-measure rulings (owner, 2026-08-12) — TWO SEPARATE FIXES, BOTH APPROVED
1. **POS lane (ev5)**: device snapshots the product's UoM into the SIGNED line at sale time — canonical `unit` at fiscal event_version 5, per `18-research-nf525-chain-vs-sidecar.md`. Queued lane; pre-v5 sealed receipts stay unit-less; NOT part of the receipts build.
2. **B2B lane (OP-22)**: add a unit column to `document_lines`, snapshotted from the product UoM at document creation. Separate lane, ticket via OP-22; NOT part of the DN-consolidation build.
Principle confirmed by owner: unit carries over from the product UoM **at the moment of sale/creation as a snapshot**, never looked up live at display time on a historical document.

## Execution-mode ruling (owner, 2026-08-12)
**All three UI-lane Desktop handovers run as FULLY AUTONOMOUS self-reviewing waves** under `docs/handoff/SELF-REVIEW-HARNESS.md`: Codex Desktop works the full scope; at every milestone it runs the adversarial review ITSELF via `scripts/adversarial-review.sh` (`claude -p --model opus`), loops fix rounds until ACCEPT, tracks state in a per-lane progress YAML, and STOPs only on the harness's three conditions. No per-milestone handback. **The parent Claude session (this lane) owns the TERMINAL audit and the merge into dev — the executor never merges.**

## Build-handover parent assignments (orchestrator, 2026-08-12)
- **Accountant seeder edit (3 keys) + `permissionsMap.generated.ts` regeneration = receipts build's task**; the DN build must NOT touch the accountant block (prevents two lanes rewriting the same generated file). RATIFIED.
- **Commit phase series per lane**: receipts = `Phase 1.<wave>.<seq>:`, DN = `Phase 2.<milestone>.<seq>:` (recorded exceptions, same basis as Wave 0's `Phase 0.*`). RATIFIED.

## Standing owner directions captured in the same session
- Post-gate implementation sessions run **in Codex** (Claude orchestrates + gates).
- en/fr/ar run **in parallel** for cheap work (translation files etc.).
- Act immediately on whatever is trivially decidable; defer what needs more consideration.
