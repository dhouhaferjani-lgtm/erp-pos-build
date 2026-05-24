# Phase 2 Implementation Plan R2 — Opus-Equivalent Review

**Date:** 2026-05-21  
**Reviewed artifact:** `docs/superpowers/plans/2026-05-21-pos-customer-accounts-phase2.md`  
**R1 review:** `docs/superpowers/reviews/2026-05-21-pos-customer-accounts-phase2-plan-opus-review.md`  
**R2 self-review:** `docs/superpowers/reviews/2026-05-21-pos-customer-accounts-phase2-plan-r2-codex-review.md`  
**Verdict:** BLOCKER. Do not approve R2 yet.

## BLOCKER

1. **Pending-customer server alias persistence is still not executable.**

   R2 correctly adds Task 5 for `pending_customer_outbox`, local `customer_aliases`, a pending-customer push service, a `PosPendingCustomerController`, idempotency tests, conflict tests, and Treasury alias-resolution tests. That resolves the previous pure dead path on the POS side.

   The remaining blocker is server-side storage: Task 5 says the controller "creates or returns a `Partner` scoped by `(tenant_id, company_id, client_customer_uuid)`", but the file map adds no API migration, no server alias table/model, and no Partner migration/fillable field for `client_customer_uuid`. The existing `partners` schema/model do not carry that value. Without a durable server mapping, `test_pending_customer_create_is_idempotent_by_client_customer_uuid()` and cross-company alias conflict detection have no concrete persistence target, and Treasury cannot reliably resolve a historical pending customer after retry/replay.

   Add an explicit server-side alias persistence contract before approving the plan. For example: a `pos_customer_aliases` table keyed by `(tenant_id, company_id, client_customer_uuid)` with `server_partner_id`, uniqueness constraints, migration/model or repository, API tests for idempotent replay and conflict detection, and Treasury bridge lookup through that table. Alternatively, add a Partner column/index if that is the chosen design, but the plan must name the migration and scoped lookup/update rules.

## REQUEST-CHANGES

1. **Treasury `Payment` actor metadata remains underspecified.**

   The locked spec requires the Treasury bridge Payment row to set explicit actor metadata. Task 10's field list includes `tenant_id`, `company_id`, `partner_id`, `payment_method_id`, `repository_id`, amount, currency, date, type, origin, and `fiscal_event_id`, but not `created_by` or another concrete actor/audit field. Step 4 only passes actor context into allocation.

   Add the Payment actor/audit field to the bridge contract and a test proving it is populated from the sealed payload when resolvable, with a fail-loud or documented nullable behavior when not resolvable.

2. **Several later task commit steps omit explicit staging despite the plan's gate.**

   The plan says "Stage explicit files only. Never use `git add -A`." Tasks 5 through 10 include `git commit` commands without explicit `git add` commands. That is easy for an implementer to fix ad hoc, but it is still a plan-quality hole because the review workflow is commit-by-commit and the task file lists are known.

   Add explicit `git add <listed files>` commands to each task before its commit.

## MINOR

1. The top-level PHPStan/Pint gate includes `app/Modules/Partner` but not `app/Modules/Contact` or `app/Modules/B2B`. The D16 static guard itself now includes Contact and B2B, so this is not a D16 blocker. Consider including touched Contact/B2B paths only if implementation later modifies them.

## CLEAN

1. **Canonical drift gates are now early enough.** Task 1 explicitly includes TS/PHP golden canonical byte parity, `StrictCanonicalParser`, `CanonicalPayloadReader`, and corresponding test commands before downstream mirror/UI/device/projection work.

2. **Treasury bridge coverage is materially stronger.** Task 10 now names repository/payment-method fields and fail-loud tests for missing Partner, wrong-company Partner, missing method, missing repository, idempotency conflict, allocation exception, pending customer with no alias, and alias resolution.

3. **Customer task boundaries are narrower.** R2 splits mirror schema/repository, server pull endpoint, pull sync/cursor, pending-create alias reconciliation, UI attach, and device authoring into separate tasks.

4. **Device stale alias conflict coverage exists.** Task 7 includes `rejects stale alias conflicts before sealing`.

5. **D16 guard wording is aligned.** Task 8 forbids Treasury, Accounting, Partner, Customer, Contact, B2B, and container helper calls in the POS-core projection.

6. **Roadmap gate wording is strict.** Task 11 requires the exact "implementation complete, but NOT customer-facing deployment-ready" wording if Phase 1.5 tax-number validation remains unresolved.

7. I did not find scope creep into ZATCA, TSE, live Partner balance reads for printable output, or Treasury calls from device authoring/POS-core projection.

## Final Verdict

BLOCKER. R2 resolves most R1 findings, but it still cannot be approved until server-side pending-customer alias persistence is explicit and executable. After that is fixed, address the Treasury actor metadata and explicit staging gaps before sending the next review round.
