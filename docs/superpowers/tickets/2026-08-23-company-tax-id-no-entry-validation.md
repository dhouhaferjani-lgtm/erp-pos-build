# Ticket: the seller matricule reaches sealed bytes with no entry validation — plus the TN-convergence lane's other residuals

**Filed:** 2026-08-23, by `fix/p0-tn-matricule-regex-convergence` at the merge gate's request
(`docs/superpowers/reviews/2026-08-23-p0-tn-mf-regex-gate-r2.md` §R2-2 — round 2 found
`CountryTaxNumberRules.php` asserting "tracked as a residual ticket" with no ticket on the
branch; a claim of tracking with nothing tracked is worse than an undisclosed gap, because
it stops the next reader from looking).

**Lane:** TN matricule-fiscale convergence — three incompatible regexes collapsed onto
`App\Shared\Domain\Validation\CountryTaxNumberRules`.
**Authority:** `docs/superpowers/specs/2026-08-23-party-contact-target-model-research.md` §3.3.
**Gates:** `…-gate-r1.md` (F-1…F-8), `…-gate-r2.md` (R2-1…R2-4).

All four items below are **pre-existing** — none is a regression introduced by the lane.
They are recorded because the lane's work put a spotlight on them, and because the lane's
own docblocks now claim a "single source of truth" that these items qualify.

---

## R-1 [P2] — company `tax_id` / `vat_number` become `seller.tax_number` with NO format rule at any entry point

**This is the item the `CountryTaxNumberRules` docblock points at.**

The class docblock claims every entry point that validates a tax number consumes it. True as
far as it goes — but the SELLER identifier never passes an entry rule at all:

| Entry point | Rule today |
|---|---|
| `apps/api/app/Modules/Company/Presentation/Requests/CreateCompanyRequest.php:28` (`tax_id`) | `['nullable','string','max:50']` |
| `apps/api/app/Modules/Company/Presentation/Requests/CreateCompanyRequest.php:30` (`vat_number`) | `['nullable','string','max:50']` |
| `apps/api/app/Modules/Company/Presentation/Requests/UpdateCompanyRequest.php:45` (`tax_id`) | `['nullable','string','max:50']` |
| `apps/api/app/Modules/Company/Presentation/Requests/UpdateCompanyRequest.php:47` (`vat_number`) | `['nullable','string','max:50']` |

(Verified by reading those four lines, gate R1 F-6 and gate R2 §5.)

Meanwhile `seller.tax_number` IS pattern-checked at seal time
(`FiscalPayloadConstraintValidator::assertTaxNumberForCountry()`, called at `:2092`). So a
company whose `tax_id` was typed as `1234567/A/M/000`, `1234567A`, or anything else can be
created and only discovers the problem when a receipt fails to seal — the asymmetry this
whole lane exists to eliminate on the BUYER side.

**Live evidence that malformed seller ids already exist:** `CoffeeShopSeeder.php:283,332`
seeds a TN tenant and company with `tax_id => '1234567A'` (8 characters). That value is
REJECTED by the converged rule (`CountryTaxNumberRules::matches('TN','1234567A') === false`,
executed). It is demo data, not production, but it demonstrates the hole is reachable.

Branch locations, by contrast, DO validate (`CreateLocationRequest` / `UpdateLocationRequest`
consume `CountryTaxNumberRules`), so the gap is specific to the company record.

**Fix shape:** apply `CountryTaxNumberRules::matches()` to company `tax_id` (and decide
whether `vat_number` follows the same rule or a VAT-specific one) in both company requests,
with the same normalize-at-the-boundary treatment the partner requests now have. Needs a data
audit first: existing companies may hold values the rule rejects, so it likely wants the same
grandfather-unchanged treatment used for partners (gate R1 F-2).

## R-2 [P2] — the country fallback is cross-country, and the `FR|IT|GB` entry literals were never audited

Closing the "self-disabling when `country_code` is absent" hole (spec §3.3) required a
fallback to the company country, and that fallback is country-agnostic by construction. So
partners with a null `country_code` in an FR / IT / GB company now get entry enforcement that
was dormant before, using literals this lane did NOT audit to the standard the TN one
received:

- `CreatePartnerRequest::validateVatNumber()` — `FR` `/^FR[0-9A-Z]{2}[0-9]{9}$/`,
  `IT` `/^IT[0-9]{11}$/`, `GB` `/^GB([0-9]{9}|[0-9]{12}|(HA|GD)[0-9]{3})$/`
- same table in `UpdatePartnerRequest::validateVatNumber()`

**Containment already in place** (gate R2 §6, executed): on the UPDATE path the grandfather
clause short-circuits before the country is ever consulted, so no unchanged value is
re-litigated. The CREATE path is uncontained by design — though the web form defaults
`country_code` to the company country (`PartnerForm.tsx:168,220-221`), so at base most creates
were already validated under that same country; the new enforcement bites only when the
operator explicitly clears the field.

**Note for whoever picks this up:** the FR ENTRY arm (`/^FR[0-9A-Z]{2}[0-9]{9}$/`) is
STRICTER than the FR SEAL pattern (`/^([0-9]{9}|[0-9]{14})$/D` plus the buyer intracom
alternative) — i.e. FR diverges in the SAFE direction (never accept-at-entry /
reject-at-seal). IT and GB have not been checked against their seal-side behaviour at all;
GB has no entry in the seal table.

**Fix shape:** run the same convergence exercise per country — for each, prove the entry
predicate is no more permissive than the seal predicate, and collapse duplicated literals
onto `CountryTaxNumberRules`.

## R-3 [P3] — the advisory endpoint applies the *matricule fiscale* pattern to `business_registration_number`

`PartnerController::validateTaxId()` (`PartnerController.php:440-482`) feeds
`business_registration_number` into `TaxIdValidationService::validate()`, whose TN arm now
delegates to the matricule-fiscale rule — i.e. the `vat_number` semantic. In Tunisia the
matricule fiscale and the RNE / registre de commerce are DIFFERENT identifiers.

**Non-blocking, confirmed twice** (gate R1 §5, gate R2 §5): the service has exactly one
consumer, which returns the result in a response body and never rejects a write. Documented
in an ADVISORY-ONLY block at `TaxIdValidationService.php` so it is not mistaken for a
contract. The visible symptom is a UI hint reading "invalid" on a legitimately-stored
registration number.

**Fix shape:** either give the endpoint a per-identifier rule (matricule for `vat_number`,
an RNE rule for `business_registration_number`), or stop routing the registration number
through a tax-number validator.

## R-4 [P3] — entry and seal choose the tax-number COUNTRY by different rules

The lane proves convergence on the **pattern** dimension. It is silent on country SELECTION,
which differs on the two sides:

| | How the country is chosen |
|---|---|
| Entry (partner) | submitted `country_code` -> stored partner `country_code` -> company `country_code` (`UpdatePartnerRequest::resolvedTaxCountryCode()`) |
| Seal (buyer) | `countryCodeFromAddress($buyer['address'])` with a seller fallback (`FiscalPayloadConstraintValidator.php:2131-2133`) |
| Seal (seller) | `seller.tax_jurisdiction_country_code` (`:2092`) |

None of the seal-side inputs is the partner's `country_code`. So a partner whose
`country_code` is TN but whose address country is FR would be pattern-checked as TN at entry
and as FR at seal. The round-trip test pins `'TN'` on both ends and therefore cannot see this
(scoped explicitly in its docblock per gate R2 §R2-3).

**Fix shape:** decide which identifier is authoritative for the buyer country and make the
entry rule consult the same one, or assert the relationship explicitly with a golden-payload
test through the public `validatePerEventConstraints()`.

---

## Not in this ticket

- **`partners.unique(tenant_id, vat_number)` is tenant-wide** while `code` uniqueness was
  narrowed to `(company_id, code)`, so a group running two companies under one tenant cannot
  register the same supplier MF twice. Named in spec §3.3 as a separate defect; untouched by
  this lane.
- **`PartnerReferenceSchemaSweepTest` is red** on `supplier_goods_return_notes.partner_id`
  (a dpa-v8 supplier-returns table). Inherited, reproduced identically with this lane's
  source reverted to base `d80ee2375`; belongs to that lane, not this one.
