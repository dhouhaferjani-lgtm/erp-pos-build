# Task 05 Opus Second-Pass Review — Pending Customer Create And Alias Reconciliation

## Verdict

REQUEST-CHANGES.

The implementation covers the sequential happy path and has good POS-side response-scope checks, but it does not fully satisfy the Task 5 idempotency and cross-company alias-safety contract under concurrent or adversarial submissions. The server endpoint currently depends on read-before-write checks outside the transaction, and the database schema does not enforce the tenant-wide client UUID conflict that the endpoint claims to reject.

## Scope Reviewed

- Implementation commit: `251d3b27b Phase 2.5.1: Reconcile pending POS customers`
- Codex self-review: `docs/superpowers/reviews/2026-05-21-task-05-codex-review.md`
- Plan anchor: `docs/superpowers/plans/2026-05-21-pos-customer-accounts-phase2.md` Task 5
- Touched production files listed in the Task 5 review prompt
- Focused tests added by the commit

## Findings

### P1 — Concurrent cross-company submissions can create the same client UUID in multiple companies

`PosPendingCustomerController::store()` checks for an existing alias in another company before it starts the transaction (`apps/api/app/Modules/POS/Presentation/Controllers/PosPendingCustomerController.php:35-48`), then performs Partner + alias creation later (`apps/api/app/Modules/POS/Presentation/Controllers/PosPendingCustomerController.php:73-94`). The schema only has a unique constraint on `(tenant_id, company_id, client_customer_uuid)` (`apps/api/database/migrations/2026_05_21_120000_create_pos_customer_aliases_table.php:22`) and a non-unique `(tenant_id, client_customer_uuid)` index (`apps/api/database/migrations/2026_05_21_120000_create_pos_customer_aliases_table.php:24`).

That leaves a race: two terminals or replay workers can POST the same `client_customer_uuid` for two companies in the same tenant at the same time. Both requests can observe no cross-company alias, both transactions can insert, and both aliases are valid because the uniqueness constraint includes `company_id`. This violates the requirement to reject cross-company alias conflicts and creates exactly the replay ambiguity the alias table is supposed to prevent.

Required fix: enforce the conflict at the database/transaction boundary, not only by preflight lookup. The simplest contract-aligned shape is a unique constraint on `(tenant_id, client_customer_uuid)` plus handling the resulting unique violation as either same-company idempotent lookup or cross-company `POS_CUSTOMER_ALIAS_COMPANY_CONFLICT`. If same-client UUID reuse across companies must remain structurally possible, then take a tenant/client scoped lock inside the transaction before the cross-company check and insert. Add a test that simulates the existing cross-company alias path at the persistence level or otherwise proves the database cannot contain two rows for the same `(tenant_id, client_customer_uuid)`.

### P1 — Concurrent same-company duplicate requests are not reliably idempotent

The same read-before-write pattern also weakens same-company idempotency. `store()` reads the existing alias before the transaction (`apps/api/app/Modules/POS/Presentation/Controllers/PosPendingCustomerController.php:50-70`), and only then creates the Partner and alias (`apps/api/app/Modules/POS/Presentation/Controllers/PosPendingCustomerController.php:73-94`). Two identical POSTs for the same tenant/company/client UUID can both miss the existing alias, both create Partners, and then race on `pos_customer_aliases_client_unique`. The losing request will surface a database exception/500 rather than returning the existing alias, so the endpoint is not idempotent under the duplicate-submit case most likely to occur during offline retry.

Required fix: make alias creation idempotent inside the transaction. Use a transaction-scoped lock or insert-first/upsert flow that catches the unique violation, reloads the scoped alias, verifies the Partner scope, and returns the same resource. Ensure the loser does not leak a duplicate Partner and does not return a generic 500. Add a concurrency or duplicate-insert test that demonstrates the second request returns 200 with the first `server_partner_id`.

### P2 — The alias lookup helper can return a cross-company or stale Partner target

`PosCustomerAlias::resolveServerPartnerId()` filters only the alias row by tenant/company/client UUID and returns `server_partner_id` directly (`apps/api/app/Modules/POS/Domain/PosCustomerAlias.php:61-73`). The migration's `server_partner_id` FK references only `partners(id)` (`apps/api/database/migrations/2026_05_21_120000_create_pos_customer_aliases_table.php:38-42`), while `partners` has tenant/company columns but no composite FK is used here. The controller validates an existing alias target with `findScopedPartner()` before returning it (`apps/api/app/Modules/POS/Presentation/Controllers/PosPendingCustomerController.php:56-66`), but the durable replay helper added for Treasury replay does not perform the same guard.

This is a fail-open edge for any bad alias row introduced by a future writer, seed, repair job, or FK-valid but cross-company Partner reference. Task 5 explicitly calls this alias row the durable replay target; the lookup should not be weaker than the endpoint response path.

Required fix: either make the schema enforce `(tenant_id, company_id, server_partner_id)` against an equivalent unique key on `partners`, or make `resolveServerPartnerId()` join/query `Partner` and return a value only when the target Partner exists in the same tenant/company and is a customer-capable Partner. Add tests for a same-tenant/different-company Partner target and a stale/missing target.

## Pass Notes

- POS push validates `client_customer_uuid`, `server_partner_id`, `tenant_id`, `company_id`, and `resolved_at` before writing the local alias or resolving the outbox (`apps/pos/src/lib/customer/pendingCustomerSyncService.ts:40-103`).
- Local stale-alias checks block sequential conflicting writes through `storeCustomerAlias()` and `assertCustomerAliasMatches()` (`apps/pos/src/lib/db/repositories/pendingCustomerRepository.ts:145-227`).
- Route wiring is live under the existing authenticated POS route group (`apps/api/app/Modules/POS/routes.php:32-99`).
- I did not find new production use of `app()`, `App::make()`, or service-locator `resolve()` in the Task 5 production diff.
- No Treasury/B2B/Accounting dependency or fiscal-engine dependency reversal was introduced by this Task 5 code.

## Test Matrix Gaps

- No test proves cross-company alias conflicts are impossible under concurrent requests or at the database invariant level. The current test only covers the sequential pre-existing alias case.
- No test proves same-company duplicate creates are idempotent when both requests miss the preflight alias lookup.
- No test covers `resolveServerPartnerId()` with an alias row pointing at a Partner from another company or a stale target.

## Verification

Static review only for this second pass. I did not rerun the test suite because the request was to inspect the commit and write the adversarial review, and the blocking findings are evident from the schema and transaction boundaries.
