# R2-A1 residuals — cross-company authz follow-ups (gate-mandated, 2026-08-07)

**Source:** R2-A1 gate record `docs/superpowers/reviews/2026-08-07-r2a1-authz-gate.md` (CLEAR TO MERGE with this ticket as the merge condition). Lane closed W-8 F-1 (JournalEntry/Account cross-company doors + caller-currency settlement) and F-5 (POST /companies commit-then-500). These are the pre-existing adjacents it does NOT fix.

## I-1 — accounts unique-code validator diverges from the DB constraint; last cross-company disclosure on the surface (P2, small)
`CreateAccountRequest.php:37` — `Rule::unique('accounts','code')->where('tenant_id', $tenantId)`. The DB constraint moved to `(company_id, code)` in `2025_12_30_195200_fix_accounts_unique_constraint.php:18-22` ("allows multiple companies … same code"); the validator never followed. Consequences: (a) 422 on a create the DB would accept — a second company cannot reuse code `401` that `ChartOfAccountsService::seedForCompany` (bypasses the FormRequest) legitimately seeds for both; (b) the 422-vs-201 difference is a cross-company **existence oracle for account codes** — and now that F-1's read doors are shut, it is the ONLY disclosure channel left on this surface. Fix: `->where('company_id', $companyId)` (mirror in the update request's unique rule if present). One-line + deny/allow tests.

## I-2 — parent_id exists-rule is tenant-only; cross-company write door (P2, small)
`CreateAccountRequest.php:45` + `UpdateAccountRequest.php:36` — `Rule::exists('accounts','id')->where('tenant_id', $tenantId)`. A company-A principal can PATCH their own account with `parent_id = <company-B account uuid>`; the validator passes and a cross-company parent edge persists, corrupting the hierarchy consumed by e.g. `SeedChartsCommand:174`. Gate-verified NOT a read-leak amplifier (`AccountData::fromModel` emits only `parent_id`, never the parent's name/code/balance). Fix: add `->where('company_id', $companyId)` to both rules + a deny test.

## m-1 — currency arm needs a genuinely-red guard (test-only)
The lane's per-company scale test is green pre-fix (proven by base revert): once post() is company-scoped, the divergent-currency scenario is unconstructible over HTTP, so dropping the `$company->currency` argument at `JournalEntryController.php:177` is defended only indirectly (via `Event::assertNotDispatched`). Add a direct `GeneralLedgerService::postEntry` test: post a company-B (EUR) entry while `CompanyContext` is bound to company A (TND) and assert the `JournalEntryPosted` totals carry EUR scale — that arm IS red against the old controller argument.

## m-2 — F-5 rides a single refresh(); optional structural hardening
`CompanyController.php:602,606` — commit `4b1659ba3` removed the runtime null-guard because `Company.php:106-107` declares `@property string` (a type demonstrably false for the exact unrefreshed instance that caused the bug). Any future caller handing `formatCompany` an unrefreshed model re-opens the commit-then-500. Optional: `formatCompany($company->fresh())` in store(), or re-add the guard with an inline `@phpstan-ignore` and a comment citing this ticket.

## m-3 — UUID guards on the four touched lookups (PG-500 class, pre-existing)
`AccountController.php:81,153` and `JournalEntryController.php:141,162` bind unvalidated route params into PG `uuid` PKs — `GET /api/v1/accounts/not-a-uuid` 500s on PG, masked by SQLite tests. Precedent guard: `PromotionController.php:72` (`Str::isUuid`). Same class as the known UUID-validation pitfall; sweep the four sites.

## m-4 — per-tenant entry numbering = weak cross-company activity oracle (R2-A2 family)
`JournalEntryController.php:187-204` `generateEntryNumber($tenantId)`: companies in one tenant interleave `JE-YYYY-NNNNNN`, so company A can infer sibling posting activity from number jumps (no content disclosed). Belongs with the R2-A2 numbering/identifier ruling (R-d/d1).

## m-5 — closure-note correction (documentation)
The W-8 F-1 remediation note must NOT claim fiscal-chain impact from the caller-currency defect: `GeneralLedgerService::sealAndPersistEntry:3270` always hashed with the entry's own company currency. Caller currency reached only the `JournalEntryPosted` event totals and balance scale. The chain was never poisoned.

## m-6 — AccountData balance scale hardcoded 3
`Application/DTOs/AccountData.php:30` — currency-blind; F-3 family, already covered by the W-8 ticket's F-3 entry. Recorded here only to note it pre-dates this lane.

## CI note
`AccountingTenantIsolationTest` silently aborts at 17/31 under the default PHP memory limit on the dev laptop; green 31/31 with `-d memory_limit=2G`. Verify the CI runner's ceiling.
