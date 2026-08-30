# G-5 opening-stock correction fence — local browser verification (scripted Playwright), 2026-08-30

Stack: G-5 worktree on API :8012 (`QUEUE_CONNECTION=sync`) + Vite :5175; fresh tenant per run via POST /auth/register. The lane (Codex) wrote `apps/web/e2e-local/g5.spec.ts` + `opening-first.csv` / `opening-corrected.csv` / `opening-locked.csv`; the Codex sandbox cannot launch Chrome, so the orchestrator ran it.

## Run 1 — API/DB leg PASS, UI leg found 2 defects
- PASS (API/DB): first import opens stock; re-import with `override` and no operations → `opening_corrected`, quantity 7.0000 (API and DB), warning present in the result workbook; after a real operation the re-import row is REFUSED: `opening_locked | opening_locked_has_operations | operations exist for this product; correct the stock with a stock adjustment`.
- Spec fix (not a product defect): the completion warning wording is "…opening-stock instructions corrected an existing opening." (locale `warnings.opening_corrected`).
- DEFECT 1 (backend): `ImportService.php:435` counts execution errors only for outcome `failed`, so an `opening_locked` row leaves `execution_error_count = 0` while `failed_rows = 1` and job `status = failed`.
- DEFECT 2 (frontend): the completion screen showed "Import Complete! Your data has been successfully imported." with tiles 0 / 0 / 0 for that failed job (the toast said "Import failed") — tiles read the execute response before the job counters and the header ignores job status.
Fix round 1 dispatched (both; the lane's `.tsx` restriction lifted for the completion step).
