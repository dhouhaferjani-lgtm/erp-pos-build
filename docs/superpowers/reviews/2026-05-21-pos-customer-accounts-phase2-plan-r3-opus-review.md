# Phase 2 Implementation Plan R3 - Opus-Equivalent Review

**Date:** 2026-05-21  
**Reviewed artifact:** `docs/superpowers/plans/2026-05-21-pos-customer-accounts-phase2.md`  
**Prior review:** `docs/superpowers/reviews/2026-05-21-pos-customer-accounts-phase2-plan-r3-codex-review.md`  
**Verdict:** REQUEST-CHANGES.

## REQUEST-CHANGES

1. **Canonical `AccountPaymentView` DTO sequencing and staging are inconsistent, making Task 1/Task 8 non-executable as written.**

   Task 1 tells the implementer to add `CanonicalPayloadReader::forAccountPayment(FiscalEvent $event): AccountPaymentView` and to write `test_canonical_reader_returns_account_payment_view()` (`docs/superpowers/plans/2026-05-21-pos-customer-accounts-phase2.md:247-255`, `:219-225`). But Task 1's file list and commit staging do not include the canonical view DTOs (`:97-111`, `:267-280`). The plan defers "Create canonical view DTOs listed in File Map" to Task 8 (`:641-648`), after Task 1 has already required code and tests that return `AccountPaymentView`.

   This is not just a staging nit: Task 1 cannot compile or pass its own canonical-reader test unless the `AccountPaymentView` and nested canonical DTO classes already exist. Either move the canonical DTO creation into Task 1, or move the `CanonicalPayloadReader::forAccountPayment()` work/tests out of Task 1 and into Task 8. The cleaner fix is to create the canonical DTOs in Task 1 because the drift gate and reader test are already intentionally early.

2. **Task 8's explicit `git add` list still stages the wrong canonical files.**

   The File Map names canonical DTOs under `apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/` with `*DTO.php` suffixes plus `AccountPaymentView.php` (`:40-45`). Task 8's commit instead stages non-matching paths such as `Domain/DTOs/AccountPaymentCustomer.php`, `AccountPaymentPayment.php`, `AccountPaymentBalanceSnapshot.php`, and `Domain/Services/CanonicalPayloadReader.php` (`:712-716`). It also omits `AccountPaymentView.php` and `AccountPaymentStalenessDTO.php`.

   R3 did fix the R2 requirement that every task use explicit staging and avoid `git add -A`, but this specific task would still commit an incomplete or impossible file set. Update Task 8's stage list to the actual canonical DTO paths, or remove those entries from Task 8 if the DTOs are moved to Task 1 per finding 1.

## CLEAN CHECKS

1. **R2 blocker resolved:** Pending-customer alias persistence is now executable on the server. Task 5 adds `pos_customer_aliases`, `PosCustomerAlias`, tenant/company/client UUID uniqueness, idempotent create/replay behavior, and Treasury replay lookup through the server alias table (`:428-545`, especially `:484-526`, `:838-840`).

2. **R2 Treasury actor request resolved:** Task 10 now sets `created_by => $resolvedActorUserId`, resolves it from sealed cashier/operator identity through a tenant/company-scoped lookup, documents nullable unresolved behavior with projection metadata, forbids `Auth::user()` in the bridge, and adds `test_bridge_sets_created_by_from_resolved_operator()` (`:789-805`, `:819-840`).

3. **R2 explicit-staging request mostly resolved:** Tasks 1 through 11 all use explicit `git add <files>` commands and I found no `git add -A` instruction (`:267-281`, `:354-355`, `:399-400`, `:424-425`, `:533-544`, `:575-583`, `:630-636`, `:712-723`, `:776-779`, `:854-857`, `:891-893`). Task 8 needs path correction per finding 2.

4. **D16 POS-core guard is aligned:** The POS-core `AccountPaymentReceiptProjection` remains always active, reads only parsed canonical payload, and explicitly forbids Treasury, Accounting, Partner, Customer, Contact, B2B, `app()`, `App::make()`, and `resolve()` (`:679-706`).

5. **Treasury bridge remains module-gated:** The bridge contract returns `requiresModule(): ?string { return 'Treasury'; }` and is registered through `FiscalEventProjector::class` (`:808-848`).

6. **Phase 1.5 launch gate remains visible:** The plan keeps the TN/FR tax-number validation gate at the top and requires the roadmap to preserve the "NOT customer-facing deployment-ready" wording if unresolved (`:13-15`, `:880-882`).

7. **Per-method skip posture preserved:** I found no new class-level `markTestSkipped()` plan. The review workflow keeps per-method skip and skip-citation accuracy as explicit review axes (`:906-918`).

## RESIDUAL RISKS

1. Task 10's actor lookup says "tenant/company-scoped user lookup" but does not name the concrete table join. Since `users` are tenant-scoped and company scope lives through `user_company_memberships`, the implementation review should verify that the bridge uses membership scope rather than a tenant-only `User::query()`.

2. Task 5's `pos_customer_aliases` migration specifies UUID columns, uniqueness, and indexes but not FK constraints to tenants/companies/partners (`:488-499`). The controller-side same-transaction creation and replay verification close the R2 blocker, but implementation review should verify FK/index choices are intentional and cross-company alias tests cover orphan/stale Partner rows.

3. The current plan still uses direct `Partner::query()` / Partner creation language in POS module controllers (`:386-393`, `:513-514`). This is consistent with the plan's cross-tenant scoping requirements, but implementation review should verify it does not violate the repository's module-boundary convention or should route through a Partner-owned public service/contract if that convention is enforced for this path.

## FINAL VERDICT

REQUEST-CHANGES. The R2 blocker and request-changes items are substantively resolved, but R3 has a Task 1/Task 8 canonical DTO sequencing and explicit-staging defect that should be corrected before approval.
