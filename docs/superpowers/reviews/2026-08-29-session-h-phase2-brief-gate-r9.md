<!-- Codex CLI read-only adversarial gate, round 9 (closing check-6), Session H orchestrator 2026-08-29; brief r9 at c7fa8d92a. -->

# Round-9 adversarial gate register

**Brief:** `docs/handoff/CODEX-DISPATCH-session-H-phase2-party-kind-2026-08-29.md` — revision r9  
**HEAD:** `c7fa8d92a1e6977ef1bfcc81d6308e248c9cc4ed`

## Round-8 findings

| Finding | Status | Evidence |
|---|---|---|
| N-34 | RESOLVED | B4’s four contact fields grep at `create_contacts_table.php:24-27`; the cited partner migrations and `Partner.php` return zero hits. B6’s central migration has `preferred_locale` at `apps/api/database/migrations/2025_11_30_214948_add_personal_info_to_tenants_table.php:27`. B7’s matching ladder is exactly `PartnerService.php:83-92`. |
| N-35 | RESOLVED | B3 now describes tax identity as optional/localization-dependent and retains the owner-ruled `DIVERGE`. Odoo declares `vat` without `required=True` and falls back to non-VAT fiscal positions; ERPNext’s `tax_id` has no `reqd`. [Odoo partner](https://github.com/odoo/odoo/blob/17.0/odoo/addons/base/models/res_partner.py#L197-L211), [Odoo fiscal-position logic](https://github.com/odoo/odoo/blob/17.0/addons/account/models/partner.py#L243-L250), [ERPNext Customer](https://github.com/frappe/erpnext/blob/version-15/erpnext/selling/doctype/customer/customer.json#L200-L204). |
| N-36 | RESOLVED | B4 marks ERPNext as contradictory: `mobile_no` and `email_id` are read-only and fetched from `customer_primary_contact`. Decision is populated with `MATCH`/`DIVERGE`. [ERPNext Customer](https://github.com/frappe/erpnext/blob/version-15/erpnext/selling/doctype/customer/customer.json#L303-L324). |
| N-37 | RESOLVED | B5 now states numeric per-party credit controls, not boolean eligibility. ERPNext uses the `Customer Credit Limit` child table; Dolibarr stores `outstanding_limit`. Decision explicitly distinguishes `ALREADY` from AutoERP’s additional eligibility flag. [ERPNext Customer](https://github.com/frappe/erpnext/blob/version-15/erpnext/selling/doctype/customer/customer.json#L458-L464), [Dolibarr tiers](https://github.com/Dolibarr/dolibarr/blob/19.0/htdocs/societe/class/societe.class.php#L1493-L1501). |
| N-38 | RESOLVED | B7 distinguishes formatting/normalization, conditional email uniqueness, and actual deduplication. Odoo only reformats on change; Dolibarr strips phone punctuation, trims email, and gates uniqueness behind `SOCIETE_EMAIL_UNIQUE`. Decision contains `MATCH` and `DEFER`. [Odoo phone validation](https://github.com/odoo/odoo/blob/17.0/addons/phone_validation/models/res_partner.py#L10-L17), [Dolibarr normalization](https://github.com/Dolibarr/dolibarr/blob/19.0/htdocs/societe/class/societe.class.php#L1282-L1293), [Dolibarr conditional uniqueness](https://github.com/Dolibarr/dolibarr/blob/19.0/htdocs/societe/class/societe.class.php#L1191-L1201). |

## Check 6

| Section | PASS/FAIL | Evidence |
|---|---|---|
| Industry baseline — presence and Decisions | PASS | Section at `brief:71`; B1–B8 are present, all eight Decisions contain `MATCH`, `DEFER`, `DIVERGE`, or `ALREADY`, and every row has the expected nine pipe delimiters. |
| Industry baseline — AutoERP evidence | PASS | Corrected B4/B6/B7 citations grep as described above. The remaining B1–B3, B5 and B8 citations also substantiate their cells on the working tree. |
| Industry baseline — B3–B7 upstream consistency | PASS | B3–B7 match the Round-8 sources: optional tax identity, ERPNext contact-fetched communication fields, numeric credit controls, preferred language, and normalization without guaranteed deduplication. |
| Industry baseline — Sources line | PASS | `**Sources**` is present at `brief:99`, with `[S1]`–`[S11]` through `brief:112`. |
| Second-of-everything — second company | FAIL | The test is meaningfully specified at `brief:126-137`, but its POS-isolation evidence cites `PosCustomerSyncController.php:59`. Line 59 only maps resources; the actual company predicate is `PosCustomerSyncController.php:44`. |
| Second-of-everything — second location | PASS | `brief:138-141` states the verified non-applicability; fresh grep for `location_id\|location_code` across partner migrations and `Partner.php` returned zero hits. |
| Second-of-everything — re-run | PASS | `brief:142-146` names Migration A, Migration B, and Parties-import reruns. Migration no-op/partial-state contracts appear at `brief:405-410` and `:777-780`; the import rerun contract explicitly requires no duplicate partner or multiplied warnings. |
| Concepts | PASS | `brief:159-170` lists every noun. Party and Contact resolve at `docs/glossary.md:26,29`; Nature, Legal form, Preferred locale, Credit account, and normalized Contact point are marked NEW with an M1 glossary task. |

## NEW findings

| Finding | Severity | Defect and required correction |
|---|---|---|
| N-39 | MAJOR | `brief:137` cites `PosCustomerSyncController.php:59` as proof that the POS mirror is company-scoped, but that line only maps the result resource. Repin this check-6 evidence to `apps/api/app/Modules/POS/Presentation/Controllers/PosCustomerSyncController.php:42-45`—specifically the `company_id` predicate at `:44`. |

VERDICT: CHANGES-REQUIRED
