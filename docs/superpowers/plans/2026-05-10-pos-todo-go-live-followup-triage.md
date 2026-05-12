# `TODO(go-live-followup):` anchors — pre/post-launch triage

**Date:** 2026-05-10
**Status:** Initial triage. Individual fixes spawn separate PRs as the orchestrator schedules.

## Background

T2.2 PR #91 Step 5.3 left 10 deferred items as `// TODO(go-live-followup):` anchors across the apps/pos codebase. As of this triage, intervening PRs (PR #94 T2.1 Step D, PR #95 cash-drawer recovery) closed several anchors; PR #99 T2.5 added one new anchor (T2.7). Net 7 anchors remain.

Per the autonomous-mode prompt: "For each anchor: decide pre-launch (close before un-draft) or post-launch (keep annotation, possibly move to issue tracker)."

## The 7 active anchors

### Pre-launch candidates (Otospex-coupled)

#### 1. `apps/pos/src/lib/offline/receiptService.ts:250` — T2.7 training-aware local-first path

**Pre-launch IF Otospex co-launches alongside parapharmacy. Post-launch otherwise.**

The PR #99 T2.5 banner surfaces `is_training_mode` in the UI but the offline-first POS path doesn't honor it — sales on a training terminal still sync as production receipts. Compliance gap if a manager toggles a Otospex terminal into training mode for cashier practice.

- **Cost to close:** 2-3 days (kickoff doc landed: `2026-05-10-pos-t2.7-offline-training-receipts-kickoff.md`).
- **Mitigation if deferred:** PR #99 banner copy is conservative ("Test terminal — verify mode before each transaction"); makes no compliance promise. The cashier sees the terminal is non-standard but no false guarantees.
- **Recommendation:** queue T2.7 as the next workstream after the queue closes. Pre-launch IF Otospex go-live ships before the architectural fix; otherwise post-launch.

### Post-launch (deferred — keep annotations)

#### 2. `apps/pos/src/stores/terminalStore.ts:110` — Full offline cold-start

Today: first launch requires online connectivity for auth + initial catalog/payment-config seed; once seeded the terminal is offline-first.

- **Why post-launch:** the merchant runs this once per device; activation in a controlled-environment flow is acceptable.
- **Pre-launch trigger:** field signal that merchants frequently activate new terminals at remote locations without immediate connectivity.

#### 3. `apps/pos/src/components/atoms/SyncButton/SyncButton.tsx:31` — Animated sync-state transitions

Today: the green/amber dot pops in/out without easing.

- **Why post-launch:** purely cosmetic; not a correctness or compliance issue.
- **Effort:** small (~1h), pure CSS opacity/scale transitions.

#### 4. `apps/pos/src/lib/images/imageCache.ts:3` — Image preloading manifest

Today: each product image fetched lazily on first render via the download queue.

- **Why post-launch:** large catalogs see staggered network bursts but the experience is functional. No correctness issue.
- **Pre-launch trigger:** first-launch perf complaint from a 5000+ SKU parapharmacy.

#### 5. `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:71` — Optimistic +1 on pendingReceiptCount

Today: badge hydrates from SQLite every 60s sync tick.

- **Why post-launch:** ~60s visible delay between insert and badge update is annoying but not broken. Phase H Step 4.1 deferral.
- **Effort:** small (~30 min), with care around the rollback path (compensating −1).

#### 6. `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:121` — Stranded-receipt operator UI

Today: failed rows are visible in the badge count but have no inspection / retry / void surface.

- **Why post-launch:** failures are rare in the audit dataset. The cashier relies on the next automatic retry. PR #95 closed the `'syncing'` strand path; PR #94 closed the boot-time recovery for `'syncing'` receipts. So stranded rows are now bounded to genuine `'failed'` cases (e.g. server hash mismatch).
- **Pre-launch trigger:** field signal that managers need a hands-on UI for these.

#### 7. `apps/pos/src/lib/sync/syncService.ts:289` — Batch receipt push for backlog recovery

Today: one receipt per round-trip on the sync loop.

- **Why post-launch:** the parapharmacy's typical use is online + small backlogs (≤ tens). 1000-receipt backlogs only matter for a multi-day offline outage.
- **Effort:** medium (~1 day) — server already accepts batches; client-side just needs chunked-batch logic.

## Closed by intervening PRs

The original sweep listed 10 anchors. Three were closed by intervening work:

| Original anchor | Closed by | Notes |
|---|---|---|
| Stranded-syncing receipt recovery (`offlineReceiptRepository`) | PR #94 T2.1 Step D | `recoverStrandedSyncingReceipts` runs on boot. |
| Stranded-syncing cash-drawer recovery (`syncService` / `cashDrawerRepository`) | PR #95 | `recoverStrandedSyncingCashDrawerOps` runs on boot. |
| Server-side endpoint reconciliation (`/payment-methods` ↔ `/treasury/payment-methods`) | PR #91 T2.2 (Codex round-1 verified) | Already closed by T0.5 — no action needed. |

## Recommended action (for orchestrator)

- **Item 1 (T2.7 training-aware):** decide pre/post based on Otospex launch coupling. If pre, kick off the dedicated workstream now. If post, keep the annotation and the kickoff doc.
- **Items 2–7:** keep the inline `TODO(go-live-followup):` annotations as-is. Move to a tracking spreadsheet / issue tracker (e.g. Linear, GitHub Issues) for post-launch scheduling. The annotations themselves are the canonical signal at the code site; the tracker is just a discoverability aid.

## Out of scope (non-anchor follow-ups noted by past PRs)

These are mentioned in PR bodies but don't have inline `TODO(go-live-followup):` anchors. Listed here for orchestrator awareness; not part of the 7-anchor triage.

- Custom-dialog organism modal focus traps (`AdvancedPaymentsModal`, `DiscountModal` organism, `LineDiscountModal`) — deferred from PR #97 T2.1 modal focus trap.
- Per-token ability scoping for POS tokens (`['pos:*']`) — small follow-up to PR #96 T1.4 Path A.
- Periodic terminal-record refresh ratchet-down — PR #99 already wires `refreshTerminalRecord` into the sync tick; no further action.
- ESLint warn-to-error ratchet-down for cleaned rules — ongoing tech debt from PR #100 T2.6.
