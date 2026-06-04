# Codex Adversarial Review — Sub-Spec C client foundation (recorded from Codex summary)

Diff: `git diff 2b5e540bd..a855ff702 -- apps/pos` (v46 outbox + recordAuditEvent helper + drain).
Verdict: **REQUEST-CHANGES** (high static confidence; Vitest hit an EPERM sandbox limit so the
agent could not save its own file — findings transcribed here by the controller, code-verified).

Counts: BLOCKER 0 | MAJOR 3 | MINOR 1 | NIT 1.

## MAJOR 1 — Dead-letter rows hidden from the pending count
`countPendingAuditEvents` (`queuedAuditEventRepository.ts:188-192`) filters
`status IN ('pending','failed') AND retry_count < MAX_AUDIT_RETRIES`, so retry-capped
(dead-letter) `failed` rows are NOT counted — contradicting its own doc comment that says
dead-letter rows "surface via countPendingAuditEvents". Backlog/dead-letter becomes invisible
to monitoring. **Fix:** count ALL non-synced rows (`status IN ('pending','failed','syncing')`,
no retry-cap filter) for visibility; keep the retry-cap filter only on the DRAIN selector
`getPendingAuditEvents`.

## MAJOR 2 — Wrong SQLite DB opened for the login-path audit event
`recordAuditEvent` stamps `companyId: input.companyId ?? auth.companyId` (line 64) but opens the
DB with `getDatabase(auth.companyId ?? '')` (line 79). For `pos.login` (where `input.companyId`
is passed explicitly because store state lags), the event is enqueued into the wrong/empty
company DB. **Fix:** resolve `companyId` once (`input.companyId ?? auth.companyId ?? null`) and
use it for BOTH the stamped row AND `getDatabase(...)`.

## MAJOR 3 — Drain loop has no time budget
`pushQueuedAuditEvents` caps at `MAX_AUDIT_BATCHES_PER_TICK=10` but has no elapsed-time guard;
on a slow network 10 batches can hold the sync tick a long time (spec requires a row/time
budget). **Fix:** add an elapsed-time break (e.g. stop the loop once `>2000ms` elapsed).

## MINOR / NIT
(Details not returned by the agent before the sandbox limit; the 3 MAJOR above are the
substantive findings. Fix round also re-scans the diff for any obvious smaller issues.)
