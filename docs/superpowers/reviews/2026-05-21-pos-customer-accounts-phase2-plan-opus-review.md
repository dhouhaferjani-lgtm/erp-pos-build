# Phase 2 Implementation Plan — Opus-Equivalent Adversarial Review

**Date:** 2026-05-21  
**Reviewed artifact:** `docs/superpowers/plans/2026-05-21-pos-customer-accounts-phase2.md`  
**Prior review:** `docs/superpowers/reviews/2026-05-21-pos-customer-accounts-phase2-plan-codex-review.md`  
**Locked spec:** `docs/superpowers/specs/2026-05-21-pos-customer-accounts-phase2-spec-v1.md`  
**Verdict:** REQUEST-CHANGES. Do not approve this plan until the blocker and request-changes findings below are resolved in a revised plan and re-reviewed.

## BLOCKER

1. **Pending customer create/alias reconciliation is planned as a dead path.**

   The locked spec requires offline minimum customer create to sync before or alongside `ACCOUNT_PAYMENT`, and requires the sync response to persist an alias mapping when the server canonical customer id changes. It also requires Treasury to wait/dead-letter only until a tenant/company-scoped server `Partner` alias exists. See spec lines 98-103 and implementation-plan input line 340.

   The plan creates only a customer pull endpoint and mirror sync in Task 2, then adds a local `PendingCustomer` shape in Task 3 that is "searchable locally immediately and marked pending in the sealed payload" (plan lines 241-345 and 392-408). There is no planned server create endpoint, no local pending-customer outbox, no `client_customer_uuid -> server_partner_id` alias table, no sync response contract, no alias conflict/stale-alias device test, and no server-side alias lookup surface for the Treasury bridge. Task 7 merely tests `test_bridge_dead_letters_when_pending_customer_has_no_alias()` (plan lines 605-610), which means the only planned implementation outcome for valid pending-create account payments is permanent reconciliation failure.

   This does not implement the spec; it turns an allowed Phase 2 workflow into a dead path. Add an atomic task, before device authoring/Treasury bridge work, for pending-customer push/reconciliation and alias persistence. It needs POS migration/repository tests, server scoped create/resolve tests, conflict tests, device stale-alias blocking, and Treasury bridge resolution through the alias before Payment creation.

## REQUEST-CHANGES

1. **Canonical byte parity and strict parser coverage are deferred/implicit, not locked where the contract lands.**

   The spec requires round-1 TS/PHP canonical byte parity for golden `ACCOUNT_PAYMENT` fixtures and strict parser accept/reject coverage (spec lines 295-300, 327). Task 1 calls for "validator and parity tests" but only names registry and payload tests in the run command and commit list (plan lines 189-238). Task 5 is titled "Server Parser" but its steps only add projection tests/table/projector/D16 guard and say to read parsed canonical payload (plan lines 469-535). The only explicit byte-equivalence check appears in Task 8 closure (plan lines 661-672), after UI, authoring, projections, allocation, and Treasury bridge have already been built on top of the contract.

   Move or add explicit golden fixture parity and `StrictCanonicalParser` tests into Task 1 or an immediate Task 1.5 before mirror/UI/device work. The implementation should fail fast if TS canonical bytes, PHP parser constraints, PHP DTOs, docs, and canonical reader views drift.

2. **Treasury bridge plan omits required FK/payment-method/repository coverage and fields.**

   The spec requires the Treasury `Payment` row to include repository/payment method and requires fail-loud tests for missing Partner, wrong-company Partner, missing repository/payment method, and allocation exception (spec lines 256-264 and 305-307). The plan's bridge field list includes tenant/company, partner, amount, currency, date, type, origin, and fiscal event id, but omits repository/payment-method mapping even though the payload includes `payment.method_code` and nullable `repository_id` (plan lines 622-637). The bridge tests cover create, retry, wrong-company partner, pending customer without alias, and FIFO invocation, but not missing Partner, missing repository, missing payment method, conflicting idempotency row, or allocation exception (plan lines 601-610).

   Add those fields to the bridge contract and make the tests explicit before implementation. Also add the idempotency conflict case from the spec, not just happy retry.

3. **Task boundaries are not atomic enough around customer mirror/create/attach.**

   Task 2 combines SQLite migration/repository, server pull endpoint, API resource, POS sync service, cursor persistence, and tests in one commit (plan lines 241-362). Task 3 then combines UI attach with pending-create behavior but without the alias contract (plan lines 365-415). Given the required commit/review loop and the cross-tenant risk in this area, these should be split into narrower reviewable commits:

   - POS mirror schema/repository/search/isolation.
   - Server customer pull endpoint/resource.
   - POS pull sync/cursor persistence.
   - Pending-create outbox/server create/alias reconciliation.
   - UI attach/search/detach over the now-stable repository contracts.

   This is not just process polish: the current grouping hides the missing pending-create alias workflow and makes a cross-tenant mirror regression harder to isolate.

4. **Device authoring tests miss the spec-required stale alias conflict case.**

   The spec requires the device engine path to reject stale alias conflict (spec line 301). Task 4 tests append, zero amount, stale balance metadata, and cross-company mirror rows (plan lines 428-436), but there is no stale/ambiguous alias test and no alias repository in earlier tasks. Add this once the alias contract is introduced.

## MINOR

1. **D16 static guard should include Contact and B2B modules.**

   The spec forbids POS-core projection imports from Treasury, Accounting, Customer, Contact, and B2B modules (spec lines 246-249 and 331). The plan's static guard covers Treasury, Accounting, Partner, Customer, and container helper calls, but not Contact or B2B (plan lines 522-534). Add those patterns to keep the guard aligned with the locked spec.

2. **Phase 1.5 launch gate is visible, but roadmap update wording should be stricter.**

   The non-negotiable gate says not to mark Phase 2 customer-facing deployment-ready until strict TN/FR validation is implemented, reviewed, and pushed (plan lines 15-16). Task 8 says to update the roadmap with shipped tasks and the remaining gate "if still unresolved" (plan lines 674-676). Tighten this to require explicit "implementation complete but not customer-facing deployment-ready" wording when the gate is unresolved.

## CLEAN

1. The high-level D16 architecture is correct: POS-core projection is standalone, Treasury is a projector-gated bridge, and the fiscal engine/parser/ingest path are not supposed to call Treasury.

2. The plan preserves the Phase 1.5 tax-number gate at the top and does not guess TN/FR country-specific regexes.

3. The POS-core account payment receipt projection is correctly sequenced before the Treasury bridge, preserving POS-only behavior if the missing parser/parity tests are added.

4. The allocation-service command DTO direction is sound and matches the spec's explicit actor/context requirement.

## Final Verdict

REQUEST-CHANGES. Do not approve. The plan is close structurally, but it does not yet fully implement the locked spec because pending customer alias reconciliation is missing, canonical/strict-parser drift gates are too late/implicit, and Treasury bridge fail-loud coverage is incomplete.
