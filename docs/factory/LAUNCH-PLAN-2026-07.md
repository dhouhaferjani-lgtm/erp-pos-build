# LAUNCH PLAN — July 2026 (living doc)

> Goal: a stable system to onboard the first parapharmacy customer
> ("Bill the Standard" — spelling UNCONFIRMED, owner to verify) within 2–3 days,
> across three parallel tracks. Maintained by the orchestrator; item state also
> lives on the `factory/board` branch (this doc is the map, the board is the queue).
> Inputs: `docs/handoff/HANDOVER-next-session-2026-07-05.md`,
> `docs/superpowers/audits/2026-07-05-unmerged-work-ledger.md`,
> `docs/handoff/OWNER-BRIEF-2026-07-04-decision-gates.md`,
> dark-factory spec `docs/superpowers/specs/2026-07-05-dark-factory-coordination-design.md`.

Legend: **Owner?** = needs the owner physically/decisionally. **VPS-safe?** = Linux-portable + test/Playwright-verifiable.

## Track A — VPS-autonomous

| Item | Owner? | VPS-safe? | Deps | Reviewer(s) | Done when |
|---|---|---|---|---|---|
| VPS environment completion (Codex CLI, playwright chromium, local stack, gh auth, dev-push-guard hook, board worktree) | no (creds handoff once) | — (IS the VPS) | dark-factory Phase 1 merged | orchestrator | boot prompt dry-run claims + completes a docs task end-to-end |
| Dashboard-demo branch rebase onto dev + PR (`feat/owner-dashboard-demo` @ `414db3771`, 183 behind) | no | yes | none | treasury-reviewer (reports/GL adjacent) | PR green vs current dev, reviewer APPROVED |
| `feat/db-per-tenant-deploy` rebase + PR (pgbouncer, DirectPostgreSQLDatabaseManager) | merge call only | yes | none | tenancy-authz-reviewer | PR green, reviewer APPROVED, owner merge call |
| Loyalty double-earn race fix (sync projection vs queued listener, same dedupe key) | no | yes | loyalty roadmap session scoping it | fiscal-pos-reviewer | single earn path proven by test |
| Full-PG suite burn-down continuation (make PG primary for DB-touching modules) | no | yes (full suites SAFE there) | none | — | PG suite green or failures triaged REAL-BUG vs TEST-DEBT |
| Screenshot sweep first full run (all web routes, seeded tenant) | no | yes | Phase 2 merged | — | gallery artifact + zero blank/error pages |
| Unified imports phases 3+ (spec FINAL v3) | no | yes | none | imports-reviewer | per-phase plan gates |
| Supplier-invoice web CREATE UI (E2E-confirmed gap; `useCreateSupplierInvoice` unused) | no | yes | coordinate w/ scan-flow session (P2P owns domain) | imports- or treasury-reviewer | invoice creatable in browser E2E |
| Sweep: design-token migration backlog (rule 18) via sweep generator | no | yes | Phase 2 | — | sweep YAML rows all done/n-a |

## Track B — Laptop / owner-in-the-loop

| Item | Owner? | VPS-safe? | Deps | Reviewer(s) | Done when |
|---|---|---|---|---|---|
| **B1 POS add-customer bug** (Tauri, macOS-bound; blocks onboarding) | repro + sign-off | NO (Tauri) | demo stack up | fiscal-pos-reviewer | customer persists + searchable from cart modal AND Customers tab, on device |
| **B2 auth 401-loop fix** | no | laptop tonight (already planned Task 13) | plan review READY | tenancy-authz-reviewer | PR merged; hard-refresh with stale token lands on /login |
| Loyalty completion session (`docs/handoff/PROMPT-loyalty-completion-roadmap.md`): boss-app enrollment flow, cashier enroll-by-default, program seeding, roadmap | UX/scope calls | partially (BE/web yes; POS surface no) | none | tenancy-authz (perm) + fiscal-pos (earn path) | parapharmacy tenant earns points E2E from a real sale |
| Owner decision gates: PHP 8.4 CI pin, staging web autoDeploy flip, GL A1 (expert-comptable), procurement OQ3, margin FE UX (brief A6), procurement v1 promote-to-dev stability call | YES (all) | no | — | — | decisions recorded in OWNER-BRIEF + board decision tasks closed |
| Remote branch bulk-prune approval (~130 verified-merged refs; ledger) | YES | script prepared | ledger review | — | prune script run, refs gone |
| erp-mobile push (all 3 branches) | YES (owner does it personally) | no | in-flight mobile work landing | — | origin has main/design-system/feat-mobile-expense-logging |
| POS Tauri work queue: return-disposition selector UI, caisse redesign P4-P7 + TransactionCart restyle, fresh Tauri build → staging | visual sign-offs | NO | B1 fixed first | fiscal-pos-reviewer | per-feature |
| Automotive vertical commonality session (2026-07-06, owner-slated): does procurement/RFQ/receipt ledger generalize to spare-parts retailers + repair garages? What's common-now vs vertical-custom? | YES (product) | prep-research VPS-able | procurement v1 landed | — | written commonality matrix + decision on shared core |
| Catalog search + techdoc ingestion pipeline verification (parent `syneriva` repo; blocked catalog search) | scope call | partially | separate session from parent repo | — | pipeline verified end-to-end or gap list produced |

## Track C — Mobile (erp-mobile, Expo)

| Item | Owner? | VPS-safe? | Deps | Reviewer(s) | Done when |
|---|---|---|---|---|---|
| Expense logging device sign-off (`feat/mobile-expense-logging` @ `6f341cf`) | YES (device test) | no | owner pushes branch | — | owner sign-off on device |
| Next mobile features (owner: "push hard — makes a lot of things way easier"); backend mobile-ready per `HANDOVER-mobile-expense-logging` | feature picks | app code yes (Expo is Linux-buildable; device testing no) | expense sign-off | — | per-feature |

## Sequencing to the 2–3-day goal

1. **Tonight (done/in-flight):** dark-factory Phase 1–2 build; B2 fix PR; cleanup; board seeded.
2. **Day 1:** owner reviews spec/PRs + decision gates batch; B1 repro+fix on laptop; loyalty session launches; VPS env completion.
3. **Day 2:** first supervised autonomous VPS run (docs/test-debt task); loyalty launch-blockers land; procurement v1 stability call → promote; staging full smoke.
4. **Day 3:** screenshot sweep review, remaining launch blockers burned down, onboarding dry-run on staging with the parapharmacy seeder.

## Standing constraints

Laptop never runs full PHPUnit; Tauri is laptop-only; one shared MCP browser
(parallel agents use standalone playwright-core); orchestrator gates all dev
merges (feature branches + PRs per dark-factory spec); staging web deploys are
explicit Dokploy actions until autoDeploy decision flips.
