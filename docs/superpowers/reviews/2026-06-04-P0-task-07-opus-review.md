# Opus Adversarial Review — Task 07: `LocationController::store()` whitelist tax fields

**Plan:** `docs/superpowers/plans/2026-06-04-branch-tax-id-P0.md` (Task 7)
**Spec:** `docs/superpowers/specs/2026-06-04-branch-tax-id-design.md` (rev 2)
**Commit reviewed:** `65a9b3d69` — "feat(branch-tax-id): persist tax fields in LocationController::store"
**Reviewer:** Opus (adversarial) · **Date:** 2026-06-05

---

## Scope of the diff

Two files, +108 lines:

1. `app/Modules/Company/Presentation/Controllers/LocationController.php` (+3): adds `tax_id`, `vat_number`, `legal_identifiers` to the explicit `Location::create([...])` whitelist in `store()` (`:106-108`).
2. `tests/Feature/Location/CreateLocationTaxPersistenceTest.php` (new, +105): `test_store_persists_tax_fields` — posts a shop branch with all three tax fields, asserts 201 and DB persistence.

---

## Verification against real code

- **Controller change is correct and complete.** `store()` does NOT mass-apply `$validated`; it builds an explicit array (`LocationController.php:95-112`). The three added lines (`:106-108`) follow the exact `$validated['x'] ?? null` idiom of the surrounding sibling fields. Without them the fields would silently persist as null — so the change is load-bearing.
- **Whitelist chain is intact.** `CreateLocationRequest` rules include `tax_id` (`:38`), `vat_number` (`:49`), `legal_identifiers` (`:50`), so `$request->validated()` actually carries the values into the controller. Had any been missing from the rules, the controller's `?? null` would have masked a silent drop — this is the classic trap, and it is NOT present here.
- **Model layer ready (Task 2).** `Location::$fillable` includes all three (`Location.php:84-86`) and `casts()` has `'legal_identifiers' => 'array'` (`:110`), so the array round-trips. The test's `assertSame(['siret' => '73282932000074'], $location->legal_identifiers)` exercises the cast correctly.
- **Spec line-reference match.** Spec §4 specifies the `LocationController::store` whitelist at `:95-108`; the implementation lands precisely there. Column names (`tax_id`/`vat_number`/`legal_identifiers`) are byte-identical to spec §4 table (`design.md:50-52`).
- **`update()` correctly untouched.** `update()` mass-applies `$validated` (`:155`), so it picks up the new fields via fillable with no edit — matching the plan's "no change needed there" note. No double-handling.

## TDD discipline

- **Genuine red→green.** Because `store()` uses an explicit whitelist, the committed test fails before lines `:106-108` exist (`tax_id` resolves to null → `assertSame` throws). Confirmed by static reasoning over the control flow.
- **Correct cross-task decoupling.** The plan's Task 7 draft test included a `data.tax_id` JSON-path assertion that depends on Task 8 (`LocationResource`). The implementer correctly **omitted** the resource assertion and asserts DB persistence only, keeping the committed state green without coupling to an unimplemented task. Good judgment — this is the right call, not a gap.
- **Setup mirrors the canonical fixture.** `setUp()` is an exact structural copy of `LocationTest::setUp()` (tenant → FR company → `RolesAndPermissionsSeeder` → admin user → `UserCompanyMembership`), and the request uses `actingAs($user, 'sanctum')` + `X-Company-Id` header — the established convention. Real models + `RefreshDatabase`, zero mocks. Compliant with the project's test conventions.

## Adversarial checks (all negative — no findings)

- **app() / mixed / any:** Production diff has none. The test's `app(PermissionRegistrar::class)` is the sanctioned test-only pattern (identical to `LocationTest`); the CLAUDE.md constructor-injection rule governs production code, not test bootstrapping. Not a violation.
- **i18n t() keys / hardcoded Tailwind colors:** N/A — backend-only diff.
- **Fiscal payload schema / version drift:** None. Task 7 touches only the location persistence path; it does not build or alter any signed `SALE_RECEIPT` / FacturX / NF525 payload. Fiscally inert, as the plan intends for Phase 1.
- **Branch-vs-company fallback bug:** N/A — no resolver/fallback logic in this task; this is pure entry-side persistence. (Fallback lives in Task 9 `TaxIdentityResolver`.)
- **Conditional-required interaction (Task 5):** The test posts `type=shop`, `address_country=FR`, `tax_id` present → satisfies the `branch_tax_id_required` gate, so 201 is the correct expectation. Consistent.
- **Mass-assignment safety:** `legal_identifiers` is a controlled JSON column; no injection/over-posting concern beyond the existing fillable surface.

## Minor / NIT (non-blocking, no action required)

- **NIT** — `CreateLocationTaxPersistenceTest.php:31-85`: the ~55-line `setUp()` is duplicated verbatim from `LocationTest`/`CreateLocationTaxValidationTest` rather than sharing a base. This matches the existing per-file convention in `tests/Feature/Location/`, so it is consistent, not a regression. Extracting a shared `LocationTestCase` base is a codebase-wide cleanup, out of scope for this task.
- **NIT** — the test does not assert null-inheritance persistence (omitting the fields stores null). Coverage for that path is implicit in other tasks; not required here.

## Verdict rationale

The diff is minimal, surgically scoped, line-accurate to the spec, follows the established controller idiom and test conventions, and ships a genuine red→green test with correct cross-task decoupling. No BLOCKER / MAJOR / MINOR findings; two harmless NITs. Test was not executed in-session (DB-backed run requires approval unavailable to the reviewer), but the full persistence chain (request rules → controller whitelist → fillable → cast → migration) is verified at the code level and is internally consistent.

---

**VERDICT: APPROVE**
