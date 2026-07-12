# ADVERSARIAL GATE REVIEW — Bank Directory, GATE 3 (Phase 3: Partner bank accounts)

- **Reviewer:** Claude Opus 4.8 (final track gate, Claude-side review/merge session)
- **Scope:** `git diff bank-gate-2..HEAD` — one commit `e625efca1 "Phase 3.0.0: Add partner bank accounts"`
- **Contract:** brief §3 ground rules + §5 verification + §6 out-of-scope (`docs/handoff/CODEX-bank-directory-2026-07-10.md`); design doc `2026-06-30-bank-reference-and-account-verification-design.md`
- **Range refs:** `bank-gate-2` = `bbe6137d3`, `HEAD` = `e625efca1`
- **Files reviewed:** 20 (backend DTOs/service/entity/migration/requests/controller/test; FE PartnerForm + B2BFieldsSection + PartnerBankAccountsSection + locales + generated types; progress doc)

---

## Verdict summary

One **HIGH/BLOCKER** correctness defect in the tenant migration (`tenant_id` foreign key targets a table that does not exist in a per-tenant database — it will pass CI/`RefreshDatabase` but fail on a real `tenants:migrate`). The rest of the phase is clean: the module boundary — the entire point of this design — is honored exactly, warn-but-allow is respected, constructor injection is used throughout, i18n and design tokens are complete, `PaymentInstrument` was not touched, and the worktree remains unmerged and unpushed.

Escalation note: the blocker is **not** on the validator mod-97 math (Phase 3 does not touch `app/Shared/Banking/**`; RIB/IBAN flow through it as strings only). Per the brief's escalation rule (§ "Autonomous audit gates" #3), Fable re-review is **not** triggered.

---

## Findings (severity-ordered)

### 1. [BLOCKER] Migration FK `tenant_id → tenants` is invalid under db-per-tenant; will fail on real tenant DBs

`apps/api/database/migrations/tenant/2026_07_12_120000_create_partner_bank_accounts_table.php:39`

```php
$table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
```

The `tenants` table is a **central-DB** table — its create migration is `apps/api/database/migrations/2025_11_30_000001_create_tenants_table.php` (root/central tier), **not** under `migrations/tenant/`. In the Stancl db-per-tenant topology, tenant migrations run against a `tenant_<uuid>` database where `tenants` does not exist. A Postgres foreign key cannot reference a table in a different database, so `php artisan tenants:migrate` against any real tenant DB will throw `relation "tenants" does not exist` and abort the migration.

Evidence this is wrong, not idiomatic:
- **This is the ONLY tenant migration in the entire repo that FKs to `tenants`** (`grep -rn "on('tenants')" apps/api/database/migrations/tenant/` → 1 hit, and it is this file). By contrast, `on('users')` appears in **39** tenant migrations — because `users` *is* a tenant-DB table (`migrations/tenant/2025_11_30_000003_create_users_table.php`).
- The **sibling `banks` table from Phase 1 of this very track** (`..._create_banks_table.php:20`) deliberately uses a plain `$table->uuid('tenant_id')` with **no** FK.
- The **parent `partners` table** (`2025_11_30_052119_create_partners_table.php:18`) also uses a plain `$table->uuid('tenant_id')` with no FK.

Why the Phase 3 test suite is green anyway (and why this slipped): `PartnerBankAccountTest` uses `RefreshDatabase`, which migrates **all** tiers (central + tenant) into a **single** database, so `tenants` happens to exist there and the FK is satisfiable. This is exactly the failure mode CLAUDE.md rule 20 warns about — a test environment masking the per-tenant/worker reality. Passing tests do **not** clear this.

The other three FKs in the same migration are fine and should stay: `partner_id → partners`, `bank_id → banks`, `created_by → users` all target tenant-DB tables.

**Fix:** drop the `tenants` FK; make `tenant_id` a plain indexed uuid to match `banks` and `partners`:
```php
$table->uuid('tenant_id')->index();   // no ->foreign()->on('tenants')
```
(The existing `$table->uuid('tenant_id')->index()` at line 22 already indexes it; simply remove the `->foreign('tenant_id')...` line at 39.) Add a regression assertion that the migration applies cleanly against a tenant-only connection, or at minimum note that `tenants:migrate` was exercised against a real tenant DB before merge.

---

### 2. [LOW] `recordBankAccountValidity()` is effectively dead code — runs on every save but has no effect

`apps/api/app/Modules/Partner/Presentation/Requests/Concerns/ValidatesPartnerBankAccounts.php:503-513`, invoked from `CreatePartnerRequest::passedValidation()` and `UpdatePartnerRequest::passedValidation()`.

```php
$country = strtoupper((string) ($this->input('country_code') ?: 'TN'));
foreach ($this->bankAccounts() as $account) {
    $validator->validateRib($account->rib ?? '', $country);   // result discarded
    $validator->validateIban($account->iban ?? '');           // result discarded
    ...
}
```

Two problems, neither blocking:
- It reads `country_code`, which is **not a partner request field** (the partner uses `country`, size:2 — see the rules block). So the country argument is *always* the `'TN'` fallback.
- Every validator return value is **discarded**. The real validity metadata surfaced to the client is (correctly) computed later in `PartnerBankAccountData::fromModel()` at response time using the company's actual `country_code`. This method therefore does nothing observable.

Warn-but-allow is still honored (it never calls `$fail`), so this is not a correctness bug — but it reads as if request-time validation is happening when it is not. Either delete it, or if the intent was to attach warnings, wire it to something. Recommend deletion for clarity.

---

### 3. [INFO] Single-primary is enforced as "at most one", never "at least one"

`apps/api/app/Modules/Partner/Application/Services/PartnerBankAccountService.php:182-183`

```php
$isPrimary = $input->is_primary && ! $primaryAssigned;
$primaryAssigned = $primaryAssigned || $isPrimary;
```

If a client submits accounts where **none** is flagged primary, the partner ends with zero primary accounts. The FE guards against this (`append(... is_primary: fields.length === 0)` and radio-style `onMakePrimary`), and the design specifies only "single `is_primary=true` per partner (app-level)", which this satisfies as an upper bound. Flagging only so a future non-browser caller (import, API) is not assumed to always send a primary. No change required for this gate.

---

### 4. [INFO] `currency` is a hard `required_with` rule (structural, not a checksum — consistent with warn-but-allow)

`ValidatesPartnerBankAccounts.php:470` — `'bank_accounts.*.currency' => ['required_with:bank_accounts', 'string', 'size:3']`. A row without a 3-char currency yields a 422. This is a **structural** field requirement, not identifier-checksum rejection, so it does not violate the warn-but-allow policy (which governs RIB/IBAN/BIC only, and is correctly implemented — no format rule is added for those). The FE always supplies a currency (defaults to company currency). Noted for completeness only.

---

## Ground-rules checklist (verified against code)

| Rule (brief §3 / §5 / §6) | Result | Evidence |
|---|---|---|
| **Module boundary (§3.3) — the point of the design** | ✅ PASS | Partner code imports only `App\Shared\Banking\Contracts\BankAccountValidatorInterface` + shared VOs (`PartnerBankAccountData.php:14-16`, `PartnerBankAccountService.php:11`, both FormRequests). `grep` for `Modules\Treasury` / Treasury `Bank` model in Partner Phase-3 files → **zero hits**. `bank_id` stored as plain uuid; `ScopedExists::tenant('banks', …)` references the table by name, not the model. No cross-module model import. |
| **Constructor injection only, no `app()` (§3.4)** | ✅ PASS | `PartnerBankAccountService.__construct(private readonly BankAccountValidatorInterface)`, `PartnerController.__construct(..., PartnerBankAccountService, BankAccountValidatorInterface, ConnectionInterface)`, both FormRequests inject the validator via `__construct` + `parent::__construct()`. No `app()` in the diff (the two `app(...)` calls in the test file are test-setup, acceptable). |
| **No int/float leakage in RIB/IBAN math (§3.10)** | ✅ PASS (N/A to Phase 3) | Phase 3 does not touch `app/Shared/Banking/**`; RIB/IBAN/BIC are handled as strings end-to-end (`PartnerBankAccountService.php:191-193` uses `strtoupper`/`str_replace`/`trim`, no numeric coercion). Validator math already reviewed at Gate 2. |
| **Warn-but-allow, no checksum rejection (§4/§6)** | ✅ PASS | FormRequest rules are structural only (`nullable string`/`max`/`uuid`/`size:3`); no RIB/IBAN format rule. `test_invalid_identifiers_never_reject_partner_save` asserts `assertCreated()` with `rib_validation.valid=false`. Validity surfaced as metadata (`rib_validation`/`iban_validation`/`bic_valid`) in `PartnerBankAccountData`. |
| **Middleware tuple (§3.6)** | ✅ PASS (N/A) | Phase 3 adds no route; it extends existing `PartnerController` actions on their existing route group. |
| **Seeder idempotency (§5)** | ✅ PASS (N/A) | Phase 3 adds no seeder. |
| **i18n en+fr, all `t()` (§3.11)** | ✅ PASS | All 15 keys under `partners.bankAccounts.*` present in both `en/sales.json` and `fr/sales.json`; component uses `t('partners.bankAccounts.*')` exclusively, reuses existing `sales` namespace (deviation noted in progress doc). No hardcoded UI strings. |
| **Design tokens, zero hardcoded colors (§3.9)** | ✅ PASS | `PartnerBankAccountsSection.tsx` uses only `semanticColorTokens` (`colorTokens.border.subtle`, `.surface.base`, `.text.*`, `.intent.success.text`, `.intent.caution.textStrong`). All referenced token keys verified to exist in `designTokens.ts` (`intent.success.text`:254, `intent.caution.textStrong`:295). Non-color utilities only otherwise. Canonical atoms used (`Button`, `Checkbox`, `FormField`, `Input`, `BankPicker`). |
| **Types flow from backend (§3.7)** | ✅ PASS | `generated.d.ts` shows `PartnerBankAccountData` + `PartnerData.bank_accounts` added by transform (not hand-edited); DTOs carry `#[TypeScript]`. Progress reports `CACHE_STORE=array … typescript:transform`. |
| **FE no double-unwrap (§3.8)** | ✅ PASS | Bank lookups go through Phase-2 `useBanks`/`useBankAccountValidation` (unchanged here); no new unwrap logic in Phase 3. |
| **Migration re-run safe (§3.13)** | ✅ PASS | `if (Schema::hasTable('partner_bank_accounts')) return;` guard + `down()` `dropIfExists`. (FK target defect is finding #1, separate concern.) |
| **TDD both layers (§3.1 / §5)** | ✅ PASS | Backend `PartnerBankAccountTest` (4 tests: warn-mode create, invalid-never-rejects, nested update/delete + one-primary, relation shape). FE `PartnerForm.test.tsx` (+2: edit-page picker→BIC autofill→derived IBAN submit; invalid-RIB warning with Save enabled). Progress records RED→GREEN evidence. |
| **Out of scope — `PaymentInstrument` NOT touched (§0/§6)** | ✅ CONFIRMED | `git diff --stat bank-gate-2..HEAD` lists no `PaymentInstrument*` path; `grep` finds no PaymentInstrument edits. |
| **Worktree unmerged + unpushed (final-gate requirement)** | ✅ CONFIRMED | Branch `feat/bank-reference-verification` is `ahead 7, behind 4` of `origin/dev`; `git for-each-ref refs/remotes/origin/feat/bank-reference-verification` → empty (branch not on origin). Not merged into dev. |

Additional positives: `store`/`update` wrapped in `$this->db->transaction(...)` with `PartnerCreated` event fired post-commit; `bankAccounts` relation eager-loaded on show/store/update; `PartnerData::fromModel` new params are optional (default `[]`/`'TN'`), so the 3 existing callers (all in `PartnerController`, all updated) remain the only ones and nothing else breaks (`grep PartnerData::from` → no bare `::from()` construction).

---

## Required before merge

1. **Finding #1 (BLOCKER):** remove the `tenant_id → tenants` foreign key from the Phase 3 migration (make it a plain indexed uuid, matching `banks`/`partners`); verify the migration applies against a real per-tenant database via `tenants:migrate`, not only `RefreshDatabase`.
2. **Findings #2 recommended:** delete (or wire up) the no-op `recordBankAccountValidity()` and its `country_code` fallback.

Re-run this gate at `rc2` after the migration fix. No Fable escalation required (blocker is not validator-math).

VERDICT: CHANGES-REQUIRED
