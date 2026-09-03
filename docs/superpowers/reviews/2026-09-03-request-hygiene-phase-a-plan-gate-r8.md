# Plan gate r8 — request hygiene Phase A (targeted)

## Verdict: REJECT

Revision 8 resolves all three r7 blockers textually, but Tasks 12 and 13 each retain one execution-level blocker.

Verification performed read-only:

- Both merge commits are ancestors of `dev`.
- Task 9 unit gate: 3 tests, 3 assertions, PASS.
- Task 13 existing backend paths: 75 tests, 239 assertions, PASS.
- Whole-web `pnpm typecheck`: PASS.
- All four entrypoints plus helper: `sh -n` PASS.
- No config-cache residue or workspace changes were produced.
- Frontend Vitest could not be rerun because the enforced read-only sandbox prevents Vite/Vitest from creating its temporary worker files. The merged Task 11 handback records its three-test green run.

## r7 disposition audit (finding · resolved? · evidence path:line)

| Finding | Resolved? | Evidence |
|---|---:|---|
| B1 — obsolete Task 11–13 hook path | Yes, path-wise | Canonical hook at [useIdempotencyKey.ts:10](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/hooks/useIdempotencyKey.ts:10); Task 11 banner at [plan:2370](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2370); Task 12 import at [plan:2587](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2587); Task 13 mock at [plan:3272](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:3272). No obsolete import remains. |
| B2 — Task 4 UUID narrowing | Yes | Rule is `string|max:100` at [plan:1232](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:1232); positive HTTP regression at [plan:1592](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:1592); actual column contract at [migration:19](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2025_11_30_140000_create_audit_events_table.php:19). |
| B3 — unused Task 2 `beforeEach` | Yes | Reset body is prescribed at [plan:713](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:713); `noUnusedLocals` remains enabled at [tsconfig.json:28](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/tsconfig.json:28). |
| r6 B1 — WebSocket cache-store bypass | Yes on disk | All four guards and EXIT traps are present, including [entrypoint-websocket.sh:4](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/docker/entrypoint-websocket.sh:4) and its normal-boot guard at [entrypoint-websocket.sh:37](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/docker/entrypoint-websocket.sh:37). |
| r6 B2 — dual-index transfer collision | Yes in planned logic | Catch/reread at [plan:2820](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2820), exact-number competing insert at [plan:3113](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:3113), real constraints at [stock-transfer migration:66](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_05_28_120000_create_stock_transfers_table.php:66). |
| Complete older payment Playwright metadata | Yes | [plan:1121](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:1121). |
| W8 remains bounded, not a whole-set helper | Yes | [plan:1181](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:1181). |
| Task 10 mandatory whole-backend CI gate | Yes | [plan:2364](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2364). |
| RecordPaymentModal precision debt deferred | Yes | [plan:2692](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2692), [plan:3623](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:3623). |
| Task 7 partial-scope disclosure | Yes | [plan:35](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:35). |
| Redis capability/connectivity caveat | Yes | [plan:1982](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:1982), [plan:2202](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2202). |
| Correct SplitPaymentModal anchors | Yes | Plan anchors at [plan:2421](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2421) match [SplitPaymentModal.tsx:42](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/components/organisms/SplitPaymentModal/SplitPaymentModal.tsx:42), line 73 and line 122. |
| Different-key transfer-number debt | Yes | [plan:2747](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2747), [plan:3620](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:3620). |
| Task 1 worker-lifecycle wording | Yes | [plan:141](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:141). |
| Task 9 live-state correction | Partial | Banner matches disk at [plan:1982](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:1982), but stale executable instructions remain below. |
| Task 14 in-flight debounce regression | Yes | Retained at [plan:3589](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:3589). |
| Task 6 sibling suites | Yes | [plan:1743](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:1743). |
| Actionable references | Partial | Task 12 and replenishment anchors are exact, but the r7 audit cited a nonexistent `2025_12_15_100000` migration; the real file is `2025_11_30_140000`. |

## Blocking findings

### 1. Task 12 — the advertised exact-decimal acceptance test passes before the implementation

The test at [plan:2557](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2557) submits `"0.100" + "0.200"` against `"0.300"` and expects an API call.

Current production code already accepts that combination: it performs floating-point addition at [SplitPaymentForm.tsx:101](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/treasury/SplitPaymentForm.tsx:101), then allows any absolute difference up to `0.01` at [SplitPaymentForm.tsx:138](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/treasury/SplitPaymentForm.tsx:138). The IEEE-754 error is approximately `5.55e-17`, so the test passes under the old float path.

The separate `0.001` shortfall test is genuinely red because the old `0.01` tolerance incorrectly accepts it, but the positive test itself does not prove the claimed decimal path. Make it falsifying—for example, additionally assert the rendered remaining amount is exactly `"0.000"` under the string-preserving formatter. The current implementation renders the floating residue instead.

### 2. Task 13 — the exact PostgreSQL command is not self-contained for the newly reserved database

The new class explicitly avoids `RefreshDatabase` at [plan:2864](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2864), but its `setUp()` immediately calls `Tenant::create()` at [plan:2916](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2916) without ensuring migrations exist.

Phase 0 only reserves a private database; the exact isolated command at [plan:3313](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:3313) therefore fails on a fresh reserved database with a missing `tenants` table. Task 1 already documents the correct no-transaction pattern: check the schema and run migrations before fixture creation. Task 13 needs the same bootstrap or an explicit preceding migration command.

## Non-blocking findings

- Task 9’s banner is accurate, including the non-fatal `config:cache` ruling. Disk uses an explicit continue in the web entrypoint and `2>/dev/null || true` in worker, scheduler, and WebSocket.
- Task 9 still contains unchecked instructions to make `config:cache` fatal and recreate landed files at [plan:1996](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:1996) and [plan:2111](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2111). Phase 0 and promotion language also still demand the already-landed WebSocket follow-up at [plan:54](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:54) and [plan:3609](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:3609).
- Task 11 similarly retains unchecked “add” and “implement” steps at [plan:2377](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2377) and [plan:2395](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2395). The banner says they are historical, but converting them to checked/history-only prose would prevent accidental re-execution.
- Task 4’s new Step 4b appears after Step 6’s verification/gate and after the task separator at [plan:1592](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:1592). Move it before Step 6 so the prescribed verification actually runs the new regression.
- Correct the stale audit migration filename to `2025_11_30_140000_create_audit_events_table.php`.

## Dispatch readiness table

| Task | Ready? | Why |
|---|---:|---|
| Task 12 | No | One claimed precision proof passes under the current float/tolerance implementation. |
| Task 13 | No | The isolated PG recipe lacks schema initialization for its freshly reserved database. |
| Task 2 | Yes | r7’s unused-import/reset-state blocker is resolved; the prescribed harness matches `noUnusedLocals`. |
| Task 3 | Yes | Required metadata, rendered pagination, bounded W8 semantics, and W5c traversal remain executable. |
| Task 4 | Yes, conditional | The UUID regression is fixed and executable; retain document-lane coordination and move Step 4b before the verification gate. |