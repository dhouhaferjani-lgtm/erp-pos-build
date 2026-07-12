# CODEX brief — launch polish: verified small fixes (no design decisions needed)

> Items verified against code 2026-07-12 (TODO sweep + launch-readiness verification agents). Fresh worktree: `git worktree add ../erp.launch-polish -b chore/launch-polish origin/dev`. TDD where behavior changes; tests by path only; one Opus gate at the end (verdict file `docs/handoff/gate-reviews-polish/LAUNCH-POLISH-rc<N>.md`). Do NOT push/merge; leave worktree intact and report done.

## 1. `Tables` module route gate (the last genuinely ungated vertical module)

`apps/api/app/Modules/POS/routes_tables.php` (floors/tables CRUD) carries only `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class]` — NO `module:` gate, unlike the 9 gated modules. Add `module:Tables` following the exact pattern of `apps/api/app/Modules/Menu/Presentation/routes.php`. Check `config/verticals.php` lists Tables for the right verticals (restaurant-flavored IziPOS), and gate the FE surface if one exists (`RequirePermission moduleKey`/`hasModule` per `docs/architecture/vertical-module-gating.md`). Feature test: route 403s for a vertical without the module, 200s with it (mirror an existing module-gating test).

## 2. Loyalty rule-edit strips conditions (plain FE bug, no policy decision)

`apps/web/src/features/loyalty/components/EarningRuleFormModal.tsx` `handleSubmit` (~:155-165) rebuilds `conditions` with ONLY `min_purchase_amount` — product_ids, category_ids, excluded_product_ids, time window, day_of_week, min/max_quantity, tier_ids are silently DROPPED on every edit-save, and the backend `$rule->fill($request->validated())` (`EarningRuleController.php:105`) overwrites the full stored condition set. Fix MINIMALLY: on edit, merge the submitted fields into the rule's EXISTING conditions (spread the loaded rule's conditions, override only the fields the form actually edits) so unedited conditions survive. Do NOT build a full condition editor (separate, owner-scoped work). Vitest: load a rule with product_ids + time window, edit min_purchase_amount, assert the payload still carries the untouched conditions.

## 3. Stale refund-flow comment (comment-only, actively misleading)

`apps/pos/src/lib/refundFlow/refundConfirmation.ts:14-24` — the docblock claims the backend collapses `ManagerOverrideRequiredException`/`DailyRefundCapExceededException`/`RefundWindowClosedException` into generic `BUSINESS_ERROR`. FALSE since `apps/api/bootstrap/app.php:231-273` registered dedicated typed-code 422 handlers for all three (plus `RefundDestinationNotAllowedException`). Correct the comment to state the real contract (typed codes available). No code change.

## 4. Batch-expiry `failed()` operator alert (log-only today)

`apps/api/app/Modules/BatchExpiry/Jobs/DailyExpiryCheck.php:219` — the `failed()` handler only `Log::error`s; a dead daily expiry check is invisible. Minimal fix: also report to the existing error-visibility channel used elsewhere in the codebase (check how other critical jobs surface failures — e.g. `report($exception)` so the exception hits the standard handler/monitoring). Do NOT build the company-admin notification feature (`:65-70` TODO) — that lands on the Phase ③ notification center after it merges; leave that TODO with a pointer comment `// TODO(phase3-notification-center): route through platform notifications once merged`. Also fix the `app(PermissionRegistrar::class)` call in this job to constructor injection (rule 13) while touching it.

## Out of scope

- Touch Mode toggle (owner decision pending: give it behavior vs hide it).
- Appointments `module:Workshop` mis-scoping (needs owner decision on the module split).
- Batch-expiry company-admin notifications (Phase ③ notification-center dependency).
- Loyalty stacking policy + `reward_type` semantics (owner decisions).

## Verification before the gate

`pnpm typecheck` + `pnpm lint` (0 errors, audits 0 new) + targeted vitest; backend phpunit by path for touched tests; phpstan on touched modules; pint on touched files.
