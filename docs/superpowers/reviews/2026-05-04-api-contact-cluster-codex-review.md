# Codex second-layer adversarial review - api.contact cluster

Review date: 2026-05-04
Reviewer: codex
Branch observed: feat/tenant-isolation-sweep-execution

Verdict: REQUEST-CHANGES
Commit reviewed: a2436417

## Summary

The code fix in `a2436417` satisfies the api.contact cluster invariant: every `ContactController` read anchored on a route contact id now carries both `tenant_id` and `company_id`, and both inventoried `party_id` validators now use `ScopedExists::tenantAndCompany('partners', ...)`.

I do not approve the cluster because the required workflow history gate does not pass. Opus claimed the workflow YAML at `e7c5dfd1` was mechanically clean; independently verifying the `e7c5dfd1` snapshot with `sweep:inventory:verify-history` reports a second orphaned `previous_yaml_sha256` tied to the api.contact claim events. This is not a runtime tenant-isolation defect in the three-file fix, but it is a required review gate and it contradicts Opus's approval evidence.

## Findings

1. **Severity: REQUEST-CHANGES** - Workflow history chain verifier fails for the reviewed api.contact workflow state.
   - Evidence: running `php artisan sweep:inventory:verify-history` against the `e7c5dfd1` inventory snapshot reports:
     `verified 557 event(s) across 262 callsite(s); 1 problem(s)`
     with `[bootstrap-orphan] 2 distinct previous_yaml_sha256 values ... 739c950... , c5739...`.
   - The extra orphan `c5739ab558edd2d367d687d56b595e01162e2774730fa7516b9c765e94a6ffd1` is used as `previous_yaml_sha256` by the `api.contact.001` and `api.contact.002` `claim` history entries, but no event in that snapshot writes it as `new_yaml_sha256`.
   - Row state itself is correct at `e7c5dfd1`: both `api.contact.001` and `api.contact.002` are `under_review`, both have `fix_commit: a2436417`, and both have the expected regression tests pinned.
   - Required change: repair the inventory history chain so `sweep:inventory:verify-history` passes on the canonical inventory file. Do not change the runtime contact fix for this finding.

2. **Severity: NICE-TO-HAVE** - `unlinkParty($partyId)` remains structurally protected, not independently validated.
   - `ContactController::unlinkParty` scopes the contact by `tenant_id + company_id + id`; `ContactService::unlinkFromParty` then deletes by `(contact_id, party_id)`.
   - A same-tenant contact plus cross-tenant party id cannot delete another tenant's row unless an invalid cross-tenant pivot already exists. This is safe for the current controller path, but a `ScopedExists` check or explicit test would harden the boundary.

3. **Severity: NICE-TO-HAVE / OUT-OF-CLUSTER** - POS still has a real Contact side channel, already inventoried outside api.contact.
   - `StoreReceiptRequest` has bare `exists:contacts,id` for `contact_id`.
   - `ReceiptCreationService` then calls `Contact::find($contactId)` and copies the contact name/identifier/contact id onto a receipt.
   - Inventory already tracks this under `api.pos-stabilization` (`StoreReceiptRequest` contacts validator and `ReceiptCreationService` Contact find), so I am not charging it to `a2436417`. It is a concrete cross-module caller that should be handled when the POS stabilization cluster is worked.

## Opus Claim Verification

- Contact regression suite: verified. `vendor/bin/phpunit tests/Feature/Contact/ContactTenantIsolationTest.php` passed with `OK (9 tests, 35 assertions)`. Opus was right; the commit message's `34 assertions` is stale.
- Requested combined regression command: verified. `vendor/bin/phpunit tests/Feature/Contact tests/Feature/Treasury/TreasuryTenantIsolationTest.php` passed with `OK (100 tests, 354 assertions)`.
- Full Treasury directory claim: verified separately. `vendor/bin/phpunit tests/Feature/Treasury` passed with `272 tests, 835 assertions`, plus 18 PHPUnit deprecations.
- PHPStan: verified. `./vendor/bin/phpstan analyse --no-progress --memory-limit=2G app/Modules/Contact tests/Feature/Contact/ContactTenantIsolationTest.php` returned `[OK] No errors`.
- Pint: verified. `./vendor/bin/pint --test app/Modules/Contact tests/Feature/Contact/ContactTenantIsolationTest.php` returned `{"result":"pass"}`.
- POS surface diff: verified for `apps/api/app/Modules/POS`, `apps/pos`, `apps/api/app/Modules/Voucher`, and `apps/api/routes`: empty.
- Workflow state rows: partially verified. The row fields at `e7c5dfd1` are correct, but the history verifier fails as Finding 1 describes.

## Structural Test Honesty

I temporarily printed the captured SQL from the two SQL-log tests and removed the instrumentation afterward. The test file diff is clean.

- `test_show_query_includes_tenant_and_company_predicates` captures the intended Contact lookup:
  `select * from "contacts" where "tenant_id" = ? and "company_id" = ? and "id" = ? and "contacts"."deleted_at" is null limit 1`
- `test_link_party_validator_query_includes_tenant_and_company_predicates` captures the intended partners validator query:
  `select count(*) as aggregate from "partners" where "id" = ? and "tenant_id" = ? and "company_id" = ?`

The `show` filter is not accidentally capturing the eager-load query, and the validator test is not a false positive.

## Audit Exhaustiveness

- Read Opus's api.contact verdict end-to-end before running checks.
- Read the Treasury round-5 Codex and Opus reviews for the reference pattern.
- Read `git show a2436417 -- apps/api/app/Modules/Contact apps/api/tests/Feature/Contact` end-to-end.
- Hostile grep in `app/Modules/Contact` found zero remaining bare `exists:` validators for `partners|contacts|companies|users|tenants`.
- Hostile grep in `app/Modules/Contact` found the expected 10 bare-where matches:
  - 5 `ContactController` route-id lookups, all now preceded by `tenant_id` and `company_id`.
  - 5 `ContactService` pivot-table operations, all structurally anchored by scoped controller/validator paths or dead code.
- Sibling controller sweep: `Contact/Presentation` contains only `ContactController`, `CreateContactRequest`, and `UpdateContactRequest`; no alternate `/contacts/*` controller surface was found.
- Route sweep: all `apps/api/app/Modules/Contact/routes.php` contact routes map to `ContactController`.
- Service caller sweep: production calls to `linkToParty` and `unlinkFromParty` are only from `ContactController`; `setPrimaryContact` has no production caller.
- Raw SQL / joins / `whereIn` sweep in `app/Modules/Contact`: no matches.
- Cross-module Contact lookup sweep found the existing POS side channel described above; it is inventoried under `api.pos-stabilization`, not api.contact.

## Confidence

High confidence on the runtime api.contact code fix and on the honesty of the new structural tests. The only request-change gate is workflow metadata integrity: the reviewed inventory snapshot does not pass the mandated history verifier, so Opus's mechanical-clean claim does not hold.
