# Owner rulings — parapharmacy remediation (spec v4, gate r4 ACCEPT-FOR-OWNER-REVIEW)

Date: 2026-09-05, verbal rulings recorded by the orchestrator (Claude Fable 5.1). Supersedes the blank sheet `OWNER-SHEET-parapharmacy-remediation-decisions-2026-09-05.md`. Spec §2.3 remains the register; this file is the ruling of record and the input to the W-LOT, W0, W2, W1, W4 and W7 briefs.

## Ruled

| ID | Ruling | Brief consequence |
|---|---|---|
| **RD2** | A manager is branch-linked, or is a **general manager** (explicit company-wide role). Recall is a safety action: a branch manager **initiates** a recall for a lot present in their branch; the recall **escalates** to the general manager, who executes it company-wide. Permissions stay tight; escalation, not denial. | W-LOT L1 becomes a two-step recall: `batches.recall.request` (branch scope: immediately blocks the lot from sale/transfer in the requesting branch, records reason and actor) and `batches.recall` (company-wide execution, general-manager role). Seeded manager loses company-wide `batches.recall`; new general-manager role or explicit grant carries it. Pending request visible to central. |
| **D9** | BatchExpiry **stays on** for pharmacies. Tracking is **per product**: some products carry lots, others do not. | Matches current model (`requires_batch_tracking` per product; module in vertical defaults). No deactivation model built. R2 worker-entitlement fix still applies (other verticals, projections without HTTP context). |
| **D3** | **Capture lot at checkout now** if not expensive. Field **prepopulated by FEFO** (owner says FIFO; the shipped rule and the pharmacy norm is first-expired-first-out), **editable** by the cashier when the shelf item does not match. | Iteration 1 becomes editable capture, not read-only display. Cost assessment in §Cost below. Sequenced: display-first slice ships inside W-LOT L7, capture slice follows in the same lane once D7 transport is built. |
| **D4** | **Refresh before opening a session**; the session is what drives lot changes at that location, and lots are location-bound, so the snapshot is current most of the time. Cached during the session with visible age. | Industry check: Odoo POS loads lot data when the session opens and lot selection is online-only in stock Odoo; offline lot selection is a community add-on. Refresh-at-session-open is the industry baseline. Spec recommendation D4 stands as ruled. |
| **D2** | **POS only at launch**; **B2B flow hidden and gated** for this customer and for the parapharmacy vertical generally (a wholesaler selling B2C competes with its own B2B customers in Tunisia). Tenders: **cash at launch**; loyalty vouchers and customer account **later**; card **very late**; bank drafts (traites) **later, not at launch**; cheques **not needed**. | New W0/W1 item: B2B Sales module removed from parapharmacy `default_modules` in `config/verticals.php`, both-layer gated (rule 12). W3 leaves the launch critical path. W2 launch matrix = cash only; voucher/loyalty/account and traite classes specified but not enabled; card behind RD3. |
| **RD3** | Electronic tenders **unavailable until correctly activated**. Reverse the H-3 day-one drawer fallback. | W2 as recommended; H-3 test gets the `_pins_limitation_` marker then is replaced. |
| **D1** | Branches are **locations of one legal company**. | As recommended. See §Sister companies. |
| **D5** | Managers move **only their branch custody**. Only the **central authority** (an explicit company-wide manager role, not any manager) has all-treasury reach. | W1 as recommended; `treasury.manage_all_locations` granted to admin and the general-manager role only. |
| **D6** | Cross-branch returns **excluded** at launch. | As recommended. |

## Explained, ruling pending owner confirmation

### D8 — tender destination binding. What kind of problem is it?

Plain version. When a cashier takes a card payment, someone has to decide which account the money lands in: the drawer, a bank clearing account, a wallet. Today three different places answer that question and none of them is authoritative: the register picks the first active bank repository it happens to hold; the sealed receipt records only the tender type (CARD), not the destination; the server, when it later projects the sale, ignores the register's pick and resolves the destination from **today's** configuration, falling back to the drawer if nothing is mapped.

Classification: **a correctness defect with a loophole, not a fiscal-compliance defect.** The fiscal record (amounts, tender type, VAT) is complete and sealed. What is wrong is treasury bookkeeping: an offline sale synced after someone changed the mapping is booked to a different account than the one that actually received the money, and the drawer fallback lets electronic money be booked as physical cash. Nothing in NF525 or the Tunisian rules requires the destination account in the receipt.

D8 asks: fix it by sealing the destination into the receipt (strongest evidence, immutable, but requires a new receipt version and a device cutover, and a mis-configured destination can never be corrected except by append-only repair), or by recording the destination as an authenticated, versioned but **unsealed** binding stored with the receipt and honoured by the server (no fiscal version bump, correctable, but needs the terminal authority W2 now builds anyway).

**Orchestrator recommendation: the unsealed authored binding**, for three reasons. Launch is cash-only, so the sealed transport buys nothing before card arrives. It keeps the fiscal chain lean, consistent with your D7 view. And the terminal authority it needs is already a W2 prerequisite. Sealing can be added later in the same D7/D8 version roadmap if an auditor ever asks for it.

### RD4 — opening float and drawer drops. What kind of problem is it?

Plain version. Treasury books every cash sale into the drawer repository. It does **not** book the float you put in the drawer at shift start, nor the drops you move from drawer to safe during the day. So the treasury balance of the drawer and the physical cash you expect to count are measuring different things, and comparing them at close would produce false shortages every day. The shipped variance listener is switched off for exactly this reason.

Classification: **a correctness and coverage gap in cash custody, not a tax issue.** Fiscal totals are fine. The question is whether Treasury should become the full record of physical cash custody (float in, drops out, bank deposits) so that drawer, safe and bank balances are real and reconcilable per branch.

RD4 asks: reconcile the close against fiscal-event-derived totals first and add repository comparison later once float and drops are booked (recommended), or fund the float/drop booking first. For a four-branch business moving cash drawer → safe → bank daily, the custody trail is exactly what central treasury will want. **Recommendation stands: fiscal-derived first for launch, fund float/drop booking as the first post-launch treasury lane.** Ruling requested: confirm, or pull float/drop booking into launch scope.

### D7 — iteration 2 lot evidence transport. Correcting the premise

D7 is **not** about printing the lot on the receipt. Printing is independent and optional either way. D7 is about **where the captured lot is stored**: (a) inside the sealed receipt payload, which makes it part of the hash chain, immutable, requires a new SALE_RECEIPT version and a device cutover, and turns a mis-scanned lot into an append-only correction case; or (b) in a separate record linked to the receipt id and hash, stored and synced alongside it, not hashed into the fiscal chain, correctable, with its own linking and ordering handling.

Your instinct, keep it out of the printed receipt and out of the fiscal chain but store it, is option (b). Industry check confirms it: Odoo's French anti-fraud module hashes order date, user, lines, payments, pricelist, session, reference, journal, fiscal position and version, and per line the product, quantity, unit price, discount and taxes. **Lot and serial fields are not hashed**; Odoo stores them on stock move lines linked to the order. ERPNext puts the batch on the invoice item but has no hash chain. NF525's four principles (inalterability, security, conservation, archiving) cover sales data, not lot traceability.

**Ruling recorded as (b)**, pending your confirmation: captured lot stored as a linked, authenticated inventory evidence record, not sealed, not printed by default. This also lowers the D3 cost because no SALE_RECEIPT version is needed for lot capture.

## Cost of D3 lot capture at checkout (orchestrator estimate, with D7 = (b))

| Slice | Work | Rough size |
|---|---|---|
| L7 display | branch lot cache in the device product sync (SQLite additive table), FEFO suggestion on tile and cart line, module gate, session-open refresh | 3 to 5 days |
| L7 capture | editable lot field prefilled from the suggestion, validation against the cache, evidence record written in the same SQLite transaction as the receipt, outbox item, server ingress and storage, link to receipt hash | 4 to 6 days |
| Projection consumption | receipt projection consumes captured lots when present, FEFO estimate only as fallback, provenance labels, conflict and shortfall as durable obligations, refund provenance | 4 to 6 days |
| Gates and second-of-everything | two companies, two locations, two lots, two terminals, module-off, offline, crash boundaries | 2 to 3 days |

Roughly three weeks of lane time end to end, of which the display slice is the first week. That is not cheap, but it removes F1 for real rather than labelling it. Recommendation: ship display first, capture second, in one W-LOT lane so the launch date is not held by the capture slice.

## Sister companies — needs one fact from the client's accountant

You described one main company and four sister companies sharing the tax-ID prefix, differing only in the suffix 001, 002, 003. In the Tunisian *matricule fiscal* the trailing three digits are the **establishment number**: 000 is the head office and 001, 002 are *établissements secondaires* of the **same legal entity**. If that is what the client has, they are one legal company with five establishments, which is exactly D1 as ruled: five locations, one company, transfers are internal, one VAT filing.

If instead each has its own registration (its own RNE number, own VAT filing), they are separate legal entities and stock moving between them is a sale with an invoice, treasury custody is per company, and the intercompany caveat in the spec applies. Please have the client's accountant confirm which case it is before the W1 brief. The model differs materially.

## New items created by these rulings

1. **Recall escalation workflow** (W-LOT L1): branch request with immediate local block, general-manager company-wide execution. New permission and role.
2. **General-manager role** (W1/W-LOT): explicit company-wide role carrying `batches.recall` and `treasury.manage_all_locations`; seeded manager loses both.
3. **B2B Sales module gated off for parapharmacy vertical** (W0 or small lane): `config/verticals.php` defaults, both-layer gating, census of parapharmacy tenants.
4. **Iteration 1 = editable capture** (W-LOT L7): supersedes R3's read-only wording; display slice first.
5. **D7 = separate linked evidence record**; **D8 = unsealed authored binding** (recommended, pending confirmation).
6. **Sister-company establishment check** (owner to client accountant).

## Still awaiting confirmation

D8 (recommend unsealed binding), RD4 (recommend fiscal-derived first), D7 (recorded as separate record per your reasoning), sister-company fact.

Sources consulted for the industry checks: Odoo 18 POS serial numbers and lots documentation; Odoo `l10n_fr_pos_cert/models/pos.py` (18.0) hashed field lists; Odoo forum and OCA notes on offline lot selection; NF525 overviews (JDC, Agiris, Tactill).

## Open questions surfaced after the rulings (2026-09-05, end of session)

1. D3 cost call: ~3 weeks lane time for editable capture; inside "not very expensive", or display-only for launch and capture right after?
2. D8: confirm unsealed authored binding (recommended).
3. RD4: confirm fiscal-derived first (recommended), or pull float/drop booking into launch.
4. General-manager: new seeded role vs explicit grant on admin.
5. Recall request: immediate local block on sale and transfer in the requesting branch while GM decides — confirm.
6. B2B module for parapharmacy: removed from vertical, or opt-in paid extra (August lean).
7. FIFO vs FEFO: FEFO kept — confirm.
8. Sister companies: establishment suffix vs separate registrations — client accountant.
9. Cross-session: over-payment on zero-balance document booked as customer advance without refusal — intended? (request-hygiene handover).
