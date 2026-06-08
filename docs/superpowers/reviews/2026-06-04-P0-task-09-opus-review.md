# Opus Adversarial Review — Task 09: `TaxIdentityResolver` + `TaxIdentityData` DTO

**Plan:** `docs/superpowers/plans/2026-06-04-branch-tax-id-P0.md` (Task 9)
**Spec:** `docs/superpowers/specs/2026-06-04-branch-tax-id-design.md`
**Reviewed artifact:** commit `0e92f202f` (HEAD) — "feat(branch-tax-id): TaxIdentityResolver + TaxIdentityData DTO".
**Note on source:** `/tmp/branch-tax-id-task-09.diff` is outside the session's allowed working directory and could not be read by any tool (cat/cp/python/Read all blocked). I reviewed the committed Task 09 diff (`git show 0e92f202f`) instead; its three files exactly match the plan's Task 9 file list, so this is the right change set.

**Files in scope:**
- `apps/api/app/Shared/Contracts/Company/TaxIdentityData.php` (new)
- `apps/api/app/Modules/Company/Application/Services/TaxIdentityResolver.php` (new)
- `apps/api/tests/Feature/Company/TaxIdentityResolverTest.php` (new)

**Verification caveat:** `phpunit` and `phpstan` invocations were blocked by the harness approval gate in this session, so I could not execute them. PHPStan/PHPUnit conclusions below are from static reading + config inspection, and are flagged as such.

---

## Spec / plan conformance

The resolver matches the spec contract (spec §60–66) precisely:

| Contract | Implementation | OK |
|---|---|---|
| `taxId = location.tax_id ?? company.tax_id` | `taxId: $location->tax_id ?? $company->tax_id` | ✓ |
| `vatNumber = location.vat_number ?? company.vat_number` | ✓ | ✓ |
| `countryCode = location.address_country ?? company.country_code` | ✓ (spec §66) | ✓ |
| `legalIdentifiers[]` per-field fallback | `array_merge(company, location)` — per-key override | see MINOR-1 |
| DTO shape `{taxId, vatNumber, legalIdentifiers, countryCode}` | identical (plan §1446 type-consistency) | ✓ |

**Fiscally inert — confirmed.** Task 09 only introduces the resolver + DTO. It does **not** build or touch the canonical signed `seller` block (spec §88/§112: that stays device-authored, 4-key shape unchanged). No fiscal payload schema/version drift. ✓

**CLAUDE.md rule compliance:**
- No `app()` helper; the service has no dependencies so `new TaxIdentityResolver` is appropriate (rule 13 N/A). ✓
- **No `mixed`** — the implementation deliberately *improved* on the plan, which used `@param array<string, mixed>`; the shipped DTO/helper use `array<string, string|int|float|bool|null>` instead (rule 3). ✓
- Pure backend (DTO/service/test): no `t()` keys, no Tailwind classes, no TS `any` — i18n / design-token / `any` rules N/A. ✓
- Strict types declared in all three files. ✓

**Test coverage exceeds the plan:** the plan specified 2 tests; the implementation adds a 3rd, `test_location_country_overrides_company_country`, satisfying the spec §132 "country-code resolution" case. Good.

---

## Findings

### MAJOR-1 — Plan's `?->` null-safety dropped; reachable NPE because `Company` is soft-deletable
`app/Modules/Company/Application/Services/TaxIdentityResolver.php:14-26`

The approved plan (plan lines 921-937) guards every company access with the null-safe operator:
```php
taxId: $location->tax_id ?? $company?->tax_id,
vatNumber: $location->vat_number ?? $company?->vat_number,
...
countryCode: $location->address_country ?? $company?->country_code,
```
The implementation silently removed it:
```php
$company = $location->company;
return new TaxIdentityData(
    taxId: $location->tax_id ?? $company->tax_id,      // $company-> , not $company?->
    vatNumber: $location->vat_number ?? $company->vat_number,
    ...
    countryCode: $location->address_country ?? $company->country_code,
);
```

This is not academic: **`Company` uses `SoftDeletes`** (`app/Modules/Company/Domain/Company.php:115`). A `belongsTo` relation to a soft-deleted parent resolves to `null` (the soft-delete scope excludes trashed parents unless `withTrashed()`). So for a `Location` whose parent company has been soft-deleted, `$location->company === null` and every `$company->...` access throws `Attempt to read property on null`.

The resolver sits on the path the spec says feeds **"every output that prints or signs"** the seller identity (ReceiptPdfService, FacturXService, etc. — Tasks 10/11). A trashed-company edge would therefore crash receipt/FacturX generation rather than degrade.

PHPStan will **not** catch this, because `Location`'s docblock declares `@property-read Company $company` (non-null) — an annotation that is itself optimistic given SoftDeletes. So the model contract masks a genuinely nullable runtime value.

**Required edit:** restore the plan's `?->` (matching the approved design), *or* add an explicit fail-loud guard (`if ($company === null) throw …`) if the team prefers crash-loud over silent-null. Either is acceptable; the current state — relying on a docblock the SoftDeletes trait contradicts — is not. Add a regression test for the trashed-company branch.

### MINOR-1 — `legal_identifiers` uses per-key merge, not whole-field `??`; partial-key inheritance is untested
`TaxIdentityResolver.php:18-21`

Scalar fields use whole-value `??`. `legal_identifiers` instead uses `array_merge($companyLegal, $locationLegal)`, i.e. **per-key** override with inheritance of company keys the branch omits. This is a defensible (arguably better) reading and it matches the plan's reference code + comment ("location keys override company keys; missing keys inherit"), so it is plan-sanctioned — but it diverges from a literal reading of spec D2 (`location.X ?? company.X`, which for the whole field would *replace* the array). Example divergence: company `{siret, rcs}`, branch `{siret}` → merge keeps `rcs`; whole-field `??` would drop it.

The two committed tests do not distinguish the semantics (both pass under merge *or* replace). Spec §132 explicitly lists "partial override" as a required resolver test; it is covered for scalar fields but **not** for `legal_identifiers` keys. Add a test where the branch sets one identifier key and inherits another, to lock the merge behaviour in.

### NIT-1 — `??` lets an empty string override the company value
`TaxIdentityResolver.php:16-17`. A branch `tax_id = ''` (empty, not null) would override the company tax id with `''`. Entry validation (Tasks 04/05) and the nullable migration default make this unlikely, and `??` is exactly what the spec specifies, so this is informational only.

### NIT-2 — `array_merge` assumes string keys
`TaxIdentityResolver.php:19`. `legal_identifiers` keys are country identifier names (siret/rcs/…), so string-keyed; `array_merge` therefore overrides rather than reindexes. Correct under the data contract — noted only because numeric keys would reindex.

---

## Things explicitly checked and found clean
- **No fiscal schema/version drift** — resolver does not emit the signed `seller` block (spec §88/§112).
- **PHPStan L8 (static reasoning):** `checkExplicitMixed` is **not** enabled (`phpstan.neon` + larastan extension + baseline all lack it), so feeding model `array<string,mixed>` into the helper's narrower `array<string, string|int|float|bool|null>|null` param is permitted — no error expected. Downstream Task 10 passing `$company->legal_identifiers` to the narrowed DTO is likewise fine. (Not executed — approval gate.)
- **DTO type consistency** across Tasks 9/10/11 (plan §1446) — field names/types align.
- **No `mixed`, no `app()`, no `any`, no Tailwind, no `t()` keys** — backend-only change.
- **TDD:** test file is comprehensive and committed with the implementation; structure follows the plan's red/green (intermediate red state not observable from a single squashed commit, which is normal for this workflow).

---

## Verdict rationale
Happy-path logic is correct and matches the spec contract exactly; the change is fiscally inert with no payload drift and improves on the plan by eliminating `mixed`. The one substantive issue (MAJOR-1) is a small, unexplained regression away from the approved plan that becomes a reachable null-dereference because the parent `Company` is soft-deletable — on a path the spec routes every tax-identity output through. It is a trivial fix (one operator / a guard + one test). That, plus the untested `legal_identifiers` partial-inherit semantics, warrants edits before this is built upon by Tasks 10/11.

VERDICT: APPROVE-WITH-EDITS
