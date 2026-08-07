# Handover — OpenAPI Contract Lane Orchestration

**Date:** 2026-08-07 · **For:** a dedicated Fable-5 orchestrator session
**Role you are taking:** governance orchestrator for one autonomous Codex lane. You do NOT write the spec. You read the lane's NO-GO verdicts, judge whether each stop is real, issue superseding rulings from OUTSIDE the lane, and hand back resume prompts.

---

## 1. What this lane is

An autonomous Codex desktop lane generating an **OpenAPI 3.1 specification for the entire ERP API** from code, with a CI drift/coverage gate. It is the prerequisite for the ERP **CLI and MCP server** (the agent-access layer — see the platform-side agentic-operations thread: "MCP server exposing ERP data" as the substrate for the procurement/coaching agents).

**Brief:** `docs/superpowers/plans/2026-08-06-codex-dispatch-openapi-mcp-layer-a-to-z.md` (on `dev`)
**Lane branch:** `codex/openapi-contract-a-to-z` · **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp.openapi`
**Handback-only.** Never push `origin/dev`. The lane hands back a branch + verdicts + handback note; you promote after dual gates.

**Hard constraint that defines the lane:** documentation/contract generation ONLY, zero product behavior change, proven by a byte-identical `php artisan route:list --json` hash (currently `ea1a35bca3627b739e1be460c9da83ae8b843356127900c110ae70e3c3552b4c`).

---

## 2. Current state — READ THIS FIRST

**Three NO-GO stalls. Zero spec artifact produced. All three stops were correct.** The lane has never touched product code; every rollback verified clean.

| # | Stall | Cause | My ruling | Commit |
|---|---|---|---|---|
| 1 | 25-op feasibility gate: got 383 targets, budget said 379 | **My error.** Budget frozen in *raw defect nodes*, gate counted *operation-resolved identities* — different units. 4 shared components fan out to 2 consumers each. | Re-froze in operation-resolved units: **383 targets / 257 ops (238-12-7)** | `06e6b3309` |
| 2 | Selector needs all-admin + all-external + 13 tenant = 32, but tranche frozen at 25 (5+7+13) | **My error again.** Fixed the admin count 5→12 but left the *derived* 25-ceiling and 5/7/13 split frozen. | Option A: tranche = **32 ops, 12/7/13**. Plus a **mandatory derived-constant sweep** so partial corrections stop recurring. | `b9bf3bf5f`, `c5eef5c9e` |
| 3 | Breadth audit proves ≥104 complete-overlay ops vs immutable ceiling of 101 | **Not my error** — the sweep confirmed 101 was independently frozen. A real collision between a pre-measurement guess and measured reality. | Ruling 1: `$ref` shared component IS a reusable carrier → the 84 collapse to 1. Ruling 2: overlay ceilings become **reported measures, not admission gates**. Plus a standing rule (below). | `b5e4bda00` |

The lane's derived-constant sweep (`docs/superpowers/reviews/2026-08-07-openapi-derived-constant-reconciliation.md`, on the lane branch) is now **part of the frozen contract** — it reconciled every dependent value and found no remaining governance question.

### ▶ YOUR STARTING POSITION: the stall-3 resume prompt WAS DISPATCHED (owner, 2026-08-07)

The §6 prompt has been sent to the Codex desktop conversation. **The lane is running.** Do **not** re-dispatch it — that would duplicate a live lane.

**Your first real task is to WAIT for the lane's next report, then triage it.** Do not start speculative work on the lane in the meantime; the branch is clean and the lane owns it. Useful things you *may* do while waiting: read the ruling chain and prior verdicts so you can judge fast, and stand up the codebase-findings register (§8).

### Triage guide — what to do with the incoming feedback

| What comes back | Verdict | Your action |
|---|---|---|
| **Progress report** — breadth classification re-run with Ruling 1, overlay/carrier counts reported as measurements, selector/closure proceeding | Working as intended | Acknowledge, confirm the counts are *reported* not gated, let it continue to fan-out. No ruling needed. |
| **A judgment call surfaced, mechanically underivable** — e.g. the 9 binary/stream responses are borderline shape-identical | Legitimate | Rule on it. Prefer the truthful modeling; a carrier is only valid where shapes are *provably* identical (Ruling 1b). When genuinely 50/50, charge them individually — over-charging is safe, a wrong carrier is a lie in the spec. |
| **NO-GO in a mandatory-stop category** — behavior change, determinism failure, truthfulness compromise (permissive schema / float money / surface bleed) | **Real. Honor it.** | Rule on substance. Does **not** count toward the numeric-stall escalation counter. |
| **NO-GO on a count-based ceiling while all correctness properties still hold** | **Standing-rule violation** — the lane should have recorded, noted, and continued | Do **NOT** issue a new ruling or adjust any number. Re-dispatch pointing at the standing rule in `b5e4bda00`, quoting it, and instruct it to continue. This is a cheap correction, not a governance event. |
| **Another count-based NO-GO after that re-dispatch** | **Escalation trigger fires** | Stop patching. Tell the owner plainly: the contract design is beyond repair. Recommend restarting the lane with a thin contract — keep the correctness properties (§4), drop every pre-frozen count. |
| **Handback: lane complete** | Ready for gates | Run the dual gate (spec-truthfulness pass + a zero-behavior verification), then promote per the normal flow. Never push `origin/dev` from the lane. |

Calibration note: the standing rule was issued precisely so numeric ceilings stop costing round-trips. If the lane honors it, stalls 1–3's failure mode is closed. Judge the *category* of the stop first — that single question routes every response above.

---

## 3. The judgment you're inheriting (the part not in the files)

**The lane's work quality is genuinely high.** Reflection-derived lower-bound proofs, canonical SHA-256s per operation-set, dual-process byte-identical regeneration, verified clean rollbacks. It is not producing slop and it is not sandbagging. When it says something is proven, check it — but expect it to hold.

**The root problem was the contract, not the lane.** It was written with multiple *exact-equality* ceilings (target counts, tranche size, overlay caps) frozen **before the codebase was measured**. Every collision between a guess and reality forced a full round-trip for an outside ruling. That is a structural defect in how I specified the lane, and stalls 1–2 were me compounding it with partial corrections.

**The 84-clone insight (stall 3) is the template for judging future stops.** The ceiling said "104 > 101, stop." The real finding was that **84 of those 100 operations are the same shape counted 84 times** — an `X-Request-ID` metadata blob composed inline in 84 action bodies. The rule refused to see it as reusable purely because the runtime composes it inline rather than via a shared method. So: *when the lane reports a count that breaches a limit, ask what the count is actually counting before you touch the limit.* Raising 101→104 would have been arbitrary number-fiddling AND left the modeling error in place.

**Standing rule now in force (`b5e4bda00`)** — this should prevent stall class 1–3 entirely:
> Any remaining pre-measurement numeric ceiling is a **reported measure, not an admission gate**, unless it encodes correctness, safety, determinism, or zero-behavior-change. If the lane meets a count-based ceiling while every correctness property still holds → **record, note in handback, CONTINUE.** Do not stop for a ruling.

**Stops that remain mandatory (these are real, honor them):** any behavior change; any determinism failure; any truthfulness compromise (permissive schema, float-typed money, tenant/admin surface bleed); any value requiring implementer judgment that isn't mechanically derivable.

**⚠️ Escalation trigger — my standing recommendation.** If the lane stalls a **fourth** time on a *numeric* ceiling, the contract design is beyond patching. Do NOT issue a fifth ruling. Recommend to the owner that the lane be **restarted with a much thinner contract** — keep the correctness properties (zero behavior change, determinism, no permissive schemas, money-as-string, surface separation, source-derived overlays) and drop every pre-frozen count. Say it plainly; the owner has been well-served by candor on this lane.

---

## 4. What is NOT negotiable (never relax these to unblock)

Zero behavior change (byte-identical `route:list`) · determinism (same tree ⇒ byte-identical spec, no timestamps) · no permissive schemas — an overlay states the TRUE shape, never `{}` or a widened type · money/quantity as `type: string` with the precision-contract regex ceilings (money `^-?\d+(\.\d{1,3})?$`, quantity `{1,4}`, percent `{1,2}`) — **float-typed money anywhere is an automatic reject** · tenant/admin/external surface separation, no bleed into one client spec · `unit_price` TTC-vs-HT documented per endpoint · no implementer-curated selection of which defects get fixed · no-dev isolation · route invariance.

**Reproducibility gotcha (hard-won):** the feasibility generator/test MUST run against **central PostgreSQL** semantics. The `phpunit.xml` sqlite default changes Scramble's admin inference and yields a wrong 370/251. Any proof run under sqlite is invalid — reject it.

---

## 5. Frozen contract values (current, post-ruling)

- Inventory: **383 directional targets** (Rq=1, Rs=382) across **257 affected operations**, split **238 tenant / 12 admin / 7 external**
- Surface partition: **954 / 43 / 18 = 1,015** operations (source figure)
- Source-node inventory: **342 empty-items + 37 free-objects = 379 raw nodes** (source figure; NOT an admission equality)
- Tranche: **exactly 32 operations**, split **12 admin / 7 external / 13 tenant**; 13-tenant selection by the frozen greedy algorithm, untouched
- Complete-overlay counts: **no cap** — reported measures with justification/reuse-first/report/determinism (P1–P4 in `b5e4bda00`)
- Tooling: Scramble v0.13.22

---

## 6. The stall-3 resume prompt — ✅ ALREADY DISPATCHED 2026-08-07, kept for reference only

**Do not re-send this.** It is recorded here so you know exactly what the lane was told, and so you can quote its clauses (especially the STANDING RULE paragraph) when triaging the reply.

```
RESUME AUTHORIZED. Your breadth-cap NO-GO is ACCEPTED AS PROVEN — the 104 lower bound,
the per-class hashes, the reflection method, and the clean rollback are all sound, and
your derived-constant reconciliation correctly established that the 101 ceiling was
independently frozen, not a residue of the prior corrections. You stopped correctly.

A superseding ruling is committed on your branch:
  docs/superpowers/reviews/2026-08-07-openapi-overlay-ceiling-and-carrier-ruling.md
  (commit b5e4bda00 on codex/openapi-contract-a-to-z)
Read it in full before resuming.

RULING 1 — A $ref'd shared schema component IS a genuine reusable contract carrier for
complete-overlay classification, subject to four conditions: (a) SPEC-LEVEL ONLY, zero
product code change — do NOT refactor the 84 action bodies to call a shared method, that
remains forbidden even though it would be behavior-preserving; (b) source-derived and
provably shape-identical by the same reflection method that produced your lower bound —
any operation whose shape differs is excluded and charged individually; (c) charged ONCE,
with the carrier listed in the evidence manifest with its member-operation set and a
canonical SHA-256; (d) determinism preserved on regeneration. Your 84 request_id
operations therefore collapse to 1 carrier identity. Assess (do not assume) whether the
9 binary/stream responses are shape-identical; the 6 SQL projections and 1 nested
request array stay individually charged; the 4 Document overlays are unchanged.

RULING 2 — The 101/1,015 and 7/71 complete-overlay ceilings are CONVERTED from admission
gates into REPORTED MEASURES. There is no numeric cap on complete overlays. Replacing
the cap: every overlay/carrier must cite the production source range proving its shape;
where ≥2 operations share a provably identical shape a carrier MUST be used (charging
identical shapes separately is now a defect); the final count is a headline figure in
your handback with full breakdown; determinism and zero-behavior-change remain HARD gates.

STANDING RULE for the rest of this lane: any remaining pre-measurement numeric ceiling is
a reported measure, not an admission gate, unless it encodes correctness, safety,
determinism, or zero-behavior-change. If you meet another count-based ceiling that would
halt work while every correctness property still holds — RECORD the measurement, NOTE the
exceedance in the handback, and CONTINUE. Do not stop for a ruling. Stops remain
mandatory for: any behavior change, any determinism failure, any truthfulness compromise
(permissive schema, float money, surface bleed), or any value requiring implementer
judgment that is not mechanically derivable.

NOT relaxed: zero behavior change (byte-identical route:list), no permissive schemas — an
overlay states the TRUE shape, never {} or a widened type — money/quantity as string with
the precision-contract regex ceilings, tenant/admin/external separation, unit_price
TTC-vs-HT documentation, no implementer-curated selection of which defects get fixed,
determinism, no-dev isolation, the frozen inventory (383/257/238-12-7), the 32-op tranche
(12/7/13), the 13-tenant greedy selector, the 8 risk tags, and the reconciliation as part
of the frozen contract.

Re-run breadth classification with Ruling 1 applied, report the resulting overlay/carrier
counts as measurements, then continue in order: risk-tag assignment, 13-tenant selector,
32-operation closure, full module fan-out, final zero-behavior gates, then the CLI/MCP
stretch. Handback-only: never push origin/dev.
```

---

## 7. What "done" looks like

1. OpenAPI 3.1 spec covering the full ERP API, generated + committed + CI-drift-guarded
2. Coverage harness/ratchet — existing gaps baselined, NEW routes must be specced or CI fails (mirror `apps/web/tools/audit-tanstack-keys.mjs`)
3. Stretch (only if gates green): read-only MCP-server skeleton + CLI skeleton driven from the spec, standard Sanctum auth, write ops out of scope
4. Handback note: tool + why, spec locations, coverage numbers + baselined gaps, **every undocumented/orphan route DISCOVERED (report, never delete)**, draft REALIGNMENT-LOG entry if published to the platform repo's `docs/04-API-CONTRACTS/`

**Value framing for the owner (already discussed, reuse it):** the 379 defects are not bugs — they're places where response shape exists only in developers' heads. The spec turns response contracts into something CI can enforce, which is a class of bug (double-unwrap, `{data,meta}` envelope drift) that currently has no systematic guard. The 37 free objects are worth a real look — CLAUDE.md rule 3 says every JSONB column needs a DTO, and `Invoice.metadata` surfacing as a free object suggests a gap.

---

## 8. Codebase findings go to a SEPARATE session — never fix them in this lane (owner ruling 2026-08-07)

This lane is **zero-behavior-change by definition**. It will nonetheless surface genuine codebase defects and improvement opportunities — that is a valuable side effect, not a licence to act on them here. **Owner ruling: any finding that implies a code fix or improvement is collected, not fixed, and scheduled as its own dedicated session.** Fixing in-lane would break the `route:list` byte-identity gate, contaminate the zero-behavior proof, and violate CLAUDE.md rule 4 (one task at a time, no scope creep).

**Your job as orchestrator:** maintain a running findings register (suggested: `docs/superpowers/audits/2026-08-XX-openapi-lane-codebase-findings.md`), append every actionable finding with its evidence and file:line, and hand it to the owner as a **separate dispatch proposal** when the lane completes — or earlier if something is severe enough to matter before then (report it immediately; still don't fix it here).

Already known to belong in that register:
- **37 free-object nodes** — CLAUDE.md rule 3 requires a PHP DTO for every JSONB column. `Invoice.metadata` and `Invoice.billing_address` surfacing as untyped free objects means either the DTO is missing or it isn't visible at the serialization boundary. Small, bounded, real.
- **342 empty-items nodes** — response array item types exist only in convention (`LengthAwarePaginator` and friends). The burn-down is a genuine typing improvement to the response layer.
- **84 action-local inline `X-Request-ID` composition** — the same metadata blob hand-composed in 84 action bodies with no shared producer. This lane models it with a spec-level carrier and is FORBIDDEN from refactoring the actions; the actual DRY refactor is a separate, behavior-preserving code lane if the owner wants it.
- **Any undocumented/orphan route discovered** — report in the handback, never delete. Deletion is another lane's decision.
- Any spec-vs-code contradiction where the code is the thing that's wrong.

Rule of thumb: if closing a finding would change a single byte of `route:list` output or any runtime behavior, it is **out of scope for this lane, full stop** — register it and move on.

## 9. Not your lane — do not confuse them

The **impersonation** lane (`codex/tenant-impersonation`, worktree `../erp.impersonation`) is a separate parallel Codex lane, currently **failed final gate with 3× REJECT** (5 blockers incl. 2 proven privilege-escalation exploits). Its consolidated fix brief is at `docs/superpowers/reviews/2026-08-07-impersonation-CONSOLIDATED-fix-brief.md` on that branch. Memory: `project_tenant_impersonation_support_access`. If the owner asks about "the other lane," that's it.

Production-environment decisions (Resend, Sentry, two launch humans, super-admin MFA) are settled and unrelated — memory `project_production_environment_and_cat_b_2026_08_05`.
