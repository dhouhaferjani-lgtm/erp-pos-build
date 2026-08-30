# G-2 SKU generation — local browser verification (scripted Playwright), 2026-08-30

Stack: G-2 worktree on API :8011 (`QUEUE_CONNECTION=sync`) + Vite :5174; fresh tenant via POST /auth/register (TN/retail). The lane (Codex) wrote the spec + sample files (`apps/web/e2e-local/sku-generation.spec.ts`, `blank-distinct.csv`, `same-name.csv`, `cjk-supplied.csv`); the Codex sandbox cannot launch Chrome, so the orchestrator ran it (config fix: baseURL under `use`).

      ✓  1 [chromium] › e2e-local/sku-generation.spec.ts:78:1 › blank SKUs generate once, merge by name, re-import idempotently, and preserve supplied values (43.2s)
      1 passed (43.8s)

Covers: blank SKUs generate once per company (`SKU-000001…`), same-name blank-SKU rows merge into one product, a second identical import allocates nothing (idempotent sequence), supplied SKUs (incl. CJK) are preserved.

## Run 2 — post fix round 1 (after laptop reboot), 2026-08-30 18:41 — **1/1 PASS** (company-B leg included)
Stack relaunched from the dirty `.worktrees/g2-sku` (API :8011 `QUEUE_CONNECTION=sync`, Vite :5174). Tenant registered by the orchestrator via POST /auth/register (TN/retail) and passed as `E2E_EMAIL/E2E_PASSWORD/E2E_COMPANY_ID/E2E_TENANT_DB` (tenant DB name keeps the hyphenated uuid: `tenant<uuid>`; company id read from `companies` in that DB).
- `blank SKUs generate once, merge by name, re-import idempotently, and preserve supplied values` — PASS (1.4m). Covers, on top of run 1: in-file warning trail `1|matched_by_name|Matched the in-file winner by normalized name.` / `1|duplicate_in_file|A later row … (row 2).` / `2|sku_generated|SKU-000003` (G2-R1-03); second company created through the real API — its first blank-SKU import yields `SKU-000001` again with `sku_sequences.next_value = 2`, and the re-import allocates nothing (G2-R1-05).
- Two harness fixes, not product defects: (a) the DB-truth SQL used `'|' || w.item->>'code'` — PG gives `||` and `->>` equal precedence so it parsed as `('|' || item) ->> 'code'` (`text ->> unknown`); parenthesised (`sku-generation.spec.ts:136`). (b) `expect.timeout` raised to 30s in `e2e-local/pw.config.ts` — on the post-reboot load (avg ~18) the completion screen appeared just after the default 5s window (page snapshot showed `Import Complete!` / 2 imported; DB had `SKU-000001/2`).
- Ops note: `throttle:register` is 5 per 15 min per IP (`AppServiceProvider.php:315`) — three quick registrations while debugging tripped it; cleared with `php artisan cache:clear` in the worktree (only these lanes use the local redis).
- Next: gate r2 (imports reviewer, register `2026-08-30-g2-gate-r2.md`).
