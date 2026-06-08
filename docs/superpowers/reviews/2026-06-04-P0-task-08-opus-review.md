# Opus Adversarial Review — Branch Tax-ID P0, Task 08

**Task:** `LocationResource` — expose `tax_id` / `vat_number` / `legal_identifiers`
**Commit:** `836e43c8` `feat(branch-tax-id): expose tax fields in LocationResource`
**Diff:** `/tmp/branch-tax-id-task-08.diff` (matches the working tree at review time)
**Reviewed against:** plan `2026-06-04-branch-tax-id-P0.md` (Task 8), spec `2026-06-04-branch-tax-id-design.md`, `apps/erp/CLAUDE.md`

---

## Scope of change

Two files, additive only:

1. `apps/api/app/Modules/Company/Presentation/Resources/LocationResource.php` (+3) — three keys added to `toArray()` immediately after `address_country`.
2. `apps/api/tests/Feature/Location/CreateLocationTaxPersistenceTest.php` (+19) — `test_resource_exposes_all_tax_fields()` appended.

This is exactly the file set and placement the plan's Task 8 specifies.

---

## Verification performed

- **Resource keys present & ordered** (`LocationResource.php:35-37`): `tax_id`, `vat_number`, `legal_identifiers` inserted after `address_country` (`:34`) and before `is_default` (`:38`) — matches plan Step 3 verbatim.
- **Model backing exists** (`Location.php`): docblock `@property` (`:33-35`), `$fillable` entries (`:84-86`), and `'legal_identifiers' => 'array'` cast (`:110`) were landed in Task 2. `@mixin Location` on the resource makes `$this->tax_id` etc. statically typed for PHPStan L8 — no magic-attribute / `mixed` leak.
- **`legal_identifiers` serializes as object**: the `array` cast guarantees `$this->legal_identifiers` is `array<string,mixed>|null`, so `assertJsonPath('data.legal_identifiers.siret', ...)` is sound. Null branches (inherit-from-company) serialize to JSON `null` cleanly.
- **Test genuinely covers the change**: `assertJsonPath('data.tax_id', ...)` fails if the resource omits the key, so the test would have been red before the resource edit (TDD red→green satisfied). It reuses the established `RolesAndPermissionsSeeder` + Sanctum + `X-Company-Id` setup from the same file — consistent with the canonical `LocationTest` pattern required by the plan.

## Adversarial checks (all clear)

- **`app()` / constructor-injection rule:** no `app()` use introduced; a `JsonResource` has no constructor deps. ✅
- **`mixed` / `any`:** none. `toArray` return type is the existing `array<string, mixed>` docblock; no new loose typing. ✅
- **i18n `t()` keys:** N/A — backend API resource, no user-facing strings. ✅
- **Hardcoded Tailwind colors:** N/A — no `.tsx`. ✅
- **Fiscal payload schema/version drift:** **None.** This resource is the *admin/config* read shape for a location row; it exposes the **raw override** values, not a resolved fiscal seller identity. It does not touch the signed canonical `SALE_RECEIPT` bytes, FacturX, or NF525. No version bump implied or needed — consistent with the spec's "Phase 1 is fiscally inert" invariant.
- **Branch-vs-company fallback bug:** **Correctly absent.** Exposing the raw nullable column (null ⇒ inherit) is the right behavior here; resolution/fallback belongs to `TaxIdentityResolver` (Tasks 9–12), not the CRUD resource. Surfacing a resolved value here would actually be a bug (it would hide whether the branch has an override). ✅
- **Information exposure:** `legal_identifiers` / `vat_number` / `tax_id` are seller establishment identifiers already returned at company level and authored by the same authenticated admin; endpoint is behind `auth:sanctum` + company scoping. No new sensitivity. ✅
- **Index vs. show parity:** `LocationResource` is the single serializer for both list and show, so the new fields appear consistently across all location reads — no asymmetric shape.

## Findings

| Severity | File:line | Finding |
|----------|-----------|---------|
| — | — | No BLOCKER, MAJOR, MINOR, or NIT findings. |

Optional (not required): a focused assertion that an *un-overridden* location serializes `tax_id: null` would document the inherit-sentinel contract, but `test_store_persists_tax_fields` + the resolver tests already cover the null path elsewhere. Not worth a change.

## Conclusion

Minimal, additive, correctly placed, model-backed, and test-covered. Matches the plan's Task 8 step-for-step, respects the fiscally-inert Phase 1 boundary, and introduces no convention violations.

VERDICT: APPROVE
