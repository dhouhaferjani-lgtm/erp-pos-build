# AutoERP Glossary — canonical domain terms (one surface per concept)

> One sentence per term. When a plan, spec, brief, migration, tile, import type or FE type uses a noun, it is
> **this** noun; the row says which table, module and operator surface are canonical and which synonyms are
> tolerated. Adding a concept = adding a row here **in the same lane** (convention
> [11-ONE-SURFACE-PER-CONCEPT](conventions/11-ONE-SURFACE-PER-CONCEPT.md)). Platform-level terms live in
> `../../../claude/glossary.md` (monorepo root `claude/glossary.md`); it points here for ERP terms (reciprocal pointer lives in the platform repo and is committed separately). Created 2026-08-29 (Session I); the Party rows are
> Session H's to keep current.

Columns: **Term** — definition · **Table / module** · **Canonical surface** (where an operator creates/edits it) ·
**Synonyms** (tolerated in UI copy only, never in code identifiers).

## Tenancy and organisation

| Term | Definition | Table / module | Canonical surface | Synonyms |
|---|---|---|---|---|
| **Tenant** | One customer account of the platform; owns one PostgreSQL database (`tenant_<uuid>`) and every row below. | `tenants` (central DB) / `Tenant` | signup (`/register`), super-admin | account, workspace |
| **Company** | A legal entity inside a tenant with its own country, currency, chart, sequences, catalogue scope and books; a tenant has ≥ 1. Catalogue keys are meant to be unique **per company** (convention 09; several are still tenant-wide today — see the tenant-only-unique ratchet baseline). | `companies` / `Company` | **Create:** the company switcher → `/company-onboarding` (`CompanySelector.tsx` → `POST /api/v1/companies`); **edit:** Settings → Companies (current company only — it cannot create). `AddCompanyModal.tsx` is an orphaned duplicate create surface rendered by nothing (owner ruling owed: delete or wire). | société, entity |
| **Location** | A physical site of a company (shop, warehouse, branch); stock, drawers and POS terminals bind to a location. Exactly one is the company default; `pos_enabled` marks a till site. | `locations` / `Location` | Settings → Locations | site, branch, warehouse, point de vente |
| **Membership** | A user's access to a company (roles are Spatie team-scoped by tenant). | `user_company_memberships` / `Identity` | Settings → Users | — |

## Parties (Session H owns these rows)

| Term | Definition | Table / module | Canonical surface | Synonyms |
|---|---|---|---|---|
| **Party** | Any external counterparty the company trades with; role is `customer`, `supplier` or `both`. The single canonical concept — a "customer" and a "supplier" are *roles* of a party, not separate entities. | `partners` / `Partner` module (table name is historical) | Partners list/form; importer `ImportType::Parties` (the balance-bearing tile *"Partenaires commerciaux — … avec soldes d'ouverture"*). **Today a second tile "Partenaires" (`ImportType::Partners`) also exists and silently drops balances** — retirement + `import-tile-parties`/`customers`/`suppliers` presets are Session G lane G-9 (pending). | partner, partenaire, tiers, business partner |
| **Customer** | A party in the customer role: receivables (AR), sales documents, POS attach. | role on `partners` | same surface, preset `customers` | client |
| **Supplier** | A party in the supplier role: payables (AP), purchase documents. | role on `partners` | same surface, preset `suppliers` | vendor, fournisseur |
| **Contact** | A person attached to a party (name, phone, email, role). Not a party. **Two tables exist today: `party_contacts` (the `Contact` module, canonical) and a legacy `contacts` table — Session H rules on the second one's fate; until then nothing new writes `contacts`.** | `party_contacts` / `Contact` | Party detail → Contacts | contact person |
| **Party balance** | The sum of a party's open items (invoices − credit notes − allocated payments) per company; read from the sub-ledger, never stored on the party. | documents + `payment_allocations` | Party detail → Balance | solde, encours |

## Catalogue

| Term | Definition | Table / module | Canonical surface | Synonyms |
|---|---|---|---|---|
| **Product** | A sellable/stockable item of a company, keyed by **SKU** — unique per **company** since G-3a (before: `products_tenant_id_sku_unique`); per-company scoping landed with Session G lane G-3a (dev `68c698f1a`, 2026-08-29: products/variant SKU and partner VAT-number keys are company-scoped; `product_variants` barcode stays tenant-wide by RUL-2) — with optional barcode, unit, tax rate, batch-tracking flag. | `products` / `Product` | Products list/form; importer `ImportType::Products` | article, item |
| **SKU** | The operator code of a product and the import upsert key; tenant-unique today, company-scoped after G-3a. | `products.sku` | product form | code article, référence |
| **Unit** | A unit of measure with `decimal_places` (drives every displayed quantity) inside a **unit category** that names its base unit; ≥ 19 seeded on day one (N-9). | `units`, `unit_categories` / `Uom` | Settings → Units | UoM, unité |
| **Category / Brand / Attribute** | Product classification rows an operator edits per company. | `categories`, `brands`, `product_attributes` | Products → settings | famille, marque |
| **Lot (batch)** | A quantity of a batch-tracked product sharing a batch number and expiry; stock of such a product is Σ lots; a `DEFAULT` lot backs openings without a number. Expiry is never invented (W4-1). | `product_batches`, `inventory_batch_stock` / `Inventory` | receipts, opening stock import, counting | batch, péremption |
| **Stock level** | On-hand quantity of a product (or variant) at a location; unique per `(tenant, product, location)` for non-variant rows and `(tenant, product, variant, location)` for variant rows; the projection of stock movements. | `stock_levels` | Inventory → Stock | stock, on hand |

## Money and books

| Term | Definition | Table / module | Canonical surface | Synonyms |
|---|---|---|---|---|
| **Account** | A chart-of-accounts row (code unique per company); system behaviour resolves accounts by **purpose**, never by code. | `accounts` / `Accounting` | Accounting → Chart | compte |
| **Purpose** | The functional tag of an account (`SystemAccountPurpose`: `vat_collected`, `sales_return`, `refund_write_off`, …); a fresh company must have every purpose in `ProvisioningRequiredPurposesV1::requiredPurposes()` tagged exactly once (the manifest is a partition: REQUIRED / SCOPE_REQUIRED / CONDITIONAL / SOFT — only REQUIRED is a hard day-one invariant). | `accounts.system_purpose` | seeded by country defaults; `accounting:backfill-chart-purposes` | — |
| **Tax configuration** | A VAT/stamp rate row of a country with one default per company. | `tax_configurations` / `Taxation` | Settings → Taxes | TVA, VAT rate |
| **Payment method** | A tender type (cash, card, cheque, …) of a company; exactly one is the **cash tender** (`is_cash_tender`). | `payment_methods` / `Treasury` | Settings → Payment methods | tender, mode de règlement |
| **Repository** | A place money sits: **cash register (drawer)**, **safe**, **bank account**, virtual. A drawer is attributed to exactly one POS location; registration seeds one drawer + one safe for the FIRST company (`TenantInitializationService`); **an additional company created via `POST /api/v1/companies` gets none today (open gap, product finding I2-F1)**; a new `pos_enabled` location gets its drawer (`LocationController::provisionCashRegisterIfPosEnabled`). | `payment_repositories` (`RepositoryType`) / `Treasury` | Treasury → Repositories | caisse, coffre, drawer, till |
| **Payment** | Money received or paid, landing in a repository, later **allocated** to open items. | `payments`, `payment_allocations` | Treasury → Payments | règlement, encaissement |
| **Allocation** | The link between a payment and the document it settles (partial allowed); what makes a party balance move. | `payment_allocations` | payment form → allocate | lettrage, matching |
| **Opening batch** | The set of opening entries (AR/AP open items, stock, treasury float, bank openings) of a company's migration; **locking** it is the end-of-migration seal after which balance imports are refused. | opening batches / `Accounting` (`opening-batches.lock`) | Accounting → Opening balances; importers Parties (balances), Products (opening stock), `OpeningBalances` | reprise, soldes d'ouverture |
| **Historical document** | A posted document created from an opening balance (`HIST-INV`, `HIST-CN`, `HIST-SINV`, `HIST-SCN`), dated at its true date, outside VAT declaration, with no product lines. | `documents` | created only by the balances phase of the Parties importer | facture historique |
| **Journal entry** | The GL posting produced by a business document/event; `(source_type, source_id)` is not globally unique. | `journal_entries` | read-only (Finance → Journal) | écriture |

## Documents and POS

| Term | Definition | Table / module | Canonical surface | Synonyms |
|---|---|---|---|---|
| **Document** | One row of the unified `documents` table; `type` (`DocumentType`) is quote / order / invoice / credit note / delivery note / supplier variants. | `documents` / `Document` | Sales / Purchasing | — |
| **Draft** | A document before posting: `document_number` is **NULL** (deferred numbering); a numbered draft is an invariant violation. | `documents.status = draft` | document form | brouillon |
| **Document number** | The fiscal sequence value assigned at posting. Sequences are per company (`document_sequences (company_id, type, year)`), but the number itself is unique per **tenant** `(tenant_id, type, document_number)` — two companies of one tenant share the key (open hazard, baselined by the tenant-only-unique ratchet; owner ruling owed). | `document_sequences`, `documents` | assigned by posting | numéro |
| **Terminal** | A POS device identity bound to one location; claims a drawer for its shift. | `pos_terminals` / `POS` | POS → Terminals | caisse (device), till |
| **Shift** | The open-to-close period of a terminal on a drawer; closes with a **Z** (totals sealed, variance recorded after a **cash count**). | `pos_shifts` / `POS` | POS device; server API contract | session, clôture |
| **Receipt** | A sealed POS sale (`SALE_RECEIPT` event, `unit_price` tax-inclusive); its refund is a `pos_receipt_refund`. | `pos_receipts` / `Fiscal` | POS device | ticket |
| **Import type** | One selectable importer (`ImportType`): Parties, Partners (retirement pending, lane G-9), Products, OpeningBalances, CompositeItems, ProductImages. **`StockLevels` is the retired case** (`@deprecated`, `deprecationMessage()`, excluded from `selectable()`; readable for historical `import_jobs` only). Target: every selectable type has exactly one terminal writer (RETRO G1 — today Parties and Partners share `importPartner`). | `import_jobs`, `import_rows` / `Import` | Settings → Import (tiles `import-tile-<entity>`) | — |

## Process terms

| Term | Definition |
|---|---|
| **Second-of-everything** | Convention 09: second company + second location + re-run test for any catalogue change. |
| **Industry baseline** | Convention 10: the Odoo/ERPNext/Dolibarr guarantees a flow spec must table before its own requirements. |
| **Day-one census** | The list of invariants a freshly registered tenant/company must satisfy (`tenant:census-day-one`, `FreshTenantCensusInvariantsTest`). |
| **Onboarding campaign** | The scripted fresh-tenant journey (`scripts/campaign-onboarding.sh`) that is a promotion precondition (`docs/qa/ONBOARDING-CAMPAIGN.md`). |
| **Manual testing loop** | `docs/qa/MANUAL-TESTING-LOOP.md` — cadence, fresh-tenant rule, bug-report shape, triage into F-style sessions. |
