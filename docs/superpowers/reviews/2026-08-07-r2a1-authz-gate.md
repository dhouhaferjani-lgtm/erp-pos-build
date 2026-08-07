# GATE RECORD — R2-A1 cross-company authz (W-8 F-1 / F-5) — tenancy/authz axis (sole gate)

**Round 1. Verdict: spec ✅ + quality APPROVED — CLEAR TO MERGE** (0 Critical; 2 Important pre-existing out-of-lane; 6 minor). Merge condition: one follow-up residuals ticket (filed as `2026-08-07-r2a1-residuals.md`).
**Branch:** `fix/r2a1-cross-company-authz` @ `4b1659ba3`, base `264e6c483`, 5 commits, 3 product + 2 test files, +452/−5.

## Scope pin VERIFIED
Ticket's F-5 row = "POST /api/v1/companies creates the company and then answers 500 — formatCompany() casts two NOT-NULL-with-DB-default columns with (string) before a null-guarded formatter"; `grep balance_due` → no match anywhere in the ticket. The `balance_due ?? total` consumers are correctly lane R2-F5.

## Doors verified at file:line
JournalEntryController :43 index / :139 show / :160 post company-scoped (before findOrFail); :177 `postEntry($entry, $user)` third arg dropped. AccountController :36 index (`forCompany`, scope = `Account.php:216-218`), :81 show / :153 update (sibling doors — genuine hardening, not creep). CompanyController :174 `refresh()` after the tx, before formatCompany. Rule-12 middleware intact (`Accounting routes.php:25`); no new `can:` permission → no seeder sync owed. `X-Company-Id` is not a trust hole: `CompanyContextMiddleware:67` → `userHasAccessToCompany` (`CompanyContext.php:146-152`, Active membership required).

## Completeness sweep — independently re-derived, NO residual door
All 7 Accounting Presentation controllers read line-by-line: AccountPurpose/PartnerBalance/OpeningBalanceBatch all `assertCompanyAccess` (real Active-membership 404, `RequiresCompanyAccess.php:37-53`); LedgerController → GeneralLedgerReportService pins `je.company_id` (:206,:267,:294,:379) + `accounts.company_id` (:524); ReportsController traced one level down across 7 services — all company-pinned; the `whereIn('id', $accountIds)` re-hydrations draw from company-scoped balance queries (not a widening). Repo-wide Account/JournalEntry builder usage outside Accounting/Domain company-pinned (Procurement:426, Compliance:245/306, Treasury bridges, GeneralLedgerHashService:103/149); `MigrationWizardService:83,405` are tenant-wide count()s only. Honest bound: the cross-company axis outside Accounting is a plan-decomposition question, not a defect of this diff.

## Currency arm — verified, one caveat
`GeneralLedgerService` UNCHANGED by the diff; `postEntry:2703` already had `?string $currencyCode = null` — all 26 call sites checked, nothing removed. `sealAndPersistEntry:3229-3231` derives from the ENTRY's company_id on null. Per-company scale guard executed (TND 11.110 / EUR 777.77). Caveat (m-1): the currency test is GREEN pre-fix (proven by revert) — regression guard only; the `Event::assertNotDispatched` at :201 is the red arm and fires correctly. **Ticket-framing correction (m-5): `sealAndPersistEntry:3270` always hashed with the entry's own company currency — the fiscal hash chain was never poisoned; caller currency reached only event totals + balance scale.**

## Deny shape — consistent, no existence oracle
404 `NOT_FOUND` across all doors; cross-company and cross-tenant now produce the identical refusal on the identical route — foreign id indistinguishable from nonexistent id. Cross-tenant precedent intact (AccountingTenantIsolationTest 31/31).

## Execution evidence (by path only)
CrossCompanyIsolation OK 12/34 · CreateCompany OK 13/60 · TenantIsolation OK 31/65 (needs `-d memory_limit=2G` locally — silent abort at 17/31 under default limit; local-runner artifact, check CI ceiling) · PHPStan 5 files clean · Pint pass.
**Non-vacuity by revert:** both accounting controllers at base → exactly **7/12 red** (the 5 green are the deliberate no-over-scoping counterparts); CompanyController at base → 7/13 red on CreateCompanyTest = **6 pre-existing dev reds + 1 new** — the "6 pre-existing" claim verified against base, not assumed. Tests use the real X-Company-Id → middleware → membership flow with deny-path STATE assertions (entryB still Draft, hash/chain null, accountB name unchanged).

## F-5 fix quality
Root cause exact: `2025_12_02_064506` migration :16-17 — `decimal(5,2)->default(30.00)` NOT NULL, not passed by `Company::create()`. `refresh()` cannot mask a real null (NOT NULL); per-create only; strictly better than per-field guards — the same payload serializes two enum-cast DB-default columns (:610-611) that would have fataled identically.

## Findings (full text in the residuals ticket)
- **I-1** `CreateAccountRequest.php:37` unique(code) tenant-scoped while DB constraint moved to (company_id, code) in `2025_12_30_195200` — 422 the DB would accept, and a cross-company **existence oracle for account codes**: after this fix, the only disclosure channel left on the surface. Fix: `->where('company_id', $companyId)`.
- **I-2** `CreateAccountRequest.php:45` / `UpdateAccountRequest.php:36` parent_id exists tenant-only — cross-company parent edge persists (hierarchy corruption); verified NOT a read-leak amplifier (AccountData emits only parent_id).
- **m-1** currency arm has no red guard — add a direct GeneralLedgerService test (company-B entry under company-A context → EUR-scale event totals; red against the old argument).
- **m-2** `4b1659ba3` removed the runtime null-guard to satisfy PHPStan (`@property string` is false for the exact instance that caused the bug) — all of F-5 rides one `refresh()`; optional structural fix `formatCompany($company->fresh())`.
- **m-3** unvalidated route params bound into PG uuid PKs on the four touched lookups (pre-existing; 500 on PG, masked by SQLite; precedent guard `PromotionController.php:72`).
- **m-4** `generateEntryNumber($tenantId)` per-tenant numbering = weak cross-company activity oracle (number-jump inference; no content).
- **m-5** chain-integrity overclaim correction (above).
- **m-6** `AccountData.php:30` hardcodes scale 3 for balance — F-3 family, already ticketed there.
