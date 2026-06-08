# Opus Adversarial Review — Branch Tax-ID P0, Task 02

**Task:** `Location` model — fillable + casts + docblock for per-branch tax-identity fields
**Commit reviewed:** `8c1ce606f` (`feat(branch-tax-id): Location model fillable/casts/docblock for tax fields`)
**Diff source:** `/tmp/branch-tax-id-task-02.diff` (inaccessible — sandbox-blocked; reviewed via `git show 8c1ce606f`, identical content)
**Spec:** `docs/superpowers/specs/2026-06-04-branch-tax-id-design.md` (rev 2), §4
**Plan:** `docs/superpowers/plans/2026-06-04-branch-tax-id-P0.md`, Task 2
**Reviewer stance:** adversarial. Claims verified against real code in `apps/api`.

---

## Scope of the diff

Two files, +65 lines, no deletions:

1. `app/Modules/Company/Domain/Location.php` — 3 docblock `@property` lines, 3 `$fillable` entries, 1 `casts()` entry.
2. `tests/Feature/Location/LocationTaxFieldsModelTest.php` — new persistence/cast round-trip test.

No other files touched. **No scope creep** (Agent Rule #4 satisfied).

---

## Verification against spec + plan

| Plan/spec requirement | Implementation | Verdict |
|---|---|---|
| `legal_identifiers` cast ⇒ `array` (§4, D2) | `'legal_identifiers' => 'array'` at `Location.php:110` | ✅ |
| Fillable adds `tax_id`, `vat_number`, `legal_identifiers` after `address_country` | `Location.php:84-86` | ✅ exact order |
| Docblock `@property` types | `string|null` / `string|null` / `array<string, mixed>|null` at `:33-35` | ✅ |
| Per-field nullable = inherit company | docblock semantics match D2/D3 | ✅ |
| Parity with `Company` tax-identity shape | `Company.php` casts `legal_identifiers => 'array'` (`:274`), same fillable triplet (`:195-198`) | ✅ matches |
| TDD: failing test first, then model change | test + change in one commit; assertion on `fresh()->legal_identifiers` round-trip genuinely fails without the `array` cast (would return JSON string) | ✅ meaningful red→green |

**Cast choice is correct and load-bearing.** The test asserts `assertSame(['siret' => '73282932000074'], $fresh?->legal_identifiers)` on a model reloaded from the DB. Without the `'array'` cast, jsonb round-trips as a `string` and `assertSame` (strict) fails — so the test actually exercises the cast, not just mass-assignment. This is correct TDD.

**Docblock nullability is correctly stricter than `Company`.** `Company::$legal_identifiers` is documented non-null (`array<string, mixed>`); `Location` documents it `|null` because the override column is nullable (null ⇒ inherit). Intentional and correct per spec §4.

---

## Adversarial checks (all negative — nothing found)

- **`app()` helper / DI violation (Rule #13):** none — pure model metadata change.
- **`mixed` PHP type:** none. `array<string, mixed>` is a generic array value-type annotation in a docblock, not a `mixed` parameter/return type — allowed and matches the `Company` convention.
- **PHPStan L8 risk:** `casts()` still returns `array<string, string>` (all values, incl. `'array'` and `LocationType::class`, are strings) — return-type docblock at `:99` stays valid. No new error surface.
- **Fiscal payload schema/version drift:** N/A — this task touches no fiscal/canonical payload. Correct: the seller-block sourcing change lands in later tasks (9–11 / Phase 2), and the spec (§7, D6) mandates no `event_version` bump.
- **Branch-vs-company fallback bug:** N/A — fallback logic is Task 9 (`TaxIdentityResolver`); this task only adds storage.
- **i18n `t()` keys / hardcoded Tailwind colors:** N/A — backend-only, no frontend/UI surface.
- **Enum-for-type-columns (Rule #9):** N/A — no status/type column added; `legal_identifiers` is free-form jsonb by design.
- **Test conventions:** test uses `RefreshDatabase` + real Eloquent models, no faked responses — compliant. It correctly omits `RolesAndPermissionsSeeder`/`actingAs` because it is a model-layer persistence test with no auth/permission surface (the canonical `LocationTest` seeds permissions only because it hits the HTTP API). Appropriate scoping.
- **Strict types:** file already has `declare(strict_types=1)`; test file declares it too (`:3`).

---

## Notes (non-blocking)

- **NIT:** The plan's suggested docblock used the `⇒` glyph ("null ⇒ inherit company"); the implementation used plain ASCII "null means inherit company". This is an improvement (no non-ASCII in source docblocks) — not a finding.
- **Observation:** Test could not be executed in this review session (test-runner Bash calls were permission-denied). The change is verified statically; the cast assertion logic and prior Task 1 migration (columns exist on `locations`, commit `bccf8da5c`) make a green result the expected outcome. Recommend the executor confirm `php artisan test tests/Feature/Location/LocationTaxFieldsModelTest.php` is green before Task 3, per the plan's Step 4 (standard gate, not a defect in this diff).

---

## Classification summary

- **BLOCKER:** 0
- **MAJOR:** 0
- **MINOR:** 0
- **NIT:** 1 (docblock glyph → ASCII; already the better choice)

Clean, minimal, spec-faithful, parity-correct with `Company`, proper TDD. Matches the Task 1 column shapes and casts. Nothing to rework.

VERDICT: APPROVE
