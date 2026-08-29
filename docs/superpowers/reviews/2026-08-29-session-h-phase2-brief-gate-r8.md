<!-- Codex CLI read-only adversarial gate, round 8 (check-6 scope), Session H orchestrator 2026-08-29; brief r8 at 257b4892c. -->

# Round-8 adversarial gate register

**Brief:** `docs/handoff/CODEX-DISPATCH-session-H-phase2-party-kind-2026-08-29.md` — revision r8  
**HEAD:** `13dd72f5b540f70da116f9a232ce6b18feb67b35`  
**Brief commit:** `257b4892ca2f9893d25957ed098ca706c1c52f19`

## Check 6

| Section | PASS/FAIL | Evidence |
|---|---|---|
| Industry baseline — presence and Decisions | PASS | Section exists at `brief:68`; B1–B8 each has a non-empty `MATCH`, `DEFER`, or `ALREADY` Decision. All table rows have the header’s nine pipe delimiters. |
| AutoERP evidence — B1, B2, B3, B5, B8 | PASS | Fresh spot-greps substantiate the cited claims: `Partner.php:102,151`; customer-category migration `:21-30`; `CreateDocumentRequest.php:66-70`; `CreatePartnerRequest.php:83-87` plus `PartnerForm.tsx:556-564`; `PosCustomerMirrorResource.php:30-32,50`; partners migration `:20`. |
| AutoERP evidence — B4, B6, B7 | FAIL | B4 cites only the `Gender` enum and does not substantiate all four fields or their absence from `partners`. B6 cites nonexistent `database/migrations/tenant/2025_11_30_214948_…`; the real file is `apps/api/database/migrations/2025_11_30_214948_…:27`. B7’s current `PartnerService.php:67-69` is input preparation; the dedup ladder moved to `:83-92`. |
| From-memory B3 | FAIL | Odoo 17 declares `vat` without `required=True` and its fiscal-position logic falls back to non-VAT rules; ERPNext v15’s `tax_id` likewise has no `reqd`. Neither supports the blanket “company tax ID required only at invoice time” guarantee. [Odoo source](https://github.com/odoo/odoo/blob/17.0/odoo/addons/base/models/res_partner.py#L211), [Odoo invoice logic](https://github.com/odoo/odoo/blob/17.0/addons/account/models/partner.py#L243-L250), [ERPNext source](https://github.com/frappe/erpnext/blob/version-15/erpnext/selling/doctype/customer/customer.json#L200-L204). |
| From-memory B4 | FAIL | ERPNext v15 contradicts the row: Customer `mobile_no` and `email_id` are read-only values fetched from the linked primary Contact, rather than person attributes owned by Customer. [ERPNext source](https://github.com/frappe/erpnext/blob/version-15/erpnext/selling/doctype/customer/customer.json#L303-L324). |
| From-memory B5 | FAIL | The cited products model credit control as limits, not the asserted explicit account-charge eligibility flag: ERPNext uses a `Customer Credit Limit` child table, while Dolibarr stores numeric `outstanding_limit`. [ERPNext source](https://github.com/frappe/erpnext/blob/version-15/erpnext/selling/doctype/customer/customer.json#L458-L464), [Dolibarr source](https://github.com/Dolibarr/dolibarr/blob/19.0/htdocs/societe/class/societe.class.php#L1493-L1501). |
| From-memory B6 | PASS | Odoo has partner `lang`; ERPNext Customer and Supplier have `language`/“Print Language”; Dolibarr tiers has `default_lang`. [Odoo source](https://github.com/odoo/odoo/blob/17.0/odoo/addons/base/models/res_partner.py#L197-L199), [ERPNext Customer](https://github.com/frappe/erpnext/blob/version-15/erpnext/selling/doctype/customer/customer.json#L258-L263), [Dolibarr source](https://github.com/Dolibarr/dolibarr/blob/19.0/htdocs/societe/class/societe.class.php#L1493-L1497). |
| From-memory B7 | FAIL | Normalization does not guarantee duplicate prevention. Odoo’s phone-validation extension only reformats on change. Dolibarr strips phone punctuation and trims email, while email uniqueness is conditional on `SOCIETE_EMAIL_UNIQUE`; no universal phone/email dedup guarantee exists. [Odoo source](https://github.com/odoo/odoo/blob/17.0/addons/phone_validation/models/res_partner.py#L10-L17), [Dolibarr normalization](https://github.com/Dolibarr/dolibarr/blob/19.0/htdocs/societe/class/societe.class.php#L1282-L1293), [Dolibarr optional uniqueness](https://github.com/Dolibarr/dolibarr/blob/19.0/htdocs/societe/class/societe.class.php#L1191-L1201). |
| Second-of-everything — second company | PASS | `brief:105-116` names the server/browser journey and its meaningful assertions: same code in company A/B, scoped lists, independently derived values, and POS mirror isolation. Existing company scoping is verified at partner-code migration `:19-23`, VAT migration `:52-58`, `PartnerController.php:118`, and `PosCustomerSyncController.php:44`. |
| Second-of-everything — second location | PASS | Fresh `rg 'location_id\|location_code' apps/api/database/migrations/tenant/*partner* apps/api/app/Modules/Partner/Domain/Partner.php` returned zero matches. The no-location-bound-behaviour claim is verifiable. |
| Second-of-everything — re-run | PASS | `brief:121-125` names Migration A, Migration B, and Parties-import reruns. Their contracts are present in M1.2 (`:384-389`), M2.7 (`:753-759`), and M4 (`:929-946`). |
| Non-unique index statement | PASS | M1.2 specifies exactly three `CREATE INDEX IF NOT EXISTS` indexes at `brief:364-365`, matching `brief:126-130`; none is declared unique. |
| Ratchet caveat | PASS | `TenantOnlyUniqueOnCatalogueTablesRatchetTest.php` is absent both at `a33b01354` and current HEAD. The brief says it cannot yet be run, requires construction-time compliance, and makes a future failure a STOP (`brief:131-136`); that is honest. |
| Concepts ✅ resolution | PASS | `Party` resolves exactly at `docs/glossary.md:26`; `Contact` resolves exactly at `:29`, including the corrected organization-contact and non-billable meanings. |
| NEW glossary task | PASS | The five promised concepts are not falsely marked ✅. M1 explicitly adds Nature, Legal form, Preferred locale, Credit account, and Contact point/normalized contact point using the existing glossary schema (`brief:145-160`). |

## NEW findings

| Finding | Severity | Defect and required correction |
|---|---|---|
| N-34 | MAJOR | Check-6 evidence is not fully grep-valid. Replace B4’s enum-only citation with paths covering all four Contact fields plus the `partners` absence; correct B6 from `database/migrations/tenant/...` to `database/migrations/...`; repin B7’s dedup ladder to current `PartnerService.php:83-92`. |
| N-35 | MAJOR | B3’s three baseline checkmarks are wrong as a general Odoo/ERPNext/Dolibarr guarantee. Change the baseline cells/wording to reflect optional or localization-dependent tax identity. The owner-ruled Phase 2/3 Decision need not change. |
| N-36 | MAJOR | B4 is wrong for ERPNext v15: mobile/email are sourced from Contact. Correct the ERPNext cell and narrow the guarantee; the AutoERP Decision may remain. |
| N-37 | MAJOR | B5 conflates numeric credit limits with an explicit charge-eligibility flag. Correct at least the ERPNext and Dolibarr cells or restate the baseline as explicit per-party credit controls rather than a boolean flag. |
| N-38 | MAJOR | B7 overclaims that normalization prevents duplicate people. Correct the product cells to distinguish formatting/normalization, optional uniqueness checks, and actual deduplication. The Phase 2 storage/Phase 4 matching split may remain unchanged. |

VERDICT: CHANGES-REQUIRED
