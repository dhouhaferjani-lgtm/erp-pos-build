# Bank Directory phases 1–3 (Track 2) — Final Whole-Branch Review (orchestrated multi-agent)

> **Date:** 2026-07-12 · **Branch:** `feat/bank-reference-verification` @ `562e979e6` + fix-wave `d6b4c7841` + deploy-doc `2eca57731` (9→11 commits, 57→59 files, base +3,471/−112, based on origin/dev `1223dcc37`)
> **Protocol (Phase-② pattern, per `HANDOFF-codex-tracks-final-review-2026-07-12.md`):** Fable orchestrator ONLY; 2 Sonnet discovery lanes + 5 verification lanes — 4 Opus + **1 Fable 5 on the mod-97 validator math** (owner tiering: crucial financial spine). Above Codex's autonomous gates (bank-gate-1/2/3) and owns the merge decision.
> **VERDICT: APPROVE-WITH-FIXES — fixes applied (`d6b4c7841`, `2eca57731`), touched lanes re-verified, merged.**

## Lane verdicts

| Lane | Scope | Verdict |
|---|---|---|
| D1 (Sonnet) | 57-file diff map, scope drift, rebase story | **CLEAN** — PaymentInstrument carve-out verified by path filter AND content grep; design-doc commit's direct parent IS the merge-base (rebase genuine); all −112 deletions in-brief; module boundary grep-clean; pickers.json en/fr/ar 3-way parity |
| D2 (Sonnet) | Gate trail, honesty | **HONEST** — every reproducible claim re-run and matched exactly (incl. Codex's 64/232 = the combined Partner regression slice); Gate-3 FK blocker real, fix verified by commit diff, rc2 genuinely re-reviewed; Gate-1 post-approval rebase patch-id-identical; "no Fable escalation" was a correct application of the rule. LOWs: dangling `bank-gate-1-rc1` tag; exit report restated a hedged "4 commits drift" (actually 6) without its caveat |
| V1 (**Fable 5**) | mod-97 validator math, both layers | **Integer safety CLEAN (kill criterion)** — all arithmetic bcmath-on-strings / chunked-mod ≤969, no int/float path, leading zeros preserved; worked vector re-derived by hand (clé 53, check 59, self-check mod=1) + 5k-vector BigInt parity fuzz on the FE mirror. **Found 2 MEDIUM Codex's gates missed:** (1) ISO-forbidden IBAN check digits 00/01/99 accepted on both layers (proven alias vectors); (2) no FE test file for the validator hook |
| V2 (Opus, treasury-reviewer) | Boundaries, warn-but-allow, middleware, spine | **APPROVE** — Partner→Shared contract only (interface injection, `ScopedExists::tenant('banks')`, zero new Treasury imports); checksum NEVER 422s (proven by tests asserting 201 with invalid values persisted); `GET /banks` tuple exact, no `can:`/`module:` gate; PaymentRepository spine byte-clean beyond nullable `bank_id` FK (nullOnDelete). 3 Minor |
| V3 (Opus) | Migrations, seeder, deploy | **FINDINGS** — code correct (all 3 migrations guarded — more defensive than the 2026_07_08 precedent; Gate-3 FK fix regression-pinned; seeder idempotent, custom banks survive; TN.json 32 canonical banks clean; seedBanks ordered before seedPaymentRepositories). **HIGH: no existing-tenant seeding step documented anywhere — and the naive `tenants:run db:seed --class=BanksSeeder` SILENTLY NO-OPS** (container class-type resolution injects an empty `Company`, the `?? Company::first()` fallback never fires, early return, zero rows, zero errors — same trap in PaymentRepositorySeeder). MEDIUM: re-seed clobbers admin edits on canonical rows |
| V4 (Opus, frontend-conventions-reviewer) | FE conventions | **APPROVE** — BankPicker tokens-exclusive (new-dir hard rule), ternaries resolve to complete static classes; i18n 3-way parity + namespace wired; tenantScopedKey on data keys / bare filter prefixes; single-unwrap; strings-only account numbers; a11y roles present; generated.d.ts transform-honest. 3 Minor (stale derived IBAN the one that mattered) |
| V5 (Opus) | Fresh runs | **GREEN** — real (non-symlink) vendor resolving worktree code; 36/157 backend across the 5 diff files; phpstan 0 on new code (25 pre-existing hits all in non-diff seeders); pint pass; typescript:transform reproduces committed generated.d.ts byte-exact; FE 10/10; audits 0 new |

## Fix-wave (commit `d6b4c7841` + doc `2eca57731`), re-verified by resumed V1 + V4 lanes

1. **[V3 HIGH]** `docs/handoff/bank-directory-deploy-checklist.md` added (`2eca57731`): existing-tenant backfill in the corrected `runForMultiple` form, explicit bare-`tenants:run` no-op trap warning, re-seed clobber caution, productization ticket.
2. **[V1 MED]** IBAN check digits 00/01/99 rejected on both layers (`invalid_check_digits`, guard before the mod test); the 3 alias vectors + corrupted-check-digit + wrong-length IBAN + spaced-RIB normalization pins added.
3. **[V1 MED]** New `useBankAccountValidation.test.ts` (9 tests) incl. a >2^53 RIB proving no `Number()` precision loss.
4. **[V4 MIN]** `PartnerBankAccountsSection` clears auto-derived IBAN on valid→invalid via `autoDerivedIbanRef` (never clobbers user-typed IBAN) + clearing test.
5. **[V2 MIN]** `PartnerBankAccountData.bic_valid` → `?bool`, null for absent BIC (aligned with the trait + controller paths); generated types regenerated (one line).

Post-fix numbers: BankAccountValidatorTest 19/59, Partner+PaymentRepository feature 21/77, phpstan 0, pint pass, FE 20/20 across 3 files, typecheck clean, lint 0 errors, audits 0 new.

## Follow-up register (non-blocking — ticket, don't block)

1. **Productize the banks backfill as a real artisan command** (bundled with the `accounting:seed-charts` + opening-balance pre-launch ticket); the command must take explicit tenant scoping and fix the `?Company` container-resolution trap. [V3 HIGH → documented, productization owed]
2. Make BanksSeeder preserve admin edits (`is_active`, name/position) on canonical-row update before any repeating deploy hook uses it. [V3 MED]
3. `PartnerData::fromModel` nullable validator param silently yields empty `bank_accounts` for future callers — make it required or throw. [D1 MED]
4. FE should distinguish `unsupported_country` from genuine checksum failure before rendering a red warning (non-TN tenants will see noise; validator is TN-only by design). [V2 MIN]
5. BankPicker: add `aria-activedescendant` for screen-reader announcement of the highlighted option; add a dedicated `BankPicker.test.tsx`. [V4 MIN]
6. Seeder identity falls back to `name` for null-`rib_bank_code` rows — collision risk for future country files (unreachable in TN.json). [V2 MIN]
7. Transformer emits `Array<any>` for the result DTOs' `errors` arrays — add typed-array hints if supported. [D1 LOW]
8. Test-hygiene LOWs: self-referential bcmath expectations partially mitigated by literal pins; leading `-` stripped as grouping char. [V1 LOW]
9. Dangling `bank-gate-1-rc1` tag points outside HEAD ancestry (post-approval rebase; patch-id-verified identical). [D2 LOW]

## Deploy (per `docs/handoff/bank-directory-deploy-checklist.md`, in-branch)

`tenants:migrate --force` (3 guarded migrations) → **existing-tenant banks backfill via the documented `runForMultiple` tinker block (NOT bare `tenants:run`)** → verify 32 banks/tenant + BankPicker populates. No permission changes.

## Post-merge unlock (separate ticket, NOT this review)

Phase-② interlock: instruments adopt `BankPicker` + real FK on `payment_instruments.bank_id` (shipped as plain uuid).
