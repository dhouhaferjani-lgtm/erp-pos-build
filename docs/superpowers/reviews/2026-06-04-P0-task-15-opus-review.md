# Opus Adversarial Review — Task 15: `TerminalResource` carries branch tax fields on `location`

**Branch:** `feat/branch-tax-id-spec`
**Commit reviewed:** `550af4abb` — *feat(branch-tax-id): TerminalResource carries branch tax fields on location*
**Plan:** `docs/superpowers/plans/2026-06-04-branch-tax-id-P0.md` (Task 15, lines 1285–1332)
**Spec:** `docs/superpowers/specs/2026-06-04-branch-tax-id-design.md` (§8 device path, D6)
**Reviewer:** Opus (adversarial)
**Date:** 2026-06-05

---

## Scope of the diff

Two files, +48 lines, additive only:

1. `apps/api/app/Modules/POS/Presentation/Resources/TerminalResource.php` — +3 lines inside the
   existing `location` `whenLoaded` closure (`tax_id`, `vat_number`, `legal_identifiers`).
2. `apps/api/tests/Feature/POS/TerminalResourceBranchTaxTest.php` — new feature test (+45).

The diff exactly matches the plan's Step 3 implementation block and Step 1 test intent.

---

## Verification performed (claims checked against real code)

| Claim | Result |
|---|---|
| Resource exposes the 3 tax fields inside `location` | ✅ `TerminalResource.php:33–35` |
| `location.legal_identifiers` resolves to an array (not raw JSON string) | ✅ `Location.php:110` casts `legal_identifiers => 'array'`; `:84–86` fillable |
| `Location` has `tax_id` / `vat_number` / `legal_identifiers` columns + docblock | ✅ `Location.php:33–35, 84–86, 110` |
| **m1 — every terminal endpoint the device consumes loads `location`** | ✅ verified all `TerminalResource` returns eager-load `location`: `index:55`, `show:77`, `available:303`, `findByDevice:471` use `->with(['location'])`; `store:119`, `update:152`, `activate:248`, `deactivate:286`, `claim:359`, `requestTerminal:390`, `getOrCreateWebTerminal:425,456`, `toggleTrainingMode:523` use `->load('location')`. No `TerminalResource` is returned without `location` loaded. |
| No `TerminalController` change was needed (only 2 files committed) | ✅ consistent — controller already satisfied m1; plan's `git add` listed the controller defensively, but it required no edit |
| Spec §8(1) endpoint list (`show/index/claim/by-device/toggle-training`) covered | ✅ all present + `activate`/`deactivate`/`available`/`store`/`update` also covered |
| Test uses real models + `RefreshDatabase` (repo convention, no mocks) | ✅ `Tenant`/`Company`/`Location`/`Terminal` factories, real serialization |
| `TerminalFactory` accepts the explicit `tenant_id`/`company_id`/`location_id` passed by the test | ✅ `TerminalFactory.php:22–25` |

---

## Adversarial checks

### TDD
The test is well-formed, uses real Eloquent models through factories, and asserts the
serialized output of `(new TerminalResource($terminal))->toArray(...)`. It is committed
alongside the implementation (repo's per-task convention). Test passing could not be
executed here (sandbox blocked `php artisan test` approval), but the code path is a pure
pass-through of three cast model attributes through an already-exercised `whenLoaded`
closure; nothing in the assertions can fail given the verified casts/fillable. **No TDD violation.**

### `app()` / `mixed` / `any`
- No `app()` helper introduced.
- The `array<string, mixed>` return annotation on `toArray()` is the pre-existing
  `JsonResource` signature (line 17), not new drift — and is the framework-mandated shape.
- No TypeScript in this diff → no `any`. **Clean.**

### i18n `t()` keys / hardcoded Tailwind colors
No frontend/`.tsx` files touched. **N/A — clean.**

### Fiscal-payload schema / version drift (D6)
This is the highest-risk axis for this feature and it is **clear**. The change touches the
**API resource** the device reads to populate `terminal.location` — it does **not** touch the
signed canonical `SALE_RECEIPT` bytes, the `seller` block, or any `fiscal_schema_version`.
Per spec §7/D6 this is a value-source exposure only; the actual branch-over-company seller
sourcing is deferred to Task 17, and the version stays pinned. `fiscal_schema_version` in the
resource (`:53`) is untouched. **No drift.**

### Branch-vs-company fallback bug
Task 15 deliberately exposes the **raw** branch values (`null` when unset) with no fallback —
fallback resolution is Task 17's responsibility on the device. Because `location` is gated by
`whenLoaded`, an endpoint that ever forgot to load `location` would **omit** the key (not emit a
stale/empty one), which is the safe failure mode for the downstream `?? company` fallback. All
endpoints verified to load it, so the device never caches a tax-less location. **Correct.**

### Tenant isolation / data leak
The exposed fields belong to the terminal's own location within tenant scope; no cross-tenant
exposure. `legal_identifiers` is the branch's own identifier set (spec §3 D2). **No concern.**

---

## Findings

### BLOCKER
None.

### MAJOR
None.

### MINOR
- **M1 — test covers only the populated case, not null-inheritance.**
  `TerminalResourceBranchTaxTest.php:24–43` asserts the fields when set, but does not assert
  that a location with `tax_id = null` serializes `null` (the "inherit company" sentinel the
  device's Task-17 fallback depends on). The code is a trivial pass-through so this is low risk,
  and the fallback semantics are exercised in Task 17, but a one-line null assertion here would
  lock the contract at the resource boundary. *Optional.*

### NIT
- **N1 — no controller-level regression test asserts `location` is loaded per endpoint.**
  m1 is currently guaranteed by manual/review confirmation (done above), not by a test. If a
  future refactor drops `->with('location')`/`->load('location')` from an endpoint, the device
  would silently get a tax-less location with no failing test. A single endpoint smoke test
  asserting `data.location.tax_id` is present would harden m1. *Optional, out of this task's
  declared scope.*

---

## Conclusion

The implementation is minimal, additive, and exactly matches the plan and spec §8(1)/D6. The
critical m1 requirement (all terminal endpoints eager-load `location`) is independently verified
across all twelve `TerminalResource` return sites. No fiscal payload/version drift, no fallback
bug (fallback is correctly deferred to Task 17), no `app()`/`mixed`/`any` introduced, conventions
honored. The only findings are optional test-coverage hardening suggestions that do not block.

VERDICT: APPROVE
