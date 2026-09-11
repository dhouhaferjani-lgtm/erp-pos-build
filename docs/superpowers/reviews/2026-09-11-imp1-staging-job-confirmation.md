# IMP-1 — staging job confirmation (orchestrator read-only query, 2026-09-11 ~00:40)

Closes the IMP-1 handback's outstanding item ("the exact PharmaBio staging job was not queried"). Read via the exposed staging PostgreSQL port (Dokploy `ERP Staging PostgreSQL`, credentials re-fetched from Dokploy at query time; never stored). Tenant DB `tenant019ee4d7-0814-72fa-a109-256fd9973249` (company "PharmaBio Tunisie SARL").

```
import_jobs.id            01a08a91-5aed-70b4-b434-a8d6cddb8e13
type                      parties
status                    failed
original_filename         Fournisseurs_Realistes_200.xlsx
total/processed/success   200 / 200 / 200
failed_rows / skipped     0 / 0
error_code                (empty)
error_detail              (empty)
error_message             Job failed: CurrencyScaleResolver::getScale() called with no currency code and no
                          CompanyContext bound. Ensure CompanyContextMiddleware is applied or pass an explicit
                          $currencyCode. For callers that intentionally run outside request context (queued jobs,
                          console commands), use getScaleSafe($currencyCode, $fallback) instead. See audit finding F-RES-1.
created / worker_started  2026-09-10 09:06:16 / 09:06:28 (UTC; the screenshot's 10:06:16 is Tunis time)
started / completed       09:06:25 / 09:06:31
```

**Confirmed mechanism:** all 200 supplier rows were committed, then the finalize phase (`PartiesBalancesPhase` for suppliers, run inside the queued `ProcessImportJob`) called `CurrencyScaleResolver::getScale()` with no currency and no `CompanyContext` (CLAUDE.md rule 19/20: queued jobs run with NO CompanyContext — pass the entity currency or use `getScaleSafe`). The exception propagated to `ProcessImportJob::failed()`, which stamped `status = failed` with the counters intact — hence "Échec, 200 imported / 0 failed". `error_code` and `error_detail` were never set (only `error_message`), which is why the history row could not explain itself.

**Consequences for the IMP-1 gate and follow-ups:**
1. The local fixture in the lane ("suppliers-with-balances reproduces the failure without fault injection") reproduces the SAME root cause — the reviewers should verify the local repro throws from the same resolver call path, not a different one.
2. The finalize-phase bug itself is a rule-19/20 defect in the parties balances phase (`getScale()` bare call) — owned by the followups ticket `docs/superpowers/tickets/2026-09-11-imp1-followups.md` ("underlying supplier currency-context/finalization defect"); the fix is `getScaleSafe($company->currency, 3)` / explicit currency at that call site plus a queued-context test (rule 20: `app(CompanyContext::class)->clear()` before the phase in the test). Whether IMP-1 fixes it now or tickets it is the reviewers' input to the orchestrator; the `partially_completed` status + job-level error surfacing (the packet's items) stand regardless.
3. Staging data is demo-only (memory: 5 tenants, no real clients); no cleanup action taken; no write performed.
