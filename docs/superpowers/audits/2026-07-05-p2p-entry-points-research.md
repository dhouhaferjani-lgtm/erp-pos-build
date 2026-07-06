# P2P flexible entry points — industry-standard + legal research (2026-07-05)

> Deep-research harness run (103 agents: 5 search angles → 21 sources fetched → 100 claims
> extracted → top 25 adversarially verified 3-vote → 24 confirmed, 1 refuted).
> ERP-behavior findings below are **verified (3-0 unless noted)**. The Tunisia/France legal
> findings were extracted from primary sources but did **not** go through the 3-vote pass
> (budget cut) — treat as sourced-but-unverified and confirm with the expert-comptable
> before encoding as hard compliance rules.
>
> Context: owner directive 2026-07-05 — the system must support the complete P2P chain but
> allow starting from an RFQ, a PO, a goods receipt/BL, or a supplier invoice, with the
> invariant documents (goods receipt = stock, invoice = finance) always present, explicit
> or implicit, configurable per country and per tenant, UI hiding the mechanics.

## 1. Verified ERP-behavior findings

### SAP Business One — the invoice-first precedent (HIGH confidence)
- The A/P invoice is the ONLY mandatory document in the purchasing chain; PO and goods
  receipt PO are optional. An A/P invoice entered without a base goods receipt PO itself
  posts the inventory receipt (Dr stock / Cr vendor) — the goods-receipt leg is created
  implicitly by the invoice. The only double-count guard is MANUAL (user must check no
  prior receipt exists). Source: SAP Learning "Managing Logistics in SAP Business One".
- Receipt-first also supported: a goods receipt PO (BL equivalent) is recorded standalone
  (no PO required); one or more GRPOs are later copied into an A/P invoice (base/target
  mechanism). Price deltas between receipt and invoice are tolerated with a defined GL
  treatment (stock account when stock covers qty; split stock/price-difference otherwise;
  fully price-difference at zero stock). (MEDIUM — help page JS-rendered, corroborated via
  snippets + TB1000 + SCN.)
- **Design take-away:** B1 proves invoice-first + receipt-first are mainstream, but its
  implicit receipt is a phantom posting with a manual double-count guard. Our invariant
  (system always materializes an explicit receipt row) is strictly safer.

### SAP S/4HANA — the auto-PO + ERS precedents (HIGH confidence)
- Auto-PO at goods receipt (MIGO): configurable per movement type (101/161), requires a
  purchasing org + purchasing info record with valid price conditions; the receipt MUST
  identify a vendor ("Enter vendor" error otherwise). It is auto-PO creation, NOT a truly
  PO-less receipt — SAP's answer to "receive without PO, bill later" is to materialize the
  PO implicitly at receipt time so it anchors pricing for later invoice verification.
  (Movement 501 is genuinely PO-less but feeds no PO-based invoice verification.)
  Sources: SAP KBA 2483551, SAP Learning S/4HANA Inventory Management.
- ERS / self-billing: the INVOICE is the implicit document — supplier never submits one;
  system posts it from PO prices + receipt quantities. Prereqs: GR-based IV flag on PO
  item, tax code, no estimated price, ERS flag on supplier master.
- **Design take-away:** auto-PO-behind-the-scenes has first-class SAP precedent, and it is
  exactly what keeps our week-old receipt ledger (PO-bound NOT NULL) untouched.

### Odoo — the explicitness counter-model (HIGH confidence)
- Exactly two bill-control policies: 'Ordered quantities' (draft bill at PO confirm,
  before receipt) and 'Received quantities' (bill only after full/partial receipt),
  company-wide default + per-product override.
- Under 'Received quantities' Odoo HARD-BLOCKS invoice-first ("Invalid Operation" on
  Create Bill before receipt) and NEVER implicitly creates a receipt from an invoice.
- Partial invoicing is receipt-driven but the mapping is FUNCTIONAL, not structural:
  derived per PO line from aggregated qty_received − qty_invoiced, no stored
  receipt-line→invoice-line link. **Odoo cannot natively reconcile a month-end invoice
  against specific named BLs** — our receipt-line-grain matcher (Wave 5) is already ahead.
- 3-way matching is an opt-in checkbox, ADVISORY ('Should Be Paid' Yes/No/Exception), only
  meaningful with 'Received quantities'. (One merged claim 2-1 on the advisory nuance.)

### Dynamics 365 Finance — the config-surface precedent (HIGH confidence)
- Four AP matching types: invoice totals / two-way (invoice price vs PO price only — the
  product receipt is NOT factored in) / three-way (adds invoice qty vs matched receipt
  qty) / charges. Whether the receipt participates in validation is itself a policy.
- Line matching policy (Not required / 2-way / 3-way) set per legal entity, overridable at
  item, vendor, item+vendor, and per-PO-line grain, resolved Item+Vendor > Item > Vendor >
  Legal entity; lower-level overrides can only RAISE strictness (**stricter-only
  invariant**).
- Matching failures are a controlled exception path, not a dead end: 'Approve posting with
  matching discrepancies' toggle; the documented worked example is precisely our
  missing-BL month-end scenario (receipt never posted, qty + price match Failed — invoice
  still postable after approval).

### Coverage gaps (open)
- NetSuite and Sage produced no surviving verified claims (NetSuite receipt-first
  perpetual-inventory posting appeared at fetch stage only, via a practitioner blog).
- No source documented a BL-line-level reconciliation workbench (missing BLs, per-BL price
  deltas) — SAP B1 multi-GR copy and D365 receipt-qty matching come closest. Our Wave 7
  "Facturer les réceptions" + Wave 5 receipt-line FIFO is already at the frontier here.
- S/4HANA (vs B1) invoice-first-with-implicit-GR: unverified whether it exists at all.

## 2. Legal layer — France (primary sources, NOT 3-vote verified)

Sources: BOFiP BOI-TVA-DECLA-30-20-10-40 (2021-08-13); archived doctrine 3E2213
(1996-11-02 — pre-BOFiP, verify against current doctrine before encoding).

- CGI art. 289: invoice due in principle immediately on delivery. **Facture
  périodique/récapitulative explicitly permitted**: multiple distinct supplies to the same
  client within the same CALENDAR MONTH may be consolidated onto one invoice, issued at
  the latest by end of that month (administrative tolerance of a few days).
- When invoicing is deferred, the supplier MUST hand over **numbered bons de livraison at
  delivery**; BLs are retained as accounting support **under the same conditions as
  invoices** (archival requirement).
- The consolidated invoice must list **each operation as a distinct line with its own
  execution date** → line↔delivery mapping is a legal requirement, directly validating
  receipt-line-grain reconciliation.
- (1996 doctrine) récapitulative invoicing framed as an exception regime (material
  obstacle to per-delivery invoicing, e.g. multiple deliveries/week to same customer) —
  check current doctrine for whether this restrictive framing still applies.
- Intra-Community supplies: deferred invoicing until the 15th of the following month.

## 3. Legal layer — Tunisia (primary/secondary sources, NOT 3-vote verified)

Sources: Code de la TVA art. 18 (jurisitetunisie), finances.gov.tn invoicing-obligations
page, tunisieconseilfiscal blog, hesabi.tn 2026 e-invoicing explainer.

- Art. 18-II: VAT-registered persons must issue an invoice **for each operation** (except
  where a contract serves as proof). No general statutory basis for month-end
  consolidation — the widespread BL-heavy month-end practice operates around this rule.
  (The invoicing obligation sits on the SUPPLIER; as the buyer's system we record what
  arrives, but our tenants also SELL — the sales side must not assume monthly
  consolidation is a statutory right. The only explicit consolidation relaxation in art.
  18 is the retail B2C **daily** global invoice.)
- The **bon de livraison "tient lieu de facture" during transport** if dated + names and
  addresses of sender/recipient + nature and quantity of goods. Transport without invoice
  or stand-in document: 250 TND fine; fictitious/inaccurate invoices 1k–50k TND; fraud up
  to 3 years imprisonment.
- **All invoice formalities extend to BLs** (numbering in uninterrupted series, mandatory
  mentions, controlled printing) — a Tunisian BL is a formal, numbered fiscal-adjacent
  document, not a casual slip.
- **2026 e-invoicing mandate** (LF 2026 art. 53): from 2026-01-01 all VAT-registered
  persons must issue electronic invoices via El Fatoora (TEIF XML, XAdES-B signature,
  SOAP to TTN). **BLs, quotes, proformas stay OUTSIDE the mandatory TTN perimeter.**
  Paper invoice in breach: 100–500 TND per invoice (≈50k TND annual cap). → Emission-side
  (sales) constraint for Tunisian tenants; out of scope for this P2P spec but a standing
  platform requirement.

## 4. Design conclusions adopted (owner-ratified 2026-07-05)

1. **Receipt-first = auto-PO behind the scenes** (S/4HANA pattern): "Nouvelle réception"
   creates + confirms a flagged auto-generated PO and receives against it at BL prices,
   in one transaction. Receipt ledger schema and matcher untouched.
2. **Invoice-first, both behaviors** (B1 pattern, hardened): "already delivered" →
   implicit receipt materialized as an explicit ledger row at billed prices (zero PPV by
   construction) then invoice matched + postable; "not yet delivered" → Draft invoice
   parked until receipt exists. Full bill-before-receipt GL (`bill_control_mode='ordered'`)
   stays Phase 2.
3. **Config = extend procurement_policies/presets** (D365-inspired): per-company
   entry-point toggles, preset defaults (Complet: off/off · Standard: receipt-first on ·
   Léger: both on), country → default preset (Tunisia → Léger), stricter-only override
   principle.
4. **Invariant:** goods receipt (stock) and supplier invoice (finance) always exist as
   explicit rows in the chain, whatever the entry point. No phantom postings.
5. Receipts carry the supplier BL identity (`external_reference` + date) — required by
   both legal regimes and the natural month-end reconciliation key.
