# G-2 SKU generation — local browser verification (scripted Playwright), 2026-08-30

Stack: G-2 worktree on API :8011 (`QUEUE_CONNECTION=sync`) + Vite :5174; fresh tenant via POST /auth/register (TN/retail). The lane (Codex) wrote the spec + sample files (`apps/web/e2e-local/sku-generation.spec.ts`, `blank-distinct.csv`, `same-name.csv`, `cjk-supplied.csv`); the Codex sandbox cannot launch Chrome, so the orchestrator ran it (config fix: baseURL under `use`).

      ✓  1 [chromium] › e2e-local/sku-generation.spec.ts:78:1 › blank SKUs generate once, merge by name, re-import idempotently, and preserve supplied values (43.2s)
      1 passed (43.8s)

Covers: blank SKUs generate once per company (`SKU-000001…`), same-name blank-SKU rows merge into one product, a second identical import allocates nothing (idempotent sequence), supplied SKUs (incl. CJK) are preserved.
