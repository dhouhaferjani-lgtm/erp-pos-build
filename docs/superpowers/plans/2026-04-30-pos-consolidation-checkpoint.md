# POS — Consolidation Checkpoint (2026-04-30)

> **This is the briefing-pack for the orchestrator session — the single source of truth for all remaining POS fixes, performance work, and hardening through go-live.** It supersedes earlier roadmap docs. Older plans (linked below) remain as input artifacts but should not be treated as actionable until they're re-mapped onto the post-refund tree.
>
> **Operating model: single orchestrator session.** All other POS sessions (refund flow, POS performance) are closed. The orchestrator owns every change to `apps/pos` and `apps/api/app/Modules/POS` from this point until go-live. **No parallel POS sessions** — eliminates the cross-session conflict matrix entirely and gives the orchestrator deterministic control over diff scope, regression-test ordering, and Codex-review gating.
>
> **Branch to work on:** `dev`. The refund flow PR #74 has merged into `dev` (sha `2efc007e`). `origin/dev` is currently **68 commits ahead** of `origin/main`. All consolidation work continues on `dev` per project policy (push to dev first, merge main back into dev before any dev → main promotion).
>
> **Active PR:** #73 (cash numpad overwrites preset) is still OPEN against main. It's already in `dev`. When the human merges #73, dev no longer needs to integrate it back, but `git pull --ff-only origin main && git merge main` should be done on dev right before any `dev → main` consolidation PR.
>
> **The earlier POS performance session is being killed.** Its in-flight work (if any) should be considered abandoned; the orchestrator restarts performance work from scratch (Tier 2 below) using the offline-first hardening plan as input. Do not attempt to resurrect partial branches from that session.

---

## Where we are right now (2026-04-30)

### Shipped to main
| PR | What | Merged |
|---|---|---|
| #72 | Cart Pay-block visibility on full-screen Tauri + i18n rehydrate sync | ✅ Merged |
| #73 | Cash numpad overwrites preset after Exact / denomination tap | 🟡 OPEN — already in `dev`, awaiting human merge to main |

### Landed in dev (not yet promoted to main)
- PR #72 + PR #73 (above)
- **PR #74 — refund flow (4 sessions, just merged into dev)** — sha `2efc007e`
- 65 other commits accumulated on dev since last `dev → main`

### Active workstreams alongside this session
1. **Refund flow** — 4th and final session done; **smoke tests + adversarial audit pending** before refund is considered "stable on dev." Owned by the refund team — orchestrator does not touch refund-specific files (return modals, refund store, credit-note flows) unless audit findings explicitly require it.
2. **POS performance** — earlier session **killed**. The orchestrator owns all performance work from now on (Tier 2 of this brief). Restart from scratch using the offline-first hardening plan as input.

### Known live bugs that the next session must address
1. **Échec du paiement** — pink banner on cash payment screen after Exact + Confirm. Cashier cannot complete a sale on dev. Diagnostic plan exists ([`pos-checkout-failure-fix.md`](2026-04-30-pos-checkout-failure-fix.md)). Console log is silent because `paymentStore.ts:272–278` swallows the error before any `console.error` runs — first 15 minutes of the new session is the observability fix that surfaces the real cause.
2. **"Fiscal chain has been interrupted"** — pink banner on home page above the cart. The paired audits agree this is set from receipt push results (`chain_broken`) rather than `pullTerminalState()`. It is probably a separate immediate root cause from cash checkout failure, but both converge through bad local receipt state, SQLite write contention, and cashier retries.
3. **Empty cart Pay block hides** — on an empty cart, `PaymentSummary` is unmounted; industry-standard is always-visible-greyed-out. Plan exists ([`pos-empty-cart-pay-block-always-visible.md`](2026-04-30-pos-empty-cart-pay-block-always-visible.md)). User has hit this twice; ship in Tier 0.
4. **Sync inconsistency: sale errors in POS but lands as finalized on web** — the audits agree `/pos/receipts/sync` is idempotent, so the biggest double-billing risk is not duplicate POSTs with the same key. It is client retry semantics: each cashier retry creates a new local receipt and a new `idempotency_key`, while missing read/overall HTTP timeouts and orphaned `status='syncing'` rows leave the client uncertain whether the first sale committed.
5. **Catalog state inconsistency: products vanish after category switch** — tie-break result: fix both layers. First, stop `productStore.refreshFromSQLite()` and foreground revalidation from replacing menu-derived category rows with generic `/products` cache rows. Second, reset/re-measure `ProductGrid` when category/search/in-stock filters change and keep the scroll container mounted across empty results. The store issue is more likely for Menu tenants; the virtualizer issue is a cheap defensive hotfix for all tenants.

### Merged Audit Inputs (2026-05-03)

The five Codex audits and five Opus audits now exist under `docs/superpowers/audits/2026-04-30-*`. High-confidence agreements:

1. **Checkout observability is the first fix.** `paymentStore` catches non-`Error` Tauri/SQLite rejections, shows generic `Échec du paiement`, then `HomePage` silently catches the rethrow. Add structured `console.error` and preserve non-Error messages before deeper fixes.
2. **Receipt sync idempotency is mostly sound on `/pos/receipts/sync`.** Treat `synced` and `duplicate` as success, but add guardrails so user retries do not mint new receipts for the same physical sale.
3. **`status='syncing'` is unsafe across hangs and crashes.** Add boot-time requeue/reconciliation before any release that claims offline durability.
4. **Foreground catalog warmup is capped at 500 SKUs.** Full pagination exists only in background sync; Tier 2 must move foreground warmup to the paginated SQLite-first path.
5. **Durability depends on defaults.** Explicitly assert SQLite PRAGMAs, move sync scheduling after `COMMIT`, and add corruption/recovery checks.

---

## Coordination with the refund team

The orchestrator owns POS fixes; the refund team owns refund-specific files. Two coordination points only:

1. **Before C0.1 (Tier 0 observability fix):** post a message to the refund team's session with the draft below. Wait for their answer before touching `paymentStore.ts`, `TransactionCart.tsx`, or `HomePage.tsx` — files refund's audit is likely to recommend changing.
2. **After every Tier 0 + Tier 1 fix lands on dev:** notify the refund team so they can rebase if their audit produces a follow-up patch.

### Draft message to the refund team

> "I'm now the single orchestrator for all remaining POS fixes through go-live (the earlier POS performance session is closed; one source of truth from here). I have Tier 0 bugs ready to diagnose + fix on `dev` — `Échec du paiement` blocks all checkout including your refund smoke testing, plus a sync-inconsistency bug class (POS errors but server finalizes) and a catalog-vanishes-on-category-switch bug.
>
> **Question:** what's the timeline for your adversarial audit, and what files do you expect it to recommend changing? If `paymentStore.ts`, `TransactionCart.tsx`, `HomePage.tsx`, or `syncScheduler.ts` are likely audit targets, I'd rather wait <24h for findings than double-touch. If your audit is going to take >24h or won't touch those files, I'll start with a purely-additive observability instrumentation pass (3 `console.error` lines, no refactor) so the next reproduction shows the actual error. That fix is forward-compatible with any refactor your audit may demand. Tell me which path."

### Decision tree based on the refund team's answer

| Refund team says | Orchestrator does |
|---|---|
| "Audit lands in <24h, will touch paymentStore" | **Wait.** Use the gap to write the sync-flow deep-dive investigation doc (no code changes). Reproduce + document each bug with screenshots and console logs. |
| "Audit lands in <24h, will NOT touch paymentStore" | **Start C0.1** (observability fix). Do not touch refund-specific files. |
| "Audit takes >24h" | **Start C0.1 immediately.** Cashier blocker is the bigger problem. Refund team rebases against orchestrator's commits when their audit lands. |
| No response in 30 min | **Start C0.1.** Observability fix is additive. If audit later forces a refactor, the diagnostic instrumentation stays valid. |

---

## Tier 0 — Immediate post-consolidation work (cashier-blocking)

Run before any other tier. Each item ships on its own commit, with regression tests, and lands as its own small PR (or a single hotfix branch with separable commits).

| # | Item | Plan reference | Effort | Notes |
|---|---|---|---|---|
| C0.1 | Diagnose Échec du paiement — observability fix | [`pos-checkout-failure-fix.md`](2026-04-30-pos-checkout-failure-fix.md) §Step 1 + checkout audits | 15 min | **Start here.** Add structured `console.error` in `processCashCheckout`, `processCardCheckout`, `processAdvancedCheckout`, `HomePage` cash/advanced handlers, `createReceiptLocalFirst`, `createOfflineReceipt`, `syncService`, `syncStore`, and `api.ts`. Preserve non-`Error` Tauri/SQLite messages in `paymentStore.error`; do not replace them with generic i18n. |
| C0.2 | Reproduce + categorize checkout failure | Checkout audits | 15 min | Do not assume `FiscalRegressionError`. The highest-likelihood causes are Tauri SQLite string rejections in local receipt creation: NOT NULL payment config, `database is locked`, failed `BEGIN`, failed `ROLLBACK`, or terminal-state read failure. Chain-break is push-time/server-detected and probably separate from the cash modal failure. |
| C0.3 | Targeted checkout/config fix | Checkout audits + §C1.2 | 30 min – 2 h | Prioritize payment-config readiness and endpoint drift: foreground uses `/payment-methods` and `/payment-repositories`, while scheduler uses `/treasury/payment-*`. Fix the scheduler route or server routing, then refresh in-memory payment config from SQLite after sync. If logs show SQLite lock/transaction errors, make `ROLLBACK` best-effort and add a local receipt write mutex. |
| C0.4 | Empty cart Pay block always-visible | [`pos-empty-cart-pay-block-always-visible.md`](2026-04-30-pos-empty-cart-pay-block-always-visible.md) | 1 – 2 h | Touches `TransactionCart.tsx`. Refund just landed there — rebase against the new tree before applying. Pattern: remove `{items.length > 0 && ...}` guard around `<PaymentSummary>`, pass `disabled={checkoutDisabled || items.length === 0}`. |
| C0.5 | TND smoke test (currency precision verify) | [`pos-checkout-failure-fix.md`](2026-04-30-pos-checkout-failure-fix.md) §Currency precision | 30 min | Switch active company to TND, run a 3-decimal Exact + numpad + change-due path. Add 1 unit test asserting receipt JSON stores 3 decimals end-to-end. |
| C0.6 | Sync inconsistency: POS error vs server finalized — fix certainty gaps | Sync-flow audits | 1 – 2 h | Add read/overall timeout support to POS API requests; add boot-time reaper for orphaned `status='syncing'` receipts; make pending/stuck receipt counts SQLite-backed; suppress duplicate cashier retries while a same-cart receipt is `pending` or `syncing`. Do not spend time making `/pos/receipts/sync` idempotent; it already is. Add a guard or deletion plan for dead online `/pos/receipts` client code so future imports do not reintroduce non-idempotent checkout. |
| C0.7 | Catalog state inconsistency: products vanish after category switch | Catalog-state audits | 1 – 2 h | Implement two-part hotfix. Store layer: for Menu tenants, do not refresh the displayed menu catalog from generic SQLite `/products` rows that collapse category/menu context; keep a menu-specific cache/source or skip `refreshFromSQLite()` until C2.1 rewires catalog warmup. UI layer: on category/search/in-stock/display-mode changes, scroll to top and `virtualizer.measure()`, and keep the scroll container mounted even when filtered results are empty. Add regression tests for menu category round-trip and virtualizer empty→non-empty transition. |

---

## Tier 1 — Stability hardening

The minimum-viable subset from the offline-first hardening plan. Ships as one feature branch with phase-by-phase Codex review gates.

| # | Item | Plan reference | Effort | Notes |
|---|---|---|---|---|
| C1.0 | Phase 0 — worktree + 5000-SKU fixture | [`pos-offline-first-hardening.md`](2026-04-30-pos-offline-first-hardening.md) §Phase 0 | 20 min | Prereq for C1.1–C2.2. |
| C1.1 | Phase 1 — auth + connectivity foundation | §Phase 1 + sync audits | ~1h 10min | Keep the planned tests and include AbortController/read-timeout coverage for `api.ts`; connect timeout alone is not enough. |
| C1.2 | Phase 2 — paymentStore.refreshFromSQLite + paymentConfigReady gate | §Phase 2 + checkout audits | ~1 h | Must include payment-config endpoint drift fix and post-sync SQLite→Zustand refresh. Gate checkout until methods/repositories are loaded from either API or SQLite. |
| C1.3 | Phase 4 — sync indicator truthfulness | §Phase 4 + sync/durability audits | ~1 h | Count `pending`, `failed`, stuck, and orphaned `syncing` rows from SQLite, not in-memory result counters. Surface chain-break/stuck receipt recovery state instead of a generic persistent banner. |

**Minimum-viable shippable subset:** C1.1 + C1.2 + C1.3 plus C0.6's `syncing` reaper close the three highest-pain failures from the audits and produce a shippable build. Phase 3 (catalog pagination) should follow immediately for parapharmacy; Phase 5 (crash safety) must land before claiming offline durability.

---

## Tier 2 — Performance (orchestrator owns end-to-end)

The earlier POS performance session is closed. The orchestrator restarts performance work from scratch using the offline-first hardening plan as input. No external coordination needed; all changes here belong to the orchestrator.

| # | Item | Plan reference | Effort | Notes |
|---|---|---|---|---|
| C2.1 | Phase 3 — paginated catalog warmup for 5000 SKUs | [`pos-offline-first-hardening.md`](2026-04-30-pos-offline-first-hardening.md) §Phase 3 + catalog audits | ~2 h | Replace foreground `fetchPOSProducts({ limit: 500 })` with the paginated `pullProducts(db)` + `refreshFromSQLite()` path; add progress (`loaded/expected`) and avoid duplicate foreground plus scheduler traffic. Consider a POS-projected product endpoint to reduce wire size. |
| C2.2 | Phase 5 — crash safety + small wins | §Phase 5 + durability audits | ~1 – 2 h | Explicitly assert SQLite `journal_mode=WAL`, `synchronous=FULL`, and `busy_timeout`; move `scheduleDebouncedSync()` after `COMMIT`; add startup integrity check/recovery plan; explicitly save or migrate `current_shift` out of debounced Tauri Store. |
| C2.3 | Phase 6 — preflight + Slow-3G smoke + dev → main PR + tagged release | §Phase 6 | ~2 h | **Final go-live gate.** Runs after Tier 0 + Tier 1 + C2.1 + C2.2 land. |
| C2.4 | Catalog/grid render performance (5000 SKUs cold cache) | (no plan yet — write `pos-catalog-render-performance.md` if Tier 2 reveals real lag) | TBD | Was the earlier POS performance session's territory. Defer until C2.1 lands and we have benchmark data; only spec a separate plan if measurements warrant. |

---

## Tier 3 — Activation hardening

| # | Item | Plan reference | Effort | Notes |
|---|---|---|---|---|
| C3.1 | Token lifetime 30d → 12mo | [`pos-activation-hardening.md`](2026-04-30-pos-activation-hardening.md) §Phase 2 | 1 day | Orthogonal; safe to do anytime. |
| C3.2 | Bootstrap error handling state machine | §Phase 3 | 3 days | Touches `AppShell.tsx`. |
| C3.3 | Surface existing Training Mode in POS UI | §Phase 1 (revised) | 4 h | Backend already wired; banner + visual tint only. |
| C3.4 | Phone-tether wizard | §Phase 4 | ~5 days | **Defer** until field signal warrants. |

---

## File ownership matrix (post-refund, single-orchestrator model)

With the orchestrator owning all POS work, the only external boundary is refund-specific files (owned by the refund team) and refund's pending audit findings (which may temporarily require a wait-state on shared files).

| File / area | Owner | Tiers that touch it |
|---|---|---|
| `TransactionCart.tsx` | Orchestrator | C0.4 (empty cart Pay block), C3.3 (training mode banner) — sequence within orchestrator's branches |
| `HomePage.tsx` | Orchestrator | C1.1 (connectivity), C3.2 (bootstrap state machine) |
| `paymentStore.ts` | Orchestrator | C0.1, C0.3, C0.6 sync investigation, C1.2 (refreshFromSQLite) — **fold C1.2 into the same branch as C0.3 if both land within 24h** |
| `productStore.ts` | Orchestrator | C0.7 (catalog vanishes), C2.1 (paginated warmup), C2.4 (render perf) — sequence carefully; C0.7 may require a quick fix that C2.1 then refactors |
| `authStore.ts` / `LoginPage.tsx` | Orchestrator | C1.1 (connectivity), C3.1 (token lifetime) |
| `connectivityStore.ts` | Orchestrator | C1.1 |
| `terminalStore.ts` | Orchestrator | C0.3 (lazy seed) |
| `syncStore.ts` / `lib/sync/*` | Orchestrator | C0.6 (sync investigation), C2.2 (crash safety) |
| Refund-specific files (returns store, refund modals, credit-note flows) | **Refund team** | Orchestrator does not touch these unless audit findings explicitly require it |

**Sequencing rules within the orchestrator:**
1. Each tier ships as its own branch off `dev`. Branches do not overlap; each merges to `dev` before the next opens.
2. Where two tiers touch the same file, the earlier tier's commit is rebased onto by the later tier — no parallel branches with overlapping files.
3. After every merge to `dev`, run the "Do not regress" inventory before opening the next branch.

---

## Regression test discipline

This is where "almost finished product" demands a higher bar. Per-tier expectations, non-negotiable:

### Tier 0 (flagrant bugs)
Every fix ships with **at least one unit test that fails on the old code and passes on the new**. Pattern already established in PR #73 (4 regression tests for the cash-numpad-overwrite fix). For C0.1: structured logging is hard to assert directly; instead add a test that asserts `paymentStore.error` includes the error class name when the underlying error has no message (covers the diagnostic-fallback branch).

### Tier 1 (stability)
Every phase ships with a Vitest suite covering the bug class:
- C1.1 → tests for token-rollback-on-companies-failure, recovery branch when `companies:[]`, AbortController timeout, monitoring-at-boot, LoginPage cancel affordance.
- C1.2 → `paymentStore.refreshFromSQLite()` with empty-on-rehydrate, partial-rehydrate, API-fresh-overwrites-cache; `paymentConfigReady` gate behavior; pre-warm at terminal activation.
- C1.3 → `pendingReceiptCount` reads SQLite (not in-memory state), `lastSyncAt` survives reload, degraded-signal renders on partial pull failure.

### Tier 2 (performance)
Every change ships with a benchmark snapshot:
- C2.1 → 5000-SKU paginated pull throughput (records per second, total wall-clock); render-time of `ProductGrid` first frame on cold cache vs warm.
- C2.2 → crash-safety: simulate `kill -9` after `INSERT` but before `COMMIT`, verify offline-receipt queue not corrupted on restart.

### Tier 3 (activation hardening)
Every phase ships with an E2E Playwright test for the affected critical path:
- C3.1 → cold-start with mocked 12-month token; assert no re-login required after simulated 6-month idle.
- C3.2 → bootstrap state machine: each phase's timeout fires correctly; retry-from-last-phase works; "use cached data" fallback engages when SQLite has the resource.
- C3.3 → terminal in `is_training_mode=true` renders banner + tinted cart; receipt confirmation modal shows training prefix.

### End-of-consolidation gate
- `pnpm test` runs green on every shipped tier.
- A snapshot of the test count is captured (`pnpm test 2>&1 | grep "Tests"`) so we know how many tests we added across all tiers.
- Any test that flakes is hardened or quarantined immediately — **no flaky tests allowed past go-live.**
- Run `./scripts/preflight.sh` from the repo root; must be green before opening the `dev → main` PR.

---

## "Do not regress" inventory

Quick smoke script the consolidation session runs **before** any work, to confirm nothing the recent refund merge broke our prior fixes.

| Fix | Symptom it eliminated | 30-second manual test |
|---|---|---|
| **PR #72** — cart Pay block height bounding | On full-screen Tauri, Pay block appeared then vanished once products loaded | Open POS full-screen, log in, open shift, wait for products to render. Confirm Total + green Cash button visible at bottom of cart panel. |
| **PR #72** — i18n rehydrate sync | UI defaulted to English on every cold start despite Français being saved | In Settings, set Language=Français. Quit Tauri, restart with `pnpm tauri dev`. Confirm UI loads in French (no toggle dance). |
| **PR #73** — cash numpad preset overwrite | After Exact + tap digit, display didn't update; cashier had to delete+retype | On Cash payment screen, tap Exact → tap "5". Confirm tendered field shows "5,00 €" (not "25,005"). Then tap Exact → tap backspace. Confirm value shrinks normally to "25.0" not reset. |

If any of these regress, the consolidation session **stops** and triages before continuing.

---

## Reference docs (input only — do not treat as live truth)

| Type | File | Status |
|---|---|---|
| Audit | [`audits/2026-04-30-pos-offline-first-audit-codex.md`](../audits/2026-04-30-pos-offline-first-audit-codex.md) | Pre-refund snapshot. Findings still mostly valid; verify each against the post-refund tree. |
| Audit | `audits/2026-04-30-pos-offline-first-audit-claude.md` | Was generated in another session. Pre-refund snapshot. Same caveat. |
| Research | [`research/2026-04-30-pos-first-launch-offline-activation-research.md`](../research/2026-04-30-pos-first-launch-offline-activation-research.md) | Industry survey on first-launch activation. Stable; no rework needed. |
| Plan | [`plans/2026-04-30-pos-empty-cart-pay-block-always-visible.md`](2026-04-30-pos-empty-cart-pay-block-always-visible.md) | Tier 0 (C0.4). Re-verify against post-refund `TransactionCart.tsx`. |
| Plan | [`plans/2026-04-30-pos-activation-hardening.md`](2026-04-30-pos-activation-hardening.md) | Tier 3. Phase numbers stable; refer to it as the source. |
| Plan | [`plans/2026-04-30-pos-offline-first-hardening.md`](2026-04-30-pos-offline-first-hardening.md) | Tier 1 + 2. Phase 0 + 1 + 2 + 4 stable; verify Phase 3 against POS performance session output. |
| Plan | [`plans/2026-04-30-pos-checkout-failure-fix.md`](2026-04-30-pos-checkout-failure-fix.md) | Tier 0 (C0.1–C0.3). Step 1 observability fix is the entry point. |
| Plan | [`plans/2026-04-30-pos-roadmap.md`](2026-04-30-pos-roadmap.md) | Pre-refund roadmap. **Superseded by this checkpoint.** Keep for context but don't action from it. |

---

## Open decisions for the human

1. **Parallel vs wait.** Discuss with the refund/audit owner; pick A or B from the decision section above.
2. **C0.4 (empty cart Pay block):** ship in the Tier 0 hotfix bundle, or hold and ship as part of Tier 1? *(Default: Tier 0 — user has hit the empty state twice, fix is small.)*
3. **C2.1 (paginated catalog) ↔ POS performance session:** sync with the POS performance owner before starting C2.1; agree who owns the paginated-pull change.
4. **Worktree topology:** one fresh worktree for the consolidation session (recommended for clean diffs and Codex review isolation), or operate directly on `dev` from the main checkout?
5. **Tagged release after C2.3:** when Phase 6 fires the `dev → main` PR, agree on the version bump (semver patch vs minor) and the release-tag convention with whoever ships the parapharmacy build.

---

## TaskCreate Backlog From Merged Audits

Use these as the Linear TaskCreate payloads. Preserve order; C0.1 is the prerequisite for reproducing the checkout blocker.

| Priority | Title | Scope | Acceptance |
|---|---|---|---|
| P0 | POS checkout observability: preserve and log non-Error failures | `paymentStore.ts`, `HomePage.tsx`, `receiptService.ts`, `syncService.ts`, `syncStore.ts`, `api.ts` | Cash/card/advanced checkout failures log structured payloads with throwable type/message/stack; `Échec du paiement` includes the original Tauri/SQLite message or error class; test covers non-`Error` rejection. |
| P0 | POS payment config readiness and endpoint drift fix | `paymentStore.ts`, `syncService.ts`, payment API routes | Scheduler pulls the same payment-method/repository endpoints that foreground uses, writes SQLite, refreshes Zustand from SQLite, and blocks checkout with a specific message until config is ready. |
| P0 | POS receipt sync certainty: timeout + orphaned syncing reaper | `lib/api.ts`, `lib/sync/*`, offline receipt repository | API requests have read/overall timeouts; app boot requeues stale `status='syncing'` receipts; retry uses existing idempotency key; stuck rows are visible in sync state. |
| P0 | POS duplicate-sale retry suppression | `paymentStore.ts`, offline receipt repository, checkout UI | Confirm cannot create a second receipt for the same cart while a matching recent receipt is `pending` or `syncing`; cashier sees "sale is being recorded" instead of a generic failure. |
| P0 | POS chain-break recovery surface | `syncStore.ts`, `ChainBreakAlert`, sync repository/UI | Chain-break banner identifies the affected receipt and offers operator/admin recovery path or force-retry workflow; retry saturation does not silently hide the broken chain. |
| P1 | POS catalog category hotfix | `productStore.ts`, `ProductGrid.tsx`, product repository/tests | Menu catalog is not overwritten by generic product cache; category A→B→A preserves products; virtualizer resets/re-measures on filter changes and survives empty result transitions. |
| P1 | POS 5000-SKU foreground warmup | `productStore.ts`, `syncService.ts`, product API | Foreground warmup uses paginated SQLite-first sync, removes the 500-SKU cap, shows progress, and avoids duplicate foreground/scheduler product pulls. |
| P1 | POS local durability hardening | `db.ts`/migrations, `receiptService.ts`, storage layer | SQLite PRAGMAs are asserted; receipt sync scheduling happens after `COMMIT`; startup integrity check exists; Tauri Store fiscal-adjacent state is explicitly saved or migrated to SQLite. |
| P2 | POS image cache warmup and eviction | `imageCache.ts`, `useProductImage.ts`, sync scheduler | Visible images start downloading without waiting for a 60s sync tick; cache has a size/age policy; orphan cleanup is called from production code. |

---

## Sync flow investigation — broader brief

C0.6 is the entry point but the investigation needs to cover the full sync flow end-to-end, not just the one symptom. The next session should produce a single document at `docs/superpowers/audits/2026-04-30-pos-sync-flow-deep-dive.md` covering:

1. **Receipt push contract** — what guarantees does the client get when it POSTs `/pos/receipts`? Is the server response idempotent (re-POSTing the same `idempotency_key` returns the existing receipt instead of erroring or creating a duplicate)? Does the response include the canonical fiscal hash and chain state so the client can update locally without re-pulling?

2. **Failure-mode taxonomy** — for each network-failure shape (DNS, connect-timeout, read-timeout-mid-request, 5xx-after-commit, 5xx-before-commit, client-process-killed-mid-POST), what's the actual behavior today? Does the client retry? After how long? With the same idempotency key?

3. **Sync push reliability** — `apps/pos/src/lib/sync/syncScheduler.ts` + `syncService.ts`. When does the queue drain? What happens if a single receipt at the head of the queue fails repeatedly — does it block all others (head-of-line blocking) or get pushed aside? Is there a max-retry / dead-letter pattern?

4. **Hash chain divergence recovery** — when the server's `last_hash` and client's `last_hash` disagree (e.g. because a "failed" POST actually committed), what does `pullTerminalState` do? The existing `FiscalRegressionError` guard preserves local state — is that correct in all directions, or does it sometimes mask a real divergence?

5. **Catalog/state pull consistency** — how does product/category list refresh interact with active filters (the C0.7 symptom). Is there a single source of truth, or do filtered subsets shadow the canonical list?

6. **Observability gaps** — every async failure path that doesn't currently log to the JS console + the Tauri devtools network tab. Add `console.error` everywhere the catch falls through silently.

Output: **done** via the Opus/Codex audit pair:
- `docs/superpowers/audits/2026-04-30-pos-sync-flow-deep-dive-opus.md`
- `docs/superpowers/audits/2026-04-30-pos-sync-flow-deep-dive-codex.md`

The ranked fixes have been merged into Tier 0 / Tier 1 / Tier 2 and into the TaskCreate backlog above.

This investigation can run in parallel with C0.1 → C0.3 (the Échec du paiement diagnosis); they likely share root causes and the same observability instrumentation will inform both.

---

## Suggested first 15 minutes of the consolidation session

1. `git fetch origin --prune && git checkout dev && git pull --ff-only`. Confirm tip = `2efc007e` (or later if more landed) and dev contains PR #73 + PR #74.
2. Run the "Do not regress" inventory (3 manual tests, 90 seconds total). Stop and triage if any fail.
3. Read this checkpoint top-to-bottom; TaskCreate entries are now listed in "TaskCreate Backlog From Merged Audits".
4. Decide Parallel vs Wait with the refund/audit owner.
5. Start C0.1 (15-minute observability fix in `paymentStore.ts`). The next reproduction of Échec du paiement on dev will reveal the actual error class + stack, which sets the trajectory for the rest of Tier 0.
6. Sync-flow investigation is complete. Start with C0.1 observability, then implement C0.6's timeout/reaper/retry-suppression fixes before deeper sync UX work.
