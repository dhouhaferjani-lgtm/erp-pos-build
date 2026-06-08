# Opus Adversarial Review — Task 04: Country tax-identity config + reader

**Scope reviewed:** `/tmp/branch-tax-id-task-04.diff` (3 files)
- `apps/api/config/tax_identity.php` (new)
- `apps/api/app/Modules/Company/Application/Services/CountryTaxIdentityConfig.php` (new)
- `apps/api/tests/Unit/Company/CountryTaxIdentityConfigTest.php` (new)

**Checked against:** plan `2026-06-04-branch-tax-id-P0.md` Task 4 · spec `2026-06-04-branch-tax-id-design.md` (rev 2) · `apps/erp/CLAUDE.md`.

---

## Summary

Clean, minimal, additive. The config table is byte-faithful to the spec's initial country mapping (spec §6 line 103), the reader is typed and PHPStan-L8-shaped, and the TDD test drives the only method consumers actually use (`isBranchTaxIdRequired`). No fiscal-payload surface is touched (config-only, Phase 1 inert). No `app()`, no `mixed`, no `any`, no frontend strings/colors in scope.

---

## Verification performed

- **Spec parity (§6 line 103, §5 4-mode set):** `Initial: FR/TN/MA structural + required; DZ separate-linked optional; IT/ES/DE/UAE/UK none; EG/KSA branch-code (deferred)`. Config matches exactly:
  - FR/TN/MA → `structural` + `required:true` ✓
  - DZ → `separate-linked` + `false` ✓
  - IT/ES/DE/AE(UAE)/GB(UK) → `none` + `false` ✓
  - SA(KSA)/EG → `branch-code` + `false` ✓
  - `default` → `none` + `false` ✓ (matches plan "default is none/not-required")
  - All `mode` values are within the Doc 05 §5 four-mode set {structural, separate-linked, branch-code, none}. ✓
- **Usage sweep:** `grep` confirms `CountryTaxIdentityConfig` is referenced only by this new test at the current tip; `isBranchTaxIdRequired` is the method later tasks (5/6 request validation) consume. `mode()` has **zero** callers anywhere in `apps/api`.
- **DI / conventions:** uses the `config()` helper (not `app()`). The plan explicitly accepts `new`-ing this stateless reader inside FormRequests (which aren't constructor-injectable). Consistent with CLAUDE.md rule 13 intent (the prohibition targets `app()` service-location, not `config()`).
- **PHPStan L8 shape:** `forCountry()` carries `@var` annotations on both `config()` reads and the documented `@return array{mode:string, branch_tax_id_required:bool}`; the array-shape accesses return correctly-typed `bool`/`string`, so dropping the plan's `(bool)`/`(string)` casts is type-safe, not a regression.

---

## Findings

### BLOCKER
None.

### MAJOR
None.

### MINOR

**M1 — `mode()` is unused and untested production code.**
`CountryTaxIdentityConfig.php:14-17` exposes `public function mode()`, but no test exercises it and no caller exists in `apps/api` (confirmed by grep at this tip). The TDD test only drives `isBranchTaxIdRequired`. This is a (small) TDD gap: a public method shipped without a failing test driving it, and currently dead. The plan itself specified `mode()`, so this is faithful to the approved plan — but adversarially it's untested surface.
*Resolution options (non-blocking):* (a) add a one-line `assertSame('structural', $config->mode('FR'))` / `assertSame('none', $config->mode('XX'))` test, or (b) drop `mode()` until a consumer needs it (later P0/P1 mode-aware sites). Recommend (a) — `mode()` is the natural reader API the §5 mode-consumers will use, so cover it rather than delete.

### NIT

**N1 — Casts dropped vs plan (`CountryTaxIdentityConfig.php:9,14`).** Plan wrote `(bool)`/`(string)` casts on the returns; implementation omits them. This is *cleaner*, not wrong — the `array{...}` shape already guarantees the scalar types under L8. No action needed; noted only as a plan-vs-impl delta.

**N2 — No `mode()`/`branch-code`/`separate-linked` applicability test.** Spec §"Testing" calls for "per country-mode applicability" coverage; the shipped test covers required/not-required only. Acceptable for this task slice (applicability gating lands in the request-layer tasks 5/6), but worth ensuring those tasks add it. Tracking note, not a Task-04 defect.

---

## Cross-cutting checks (all clear)

- **Fiscal payload schema/version drift:** none — config + reader only; no canonical SALE_RECEIPT/event bytes touched. Phase 1 inert as designed.
- **Branch-vs-company fallback bugs:** N/A to this task (fallback lives in `TaxIdentityResolver`, Task 9).
- **i18n `t()` keys / hardcoded Tailwind:** N/A — no frontend in diff.
- **`app()` / `mixed` / `any`:** none.
- **Additive & reversible:** new files only; no edits to existing behavior.

---

## Conclusion

Implementation is correct, complete, and matches the approved plan and spec verbatim. The sole substantive note (M1: untested/unused `mode()`) is minor and was inherited from the plan; recommend adding a one-line test for `mode()` either here or in the task that first consumes it. Not blocking the next task.

VERDICT: APPROVE-WITH-EDITS
