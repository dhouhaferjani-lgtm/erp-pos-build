# POS — Unified Roadmap (2026-04-30, last realigned 2026-05-09)

> **Purpose:** single source of truth for what's open across all POS sessions, ranked for execution order with explicit conflict surfaces between parallel work.
>
> **2026-05-09 realignment note:** the original Tier 1 / Tier 2 numbering planned T1.3 = Phase 3 (paginated catalog warmup) and T2.1 = Phase 4 (sync indicator truthfulness). At execution time the productStore conflict with the POS performance session pushed Phase 3 down the queue, so Phase 4 was promoted into the T1.3 slot. To keep the numbering sequential with what actually shipped, this document now uses **T1.3 = sync indicator truthfulness (Phase 4)** and tracks the deferred paginated catalog warmup as **T2.1 (Phase 3)** in Tier 2. Every status, "what shipped" mark, and execution-order entry below reflects that realignment.
>
> **Active sessions running in parallel:**
> 1. This session — coordination, hotfixes, planning.
> 2. Refund flow (×2 sessions) — `feat/refund-flow` worktree, returns + credit notes.
> 3. POS performance — catalog/image cache/virtualizer optimization.
> 4. Offline-first hardening — Tier 0 + Tier 1 + T2.2 shipped (PRs #82–#91); T2.3 (Phase 6) is the only Tier 2 item left before go-live.

---

## Reference docs

| Type | File | What it covers |
|---|---|---|
| Audit | [`audits/2026-04-30-pos-offline-first-audit-codex.md`](../audits/2026-04-30-pos-offline-first-audit-codex.md) | Codex's offline-first ranked findings (P0–P3). |
| Audit | `audits/2026-04-30-pos-offline-first-audit-claude.md` (in flight in another session) | Claude's second-opinion audit. |
| Research | [`research/2026-04-30-pos-first-launch-offline-activation-research.md`](../research/2026-04-30-pos-first-launch-offline-activation-research.md) | Industry survey on first-launch activation; concludes one-online-then-offline is the right model. |
| Plan | [`plans/2026-04-30-pos-empty-cart-pay-block-always-visible.md`](2026-04-30-pos-empty-cart-pay-block-always-visible.md) | Always-visible Pay block, greyed when empty. |
| Plan | [`plans/2026-04-30-pos-activation-hardening.md`](2026-04-30-pos-activation-hardening.md) | Token lifetime 30d→12mo, bootstrap error handling, surface existing Training Mode. |
| Plan | [`plans/2026-04-30-pos-offline-first-hardening.md`](2026-04-30-pos-offline-first-hardening.md) | Comprehensive 6-phase implementation plan from Codex+Claude audit findings. |
| Plan | [`plans/2026-04-30-pos-checkout-failure-fix.md`](2026-04-30-pos-checkout-failure-fix.md) | Diagnose + fix the current "Échec du paiement" + chain-break banner. |

---

## Already shipped (Tier 0 → Tier 1 → T2.2 — all merged to dev)

| PR | Item | What | Status |
|---|---|---|---|
| #72 | (pre-Tier-0) | Cart Pay-block visibility on full-screen + i18n rehydrate sync | ✅ Merged to main |
| #73 | (pre-Tier-0) | Cash payment numpad overwrites preset after Exact / denomination | ✅ Merged to main |
| #82 | T0.1 | "Échec du paiement" diagnosis + observability fix | ✅ Merged to dev |
| #83 | T0.2 | Idempotency-key-per-retry double-billing | ✅ Merged to dev |
| #84 | T0.3 | HTTP read-timeout via AbortController + FetchTimeoutError | ✅ Merged to dev |
| #85 | T0.4 | Chain-break recovery contract — codified | ✅ Merged to dev |
| #86 | T0.5 | Payment-config endpoints align + paymentStore rehydrate-after-sync | ✅ Merged to dev |
| #87 | T1.1 | Auth + connectivity foundation (Phase 1) | ✅ Merged to dev |
| #88 | T1.2 | Payment-config residual hardening — `paymentConfigReady` gate (Phase 2) | ✅ Merged to dev |
| #89 | T1.0 | Large-catalog fixture — `PARAPHARMACY_SEEDER_SCALE` (Phase 0) | ✅ Merged to dev |
| #90 | T1.3 | Sync indicator truthfulness — pendingCount/lastSyncAt persistence + degraded amber dot (Phase 4) | ✅ Merged to dev |
| #91 | T2.2 | Crash safety + small wins — sync trigger past COMMIT + regression-lock audit + TODO sweep (Phase 5) | ✅ Merged to dev |

**Pending dev → main promotion:** all of the above sit on dev awaiting the pre-launch audit (Graphify run + security review + doc realignment) before the dev→main merge. See [`project_prelaunch_audit_plan.md`](../../../) memory note.

---

## Open work, ranked by impact and execution order

### Tier 0 — Blockers shipped

All Tier 0 items are merged to dev (PRs #82–#86). Kept here for archival continuity.

### Tier 1 — Pre-go-live hardening

| # | Item | Plan | Status | Notes |
|---|---|---|---|---|
| T1.0 | Offline-first hardening Phase 0 (worktree, baseline, 5000-SKU fixture) | `pos-offline-first-hardening.md` §Phase 0 | ✅ Shipped (PR #89) | Default `PARAPHARMACY_SEEDER_SCALE=1` preserves the 1000-product fixture; SCALE=5/10 produce 5000/10000 products for perf assertions. |
| T1.1 | Offline-first hardening Phase 1 (auth + connectivity foundation) | `pos-offline-first-hardening.md` §Phase 1 | ✅ Shipped (PR #87) | 4 steps + 5 Codex review rounds. |
| T1.2 | Offline-first hardening Phase 2 (paymentStore.refreshFromSQLite + paymentConfigReady gate) | `pos-offline-first-hardening.md` §Phase 2 | ✅ Shipped (PR #88) | Subsumed T0.1's payment-config stale-state path. |
| T1.3 | Offline-first hardening Phase 4 (sync indicator truthfulness) | `pos-offline-first-hardening.md` §Phase 4 | ✅ Shipped (PR #90) | **Numbering note:** the original roadmap planned T1.3 = paginated catalog warmup (Phase 3); the productStore conflict with the POS-performance session pushed Phase 3 down to T2.1 (deferred), and Phase 4 was promoted into the T1.3 slot. The kickoff doc (`2026-05-08-pos-t1.3-sync-indicator-truthfulness-kickoff-prompt.md`) reflects that. |
| T1.4 | Activation hardening Phase 2 (token 30d → 12mo) | `pos-activation-hardening.md` §Phase 2 | 🟡 Pending | Orthogonal to the offline-first sequence; safe to slot in anytime post-go-live. ~1 day. Not on the critical path for the current parapharmacy launch. |
| T1.5 | Empty cart Pay block always-visible | `pos-empty-cart-pay-block-always-visible.md` | ✅ Shipped (pre-Tier-0 hotfix) | Landed alongside T0.1 per "ship today" decision; refund rebased trivially. |
| T1.6 | TND smoke test (currency precision verify) | T0.1 plan §Currency precision | 🟡 Pending | One-shot manual + 1 unit test, ~30 min. Defer to the Phase 6 (T2.3) preflight battery. |

### Tier 2 — Pre-go-live polish

| # | Item | Plan | Status | Notes |
|---|---|---|---|---|
| T2.1 | Offline-first hardening Phase 3 (paginated catalog warmup for 5000 SKUs) | `pos-offline-first-hardening.md` §Phase 3 | 🟡 Deferred | Originally planned as T1.3 but the `productStore.ts` conflict with the POS-performance session pushed it to Tier 2. Owner / coordination still needed before kickoff. ~1.5 h, 3 Codex review gates. |
| T2.2 | Offline-first hardening Phase 5 (crash safety + small wins) | `pos-offline-first-hardening.md` §Phase 5 | ✅ Shipped (PR #91) | Step 5.1 (sync trigger past COMMIT) + Step 5.2 (regression-lock audit — all four guards already covered) + Step 5.3 (10-deferral TODO sweep). |
| T2.3 | Offline-first hardening Phase 6 (preflight, Slow-3G scripted smoke test, full-branch Codex review, dev→main PR, tagged release) | `pos-offline-first-hardening.md` §Phase 6 | 🟡 Active prep — gated on pre-launch audit | **Final go-live gate.** Phase 6 PREP underway in this session: dev→main DRAFT PR opens BEFORE the audit runs; merge trigger held until Graphify + security + doc realignment sign off. Then preflight + Slow-3G smoke + tag. |
| T2.4 | Activation hardening Phase 3 (bootstrap error handling state machine) | `pos-activation-hardening.md` §Phase 3 | 🟡 Pending | Touches `AppShell.tsx` — minor conflict with refund flow if refund adds routes. ~3 days. |
| T2.5 | Activation hardening Phase 1 (surface existing Training Mode in POS UI) | `pos-activation-hardening.md` §Phase 1 (revised) | 🟡 Pending | Backend already wired; only banner + visual tint needed. ~4 h. |

### Tier 3 — Post-go-live (after first parapharmacy launch)

| # | Item | Plan | Effort | Notes |
|---|---|---|---|---|
| T3.1 | Phone-tether wizard | `pos-activation-hardening.md` §Phase 4 | ~5 days | Defer until field signal shows merchants hit it |
| T3.2 | Cosmetic: NumPad decimal button "." → "," for FR/TN locales | T0.1 plan §UX consideration | 30 min | Optional, only if cashier feedback complains |
| T3.3 | Per-merchant signed installer (enterprise tier) | Research §Per-merchant installer | ~2-3 weeks | Future enterprise tier; not for current single-pharmacy go-live |
| T3.4 | Items deferred from offline-first-hardening Phase 0 (full offline cold-start, batch receipt push, stranded-receipt admin UI, image preloading manifest) | `pos-offline-first-hardening.md` §Out of scope | M-L per item | Captured as `// TODO(go-live-followup):` comments during T2.2 sweep (PR #91); see Step 5.3 sweep table for code-site anchors. |

---

## Cross-session conflict matrix

| File / area | Hotfix session | Refund flow | POS performance | Offline hardening (status) |
|---|---|---|---|---|
| `TransactionCart.tsx` | Empty cart fix (T1.5 ✅) | Adds return UI | — | — |
| `HomePage.tsx` | Activation Phase 3 (T2.4 pending) | Adds /refund route | — | Phase 1 connectivity (T1.1 ✅) |
| `paymentStore.ts` | T0.1 ✅ | Adds refund/credit-note flows | — | Phase 2 refreshFromSQLite (T1.2 ✅) |
| `productStore.ts` | — | — | Cache + virtualizer | Phase 3 paginated warmup (T2.1 deferred) — **DIRECT CONFLICT remains** |
| `authStore.ts` / `LoginPage.tsx` | Activation Phase 2 (T1.4 pending) | — | — | Phase 1 connectivity (T1.1 ✅) |
| `connectivityStore.ts` | — | — | — | Phase 1 (T1.1 ✅) |
| `terminalStore.ts` | T0.1 (lazy seed if missing) ✅ | — | — | Phase 1 (T1.1 ✅) + Phase 4 hydrations (T1.3 ✅) |
| `syncStore.ts` / `lib/sync/*` | — | — | — | Phase 4 sync-indicator truthfulness (T1.3 ✅) + Phase 5 trigger past COMMIT (T2.2 ✅) |
| `offlineReceiptRepository.ts` / `receiptService.ts` | — | — | — | Phase 5 sync-trigger move (T2.2 ✅) |

**Hotspot (still active):** `productStore.ts` — POS performance session and T2.1 (deferred Phase 3) will collide. **Action:** before T2.1 starts, sync with the POS performance owner. They may have already implemented paginated pulls — check first.

**Hotspot (resolved):** `paymentStore.ts` — T0.1 + T1.2 overlap was resolved by folding both into the same hotfix branch as planned; no further conflict here.

---

## Execution discipline (offline-first hardening)

The offline-first hardening plan defines a strict **Codex-adversarial-review gate between every step** (16 gates total across Phases 1–4). No step advances until typecheck/lint/test pass AND Codex's review is addressed AND a manual smoke of the affected critical path is green.

**Minimum-viable shippable subset (now realized):** Phases 1 + 2 + 4 + 5 are landed. Phase 3 (paginated catalog) and Phase 6 (release gate) remain. The MVS predicted that "Phases 1 + 2 + 4 alone close the three highest-pain failures from the audits"; with Phase 5 added on top (regression-lock + TODO sweep), the codebase is shippable contingent on the pre-launch audit and the Phase 6 preflight + Slow-3G smoke.

Items explicitly out of scope and captured as deferred TODOs (do **not** scope-creep mid-flight) — now anchored as `// TODO(go-live-followup):` comments via T2.2 PR #91 Step 5.3 sweep:
- Full offline cold-start mode for fresh devices.
- Batch receipt push for 1000-receipt offline backlogs.
- Stranded-receipt operator/admin UI.
- Image preloading manifest.
- Server-side endpoint reconciliation (`/payment-methods` ↔ `/treasury/payment-methods`) — **Codex T2.2 round-1 verified this is already closed by T0.5**; the `/treasury/payment-methods` string only appears in `OnboardingStep.php` as a frontend onboarding URL, not as a backend route alias. No further work needed.

---

## Recommended execution order (now)

**Pending Tier 1:**
- **T1.4** — token lifetime 30d → 12mo. Orthogonal; can land anytime post-go-live or before, owner's call.
- **T1.6** — TND smoke test. Fold into Phase 6 (T2.3) preflight battery.

**Pending Tier 2:**
- **T2.3 (Phase 6)** — preflight + Slow-3G smoke + dev→main PR + tagged release. **Currently in PREP in this session; merge gated on pre-launch audit.**
- **T2.1 (Phase 3 paginated catalog)** — deferred behind go-live; needs POS-performance-session coordination before kickoff. Post-launch unless field signal demands it sooner.
- **T2.4** — bootstrap error handling state machine. Post-go-live unless an audit finding makes it pre-go-live.
- **T2.5** — Training Mode UI surface. Post-go-live polish.

**Tier 3:** post-go-live, when field data warrants.

---

## Decisions still needed from the human

(Most original questions resolved — only the still-active items remain.)

1. **T2.1 (Phase 3 paginated catalog) ↔ POS performance session:** still pending. Coordinate before T2.1 kickoff to avoid the `productStore.ts` collision. Owner unknown.
2. **T1.4 timing:** token-lifetime bump can land anytime; pre-go-live (one extra PR) or post-go-live (lower risk) are both viable.
3. **Pre-launch audit scope and trigger:** Phase 6 (T2.3) merge is gated on Graphify + security review + doc realignment sign-off. Owner/timeline for the audit?

(Resolved: T1.5 shipped; T0.2 collapsed into T0.1 as suspected; offline-first worktree pattern adopted across all Tier 0 / Tier 1 / T2.2 sessions; T1.3 = sync indicator (not paginated catalog) per the realignment note above.)
