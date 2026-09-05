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

## Benchmarks for the open questions (added 2026-09-05 per the owner's standing rule: research before any ERP-behaviour question)

| Q | Guarantee | Odoo | ERPNext | AutoERP today | Benchmark-derived default (owner rules) |
|---|---|---|---|---|---|
| 4/5 Recall escalation and general-manager role | A branch can stop a suspect lot immediately; company-wide recall needs central authority | No approval workflow built in. Recall = block the lot (community `stock_lock_lot`, or `quality_hold` on the lot) or move it to a Quarantine location so it is not reservable, then downstream traceability report, scrap/return. Authority via Inventory Administrator group and warehouse-level record rules | No approval workflow. Recall = set Batch **disabled** (blocks new transactions) plus traceability from Stock Ledger; **User Permission on Warehouse** restricts a user to their warehouse while a company-wide role has no warehouse restriction | Recall is a single company-wide boolean action with no permission (`BatchController:176-210`); seeded manager holds `batches.recall`; no branch-scoped hold exists | Two-step as ruled is **stricter than both benchmarks** and is defensible for pharmacy safety: branch **hold** (Odoo quality_hold / ERPNext disabled, scoped to the branch's stock) is immediate and needs no approval; company-wide recall is a separate action held by an unrestricted (general-manager) role, modelled as ERPNext does: same permission set, no location restriction. Recommendation: **new seeded role `general_manager`** = manager permissions with no location restriction plus `batches.recall` and `treasury.manage_all_locations`; `admin` keeps both too. |
| RD4 Float and drops | Opening float and cash in/out are booked so drawer balance is real | **Cash control**: opening balance recorded at session open; **Cash In/Out creates a journal entry** (credit cash, debit bank suspense); transfer to bank creates two liquidity entries; close difference booked as cash-difference gain/loss | **POS Opening Entry** records opening amount per mode of payment; **POS Closing Entry** reconciles opening + expected vs counted per mode and books the difference | Float and drops are device facts only; Treasury books sales only; variance listener disabled (`PostShiftCashVarianceAdjustment:50-56`) | Both benchmarks book float and drops as accounting/treasury facts. **Funding float/drop booking is the industry norm**, not an extra. Recommendation revised: keep fiscal-derived reconciliation as the launch check (it is independently useful), but schedule float/drop booking as the **first post-launch lane, not "later"**, so repository balances become real before card tenders arrive. |
| D8 Tender destination | Each payment method is bound to one destination at configuration time | `pos.payment.method` has a **required journal**: Cash journal for cash, **Bank journal for card/terminal**, optional intermediary/outstanding account; the device never chooses | **Mode of Payment** carries a **default account per company**; POS Profile lists allowed modes; the account is resolved from configuration, not from the till | Device picks first active bank repo; sealed receipt has method only; server resolves from current mapping with drawer fallback | Both benchmarks bind method → destination **in configuration, not in the sealed sale**; neither seals a destination into the receipt. Supports the **unsealed authored binding** (configuration revision recorded with the sale, honoured by the server). Sealing would be stricter than both. |
| 7 FIFO vs FEFO | Removal strategy for expiry-tracked stock | Removal strategies: FIFO, LIFO, **FEFO** (requires expiration dates), closest location; FEFO is the documented choice for perishables | Batch has expiry; picking by expiry available via batch selection; **FIFO valuation** is a separate (costing) concept | FEFO shipped in `FEFOInventoryService` | Keep **FEFO** for lot selection. Note the owner's "FIFO" likely refers to the general principle; valuation stays WAC per the precision contract. |
| 6 B2B module for parapharmacy | Module can be off for a vertical yet activatable | Apps are installable per database; no vertical concept | Modules/workspaces can be hidden per role; domains (verticals) set defaults | `config/verticals.php` `default_modules` + `compatible_extras`; both-layer gating | Model as **compatible extra, default off** for parapharmacy (matches the August lean and the vertical model); removing it entirely would block a future wholesaler tenant. |
| L9 DEFAULT split (already ruled into W-LOT) | Identify a lot cohort after the fact | Lot reassignment procedure via inventory adjustment | **Batch → Split** button splits a batch into smaller batches | No split operation | ERPNext's split is the closest precedent for L9. |

Sources: Odoo forum on blocking/quarantining a lot, OCA `stock_lock_lot`, Odoo 19 lot documentation; ERPNext Batch documentation (disabled, Split, expiry) and User Permissions (Warehouse); Odoo 14–18 POS cash control, cash in/out accounting forum answers; ERPNext POS Opening/Closing Entry documentation; Odoo 17–19 POS payment methods (journal per method, Bank for terminals); ERPNext Mode of Payment / POS Profile.

## Second ruling pass (2026-09-05, later)

| ID / Q | Ruling | Consequence |
|---|---|---|
| **D8** | CONFIRMED: destination is defined **at tender setup**, never chosen by the cashier. Each payment method (tender) is bound to its final destination(s): cash → the terminal location's drawer (already true); card and other electronic settlement → a **bank account (repository)**, with per-location override when the company has several bank accounts. Transport = **unsealed authored binding** (configuration revision recorded with the sale, honoured by the server), not sealed into the receipt. Benchmark: Odoo requires a journal per payment method and attaches methods per POS; the cashier has no choice of destination. | W2: `PaymentMethod` settlement binding becomes required for electronic classes at activation (RD3), with optional per-location repository override; policy snapshot to the device; terminal projection filtered by persisted terminal location; no drawer fallback for electronic. |
| **D7** | CONFIRMED: lot evidence is **not** in the hash chain and not printed by default. The chain stores only what certification requires; everything else about an order is stored alongside and recallable for operations and audit. | W-LOT L7/L8: separate linked, authenticated inventory-evidence record. No SALE_RECEIPT version needed for lot capture. |
| **D3** | CONFIRMED: ship in the suggested order inside one W-LOT lane: display (label + suggested lot) → capture (editable, FEFO-prefilled) → projection consumption. | W-LOT brief sequences L7a display, L7b capture, L7c consumption. |
| **D1 / sister companies** | CONFIRMED: one legal company, five establishments (tax-ID suffixes 000/001/002…). Locations of one company. | No intercompany design. Establishment number may need to appear on receipts/invoices per establishment (verify with accountant during the Tunisia review, not blocking). |
| **RD4** | RULED **CRITICAL, into launch scope**: Treasury must book opening float, cash in/out (drops) and drawer→safe→bank transfers so repository balances are real and the drawer reconciles to the Z report. Owner believed this was already fixed; it was not (see explanation below). | New lane **W-CASH** (or W7b): book float/drops from device shift events into Treasury via existing movement/transfer services with stable source identities; then enable repository comparison in W7 and the variance GL leg. Benchmark: Odoo cash control books opening balance and cash in/out as journal entries; ERPNext POS Opening/Closing Entry per mode of payment. |
| **Q4 general-manager role** | CONFIRMED: new seeded `general_manager` role (manager set, no location restriction, `batches.recall`, `treasury.manage_all_locations`). | W1 + W-LOT seeder deltas. |
| **Q5 recall hold** | CONFIRMED: branch request = immediate local hold on sale and transfer. | W-LOT L1. |
| **Q6 B2B module** | CONFIRMED: compatible extra, default off for parapharmacy. | W0 verticals change. |
| **Q7 FEFO** | CONFIRMED. | — |
| **Q9 over-payment as advance** | STILL OPEN (cross-session). | — |

### What is actually in place for float and drops today (verified in code)

- The **device** records opening float, cash in/out and the count in the Z report and shift events, and the server **fiscal** projection copies them (`ZSessionLifecycleProjection`) and derives expected cash from them (`ShiftExpectedCashService`, sources: v3 `pos_z_session_events` or v2 `pos_cash_drawer_operations`). So the Z report and the expected-cash figure are correct.
- **Treasury** has no consumer of those events: the only Treasury bridges are receipt, account payment, account charge and deposit. Nothing books the float into the drawer repository, nothing books a drop out of it into the safe, so the drawer repository balance only ever contains sales takings. Back-office drawer→safe transfers exist as a manual service (`RepositoryTransferService::transfer`), but they are not driven by the shift.
- The variance GL leg (`PostShiftCashVarianceAdjustment`) shipped **disabled** for exactly this reason (gate finding I1, SV-3/SV-4): enabling it before float and drops are booked would create a cash/GL mismatch.

## Q9 — over-payment on a zero-balance document (ruled 2026-09-05)

**Ruling: match the benchmark (Odoo/ERPNext): never over-allocate to the document; accept the surplus only as an explicit, operator-confirmed customer advance; make advances visible in AR aging and the customer balance; provide a supported refund path.** Two constraints from the owner:

1. **The document payment path is not a POS path.** For parapharmacy tenants the web B2B Sales module is a compatible extra, **off by default and activatable** (D2/Q6), so "pay against a document on the web" is not reachable for this client unless they activate the module; the confirmation gate above applies to tenants that do have the module. Do not build a POS-specific variant of it.
2. **Standalone customer-account replenishment stays.** Topping up a customer account (an advance/deposit not tied to any document) must remain possible for this client, through the existing account-deposit path (`TreasuryDepositBridge` / customer-account deposit surfaces), independent of the document module. It is the operational way a parapharmacy customer pre-funds an account.

Consequence for the testing session (owner of PR #214 and the treasury fix lanes): (a) confirmation gate on document payments when balance is zero or the amount exceeds balance; (b) advances surfaced in AR aging and refundable; (c) the customer-account deposit path audited to be reachable with the Sales module inactive, both layers (rule 12). Benchmark sources: Odoo 18 payments (outstanding credit, keep-open vs mark-fully-paid prompt); ERPNext Payment Entry (allocated ≤ outstanding, unallocated amount as advance).

**All twelve spec decisions plus Q1–Q9 are now ruled. No open owner question remains for this program.**
