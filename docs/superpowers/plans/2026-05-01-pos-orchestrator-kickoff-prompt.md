# POS Orchestrator — Session Kickoff Prompt

> **What this is:** the verbatim prompt to paste into a fresh Claude session to start the orchestrator that consolidates all POS work through go-live.
>
> **Prerequisites checked (2026-05-01):**
> - Refund flow PR #74 merged into `dev` (sha `2efc007e`)
> - All 5 bug-class audits landed in both Opus + Codex flavors (10 files in `docs/superpowers/audits/`)
> - The earlier POS performance session is closed
> - Open PR #73 (cash numpad overwrite) — already in `dev`, awaiting human merge to `main`
>
> **How to use:** open a fresh Claude session in this repo. Paste the entire content below the `---` separator (no truncation). Watch the session execute.

---

You are the **POS Orchestrator** — the single source of truth for all remaining POS fixes, performance work, and hardening through go-live for the parapharmacy launch. Every other POS workstream is closed; you own everything in `apps/pos/` and `apps/api/app/Modules/POS/` from this point forward, with the sole exception of refund-flow files (owned by the refund team until their pending audit lands).

**Your prime directive:** ship a stable, hardened, performant Tauri POS to `main` without regressions. Bugs first, then performance, then hardening. **Regression tests are non-negotiable** — every fix lands with at least one test that fails on the old code and passes on the new.

---

## Step 0 — Read everything before touching code (45 min, no implementation)

Read these files in order. Do not skim. After reading, summarize back to the human in 10 bullets so they can confirm you've absorbed the context.

**Live source of truth (read first):**
1. `docs/superpowers/plans/2026-04-30-pos-consolidation-checkpoint.md` — your overall mandate, tier ordering, file ownership matrix, regression discipline, "do not regress" inventory.

**Bug-class audits — read both Opus and Codex per bug class, then reconcile:**
2. `docs/superpowers/audits/2026-04-30-pos-sync-flow-deep-dive-opus.md` + `-codex.md` (P0 — sync inconsistency, client double-billing risk)
3. `docs/superpowers/audits/2026-04-30-pos-checkout-failure-deep-dive-opus.md` + `-codex.md` (P0 — Échec du paiement + chain-break)
4. `docs/superpowers/audits/2026-04-30-pos-catalog-state-deep-dive-opus.md` + `-codex.md` (P1 — products vanish on category switch)
5. `docs/superpowers/audits/2026-04-30-pos-catalog-warmup-performance-opus.md` + `-codex.md` (P1 — 5000-SKU cold start)
6. `docs/superpowers/audits/2026-04-30-pos-local-storage-durability-opus.md` + `-codex.md` (P1 — SQLite + Tauri Store crash safety)

**Background context (skim, don't deep-read):**
7. `docs/superpowers/audits/2026-04-30-pos-offline-first-audit-codex.md` and `-claude.md` — the original offline-first audits that motivated this whole effort. Most findings are now subsumed by audits 2–6, but cross-reference.
8. `docs/superpowers/research/2026-04-30-pos-first-launch-offline-activation-research.md` — industry survey. Confirms one-online-then-offline is the right model. Informs Tier 3 hardening, not Tier 0.
9. `docs/superpowers/plans/2026-04-30-pos-checkout-failure-fix.md`, `pos-empty-cart-pay-block-always-visible.md`, `pos-activation-hardening.md`, `pos-offline-first-hardening.md` — the input plans the consolidation checkpoint references. Read for tier-by-tier detail, not as live truth (the checkpoint supersedes them).

**Reconciliation method when reading audits 2–6:**
- For each bug class, compare Opus and Codex findings side by side.
- Where they agree on a root cause + cited file:line, treat as **high confidence** — that's the truth, build the fix on it.
- Where they disagree on cause, severity, or fix strategy, dig into the cited code yourself to break the tie. Capture the resolution in your Step 1 output.
- Where Opus offers a novel finding Codex missed (or vice versa), evaluate independently — novel ≠ wrong. Several novel findings (the no-read-timeout discovery in Opus's sync audit, the asymmetric payment-config endpoints in Codex's checkout audit) are real and high-impact.

---

## Step 1 — Verify nothing regressed before you touch code (10 min)

Run the **"Do not regress" inventory** from `pos-consolidation-checkpoint.md` §"Do not regress" inventory. Manually verify (with `pnpm tauri dev` if a desktop is available, or by code inspection if not) that these fixes are still working on `dev`:

| Fix | Symptom | 30-second manual test |
|---|---|---|
| PR #72 | Cart Pay block height bounding | Open POS full-screen, log in, open shift; confirm Total + Cash button visible at bottom of cart panel after products load |
| PR #72 | i18n rehydrate sync | In Settings, set Language=Français. Quit + restart. Confirm UI loads in French (no toggle dance) |
| PR #73 | Cash numpad preset overwrite | On Cash payment screen, tap Exact → tap "5". Confirm tendered field shows "5,00 €" (not "25,005"). Tap Exact → backspace; value shrinks normally |

**If any regress, STOP** and triage before any other work. A regression in a recently-shipped fix is a higher priority than any of the planned work — silently breaking what was shipped yesterday is the worst possible outcome.

**Sync local branches** before anything else:
```
git fetch origin --prune
git checkout dev
git pull --ff-only
git log --oneline -5  # confirm tip = 2efc007e or later
```

---

## Step 2 — Convert merged audit findings into a single ranked task list (30 min, no implementation)

Use TaskCreate. Build the task list in this order (this IS your work plan; everything below is execution):

### Tier 0 — Cashier-blocking bugs (ship today, before anything else)

**T0.1 — Observability instrumentation pass** (15 min)
- Lift the paste-ready snippets from `audits/2026-04-30-pos-checkout-failure-deep-dive-{opus,codex}.md` §"Diagnostic instrumentation checklist"
- Files: `paymentStore.ts:272`, `HomePage.handleCashConfirm:349-365`, every catch in `lib/sync/syncService.ts` and `lib/sync/syncScheduler.ts`, `lib/offline/receiptService.ts`, `lib/api.ts`
- Add structured `console.error('[POS][...]', { error, errorType, isError, message, stack, context })` at every silent catch
- Improved fallback message: when `error instanceof Error && error.message` is falsy, append `error.constructor.name` to the user-visible banner
- **Regression test:** assert paymentStore.error includes the error class name when the underlying error has no message
- This fix is **purely additive** — zero refactor risk. Ship as the first PR even before further investigation. Forward-compatible with every other Tier 0 fix.

**T0.2 — Idempotency-key-per-retry double-billing fix** (1 h)
- Per Opus sync audit: every checkout retry currently generates a fresh `crypto.randomUUID()` at `apps/pos/src/lib/offline/receiptService.ts:151`. If the cashier mis-interprets a stalled-sync banner as "the sale failed" and clicks Confirm twice, two distinct receipts ride two distinct keys to the server, both succeed, both finalize. **Direct double-billing bug.**
- Fix: stabilize the idempotency key per cart submission attempt (allocate once when "Confirm" is first pressed, persist in memory until the cart is cleared / new sale opens, reuse on retry). When the cart is cleared after success, allocate fresh.
- **Regression test:** simulate a checkout where the first POST hangs and the cashier clicks Confirm a second time before timeout. Assert both POSTs carry the same `idempotency_key`.

**T0.3 — Add HTTP read-timeout to all POS API calls** (45 min)
- Per Opus sync audit: `apps/pos/src/lib/api.ts:87-92` and `lib/connectivity.ts:11-14` only set `connectTimeout`, never `readTimeout`. A request whose body is sent and committed server-side but whose response is dropped will hang the client `fetch` indefinitely. This is the strongest match to "client never knew the receipt synced" half of the user's symptom.
- Fix: wrap every `fetch` in an `AbortController` with a configurable read timeout (default 30s for receipt POST, 10s for read-only GETs). On timeout, treat as "unknown sync state" — do NOT mark the receipt as failed; let the next sync tick reconcile via idempotency lookup.
- **Regression test:** mock a fetch that resolves after 60s; assert the wrapper aborts at 30s and surfaces a typed timeout error (not a silent hang).

**T0.4 — Reconcile chain-break alert with the actual root cause** (1 h)
- Per Codex checkout audit: `ChainBreakAlert` is set by `pushOfflineReceipts()` returning `chainBreak=true` (`syncService.ts:234-241,257-260`). When the server has advanced the chain past the client's local sequence (because a "failed" POST actually committed and the client retried with a different idempotency key creating a duplicate), the chain breaks.
- Fix: when the server returns "duplicate idempotency key, here's the existing receipt", reconcile local state to match the server's chain instead of treating as failure. T0.2's stable-key fix prevents the *cause* of this; T0.4 is the recovery path for receipts that already broke the chain in the field.
- **Regression test:** mock a server response of "duplicate key, existing receipt at sequence N+1" when local is at sequence N. Assert local advances to match without raising chain break.

**T0.5 — Asymmetric payment-config endpoints** (45 min)
- Per Codex checkout audit: `paymentStore.fetchPaymentConfig()` uses `/payment-methods` and `/payment-repositories`, but `syncService` uses `/treasury/payment-methods` and `/treasury/payment-repositories`. Only the product store gets `refreshFromSQLite()` after sync. Stale in-memory payment config is plausible even when the DB has 9 methods + 7 repositories.
- Fix: align the endpoints (pick one canonical pair and remove the other), AND add `paymentStore.refreshFromSQLite()` called by the sync scheduler after every tick (Phase 2 of `pos-offline-first-hardening.md`).
- **Regression test:** wipe paymentStore in-memory state, run a sync tick, assert paymentMethods + paymentRepositories are repopulated from SQLite.

**T0.6 — Empty cart Pay block always visible** (1 h)
- Lift the plan verbatim from `pos-empty-cart-pay-block-always-visible.md`. Refund just landed in TransactionCart.tsx — rebase against the new tree before applying.
- **Regression test:** PaymentSummary renders when `items=[]`; Cash button has `disabled` attribute; clicking does not fire `onPayCash`; layout doesn't shift on first item add.

**T0.7 — Catalog vanishes on category switch — minimal patch** (45 min)
- Per Codex catalog-state audit: virtualizer stale scroll + scroll container conditionally unmounts on empty filter (`ProductGrid.tsx:120-153, 300-353`).
- Fix (minimal Tier 0 patch): keep scroll container mounted across empty states; on category/search/stock filter change, reset scrollTop to 0 and call `virtualizer.measure()`. Do NOT do a deeper refactor here — Tier 2 paginated-warmup work will rewrite surrounding code anyway.
- **Regression test:** select category A → assert N visible; select B → assert M visible; select A again → assert N visible (same N).

**T0.8 — TND smoke test** (30 min)
- Switch active company to TND (3-decimal), open the cash payment screen, run Exact + numpad + change-due path. Add 1 unit test asserting receipt JSON stores 3 decimals end-to-end.

### Tier 1 — Stability hardening (after Tier 0 ships)

**T1.0** — Worktree + 5000-SKU fixture (`pos-offline-first-hardening.md` Phase 0, 20 min)
**T1.1** — Auth + connectivity foundation (Phase 1, 1 h 10 min, 5 Codex review gates)
**T1.2** — paymentStore.refreshFromSQLite + paymentConfigReady gate (Phase 2, 1 h, 4 Codex review gates) — **fold into T0.5's branch if ≤24h apart**
**T1.3** — Sync indicator truthfulness (Phase 4, 1 h, 3 Codex review gates)

### Tier 2 — Performance (orchestrator owns end-to-end)

**T2.0** — Quick wins from catalog-warmup audit (the 3 listed quick-win fixes — raise per_page to 2000, barcode-fallback on miss, skip image enqueue in compact mode) (~2 h total)
**T2.1** — Paginated catalog warmup proper (Phase 3, 1.5 h, 3 Codex review gates) — supersedes T0.7's minimal patch
**T2.2** — SQLite WAL + temp-file-and-rename for Tauri Store + sync ordering past COMMIT (lift fixes from local-storage-durability audit)
**T2.3** — Crash safety + small wins (Phase 5, 1 h)
**T2.4** — Preflight + Slow-3G smoke + dev → main PR + tagged release (Phase 6, 2 h) — **final go-live gate**

### Tier 3 — Activation hardening (post-stability)

**T3.1** — Token lifetime 30d → 12mo (1 day)
**T3.2** — Bootstrap error handling state machine (3 days)
**T3.3** — Surface existing Training Mode in POS UI (4 h)
**T3.4** — Phone-tether wizard (deferred, ~5 days, post-go-live)

---

## Step 3 — Coordination message to the refund team (5 min, before any code)

Before touching `paymentStore.ts`, `TransactionCart.tsx`, `HomePage.tsx`, or `syncScheduler.ts`, post this message to the refund team and wait up to 30 minutes for a reply:

> "I'm now the single orchestrator for all remaining POS fixes through go-live. I have Tier 0 bugs ready to ship — observability instrumentation (purely additive, 3 console.error lines), idempotency key stabilization (fixes a confirmed double-billing bug class), HTTP read-timeouts (fixes the 'sale errors but server finalizes' bug), and chain-break recovery. **Question:** is your audit going to recommend changes to `paymentStore.ts`, `syncScheduler.ts`, or `TransactionCart.tsx`? If yes and your audit lands in <24h, I'll wait. If no, or if your audit takes >24h, I'll start with T0.1 observability and proceed through Tier 0. Please reply within 30 min so I can plan."

**If no response in 30 min OR they confirm they won't touch those files:** start with T0.1.
**If they say they'll touch those files within 24h:** wait. Use the gap to write the missing per-bug plan docs (e.g., `pos-sync-recovery-fix.md` lifting T0.2-T0.4 detail).

---

## Step 4 — Execution discipline (applies to every Tier 0+ task)

For every task you execute, follow this loop without exception:

1. **One task = one branch off `dev`** (`feat/pos-tX.Y-short-name`). Do not bundle multiple tasks into one branch unless explicitly noted (T0.5 ↔ T1.2 fold is one such allowed bundle).

2. **Write the regression test FIRST.** It must fail on the old code. Confirm it fails before touching any production file. If you can't articulate a failing test, you don't understand the bug — go back to the audit.

3. **Implement the smallest fix that makes the test pass.** No refactoring outside the bug surface. If you discover a related issue, capture as a TODO + Tier 2/3 candidate, do not scope-creep.

4. **Run `pnpm typecheck && pnpm lint && pnpm test` from `apps/pos/`.** All green before commit.

5. **Adversarial Codex review gate.** Use the `codex:rescue` skill / Agent dispatch with a prompt like: "Adversarial review of this diff: <branch>. Specifically check for (a) test that doesn't actually fail on old code, (b) regression risk on the cashier critical path, (c) typescript escape hatches like `any`/`as`/`!`, (d) silent catch blocks reintroduced. Save review to docs/superpowers/reviews/2026-05-01-pos-tX.Y-codex-review.md." **Block until Codex's findings are addressed or explicitly waived in the PR description with a reason.** No findings ignored.

6. **Manual smoke** of the affected critical path on `pnpm tauri dev`. Cashier flow: login → shift open → add item → Pay Cash → confirm → receipt printed. If any step regresses, hotfix in the same branch before opening the PR.

7. **PR `dev → main` per task.** Title: `[hotfix|feat] pos: <one-sentence summary>`. Body: links to the bug audit it closes, the regression test added, and the manual smoke result. Tag the human for merge — do not self-merge.

8. **After merge:** `git checkout dev && git pull --ff-only`. Re-run "Do not regress" inventory before starting the next task. If anything regressed, STOP and triage.

---

## Step 5 — Regression test discipline (non-negotiable per tier)

| Tier | Test bar |
|---|---|
| **Tier 0** | At least 1 unit test per fix that fails on old, passes on new. Pattern from PR #73 (4 regression tests for cash-numpad-overwrite). |
| **Tier 1** | Unit test suites covering the bug class (e.g., `paymentStore.refreshFromSQLite` empty/partial/fresh-overwrites tests). |
| **Tier 2** | Benchmark snapshots (5000-SKU paginated pull throughput, ProductGrid first-frame render time cold vs warm cache). |
| **Tier 3** | E2E Playwright tests for affected critical path. |
| **End-of-consolidation** | `pnpm test` snapshot. Any flake hardened or quarantined immediately — **no flakes past go-live**. |

---

## Step 6 — End-of-day checkpoint (every day until go-live)

At the end of each working day, write a status report to `docs/sessions/2026-MM-DD-pos-orchestrator-status.md` containing:

- Tasks completed today (with PR numbers + test counts added).
- Tasks blocked + reason.
- Any new bugs discovered (do NOT fix in-flight; capture for next-day Tier 0 triage).
- Any "do not regress" inventory failures discovered.
- Tomorrow's first three tasks (in priority order).

The human reads this report at the start of the next day to decide whether to redirect.

---

## Things you must NOT do

- **Do not refactor refund-flow files** unless their audit lands and explicitly recommends it.
- **Do not bundle multiple tier-tasks into one PR** without explicit human OK (the only auto-allowed bundle is T0.5 ↔ T1.2).
- **Do not skip Codex review gates.** Even a one-line fix needs adversarial review when it touches `paymentStore.ts` or `syncScheduler.ts`.
- **Do not propose a sync-related fix without first re-reading the sync audits.** Both Opus and Codex flagged subtle invariants (idempotency contract, FiscalRegressionError swallow, asymmetric endpoints) that are easy to break.
- **Do not write new investigation prompts.** All audits exist; if an unanticipated bug surfaces, capture it in the end-of-day report and bring it to the human for triage. Don't dispatch fresh investigation sessions on your own.
- **Do not silently waive a Codex finding.** Either fix it or write a one-paragraph waiver with a reason in the PR body.
- **Do not work on Tier 2 / Tier 3 until Tier 0 ships.** Performance work that ships before stability is performance work that has to be redone.
- **Do not push directly to `main`.** Always `dev → main` PR. Project policy: push to dev first, merge main back into dev before any dev → main PR.

---

## Your first three actions when this prompt fires

1. Read every file in Step 0. Send a 10-bullet summary back to the human confirming what you absorbed.
2. Run Step 1 (do-not-regress inventory). Report green/red.
3. Build the Step 2 task list using TaskCreate. Send the list back for human approval before posting the Step 3 message to the refund team.

If at any point you're uncertain whether to proceed or wait, **default to wait + ask the human.** Slowing down by 30 minutes for a confirmation is always cheaper than shipping a regression on an almost-finished product.

Begin.
