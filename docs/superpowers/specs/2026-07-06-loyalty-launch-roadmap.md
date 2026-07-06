# Loyalty Launch Roadmap — Parapharmacy E2E

> Session: loyalty-completion 2026-07-06 (board T-0002, branch `feat/loyalty-launch`,
> worktree `../erp.loyalty` off origin/dev `cd7b8aefa`). Source ask:
> `docs/handoff/PROMPT-loyalty-completion-roadmap.md` (owner-requested 2026-07-05).
> Ground truth below re-verified 2026-07-06 against the dev tip by two read-only
> traces (backend + frontend); every claim cites file:line actually read.

## Goal

A parapharmacy tenant runs loyalty end-to-end at launch: an active program with
earning rules exists, staff can enroll customers (boss app + POS), points earn
exactly once per sale, and the customer record shows loyalty state.

---

## 1. Verified ground truth (2026-07-06) — corrections vs the 2026-07-05 prompt

What the prompt got right, and what has to be corrected before building:

| # | Prompt claim | Verdict 2026-07-06 |
|---|---|---|
| G1 | No seeder creates a LoyaltyProgram | **CONFIRMED** — `grep LoyaltyProgram::create database/` = 0 hits. `ParapharmacySeeder.php:473` enables the `Loyalty` extra only; `DemoPharmacySeeder` doesn't even enable the extra. **BUT**: `SeedDefaultEarningRuleOnProgramActivated` (registered listener) auto-seeds a 1-TND=1-point Spend rule when a program is ACTIVATED via `ProgramManagementService::activateProgram` — so an admin who creates+activates a program in the web UI gets working earn with zero extra config. The blocker is seeding only. |
| G2 | Cashiers can't enroll | **NUANCED** — the entire `loyalty/pos/*` subtree (balance/lookup/preview/earn/redeem) is gated `can:pos.operate_terminal` (`Loyalty/Presentation/routes.php:189-211`), which cashiers HAVE (`RolesAndPermissionsSeeder.php:525`). So POS attach-time auto-enroll already works for cashiers (customer synced + has phone). What cashiers lack: any `loyalty.*` permission (`:507-540`), so all admin surfaces (`/loyalty/members`, enroll endpoint `can:loyalty.manage`) are closed; and the Tauri POS has NO enroll affordance — `CustomerLoyaltyBadge.tsx:39-41` renders inert "joins on purchase" text when `!enrolled`. |
| G3 | No loyalty on customer record | **CONFIRMED** — `PartnerDetailPage.tsx` has zero loyalty references. Plug-in pattern exists: `showVehiclesTab`/`showDepositsTab` (`hasModule(...)` + customer-type, lines 206-210) + hook + modal triple (deposits tab). |
| G4 | Phone hard-required both paths | **SPLIT** — `CreateMemberRequest.php:39-46` requires phone (unique per tenant, soft-delete aware). POS balance path takes `phone` as `nullable` (`PosBalanceRequest.php:19-24`); no phone → silent `notEnrolled` (`PosLoyaltyBalanceService.php:55-57`). Phone is the member identity key (`UNIQUE(tenant_id, phone)`). |
| G5 | Double-earn race, sync vs queued | **WORSE than claimed** — BOTH paths are queued jobs racing on different queues: `PosCoreReceiptProjection::earnLoyaltyPoints` (`:863-895`, via `ApplyFiscalEventProjectionJob` on `fiscal-projections`) and `EarnPointsOnReceiptCompleted` (on `default`, registered `EventServiceProvider.php:73-75`, fired from `ReceiptPaymentService.php:446` in `DB::afterCommit`). Dedupe = TOCTOU `whereJsonContains(metadata->source_type/source_id)` scan (`EloquentTransactionRepository.php:65-70`) with **no unique DB constraint** → true concurrent double-credit is possible, not just noisy logs. **Calculation divergence**: the projection path sends `'items' => []` (`SaleEarningService.php:59`) so Item/Category/Quantity rules compute ZERO there, while the listener path builds real line items (`EarnPointsOnReceiptCompleted.php:69-84`). The `SaleEarningService` docblock claims the listener is "retired" — it is NOT; both are live. |
| G6 | Pay-with-points is web-POS-only | **CONFIRMED but MOOT** — `POST /loyalty/pos/redeem` exists server-side (`LoyaltyPOSController::redeem:172-218`, tenant-isolation guarded). Its only FE caller, `LoyaltyRewardSelector`, lives in `POSPage.tsx` which is **unrouted/orphaned** — `routes/index.tsx` never mounts it, and a code comment (~:2637) records the owner is removing the web POS. `lookupMember` (`loyaltyApi.ts:64`) has zero callers. Web POS loyalty is dead code; the Tauri POS is the only live checkout and has zero redeem callers. |

Additional facts that shape the design:

- **Enroll contract today**: `POST /loyalty/members/{memberId}/enroll` (`can:loyalty.manage`) takes a *loyalty member id*; there is **no** lookup-by-partner endpoint and no enroll-by-partner shortcut — the boss-app flow must create the `LoyaltyMember` (phone required) first.
- **"Single active program" is not an invariant**: `activateProgram` never deactivates others; `findByTenantAndStatus(...)->first()` silently picks the newest of several active programs.
- **FE permissions are a hardcoded map**: `apps/web/src/hooks/usePermissions.ts` (role→permissions const; `loyalty.view|manage` = admin+manager, `:161-162`). Any new permission must be added there AND in the backend seeder.
- **Tauri POS has no RBAC layer** — only `useHasModule('Loyalty')` (`productStore.ts:116-131`). POS-side gating must ride `pos.operate_terminal` server-side.
- **Points math is bcmath end-to-end** (`PointEarningService`, `EarningProcessingService`), with three known float boundaries: `applyDailyCap(float $alreadyEarnedToday)`, `PointsEarnedV2 amount: (float)`, and the intentional preview wire float.
- **Spatie permission cache is tenant-blind** ([[project_spatie_permission_cache_tenant_blind]]): any permission addition needs `permission:cache-reset` per deploy.

---

## 2. Backlog

Ordering = build order. **LB = launch-blocking** (a parapharmacy cannot run
loyalty correctly without it), **PL = post-launch**.

### LB-1 — Seed an active program for launch/demo tenants ⛔ biggest blocker

Without an active program EVERY earn/balance/enroll call silently no-ops.

- `ParapharmacySeeder`: create a `LoyaltyProgram` for the tenant and activate it
  **through `ProgramManagementService::activateProgram`** (so `ProgramActivated`
  fires and `SeedDefaultEarningRuleOnProgramActivated` seeds the Spend rule) — or
  create program + Spend rule explicitly if service wiring inside a seeder is
  awkward; either way the seeded tenant must end with 1 ACTIVE program + 1 active
  Spend rule.
- `DemoPharmacySeeder` (the launch-demo account, [[project_demo_pharmacy_account]]):
  add `Loyalty` to `enabled_extras` + same program seeding.
- Real (non-seeded) tenants: covered — admin creates+activates via existing UI;
  activation auto-seeds the Spend rule. No new config UX needed for launch (PL-6).
- Tests: seeder test asserting active program + active Spend rule exist; POS
  balance call against seeded tenant returns `enrolled` (not the no-program branch).

### LB-2 — Boss-app enrollment on the customer record (owner-decided)

- **Backend**: new `GET /loyalty/members/by-partner/{partnerId}` (reuse
  `MemberResolver::resolveByContactOrPartner`) returning member + enrollments +
  balance, or `404`-shaped `not_a_member`; new `POST /loyalty/partners/{partnerId}/enroll`
  that find-or-creates the member (phone: prefilled from partner, overridable,
  required) and enrolls into the chosen (default: single active) program —
  transactional, reusing `PosLoyaltyBalanceService::findOrCreateMember` semantics
  (soft-delete restore, phone-collision re-point). Permission: both new
  endpoints gated `can:loyalty.enroll` (LB-3) — `can:` middleware can't OR and
  Spatie permissions don't imply each other, so `loyalty.enroll` is granted
  explicitly to cashier AND manager (admin syncs all). Module-gated
  `module:Loyalty` (rule 12).
- **Frontend**: Loyalty card on `PartnerDetailPage` overview grid (pattern: the
  deposits hook+tab+modal triple) — shows member status/points/tier or an Enroll
  button; enroll modal = program (defaulted) + phone (prefilled from partner).
  Gated `hasModule('Loyalty')` + customer-type + `loyalty.view` for display,
  `loyalty.enroll` for the action (rule 12: both layers).
- Tests: BE feature tests (lookup 200/not-member, enroll creates member+enrollment,
  duplicate-enroll idempotent, phone collision, permission matrix, module gate);
  FE Vitest for card render states + gating.

### LB-3 — `loyalty.enroll` permission for cashiers + POS enroll affordance (owner-decided)

- **Permission (narrow, per owner preference)**: add `loyalty.enroll` to
  `RolesAndPermissionsSeeder`; grant to `cashier`, `manager`, `admin` (admin gets
  all anyway). Gate the LB-2 endpoints with it (`can:loyalty.enroll`). Do NOT
  grant `loyalty.manage` to cashiers. Update the FE `usePermissions` hardcoded
  map + `MODULE_PERMISSIONS`. Deploy note: `permission:cache-reset` + per-tenant
  seeder sync (recurring staging gap, WORKFLOW.md Stage 7).
- **POS enroll affordance (where the cashier works = Tauri POS)**: NO new
  backend endpoint needed. `POST /loyalty/pos/balance` (gated
  `can:pos.operate_terminal`, which cashiers have) already find-or-creates the
  member and enrolls when given a phone (`PosLoyaltyBalanceService:52-66`) —
  today the POS only ever sends `customer.phone`, so a phone-less customer is
  permanently `notEnrolled`. The affordance: when an attached synced customer
  shows `!balance.enrolled && balance.rate !== null` (rate present = active
  program exists; not-enrolled + synced ⇒ the customer record has no phone),
  replace the inert "joins on purchase" text in `CustomerLoyaltyBadge` with an
  Enroll action opening a phone-capture dialog that re-calls
  `/loyalty/pos/balance {partner_id, phone: <typed>}` and refreshes the badge.
  The typed phone lands on the loyalty member only — the partner record is not
  mutated. Offline or `pending_create` customer → affordance hidden (online-only,
  per [[project_loyalty_pos_offline]] owner decision). **Known risk to surface
  in review**: `findOrCreateMember` re-points an existing member on phone match
  (phone = identity, by design); a cashier-typed wrong phone can re-point
  another customer's member. Accepted for launch (same semantics as attach-time
  auto-enroll); revisit with a collision-confirm UX post-launch.
- Tests: permission matrix feature test (cashier CAN enroll via both POS and
  partner-enroll endpoints, CANNOT hit `loyalty.manage` surfaces); Vitest for the
  badge affordance states.

### LB-4 — Double-earn quiet fix (board T-0005; fiscal-pos-reviewer gate)

Flow trace (2026-07-06) settled the design. `POST /pos/receipts` and
`POST /pos/receipts/{id}/payments` are **retired** (`POS/routes.php:145-168` —
"device-authored, ingested via /pos/sync/fiscal-events"), so `ReceiptCompleted`
(fired only from `ReceiptPaymentService.php:446`) reaches the listener only for
server-authored receipt flows that still call it (e.g. the exchange path).
Device (Tauri) sales earn ONLY via the projection; server-authored receipts
earn ONLY via the listener (projection's `insertReceiptOnConflictDoNothing`
returns null on the pre-existing row → early return before earn). **Do NOT
deregister the listener** — it is the sole earn path for server-authored sales.
The real defects and the fix:

1. **Make dedupe atomic**: dedicated nullable `source_type`/`source_id` columns
   on `loyalty_transactions` (backfilled from `metadata`), partial unique index
   `(enrollment_id, source_type, source_id) WHERE transaction_type='earn' AND
   source_type IS NOT NULL` (source-type-scoped, per
   [[reference_journal_entries_no_global_source_uniqueness]]). Unique-violation
   is caught and rethrown as the existing "already earned" duplicate signal.
2. **Quiet both paths**: the listener currently `Log::error`s benign duplicates
   (`EarnPointsOnReceiptCompleted.php:95-101`); give it the same
   discriminate-and-swallow treatment `SaleEarningService.php:74-90` already has.
   Fix its rule-19 float casts (`(float) $line->unit_price`,
   `(float) $event->totalAmount`) to strings while touching it. Correct the
   stale "retired listener" docblock on `SaleEarningService`.
3. **Close the rule-coverage divergence**: enrich `SaleEarnContext` with line
   items (product_id, category_id, quantity — price is unused by every rule
   type) built from `SaleReceiptCanonicalView::lineItems()` so Item/Category/
   Quantity rules compute in the projection path (today it sends `items: []`
   and silently earns zero for those rules on every device sale).
- Tests: replay/idempotency (same fiscal event twice → one Earn txn, rule 20),
  constraint-path duplicate → no error log, item-rule earn through the
  projection path, refund/void/training still earn nothing.

### LB-5 — Phone-requirement policy (decision, not a build item)

**Keep phone as the member identity key for launch** (UNIQUE(tenant,phone) is
load-bearing: POS auto-enroll, collision re-pointing, offline QR seam). The
launch mitigation is affordances, not schema: LB-2 prefills phone from the
partner record; LB-3 captures it at the POS. Relaxing to phone-or-email is PL-7.

### Post-launch

- **PL-1 — Pay-with-points on the Tauri POS.** Server redeem endpoint is ready
  and tenant-guarded; web-POS caller is dead code. **OWNER GO/NO-GO** for launch
  scope — recommendation: post-launch (earn-only at launch; redemption adds
  tender-flow + fiscal questions the launch doesn't need).
- **PL-2 — Web POS loyalty dead-code sweep**: `POSPage` unrouted, `lookupMember`
  uncalled, `LoyaltyRewardSelector`/`LoyaltyMemberBadge` orphaned with it. Remove
  or quarantine alongside the owner's web-POS removal.
- **PL-3 — Single-active-program invariant**: `activateProgram` should deactivate
  siblings (or the enroll paths should stop silently picking `.first()`).
- **PL-4 — Precision touch-ups**: `applyDailyCap(float $alreadyEarnedToday)`
  boundary; `PointsEarnedV2 amount: (float)` payload; `FALLBACK_SCALE=3` silently
  assumes TND-style currency in queue contexts.
- **PL-5 — Tauri POS RBAC layer** (permission checks, not just module flags).
- **PL-6 — Program config/onboarding UX** (nudge tenant admins without a program).
- **PL-7 — Phone-or-email membership identity** (schema + resolver change).

### Memory staleness (close-out chore, this session)

`project_loyalty_earn_demo_cutoff` / `project_loyalty_pos_offline` statuses are
correct post-2026-07-05 correction (merged), but both predate: the web-POS
orphaning, the pos.operate_terminal gating nuance, the double-earn TOCTOU/
divergence findings, and this roadmap. Update both + MEMORY.md Active Work line
at Stage 8.

---

## 3. Launch-blocking build set (this session)

LB-1 → LB-2 → LB-3 → LB-4 (LB-5 is a recorded decision). Reviewer map
(WORKFLOW.md Stage 4): LB-2/LB-3 permission + route changes →
`tenancy-authz-reviewer`; LB-4 (+ any LB-1 touch of the earn path) →
`fiscal-pos-reviewer`. Board: T-0002 (this session) + claim T-0005 when LB-4
starts. PRs into dev per the dark-factory spec; laptop = scoped preflight only.

## 4. Open owner questions (do not block the build)

1. PL-1 go/no-go: pay-with-points at the Tauri POS for launch? (Recommended: no.)
2. LB-2 default program choice UI when multiple programs are active (rare until
   PL-3): default to newest-active with a visible program name, or force a pick?
