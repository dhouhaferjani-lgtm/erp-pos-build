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
