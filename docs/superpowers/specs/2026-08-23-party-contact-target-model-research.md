# Party / Contact target model — domain research and converged recommendation

**Date:** 2026-08-23
**Scope:** research only. No code changed. Read-only verification against main checkout `/Users/houssamr/Projects/syneriva/apps/erp`, local `dev` tip `a3b2f9ec9`.
**Input of record:** `docs/handoff/AUDIT-parties-partners-disambiguation-2026-08-23.md` (current-state ground truth) + the owner's design sketch, LEDGER/owner-sheet **B-4** (`docs/handoff/OWNER-SHEET-2026-08-21-first-client-session.md:22`).
**Audience:** the owner, deciding the entity model this ERP keeps for years.

---

## 0. The ruling, up front

Five decisions. Each is argued below; each is checkable against code or law.

| # | Decision | Confidence |
|---|---|---|
| **R1** | **Keep one billable entity: `partners`.** Add `party_kind ∈ {person, organization}` NOT NULL as the *nature* axis, orthogonal to the existing `type ∈ {customer, supplier, both}` *role* axis. This confirms audit §6.1. | **High** — every reference ERP does exactly this, and the alternative costs a rewrite of the AR sub-ledger. |
| **R2** | **Contacts are people AT an organization, never billable.** Refuse the "Contact as POS-facing billable identity" reading of the sketch. A private person buying at the till is a **`person` party**, not a Contact. | **High** — confirmed by Odoo, Dolibarr, ERPNext, and by 24 FK tables + an immutable-by-trigger `fiscal_events.partner_id`. |
| **R3** | **POS variant (b): B2C-first with invoice-on-request escalation.** Staged toward (a), but (a)'s "a company always carries a linked contact person" is **refuted as a schema constraint** and re-expressed as an optional per-transaction attribution. | **High** — TN law is literally written for variant (b), and (b) needs *zero* new sealed-payload keys. |
| **R4** | **The facture escalation is a separate Document, never a mutation of the sealed receipt and never a new key on `SALE_RECEIPT`.** The existing `buyer` block carries identity truth; a non-fiscal `POST /pos/receipts/{id}/facture` carries the request. | **High** — matches the house "document-per-action" principle *and* Odoo's own Paid→Invoiced QR flow. |
| **R5** | **Minimum contact data = `name` + at least one of `phone \| email`.** This rule already exists, in exactly three places, and simply needs promoting to canonical + a shared normalizer. | **High** — it is already the strictest rule in the codebase (`PosPendingCustomerController.php:163-176`). |

**The one thing that will bite before any of this ships:** the codebase carries **three mutually incompatible Tunisian matricule-fiscal regexes**, and the one that governs partner records is *incompatible* with the one that governs sealed fiscal payloads. See §3.3 — this is a live P0 on the existing account-charge lane, independent of the redesign.

---

## 1. What I verified, and where the audit needs amending

The audit is accurate. Five refinements matter for the design.

### 1.1 The `SALE_RECEIPT` buyer block already has the slots we need — and the fix is a *value* change, not a *key* change

`apps/pos/src/lib/fiscal/FiscalEventEngine.ts:287-299`:

```ts
export interface BuyerBlockInput {
  readonly address: AddressInput | null;
  readonly codice_fiscale: string | null;
  readonly contact_id: string | null;      // POS-local mirror ref; non-authoritative once sealed
  readonly customer_id: string | null;     // POS-local mirror ref; non-authoritative once sealed
  readonly name: string | null;
  readonly tax_number: string | null;      // optional buyer tax number, country regex table
}
```

Six slots, all nullable, key set exact-matched on both sides (`BUYER_KEYS`, `FiscalEventEngine.ts:2011`; server `validateBuyer()`, `FiscalPayloadConstraintValidator.php:2102-2140`). `buyer` is inside `payload`, which is inside the canonically-encoded object that gets hashed (`FiscalEventEngine.ts:617-638`) — so it *is* signed.

**Consequence, and it is the single most important fact in this document:** a buyer-identity fix populates existing keys. The key-set drift gates see nothing change. `name`, `tax_number` and `address` are enough to identify a facture buyer under both TN and FR law (§3). **The identity model we need is already expressible in the sealed bytes.**

The corollary is a hazard: because the key set is unchanged, the drift gates will **not** notice `buyer` going from `null` to an object. That silent-change hole must be closed by a golden canonical-bytes fixture over a *populated* buyer, alongside the existing `null` one.

### 1.2 The audit's "mirror the ACCOUNT_CHARGE builder which already carries a full customer block" is imprecise

`ACCOUNT_CHARGE` has **two** blocks: a `buyer` (same 6-field shape — and it is *also* hardcoded `null` at `apps/pos/src/lib/accountCharge/accountChargeService.ts:351`) and a separate, differently-keyed `customer` block of 9 fields (`AccountChargePayload.ts:55-65`) carrying `phone`, `email`, `customer_category`, `account_identifier`, and `customer_sync_status`.

So "copy ACCOUNT_CHARGE" is ambiguous between two materially different changes:
- **(a)** populate `SALE_RECEIPT.buyer` — reuses existing keys, **no version bump**;
- **(b)** add a `customer` key to `SALE_RECEIPT` — a new key in an exact-key-set contract, **hard version bump, XL**.

**Take (a).** §6 specifies it.

### 1.3 The sharpest correctness hole in the naive buyer fix: there is no `customer_sync_status` on `SALE_RECEIPT`, and `pos_receipts.partner_id` is a real FK

`pos_receipts.partner_id` is `foreignUuid(...)->constrained('partners')->nullOnDelete()` (`2026_03_09_100000_add_partner_id_to_pos_receipts.php:21-25`). The D16 projector writes it blind from the sealed snapshot, and is *forbidden by a grep-guard test* from doing any live lookup (`PosCoreReceiptProjection.php:87-92, 361-366`).

Therefore: **if a device seals its locally-derived pending-customer UUID into `buyer.customer_id`, the projection insert violates the FK and the receipt fails to project.** `ACCOUNT_CHARGE` avoids this because it carries `customer_sync_status` and the facture bridge hard-refuses anything not `'synced'` (`DocumentAccountChargeFactureBridge.php:85-102`). `SALE_RECEIPT` has no such signal and cannot gain one without a key change.

**Design rule that falls out:** `buyer.customer_id` may only ever contain a **server-resolved `partners.id`**, or `null`. Never a device-minted pending UUID. §6.2 handles the offline case without breaking this.

### 1.4 You can never backfill a receipt's partner after fiscalization

`prevent_receipt_modification` (`2026_05_26_100001_allow_pos_receipt_fk_cleanup.php:44-60`) permits exactly one identity mutation on a fiscalized row: setting **both** `partner_id` and `contact_id` to NULL together, with every other column byte-identical. There is no path that sets them.

So late binding — "this receipt actually belonged to Mme Ben Salah, whose account synced ten minutes later" — **must** live in a separate, mutable, non-fiscal link table. It cannot be an UPDATE on `pos_receipts`.

### 1.5 `party_kind` does **not** require a POS device migration

The audit lists "new device migration + POS version bump" as a risky seam for `party_kind` (§6.2 b1/b2). It does not have to be.

`PosCustomerMirrorResource.php:34-62` already ships `customer_category` (and already renames `partners.vat_number` → `tax_number` on the wire). If `customer_category` becomes a **derived wire field** computed from `party_kind` at the mirror boundary, the device SQLite schema (currently at migration **v67**, `apps/pos/src/lib/db/migrations.ts:2163`) is untouched, the device's `customer_category` reads keep working, and the `b2b_facture_draft_requested` coherence rule that both sides mirror byte-for-byte (`FiscalPayloadConstraintValidator.php:1725-1729` ↔ `FiscalEventEngine.ts:1999-2002`) keeps passing unchanged.

That removes the largest risk item from phase (b).

---

## 2. Reference models

### 2.1 Odoo — `res.partner`: one table, `is_company` + `parent_id`, plus a third idea we should steal

Odoo stores companies, individuals, vendors, employees and users in **one** model. `is_company` is the nature flag; `parent_id`/`child_ids` give the company↔contact hierarchy; a `type` field on the child says whether an address row is `contact` / `invoice` / `delivery`.

The piece worth stealing is **`commercial_partner_id`** — a computed field that walks up to the nearest `is_company` ancestor and *is* the accounting entity. Odoo's own docs describe it as the "Commercial Entity" and the "bill payer"; invoices, credit control and receivable grouping key on it, not on the raw `partner_id`. Odoo also propagates a `_commercial_fields` set (VAT, receivable/payable accounts, payment terms) down from the commercial entity to its children, so a child contact can never carry a divergent VAT number.

**Why this matters for us:** Odoo bills a natural person perfectly well — *because persons ARE partners*. That is a direct argument for `party_kind` on `partners`, and a direct argument against billable Contacts. And `commercial_partner_id` is the clean answer to the owner's "a company, but a contact person was in the shop": the person is recorded, the money resolves to the organization.

**Documented pain points** (we should design them out, not inherit them):
- Flat lists return persons and companies interleaved; every consumer needs post-processing. → *We keep role-scoped screens (Customers / Suppliers) rather than one flat Parties list.*
- Duplicates are endemic: different users, CSV imports, and web registrations each mint a partner; "the same company can appear as duplicates for each associated contact person."
- Merging is where it hurts: the merge wizard **cannot merge contacts with related accounting entries** ("IntegrityError during merge", "foreign key constraints prevent reassignment"), cannot merge user-linked partners, and refuses company↔own-child merges. Their own guidance is "never auto-merge blindly."
- Forgetting `type` on a child contact silently misroutes invoice/delivery addresses.

The merge finding is the strongest evidence for the audit's tombstone recommendation: Odoo, with no immutability triggers at all, still cannot re-key partners that have accounting history. We have a DB trigger that makes it *impossible*. **Merge must be alias/tombstone from day one.** (Confirmed: `grep merged_into|PartnerMerge` over `apps/api` returns nothing — no merge machinery exists yet, so nothing has to be unwound.)

### 2.2 ERPNext — separate doctypes, linked by Dynamic Link

Customer, Supplier, Contact and Address are four independent doctypes joined through a polymorphic `Dynamic Link` child table. Their documentation is explicit about the boundary: *"The Customer is the party you sell to, a Contact is a person you communicate with, and an Address is a location."* One Customer can have several Contacts and Addresses without duplicate customer accounts; and *"Create another Customer only when the party must be treated as a separate commercial or accounting relationship. Do not create a new Customer merely for a new employee, office, or delivery point."*

That last sentence is the cleanest available statement of the rule the owner is reaching for.

Crucially, ERPNext still puts a nature flag **on the billable party**: `customer_type ∈ {Company, Individual, Partnership}`, with `tax_id` on the Customer, not the Contact. A Contact can never be the party on a Sales Invoice.

**Their own known pain point is instructive for us.** Issue [frappe/erpnext#38233](https://github.com/frappe/erpnext/issues/38233) argues `customer_type` is confusing precisely *because it mixes legal forms*: NGOs, ministries and religious organizations do not fit "Company"; "Company vs Proprietorship vs Partnership" means nothing to a non-accountant; the maintainers' proposal is to reduce the primary axis to **Entity vs Individual** and push legal form into a secondary grouping field.

**Take:** that is an argument to make `party_kind` a strict **binary** (`person | organization`) and to keep legal form (SARL / SUARL / personne physique / association) in a separate, optional field. Do not enrich the enum.

### 2.3 Dolibarr — French practice, and the closest match to the owner's mental model

Dolibarr splits `llx_societe` (*tiers* / third party — the billable entity) from `llx_socpeople` (*contacts* — natural persons). The wiki is explicit that a third party *"is a moral or physical entity (prospect, customer, supplier), which can be a company, foundation but also **be a physical person**"*, while a contact *"is an individual: a representative of the business entity (e.g. the CEO … the sales manager of a trade customer)."* A third party has 1..n contacts.

So the canonical French-practice model is **exactly** what R1+R2 describe: one *tiers* table that is either a company or a private individual, and contacts hanging off it that are never themselves invoiced. Dolibarr never requires a contact on a third party.

Terminology note for the FR locale: Dolibarr's user-facing word is *Tiers*. It is precise and it is what a Tunisian or French accountant will recognise — but it is opaque to a shop manager. §7 recommends keeping *Clients* / *Fournisseurs* in the nav and reserving *Tiers* for accounting screens only.

### 2.4 POS-first systems — Square and Lightspeed: a flat customer, company as an *attribute*

**Square's Customer object** carries `given_name`, `family_name`, `nickname`, `company_name`, `email_address`, `phone_number`, `address`, `reference_id`, `birthday`, `tax_ids`. There is **no person/company discriminator**, **no parent-child relationship**, and no B2B account concept — a record can have both `given_name` and `company_name` populated at once. Nothing is documented as required.

**Lightspeed Retail X-Series** is person-primary: `customer_code`, `first_name`, `last_name` are the **required** import fields; `company`, `companyRegistrationNumber`, `vatNumber` and `creditAccountID` are optional attributes on the same flat record, plus an on-account rolling balance.

**Take.** This is the model the owner's sketch drifts toward if left alone, and it is the one to avoid at the ERP layer. It works for Square and Lightspeed because their AR ambitions stop at store credit; the moment you have a general ledger with a partner sub-ledger, "company is a text field on a person" produces two customers named *Ben Ali* who are the same debtor. Note that Lightspeed themselves push B2B invoicing to a **third-party add-on** rather than model it.

But steal one thing: their **required set is tiny and person-shaped**. Lightspeed requires a name and a code; Square requires effectively nothing. Our till must not be heavier than that — which is R5.

### 2.5 The academic backing: Silverston / Fowler "Party" pattern

The Party/Party-Role pattern (Silverston, *The Data Model Resource Book*; Fowler, *Analysis Patterns*) makes **Party** a supertype of **Person** and **Organization**, and models involvement — customer, supplier, employee, contractor — as a **separate Role** axis, because a party plays any number of roles over time.

Our `partners` table is already Party-with-Role: `type ∈ {customer, supplier, both}` **is** the role axis. What is missing is the supertype discriminator. `party_kind` is not an invention — it is the missing half of a pattern the schema is already half-way through.

That also settles a naming question: **do not rename `partners` to `parties`.** The table is the Party; the word "Party" belongs in the UI and the docs. Renaming buys nothing and touches 24 FK tables plus an immutable fiscal trigger.

---

## 3. The fiscal grounding — TN first, FR later

This is the decisive input, and it points one way.

### 3.1 Tunisia: the law is written for variant (b)

Article 18 of the *Code de la TVA* requires an invoice to carry *"l'identification du client et son adresse ainsi que le numéro de sa carte d'identification fiscale"* — client identity, address, **and matricule fiscal**. An invoice missing the mandatory mentions *does not open the right to VAT deduction* and must be rejected by the recipient. There is a stated exemption: the client-MF obligation does not apply to *"redevables de la TVA non tenus d'appliquer la majoration de l'assiette de … 25 %"* — i.e. it does not bite for a private consumer with no fiscal registration.

And, decisively for the POS:

> *"Les ventes faites par les commerçants de détail aux particuliers peuvent … ne pas donner lieu à une facture individuelle."*

Retail sales to private individuals **need no individual facture**. Instead the retailer establishes *"une facture globale"* **daily**, from the cash-register records. When a client *does* ask for a facture, a proper one is issued with full client identification.

**That is variant (b), verbatim, in the statute.** Ticket by default; facture on request; daily consolidation for the rest.

Two operational consequences the design must honour:
1. **The facture globale must exclude receipts already individually invoiced**, or the day's revenue is declared twice. Any escalation design must leave a machine-readable mark linking receipt → facture so the consolidation can exclude it.
2. **A facture issued to a *particulier* is legitimate without an MF.** Requiring a matricule fiscal from every facture buyer would block a legal, common case. Require it for `organization` buyers; allow a `person` facture on name + address.

E-invoicing: since 1 Jan 2026 (*loi de finances 2026*, art. 53) TN electronic invoicing via **El Fatoora / TTN** is mandatory for service providers under the *régime réel*, liberal professions, and commercial/industrial enterprises, in TEIF XML, signed, archived 10 years, with 100–500 DT penalties per paper invoice. Article 18 §III ter already contemplated electronic invoices requiring the seller's electronic signature, registration with the authorised body, and a unique reference. B2C retail is the part still outside the individual-invoice obligation. **So the facture we escalate to is on the road to becoming a TEIF document with a structured buyer party — one more reason the buyer identity must be a real party record, not a free-text name on a ticket.**

### 3.2 France: same shape, different thresholds

- **B2B:** facture always mandatory. Client's company name required; the client's **VAT identification number** becomes mandatory once the invoice exceeds €150 HT.
- **B2C:** a facture is mandatory **only** on client request, for distance selling, and for intra-community deliveries; for *services* a *note* is mandatory at ≥ **€25 TTC** (and below that, on request). A B2C facture, when issued, needs the client's **full name and address** — no SIREN, no VAT number.
- **Ticket de caisse:** since 1 Aug 2023 systematic printing is prohibited; the ticket is issued **on request**.
- **2026 reform:** from 1 Sept 2026 (large/ETI) and 1 Sept 2027 (PME/micro), four new mandatory mentions on B2B e-invoices — **the client's SIREN**, delivery address if different, nature of the operation, and the "TVA sur les débits" option. The SIREN is not administrative decoration: it is the **routing key** in the central directory. **B2C is explicitly out of scope for e-invoicing** (it feeds e-reporting instead), and no SIREN is required.

**Take.** FR and TN agree on the shape: *ticket by default for consumers; facture on request; a facture to an organization requires a structured national identifier (MF / SIREN), a facture to a person requires name + address.* Designing for TN with a country-parameterised identifier requirement gets FR nearly free. The identifier field must be a **party attribute** — which it already is (`partners.vat_number`) and which the sealed buyer block already has a slot for (`buyer.tax_number`).

### 3.3 🔴 P0, latent today, blocking under this design: three incompatible TN matricule regexes

The canonical Tunisian MF is 13 characters: **7 digits + control key letter + VAT-status letter (A/B/P/F/N) + activity-category letter (M/C/P/N) + 3-digit establishment** — e.g. `1234567/A/M/000` written long-form, `1234567AMN000` compact. (Aside worth noting: the **activity-category letter already encodes person vs organization** — `M` = personne morale, `C` = commerçant personne physique, `P` = profession libérale. TN's own tax id carries a `party_kind`.)

What the codebase actually enforces:

| Where | TN pattern | Applies to |
|---|---|---|
| `CreatePartnerRequest.php:128` / `UpdatePartnerRequest.php:137` | `/^[0-9]{7}[A-Z]{3}[0-9]{3}$/` — **3 letters** | `partners.vat_number`, **only when `country_code` is present in the request** |
| `TaxIdValidationService.php:90` | `/^\d{7}[A-Z][A-Z0-9]{3}$/` | `partners.business_registration_number`, advisory endpoint only |
| `CountryTaxNumberRules.php:18` | `/^[0-9]{7,8}[A-Z]{2}[0-9]{3}$/D` — **2 letters** | locations / branches |
| `FiscalPayloadConstraintValidator.php:154` | `/^[0-9]{7,8}[A-Z]{2}[0-9]{3}$/D` — **2 letters** | sealed payload `seller.tax_number` **and `buyer.tax_number`** |

The partner-level rule demands exactly three letters. The fiscal rule demands exactly two. **They cannot both accept the same string.** A TN partner whose MF was entered in the 13-character form passes partner validation and is then **rejected at seal time** by `assertTaxNumberForCountry(..., buyer: true)`.

This is not hypothetical: the existing `ACCOUNT_CHARGE` lane already normalizes and asserts `customer.tax_number` for any non-null value (`accountChargeService.ts:375`, `FiscalEventEngine.ts:3378`), so an account charge to a business customer with a 13-char MF fails today. It has stayed invisible only because `customer_category` is NULL for 100% of real partners, so nobody has driven the B2B lane. **Every design in this document routes a partner's MF into a sealed payload, so this must be fixed first.**

Two further defects in the same area:
- The partner-level VAT check **self-disables when `country_code` is absent** (`CreatePartnerRequest.php:69-72`), and `country_code` is itself `nullable` with no fallback to the company's country. A TN tenant that never sends it gets **zero** format enforcement.
- `partners` has `unique(tenant_id, vat_number)` — **tenant-wide**, while `code` uniqueness was narrowed to `(company_id, code)`. A group operating two companies under one tenant cannot register the same supplier MF twice.

**Fix:** make `CountryTaxNumberRules` the single source of truth, widen its TN pattern to accept both the 12- and 13-character forms after slash/space normalization, and have partners, locations and the fiscal validator all consume it. Add a round-trip test: *any MF accepted by `CreatePartnerRequest` must seal successfully in a `buyer.tax_number`.*

---

## 4. The owner's two POS variants

### 4.1 Engaging the sketch clause by clause

> *"Selling on the POS is either towards a contact if it's a private person…"*

**Right instinct, wrong entity.** The private person must be a **`person` party** (a `partners` row), not a `Contact` row. Reason: `documents.partner_id` is NOT NULL for every document type, `journal_lines`, `payments`, work orders and `fiscal_events` all key on `partners.id`, and `fiscal_events.partner_id` + `partner_identity_snapshot` are immutable by DB trigger. `contacts` is reachable from exactly two columns in the entire schema (`party_contacts.contact_id`, `pos_receipts.contact_id`) and can be named on no document, no payment, no journal line. Odoo, Dolibarr and ERPNext all reach the same place from different directions: the natural person you sell to is a *party*, and Odoo can only invoice a "contact" because in Odoo a contact **is** a `res.partner`.

> *"…or it could be a company — but a company would always be linked to a contact person."*

**Agree with the intent; refute the "always" as a schema constraint.** Real counter-cases, all of which occur in the target verticals:
- a fleet/garage account where the driver who presents the vehicle differs every visit (this is the Otospex default, not an edge case);
- a company account settled by bank transfer with no person ever at the counter;
- a delivery driver or courier collecting on behalf of a company;
- a procurement email order with no named human at all;
- a newly imported supplier list — **223 suppliers in `tenant019fbe86…` today, 0 rows in `party_contacts` across all 8 local tenants.** Making the link mandatory would invalidate 100% of existing data on day one.

None of the four reference systems requires it: Odoo allows a company partner with zero `child_ids`; ERPNext explicitly warns against creating records for people who are not commercial relationships; Dolibarr allows a *tiers* with no *socpeople*.

**Re-expressed correctly:** the link is **optional and M:N** at the master-data layer (`party_contacts`, which already exists with `job_title`, `department`, `is_primary`, `is_invoice_contact`, `is_delivery_contact`), and the "who was actually in the shop" question is answered **per transaction**, as an optional snapshot — which is precisely what `buyer.contact_id` and `pos_receipts.contact_id` are for. That satisfies what the owner wants (never ambiguous who you dealt with) without a constraint that reality violates weekly.

> *"OR we keep the POS mainly for B2C flows, and if a customer wants an invoice, the sale gets pulled together to invoice as a company."*

**This is the recommendation.** It is TN law almost word for word (§3.1), it is Odoo's own POS design (§4.3), and it needs no new signed-payload surface.

> *"We need to determine the minimum required data for a contact — could be a phone number, or an email address."*

**Correct, and the rule already exists.** §5.

> *"Then clean up the dashboard and link things together properly so there is no ambiguity."*

§7.

### 4.2 Variant (a) — POS sells to person-parties AND organizations, org always carrying a contact person

**What it needs.**
- `party_kind` on the wire and in the device SQLite mirror — or derived, per §1.5.
- A `contact_id` reaching the device: **the device mirror has no contacts table at all.** SQLite `customers` (v39 + v41/v42/v59 ALTERs) has `id, tenant_id, company_id, name, phone, email, tax_number, customer_category, balances, credit_limit, payment_terms_days, charge_account_enabled, charge_policy_version, account_status*, skin_*`. No `contact_id`, no address columns. `PosCustomerMirrorResource` ships none either. So variant (a) requires: a new server sync endpoint for contacts, a new device table, a device migration (**v68**), a POS version bump, and a new picker UI at the till.
- A cashier flow that asks "which person?" on every organization sale.

**Signed-payload cost:** `buyer.contact_id` already exists, so still no key change — but the *data* to fill it does not exist on the device, and building the pipe to get it there is the expensive part.

**Verdict:** achievable, but it is a whole POS feature (sync + schema + UI + version bump), it is not needed for any fiscal obligation in TN or FR, and it imposes a mandatory question on a cashier for a case that does not always have an answer.

### 4.3 Variant (b) — B2C-first with invoice-on-request escalation

**What it needs.**
- The buyer block populated with a **resolved** partner when the cashier attaches a customer (this is `a1`, correctly scoped — §6.2).
- A **facture request** action that creates a real `Document` bound to an organization (or person) party.

**Signed-payload cost: zero new keys, and — if the escalation is a separate document — zero new semantics inside the sealed payload at all.**

**Precedent.** Odoo POS runs exactly this, in both directions:
- at payment time, *"To be able to issue an invoice, a customer must be selected"* — then **Invoice** issues a real accounting invoice for that order;
- after the fact, a **QR code on the receipt** lets the customer fill in their own billing information and **"the order status goes from Paid or Posted to Invoiced in the Odoo backend."**

The second path is the important one: Odoo escalates an **already-completed, already-receipted sale** into an invoice by *adding a document*, not by rewriting the sale. That is the shape our immutability triggers force on us anyway, and it happens to be what the house **document-per-action principle** demands.

### 4.4 Which variant needs less signed surface — and the recommendation

| | (a) person+org at the till | (b) B2C-first + escalation |
|---|---|---|
| New sealed **keys** | 0 | 0 |
| New sealed **semantics** | `contact_id` becomes meaningful | none (identity only) |
| Device SQLite migration | **yes (v68)** — contacts table | **no** |
| POS version bump | **yes** | **no** |
| New server sync endpoint | **yes** (contacts) | **no** |
| New cashier question per sale | **yes**, for orgs | only when a facture is asked for |
| Satisfies TN art. 18 | yes | **yes, and it is the statute's own model** |
| Satisfies FR B2C/B2B split | yes | **yes** |
| Blocked by anything today | contacts pipeline absent | nothing structural |

**Recommendation: (b) now, with a defined path to (a).**

Staging:
- **Phase 1 (TN launch):** variant (b). Attach a *party* at the till (person or organization, same picker); facture on request via a separate document. `buyer.contact_id` stays `null` and honest.
- **Phase 2 (post-launch, on demand):** if fleet/B2B volume justifies it, mirror contacts to the device and start populating `buyer.contact_id`. Nothing in phase 1 has to be undone — the slot is reserved, validated, and already read by the projector and by `SaleEarnContext`.

This is not a compromise; (b) is a strict prerequisite for (a), and (a) is an optional enrichment of (b).

---

## 5. Minimum contact data

### 5.1 The rule

> **A party is identifiable if it has a `name` and at least one of `phone` or `email`.**
> **Dedup key = normalized phone (E.164), then normalized email (lowercased, trimmed), then `code`, then `vat_number`. Never bare name.**

This is not new. It is already the strictest rule in the codebase — `PosPendingCustomerController.php:163-176`:

```php
'client_customer_uuid' => ['required', 'uuid'],
'name'  => ['required', 'string', 'max:255'],
'phone' => ['nullable', 'string', 'max:50',  'required_without:email'],
'email' => ['nullable', 'email',  'max:255', 'required_without:phone'],
```

and it is mirrored, by hand, in two device components (`CustomerAttachPanel.tsx:114-130`, `CustomersPage.tsx:388-400`). **Everywhere else in the system requires only `name` + `type`** (`CreatePartnerRequest.php:43-44`, `ImportType.php:88-99`, `PartnerForm.tsx:483,497`), and `UpdatePartnerRequest` requires *nothing at all* — every field is `sometimes`, including `name`.

### 5.2 Why phone-first, and why the current dedup is broken

The current ladder is **`code` → `vat_number` → exact-name**, strictly exclusive (`PartnerService.php:54-110`):

```php
->when($code !== null, fn ($q) => $q->where('code', $code))
->when($code === null && $vatNumber !== null, fn ($q) => $q->where('vat_number', $vatNumber))
->when($code === null && $vatNumber === null, fn ($q) => $q->where('name', $data['name']))
```

That name fallback is **exact, case-sensitive, untrimmed**. "SARL Ben Ali" and "Sarl Ben Ali" are two debtors. Phone and email are **never** dedup keys anywhere.

And there is essentially **no normalization in the system**: an exhaustive grep for `normalizePhone|libphonenumber|E164|phone_normalized` over `apps/api/app`, `apps/pos/src`, `apps/web/src` and `packages` returns **7 hits, all inside the Loyalty module**. The one implementation is `LoyaltyMember::normalizePhone()` (`LoyaltyMember.php:112-118`), which strips non-digits except `+`. `LoyaltyPartnerController.php:77` then queries `partners.phone` with a *normalized* value while every partner write path stores the phone **raw** — so that lookup matches only by accident. Email is never lowercased or trimmed on any path.

This is the mechanism behind audit §5.6. The device's dedup UUID is content-hashed over `tenant|company|name|phone|email`, lowercased and trimmed (`customerAttachUtils.ts:26-32`) — so `+216 20 123 456` and `20123456` are two customers, forever, with no server-side backstop.

Two more sources of unvalidated partners that will collide on the name arm: `MarketplaceOrderService.php:222,234` (name suffixed `' (Marketplace)'`) and `CartConversionService.php:87` (defaults to the literal `'Unknown Supplier'`). Both bypass every FormRequest.

### 5.3 What is mandatory, when

| Moment | Mandatory | Rationale |
|---|---|---|
| **Anonymous B2C sale** | **nothing** | TN art. 18: retail sales to *particuliers* need no individual facture. FR: ticket on request only. Never make the cashier ask. |
| **Attaching a customer at the POS** | `name` + (`phone` \| `email`); `party_kind` defaults `person` | Already the rule; keep it. This is the loyalty/history identity, not a fiscal one. |
| **Creating a party in the back office** | `name` + `type` + **`party_kind`** + (`phone` \| `email`) | Raises the back office to the till's standard. `party_kind` becomes required — this is the one genuinely new mandatory field. |
| **Import (`Parties`)** | `name` + `type` + `party_kind` (derivable, see §6.1) | Openings already key on `code`; do not add a contact requirement that would fail bulk migrations. |
| **`party_kind = organization`** | `name`; MF/VAT **strongly recommended**, not blocking | An organization can legitimately exist in the ledger before its MF is known (e.g. a prospect). |
| **Issuing a facture to an `organization`** | **name + address + tax id (TN matricule fiscal / FR SIREN)** — hard block | TN art. 18 makes the client MF mandatory and a non-compliant invoice loses the recipient's VAT deduction. FR 2026: client SIREN is the routing key. |
| **Issuing a facture to a `person`** | name + address; **no tax id** | TN exempts clients not subject to the 25% majoration. FR B2C facture needs name + address only. Blocking on MF here would break a legal case. |
| **Account charge / credit** | party must be `organization` **or** explicitly credit-approved; MF required if `organization` | Preserves the existing `b2b_facture_draft_requested` rule while fixing its dead input. |

### 5.4 Where each rule is enforced — named files

| Rule | Enforcement point | Change |
|---|---|---|
| `name` + (`phone`\|`email`), back office | `apps/api/app/Modules/Partner/Presentation/Requests/CreatePartnerRequest.php:43-44` | add `required_without` pair; add `party_kind` required |
| same, on update | `.../UpdatePartnerRequest.php:45-46` | make `name` `sometimes|required`; forbid clearing both contact channels |
| same, POS | `apps/api/app/Modules/POS/Presentation/Controllers/PosPendingCustomerController.php:163-176` | **already correct** — becomes the reference implementation |
| same, device | `apps/pos/src/components/customers/CustomerAttachPanel.tsx:114-130`, `apps/pos/src/pages/CustomersPage.tsx:388-400` | move the duplicated check into `pendingCustomerRepository.enqueuePendingCustomer` (`pendingCustomerRepository.ts:73-74`) so it cannot be bypassed |
| same, import | `apps/api/app/Modules/Import/Domain/Enums/ImportType.php:88-99, 141-160` | **do not** make contact data required; add `party_kind` as an optional column with derivation |
| same, web form | `apps/web/src/features/partners/PartnerForm.tsx:483,497,531,549` | add the cross-field rule; `PartnerForm` uses bare RHF with no zod — add one |
| same, inline modal | `apps/web/src/components/organisms/AddPartnerModal/AddPartnerModal.tsx:35-46,213` | **rebuild against the generated `PartnerData`** — it currently posts `tax_id`/`address`, which `CreatePartnerRequest` does not declare, so they are silently dropped, and free-text `country` 422s against `size:2` |
| **phone/email normalization** | new `App\Shared\Domain\Validation\ContactPointNormalizer` + `prepareForValidation()` on both partner requests, `PosPendingCustomerController`, `PartiesRowMapper.php:15-29`, and `apps/pos/src/components/customers/customerAttachUtils.ts:26-32` | **new** — E.164 with the company country as default region; must be **byte-identical** on device and server or the content-hashed UUID diverges |
| **dedup ladder** | `apps/api/app/Modules/Partner/Application/Services/PartnerService.php:54-110` | insert normalized phone / email above the name arm; make the name arm case-insensitive + trimmed |
| **tax id format (single source)** | `apps/api/app/Shared/Domain/Validation/CountryTaxNumberRules.php:16-43` | becomes canonical; consumed by `CreatePartnerRequest.php:128`, `UpdatePartnerRequest.php:137`, `TaxIdValidationService.php:90`, `FiscalPayloadConstraintValidator.php:154` |
| **facture-time identity** | new `IssueFactureFromReceiptRequest` + `DocumentPartyIdentityPolicy` (country-parameterised) | **new** — the only place a tax id is ever *blocking* |
| **document posting** | `apps/api/app/Modules/Document/Presentation/Requests/CreateDocumentRequest.php:66-70` | today requires only a `partner_id` that exists; add the identity policy for TN/FR factures |

> Note: `FacturXService.php:53-58` already treats a missing partner VAT as an **eligibility filter** — missing VAT silently skips Factur-X generation. Under the identity policy that silence becomes a validation error at issuance instead, which is the correct place for it.

---

## 6. Unified vs split — the decision, with the cost asymmetry

### 6.1 The two candidates

**Candidate U (audit §6.1, recommended).** One billable table.

```
partners                                    -- table name unchanged
├─ type        : customer | supplier | both        ← ROLE    (exists)
├─ party_kind  : person | organization  NOT NULL   ← NATURE  (new)
└─ contacts  (M:N via party_contacts)              ← PEOPLE  (exists, 0 rows)
```

**Candidate S (the owner's language, read literally).** `Contact` becomes the POS-facing identity, linked to a billable party.

### 6.2 Why S loses — the migration cost asymmetry is roughly 100:1

| Dimension | U — `party_kind` on `partners` | S — Contact as POS identity |
|---|---|---|
| Schema | 1 column + backfill (+4 optional person columns, §8-OQ1) | `documents.partner_id` must become nullable **and** polymorphic; same for `journal_lines`, `payments`, `payment_instruments`, work orders, appointments, loyalty, vouchers… |
| FK tables touched | **0** of 24 | up to **24**, across 16 modules (`PartnerReferenceTable` registry) |
| Fiscal rows | **0** — `customer_category` derived at the wire boundary, `fiscal_events` untouched | `fiscal_events.partner_id` + `partner_identity_snapshot` are **immutable by DB trigger** (`2026_05_14_100002…:91-92`); historical rows could never be reinterpreted, so the sub-ledger would permanently straddle two identity schemes |
| POS device | **none** (§1.5) | new table + v68 + version bump + new sync endpoint |
| Sealed payload | **none** — existing `buyer` slots | new key(s), version bump, golden fixtures, XL |
| Delete/merge semantics | inherits the existing 24-table census guard (`PartnerController.php:361-378`) | `ContactController.php:218` is an **unguarded `$contact->delete()`** on a soft-deleting model — the exact hazard the Partner guard exists to prevent, currently unmitigated against `pos_receipts.contact_id` |
| Precedent | Odoo, Dolibarr, ERPNext, Silverston | none found |

**And S is not even what the owner needs.** The requirement behind "sell to a contact" is *"a private person must be a first-class customer with history, loyalty and, on request, a facture."* Candidate U delivers that: a `person` party is a full customer. What S would add — "which human was at the counter for this company sale" — is already representable in U via `buyer.contact_id` and `pos_receipts.contact_id`, at zero extra cost, in phase 2.

### 6.3 Confirming the audit's "contacts must never be billable" — from the reference models

The audit asserts it from our own constraints. The reference models confirm it independently, and the Odoo case is the one worth stating precisely, because it looks like a counter-example and is not:

- **Odoo bills persons fine — because persons ARE `res.partner` rows.** A child contact can be invoiced only in the sense that it is itself a partner; and even then the accounting fields resolve up to `commercial_partner_id`, the nearest `is_company` ancestor. Odoo does **not** have a separate non-partner "contact" entity that can be billed. → supports `party_kind`, **not** billable contacts.
- **Dolibarr** invoices *societe* only; *socpeople* are never invoiced.
- **ERPNext** invoices the Customer; Contact is a link target, never the party on a Sales Invoice.
- **Square / Lightspeed** have no separate contact entity at all — one flat customer.

**Confirmed, unanimously. Contacts stay the people at a party, never the party.**

### 6.4 The one place S has a point, and how U absorbs it

S's real insight is that **the POS-facing identity and the billable identity are not always the same object**. U absorbs this with Odoo's `commercial_partner_id` idea, in a reduced form:

- the till attaches a **party** (person or organization);
- the sealed receipt records who was served (`buyer.name`, `buyer.customer_id`, and later `buyer.contact_id`);
- the facture, when requested, is addressed to the **billing party** — which may be the same party, or the organization the person belongs to via `party_contacts`.

That is exactly "the sale gets pulled together to invoice as a company," expressed in a model every reference ERP already validates. **No `parent_id` column is needed** for the launch scope: `party_contacts` already carries `is_invoice_contact` and can express "this person bills to that organization" without adding a hierarchy the data does not have.

---

## 7. Dashboard and IA — zero ambiguity

### 7.1 The current mess, in one paragraph

Three URL namespaces render the same three components with behaviour switched by `location.pathname.includes(...)` (`PartnerListPage.tsx:85`, `PartnerDetailPage.tsx:161-168`). A partner of `type: 'both'` therefore has **two detail URLs showing different tab sets**. The sidebar has a **"Companies"** entry (`Sidebar.tsx:264`) pointing at an 11-line redirect stub, gated on `contacts` in the sidebar but on `partners.view` in the route — a guaranteed bounce for `contacts.view`-only roles. Editing a customer requires `contacts.update` (`routes/index.tsx:627,876`). "Company" means two unrelated things in the same nav tree: a partner (`crm:contacts.company`) and the tenant's own legal entity (`settings:company`). Three fully-built capabilities are wired to nothing: `ContactPersonsSubForm.tsx` (**zero importers**), `usePartnerContacts.ts` (**one importer — a test**), `CreditLimitWarning.tsx` (**zero importers**). Two customer-statement backends exist with **zero** UI and a fully translated EN/FR key block waiting for a screen nobody built.

### 7.2 Naming decisions

**Do not add a "Parties" or "Partners" hub to the sidebar.** Users think in roles, not in supertypes; Odoo's flat mixed list is a documented pain point. `partners` stays the table; *Party* stays a docs/architecture word.

| Concept | EN | FR (TN/FR) | AR |
|---|---|---|---|
| nav: sales counterparties | Customers | Clients | العملاء |
| nav: purchase counterparties | Suppliers | Fournisseurs | المورّدون |
| the nature field | Type — *Individual* / *Company* | Nature — *Particulier* / *Société* | النوع — *فرد* / *شركة* |
| people at a company | Contacts | Contacts | جهات الاتصال |
| TN tax id | Tax ID | **Matricule fiscal** | المعرّف الجبائي |
| FR tax id | VAT number | N° de TVA intracommunautaire | الرقم الضريبي |
| accounting screens only | Party / Third party | Tiers | الطرف |

Two i18n corrections that follow: today FR `sales:partners.taxId` = *"Numéro de TVA"* while `taxRegistrationNumber` = *"Matricule fiscal"* — two labels for one column, and the wrong one is the default for a TN tenant. And *Particulier* (not *Personne*) is the term a Tunisian or French merchant uses, and it is the word the statute uses.

**Arabic is structurally broken here and must be fixed as part of this work, not after.** There is no `ar/crm.json` at all — `lib/i18n.ts:398` hardwires `crm: enCrm`, so the CRM screens render **English inside an RTL layout**. Worse, `lib/i18n.ts:290-292` merges `sales` **shallowly**, so `arSales.partners` wholly *replaces* `enSales.partners`; since `ar/sales.json` has no `taxInfo`, `b2b`, `bankAccounts`, `paymentTerms` or `contacts` sub-blocks, the entire B2B section renders as **raw key strings with no English fallback**. `ar/deposits.json` and `ar/reports.json:customerStatement` are likewise absent. Any new party UI must ship AR keys in the same commit.

### 7.3 Target sidebar

```
Sales
  └ Customers            /sales/customers           module: Sales,     perm: partners.view
Purchases
  └ Suppliers            /purchases/suppliers       module: Sales?,    perm: partners.view      ← today gated on NOTHING
Customers & Marketing
  ├ (DELETE) Companies   /crm/companies                                                          ← redirect stub
  ├ (DELETE) Contacts    /crm/contacts                                                           ← becomes a tab
  ├ Loyalty programs / members, Promotions, Coupons          (unchanged)
Reports
  └ Customer statement   /reports/customer-statement          perm: accounts.view                ← NEW; backend + i18n already exist
```

Deletions: the `companies` nav entry and route (`Sidebar.tsx:264`, `routes/index.tsx:2785-2798`), `features/crm/pages/CompanyListPage.tsx`, the unused `crm:companies.*` key block, and the standalone `/crm/contacts*` routes. Keep `/partners` → `/sales/customers` legacy redirects (`routes/index.tsx:3201-3203`).

Permission repair (all four inconsistent today): view / create / edit / delete on a partner all gate on `partners.*`. `contacts.update` stops governing partner editing (`routes/index.tsx:627,876`). `/purchases/suppliers` gains a gate.

### 7.4 Screen map and affordance binding

**`/sales/customers` · `/purchases/suppliers`** — one list, role-filtered. Columns: Name, **Nature badge** (Particulier / Société), Code, Tax ID (**fix `partner.tax_id` → `vat_number`**; it renders `-` for all 135 VAT-bearing partners in `tenant019fbe86…` because `apps/web` hand-rolls its own `Partner` interface instead of consuming the generated `PartnerData` — CLAUDE.md rule 7), Phone, Balance, Status. New filter: nature. Balance column only meaningful for parties with movement — keep it, but the nature badge is what disambiguates the walk-in rows that dominate the count.

**`/…/:id` detail** — one tab set regardless of which URL you arrived by, resolving audit §3.2-A/B/D:

| Tab | Shown when | Notes |
|---|---|---|
| Overview | always | affordances gate on `party_kind` — §7.5 |
| **Contacts** | `party_kind = organization` | **NEW**: wire `usePartnerContacts` + `ContactPersonsSubForm`, both already written and orphaned. `GET /partners/{id}/contacts` is live. |
| Documents / Purchase orders | role-dependent | unchanged |
| Payments | always | unchanged |
| **Statement** | `hasPermission('accounts.view')` | **NEW**: `GET /reports/customer-statement/{id}` + `fetchCustomerStatement()` (`reportsApi.ts:91-101`, **zero call sites**) + a fully translated EN/FR key block. This is a build, not a design. |
| Vehicles | `hasModule('Vehicle')` + customer role | **Otospex.** Unchanged binding (`vehicles.partner_id`). |
| Deposits, Delivery notes | as today | unchanged |

**Vertical differences to respect.** `Partner` is in **all 12 verticals'** `default_modules` — it is never a differentiator, so nothing here can be module-gated on it; gate on permissions. `Vehicle`/`Workshop` are the true Otospex differentiators (absent from `parts_retailer`, `tire_shop`, `service_station`). There is **no `Contact` backend module**, so the CRM surfaces appear in every vertical including `restaurant` and `coffee_shop` — another reason to fold them into a tab rather than keep a top-level entry. Parapharmacy hides the **entire advanced import section** (5 cards, `ImportDashboardPage.tsx:106`), not just the partner importer; the customers/suppliers **Import** button routes to `?entity=customers|suppliers` → the primary `parties` card, so that path survives the hiding.

**Otospex vehicle→partner binding needs one fix while we are here.** The same relationship is expressed three ways: an **unpaginated raw `<select>` of every partner including suppliers** on vehicle create (`VehicleForm.tsx:238-252`), a read-only link list on the partner detail tab (`VehiclesTab.tsx`), and a proper searchable `PartnerPicker partnerType="customer"` on ownership transfer (`TransferOwnershipModal.tsx:109`). Make create use the picker.

### 7.5 The affordance binding table (this is the "no ambiguity" deliverable)

| Affordance | Today | Target gate |
|---|---|---|
| VAT / Matricule fiscal | unconditional (`PartnerForm.tsx:553-564`) | `organization` — or `person` who declares themselves fiscally registered |
| Tax status + exemption certificate | unconditional (`PartnerForm.tsx:604-663`) | `organization` |
| Company legal name, registration number | `customer_category === 'business'` (NULL for 100% of rows → **invisible on every real partner**) | `organization` |
| Payment terms, credit limit, discount %, invoice consolidation, bank accounts (RIB/IBAN/BIC) | same dead gate (`B2BFieldsSection.tsx:40-224`) | `organization`, or `person` with credit explicitly enabled |
| Credit-limit warning | `CreditLimitWarning.tsx` — **built, zero importers** | wire it wherever credit limit is shown |
| Contacts tab | does not exist | `organization` |
| Date of birth, gender, national ID, mobile | only on `contacts`, which nothing can bill | `person` — §8-OQ1 |
| Account statement | backend ×2, **zero UI** | any party with `accounts.view` |
| AR/AP balance columns | every row | keep, but read alongside the nature badge |
| Type select on a type-scoped create route | full customer/supplier/both (`PartnerForm.tsx:488-505`) — pick wrong and the record vanishes from where you created it | constrain to the route's context |

---

## 8. Converged spec skeleton

### 8.1 Schema deltas

**Tenant DB — `partners`:**

| Column | Type | Notes |
|---|---|---|
| `party_kind` | `varchar(20) NOT NULL` | PHP enum `PartyKind {Person, Organization}` (rule 9). Default `person` only for the duration of the backfill migration, then drop the default. |
| `date_of_birth`, `gender`, `national_id`, `mobile` | nullable | **only if OQ1 is answered "columns on `partners`"** — see §8.5 |
| `legal_form` | `varchar(50)` nullable | optional, `organization` only (SARL / SUARL / personne physique / association). Kept **out** of `party_kind` per the ERPNext lesson (§2.2). |
| index | `(tenant_id, company_id, party_kind)` | list filtering |
| index | `(tenant_id, phone_normalized)`, `(tenant_id, email_normalized)` | dedup — either generated columns or normalized-at-write |

**Backfill** (self-guarding; `origin/dev` push auto-deploys `tenants:migrate`):
```
customer_category = 'business'                              → organization
vat_number IS NOT NULL OR company_legal_name IS NOT NULL
  OR business_registration_number IS NOT NULL
  OR credit_limit IS NOT NULL OR payment_terms IS NOT NULL  → organization
type IN ('supplier','both')                                 → organization   -- a supplier is an org by default
everything else                                             → person
```
On the 2026-08-23 local snapshot that classifies the 223 suppliers and the 135 VAT-bearing customers of `tenant019fbe86…` as organizations and the rest as persons — a defensible default that an operator can correct per row.

**`customer_category` is NOT dropped.** It stays as a **derived** value: written from `party_kind` on save, exposed on the device wire (`PosCustomerMirrorResource.php:41`), and never editable in the UI. That is what keeps the sealed `'business'` literal and the `b2b_facture_draft_requested` rule byte-identical on both sides, and what removes the device migration from the critical path (§1.5).

**New table — `pos_receipt_party_links`** (non-fiscal, mutable; §1.4 makes this mandatory):

| Column | Notes |
|---|---|
| `id`, `tenant_id`, `company_id` | |
| `pos_receipt_id` | FK → `pos_receipts`, unique per receipt |
| `partner_id` | FK → `partners` |
| `source` | enum: `late_alias_resolution` \| `facture_escalation` \| `manual_correction` |
| `linked_by_user_id`, `linked_at`, `reason` | audit trail |

This is the **only** way a receipt acquires a party after fiscalization. The sealed `buyer` snapshot remains authoritative for fiscal purposes; this table serves customer history, loyalty backfill and the facture bridge.

**New column — `documents.source_pos_receipt_id`** (nullable, FK): marks a facture escalated from a receipt, so the TN *facture globale* consolidation can exclude it (§3.1). Alternatively a link table if a facture may consolidate several receipts — see OQ4.

**Rule 8 compliance:** `PartnerCreated` / `PartnerUpdated` / `PartnerDeleted` are frozen. `party_kind` needs `PartnerCreatedV2` / `PartnerUpdatedV2`, or a distinct `PartyKindAssigned` event. No existing event is edited.

### 8.2 POS flow deltas

**Flow 1 — attach a customer to a sale (this is `a1`, correctly scoped).**

```
cashier picks a customer
  → device resolves it to a SERVER partner id
      · mirrored customer                → customers.id
      · offline-created, alias resolved  → customer_aliases.server_partner_id
      · offline-created, NOT yet resolved → NO id available
  → BuildSaleReceiptPayloadInput gains ONE optional field: buyer?: BuyerBlockInput | null
  → buildSaleReceiptPayload emits:
        buyer = null                                  when nothing attached
        buyer = { name, customer_id: <server uuid>, tax_number, address: null,
                  contact_id: null, codice_fiscale: null }   when resolved
        buyer = { name, customer_id: NULL, tax_number: null, … }  when attached-but-unresolved
  → server validateBuyer() unchanged; TIGHTEN customer_id to uuid-or-null
  → PosCoreReceiptProjection writes partner_id / contact_id / customer_name /
    customer_identifier from the snapshot — D16 untouched, FK always satisfiable
  → unresolved case: on alias resolution the device calls
    POST /pos/receipts/{id}/party-link → pos_receipt_party_links
```

`contact_id` stays `null` at launch — the device has no contact mirror (§4.2). Say so explicitly in the builder so it is a decision, not an omission. Note the consequence: `SaleEarnContext.contactId` (`PosCoreReceiptProjection.php:1642-1646`) stays null; **loyalty earn keyed on partner starts working, loyalty keyed on contact does not** — which is fine, because `loyalty_programs.target_type` has no behavioural consumer at all today and should be dropped (audit b7).

**Flow 2 — facture on request (the escalation), end to end.**

```
① Sale completes normally. SALE_RECEIPT seals. Ticket prints.
   No new key. No new classification. No change to the sealed payload's meaning.

② Cashier (or back office) taps "Demander une facture" on the receipt.

③ Bind the BILLING PARTY:
     · buyer already attached and an organization  → propose it
     · buyer attached, a person, linked via party_contacts(is_invoice_contact)
                                                   → propose the linked organization
     · nothing attached                            → pick or create a party inline
   Minimum data enforced HERE, by DocumentPartyIdentityPolicy, per country:
     organization → name + address + tax id (TN matricule fiscal / FR SIREN) — HARD BLOCK
     person       → name + address                                          — no tax id

④ POST /pos/receipts/{id}/facture   (NON-fiscal, authenticated, idempotent per receipt)
     → creates a Document(type=Invoice) with source_pos_receipt_id
     → writes pos_receipt_party_links(source='facture_escalation')
     → the receipt row is NEVER touched (prevent_receipt_modification forbids it anyway)

⑤ Facture is numbered, posted, printed / (later) emitted to El Fatoora as TEIF.

⑥ The daily "facture globale" consolidation EXCLUDES receipts having a facture.
```

Why not reuse `invoice_classification` on `SALE_RECEIPT`? Because it does not exist there — the key sets are 28/30/33 keys and none is `invoice_classification`; adding it is a hard version bump for a signal that has a better home. And because a facture is a **document**, and the house rule is that every fiscal/GL mutation gets its own justifying document. This is the same shape Odoo ships (Paid → Invoiced via a post-hoc customer-supplied billing form) and the same shape TN art. 18 assumes.

**Existing machinery to reuse, not rebuild:** `DocumentAccountChargeFactureBridge` (`Document/Application/Projections/`) already turns a fiscal event into a facture draft, resolves the partner with tenant/company/type scoping, and hard-refuses a non-`synced` customer. The escalation endpoint should share its partner-resolution and draft-construction path.

### 8.3 Enforcement points

Restating §5.4 as a checklist, plus the fiscal ones:

1. `CreatePartnerRequest` / `UpdatePartnerRequest` — `party_kind` required; `required_without` phone/email pair; `prepareForValidation()` normalization; tax id via `CountryTaxNumberRules` with the **company country as fallback** when `country_code` is absent.
2. `PosPendingCustomerController:163-176` — unchanged (reference implementation); add `party_kind = person`.
3. `pendingCustomerRepository.enqueuePendingCustomer` — hoist the phone-or-email invariant off the two UI components.
4. `ImportType::Parties` — add optional `party_kind`; derive when absent; fix `code` `max:100` → `max:50` (audit a7 — `partners.code` is `varchar(50)`, so 51–100 chars currently pass validation and then fail with PG `22001`).
5. `PartnerService::upsertWithTypeMerge` — normalized phone/email above the name arm; case-insensitive trimmed name arm.
6. `CountryTaxNumberRules` — single source of truth for TN/FR/IT/DE/SA; **round-trip test: anything `CreatePartnerRequest` accepts must seal in `buyer.tax_number`** (§3.3).
7. New `DocumentPartyIdentityPolicy` — the only blocking tax-id check, at facture issuance, country-parameterised.
8. `FiscalPayloadConstraintValidator::validateBuyer` — tighten `customer_id` to uuid-or-null (safe: it is `null` on 100% of existing sealed events).
9. New golden canonical-bytes fixture over a **populated** buyer, beside the existing null one — closes the silent-value-change hole (§1.1).
10. `ContactController.php:218` — give Contact delete the same reference-census guard `PartnerController.php:361-378` has (audit b4).

### 8.4 Phasing, mapped onto the audit's (a)/(b)

**Phase 0 — P0 fix, before anything else** *(new; not in the audit)*
`CountryTaxNumberRules` convergence + the round-trip test (§3.3). Blocks the existing account-charge lane and every design below. **XS–S.**

**Phase 1 — pre-launch, shape-neutral** *(audit (a), re-scoped)*
`a1` per §8.2 Flow 1 + the populated-buyer golden fixture; `a2` (retire `ImportType::Partners` via `deprecationMessage()`, the pattern already used for `StockLevels`); `a3` (`tax_id` → `vat_number`, consume generated `PartnerData`); `a5` (delete Companies, fix the `contacts.update` gate); `a6` (constrain the Type select); `a7`; `a8`. **`a4` is re-scoped — see §9.**

**Phase 2 — `party_kind`** *(audit (b1/b2))*
Column + backfill + required on all five write paths + derived `customer_category` at the mirror boundary + re-gate every affordance in §7.5. **No device migration, no sealed-byte change.** M.

**Phase 3 — facture escalation** *(new)*
`DocumentPartyIdentityPolicy`, `POST /pos/receipts/{id}/facture`, `documents.source_pos_receipt_id`, facture-globale exclusion, device UI. M–L. Entry-gated on Phase 0 and Phase 2.

**Phase 4 — contacts and dedup** *(audit b3/b4/b5/b6/b7/b8)*
Contacts tab (wire the two orphans), Contact delete guard, `pos_receipt_party_links` + late binding, server-side dedup + tombstone merge (`merged_into_partner_id`, resolved on read — **never** a destructive re-key), import unknown-column warnings, drop `loyalty_programs.target_type`, update `docs/architecture/database.md` §8 (which still documents `account_balance` / `days_payable_outstanding` — columns that do not exist — and omits `contacts` and `party_contacts` entirely).

**Phase 5 — optional** contacts on the device → populate `buyer.contact_id` (variant (a)).

### 8.5 Risky seams — amendments to the audit's list

The audit's five seams stand. Three amendments:

- **Device mirror: downgraded.** `party_kind` needs no device migration if `customer_category` is derived at the wire boundary (§1.5). Device stays at v67.
- **Sealed payload: narrowed but sharpened.** No key change is needed anywhere in this design — but the `buyer` value change is invisible to the key-set drift gates, so it needs an explicit golden fixture, and `buyer.customer_id` must be constrained to resolved partner ids or the projection FK fails (§1.3).
- **New seam: `pos_receipts` identity is write-once.** `prevent_receipt_modification` permits only nulling `partner_id`+`contact_id` together. All late binding goes to `pos_receipt_party_links` (§1.4).

Plus the two the audit did not name:
- **TN tax-number regex divergence** (§3.3) — P0.
- **Arabic i18n is structurally broken** for these screens (§7.2) — no `ar/crm.json`, and the shallow `sales` merge drops `partners.b2b` / `bankAccounts` / `taxInfo` / `paymentTerms` / `contacts` with no English fallback.

### 8.6 Open questions for the owner — 5, each with my recommended answer

| # | Question | My recommendation |
|---|---|---|
| **OQ1** | Where do person attributes (date of birth, gender, national ID, mobile) live for a `person` party? The audit proposes auto-creating a 1:1 `Contact` per person party. | **Put four nullable columns on `partners`, gated on `party_kind = person`.** Auto-creating a Contact abuses an M:N pivot to hold a 1:1 fact, forces a join on every read, doubles the merge surface, and makes "Contacts" mean two different things. Keeping `contacts` strictly = *people at an organization* is also exactly Dolibarr, and exactly the owner's own words. I disagree with audit §6.1 rule 3 here. |
| **OQ2** | Does the standalone CRM Contacts surface survive? | **Delete it.** `contacts` has **0 rows in all 8 local tenants**, no seeder, no vertical, no billing path. Fold it into the organization detail's Contacts tab, wiring the two already-built orphans. Keep the table and the API. |
| **OQ3** | At facture issuance, is a missing TN matricule fiscal a hard block or a warning? | **Hard block for `organization`; not required for `person`.** TN art. 18 makes it mandatory and a non-compliant facture costs the *recipient* their VAT deduction — a warning here means shipping invoices your customer's accountant rejects. The `person` exemption is real and must not be blocked. |
| **OQ4** | Can one facture consolidate several receipts (a monthly facture for a regular account), or is it strictly 1 receipt → 1 facture? | **Ship 1:1 at launch** (`documents.source_pos_receipt_id`, a single nullable FK) and **design the exclusion logic against a link table shape** so N:1 can land later without a data migration. The existing `partners.invoice_consolidation` / `consolidation_frequency` columns say you will want N:1 eventually. |
| **OQ5** | Does the cashier get the "Demander une facture" button at launch, or is escalation back-office only? | **Cashier button at launch, server-side document creation.** A Tunisian customer asks at the counter, not by email the next day. The device only collects identity and calls a non-fiscal endpoint — no fiscal risk on the device. |

---

## 9. Do the a1–a8 lanes survive this target model?

The escalation note says a1/a2–a8 are "shape-neutral" and proceed regardless. **Verified against the recommendation: seven of eight survive; one is wrong and one is mis-labelled.**

| # | Verdict |
|---|---|
| **a1** — fix `buyer: null` | **Survives, but is NOT shape-neutral and needs re-scoping before dispatch.** It writes into signed canonical bytes (`FiscalEventEngine.ts:617-638`). Three amendments, all in §8.2 Flow 1: **(i)** `buyer.customer_id` must only ever carry a **resolved server partner id**, never a device-minted pending UUID — otherwise the projection violates the `pos_receipts.partner_id` FK and the receipt fails to project; **(ii)** `contact_id` stays `null` (the device has no contact mirror) — make that explicit; **(iii)** add a golden canonical-bytes fixture over a *populated* buyer, because the key-set drift gates cannot see a `null` → object value change. Also: the audit's "mirror the ACCOUNT_CHARGE builder" is ambiguous (§1.2) — populate the existing 6-key `buyer`, do **not** add a `customer` key. |
| **a2** — retire `ImportType::Partners` | Survives unchanged. Aligned. |
| **a3** — `tax_id` → `vat_number`, consume generated `PartnerData` | Survives, and is a **prerequisite** for Phase 2: `party_kind` cannot reach the FE cleanly while `apps/web` hand-rolls its own `Partner` interface. Promote it. Note `AddPartnerModal` needs the same treatment — it posts `tax_id`/`address` that `CreatePartnerRequest` silently drops, and a free-text `country` that 422s against `size:2`. |
| **a4** — show the B2B block when `customer_category IS NULL` | ⚠️ **The one item that is wrong under the target model — do not ship as written.** Treating NULL as "show it" makes every B2C walk-in display VAT number, credit limit, payment terms and bank accounts — it inverts audit §3.2-A instead of fixing it, and it must then be un-done in Phase 2. **Replace with:** make the nature field **required on create** (labelled *Particulier / Société*, not the unexplained "Customer Category"), and treat existing NULL rows as `organization` **only** where `vat_number`/`company_legal_name`/`business_registration_number`/`credit_limit` is populated — i.e. run the Phase-2 backfill heuristic early. That unblocks "give an imported company a credit limit" (the actual complaint) without spraying company fields over walk-ins. Cost is comparable; it is the same work done once. |
| **a5** — hide Companies, fix the `contacts.update` gate | Survives; §7.3 upgrades **hide → delete** (the stub, the route, the nav entry and the dead `crm:companies.*` keys). Also fix the sidebar/route gate mismatch (`contacts` vs `partners.view`) rather than preserving it. |
| **a6** — constrain the Type select | Survives unchanged. |
| **a7** — `code` `max:100` → `max:50` | Survives unchanged. |
| **a8** — `'retail'` literal → `null` | Survives; under Phase 2 the correct value becomes the **derived** `customer_category` from `party_kind` (`'individual'` for a POS-created person). `null` is right in the interim. |

**One addition to the pre-launch queue:** the §3.3 TN regex convergence. It is XS–S, it fixes a live defect on the existing account-charge lane, and every phase below depends on it.

---

## Sources

**Reference models**
- [odoo/odoo — `res_partner.py`](https://github.com/odoo/odoo/blob/14.0/odoo/addons/base/models/res_partner.py) · [The res.partner Model: Odoo's Contact Architecture Explained](https://www.dasolo.ai/blog/odoo-data-api-5/odoo-res-partner-model-guide-154) · [Odoo res.partner Concept](https://www.technaureus.com/blog-detail/odoo-partner-respartner-concept-2) · [Odoo forum — partner_id vs commercial_partner_id](https://www.odoo.com/forum/help-1/difference-between-partner-id-and-commercial-partner-id-in-partner-master-and-account-invoice-71045) · [Odoo API contact logic (duplicates, flat lists)](https://www.maesn.com/blog/odoo-contact-logic) · [Fix Odoo Partner Merge and Duplicate Contact Issues](https://deploymonkey.com/blog/odoo-partner-merge-duplicate-fix) · [Odoo 18 — Receipts and invoices (POS)](https://www.odoo.com/documentation/18.0/applications/sales/point_of_sale/receipts_invoices.html)
- [ERPNext — Customer, Contact and Address Relationship](https://docs.frappe.io/erpnext/customer-contact-and-address-relationship) · [ERPNext — Customer doctype](https://docs.frappe.io/erpnext/customer) · [frappe/erpnext#38233 — Customer Type & Customer Group fields rethinking](https://github.com/frappe/erpnext/issues/38233) · [Frappe Dynamic Link fields](https://docs.erpnext.com/docs/v13/user/manual/en/customize-erpnext/articles/dynamic-link-fields)
- [Dolibarr wiki — Module Third Parties](https://wiki.dolibarr.org/index.php?title=Module_Third_Parties) · [Table llx_societe](https://wiki.dolibarr.org/index.php?title=Table_llx_societe) · [Table llx_socpeople](https://wiki.dolibarr.org/index.php?title=Table_llx_socpeople)
- [Square — Customer object reference](https://developer.squareup.com/reference/square/objects/Customer) · [Lightspeed Retail X-Series — managing customers](https://x-series-support.lightspeedhq.com/hc/en-us/articles/25534063246235-Managing-customers-in-Retail-POS-X-Series) · [Lightspeed Retail — Customer API](https://developers.lightspeedhq.com/retail/endpoints/Customer/)
- [Silverston — A Universal Person and Organization Data Model (TDAN)](https://tdan.com/a-universal-person-and-organization-data-model/5014) · [The Data Model Resource Book vol. 3, ch. 3 — Using Roles](https://www.oreilly.com/library/view/the-data-model/9780470178454/ch03.html)

**Tunisia**
- [Code de la TVA, art. 18 — obligations des assujettis (Jurisite Tunisie)](https://www.jurisitetunisie.com/tunisie/codes/tva/tva1060.htm) · [Profiscal — système de taxation du chiffre d'affaires, ch. 9](https://www.profiscal.com/etudiants/TCA/tca_ch9_06.htm) · [Mentions obligatoires sur les factures — risques](https://tunisieconseilfiscal.over-blog.com/article-attention-risque-lie-au-non-respect-des-mentions-obligatoires-sur-les-factures-60862007.html) · [Finco — créer une facture conforme en Tunisie (2026)](https://finco.tn/blog/comment-creer-une-facture-conforme-en-tunisie-guide-complet-2026) · [El Fatoora / TTN](https://elfatoora.digital/index.php?lang=en) · [Facturation électronique en Tunisie 2026](https://shazler.com/blog/facturation-electronique-tunisie-2026-el-fatoora) · [Matricule fiscal — structure](https://gastevo.com/blog/matricule-fiscal-tunisie-guide)

**France**
- [Tout savoir sur la facturation — entreprendre.service-public.gouv.fr](https://entreprendre.service-public.gouv.fr/vosdroits/F23208?lang=fr) · [Ticket de caisse : obligations des professionnels — economie.gouv.fr](https://www.economie.gouv.fr/entreprises/gerer-son-entreprise-au-quotidien/gerer-un-commerce/ticket-de-caisse-professionnels-quelles-sont-vos-obligations) · [Facture particulier — obligations et seuils 2026](https://softindep.fr/obligation-facture-particulier/) · [SIREN du client sur la facture électronique — obligations 2026](https://ma-facture-electronique.org/reforme-2026/nouvelles-mentions-obligatoires/siren-client/) · [Mentions obligatoires de la facture électronique](https://www.fiducial.fr/facturation-electronique/faq/mentions-obligatoires-facture-electronique)

**EN 16931 (for the buyer-block field set)**
- [EN 16931 explained — fields and business rules](https://validatefin.com/en/blog/en16931-complete-guide) · [ConnectingEurope/eInvoicing-EN16931 schematron](https://github.com/ConnectingEurope/eInvoicing-EN16931/blob/master/ubl/schematron/abstract/EN16931-model.sch)
