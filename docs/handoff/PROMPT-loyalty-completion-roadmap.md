# PROMPT — Loyalty Completion: Roadmap + Build (dedicated session)

> Owner-requested 2026-07-05. Paste this into a fresh session run from
> `apps/erp/`. Goal: produce a roadmap/backlog to finalize loyalty for the
> parapharmacy launch, then build the two owner-decided items. Boot per
> `docs/factory/WORKFLOW.md` (read it first), work in an isolated worktree off
> the current `origin/dev` tip, PRs into dev per the dark-factory spec
> (`docs/superpowers/specs/2026-07-05-dark-factory-coordination-design.md`).

## Ground truth (verified 2026-07-05 by a read-only trace — re-verify file:line before building)

Enrollment exists in two disconnected layers:
- **Web admin** (`apps/web/src/features/loyalty/`): full CRUD — programs, members,
  `EnrollMemberModal`, earning rules/rewards/tiers. Routed under
  `ModuleGuard module="Loyalty"` + `loyalty.view|loyalty.manage`
  (`apps/web/src/routes/index.tsx:2728-2822`), sidebar → Customers & Marketing.
- **Tauri POS auto-enroll (silent)**: attaching a customer WITH A PHONE to the cart
  calls `POST /loyalty/pos/balance` → `PosLoyaltyBalanceService::ensureAndGetBalance`
  (`apps/api/app/Modules/Loyalty/Application/Services/PosLoyaltyBalanceService.php:37-80`)
  which findOrCreates the member and enrolls into the tenant's single ACTIVE program.
  No phone or no active program → permanent silent `notEnrolled`.

Known gaps (evidence in the 2026-07-05 trace):
1. **No active LoyaltyProgram is seeded anywhere** for the parapharmacy tenant —
   `ParapharmacySeeder.php:473` enables the Loyalty extra but creates zero
   programs/earning rules. Without an active program, EVERY earn/balance/enroll
   call silently no-ops. (Biggest launch blocker.)
2. **Cashiers can't enroll**: only admin/manager have `loyalty.manage`
   (`RolesAndPermissionsSeeder.php:483`); cashier role (`:507-543`) has neither
   `loyalty.view` nor any enroll permission; POS UI has no enroll button at all.
3. **No loyalty surface on the customer record** (`PartnerDetailPage.tsx` — zero
   loyalty integration); staff must know to visit the separate Loyalty Members page.
4. **Phone is a hard requirement** in both paths (`CreateMemberRequest.php:37-42`).
5. **Double-earn race**: BOTH `PosCoreReceiptProjection::earnLoyaltyPoints` (sync)
   and the queued `EarnPointsOnReceiptCompleted` listener (`EventServiceProvider.php:73-75`)
   earn for the same receipt with identical dedupe keys → loser logs errors, and
   item/category-rule calculation can differ by which path wins. Quiet fix wanted.
6. **Pay-with-points exists ONLY in the browser POS** (`apps/web/src/features/pos`
   `LoyaltyRewardSelector`); the Tauri POS (`apps/pos`) has zero `/loyalty/pos/redeem`
   callers. Phase 2 — confirm launch relevance with owner before building.

## Owner decisions ALREADY MADE (build these, don't re-ask)

- **A proper enrollment flow in the boss (web admin) application** — including a
  loyalty section/enroll action on the customer record itself, not just the
  separate Loyalty Members page.
- **Cashiers get enrollment rights BY DEFAULT** — add the appropriate permission
  to the cashier role seeder (decide the right permission shape: a narrow
  `loyalty.enroll` vs granting `loyalty.manage`; prefer narrow) and expose an
  enroll affordance where the cashier works. Remember the Spatie permission
  cache is tenant-blind (memory `project_spatie_permission_cache_tenant_blind`):
  any permission addition needs `permission:cache-reset` on deploy.

## Task

1. **Roadmap first**: write a backlog doc (`docs/superpowers/specs/…loyalty-launch-roadmap.md`)
   ordering everything needed for a parapharmacy ("power pharmacy") to run
   loyalty end-to-end: program seeding/config UX, the two decided items above,
   customer-record surface, phone-requirement policy, double-earn fix,
   pay-with-points (Tauri) go/no-go, enrollment during POS checkout, staleness
   of `project_loyalty_*` memories. Mark each item: launch-blocking vs post-launch.
2. **Adversarial review of the roadmap+plan** before building (standing rule).
3. **Build the launch-blocking set**, TDD, respecting rule 12 (module gating both
   layers), rule 19 (precision — points math must not float), and the reviewer
   gate: `tenancy-authz-reviewer` for the permission change; `fiscal-pos-reviewer`
   for anything touching the POS earn path.
4. PRs into dev; update the factory board (`../erp.board` worktree) if it exists.

## Related-but-separate (do NOT fold in; they get their own sessions)

- Otospex/automotive vertical commonality (procurement/RFQ reuse, auto specs,
  spare-part retailers, repair garages) — owner session slated 2026-07-06.
- Catalog search + techdoc ingestion pipeline verification — parent `syneriva`
  repo, separate session.
