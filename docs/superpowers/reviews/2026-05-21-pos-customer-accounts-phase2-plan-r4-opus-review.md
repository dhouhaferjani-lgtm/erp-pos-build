# Phase 2 Implementation Plan R4 - Opus-Equivalent Second-Pass Review

**Date:** 2026-05-21  
**Reviewed artifact:** `docs/superpowers/plans/2026-05-21-pos-customer-accounts-phase2.md`  
**Immediate prior reviews:**  
- `docs/superpowers/reviews/2026-05-21-pos-customer-accounts-phase2-plan-r3-opus-review.md`  
- `docs/superpowers/reviews/2026-05-21-pos-customer-accounts-phase2-plan-r4-codex-review.md`  
**Verdict:** APPROVE.

## Findings By Severity

### BLOCKER

None.

### REQUEST-CHANGES

None.

### MINOR

None.

## Clean Checks

1. **R3 canonical DTO sequencing/staging is resolved.** Task 1 now explicitly creates all canonical DTOs needed by `CanonicalPayloadReader::forAccountPayment()` and `test_canonical_reader_returns_account_payment_view()`: `AccountPaymentView.php`, `AccountPaymentCustomerDTO.php`, `AccountPaymentPaymentDTO.php`, `AccountPaymentBalanceSnapshotDTO.php`, and `AccountPaymentStalenessDTO.php` (`docs/superpowers/plans/2026-05-21-pos-customer-accounts-phase2.md:97-113`, `:252-260`). Task 1 also stages those exact files in its explicit `git add` block (`:271-290`).

2. **Task 8 no longer stages nonexistent canonical DTO paths.** The Task 8 file list and commit step are now limited to POS-core projection files, the receipt model, migration, provider registration, and projection/D16 tests (`:649-657`, `:717-727`). The wrong R3 paths under `Domain/DTOs/AccountPaymentCustomer.php`, `AccountPaymentPayment.php`, `AccountPaymentBalanceSnapshot.php`, and `Domain/Services/CanonicalPayloadReader.php` are gone.

3. **R2 server alias persistence blocker is resolved.** Task 5 now creates durable server alias persistence via `pos_customer_aliases`, `PosCustomerAlias`, tenant/company/client UUID uniqueness, server-partner lookup indexing, idempotent pending-customer create behavior, stale/conflicting alias handling, and Treasury replay lookup through the alias row (`:440-465`, `:494-536`, `:543-554`, `:842-844`).

4. **R2 Treasury actor metadata request is resolved.** Task 10 includes `created_by => $resolvedActorUserId`, requires tenant/company-scoped operator resolution from sealed cashier/operator identity, documents nullable unresolved behavior with projection metadata, forbids `Auth::user()` in the bridge, and adds `test_bridge_sets_created_by_from_resolved_operator()` (`:797-810`, `:821-844`, `:846-848`).

5. **Explicit staging rule is satisfied.** The plan states "Stage explicit files only. Never use `git add -A`" (`:34`) and every task commit block uses named files (`:271-290`, `:362-365`, `:407-410`, `:432-435`, `:540-554`, `:583-593`, `:638-646`, `:719-727`, `:778-783`, `:856-861`, `:894-897`). I found no `git add -A`.

6. **Cross-tenant and fail-loud posture remains intact.** The plan scopes customer mirror upsert/search by tenant/company (`:302-358`), server customer sync by tenant/company (`:393-403`), pending aliases by tenant/company/client UUID (`:484-509`), local sync response validation by tenant/company (`:536`), allocation by explicit tenant/company command (`:747-770`), and Treasury bridge lookups/Payment/idempotency checks by tenant/company (`:821-844`). Missing Partner, alias, method, repository, allocation failure, cross-company Partner, and conflicting fiscal-event Payment cases are fail-loud tests (`:797-809`).

7. **D16 bounded-module guard remains aligned.** POS-core receipt projection is always active, returns `requiresModule(): ?string { return null; }`, reads only parsed canonical payload, and forbids Treasury, Accounting, Partner, Customer, Contact, B2B, `app()`, `App::make()`, and `resolve()` (`:688-715`). Treasury remains a separate module-gated projector with `requiresModule(): ?string { return 'Treasury'; }` (`:812-819`).

8. **Constructor-injection / no container-helper rule is preserved for replay paths.** The plan explicitly forbids `Auth::user()` in allocation replay (`:770`) and in the Treasury bridge (`:844`), and the review axes carry forward no `app()`, `App::make()`, `resolve()`, and constructor injection only (`:910-921`). This matches `CLAUDE.md` rule 13.

9. **Discriminated-union and contract drift gates are early enough.** Task 1 requires positive fixtures for synced/pending customer, fresh/stale balance, local/foreign currency, nullable/populated references, and negative malformed cases (`:206-230`), plus TS/PHP golden canonical parity (`:232-237`). This matches the spec's round-1 matrix requirement for discriminated-union completeness and byte-equivalent TS/PHP contracts (`docs/superpowers/specs/2026-05-21-pos-customer-accounts-phase2-spec-v1.md:293-308`).

10. **Phase 1.5 launch gate remains visible and not weakened.** The top gate still blocks customer-facing Phase 2 deployment until per-country tax-number validation is implemented, reviewed, and pushed (`docs/superpowers/plans/2026-05-21-pos-customer-accounts-phase2.md:13-15`). Task 11 preserves the exact "NOT customer-facing deployment-ready" roadmap language if the gate remains unresolved (`:884-887`).

11. **Per-method skip posture remains visible.** The plan's review axes require per-method skip only and skip-citation accuracy (`:910-918`), and I found no class-level skip plan.

## Residual Risks

1. **Actor lookup implementation still needs close review.** The plan says the bridge resolves `$resolvedActorUserId` by "tenant/company-scoped user lookup" (`:844`). Implementation review should verify this uses the actual company-membership relation rather than a tenant-only user query.

2. **Alias FK strategy remains an implementation choice.** The `pos_customer_aliases` migration specifies UUID columns, uniqueness, and indexes but not hard FK constraints (`:494-509`). The controller/replay rules are concrete enough for plan approval, but implementation review should verify the intended FK/index posture and stale Partner behavior.

3. **Direct Partner access in POS controllers should be checked against module-boundary conventions.** The plan uses `Partner::query()` and Partner creation in POS sync/pending controllers (`:393-403`, `:523-524`). The tenant/company scope is explicit, but implementation review should confirm whether this path is acceptable as a public integration boundary or should move through a Partner-owned service/contract.

## Final Verdict

APPROVE. The R2 blocker/request-change items are resolved, and the R3 canonical `AccountPaymentView` sequencing/staging defect is resolved without introducing a Task 8 regression.
