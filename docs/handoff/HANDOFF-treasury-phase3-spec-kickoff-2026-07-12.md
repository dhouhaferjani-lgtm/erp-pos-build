# Treasury Phase ③ — Cash-Visibility Read Layer: spec+plan cycle kickoff (2026-07-12)

**Mandate (owner, 2026-07-12):** run the full spec/plan cycle for treasury Phase ③ — the cash-visibility read layer — same process as Phase ②: brainstorm → spec → adversarial review (saved to file) → Rev 2 → writing-plans → plan review → Codex brief with AUTONOMOUS `claude -p` gates (Opus standard reviewer, Fable 5 escalation only on money-path BLOCKER/HIGH) → STOP; execution dispatch and the final orchestrated whole-branch review follow the Phase-② pattern exactly (see `docs/handoff/CODEX-treasury-phase2-instruments-2026-07-11.md` §3 for the gate protocol to copy).

## Ground truth to load first
1. **Phase ① (spine) + Phase ② (instrument portfolio/échéancier) are BOTH SHIPPED on origin/dev** — Phase ② merged at `18ad9e67c` (2026-07-11). Final review: `docs/superpowers/audits/2026-07-11-treasury-phase2-final-review.md` (8 lanes, all APPROVE; 7 LOW follow-ups being fixed on `feat/treasury-phase2-followups`). Full history: memory `project_treasury_phase2_instruments`, `project_treasury_spine_rebuild`.
2. **What Phase ③ is, per the owner's 5-phase decomposition:** ③ cash-visibility read layer → ④ expense depth → ⑤ bank statement import. The gap register is `docs/superpowers/audits/2026-07-07-treasury-industry-gap-audit/README.md` (P2 block) — but several P2 items are ALREADY DONE by Phases ①/②; verify against code, don't trust the register:
   - G11 (movements ledger + drill-down) ✅ Phase ① · G14 (server cash-position endpoint) ✅ Phase ① · G8/C5 (instrument maturities in forecast) ✅ Phase ② · échéancier panel ✅ Phase ②.
   - **Remaining Phase-③ meat:** G12 — FE page for the trustworthy `GET /reports/cash-movements` (`CashMovementsReportService`, backend-only today; this is also the unified-outflow view, E9); G13 — **inter-repository cash transfer** endpoint + GL + FE (drawer→bank, drawer→safe; remise d'espèces); C9 — main-dashboard cash widget (owner's first treasury question); alert SURFACING beyond `audit_events` (Phase ② explicitly deferred mail/notification-center delivery of maturity + drift alerts to Phase ③); candidate: cash-position history/snapshots.
3. **G13 is THE structural item: `TreasuryMovementService::transfer()` has ZERO production callers** — Phase ② confirmed it stays unconsumed (clearing is single-leg). Its concurrency contract (advisory lock → both repos sorted-by-id, single txn, paired legs netting zero, cross-account JE only) is test-proven only. Phase ③'s transfer flow is its FIRST consumer — the spec must treat that contract as binding and pin it live.
4. **Interlocks (all potentially in flight in parallel — check branch/worktree state at session start):**
   - `feat/treasury-ui-gaps` (Codex, autonomous): owns `RepositoryDetailPage.tsx` + `ExpenseDetailPage.tsx`. A Phase-③ transfer action will WANT to live on RepositoryDetailPage — either sequence behind that track's merge or scope the FE to not collide (spec must decide explicitly).
   - `feat/bank-reference-verification` (Codex, autonomous): owns `banks` + `BankPicker`; no expected overlap with Phase ③.
   - `feat/treasury-phase2-followups`: 6 small fixes, merges soon; base Phase ③ on whatever origin/dev tip includes it.
5. **Standing invariants (non-negotiable):** every cash movement through the Phase-① port with a JE (`postEntryNow`, never afterCommit); global lock order instrument→documents→GL advisory→repository (transfers: advisory→both repos sorted); precision rule 19; rule 20 in any projection/queue context; reconcile checks #1-#4 must stay green — a transfer flow that breaks movement↔JE equality freezes repos nightly.
6. **Deploy reality:** no production tenants; staging auto-deploys from dev (entrypoint-automated migrate/reseed/cache-reset); Phase-② deploy checklist owes (chart reseed, perm reseed) apply to any tenant provisioned before `18ad9e67c`.

## Research scope (lighter than Phase ② — this is mostly read-layer + one write flow)
- Inter-repo transfer conventions: remise d'espèces en banque (cash deposit slip — bordereau de remise d'espèces), GL for same-account vs cross-account moves (532/512 ↔ 54/53 caisse), Odoo internal transfers (outstanding-account pattern), Sage caisse→banque.
- Cash-position dashboards: what Odoo/Agicap/Pennylane put on the landing widget (per-repo balances, 7/30d in-out, alerts count).
- Notification surfacing: in-app notification center vs mail for maturity/drift alerts — check what notification infra already exists in the repo before designing any (grep first; do not invent a channel if one exists).

## Process contract (repo standing rules — same as Phase ②)
- superpowers:brainstorming FIRST; spec to `docs/superpowers/specs/2026-07-12-treasury-phase3-cash-visibility-design.md`; adversarial review saved to `docs/superpowers/specs/reviews/` (treasury-reviewer Fable-tier for the transfer/GL surfaces, Opus for read-layer — owner tiering); reconcile Rev 2; writing-plans; plan review; Codex brief with autonomous gates; STOP at owner dispatch gate.
- Review model tiering (owner): Opus standard; Fable 5 only for crucial financial-spine surfaces (here: the transfer write flow + its reconcile interplay).
- Flag GL-account choices (caisse/virements internes accounts, bordereau d'espèces) as owner/expert-comptable decision points.

## Session-start prompt
"Read docs/handoff/HANDOFF-treasury-phase3-spec-kickoff-2026-07-12.md and run the Phase ③ spec/plan cycle it mandates."
