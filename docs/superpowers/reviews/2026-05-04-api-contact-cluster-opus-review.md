# Opus adversarial review — api.contact cluster (round 1)

Review date: 2026-05-04
Branch tip reviewed: e7c5dfd10da3e812a0f3b16ce5f74ad06dd98182
Reviewer: opus (first-layer adversarial review)

Verdict: APPROVE
Commit reviewed: a2436417

## Summary

The api.contact cluster fix (a2436417) cleanly closes both inventoried bare-exists callsites (api.contact.001 = `CreateContactRequest::rules` party_id, api.contact.002 = `ContactController::linkParty` inline validator) using the canonical `ScopedExists::tenantAndCompany` helper with the constructor-injected `CompanyContext` pattern from `RefundPrepaymentRequest`. The 5 controller bare-where blind spots (`show` / `update` / `destroy` / `linkParty` / `unlinkParty`) all now lead with `where('tenant_id', ...)`, satisfying the cluster invariant Codex established in Treasury round-3 Finding 14. The 9-test regression suite is honest — cross-tenant denial paired with same-tenant control on every callsite, plus two structural-SQL-log invariant tests pinning the SQL shape (mirroring Treasury round-5 bar-raising pattern). Tests pass (9 / 35 — commit message slightly understated at 34, harmless), PHPStan clean, Pint clean, sweep-progress architecture gates intact (96 / 102 — same numbers as before the fix because Contact module had zero matches in the scanner; the bare-where blind spots were never counted), POS surface diff empty. Treasury regression-clean (272 / 835 OK). Workflow YAML state at e7c5dfd1 is mechanically correct.

## Findings

1. **Severity: NICE-TO-HAVE** — `ContactController::unlinkParty($id, $partyId)` does not run `$partyId` through `ScopedExists::tenantAndCompany`. The downstream service deletes from `party_contacts` filtered by `contact_id` (already tenant-scoped) AND `party_id` (raw URL param), so it is structurally protected: a cross-tenant `partyId` cannot match a join row anchored on a same-tenant contact. The cluster invariant is satisfied **structurally**, not predicate-by-predicate. Treasury template treats this as category (b)/(c). Suggest a one-line `// structurally_protected_by_upstream_guard` annotation on the `unlinkFromParty` call (`ContactController.php:303`) plus an explicit cross-tenant `partyId` test (same-tenant contact + cross-tenant partyId returns 204 but deletes nothing — DB invariant). Not blocking; mirrors the Treasury annotation discipline introduced in `api.treasury.027/028/030`.
   - File: `apps/api/app/Modules/Contact/Presentation/Controllers/ContactController.php:303`
   - Suggested fix: add comment annotation; add a regression test asserting `assertDatabaseHas('party_contacts', [contact_id=$contactA->id, party_id=$partnerA->id])` after a same-tenant-contact + cross-tenant-partyId unlink call.

2. **Severity: NICE-TO-HAVE** — `ContactController::index()` (line 65–66) uses `Contact::query()->where('company_id', $companyId)` — no `tenant_id` predicate. This does **not** violate the cluster invariant (the invariant scopes route-param-anchored reads, not list queries scoped by `CompanyContext`), and is structurally safe because `companies.id` is globally unique and `CompanyContext` is set after membership validation. However, the precedent in Treasury (e.g. `MultiPaymentService::219`, `PaymentController::486`) does the same thing, so this is **consistent with the reference cluster**, not a regression. Mentioning only because a future "all-reads-tenant-scoped" tightening will need to revisit it.
   - File: `apps/api/app/Modules/Contact/Presentation/Controllers/ContactController.php:65-66`
   - Suggested fix: defer; capture as a separate sweep section if the invariant ever broadens.

3. **Severity: NICE-TO-HAVE** — `ContactService::setPrimaryContact` (line 76) is dead code (no callers). Recommend deletion in a separate cleanup commit; not part of this cluster's scope.
   - File: `apps/api/app/Modules/Contact/Application/Services/ContactService.php:76`
   - Suggested fix: delete in a follow-up.

## Audit exhaustiveness

- Hostile-grep result count: 10 matches in `apps/api/app/Modules/Contact/` for the `where('id|partner_id|document_id|contact_id|party_id|user_id', ...)` pattern.
  - 5 in `ContactController` (lines 131 / 164 / 202 / 233 / 287) — all NOW lead with `where('tenant_id', ...)` → category (a) clean.
  - 5 in `ContactService` (lines 68–69, 81–82, 92) — join-table `PartyContact` reads anchored on either a tenant-scoped `Contact` (controller-validated) or a tenant-scoped `partyId` (validator-validated). All category (b)/(c) structurally protected. The only one with a raw URL `partyId` is `unlinkFromParty`, addressed in Finding 1.
- Bare-exists scanner check: `grep -rnE "exists:(partners|contacts|companies|users|tenants)"` against the Contact module returned **zero matches** post-fix.
- Sibling FormRequest check: `UpdateContactRequest` reviewed — has no exists rules, no party_id field, no constructor injection needed. Clean.
- FormRequest constructor injection sanity: `CreateContactRequest::__construct(private readonly CompanyContext)` matches canonical `RefundPrepaymentRequest` pattern; Laravel resolves FormRequest constructors via the container, so `parent::__construct()` is sufficient.
- Test setUp honesty: `partnerB` is created with `tenant_id => tenantB->id` and `company_id => companyB->id` (lines 171–176). Cross-tenant assertion is real, not a false positive.
- Structural-SQL-log honesty:
  - `test_show_query_includes_tenant_and_company_predicates` filter `from "contacts" + "id" = + !count(*)` correctly captures the production `show()` query, not the eager-load `with('parties')` query (which targets `party_contacts` / `partners`, different table).
  - `test_link_party_validator_query_includes_tenant_and_company_predicates` filter `from "partners" + "id" = + (exists | count(*))` correctly captures the `Rule::exists` `select count(*) from "partners" where "id" = ? and "tenant_id" = ? and "company_id" = ?` validator query.
- Tests run:
  - `vendor/bin/phpunit tests/Feature/Contact/ContactTenantIsolationTest.php` → **OK (9 tests, 35 assertions)** [commit msg said 34; 35 is correct]
  - `vendor/bin/phpunit tests/Feature/Treasury` → **OK 272 / 835** (regression-clean; 18 unrelated PHPUnit deprecations)
  - `vendor/bin/phpunit --testsuite=Architecture --group=sweep-progress` → **OK 2 / 4** (Gate A: 96 unscoped exists, Gate B: 102 unscoped find — gates intact)
- PHPStan: `app/Modules/Contact + new test` → **[OK] No errors**
- Pint: `app/Modules/Contact + new test` → **{"result":"pass"}**
- POS surface diff dev..a2436417: **empty**
- Workflow YAML diff (e7c5dfd1) sanity: status `in_progress → under_review` for both api.contact.001 + api.contact.002, fix_commit pinned to a2436417, regression_test pinned to two test methods, history entries appended, schema_sha256 unchanged, yaml_sha256 chained correctly. Mechanical and clean.

## Confidence

High confidence the cluster is fully closed against the Codex Finding-14 cluster invariant for inventoried callsites and the 5 controller bare-where blind spots. The two structural-SQL-log tests provide bar-raising coverage that pins SQL shape (not just behavior), so a future regression that reverts one of the predicates would fail loudly.

What I could have missed:
- A side-channel I did not exhaustively trace: if a Contact-related event (e.g. `ContactCreated`) is consumed by another module's listener that re-reads `Contact::find($id)`, that listener could be unscoped. Spot-grep across the codebase showed `POS/Application/Services/ReceiptCreationService.php:508` does `Contact::find($contactId)` — but that's a separate module, out of api.contact cluster scope, and is already counted in the Gate B = 102 unscoped finds tracked architecturally. Not a regression introduced by this commit; flagged here for orchestrator awareness.
- I did not run the **full** test suite (the cluster gate is `tests/Feature/Contact + tests/Feature/Treasury` per the handoff). A broader regression sweep (e.g., `tests/Feature/Loyalty` since LoyaltyServiceProvider aliases `'contact' => Contact::class`) would deepen confidence but is outside the cluster's review scope.
- The unlinkParty path (Finding 1) is structurally protected today. If a future change loosens the contact lookup — e.g. someone replaces `Contact::where(...)->first()` with a route-model-binding shortcut — the unlink would become exploitable. The defense-in-depth fix (validate `partyId` via ScopedExists + add the test) would close that latent risk; tracked as NICE-TO-HAVE.
