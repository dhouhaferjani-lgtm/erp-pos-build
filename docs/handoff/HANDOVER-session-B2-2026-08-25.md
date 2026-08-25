# HANDOVER — Session B2: state-machine fixes, continuation (2026-08-25)

> You are Session B2, the direct continuation of Session B (2026-08-23 → 08-25), which merged Wave 1 (Q-1..Q-13) + Slice D-1 to LOCAL
> dev (tip `524e2f477` at hand-off). Sessions A (first-tenant launch + promotion), C (owner-decisions program) and W (imports/openings
> lanes) are LIVE on this machine and repo. Everything below is on dev; nothing is pushed (S-17: CI-blind, never push origin/dev).

## 0. Read first (in order)
1. `docs/handoff/SESSION-B-DELIVERABLE-2026-08-24.md` — the state ledger: merged-lane table, the honest CHECK census (245 / baseline 188), owner items.
2. `docs/handoff/LEDGER.md` rows **C-27** (🚨), C-26, C-24, C-28, C-30, O-30, O-31, S-19, S-20 — your queue's entry conditions live there.
3. `docs/sessions/session-B-2026-08-23/LANE-PROTOCOL.md` (implementer contract, unchanged) + `BRIEF-*.md` as shape references; `SESSION-LOG.md` tail for the machine/process lessons.
4. Gate records `docs/superpowers/reviews/2026-08-25-sb-*.md` (D-1 fiscal/tenancy/r2, Q-11, Q-12) — the fix shapes and residuals you inherit.
5. Memory: `project_session_b_state_machine_fixes_2026_08_23.md`.

## 1. Queue (strict order; one lane at a time)
| # | Lane | Why now | Entry condition | Gate |
|---|---|---|---|---|
| **B2-1** | **C-27 journal-entry numbering** — `GeneralLedgerService::generateEntryNumber` (`:5339-5356`) AND `IncomeService.php:206-224`: tenant-keyed `pg_advisory_xact_lock` + tenant-scoped max+1 (copy Q-11 `1f76745e8` exactly), tenant lock taken BEFORE the company chain lock (`:3742` ordering). Red-first: two companies, one tenant, two JE-minting posts → 23505 today. Census + fix shape are in `2026-08-25-sb-q11-supplier-invoice-gate-r1.md` §C. | The 2nd company of ANY tenant cannot post a JE; 43 GL paths. Launch-exposed the day a second company exists. | none — dispatch immediately. Check the register first: Session A must not be touching `GeneralLedgerService` (its N-6 lane is treasury-adjacent — `git diff --stat dev...fix/campaign-n6-payment-advance -- apps/api/app/Modules/Accounting`). | treasury + stock-gl-interaction (dual — the entry-number path is shared by inventory postings) |
| B2-2 | **C-26 parser preconditions** for the parity ratchet: R2-1 AND-compound CHECK false-COVERED (`PgValueSetCheckReader.php:145,161` — split on top-level AND, refuse multi-column) + a liveness pin; wire `EnumCheckParityTest` into `treasury-spine-pgsql` (S-14 leg — report, do not edit `.github/**`). | Must precede any CHECK-adding batch. | none | fiscal-pos (test-only lane) |
| B2-3 | **O-31 owner blob** — owner action; you prepare the seed (the three baseline artifacts at dev tip) and the exact repo-variable + pin-tag steps, modelled on `DocumentPerActionBaselineRatchetTest.php:114-149`. | Without it acknowledgements are deletable for free (R2-2). | owner reply | parent-verified |
| B2-4 | **Slice D batch 1** — money/fiscal columns from baseline 188: `vouchers.status`, `journal_entries.status`, `payments.status`, `documents.type`, `instrument_events.from/to_status` …; per-tenant distinct-value census FIRST, `NOT VALID` + separate `VALIDATE`, baseline shrinks per batch. | The owner's actual question. | B2-2 + B2-3 done | treasury + fiscal-pos |
| B2-5 | **C-24 manual single-period close** endpoint (Company lane) — prerequisite for the reopen UI; a reopened period currently holds its fiscal year open forever. | | none | treasury |
| B2-6 | Residual sweep: C-14 (manualOverride lock), C-15 (void-edge 7-col guard), C-16 (held-order terminal scope web), C-17 (device binding invalidation — POS lane), C-19/C-20 (KDS 422 + Tables-behind-Menu), C-28 (Re-match FE), C-30 (`default_journal_id` phantom writer). | | | per lane |

## 2. Collision matrix (2026-08-25)
- **Session A** (`fix/campaign-n1-vat-resolution`, `fix/campaign-n6-payment-advance`, Playwright wave 2, promotion): off-limits = VAT resolution, StockLevel read paths, payments/advances reclass, opening-balance wizard, web document pages, PIN/has_pins, X/Z. **Before B2-1, grep A's branches for `GeneralLedgerService`/`IncomeService` edits.**
- **Session C** (owner-decisions OQ-*, credit-note chain-fork C-22): off-limits = credit-note lifecycle, `OWNER-DECISIONS-*.md` files.
- **Session W** (`w23-import-category`, `w27-default-batch`, `w43-openings`, `w49-pos-vat-gl`): off-limits = imports, batches, openings, POS VAT→GL.
- **LEDGER ids collide across four sessions**: grep the tail immediately BEFORE and AFTER every row commit; the later writer renumbers. Commit record files the instant they are written (three of Session B's were swept into other sessions' commits).

## 3. Machine + process rules (post-panic, still in force)
- **≤ 1 stream (implementer OR reviewer) while the link is the hotspot (gateway 172.19.x) or jittery (> 300 ms stddev); ≤ 3 on a stable link**, shared with the other sessions. Check `route -n get default` + `ping -c 5 8.8.8.8` before every dispatch.
- **When a stream dies (ENOTFOUND / 600 s watchdog / connection lost): RESUME the agent via SendMessage — its context is intact — never cold re-dispatch.** Tell agents: short tool calls (< 90 s), one test file at a time.
- Worktrees off dev under `.worktrees/sb2-*`, REAL `vendor` copy + `.env`, `ReflectionClass` resolution check; tests BY PATH only, never the full suite; PG legs on 5433 on your own throwaway DB named `^autoerp_[a-z0-9_]*test$`; no stash; never push.
- Post-merge protocol: lane tests by path on merged dev → `php apps/api/tools/feature-lane-manifest-check.php` EXIT=0 (resolve the manifest from LIVE dev numbers + your delta, never from the branch's stale base) → lint ratchets if web/pos touched → register row + LEDGER + session log, committed at once. Migration-bearing lanes: pre-flight scan docblock + census; a green-field tenant makes these formalities (owner ruling 2026-08-24) but the docblock stays.
- Process census: `pgrep -fl 'vitest|phpunit' | grep -o 'worktrees/[a-z0-9-]*' | sort | uniq -c` (a bare `wc -l` over-counts). Kill vitest pools after every web/pos run.

## 4. Deliverable at the end of B2
(a) C-27 merged with gate record + register row (tell Session A in the register the multi-company block is lifted); (b) batch-1 CHECK count merged and the baseline shrunk (188 → n); (c) O-31 seed + steps handed to the owner; (d) the spec's §R questions still outstanding — list them for the owner in one place.
