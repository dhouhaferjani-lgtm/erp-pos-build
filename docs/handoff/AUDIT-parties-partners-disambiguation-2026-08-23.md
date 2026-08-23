# AUDIT — `parties` vs `partners`, and contact-vs-company disambiguation

**Date:** 2026-08-23
**Scope:** read-only investigation, main checkout `/Users/houssamr/Projects/syneriva/apps/erp`, local `dev` tip `fa807a699`.
**Trigger:** LEDGER/owner-sheet **B-4** (`docs/handoff/OWNER-SHEET-2026-08-21-first-client-session.md:48`), widened by the owner after hitting real issues while testing (`…OWNER-SHEET…:22`).
**Nothing was modified.** Empirical checks ran read-only against local PG `autoerp_postgres` (host port 5433).

---

## 0. Headline

**There is no `parties` table.** There never was. Grep of every tenant migration for `parties` returns nothing but `party_contacts`. The word "party" entered the codebase in March 2026 as *new vocabulary for the existing `partners` table* and was never carried through to the schema, so the product now speaks two dialects for one entity.

The real situation is three-layered, and only the first layer is what B-4 described:

| Layer | What it is | Verdict |
|---|---|---|
| **1. Two import types, one table** | `ImportType::Parties` and `ImportType::Partners` both write `partners` via the same writer. Parties is a strict superset. | **B-4 CONFIRMED** + more differences than reported |
| **2. A second, parallel person entity** | `contacts` (+ `party_contacts` pivot) — a real table, a real module, a real CRM UI. | **Dead in practice: 0 rows in all 8 local tenant DBs, 0 seeders, transactable nowhere** |
| **3. No individual-vs-organization axis in use** | `partners.customer_category` (`individual`\|`business`) exists but is **NULL for 100% of partners in every local tenant** and has zero behavioural consumers except one fiscal gate that can therefore never pass. | **The disambiguation the owner is asking for does not exist yet** |

The "contact actions vs company actions" problem is therefore not a matter of moving buttons between two screens. **The company/person distinction is not modelled on the entity that carries the money.** Every field a company needs (VAT number, credit limit, payment terms, bank accounts, AR/AP balance) hangs off `partners` unconditionally, and every field a person needs (first/last name, DOB, gender, national ID) hangs off `contacts`, which nothing can bill.

---

## 1. The two models' anatomy

### 1.1 `Partner` — the load-bearing entity

* Model: `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Partner/Domain/Partner.php:82`
* Table: `partners`, created `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2025_11_30_052119_create_partners_table.php:16-35`
* Owner module: `Partner` (`app/Modules/Partner/`), listed as a module in **all 12 verticals** — `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/config/verticals.php:26,55,87,117,145,175,203,233,262,292,320,348`.

Discriminator: `type` = `customer` \| `supplier` \| `both` (`app/Modules/Partner/Domain/Enums/PartnerType.php:7-11`). This is a **role** axis, not a legal-nature axis.

Columns accreted in waves (all on `partners`):

| Wave | Columns | Migration |
|---|---|---|
| base 2025-11-30 | name, type, code, email, phone, country_code, vat_number, notes | `2025_11_30_052119_create_partners_table.php:19-26` |
| balances 2025-12-06 | receivable_balance, credit_balance, payable_balance, balance_updated_at | `2025_12_06_100001_add_balance_fields_to_partners.php` |
| tax 2026-01 | tax_status, tax_exemption_*, withholding_* | `2026_01_09_111429…`, `2026_01_02_100001…`, `2026_01_08_172040…` |
| B2C/B2B split 2026-03-09 | **customer_category** (`individual`\|`business`) | `2026_03_09_100001_add_customer_category_to_partners.php:22-25` |
| B2B fields 2026-03-11 | company_legal_name, business_registration_number, payment_terms, payment_terms_days, **credit_limit**, discount_percentage, invoice_consolidation, consolidation_frequency | `2026_03_11_600000_add_b2b_fields_to_partners.php:18-27` |
| address 2026-03-10 | street_address, street_address_2, city, state, postal_code, country | `2026_03_10_100000_add_address_fields_to_partners.php` |
| account status 2026-05 | account_status, account_status_version, … | `2026_05_22_102000_add_account_status_to_partners_table.php` |
| skin (parapharmacy) 2026-06 | skin_type, skin_advice_note | `2026_06_28_100000_add_skin_type_to_partners_table.php` |
| bank accounts 2026-07 | `partner_bank_accounts` child table | `2026_07_12_120000_create_partner_bank_accounts_table.php` |

**Everything points at `partners`.** The codebase maintains an explicit registry of every table carrying a pointer to `partners.id` — `App\Shared\Application\Partner\PartnerReferenceTable`, declared per-module. **24 tables across 16 modules**:

| Module | Tables | Declaration |
|---|---|---|
| Document | `documents` (**NOT NULL** FK — `2025_11_30_080000_create_documents_table.php:16`) | `app/Modules/Document/Application/Services/DocumentPartnerReferenceSource.php:27` |
| Accounting | `journal_lines` | `…/AccountingPartnerReferenceSource.php:28` |
| Treasury | `payments`, `payment_instruments` | `…/TreasuryPartnerReferenceSource.php:30-31` |
| POS | `pos_receipts`, `pos_orders`, `pos_customer_aliases`, `pos_deposit_receipts`, `pos_account_charge_receipts`, `pos_account_payment_receipts` | `app/Modules/POS/Application/Services/PosPartnerReferenceSource.php:57-62` |
| Workshop | work orders (`customer_partner_id` **NOT NULL** — `2026_04_19_130001…:37`), work order lines (`core_deposit_partner_id`) | `…/WorkshopPartnerReferenceSource.php:31,36` |
| Vehicle | `vehicles`, `vehicle_ownership_history` (`owner_partner_id`) | `…/VehiclePartnerReferenceSource.php:29-30` |
| Scheduling | `scheduling_appointments` (`customer_partner_id`) | `…/SchedulingPartnerReferenceSource.php:28` |
| Taxation | `withholding_certificates`, `sales_withholding_tracking` | `…/TaxationPartnerReferenceSource.php:29-30` |
| Loyalty | `loyalty_members` | `…/LoyaltyPartnerReferenceSource.php:39` |
| Expense | `expense_recurrence_templates` | `…/ExpensePartnerReferenceSource.php:28` |
| Promotion / Coupon | `promotion_usages`, `coupon_usages` | `…:27` each |
| Voucher / Marketplace / PlatformIntegration | vouchers, buyer_seller_mappings, platform_supplier_mappings | resp. sources |

Plus the fiscal chain: `fiscal_events.partner_id` + `fiscal_events.partner_identity_snapshot`, both **immutable by DB trigger** (`2026_05_14_100001_create_fiscal_events_table.php:53-54`, `2026_05_14_100002_create_fiscal_events_immutability.php:91-92`).

**There is no equivalent registry for `contacts`.** Grep for `ContactReferenceTable` returns nothing.

### 1.2 `Contact` — the person entity that can't transact

* Model: `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Contact/Domain/Contact.php:47`
* Table: `contacts` — `…/database/migrations/tenant/2026_03_10_100001_create_contacts_table.php:16-44`
* Columns: `first_name, last_name, email, phone, mobile, date_of_birth, gender, national_id, avatar_media_id, notes, is_active` (+ `profile_metadata` jsonb, 2026-03-27). **Genuinely person-shaped.**
* Pivot: `party_contacts(party_id → partners.id, contact_id → contacts.id, job_title, department, is_primary, start_date, end_date)` — `2026_03_10_100002_create_party_contacts_table.php:15-40`; `is_invoice_contact` / `is_delivery_contact` added `2026_03_11_600001…:15-19`.
* Relations: `Contact::parties()` (`Contact.php:116-121`), `Partner::contacts()` (`Partner.php:447-452`), `Partner::primaryContact()` (`Partner.php:457-460`).

**Only two things in the whole system point at `contacts.id`:**
1. `party_contacts.contact_id` (the pivot itself)
2. `pos_receipts.contact_id` — `2026_03_10_100003_add_contact_id_to_pos_receipts.php:17-23`

Nothing else. **No document, no journal line, no payment, no work order, no appointment can name a Contact.** `documents.partner_id` is NOT NULL and constrained to `partners`, so *every* sales and purchase document — including `Invoice`, `CreditNote`, `SupplierInvoice`, `DeliveryNote` (`app/Modules/Document/Domain/Enums/DocumentType.php:9-20`) — is addressed to a Partner.

`Contact` is also **absent from `config/verticals.php`** — the Partner module is enabled in every vertical, Contact in none; the `/crm/contacts` surface is gated only by the `contacts.view` permission (`apps/web/src/hooks/usePermissions.ts:79`), and the backend Contact routes carry no `module:` middleware at all (`app/Modules/Contact/routes.php:20`).

### 1.3 Load-bearing entity, per flow

| Flow | Entity that carries it | Evidence |
|---|---|---|
| Sales documents (quote/order/invoice/CN/DN) | **Partner** (mandatory) | `documents.partner_id` NOT NULL, `create_documents_table.php:16` |
| Purchase documents (PO/supplier invoice/RFQ) | **Partner** (same table) | ditto |
| GL / AR / AP sub-ledger | **Partner** | `journal_lines.partner_id`, `2025_12_06_100000_add_partner_id_to_journal_lines.php:16-28` |
| Treasury payments & instruments | **Partner** | `create_treasury_tables.php:96,142` |
| Workshop work orders | **Partner** (mandatory, `customer_partner_id`) | `2026_04_19_130001…:37` |
| Vehicle ownership (Otospex) | **Partner** | `vehicle_ownership_history.owner_partner_id` NOT NULL, `2026_04_19_100001…:19` |
| POS device sale | **neither, in practice** — see §5.1 | `SaleReceiptPayload.ts:152` |
| POS account charge / deposit / payment | **Partner**, via device-uuid → `pos_customer_aliases` → `partners` | `PosPendingCustomerController.php:81-102`; `TreasuryAccountPaymentBridge.php:335-372` |
| Loyalty | **polymorphic** `partner`\|`contact`; from POS always `partner` | `2026_03_10_100004_make_loyalty_polymorphic.php:18-23`; `MemberProvisioningService.php:37,46` |
| Imports (both types) | **Partner** | `ImportService.php:404-406`, both arms reach `importPartner` |
| Opening balances (AR/AP) | **Partner**, keyed by `partners.code` | `PartiesBalancesPhase.php:44-49`, `PartiesRowMapper.php:105` |

---

## 2. How it happened — from git history

Three independent waves, none of which retired its predecessor.

**Wave 1 — 2025-11-30, `d6d1ee90d` "feat(partner): implement partners module with full CRUD".**
Creates `partners`. Same day, `2937b07d3` adds `ImportType::Partners` (`app/Modules/Import/Domain/Enums/ImportType.php:10`) — an 8-column CSV importer: name, type, email, phone, vat_number, address, city, country.

**Wave 2 — 2026-03-09, `c82083fe9` "feat: product/service separation, POS enhancements, contacts, platform integration, and automotive metadata".**
A large mixed commit whose message line reads *"Add Contact module with CRUD and polymorphic party associations"*. This is where **"party" is coined as a synonym for Partner**: the new pivot is `party_contacts` with a `party_id` column pointing at `partners.id`; the DTO is `ContactPartyData`; the endpoints are `POST /contacts/{id}/link-party` and `DELETE /contacts/{id}/unlink-party/{partyId}` (`app/Modules/Contact/routes.php:41-45`). The same commit adds `contacts`, `party_contacts`, `pos_receipts.contact_id`, and the whole `apps/web/src/features/crm/` frontend. Two days earlier/later in the same wave, `customer_category` (2026-03-09) and the B2B field block (2026-03-11) land on `partners`.

The intent is legible: **`partners` was to become "Party" (an organization or an individual you trade with), and `contacts` the natural persons attached to it** — the classic party/contact CRM model. The rename to `parties` never happened. The `Partner` model, table, module, routes, permissions and 24 FK columns stayed put.

**Wave 3 — 2026-07-03, `5e6e86aec` "feat(import): ImportType::Parties — signed opening_balance columns, FR aliases, wizard touchpoints, sign-quadrant row mapper (plan tasks 5+7, TDD)".**
Adds `ImportType::Parties` (`ImportType.php:9`) with `PartiesRowMapper` and, later, `PartiesBalancesPhase`. It uses the *new* vocabulary and the *new* column names (`tax_id`, `address_line1`, `opening_balance*`) while writing the *old* table. Crucially the commit touched **5 files only** (enum, wizard, mapper, 2 tests) — **it never touched `ImportType::Partners`**, never deprecated it, never removed it from the dashboard.

**So: not two verticals' parallel inventions, and not a completed legacy replacement.** It is one intended rename (Partner → Party) that was executed in the *vocabulary* of two later features and never in the *schema* — plus a superseding importer that shipped alongside its predecessor instead of replacing it.

**Bridge between them:** none is needed for `parties`/`partners` (same table). Between `partners` and `contacts` the only bridge is the `party_contacts` pivot; there is **no migration that ever populated it**, and no data path that creates a Contact from a Partner or vice versa.

---

## 3. The individual-vs-organization axis

### 3.1 What exists

**One column, and it is dead.** `partners.customer_category` — enum `individual` \| `business` (`app/Modules/Partner/Domain/Enums/CustomerCategory.php:7-10`), added 2026-03-09 with the explicit intent documented in the migration (`2026_03_09_100001…:13-17`):

> `individual`: B2C customers (lightweight: name, phone, email)
> `business`: B2B customers (rich data: company name, tax ID, addresses, payment terms)

**Empirically (local PG, read-only):**

```
tenant019fbe86…  890 customers + 223 suppliers → customer_category NULL for all 1113
tenant01a01b77…  213 partners                  → NULL for all 213
tenant019fe276…  236 partners                  → NULL for all 236
```

Nothing writes it except the web form's optional dropdown:
* `CreatePartnerRequest.php:45` / `UpdatePartnerRequest.php:84` — `nullable`, never required.
* `PartnerService::upsertWithTypeMerge` (`app/Modules/Partner/Application/Services/PartnerService.php:88-107`) — **the import writer does not include `customer_category` in its payload at all**. Neither import type can set it.
* `PosPendingCustomerController.php:82-94` — **the POS customer creator does not set it either.**
* `PartnerForm.tsx:507-520` — an optional `<Select>` defaulting to `''` (`PartnerForm.tsx:206`).

`Partner::isB2B()` (`Partner.php:229-232`) has **zero callers** anywhere in `app/`.

**No parent-company link exists.** There is no `parent_partner_id`, no `is_company`/`is_individual`/`entity_type`/`legal_form` column, and the frontend agent's sweep of `apps/web/src` for those names returned zero hits. The only "contact belongs to company" relation in the system is `party_contacts`, which as established holds **0 rows in every local tenant**.

`Contact` has no organization axis either — it is unconditionally a person.

### 3.2 Concrete misbindings the owner can hit

**A. Company-only affordances offered on anything, including a walk-in individual**

| Affordance | Where | Why it's a misbinding |
|---|---|---|
| **VAT number** input | `PartnerForm.tsx:553-564` — rendered unconditionally | Shown for a POS walk-in with `customer_category` NULL/`individual`. `partners` also carries `unique(tenant_id, vat_number)` (`create_partners_table.php:34`), so two individuals both left blank are fine but two rows sharing a national-ID-as-VAT collide. |
| **Tax status + exemption certificate upload** | `PartnerForm.tsx:604-663` — unconditional | An exemption certificate is an organizational instrument. Offered on every party. |
| **Business registration number, payment terms, credit limit, discount %, invoice consolidation, bank accounts (RIB/IBAN/BIC)** | `components/B2BFieldsSection.tsx:40-224` | Gated **only** by `customer_category === 'business'` (`PartnerForm.tsx:667-676`). Since the column is NULL for 100% of real rows, **these are invisible for every existing partner** — including genuine B2B companies imported from CSV. The client cannot set a credit limit on an imported company without first hand-flipping a dropdown nobody explains. |
| **Credit-limit / payment-terms round-trip loss** | same | Save a partner as `business` with credit limit + bank accounts, then blank the category → the entire editing surface disappears while the stored values remain. |
| **Account statement** | Backend route exists: `GET /companies/{companyId}/partners/{partnerId}/statement` (`app/Modules/Accounting/Presentation/routes.php:82`) | **Never surfaced.** `PartnerDetailPage` has no statement action; its tab set is `['overview','documents','payments','vehicles','deposits','delivery-notes']` (`PartnerDetailPage.tsx:119`). A company-relevant action that exists but is unreachable. |
| **AR/AP balance columns** | `PartnerListPage.tsx:328-335,394-398` | Rendered for every row regardless of nature. Correct for a B2B account, meaningless for a POS walk-in — and the walk-in dominates the row count. |

**B. Person-only affordances that cannot reach the money**

| Affordance | Where | Why it's a misbinding |
|---|---|---|
| Date of birth, gender, national ID, mobile, avatar | `ContactDetailPage.tsx:132-175`, `create_contacts_table.php:24-27` | Live **only** on `contacts`. A Partner that *is* a natural person has nowhere to record them. |
| Job title / department / primary / invoice-contact / delivery-contact | `party_contacts` (`2026_03_11_600001…:15-19`), returned by `GET /partners/{id}/contacts` | The endpoint works; the UI hook `apps/web/src/features/partners/hooks/usePartnerContacts.ts:27` is imported **only by a test**, and the complete editor `apps/web/src/features/partners/components/ContactPersonsSubForm.tsx` is imported by **nothing**. A company detail page cannot show its people. |
| Loyalty targeting | `loyalty_programs.target_type` defaults to `'contact'` (`2026_03_10_100004…:35`) | The column is cast (`LoyaltyProgram.php:81`) and **has no behavioural consumer** — grep for `target_type` in `app/Modules/Loyalty` returns only the model. A program declared to target contacts still enrols partners. |

**C. Asymmetric destructive semantics**

* Deleting a **Partner** is blocked by a 24-table reference census returning HTTP 409 `PARTNER_HAS_DOCUMENTS` (`PartnerController.php:361-378`), with the comment: *"The partner is soft-deleted, so the DB FKs never fire and the referencing rows would silently point at an invisible partner."*
* Deleting a **Contact** is an unguarded `$contact->delete()` (`ContactController.php:218`). Contact also uses `SoftDeletes` (`Contact.php:53`), so the exact hazard the Partner guard was written to prevent applies verbatim to `pos_receipts.contact_id` — with no guard.

**D. Navigation misbinding**

* The sidebar shows **"Companies"** under Customers & Marketing (`apps/web/src/components/organisms/Sidebar/Sidebar.tsx:264`) → `/crm/companies` → `CompanyListPage.tsx:4-11` is a bare `useEffect(navigate('/sales/customers'))` stub. "Companies" and "Customers" are literally the same screen.
* `/partners` and `/partners/*` redirect to `/sales/customers` (`apps/web/src/routes/index.tsx:3202-3203`). **There is no "Parties" screen at all** — the only place a user meets the word "parties" is the import dashboard.
* Both **edit** routes gate on `contacts.update` while their create routes gate on `sales.create` / `purchases.create` (`routes/index.tsx:627, 876`), even though a `partners.update` permission exists (`permissionsMap.generated.ts:149`). Editing a customer requires a CRM-contacts permission.

**E. Two divergent Partner create forms**

`AddPartnerModal` (opened inline from every `PartnerPicker`) posts `tax_id` and `address` (`AddPartnerModal.tsx:204, 199, 213`). `CreatePartnerRequest` declares **neither** (`CreatePartnerRequest.php:43-104`), and `PartnerController::store` spreads only `$request->validated()` (`PartnerController.php:208, 215-219`). **The VAT number and street address typed into that modal are silently discarded.** The full form posts `vat_number` / `street_address` correctly (`PartnerForm.tsx`).

Same drift on read: `PartnerListPage.tsx:33,392` renders `partner.tax_id`, but `PartnerData` exposes only `vat_number` (`app/Modules/Partner/Application/DTOs/PartnerData.php:37`) and `tax_id` appears nowhere in `packages/shared/types/generated.d.ts`. **The "Tax ID" column on the customers and suppliers lists is always `-`.** Root cause: `apps/web` hand-rolls its own `Partner` interface instead of consuming the generated `PartnerData` (CLAUDE.md rule 7).

**F. One fiscal gate that can never pass**

`FiscalPayloadConstraintValidator.php:1725-1729` refuses an ACCOUNT_CHARGE payload classified `b2b_facture_draft_requested` unless `customer.customer_category === 'business'`. The value is mirrored to the device from `partners.customer_category` (`PosCustomerMirrorResource.php:41`), which is NULL everywhere. Separately, the POS device writes the **invalid literal `'retail'`** into its local mirror on optimistic create (`apps/pos/src/lib/customer/pendingCustomerCreateService.ts:46`, `apps/pos/src/components/customers/CustomerAttachPanel.tsx:163`) — a value the server enum does not contain.

---

## 4. The import duplication — B-4 confirmed, and it is worse than reported

Both types funnel into the same writer:

```
ImportService::importRow()                                app/Modules/Import/Services/ImportService.php:404-421
  ImportType::Parties  → importParty(…)  → PartiesRowMapper::toPartnerData → importPartner   :405, :426-435
  ImportType::Partners → importPartner($job->tenant_id, $row->data, …)                        :406
importPartner → PartnerService::upsertWithTypeMerge                                           :485-490
```

**The opening-balance claim — confirmed at the exact line:**

```php
// ImportService.php:452-459
public function finalizeImport(ImportJob $job, string $companyId): void
{
    $results = match ($job->type) {
        ImportType::Parties          => $this->partiesBalancesPhase->run($job->refresh(), $companyId),   // :455
        ImportType::Products         => …,
        ImportType::OpeningBalances  => …,
        default                      => [],                                                              // :458  ← Partners lands here
    };
```

`ImportType::Partners` matches `default` and posts **nothing**. `PartiesBalancesPhase::run` (`app/Modules/Import/Services/PartiesBalancesPhase.php:30-73`) is what creates the AR and AP `OpeningBalanceBatch`es and posts them via `ArApOpeningService`. A client who picks "Partners" loses every AR/AP opening with **no error and no warning**.

### 4.1 Everything else that differs

| Dimension | `Parties` | `Partners` |
|---|---|---|
| **Columns** | 2 required + 13 optional = **15** (`ImportType.php:91, 109-123`) | 2 required + 6 optional = **8** (`ImportType.php:92, 124`) |
| **Opening balances** | `opening_balance`, `opening_balance_customer`, `opening_balance_supplier`, `balance_date`, `reference` | **absent** |
| **Partner code** | `code` accepted; **auto-synthesised** as `IMP-{job8}-{row}` when any balance column is present, so the balance phase has a key (`ImportService.php:429-432`; consumed at `PartiesBalancesPhase.php:44-47`) | **not an advertised column** |
| **Dedup** | identical writer, but with `code` available the key ladder is **code → vat_number → exact name** (`PartnerService.php:65-70, 82-86`) | no `code`, so the ladder degrades to **vat_number → exact name**. "SARL Ben Ali" vs "Sarl Ben Ali" ⇒ two rows. |
| **Tax id header** | `tax_id` → mapped to `vat_number` (`PartiesRowMapper.php:23`) | `vat_number` directly |
| **Address** | `address_line1`, `address_city`, `address_postal_code`, `address_country` (4 discrete) | `address`, `city`, `country` — **no postal code** |
| **Cross-field validation** | `applyPartiesExtraValidation` enforces the sign quadrant: type `both` must not use bare `opening_balance`; a customer row must not carry `opening_balance_supplier`; etc. (`ImportService.php:179-180, 197-215`; rules `PartiesRowMapper.php:59-77`) | **none** |
| **Money precision ceiling** | 3-decimal regex on all balance columns (`ImportType.php:148-150`), per CLAUDE.md rule 19 | n/a |
| **Lock awareness** | refuses/warns when an AR/AP opening batch is already `Locked`, and detects conflicting unlocked batches (`PartiesBalancesPhase.php:65-67, 101-108`) | n/a |
| **Wizard label** | "Business Partners" — *"Import customers and suppliers with optional opening balances."* (`MigrationWizardService.php:454-458`) | "Partners (Customers & Suppliers)" — *"Import your customer and supplier records. Should be imported first."* (`:459-463`) |
| **Dashboard placement** | **primary** grid (`apps/web/src/features/import/pages/ImportDashboardPage.tsx:36`) | **advanced** `<details>` grid (`:49`), hidden only for the parapharmacy vertical (`:106, 216`) |
| **i18n copy** | "Business partners / Import customers and suppliers with opening balances" | "Partners / Import customers and suppliers" (`apps/web/src/locales/en/import.json:50-57`) |
| **Deprecation status** | selectable | **selectable** — `isDeprecated()` returns false; `ImportController::store` validates with a bare `new Enum(ImportType::class)` (`ImportController.php:91`) and special-cases only `ProductImages` and `OpeningBalances` (`:126, 135`) |

### 4.2 Two aggravating factors

1. **`getMigrationStatus` reports the same number under both keys** — `'parties' => $partnerCount` and `'partners' => $partnerCount` (`MigrationWizardService.php:419-426`). The wizard cannot tell the operator which importer they already used.
2. **Extra columns are silently swallowed.** `ValidationEngine::validateHeaders` computes `unknown` but sets `is_valid` from `missing` alone (`app/Modules/Import/Services/ValidationEngine.php:126-142`), and `ImportController` only surfaces `unknown_columns` inside the **422 body it returns when required columns are missing** (`ImportController.php:180-193`). Upload a full parties-shaped file under type `partners` and it imports cleanly, dropping `tax_id`, all address detail and all balances without a word.

### 4.3 Adjacent defect found while confirming

`ImportType.php:144` validates the parties `code` column as `max:100`, but `partners.code` is `varchar(50)` (`create_partners_table.php:21`; `CreatePartnerRequest.php:30` correctly uses `max:50`). A 51–100 character code passes validation and then fails the row with a PG `22001` truncation error at write time. (The auto-synthesised code is ~14–18 chars, so this only bites operator-supplied codes.) Note also that the code uniqueness scope was narrowed from `(tenant_id, code)` to `(company_id, code)` by `2025_12_30_195300_fix_multi_company_unique_constraints.php:20-23`, while `vat_number` uniqueness is still tenant-wide.

---

## 5. What the owner most likely hit while testing

Ranked by likelihood × visibility. Each is verified in code; the first is also verified in data.

### 5.1 🔴 The customer attached at the POS is dropped from the sale

**The device hardcodes `buyer: null` in the sealed sale payload** — `apps/pos/src/lib/fiscal/payloads/SaleReceiptPayload.ts:152`. The V3 builder spreads V2/V1 without touching it. The server projection, by design (invariant D16), reads the buyer **only** from the sealed payload with no live lookup:

```php
// PosCoreReceiptProjection.php:361-366
// Buyer block snapshot — D16 invariant: read ONLY from the parsed payload.
$buyer = $view->buyer;
$customerName = $buyer?->name;
$partnerId    = $buyer?->customerId;
$contactId    = $buyer?->contactId;
```

Consequences: `pos_receipts.partner_id`, `contact_id`, `customer_name`, `customer_identifier` all NULL for every device-authored sale; `GET /pos/receipts?customer_id=` (`ReceiptController.php:120-121`) never returns them; and **no loyalty points are earned** on device sales (`PosCoreReceiptProjection.php:1642-1643` passes the two null ids into `SaleEarnContext`).

**Verified in data:** in `tenant019fbe86…`, all 5 `pos_receipts` rows have `partner_id IS NULL AND contact_id IS NULL`; `pos_customer_aliases` is empty.

The cashier sees the customer attached on screen (it lives in Zustand — `apps/pos/src/stores/paymentStore.ts:1834-1846`) and then finds no trace of them on the receipt or the customer's history. This is the single most likely thing the owner hit.

### 5.2 🔴 "Tax ID" is always blank on the customers and suppliers lists

`PartnerListPage.tsx:392` reads `partner.tax_id`; the API returns `vat_number` and nothing else (`PartnerData.php:37`; `tax_id` absent from `packages/shared/types/generated.d.ts`). Every row shows `-` even for partners whose VAT number is populated (135 of them in `tenant019fbe86…`).

### 5.3 🟠 VAT number and address typed into the inline "Add partner" modal vanish

`AddPartnerModal` posts `tax_id` / `address`; `CreatePartnerRequest` declares neither; `PartnerController::store` spreads only validated keys. Create a supplier inline from a purchase-order picker, fill in the tax number, save — the partner is created without it, and re-opening it shows the field empty. (§3.2-E, `AddPartnerModal.tsx:204,213` vs `CreatePartnerRequest.php:43-104`, `PartnerController.php:208-219`.)

### 5.4 🟠 The B2B block — credit limit, payment terms, bank accounts — is invisible on every real partner

Gated on `customer_category === 'business'` (`PartnerForm.tsx:667-676`), a column that is **NULL for 100% of partners in all three populated local tenants** and that neither importer nor the POS ever sets. Import your supplier list, then try to give one a credit limit or a RIB: the section does not exist until you notice and change an unlabelled "Customer Category" dropdown. If the owner then also tried a POS B2B account charge, `FiscalPayloadConstraintValidator.php:1725-1729` would refuse it.

### 5.5 🟠 A customer saved from `/sales/customers/new` can silently leave the customers list

`PartnerForm.tsx:488-505` always renders the full customer / supplier / both `Type` select even on a type-scoped route; `PartnerController::index`'s type filter is exclusive (`PartnerController.php:70-80`). Pick the wrong option and the record is created but does not appear where it was created from — it is only reachable from `/purchases/suppliers`.

### 5.6 🟡 Duplicate customers from the POS

`PosPendingCustomerController` dedups **only** on the device's `client_customer_uuid` (`:57-61`) — there is no server-side match on phone, email or name. The device uuid is content-hashed over `tenant|company|name|phone|email` (`apps/pos/src/components/customers/customerAttachUtils.ts:19-39`), so editing the spelling of a name before sync mints a fresh uuid and a second `partners` row. Nothing later merges them, and the partner-delete guard will refuse to remove whichever one acquired a receipt.

### 5.7 🟡 "Companies" and "Contacts" are a dead end

The sidebar "Companies" entry is a redirect stub to the customers list (`CompanyListPage.tsx:4-11`), and `/crm/contacts` writes to a table that is empty in every tenant, has no seeder, appears in no vertical, and whose rows cannot be placed on any document. A user who does the natural thing — create the company under Companies, then its people under Contacts — ends up with a Partner and a set of Contacts that **the company's own detail page will never display** (no Contacts tab; `PartnerDetailPage.tsx:119`).

### 5.8 🟡 The wrong importer

Two cards labelled "Business partners" and "Partners" with near-identical descriptions (`import.json:50-57`), the weaker one one `<details>` toggle away (`ImportDashboardPage.tsx:49`). Picking it loses openings silently. *(Mitigating: the Import button on the customers/suppliers list always routes to `parties` — `ImportDashboardPage.tsx:82-91` maps `customers`/`suppliers` → `'parties'`.)*

---

## 6. Recommendation

### 6.1 Target model

Adopt the model the 2026-03 wave already started, and finish it — **without renaming the table**.

```
partners  (keep the table name; "Party" stays a UI word)
├─ type            : customer | supplier | both          ← ROLE      (exists)
├─ party_kind      : person | organization  NOT NULL     ← NATURE    (NEW — replaces customer_category)
└─ contacts (M:N via party_contacts)                     ← PEOPLE    (exists, unused)
```

Three rules, each mechanically checkable:

1. **`party_kind` is required and immutable-ish.** Every write path sets it: web form (required radio, not an optional dropdown), both importers, `PosPendingCustomerController` (→ `person`), `AddPartnerModal`. Backfill: `business` → `organization`; everything else → `person` where `vat_number IS NULL AND company_legal_name IS NULL`, else `organization`.
2. **Organization-only affordances gate on `party_kind = organization`**, not on the current optional dropdown: VAT number, tax status + exemption certificate, business registration number, company legal name, payment terms, credit limit, invoice consolidation, bank accounts, account statement, Contacts tab.
3. **Person-only affordances gate on `party_kind = person`** *or* live on `contacts`: DOB, gender, national ID, mobile, avatar. A `person` party gets a 1:1 auto-created `Contact` so those fields have a home and loyalty stops being polymorphic-by-accident.

Do **not** retire `customer_category` immediately — `FiscalPayloadConstraintValidator` and the sealed POS payload schema depend on the literal `business`. Derive it from `party_kind` at the fiscal boundary instead.

**Explicitly reject** the "make Contacts billable" alternative: `documents.partner_id` is NOT NULL across every document type, `journal_lines`, `payments`, work orders and `fiscal_events` all key on `partners.id`, and `fiscal_events.partner_id` is immutable by DB trigger. Contacts must stay the *people at* a party, never the party.

### 6.2 Migration path

#### (a) Pre-launch minimum — **S**, no schema change, no data migration

| # | Action | Files | Size |
|---|---|---|---|
| a1 | **Fix `buyer: null` on the device sale payload** (§5.1). Populate the buyer block from `selectedCustomer` in `SaleReceiptPayload`/`receiptService`, mirroring the ACCOUNT_CHARGE builder which already carries a full customer block. | `apps/pos/src/lib/fiscal/payloads/SaleReceiptPayload.ts:152` + `receiptService.ts` + `paymentStore.createReceiptLocalFirst` | **M** — touches a *sealed payload shape*; needs the fiscal-pos reviewer and a canonical-bytes/golden-fixture pass. Not optional: without it "customer" does not work at the till at all. |
| a2 | **Retire `ImportType::Partners` exactly as `StockLevels` was retired**: add a `deprecationMessage()` arm, which makes `isDeprecated()`/`selectable()` do the rest, and drop the card from `ADVANCED_IMPORT_TYPES`. Keep the case for historical reads (same `ValueError` hazard documented at `ImportType.php:26-38`). Add the gate in `ImportController::store`/`::execute` beside the existing ones. | `ImportType.php:50-56`, `ImportController.php`, `ImportDashboardPage.tsx:49`, `ImportWizardPage.tsx:144-153`, `import.json` | **S** — strictly better than the hide+banner in B-4, and the pattern is already in the file. |
| a3 | **Fix `tax_id` → `vat_number` drift** on the list column and the inline modal; make `apps/web` consume the generated `PartnerData` (rule 7). Add `tax_id`/`address` to nothing — fix the client. | `PartnerListPage.tsx:33,392`, `AddPartnerModal.tsx:31,44,182,204,312` | **S** |
| a4 | **Surface the B2B block on `customer_category = NULL`** as an interim (treat NULL as "show it"), or make the dropdown required on create. Otherwise no imported company can be given a credit limit. | `PartnerForm.tsx:667` | **S** |
| a5 | **Hide the "Companies" sidebar entry** (it is a redirect stub) and/or hide `/crm/contacts` until (b) lands. Fix the `contacts.update` gate on the two partner **edit** routes → `partners.update`. | `Sidebar.tsx:264-265`, `routes/index.tsx:627,876,2785-2798` | **S** |
| a6 | **Constrain the Type select** to the route's context on `/sales/customers/new` and `/purchases/suppliers/new` (allow `customer`/`both`, resp. `supplier`/`both`). | `PartnerForm.tsx:488-505` | **S** |
| a7 | **Fix `code` max:100 → max:50** in the parties import rules. | `ImportType.php:144` | **XS** |
| a8 | **Fix the `'retail'` literal** in the POS optimistic mirror → `null`. | `pendingCustomerCreateService.ts:46`, `CustomerAttachPanel.tsx:163` | **XS** |

#### (b) Post-launch consolidation — **M / L**

| # | Action | Size | Risky seams |
|---|---|---|---|
| b1 | Add `partners.party_kind` NOT NULL + backfill; make it required in `CreatePartnerRequest`, both import types, `PosPendingCustomerController`, `AddPartnerModal`. | **M** | Migration runs on `origin/dev` push (auto-deploy incl. `tenants:migrate`) — must be self-guarding. No FK impact. |
| b2 | Re-gate every affordance in §3.2-A/B on `party_kind`; derive `customer_category` for the fiscal boundary rather than reading it. | **M** | `FiscalPayloadConstraintValidator.php:1725-1729` and the ACCOUNT_CHARGE payload schema are **canonical bytes** — the derived value must be byte-identical to what devices already sign. |
| b3 | Wire the Contacts tab on `PartnerDetailPage` using the already-working `GET /partners/{id}/contacts` and the already-written `ContactPersonsSubForm`; make party names on `ContactDetailPage` real links. Auto-create a 1:1 Contact for `party_kind = person`. | **S–M** | Pure UI + one service; both halves already exist and are orphaned. |
| b4 | Give Contact delete the same reference guard Partner has; decide the `pos_receipts.contact_id` semantics (probably: block delete, as for Partner). | **S** | `ContactController.php:218`; mirrors `PartnerController.php:361-378`. |
| b5 | Server-side POS customer dedup on normalised phone/email in `PosPendingCustomerController`, plus a merge tool. | **M** | See seams below — merge is the hard one. |
| b6 | Add `unknown_columns` as a **warning on success** in the import result workbook, not only inside the 422. | **S** | `ValidationEngine.php:126-142`, `ImportController.php:180-193`. |
| b7 | Either wire `loyalty_programs.target_type` to actual behaviour or drop it; today it is a stored lie. | **S** | `LoyaltyProgram.php:81`. |
| b8 | Update `docs/architecture/database.md` §8 — it still documents `account_balance` / `days_payable_outstanding` (columns that do not exist) and **omits `contacts` and `party_contacts` entirely** (`docs/architecture/database.md:829-851`). | **S** | — |

#### Named risky seams

* **FKs — 24 tables, 16 modules.** Any partner *merge* must rewrite every column in the `PartnerReferenceTable` registry (`app/Shared/Application/Partner/PartnerReferenceTable.php`; declarations listed in §1.1). The registry is the asset that makes this tractable — use it, and extend the sweep test (`tests/Feature/Partner/PartnerReferenceSchemaSweepTest.php`) rather than hand-listing tables.
* **Rule 8 / fiscal immutability.** `fiscal_events.partner_id` and `partner_identity_snapshot` are protected by a DB trigger (`2026_05_14_100002_create_fiscal_events_immutability.php:91-92`), and `pos_receipts` by `prevent_receipt_modification` (`2026_05_26_100001_allow_pos_receipt_fk_cleanup.php:17-60`, which permits *only* nulling both id columns together on a fiscalized row). **A partner merge can never rewrite a fiscal event's partner id.** A merge must therefore be an alias/tombstone (`merged_into_partner_id`) that read paths resolve, not a destructive re-key. `PartnerCreated`/`PartnerUpdated`/`PartnerDeleted` (`app/Modules/Partner/Domain/Events/`) are equally frozen — a `party_kind` change needs a new event or a versioned successor.
* **Imports.** `PartiesBalancesPhase` keys AR/AP openings on `partners.code` (`PartiesBalancesPhase.php:44-47`). Any dedup/merge change must not orphan a synthesised `IMP-…` code, and the enter-once batch guard (`openingBalancesLocked`, `:183-189`) must keep holding.
* **POS offline copies.** Three device artefacts mirror `partners`: SQLite `customers` (`apps/pos/src/lib/db/migrations.ts:1166-1191`, extended at v41/v42/v59), `customer_aliases` (`:1216-1225`), `pending_customer_outbox` (`:1197-1226`). Adding `party_kind` means a **new device migration + a POS device version bump**, and `PosCustomerMirrorResource` must ship the field before the device reads it. The device's `StaleCustomerAliasConflictError` guard (`pendingCustomerRepository.ts:37-51`) means an alias can never be re-pointed — so a server-side merge that changes which partner a device uuid resolves to needs an explicit device-side reconciliation path, not a silent update.
* **Deltas / sync ordering.** `syncService.ts:2321-2331` pushes pending customers *before* pulling the delta, deliberately. Any change to the pending-customer contract must preserve that ordering or optimistic rows will be clobbered before promotion.

### 6.3 Suggested sequencing

**a1** is the only pre-launch item that is genuinely load-bearing for the client (customers do not work at the till without it) and it is also the only pre-launch item that touches sealed fiscal bytes — dispatch it as its own gated lane with the fiscal-pos reviewer. **a2–a8** are a single small cleanup lane. Everything in (b) is post-launch and should be entry-gated on a written spec for `party_kind`, because b1/b2 change a value that ends up inside signed payloads.

---

## Appendix — empirical checks (read-only, local PG `autoerp_postgres`:5433)

```
partners / contacts / party_contacts / pos_receipts, per tenant DB
  tenant4c3a1260…      0 /   0 / 0 / 0
  tenantbe3cd47a…      0 /   0 / 0 / 0
  tenant019fe276…    236 /   0 / 0 / 0
  tenant019fbe86…   1113 /   0 / 0 / 5
  tenant019fcf48…     12 /   0 / 0 / 0
  tenantf6c592ac…     33 /   0 / 0 / 0
  tenant3f16ac36…     19 /   0 / 0 / 0
  tenant01a01b77…    213 /   0 / 0 / 1

tenant019fbe86… partners by type / customer_category
  customer | (null) | 890 | with_code 190 | with_vat 135 | with_balance 240
  supplier | (null) | 223 | with_code  18 | with_vat   9 | with_balance  11

tenant019fbe86… pos_receipts     → 5 rows, partner_id NULL AND contact_id NULL for all 5
tenant019fbe86… pos_customer_aliases → 0
tenant019fbe86… duplicate partner names → none
tenant01a01b77… / tenant019fe276… customer_category → NULL for 100%
```

`contacts` is empty in every tenant because **no seeder creates one** — `grep -rl "Contact::" database/seeders/` matches only `RolesAndPermissionsSeeder.php` (the permission strings), while 9 seeders create `Partner` rows.
