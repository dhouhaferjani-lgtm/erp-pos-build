# Codex slice-plan gate r1 — W-CASH-1 rev 1 (gpt-5.6-sol, high, read-only, 2026-09-06)

Input: docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md at 64bc7f84a. Verbatim.

---
Changes are required before dispatch. I found 3 BLOCKERs, 6 MAJORs, and 1 MINOR.

Audit baseline: current HEAD is `64bc7f84aa69d43d4d5131691e2c30ea78fb116b`. The cited production code is unchanged from the plan’s pinned baseline; the material drift is in owner rulings and the newly added staging manifest. No edits, tests, or git writes were performed.

## BLOCKER

1. Owner authority is stale and contradicted

- Plan: [lines 3, 27–35, 246, 254](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:27)
- Authority: [owner rulings 147–158](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:147)
- Failure: The plan repeatedly says Q11–Q13 are OPEN and their recommendations are not approvals. They are now RULED. An implementer could stop for decisions already made or treat the accepted defaults as optional.
- Minimum correction: Rebase the evidence header to current HEAD. Retain the Q11–Q13 benchmark wording if useful, but label each as RULED and accepted. State that this slice remains policy-neutral: it implements none of the drawer-session, typed-reason, or alignment policies. Replace every “remain open” checklist/deployment statement.
- Scope verdict: The actual tasks are policy-neutral and do not contradict the substance of the accepted defaults; only their authority/status wording is wrong.

2. Deployment section does not use the canonical manifest

- Plan: [lines 236–247](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:236)
- Manifest contract: [lines 265–287](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:265); rollout sequence [44–195](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:44)
- Failure: The plan falsely says the manifest is absent, re-derives eight deployment steps, omits the required reference sentence, and supplies none of the required per-slice variables. There is no exact flag/cutover mechanism, web fingerprint, push-collapse declaration, queue/device declaration, command marker, or env path. Because API pushes auto-deploy while the web is separately deployed, this can expose either a stale UI or the old documentless writer. A simple flag rollback could also re-enable the old writer after documents exist, violating the plan’s own invariant.
- Minimum correction: Replace the section with the manifest’s verbatim reference sentence and a complete variable table:

  - `<slice>`
  - ordered migrations with additive/self-guarding/prerequisite annotations
  - exact config key, env name, config file, and default-false flag(s)
  - command execution context and stable grep marker
  - Push 1/4 census mapping and pass/fail greps
  - web fingerprint string
  - device build: none
  - queues: none
  - collapsed pushes and reasons
  - verified env path

  Also define a forward-only writer cutover: while false, the new deployment must block transfer writes during the maintenance window; after any document is recorded, disabling the flag must refuse writes rather than fall back to the documentless implementation.

3. Replay authorization and semantic identity are not safe

- Plan: [lines 150–158, 188, 194, 204](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:150)
- Governing contract: [spec W1 139–141](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:139); current resolver behavior [LocationScopeResolver.php:31](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Company/Services/LocationScopeResolver.php:31)
- Failure scenarios:

  - A branch-A user submits a known branch-B operation UUID with an authorized A source. If the adapter authorizes only the request source before the service looks up the existing document, the result is a conflict/existence oracle rather than scoped 404 for the document’s actual source.
  - The request contract carries no configuration ID/revision, yet replay equality includes the currently derived configuration and source location. If custody configuration changes after a committed response is lost, the identical client retry becomes a conflict instead of `AlreadyRecorded`.

- Minimum correction: Specify one replay order. Under the company-operation lock, resolve any existing document first, authorize its persisted/current source custody, return 404 when inaccessible, then compare client-authored semantic fields. Server-derived configuration, location, and timestamps must either be taken from the original document on replay or excluded from the client semantic fingerprint. Add PG tests for cross-branch UUID replay returning 404 with unchanged snapshots and retry after configuration revision change returning the original document.

## MAJOR

1. T1 does not enforce tenant/company/actor consistency in compatibility topology

- Plan: [lines 61–78, 83–109, 119](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:61)
- Source: companies carry `tenant_id` at [create_companies:23–27](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2025_11_30_104000_create_companies_table.php:23); the PG lane defaults to compatibility mode at [phpunit-pgsql.xml:62](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/phpunit-pgsql.xml:62); staging topology remains unverified at [manifest U-2](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:295).
- Failure: A direct insert can store tenant A with company/location/repositories or `created_by` from tenant B, especially in compatibility mode. Boundary validation is not a complete relational schema guarantee.
- Minimum correction: Add composite tenant/company and tenant/actor enforcement through owned parent uniques plus composite FKs, or equivalent fail-closed PG triggers. Name direct-SQL wrong-tenant and wrong-actor test methods, SQLSTATE assertions, and unchanged-row assertions.

2. T2’s command path and operational contract do not match the module

- Plan: [line 140](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:140)
- Source: existing Treasury commands live under `Presentation/Console` and are registered at [TreasuryServiceProvider.php:236–245](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Providers/TreasuryServiceProvider.php:236), not the proposed `Presentation/Commands`.
- Failure: The implementation path and provider seam are ambiguous. Option combinations and deterministic output needed for fleet execution are unspecified.
- Minimum correction: Use `Presentation/Console/CashCustodyCensusCommand.php`, cite the exact provider import and registration lines, and define behavior for no options, tenant-only, tenant+company, invalid company-without-tenant, JSON shape, aggregate exit status, stable stdout marker, and whether it runs under `tenants:run`—it should not if it manages tenancy itself.

3. T2 promises repository-metadata protection without a writer or safe lock order

- Plan: [lines 127–142](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:127)
- Source: the current update path locks the repository first at [PaymentRepositoryController.php:216–228](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentRepositoryController.php:216), while the plan orders configuration saves as location → configuration → repositories.
- Failure: Calling the proposed validator from inside the existing repository lock can deadlock against a concurrent configuration save. Without an exact integration path, an enabled safe/bank default may also be invalidated by changing repository type, location, or active state.
- Minimum correction: Name the exact metadata-update service and controller changes, use one lock order for both flows, and enumerate protected mutations. Add PG tests for concurrent save versus repository update and for each invalidating mutation rolling back completely.

4. Red-first contracts and convention-09 coverage are incomplete per task

- Plan tests: [T1 119](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:119), [T2 142](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:142), [T3 164](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:164), [T4 180](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:180), [T5 204–206](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:204), [T6 214–232](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:214)
- Convention: [09 lines 37–50](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/09-SECOND-OF-EVERYTHING.md:37)
- Failure: T1’s direct-SQL tests are unnamed; T4 specifies first assertions for only two of seven methods; T5 specifies one of five backend assertions; T6 leaves migration-rerun, topology, several concurrency, and E2E assertions without exact test identities. CI lanes are inferred from config rather than named. Convention-09 is deferred generically to T2/T6 rather than mapped to every task touching the catalogue.
- Minimum correction: For every task, give exact file, class/test name, first failing assertion, exact command, and named lane. Add an explicit convention-09 line naming second-company, second-location, and rerun coverage—or a justified N/A—and do not close an earlier task gate on coverage deferred to T6. T3 is otherwise the best-complete red contract and should be preserved.

5. T5 lacks a complete HTTP contract and a hard W1 prerequisite

- Plan: [lines 188–204](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:188)
- Source: `treasury.manage_all_locations` does not exist at HEAD; the current permission catalogue around Treasury is [RolesAndPermissionsSeeder.php:260–266](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/RolesAndPermissionsSeeder.php:260). W1 requires its rollout at [spec:147](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:147).
- Failure: T5 cannot green at current HEAD without either silently absorbing W1 scope or inventing a temporary permission. The request rules, exact index mode names, document-read scope, response envelopes, and recorded/replayed/stale/legacy HTTP statuses are also unspecified.
- Minimum correction: Make an accepted W1 implementation/reviewer SHA a hard T5 prerequisite, or explicitly add the seeder/role/cache files to this slice. Provide exact paths and full rules for both new FormRequests, exact query parameter values for source/destination modes, read-scope semantics for documents visible through either repository, and exact response/status/error contracts.

6. Convention 11 migration stops while shadow types remain

- Plan: [lines 196–202](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:196)
- Convention: [11 lines 44–47](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md:44)
- Remaining production shadows include [SplitPaymentForm.tsx:27](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/treasury/SplitPaymentForm.tsx:27), [RecordPaymentModal.tsx:28](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/components/organisms/RecordPaymentModal/RecordPaymentModal.tsx:28), [AddRepositoryModal.tsx:41](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/components/organisms/AddRepositoryModal/AddRepositoryModal.tsx:41), and [paymentRepositoryApi.ts:9](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/pos/api/paymentRepositoryApi.ts:9).
- Failure: Introducing generated `PaymentRepositoryData` while converting only six files leaves multiple hand-rolled shapes beside the generated source of truth.
- Minimum correction: Census every `/payment-repositories` consumer. Replace structural interfaces with the generated DTO or generated-type `Pick` aliases. This can remain a type-only change for POS; it must not activate W2 scoping or alter device behavior.

## MINOR

1. Three citations are not exact enough

- [plan line 21](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:21) cites `RepositoryMovement.php:45`, which is only the timestamp comment; the actual append-only guards are [73–81](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Domain/RepositoryMovement.php:73).
- [plan line 186](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:186) cites `routes/index.tsx:1936`; the `RequirePermission` wrapper begins at [1937](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/routes/index.tsx:1937).
- [plan line 125](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:125) cites the provider’s `register()` declaration, not its command-registration seam.
- Minimum correction: Update those anchors while rebasing the plan. All other cited current-code anchors were present and context-correct at HEAD.

## Rejected false positives

- The implementation content does not add the Q11 drawer-session model, Q12 device reason-code enum/mapping, or Q13 alignment. The existing `MovementReasonCode` is the older adjustment vocabulary, not the ruled device cash-reason model.
- `allowWhileFrozen` is not a Q12 policy choice; it extends the already-shipped record-and-mark frozen replay contract required by the authoring scope.
- `repository_transfer_documents` does not improperly duplicate the unified sales/purchase `documents` table: it is a qualified Treasury evidence concept explicitly required by the scope and assigned one writer/surface.
- Same-GL transfers having two repository legs and no JE is intentional and matches current Treasury semantics.
- The existing raw Axios hook is not double-unwrapping. The plan correctly warns that conversion to `apiPost` must return its already-unwrapped result.
- The convention-10 table has the exact prompt-required eight-column format and all nine mandatory rows. ERPNext’s current documentation confirms Internal Transfer as a Payment Entry between source and destination cash/bank accounts; the Dolibarr page is sparse, but the authoring prompt explicitly supplied that benchmark fact. [ERPNext Payment Entry](https://docs.frappe.io/erpnext/payment-entry), [Dolibarr Banks and Cash](https://wiki.dolibarr.org/index.php/Module_Banks_and_cash).
- No cited production schema or service has silently gained the proposed custody/document types; every proposed path remains genuinely NEW.

## Preserve list

- Six-task limit and sequential T1 → T6 dispatch.
- The exact convention-10 matrix and two glossary concepts on the existing Treasury → Repositories surface.
- Company-scoped operation UUID plus independent server-generated transfer group.
- One transaction, exactly two cross-linked legs, zero/one posted JE, immutable original, and linked full reversal.
- Frozen internal record-and-alert behavior with HTTP unable to request the override.
- Adapter-only user/location authorization and an explicit service intent.
- Numeric-string money, generated DTOs, tenant-scoped query keys, and single-unwrapped API helpers.
- Real registration, real second company, selected second POS location, explicit rerun outcomes, PG constraints/concurrency, and the ratchet.
- Policy-neutral exclusion of shift booking, v2/v3 adapters, W7, variance activation, reason-code policy, drawer sessions, and alignment.
- Forward-only rollback that preserves all committed documents, movements, and journal entries.
- The two unrelated untracked files already in the worktree; they were untouched.

## Owner decisions required

None. Q11–Q13 are ruled. Manifest U-1/U-2 are operational environment facts to verify before promotion, not owner policy decisions.

VERDICT: CHANGES-REQUIRED