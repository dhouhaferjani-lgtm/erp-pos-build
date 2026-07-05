# POS — Investigation Prompts to Dispatch in Parallel (2026-04-30)

> **Purpose:** while the refund team works on refund-flow audit + fixes, dispatch independent investigation sessions to deep-dive each Tier 0 / Tier 1 bug class. Run each prompt **first through Opus**, then **through Codex** as an adversarial second opinion. Compare results and merge findings.
>
> **Operating model:** these are *investigation only* prompts — none of them ship code. Each produces a written audit at a specific path under `docs/superpowers/audits/2026-04-30-*` so the orchestrator session can read all findings together when it kicks off.
>
> **Why two models per prompt:** Opus tends to find architectural patterns and propose holistic fixes. Codex tends to find sharp implementation bugs and edge cases the other missed. For blocking bugs (sync, payment), running both in parallel and merging is worth the cost.
>
> **How to use:** open a fresh session for each prompt. Paste it verbatim. When done, save the output file path. Repeat with the second model. The orchestrator session reads all the produced audit files as input when it starts the consolidation work.

---

## Prompt order (run all in parallel; results don't depend on each other)

| # | Bug class | Output file | Priority |
|---|---|---|---|
| 1 | **Sync inconsistency: POS errors but server finalizes** (highest impact — risk of double-billing) | `docs/superpowers/audits/2026-04-30-pos-sync-flow-deep-dive-{opus,codex}.md` | P0 |
| 2 | **Échec du paiement + chain-break banner** (cashier-blocking) | `docs/superpowers/audits/2026-04-30-pos-checkout-failure-deep-dive-{opus,codex}.md` | P0 |
| 3 | **Catalog state inconsistency: products vanish after category switch** | `docs/superpowers/audits/2026-04-30-pos-catalog-state-deep-dive-{opus,codex}.md` | P1 |
| 4 | **Catalog warmup performance for 5000+ SKUs** (post-refund baseline) | `docs/superpowers/audits/2026-04-30-pos-catalog-warmup-performance-{opus,codex}.md` | P1 |
| 5 | **Tauri Store / SQLite durability across crash + restart + OS suspend** | `docs/superpowers/audits/2026-04-30-pos-local-storage-durability-{opus,codex}.md` | P1 |

---

## Prompt 1 — Sync flow deep-dive (P0, blocking)

```
Investigate the Tauri POS desktop app (apps/pos) sync flow end-to-end and produce a ranked findings audit. The user has reported a correctness bug: cashier sees a checkout failure on the POS (red banner / "Échec du paiement" toast), believes the sale didn't go through, but the same receipt later appears as finalized in the back-office web. This is a double-billing risk if the cashier retries.

Save the report to docs/superpowers/audits/2026-04-30-pos-sync-flow-deep-dive-{opus|codex}.md (replace the model name with whichever you are).

DO NOT write code. This is investigation only. Reference docs/superpowers/plans/2026-04-30-pos-consolidation-checkpoint.md for context on the orchestrator session that will action your findings.

COVER ALL OF THESE AREAS — DO NOT SKIP ANY:

1. **Receipt push contract** — what guarantees does the client get when it POSTs /pos/receipts? Read apps/api/app/Modules/POS/Presentation/Controllers/*.php and the corresponding routes. Is the server response idempotent (re-POSTing the same idempotency_key returns the existing receipt instead of erroring or creating a duplicate)? Does the response include the canonical fiscal hash and chain state so the client can update locally without re-pulling? Cite file:line.

2. **Failure-mode taxonomy** — for each network-failure shape, what's the actual behavior today?
   - DNS resolution fails
   - TCP connect timeout
   - Read timeout mid-request (server received body but client never got the response)
   - 5xx after server commit (transaction succeeded, response failed)
   - 5xx before server commit
   - Client process killed (SIGKILL) mid-POST
   - Network drops between request and response
   For each: trace what the client does. Read apps/pos/src/lib/sync/syncService.ts, syncScheduler.ts, apps/pos/src/lib/api.ts, apps/pos/src/stores/paymentStore.ts. Is there a retry? After how long? With the same idempotency key? Does the receipt stay in the local SQLite queue? Does it get marked failed?

3. **Sync push reliability** — apps/pos/src/lib/sync/syncScheduler.ts + syncService.ts. When does the queue drain? What happens if a single receipt at the head of the queue fails repeatedly — does it block all others (head-of-line blocking) or get pushed aside? Is there a max-retry / dead-letter pattern? What's the user-visible signal when a receipt is stuck?

4. **Hash chain divergence recovery** — when the server's last_hash and client's last_hash disagree (e.g., because a "failed" POST actually committed and advanced the chain server-side), what does pullTerminalState do? The existing FiscalRegressionError guard preserves local state — read apps/pos/src/lib/db/repositories/terminalStateRepository.ts and apps/pos/src/lib/sync/syncService.ts pullTerminalState. Is preserving local state correct in all directions, or does it sometimes mask a real divergence? Specifically: if the server has advanced the chain past the client's local sequence (because the client thinks a receipt failed but it actually committed), what happens?

5. **Idempotency end-to-end** — read the server-side receipt creation (apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php). Does it look up by idempotency_key and short-circuit duplicate inserts? If yes, what does the response look like for a duplicate (the original receipt? a 409? a 200 with the existing data?). Trace the client side: does paymentStore.ts handle a "this receipt already exists server-side" response correctly?

6. **Observability gaps** — for every async failure path, does the failure get logged to the JS console? Read every catch block in apps/pos/src/lib/sync, apps/pos/src/stores/paymentStore.ts, apps/pos/src/stores/syncStore.ts, apps/pos/src/lib/api.ts. List every catch that swallows an error without console.error.

7. **The specific user-reported symptom** — try to construct a precise sequence of events that produces "POS shows error but server has the receipt finalized." Cite the lines that fail. Identify the smallest fix that closes the bug class.

REPORT STRUCTURE:
- Executive summary: top 5 findings ranked by user-visible impact (P0 = correctness/double-billing, P1 = blocked workflow, P2 = visible degradation, P3 = silent risk).
- Per-area sections (1-7 above) with current state cited file:line, failure modes enumerated, and recommended fix sketched (don't implement — just sketch).
- Idempotency contract diagram (text-based ascii is fine) showing the exact client→server→client round trip with the key invariants.
- Observability instrumentation checklist (every line where a console.error needs to be added, with the structured payload to log).
- Open questions for the human that you can't determine from code alone (e.g., "what's the typical 4G latency at the pharmacy site? if >2s, the read-timeout should be longer").
- Reconciliation note at the end: where you agree/disagree with the existing audits at docs/superpowers/audits/2026-04-30-pos-offline-first-audit-codex.md and docs/superpowers/audits/2026-04-30-pos-offline-first-audit-claude.md (if it exists).

Save to docs/superpowers/audits/2026-04-30-pos-sync-flow-deep-dive-{opus|codex}.md and report back the file path + a 5-bullet summary of top findings.
```

---

## Prompt 2 — Échec du paiement + chain-break deep-dive (P0, blocking)

```
Investigate the Tauri POS desktop app (apps/pos) cash checkout failure and the "fiscal chain has been interrupted" home-page banner. Reproduce both bugs from code reading. Produce a ranked findings audit.

Symptoms:
1. Cashier opens shift, adds items to cart, taps Pay Cash, taps Exact button, taps Terminer et imprimer. Pink banner appears with text "Échec du paiement". Tauri devtools console is silent — no JavaScript error logged.
2. On the home page (above the cart), a thin pink banner reads "Fiscal chain has been interrupted" (ChainBreakAlert component). User said this also persists.

Save the report to docs/superpowers/audits/2026-04-30-pos-checkout-failure-deep-dive-{opus|codex}.md.

DO NOT write code. Investigation only. Reference docs/superpowers/plans/2026-04-30-pos-checkout-failure-fix.md for prior diagnostic work; verify or extend its hypotheses.

COVER:

1. **Why the console is silent** — read apps/pos/src/stores/paymentStore.ts processCashCheckout (around line 218) and the catch block (around line 272). Confirm the catch swallows the underlying error. Identify every other catch in the call chain (HomePage.handleCashConfirm, createReceiptLocalFirst, createOfflineReceipt, getDatabase, getTerminalState) that may also swallow.

2. **Top 5 candidate root causes** for "Échec du paiement", each with: (a) the exact code path that throws, (b) why the error.message could be empty/falsy, (c) what state would have to be true for this to fire, (d) how likely on a fresh PharmaBio France parapharmacy seed (which has 9 payment methods + 7 payment repositories per the DB).

3. **ChainBreakAlert root cause** — find the component (apps/pos/src/components/atoms/ChainBreakAlert.tsx). What does it gate on? Read the store selector. Trace where the hash-chain interruption flag gets set. Is it a server-detected divergence (pullTerminalState) or a client-detected one (a failed receipt push)? Cite file:line.

4. **Convergence with sync deep-dive** — are these two bugs (Échec du paiement + chain-break) likely the same root cause as the sync inconsistency (POS error / server finalized)? Justify with code paths.

5. **Minimum-viable diagnostic instrumentation** — the orchestrator session will ship a 15-minute "observability fix" that adds console.error in every catch. List exactly which files + line numbers + what to log. Provide the ready-to-paste code snippets.

6. **Targeted fix candidates** — for each of the top 3 most likely root causes, sketch the targeted fix (don't implement, just describe the change with cited lines). Estimate effort.

REPORT STRUCTURE:
- Executive summary: top 5 findings ranked by likelihood + user impact.
- Per-bug-class sections.
- Diagnostic instrumentation checklist (paste-ready).
- Reconciliation note vs prior plans.

Save to docs/superpowers/audits/2026-04-30-pos-checkout-failure-deep-dive-{opus|codex}.md and report back the file path + a 5-bullet summary.
```

---

## Prompt 3 — Catalog state inconsistency (P1)

```
Investigate the Tauri POS desktop app (apps/pos) catalog state bug: products visible in category A → switch to category B → switch back to A → products gone. Produce a focused audit with a specific reproduction recipe and a fix sketch.

Save the report to docs/superpowers/audits/2026-04-30-pos-catalog-state-deep-dive-{opus|codex}.md.

DO NOT write code. Investigation only.

COVER:

1. **Trace the filter selector** — read apps/pos/src/stores/productStore.ts. How is the displayed product list derived from the canonical store list when a category filter is active? Is there mutation happening (Array methods like splice, sort that mutate in place)? Is there a memoization key that resets incorrectly on category switch?

2. **Trace the virtualizer** — read apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx. Does react-virtual's row-recycling cache assume stable input ordering? When the filtered list changes from N items to M items and back, does the virtualizer correctly re-derive its scroll positions?

3. **Trace category state** — where is the active category stored? In productStore? Component-local useState? Zustand persist? Could a stale closure or stale selector cache a snapshot of the filter list and serve it after the source list changed?

4. **Reproduction recipe** — write the exact sequence of clicks that reproduces the bug, and the exact JS state mutations that occur at each click (you can infer this from reading the code).

5. **Top 3 candidate root causes**, ranked by likelihood, with the exact lines that produce each.

6. **Fix sketch** — for the most likely cause, sketch the targeted fix. Include the regression test that would fail on the old code and pass on the new (test name + assertions, not the full implementation).

REPORT STRUCTURE:
- Executive summary: top 3 candidate root causes + 1 recommended fix.
- Code trace with file:line citations.
- Reproduction recipe.
- Regression test sketch.

Save and report back the file path + a 3-bullet summary.
```

---

## Prompt 4 — Catalog warmup performance for 5000+ SKUs (P1)

```
Audit the Tauri POS desktop app (apps/pos) product catalog warmup performance for a 5000-SKU parapharmacy tenant. Produce a ranked findings audit covering: time-to-first-render, full-warmup wall clock, image loading strategy, SQLite cache hit rate on cold launch, network bandwidth profile.

Save the report to docs/superpowers/audits/2026-04-30-pos-catalog-warmup-performance-{opus|codex}.md.

DO NOT write code. Investigation only. Reference docs/superpowers/plans/2026-04-30-pos-offline-first-hardening.md §Phase 3 for prior planning; verify or extend its assumptions.

COVER:

1. **Current pull strategy** — read apps/pos/src/stores/productStore.ts fetchProducts. Is it single-shot (one API call returning all 5000) or paginated (multiple calls)? What's the page size? What does the cashier see during the pull (skeleton? blocking spinner? products streaming in?). The Codex audit at docs/superpowers/audits/2026-04-30-pos-offline-first-audit-codex.md flagged a possible 500-SKU cap — verify or refute.

2. **SQLite cache** — read apps/pos/src/lib/db/repositories/productRepository.ts. Is the catalog cached locally? When? What's the cache invalidation strategy? On second launch, is the cashier looking at SQLite or hitting the API again?

3. **Image loading** — read apps/pos/src/lib/images/imageCache.ts (and call sites). Are product images preloaded, lazy-loaded, or fetched on virtualizer scroll? What's the cache eviction policy? On 5000 SKUs with thumbnail images, what's the disk + memory footprint?

4. **Render performance** — apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx uses react-virtual. What's the row height assumption? Is the grid display-mode (compact vs visual) affecting render cost? Are there any synchronous filter computations that block the main thread on category switch?

5. **Network bandwidth** — sketch the wire format. JSON-encoded 5000 products with X fields each = how many KB compressed? Is there any pagination, cursor, ETag, or 304 support?

6. **Failure modes** — what happens if the pull is slow (15s+) on 4G? Does the cashier get a useful loading indicator? Can they start a sale on a partial catalog?

7. **Quick wins** — list 3-5 fixes that take <2h each and would meaningfully improve cold-start time. Estimate impact.

REPORT STRUCTURE:
- Executive summary: 5 ranked findings + 3-5 quick wins.
- Per-area sections with file:line citations.
- Benchmark plan: what to measure, how to measure it, expected baselines.
- Reconciliation vs the offline-first audit and Phase 3 plan.

Save and report back the file path + a 5-bullet summary.
```

---

## Prompt 5 — Local storage durability (P1)

```
Audit the Tauri POS desktop app (apps/pos) local storage layer for durability across: Tauri process crash, OS suspend/resume, mid-write power loss, mid-checkout SIGKILL, full disk, corrupted SQLite file. Produce a ranked findings audit.

Save the report to docs/superpowers/audits/2026-04-30-pos-local-storage-durability-{opus|codex}.md.

DO NOT write code. Investigation only.

COVER:

1. **SQLite journal mode + sync mode** — read apps/pos/src/lib/db/migrations.ts and the Tauri SQL plugin config. Is WAL enabled? Is synchronous=NORMAL or FULL? What durability guarantees does this give us against power loss?

2. **Transactional write boundaries** — read apps/pos/src/lib/offline/receiptService.ts createOfflineReceipt + apps/pos/src/lib/sync/syncScheduler.ts. Is the offline-receipt INSERT inside a transaction? Where exactly does COMMIT happen relative to the scheduleDebouncedSync call? If sync fires before COMMIT, what could go wrong?

3. **Tauri Store usage** — find every call to @tauri-apps/plugin-store (auth tokens, settings, terminal pairing). Is Tauri Store a JSON file or a real DB? What atomicity guarantees does it give? What happens if Tauri is killed mid-write?

4. **Idempotency key generation** — every offline receipt gets an idempotency_key (crypto.randomUUID()). Where is it persisted? If the receipt is generated in-memory but Tauri is killed before COMMIT, is the key lost? Could a retry generate a fresh key and double-bill?

5. **Database file location + corruption recovery** — where does the SQLite DB live on disk per OS? Is it backed up? If it gets corrupted, what's the recovery story for the cashier?

6. **Offline receipt queue durability** — what's the guarantee that an offline receipt survives a Tauri restart and gets pushed when sync resumes? Trace the lifecycle from createOfflineReceipt → SQLite row → syncScheduler picks it up → POST → server response → mark synced.

7. **Crash-safety regression tests** — list the unit tests that should exist to lock down each invariant. The orchestrator will write them; you sketch the test names + assertions.

REPORT STRUCTURE:
- Executive summary: top 5 durability findings.
- Per-area sections with file:line citations.
- Crash-safety test matrix (list of tests with names + assertions).
- Reconciliation vs the offline-first audit Phase 5 (crash safety).

Save and report back the file path + a 5-bullet summary.
```

---

## After all 5 prompts produce output (10 audit files, 5 from Opus + 5 from Codex)

The orchestrator session reads them all at startup and merges:
1. For each bug class, compare Opus + Codex findings. Where they agree, treat as high-confidence. Where they disagree, dig into the cited code to break the tie.
2. Update `docs/superpowers/plans/2026-04-30-pos-consolidation-checkpoint.md` Tier 0 / Tier 1 sections with the actual ranked fixes.
3. Convert the merged findings to TaskCreate items.
4. Begin C0.1 (15-minute observability fix) using the paste-ready snippets from Prompt 2's audit.

---

## Operational tips for the human running these prompts

- **Run all 5 in parallel.** They don't depend on each other and they're investigation-only (no code conflicts).
- **Run each through Opus first** — give it ~20-40 minutes of context exploration. Then run the same prompt through Codex with a single line prepended: "An Opus audit already exists at <path>. Read it first, then independently verify, challenge, or extend its findings — do not just paraphrase."
- **Token cost:** these are read-heavy audits, expect 30-60K tokens per run (60-120K per bug class × 5 bug classes). Budget accordingly.
- **Time budget:** Opus pass ~30 min each, Codex pass ~15-30 min each. Total wall-clock to complete all 10 audits: 4-8 hours depending on parallelism.
- **Reading the output:** when a Tier 0 bug audit lands, you can ship the observability fix from Prompt 2 immediately as a hotfix (it's purely additive) without waiting for the rest of the audits to complete.
- **If only one budget is available:** prioritize Prompts 1 + 2 (sync + checkout failure). Both are P0. Prompts 3, 4, 5 can run after refund audit finishes.
