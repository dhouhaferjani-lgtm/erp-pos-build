# Codex Dispatch Brief — OpenAPI Layer for the Entire ERP API (A→Z, autonomous)

**Date:** 2026-08-06 · **Executor:** Codex desktop, fully autonomous with quality gates
**Runs in parallel with:** the launch-program fix lanes — this lane is **documentation/contract-generation only** and MUST NOT change product behavior.

## Why
This is the preparation step for the agent-access layer discussed under the agentic-operations thread: an ERP **CLI and MCP server** that exposes ERP operations to agents ("Claude API + MCP server exposing ERP data" — the shared substrate for the procurement/coaching/visibility agents). Neither can be built safely without a machine-readable contract of the API. Today there is **no OpenAPI/Swagger/codegen anywhere in the ERP** — all TS types flow from PHP DTOs via `typescript:transform`; clients are hand-wired. The platform repo reserves `docs/04-API-CONTRACTS/` for OpenAPI specs; the ERP contributes nothing to it yet.

## Deliverable (in order; later stages only if earlier gates pass)
1. **OpenAPI 3.1 specification covering the entire ERP API** (`apps/api` — all module `routes.php` surfaces), generated from code, committed, and CI-drift-guarded.
2. **A validation + coverage harness** proving the spec matches reality.
3. **(Stretch, only if 1–2 gate green): scaffold consumers** — an MCP-server skeleton + CLI skeleton that are *generated from* the spec, read-only endpoints first.

## Ground rules (hard constraints)
- **ZERO behavior change.** No route changes, no controller logic changes, no FormRequest rule changes, no DTO shape changes. Allowed touches: PHP attributes/annotations, new doc-generation config, new artisan command(s) for spec generation, CI wiring, generated spec files, new scaffold packages that nothing in production imports.
- **Proof of zero change is a gate:** `php artisan route:list --json` byte-identical before/after; PHPStan clean; existing PHPUnit tests untouched and green (run by path per module, never the full suite at once).
- **Tenancy/auth must be first-class in the spec:** every route documents its auth scheme (`auth:sanctum` vs `sanctum-admin`), required permission, module gate (`module:<Name>`), and tenant context. The spec must NOT leak cross-tenant or super-admin surfaces into the "tenant client" grouping — model them as separate OpenAPI tags/servers or separate spec documents (tenant API vs admin API).
- **Precision contract carries into the spec:** money/quantity fields are `type: string` with the documented regex ceilings (money `^-?\d+(\.\d{1,3})?$`, quantity `{1,4}`, percent `{1,2}`) — NOT `number`. An OpenAPI spec that types money as float is a REJECT.
- The overloaded `unit_price` semantics (TTC in B2C POS vs HT in B2B documents) must be documented per-endpoint in descriptions — this is the known false-positive trap.

## A→Z flow (numbered steps are gates)
1. **Tooling decision memo (short):** evaluate generation approaches for Laravel 12 (attribute/inference-based e.g. Scramble vs annotation-based e.g. l5-swagger vs hand-rolled from route+FormRequest+DTO reflection). Criteria: works with modular `routes.php` autoloading, strict DTOs, zero-runtime-cost in prod, incremental per-module adoption, CI-friendly determinism. Pick one, justify in the memo.
2. **GATE — adversarial review of the memo + rollout plan** (file verdict) before touching the tree.
3. **Pilot module** (suggest: Treasury or Document — high-value, DTO-rich): generate the spec slice, validate with an OpenAPI 3.1 validator, diff against reality (see harness), fix inference gaps via annotations only.
4. **Coverage harness:** a CI script that (a) enumerates every registered route from `route:list --json`, (b) checks each against the spec, (c) emits a coverage ratchet (like `audit-tanstack-keys.mjs` / deptrac baselines): existing gaps baselined, NEW routes must be specced — build fails otherwise.
5. **Fan out module-by-module** to the full surface. Per-module milestone: spec validates; coverage 100% of that module's routes; response shapes spot-verified against 3+ real feature tests' actual JSON (the `{data:...}` envelope and `{error:{errors}}` validation envelope must be modeled correctly).
6. **GATE — zero-behavior proof** (route:list diff, PHPStan, per-module PHPUnit paths green) + adversarial review of the complete spec: hunt for admin/tenant surface bleed, float-typed money, missing auth/permission documentation, spec-vs-code lies.
7. **Stretch — consumers:** MCP-server skeleton (read-only tools first: product lookup, stock levels, document read, report summaries) + CLI skeleton, both generated/driven from the spec, each with its own smoke test against a live local tenant. Auth = standard Sanctum token; no new auth surface invented. Write operations DEFERRED to a future lane with its own review.
8. **Deliverable:** feature branch off `dev` (isolated worktree, never shared dev), review verdicts to files, a REALIGNMENT-LOG draft entry if the spec is published to `docs/04-API-CONTRACTS/`, and a handback note. **Do NOT push to origin/dev** — orchestrator promotes after dual gates.

## Non-negotiables
- If the chosen tool cannot model something truthfully (e.g. the POS unit_price semantics), document the limitation in the spec description rather than "fixing" the code to fit the tool.
- Spec files are generated artifacts with a committed source-of-truth config — regeneration must be deterministic (same tree ⇒ same spec, no timestamps).
- Any discovered undocumented/orphan route is REPORTED (list in the handback), not deleted or "cleaned up" — that's another lane's decision.
