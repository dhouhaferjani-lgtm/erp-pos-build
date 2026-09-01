# Wave 2 — Purchase-order full flow — evidence record (Session L, 2026-09-01)

Spec under test: `docs/superpowers/audits/2026-09-01-wave2-po-flow/02-scenario-matrix.md` **rev 4, gated** (ACCEPT-WITH-CONDITIONS r4, conditions applied — `925f280ab`). Code truth: `01-research.md` (findings `F-W2-01..36`). Gate trail: `docs/superpowers/reviews/2026-09-01-wave2-po-spec-gate-r1..r4*.md`.

Rules of this record (owner, 2026-08-31): a class is "confirmed working" only when every row has committed evidence — `[measured]` beside `[derived]`, screenshot names, raw SQL + output, and a PASS / FAIL / BLOCKED verdict. Every browser leg captures 5xx and console errors and asserts zero; the only tolerated console line is the `/auth/me` 401 on `/login` (until K-10 merges) and it is named on every leg that tolerates it. `[RULING]` figures are recorded, not asserted.

## Environment
| Item | Value |
|---|---|
| Worktree / branch | `.worktrees/L-po-flow` · `test/L-po-flow` @ `925f280ab` (== dev `62964e5cc` + docs) |
| Stack | vite `http://localhost:5178` → API `http://localhost:8015`, `QUEUE_CONNECTION=sync`, PG `127.0.0.1:5433`, Redis `:6380` |
| Harness | `apps/web/e2e-local/pw.config.ts` (chromium, workers 1, serial), `wave2-support.ts`, `wave2-po.part1.spec.ts` / `.part2.spec.ts` (git-excluded; authored by Codex lane L-0, run by the orchestrator) |
| Oracle | psql on `tenant<uuid>`; tables verified present 2026-09-01 (`goods_receipts`, `goods_receipt_lines(movement_id, free_movement_id)`, `product_batches`, `inventory_batch_stock`, `journal_entries(source_type, source_id, status)`, `journal_lines`, `document_additional_costs`, `repository_movements`, `payments`, `payment_allocations`, `stock_movements`, `stock_levels`, `document_tax_details`) |
| Fixtures | fresh TN/parapharmacy tenant per part via `POST /auth/register`; suppliers from the real `État des Fournisseurs (1).xlsx` (11 rows) via the parties import wizard; per-class virgin SKUs `P-<CLASS>-n`; second company via the in-app company path (never a second registration) |

## Run log
| Run | Date/time | Part | Tenant | Result | Notes |
|---|---|---|---|---|---|
| — | — | — | — | — | (no run yet — L-0 part 1 being authored) |

## Findings promoted to MEASURED
| ID | Sev | Row(s) | Measured | Status |
|---|---|---|---|---|
| — | — | — | — | — |

## Part 1 — SETUP · HP · TOT · PART · LOT · OVER · UNDER
_(per-row evidence pasted from `test-results-local/wave2/part1-evidence.md` after each run)_

## Part 2 — LOC · DRAFT · REV · PRICE · LAND · VAT · DISC · MATCH · IFIRST · IDEM · SEC · PERM · WDIL · EDGE
_(not started)_

## Cross-wave flags (not fixed here)
| Finding | Owner lane | Evidence row |
|---|---|---|
| F-W2-07 Total-mode gross `line_total` (K1-S1-12) | K-1 task 4.2 | W2-TOT-* |
| F-W2-02 receive-all batch shape (F-SOE-2) — API-only, UUID leak | wave-2 fix brief (L-*) after evidence | W2-LOT-* |
