# ADVERSARIAL GATE REVIEW — Bank Directory, GATE 3 RC2 (Phase 3: Partner bank accounts)

- **Reviewer:** Claude Opus 4.8 (final track gate, Claude-side review/merge session)
- **Scope:** `git diff bank-gate-2..HEAD` — two commits: `e625efca1 "Phase 3.0.0: Add partner bank accounts"` + `b35a2b3d5 "Phase 3.0.1: Fix tenant bank account migration"` (the RC1 remediation)
- **Contract:** brief §3 ground rules + §5 verification + §6 out-of-scope (`docs/handoff/CODEX-bank-directory-2026-07-10.md`); design doc `2026-06-30-bank-reference-and-account-verification-design.md`
- **Range refs:** `bank-gate-2` = `bbe6137d3`, `HEAD` = `b35a2b3d5`
- **RC1 review:** `docs/handoff/gate-reviews-bank/GATE-3-rc1.md` — verdict CHANGES-REQUIRED (1 BLOCKER + 1 LOW + 2 INFO)

---

## Verdict summary

**RC1's BLOCKER is fully resolved and the two follow-up findings are addressed.** The invalid `tenant_id → tenants` cross-database foreign key is gone; the migration now carries only tenant-DB-local FKs (`partner_id`, `bank_id`, `created_by`) plus a plain indexed `tenant_id`, matching the sibling `banks` and parent `partners` tables. A regression test locks the fix in. The formerly-dead validity recorder is now wired to surface warn-mode validity in response `meta`, and single-primary now guarantees "at least one" as well as "at most one".

Re-hunting the RC2 range against the full §3/§5/§6 contract surfaces **no new BLOCKER or HIGH**. The module boundary — the entire point of this design — remains honored exactly (Partner depends only on `App\Shared\Banking\Contracts\BankAccountValidatorInterface` + shared VOs; zero Treasury-internal or `Bank`-model imports), warn-but-allow is intact (no `$fail` on RIB/IBAN/BIC), constructor injection is used throughout, i18n is complete with EN/FR parity, design tokens are used exclusively, `PaymentInstrument` is untouched, and the worktree is unmerged and unpushed.

Escalation note: the RC1 blocker was migration topology, not validator mod-97 math (Phase 3 does not touch `app/Shared/Banking/**`; RIB/IBAN/BIC flow through it as strings only). Per the brief's escalation rule, Fable re-review is **not** triggered.

---

## RC1 blocker — resolution verification

### RC1 Finding #1 [BLOCKER] — `tenant_id → tenants` FK — ✅ RESOLVED

`apps/api/database/migrations/tenant/2026_07_12_120000_create_partner_bank_accounts_table.php`

- The offending `$table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();` line is **removed** (fix commit `b35a2b3d5`).
- `tenant_id` is now a plain indexed uuid — `$table->uuid('tenant_id')->index();` (line 19) — exactly matching `banks` (Phase 1) and `partners`.
- The three remaining FKs all target tenant-DB tables and are correct: `partner_id → partners` (line 32), `bank_id → banks` nullOnDelete (line 33), `created_by → users` nullOnDelete (line 34).
- Re-run safety retained: `if (Schema::hasTable('partner_bank_accounts')) return;` guard (line 13) + `down()` `dropIfExists` (line 41).
- **Regression lock added:** `PartnerBankAccountTest::test_tenant_migration_does_not_reference_the_central_tenants_table` (lines 252–258) reads the migration source and asserts `assertStringNotContainsString("->on('tenants')", …)`. This directly prevents recurrence and does not depend on `RefreshDatabase` masking the per-tenant reality.

I independently confirmed no other tenant-tier migration in the RC2 range references a central-only table: the diff adds exactly one migration, and it is this (now-fixed) file.

### RC1 Finding #2 [LOW] — dead `recordBankAccountValidity()` — ✅ RESOLVED (improved)

`ValidatesPartnerBankAccounts.php:73–85`, `PartnerController.php:223,304`

The recorder no longer discards validator results. It now accumulates `['rib' => RibValidationResult, 'iban' => IbanValidationResult, 'bic_valid' => ?bool]` into `$recordedBankAccountValidity`, exposed via `bankAccountValidity()` and surfaced in both `store` and `update` under `meta.bank_account_validation`. The VOs serialize cleanly (`RibValidationResult`/`IbanValidationResult` extend Spatie `Data` → `JsonSerializable`, public props), and `test_creates_partner_accounts_and_records_warn_mode_validity` now asserts `meta.bank_account_validation.1.rib.valid`. It remains warn-only (never calls `$fail`), so warn-but-allow is preserved.

### RC1 Finding #3 [INFO] — single-primary was "at most one", never "at least one" — ✅ ADDRESSED

`PartnerBankAccountService.php:25–43`

The service pre-scans for `$hasRequestedPrimary`, then assigns primary as
`! $primaryAssigned && ($input->is_primary || (! $hasRequestedPrimary && $retainedIds === []))`.
This preserves "at most one" (guarded by `$primaryAssigned`) and now guarantees "at least one" for any non-empty collection where the client flags none — the first row is defaulted primary. Verified against create, update, and legacy-partial-payload paths; no double-primary drift is possible because non-retained rows are deleted and retained rows have `is_primary` overwritten each sync. New assertion: a lone account submitted with `is_primary=false` returns `is_primary=true`.

---

## Findings (RC2, severity-ordered)

### 1. [LOW] `meta.bank_account_validation` derives country from the partner request field, while the authoritative `data` validity uses the company country

`ValidatesPartnerBankAccounts.php:75` vs `PartnerBankAccountData::fromModel()` (`PartnerBankAccountData.php:49`, called with `$company->country_code` in `PartnerController.php:164,220,301`).

The request-time `meta` channel resolves country as `strtoupper((string) ($this->input('country_code') ?: 'TN'))` — the partner's own (nullable) `country_code` field, falling back to `TN`. The authoritative per-account validity in the response **body** (`data.bank_accounts.*.rib_validation`) is computed against the **company's** `country_code`. These are two different country sources and can diverge (e.g. a TN company creating a partner whose `country_code` is set to something else). Note this corrects RC1's incidental claim that `country_code` "is not a partner request field" — it *is* one (`CreatePartnerRequest.php:56`, `nullable|string|size:2`).

Impact today is nil: FR/other-country validation is not shipped (validator is TN-only this pass), so both sources resolve to TN in practice, and the channel is metadata that never blocks the save. Recommend (post-gate, not blocking) either dropping the `meta` channel or feeding it the same `$company->country_code` the body uses, so the two validity views cannot disagree once a second country lands.

### 2. [INFO] `currency` is a hard `required_with` rule (structural, not a checksum)

`ValidatesPartnerBankAccounts.php:32` — `'bank_accounts.*.currency' => ['required_with:bank_accounts', 'string', 'size:3']`. A row without a 3-char currency yields 422. This is a **structural** field requirement, not an identifier-checksum rejection, so it does not violate warn-but-allow (which governs RIB/IBAN/BIC only — correctly left as `nullable|string|max:*` with no format rule). The FE always supplies a currency (defaults to company currency). Carried over from RC1 for completeness; no change required.

### 3. [INFO] `defaultCurrency` prop is passed to `PartnerBankAccountRow` but not consumed there

`PartnerBankAccountsSection.tsx:190` passes `defaultCurrency` into each row, but `PartnerBankAccountRow` does not destructure/use it (currency defaulting happens at `append(...)` time, line 171). Harmless dead prop; typecheck/lint are green. Optional cleanup.

---

## Ground-rules checklist (re-verified against RC2 code)

| Rule (brief §3 / §5 / §6) | Result | Evidence |
|---|---|---|
| **Tenant migration must not reference central-only tables** | ✅ PASS | `tenant_id` plain indexed uuid; FKs only to `partners`/`banks`/`users`. Regression test `test_tenant_migration_does_not_reference_the_central_tenants_table`. (RC1 BLOCKER — resolved.) |
| **Module boundary (§3.3) — the point of the design** | ✅ PASS | Partner imports only `App\Shared\Banking\Contracts\BankAccountValidatorInterface` + shared VOs (`PartnerBankAccountService.php:10`, `PartnerBankAccountData.php:8–10`, both FormRequests, `PartnerController.php:20`). Diff grep for `Modules\Treasury` / Treasury `Bank` model → **zero hits**. `bank_id` referenced by table name via `ScopedExists::tenant('banks', …)`, never the model. Cross-module `belongsTo(Tenant)`/`belongsTo(User,'created_by')` on the new entity mirror existing precedent (`Partner.php:203`, + 10 modules) — not a new violation. |
| **No int/float leakage in RIB/IBAN math (§3.10)** | ✅ PASS (N/A to Phase 3) | Phase 3 does not touch `app/Shared/Banking/**`; RIB/IBAN/BIC handled as strings (`PartnerBankAccountService.php:51–53` — `strtoupper`/`str_replace`/`trim`, no numeric coercion). Validator math already gated at Gate 2. |
| **Warn-but-allow, no checksum rejection (§4/§6)** | ✅ PASS | FormRequest rules structural only (`nullable string`/`max`/`uuid`/`size:3`); no RIB/IBAN/BIC format rule; recorder never calls `$fail`. `test_invalid_identifiers_never_reject_partner_save` asserts `assertCreated()` with `rib_validation.valid=false`. |
| **Middleware tuple (§3.6)** | ✅ PASS (N/A) | Phase 3 adds no route; extends existing `PartnerController` actions on their existing group. |
| **Seeder idempotency (§5)** | ✅ PASS (N/A) | Phase 3 adds no seeder. |
| **i18n en+fr, all `t()` (§3.11)** | ✅ PASS | 15 `partners.bankAccounts.*` keys present with identical key sets in `en/sales.json` and `fr/sales.json` (verified). Component uses `t('partners.bankAccounts.*')` exclusively; warn messaging ("saving is still allowed") reinforces warn-but-allow. Reuses existing `sales` namespace (deviation noted in progress doc). |
| **Design tokens, zero hardcoded colors (§3.9)** | ✅ PASS | `PartnerBankAccountsSection.tsx` uses only `semanticColorTokens` (`border.subtle`, `surface.base`, `text.*`, `intent.success.text`, `intent.caution.textStrong`); no `bg-*-NNN`/`text-*-NNN` literals added. Canonical atoms (`Button`, `Checkbox`, `FormField`, `Input`, `BankPicker`). |
| **Types flow from backend (§3.7)** | ✅ PASS | `generated.d.ts` gains `PartnerBankAccountData` + typed `PartnerData.bank_accounts` via transform; DTOs carry `#[TypeScript]`; not hand-edited. |
| **FE no double-unwrap (§3.8)** | ✅ PASS | Bank lookups go through Phase-2 `useBanks`/`useBankAccountValidation`; no new unwrap logic. |
| **Migration re-run safe (§3.13)** | ✅ PASS | `Schema::hasTable` guard + `down()` `dropIfExists`. |
| **TDD both layers (§3.1 / §5)** | ✅ PASS | Backend `PartnerBankAccountTest` — 5 tests / 31 assertions (warn-mode create + observable meta validity, invalid-never-rejects + default-primary, nested update/delete + one-primary, relation shape, tenant-FK regression). FE `PartnerForm.test.tsx` — edit-page picker→BIC autofill→derived IBAN (`07040005810111129653 → TN5907040005810111129653`, the design's canonical Amen Bank vector) submit; invalid-RIB warning with Save enabled. Progress records RED→GREEN. |
| **Single-primary enforcement** | ✅ PASS | "At most one" via `$primaryAssigned`; "at least one" via `$hasRequestedPrimary` default; FE radio-style `onMakePrimary` + `is_primary: fields.length === 0`. |
| **Out of scope — `PaymentInstrument` NOT touched (§0/§6)** | ✅ CONFIRMED | `git diff --stat bank-gate-2..HEAD` lists no `PaymentInstrument*` path; grep finds no instrument edits. |
| **Worktree unmerged + unpushed (final-gate requirement)** | ✅ CONFIRMED | SessionStart branch guard reports the branch **8 ahead / 4 behind `origin/dev`** — 8 local commits absent from dev ⇒ HEAD is not merged into `origin/dev`. RC1 verified the branch is not on origin (`for-each-ref` empty); the only change since is one local fix commit (`b35a2b3d5`), Codex was instructed not to push, and the `dev-push-guard` hook is active. Working tree clean at the local feature tip. (Live re-query of remote refs was blocked by this session's git sandbox; the branch-guard ahead/behind figure is authoritative on the not-merged fact.) |

---

## Required before merge

None blocking. Optional, post-gate:
1. **Finding #1 (LOW):** unify the `meta.bank_account_validation` country source with the response-body validity (use `$company->country_code`) before any second country ships, to prevent the two validity views from diverging.
2. **Finding #3 (INFO):** drop the unused `defaultCurrency` prop threaded into `PartnerBankAccountRow`.

The BLOCKER that gated RC1 is resolved with a regression test; no validator-math risk was introduced; the module boundary, warn-but-allow, i18n, tokens, and out-of-scope constraints all hold.

VERDICT: APPROVE
