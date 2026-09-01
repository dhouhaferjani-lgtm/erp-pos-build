# Wave 1 — imports: local browser evidence on the K-6 branch + real tester files — 2026-08-31 (run 2, fresh tenant)

Stack: `.worktrees/k67-import` (K-6 implemented by Codex `gpt-5.6-sol`, TDD evidence in `lane-k6-summary.md`: FE RED→GREEN, BE RED→GREEN, `whereUuid('id')` route constraint; import Vitest 70/70; touched PHPUnit 33/33 SQLite + PG; malformed-route sweep 90 assertions). API :8013 sync queue + Vite :5176. Spec `apps/web/e2e-local/wave1-imports.spec.ts` — every step captures 5xx + console errors. Run log: scratchpad `wave1-run2.log`.

| Step | Result | 5xx |
|---|---|---|
| S1 synthetic products import (wizard UI) | imported 2 / 0 / 0 | **0** (K-6 verified: the empty-id preview 500 is gone) |
| S2 synthetic parties import | imported 2 / 0 / 0 | **0** |
| R1 `liste clients.xlsx` — 257 rows, French headers | mapping auto-suggested (`référence→code`, `nom complet→name`, `téléphone→phone`, `solde depart→opening_balance`, `type→type`) → **257 imported / 0 failed** | 0 |
| R2 `État des Fournisseurs (1).xlsx` — 11 rows | **11 imported / 0 failed** (Débit/Crédit/solde columns: only `solde` mapped to opening balance — the file's internal débit≠solde inconsistency is NOT surfaced; see F-W1-3) | 0 |
| R3 `model produits.xlsx` — 859 rows | preview **"856 valid / 3 with errors"** → Import Valid Rows → **imported 0 / failed 859** — every row `Unknown unit "piece"; enter a code exactly as spelled.` | 0 |
Whole run: `TOTAL 5xx=0`, one stray `console.error` 401 (not against :8013 — likely the WebSocket/broadcast auth or the verification banner; F-W1-4, identify in K-7 close-out).

## Findings
- **F-W1-1 (P1, imports — preview/execution divergence):** unit resolution runs only at execution, so the preview validated 856 rows that all failed. Owner ruling 2026-08-31: fix at validation (lane **K-8**) — strictness stays.
- **F-W1-2 (P1, product/ops):** the tester's real catalogue uses `piece`; seeded code is `pc`. Owner ruling: pull the G-13 "Unmapped unit texts" mapping panel forward (lane **K-9**) — map once in Settings → Units, re-import clean. Staging census already showed `piece` ×856–859 per legacy tenant.
- **F-W1-3 (P2, parties validation honesty):** supplier file rows where `Débit (DT)` ≠ `solde` (e.g. 1033.708 vs 1033.78; 2801.325 vs 2801.568) import silently using `solde`; the wizard never tells the operator the source columns disagree. Candidate for the parties preview: "N rows have inconsistent balance columns". Not ruled.
- **F-W1-4 (P3):** one 401 console error per session on the wizard pages — identify source.
- Harness learnings: files with invalid rows open a confirmation dialog (Cancel / Import Valid Rows) — specs must answer it; a `--grep` partial run of a serial suite skips the login test — run the whole file.

## Wave-1 status
K-6 ✅ local evidence. K-7 (enrichment) Codex in flight. K-8/K-9 (units) Codex in flight on `fix/k89-units-validation-mapping`. Imports step closes only when the three real files import cleanly through the UI after K-8/K-9 (units mapped), enrichment adopts platform images for the two known EANs on staging, and the adversarial code gate passes.

## 2026-09-01 — lanes K-7 / K-8 / K-9 implemented (Codex gpt-5.6-sol); browser evidence round
- **k67 branch (K-6 + K-7)** — `wave1-imports.spec.ts` run 4: **6/6 PASS**, `TOTAL 5xx=0` (synthetic products/parties, clients 257/257, fournisseurs 11/11, produits 0/859 — expected on this branch, no K-8). K-7 local spec (`k7-import-enrichment.spec.ts`, dev lookup stub on): registration + "imports with enrichment on, stays green, exposes the miss warning" — product assertions PASS (miss warning rendered, 0×5xx); the leg is held red only by the console-error assertion → F-W1-4.
- **F-W1-4 identified:** `401 GET /api/v1/auth/me auth=no @/login` — the auth bootstrap probes `/auth/me` on the login page with no token. Lane **K-10** (FE, S) dispatched. Until it lands every zero-console-error assertion fails by design.
- **K-8** (validation-time unit check): Codex evidence — real file → 859/859 `unit_unknown` at validation, revalidation stable; PHPUnit 36/36 SQLite+PG; Vitest 72/72; the 3 unrelated preview errors are **negative quantities (-1) on rows 141, 160, 827** of `model produits.xlsx` (tester data issue — tell the team).
- **K-9** (unit-text mapping panel): implemented, BUT the orchestrator's browser leg found a **BLOCKER**: `2026_09_01_100000_create_unit_text_mappings_table.php:15` constrains `tenant_id` to the central `tenants` table inside a tenant-DB migration → `POST /auth/register` 500 (`relation "tenants" does not exist`); would have broken `tenants:migrate` fleet-wide on push. Fix round 1 dispatched with a guard test (tenant migrations must never reference central tables). K-8/K-9 browser legs re-run after FR1.
- Harness lessons: Codex's spec logged into the local demo tenant (stale schema) — replaced by fresh registration; `page.request` needs the Bearer token explicitly; both local stacks share Redis so the register throttle (5/15 min) counts across them — `cache:clear` between bursts; never run browser legs while Codex edits the same worktree (HMR reload).

## 2026-09-01 (cont.) — K-8 browser PASS; K-9 fix rounds
- **K-9 FR1 verified:** migration now `uuid('tenant_id')->index()` + `Schema::hasTable` idempotency guard; new `tests/Unit/Migrations/TenantMigrationsNeverReferenceCentralTablesTest.php` (RED on the old file → GREEN); PG fresh-tenant registration 201; K-8+K-9 API regression 73/73 on PG.
- **K-8 browser leg PASS (fresh tenant, k89 stack):** real `model produits.xlsx` → preview aggregates **"859 rows use unknown unit 'piece'"**, Proceed disabled, honest "no valid rows" state, no partial-rows dialog. F-W1-1 closed at the UI.
- **K-9 browser leg → second defect (FR2 dispatched):** Settings → Units panel lists `piece · 859 preview rows` correctly, but the target combobox is EMPTY: `GET /uom/units` returns camelCase (`isActive`) while the panel filters `unit.is_active`; the hand-rolled FE `Unit` type in `features/uom/api/uomApi.ts:9,28` declares snake_case (pre-existing type drift vs the API — one-surface-per-concept). Panel Vitest passed on a snake_case fixture → fixture-realism rule added to the fix brief. UX note: the count sums across all pending imports (two uploads → 1718) — FR2 labels it.
- Remaining before the units leg can close: FR2 → re-run "operator maps piece to pc, then imports the 856 quantity-valid rows with unit ids" on a fresh tenant.
