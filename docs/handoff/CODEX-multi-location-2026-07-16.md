# CODEX DISPATCH BRIEF — Multi-Location Management (§1–§4), long-running autonomous build

> **Date:** 2026-07-16 · **Controller:** Claude (Fable) session, owner: Houssam
> **Mission:** implement ALL FOUR plans end-to-end in long-running sessions, self-verifying with severity-tiered adversarial reviews (Opus default, Fable escalation), Playwright e2e + visual testing, until everything is CONFIRMED working. The controller then independently verifies and merges. **Use Codex Desktop** (CLI-brokered Codex cannot write to `apps/erp.*` worktrees — known limitation).

## Authority chain (read in this order before writing any code)

1. `docs/superpowers/specs/2026-07-16-multi-location-management-design.md` — spec **Rev 2**. The law.
2. `docs/superpowers/specs/reviews/2026-07-16-multi-location-design-review.md` — findings register already folded into Rev 2. If a plan step seems to contradict a finding, the finding + spec win; stop and flag.
3. The four plans (execution order below). Every task = TDD checkbox steps; tick them in the file as you complete them (commit plan-file updates alongside code).
4. `docs/superpowers/plans/reviews/2026-07-16-multiloc-plans-codex-review.md` — pre-dispatch plan review; any APPLIED fixes there supersede plan text.
5. Repo law: `apps/erp/CLAUDE.md` (rules 1–21), `docs/conventions/*`.

## Workspace & branch contract

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp
git worktree add ../erp.multiloc -b feat/multi-location origin/dev   # base on origin/dev, fresh
```
- ALL work in `../erp.multiloc` on `feat/multi-location`. Sub-branches per package optional (`feat/multiloc-1-foundation` etc.) but merge them into `feat/multi-location` at each gate.
- **NEVER push to `origin/dev`** (push = staging auto-deploy incl. `tenants:migrate`). Never force-push anything. Push `feat/multi-location` to origin as backup after each gate: `git push origin feat/multi-location`.
- Tag each passed gate: `multiloc-gate-1` … `multiloc-gate-5`.
- Worktree gotchas (memory-verified): symlinked vendor runs stale code — use a real `composer install`; `php artisan typescript:transform` needs `CACHE_STORE=array`; run backend tests BY PATH.

## Execution order & waves

| Wave | Plan | Gate |
|---|---|---|
| 1 | `2026-07-16-multiloc-1-scope-foundation.md` (10 tasks — membership backfill FIRST, enforcement LAST) | Gate 1: tenancy-authz + frontend-conventions |
| 2a ∥ 2b | `…-2-inventory-visibility.md` ∥ `…-3-financial-location-dimension.md` (both consume §1 contracts; run sequentially if a single session, order 2→3) | Gate 2: inventory-costing + frontend-conventions · Gate 3: treasury + frontend-conventions |
| 3 | `…-4-analytics-dashboards.md` (Task 6 widgets LAST — depends on §2/§3 endpoints) | Gate 4: frontend-conventions (+treasury if touched) |
| 4 | Whole-branch final review + full verification sweep | Gate 5: ALL four reviewers + Fable final |

## Adversarial gates — severity-tiered, automatic (owner directive)

Reviews are run headlessly via the Claude Code CLI from the worktree. Write each gate request to a file, then:

```bash
# Standard gate (Opus — the default for every milestone):
claude -p "$(cat .gates/gate-N-request.md)" --model claude-opus-4-8 > .gates/gate-N-verdict.md

# Fable escalation (see matrix):
claude -p "$(cat .gates/gate-N-request.md)" --model claude-fable-5 > .gates/gate-N-verdict.md
```

Gate request file must contain: the reviewer persona to adopt (copy the matching agent definition from `.claude/agents/<reviewer>.md`), the branch diff scope (`git diff origin/dev...HEAD -- <paths>`), the plan file, and the demand for verdict APPROVE/REJECT with file:line evidence. Commit `.gates/` files — they are the audit trail.

**Severity matrix (when to escalate to Fable `claude-fable-5`):**
- Default: **Opus** for every task-batch and wave gate.
- **Fable** when ANY of: (a) a reviewer issues a BLOCKER you believe is wrong (dispute resolution); (b) two consecutive REJECTs on the same gate (deadlock break); (c) the diff touches the financial spine — §3 attribution rules, instrument freeze rule, reconciliation tests, any migration or backfill — those wave gates are Fable directly; (d) Gate 5 final whole-branch review (Fable, after the four Opus lanes pass).
- A REJECT is never argued away in-session: fix, or escalate per the matrix. Never weaken a test to pass a gate.

## Non-negotiable verification (every wave, before its gate)

1. **TDD per plan step** — failing test first, by-path runs only. **NEVER run the full PHPUnit suite** (crashes the machine). Postgres for aggregate tests (plans specify).
2. **Static + style:** `./vendor/bin/phpstan` (level 8, zero NEW errors on touched paths), `./vendor/bin/pint --test`, `pnpm lint && pnpm typecheck`.
3. **Type flow:** `CACHE_STORE=array php artisan typescript:transform` after any DTO change; §4 Task 1 additionally requires `git diff --exit-code packages/shared/types/` (byte-identical DTO bound).
4. **Playwright e2e** (`apps/web/e2e/`, config exists): add the specs each plan's Gates section names — restricted-manager scoping, scope persistence, matrix + threshold round-trip, transfer suggested-source, receive destination, cash-position-by-location incl. Unattributed, échéancier scope filter, analytics scope, widget drill-through. Run headed against the local stack (`reference_local_db_per_tenant_demo_launch.md` recipe: API :8010, demo tenant owner@pharmabio.tn/password) with seeded multi-location data.
5. **Visual testing:** Playwright screenshots of every new/changed surface in **light AND dark** at desktop + tablet widths; store under `.gates/visual/gate-N/`; eyeball for token violations (no hardcoded colors), RTL spot-check (`ar` locale) on the scope picker + matrix. Screenshots ship with the gate request.
6. **End-to-end confirmation rule (rule 5):** a task is done when the critical path is DRIVEN and OBSERVED (a real transfer created with a suggested source; a real payment landing in the right location bucket) — never when it merely compiles.

## Hard constraints (violations = stop and flag, do not improvise)

- Migration ordering is load-bearing: §1 membership backfill BEFORE any enforcement activation. §3 ships in two deploy waves: 3a (repository-location UI + schema + writer attribution, deploy-safe) → owner assigns repositories → the backfill runs as a MANUAL command (`treasury:backfill-location-attribution`, never an auto-running migration) → 3b surfaces. All migrations idempotent + self-guarding (push=deploy on the eventual dev merge).
- Scope semantics are absolute: every location-scoped endpoint ALWAYS calls `LocationScopeResolver::resolve()` and ALWAYS applies the returned ids — no-param requests included; empty request = full allowed set, never "unfiltered". The ONLY bypass permission in the program is the existing `replenishment.process`; §2/§3/§4 pass `null`. Unattributed (location-NULL) financial rows are visible only to unrestricted users.
- No `documents.location_id` migration/backfill (it exists; Rev 1's mistake — see review T1). No `expense_metadata.location_id`. `payment_allocations.location_id`/`journal_entries.location_id` stay inert.
- §4 POS analytics: filter only, DTO shapes byte-identical (F6).
- Rules 19/20 everywhere money/quantity moves: bcmath, injected scale resolver, explicit currency in queued bridges, strings on the wire, MoneyInput/QuantityInput.
- Module boundaries (rule 6), constructor injection (rule 13), enums (rule 9), `t()` (rule 11), tokens (rule 18), tenant-scoped + location-scoped query keys (rule 14 + §1 contract).
- Permission changes: seed + note `permission:cache-reset` in the deploy checklist (tenant-blind cache platform bug).
- Coordinate the §3 bypass-permission names with what §1 actually seeds (plan 3 deploy checklist item 4) — one canonical set, no duplicates.

## Deliverables at the end (Gate 5 passed)

1. `feat/multi-location` pushed to origin (NOT dev), all gate tags, `.gates/` audit trail incl. visual archives.
2. Consolidated deploy checklist `docs/handoff/multiloc-deploy-checklist.md` (merge the per-plan checklists; stacks on treasury ③/④ + location owes; ordering: membership backfill → repository assignment (OWNER manual step) → financial backfill → permission reseed + cache-reset → verify).
3. All four plan files fully checkbox-ticked; a `HANDBACK-multiloc.md` note: what shipped, deviations (each with its gate approval), known follow-ups, exact verify commands for the controller.
4. **STOP.** The controller session takes over: independent verification, merge to local dev, batched promotion to origin/dev.

## If blocked

Deadlocked gate after Fable escalation, broken assumption in a plan, or an environment failure you cannot fix → write `BLOCKED-multiloc.md` (what, evidence, options) at the worktree root, commit, push the branch, and stop that wave. Continue any independent wave if one exists.
