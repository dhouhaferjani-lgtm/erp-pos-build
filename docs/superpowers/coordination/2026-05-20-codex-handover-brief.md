# Codex Handover Brief — Task 27B Pass 2 Completion

**Created:** 2026-05-20
**Purpose:** Hand off the remaining Phase 1 implementation to Codex (acting as primary implementer + first-pass adversarial reviewer; Opus called for second adversarial pass only).

This brief is **self-contained** — Codex starts cold with no prior session context. Read top-to-bottom, then execute §6 work in order. **Do not delegate the standing-patterns discipline (§3) — it's load-bearing.**

---

## 1. Workflow change vs. prior pattern

**Old workflow** (controller-driven dispatch):
- Controller dispatches Opus implementer subagent.
- After implementer ships, controller dispatches Opus spec reviewer + Codex adversarial reviewer in parallel.
- Controller dispatches fix implementer on findings.
- Repeat until both reviewers APPROVE.

**New workflow** (Codex as implementer + first adversarial pass):
- Codex implements the task in a single atomic commit on the dev branch.
- Codex runs its OWN adversarial review of the just-shipped commit, scrutinizing the same axes a separate Codex reviewer would (cross-tenant FK leaks, fail-loud vs silent-downgrade, dead-path-rebuild, discriminated-union test matrix completeness, contract drift, standing-pattern violations, etc.). Write findings to disk as structured Codex review.
- If Codex's own adversarial pass returns BLOCKER / REQUEST-CHANGES: fix in a follow-up commit; re-adversarial-review.
- Once Codex's own pass is APPROVE / APPROVE-WITH-MINOR-EDITS: invoke the Opus reviewer subagent (Codex CLI can dispatch Opus via `claude` CLI or subagent runtime) for a SECOND adversarial pass. Opus reads the commit + Codex's review file + the authoritative spec sources; flags anything Codex missed.
- If Opus finds anything: fix; re-review (Codex + Opus both).
- Once both reviewers APPROVE: push the task's commits + audit-trail review files to origin.
- Move to next task.

**Why this works:** Codex's strength on this branch has been adversarial review (BLOCKER-streak 16 of 30+ tasks; consistently catches what Opus misses). Inverting the role — Codex implements + Codex reviews own work + Opus second-passes — should be tighter than the prior pattern because Codex already-internalizes the standing patterns when implementing.

**Risk if Codex over-trusts its own review:** the Task 23/24/25/30/PHP.2 R2 standing pattern recurs — R2 fixes occasionally introduce new defects in the fix itself. Always do a clean adversarial re-review of fix commits, even if you wrote them yourself.

---

## 2. Worktree + branch state (CRITICAL)

- **Working directory:** `/Users/houssamr/Projects/syneriva/apps/erp.fiscal-phase1/` — dedicated git worktree.
- **NEVER cd to `apps/erp/`** — that's the main worktree on detached HEAD; parallel sessions may claim it. ALWAYS use absolute paths in Bash.
- **Branch:** `feat/pos-fiscal-event-engine-phase1` (PR #124).
- **HEAD at handover:** `8f358d5e6` (Pass 2A.TS shipped + pushed). Latest 8 commits this session:
  ```
  8f358d5e6 feat(fiscal): Task 27B Pass 2A.TS — TS engine validator + drift gate + .PASS_2B_PENDING marker
  803ffc19b docs(fiscal): Task 27B Pass 2A.PHP.2 R2 — commit Codex round-2 review audit trail
  688878c54 fix(fiscal): Task 27B Pass 2A.PHP.2 round-3 — close Codex R2 BLOCKER + 2 polish
  3c1711f4a fix(fiscal): Task 27B Pass 2A.PHP.2 round-2 — close Codex 2 BLOCKER + 2 P1 + 1 P2
  a947ce9a0 docs(fiscal): Task 27B Pass 2A.PHP.2 R1 — commit Opus + Codex review audit trail
  d9cc8e250 feat(fiscal): Task 27B Pass 2A.PHP.2 — consumer migration (projector + Nf525 + test helpers)
  d2319c068 docs(fiscal): Task 27B Pass 2A.PHP.1 R2 — commit Codex round-2 APPROVE review
  ed17e022d fix(fiscal): Task 27B Pass 2A.PHP.1 round-2 — close Codex 3 P1 + 1 P2 + 1 P3 (doc)
  ```
- **Stage explicit files only — NEVER `git add -A` or `git add .`.**

---

## 3. Project rules + standing patterns (READ FULLY)

### 3.1 — Authoritative project rules

- **`/Users/houssamr/Projects/syneriva/apps/erp/CLAUDE.md`** — AutoERP rules. Especially:
  - Rule 5: verification is law, end-to-end.
  - Rule 6: module boundaries via Shared/Contracts only.
  - Rule 10: preflight before commit.
  - Rule 13: constructor injection only; NEVER `app()` / `App::make` / `resolve()`.

### 3.2 — Standing patterns from prior tasks (29+ patterns; HOT)

Read in full: `docs/superpowers/coordination/2026-05-14-pos-fiscal-engine-session-handoff.md` §4.2.

**The patterns most relevant to remaining work (Pass 2B + Task 33):**

- **Dead-path rebuild (Tasks 30 + 32; also struck in Pass 2A.PHP.1 + PHP.2):** every new code path needs a live caller. After ANY refactor, grep callers of BOTH the new method and the legacy method; LIVE production callers must reach the new path. Side-channel verification (verify but don't use) is NOT enough.
- **Cross-task wiring premises (Task 22 + 23):** when Task X's behavior depends on Task Y's effects, the test for Task X must drive Task Y's failure mode end-to-end. Bridge components fail-LOUD on missing dependencies (typed exception → retryable), not silent return.
- **Discriminated-union test matrix exhaustively in round-1 (Task 20):** when a service returns a discriminated-union DTO, the test class covers EVERY variant in round-1. Don't ship round-1 with only happy-path.
- **DB primitives load-bearing (Task 19):** raw `INSERT … ON CONFLICT DO NOTHING RETURNING id` is the contract. Don't substitute "functionally equivalent" `try/catch (QueryException)`.
- **Per-method `markTestSkipped` ONLY (Task 29):** never class-level skip. Each skip cites the surviving owner + the follow-up task that will un-skip.
- **Skip-citation accuracy (Task 29):** if a skip says "the assertion moved to X", grep-verify X actually enforces the assertion.
- **"Not 410" is not "happy path" (Task 29):** carve-out tests for retired routes must exercise happy path with eligible state + actual payload + 2xx + DB side-effect assertions, not absence-of-disposition-code.
- **CI gate dependency setup in same commit (Task 30):** when extending a CI gate's mechanism, update the job's deps in the same commit. A clean checkout has no vendor / no PHP / no jq.
- **Receiver-type validation specificity (Task 30):** multi-dep classes need property-specific check, not class-has-some-property-of-type-X.
- **§8 quarantine completeness (Task 30):** both partition-table + in-table quarantined rows count.
- **`Log::spy + shouldHaveReceived` over Mockery `Log::shouldReceive` (Task 30):** Laravel-native.
- **Cross-tenant FK safety (Task 21 R2 standing pattern + Pass 2A.PHP.2 BLOCKER-1):** ALWAYS scope FK lookups by tenant_id. The product FK gap in PHP.2 R1 was caught only by Codex.
- **Fail-loud over silent-downgrade (Task 22 R2 + Pass 2A.PHP.2 BLOCKER-2):** projection failures throw typed exceptions, not silently fall to a different code path.
- **R2 introduces new defects (Tasks 23/24/25/30 + Pass 2A.PHP.2):** the very R2 fix that closes R1 findings often introduces NEW ones. ALWAYS do a clean adversarial re-review of R2 commits, even if you wrote them.

### 3.3 — Codex sandbox workaround

Codex's `apply_patch` is sandboxed to a configured root. If the sandbox blocks worktree writes (common when sandbox config is `apps/erp` but worktree is `apps/erp.fiscal-phase1`), you have two paths:
- (a) Pre-create stub files via Write tool (the controller's done this), then Codex modifies them with apply_patch.
- (b) Return review content INLINE in the agent response; controller transcribes to disk.

For Codex-acting-as-implementer (not reviewer), this is less of an issue — you have full Write tool access if running via the standard Claude Code CLI subagent. But if you're running as a Codex CLI session, expect sandbox restrictions and use Write tool for new files.

---

## 4. Authoritative artifacts (READ in order)

1. **Session handoff:** `docs/superpowers/coordination/2026-05-14-pos-fiscal-engine-session-handoff.md` — the durable anchor. §4 has per-task tables + standing patterns + remaining work.
2. **Synthesis v5:** `docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md` — the locked SALE_RECEIPT contract.
3. **Multi-country research:** `docs/superpowers/research/2026-05-20-multicountry-fiscal-research.md` — NF525/ZATCA/DE/IT field citations with URLs.
4. **Plan §2144+:** `docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md` — Task 27B FINAL Owner-Approved Decisions subsection (the authoritative section; the v3-era SUPERSEDED section below it is audit trail only).
5. **Spec v7 §11.2-§11.4:** `docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md` — amended in `be1d3a687` with SALE_RECEIPT payload contract + country-adapter pattern + cross-language drift gate test-lock.
6. **Roadmap v2 §Phase 1.5:** `docs/superpowers/specs/2026-05-14-pos-customer-accounts-roadmap-v2.md` — 3 deferred-task entries (mirror-column audit + per-country tax-number strict validation + ParseFailureResolution operator UX).

---

## 5. What's done so far (Phase 1 status: 32 of 33 task slots shipped or split)

Tasks 1-26, 27 Pass 1, 29, 30, 31, 32 — all shipped previously.

**This session shipped (2026-05-20):**

- **Synthesis loop (5 rounds + plan + spec + roadmap amendment):**
  - `e9a94790b` — audit trail (5 synthesis versions + 5 Codex reviews + multicountry research).
  - `be1d3a687` — plan §2144-2389 amended (v5 FINAL A1-A6 above SUPERSEDED v3-era) + spec v7 §11.2-§11.4 + roadmap v2 §Phase 1.5.
- **Task 27B Pass 2A.PHP.1 (foundation):**
  - `b6f143e1a` — R1: validator + 9 DTOs + CanonicalPayloadReader + migration + 49-test FiscalPayloadConstraintValidatorTest + D16 guard + 15 golden vectors + 36 per-method skips.
  - `ed17e022d` — R2: currency_scale allowlist + UUID/ISO8601 format helpers + TRAINING-flag invariant + F-15 BCMath fix + synthesis §6.E case 8 doc amendment.
  - `d2319c068` — R2 Codex APPROVE review audit trail.
- **Task 27B Pass 2A.PHP.2 (consumer migration):**
  - `d9cc8e250` — R1: PaymentMethodResolver interface + EloquentPaymentMethodResolver + PosCoreReceiptProjection migration + Nf525DataProvider bifurcation + TreasuryReceiptBridge sibling migration + 9 test helpers + 36 skips removed + D16 extension + F-15 determinism test.
  - `a947ce9a0` — R1 Opus + Codex review audit trail.
  - `3c1711f4a` — R2: cross-tenant product FK + REFUND/VOID fail-loud (`OriginalReceiptUnresolvableException`) + buyer-deletion regression test + D16 guard `Shared/Contracts/Treasury` pattern + PaymentMethodResolver null contract docblock.
  - `803ffc19b` — R2 Codex review audit trail.
  - `688878c54` — R3: QueryException propagates (no swallow) + buyer-deletion test value differentiation + cross-tenant product test sealed-snapshot reload + new `EloquentPaymentMethodResolverTest` (4 cases).
- **Task 27B Pass 2A.TS (THIS COMMIT):**
  - `8f358d5e6` — TS engine `SaleReceiptPayloadInput` expanded to 27-key nested shape + `validateSaleReceiptPayload` runtime validator + `SALE_RECEIPT_PAYLOAD_KEYS` constant + cross-language drift gate test + `.PASS_2B_PENDING` marker file + `check-pass-2b-pending.sh` CI sentinel wired into chokepoint-gate CI job + 28 new vitest tests. 1467/1467 POS suite passing.

**Pass 2A.TS R1 has NOT yet been dual-reviewed.** This is the FIRST task you should pick up.

---

## 6. Remaining work (execute in order)

### TASK A — Pass 2A.TS dual review

You just inherited `8f358d5e6` (Pass 2A.TS R1). Run your own adversarial review on it. Specifically check:

- **TS validator coverage matches PHP validator** at `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php`. STRUCTURAL conformance only — partition algorithm + total arithmetic stay server-side per v5 §6.F. But every regex / enum / nested-object shape check should mirror PHP.
- **`SALE_RECEIPT_PAYLOAD_KEYS` byte-mirrors PHP** `PAYLOAD_KEYS['SALE_RECEIPT']` (sorted lex, 27 entries).
- **Cross-language drift gate test** reads the PHP file at test time + extracts via regex + asserts byte equality. Verify it actually runs (not skipped) + passes.
- **`.PASS_2B_PENDING` marker** content matches synthesis v5 §8.A.
- **`check-pass-2b-pending.sh`** covers 4 forbidden patterns (`FiscalEventEngine` / `getFiscalEventEngine` / `lockTerminal` / `\.append\(.*event_type`) against `receiptService.ts` + `paymentStore.ts`. Verify the script exits 0 when marker exists + receiptService is unchanged, AND exits 1 when receiptService imports FiscalEventEngine.
- **CI sentinel wired into `chokepoint-gate` job** in `.github/workflows/ci.yml` (Task 30 added that job; rg + jq + bash baseline).
- **Dead-path probe:** every new validator branch has a live test caller. New helpers + constants have callers.
- **Discriminated-union matrix:** 28 new vitest tests cover all failure modes (extras, missing keys, UUID, ISO 8601, currency_scale allowlist, enums, training_flag both directions, negative money, scale violations, discount-reason both directions, buyer present/null, foreign_currency paired/half-paired, REFUND/SALE original_reference, tax_category_code shape, jurisdiction code, tax_number control byte, etc.).

If you find BLOCKERs / P1s: fix in a follow-up commit (`fix(fiscal): Task 27B Pass 2A.TS round-2 — close Codex self-review N-X`) and re-review.

Then dispatch Opus for second adversarial pass via subagent (target output file: `docs/superpowers/reviews/2026-05-20-task-27B-pass-2A-ts-opus-review.md`). Brief Opus on:
- The synthesis v5 contract.
- The PHP-side analogue.
- The Pass 2A.TS commit + your own Codex review.
- What you want Opus to spot-check.

If Opus finds anything: fix + re-review (both Codex + Opus).

Once both APPROVE: commit your review file (`docs/superpowers/reviews/2026-05-20-task-27B-pass-2A-ts-codex-review.md` + opus-review.md) and push.

### TASK B — Pass 2B implementation

**Scope per synthesis v5 §8.D + §9:**

1. **Device receiptService refactor:** `apps/pos/src/lib/offline/receiptService.ts` `createOfflineReceipt()` rewritten to:
   - Build the canonical 27-key `SALE_RECEIPT` payload via a new `apps/pos/src/lib/fiscal/payloads/SaleReceiptPayload.ts` payload-translation layer (currency_scale + bcformat money strings per PHP `FiscalPayloadConstraintValidator::validateSaleReceiptPayload`).
   - Call `engine.append({event_type: 'SALE_RECEIPT', payload, …})` inside the existing SQLite tx.
   - `offline_receipts.canonical_bytes` MIRRORS `fiscal_events.canonical_bytes` from engine return.
   - Atomic rollback semantics: any sub-write failure aborts entire tx; engine idempotency (`source_event_class`, `source_event_id`) prevents double-author on retry.

2. **Engine singleton wiring (Amended A1):** create `apps/pos/src/lib/fiscal/instance.ts` with `getFiscalEventEngine(companyId: string): Promise<FiscalEventEngine>` — async, per-companyId-keyed memoised factory mirroring `getDatabase(companyId)` precedent. Test reset helper `__resetFiscalEventEngineForTesting()`. Wire 4 explicit FiscalEventEngine constructor args.

3. **Genesis seed mirror (Amended A2):** extend `apps/pos/src/lib/db/repositories/terminalStateRepository.ts` `upsertTerminalState` to write-once mirror `genesis_seed` (already in TerminalResource:42 server-side) into v37 `terminal_state.fiscal_event_genesis_seed`. Never overwrite non-empty (chain-restart protection). Re-claim with mismatched seed → throw new `ChainGenesisSeedConflictError`.

4. **Tenant + company threading (Amended A3):** `OfflineReceiptInput` gains required `tenantId: string` + `companyId: string`. `paymentStore.createReceiptLocalFirst()` reads from `useTerminalStore.getState().activeTerminal`; throws new `ActiveTerminalRequiredError` if missing. NO `useAuthStore` reads inside receiptService (CLAUDE.md rule 13).

5. **Concurrent-receipt mutex (Amended A5):** create `apps/pos/src/lib/offline/terminalMutex.ts`:
   ```ts
   const queues = new Map<string, Promise<unknown>>();
   export function lockTerminal<T>(tenantId: string, terminalId: string, fn: () => Promise<T>): Promise<T> {
     const key = `${tenantId}:${terminalId}`;
     const prev = queues.get(key) ?? Promise.resolve();
     const next = prev.then(fn, fn);
     queues.set(key, next.catch(() => {}));
     return next;
   }
   ```
   `paymentStore.createReceiptLocalFirst()` wraps the entire `createOfflineReceipt` call. On `ConcurrentChainAdvanceError`: retry with linear backoff (50ms, 100ms, 200ms); after 3 retries → `FiscalChainContentionError`.

6. **Legacy chain DELETION** per synthesis v5 §9:
   - Device-side: DELETE `computeV3FiscalHash`, `buildCanonicalPayload`, `computeFiscalHash` imports, direct writes to `terminal_state.last_hash`/`hash_sequence`, v3 fiscalSchemaVersion branching. DELETE `apps/pos/src/lib/fiscal/v3/` directory.
   - Device-side: `lib/sync/syncService.ts` — DELETE `/pos/receipts/sync` POST path; REPLACE with `/pos/sync/fiscal-events` push (Task 20's endpoint).
   - Server-side: DELETE `SyncController::sync` handler + `ReceiptSyncService::sync()` consumer + `SyncReceiptPayload` DTO + `SyncReceiptsRequest` + `SyncReceiptResult` + the route in POS routes.php. Per-method skip the §14.1 feature suites with citation OR delete entirely.

7. **`.PASS_2B_PENDING` marker DELETION (atomic with engine wiring):** delete the marker file ATOMICALLY with adding the engine wiring in receiptService.ts / paymentStore.ts. The CI sentinel will block any PR that adds engine wiring while the marker exists — the only legal way to merge Pass 2B is to atomically delete the marker AND add wiring in the same commit.

8. **Task 28 absorption:** the synthesis v5 §8.D plan says Pass 2B absorbs Task 28 (the `/pos/receipts/sync` retirement is part of step 6 above).

9. **Device-side test migration per Amended A6 (Hybrid strategy):**
   - **Bucket 1 KEEP as unit tests (mocks)** — ~600-700 LOC: cart-line aggregation, voucher dedup, currency-scaling, line discount logic, payload-assembly correctness. Tests mock `FiscalEventEngine.append` and assert 27-key payload shape passed.
   - **Bucket 2 MIGRATE to `SqliteTestAdapter` integration tests** — ~300-400 LOC: engine-append + projector-write tx; split-payment with foreign currency; voucher-redemption + dedup; atomic rollback on voucher-update failure / unique-key collision / cross-tenant guard; idempotency on retry (same source_event_id); genesis-seed-empty rejection; cross-tenant guard; refund/void path with `original_receipt_reference`; training-mode; D16 buyer-block snapshot (delete customer mid-projection); concurrent-receipt serialization rule from Amended A5.
   - **Bucket 3 DELETE** — ~400-500 LOC: `computeReceiptHash`/`computeV3FiscalHash`/`buildCanonicalPayload` internal tests; tests asserting `terminal_state.last_hash`/`hash_sequence` via direct SQL; v3 hash chain shape tests; legacy `offline_receipts.fiscal_hash` shape tests.

10. **Source-level guards** (per Codex P2.14 standing pattern):
    - Forbid in `receiptService.ts`: `computeReceiptHash`, `computeV3FiscalHash`, `computeFiscalHash`, raw `UPDATE terminal_state SET (last_hash|hash_sequence|chain_sequence)` SQL.
    - REQUIRE at least one `.append(` invocation on a value imported as `FiscalEventEngine` (AST-aware OR scoped regex over non-comment lines).
    - Pass 2B's grep gate inherits Pass 2A's gate AND extends to include `apps/pos/src/lib/offline/**` + `apps/pos/src/stores/**`. After Pass 2B merge: ZERO matches of OLD 10-key signature across the worktree.

**Pre-commit verification for Pass 2B:**
```bash
cd /Users/houssamr/Projects/syneriva/apps/erp.fiscal-phase1/apps/pos
pnpm test
pnpm typecheck
pnpm lint

cd /Users/houssamr/Projects/syneriva/apps/erp.fiscal-phase1/apps/api
./vendor/bin/phpunit tests/Feature/Fiscal/ tests/Unit/Fiscal/
./vendor/bin/phpstan analyse --level=8
./vendor/bin/pint --test

bash /Users/houssamr/Projects/syneriva/apps/erp.fiscal-phase1/apps/api/scripts/check-saleReceipt-chokepoints.sh
bash /Users/houssamr/Projects/syneriva/apps/erp.fiscal-phase1/apps/pos/scripts/check-pass-2b-pending.sh   # MUST exit 0 (marker deleted)
```

After Pass 2B commits: self-adversarial-review → fix → Opus second-pass → fix → push.

**Scope estimate:** ~2-3K LOC across ~15+ files. Expected ~2-3 review rounds.

### TASK C — Task 33 (full-flow verification + Phase 1 closure)

Per plan Task 33: end-to-end verification of an engine-authored sale flowing through the new sync endpoint to the server projection. Update roadmap v2 status. Branch ready for merge to dev.

Specifics:
- Integration test: device authors SALE_RECEIPT → seals → syncs via `/pos/sync/fiscal-events` → server ingests → projects → NF525 export reads from canonical via `CanonicalPayloadReader` → byte-equivalence check (canonical_bytes server-stored matches device-authored).
- Verify chain integrity: device-side chain via `terminal_state.fiscal_event_*`; server-side chain via `fiscal_events`. Re-hash via `HashChainIntegrityProvider`.
- Verify NF525 export produces correct output for sale + refund + void + training.
- Update roadmap v2 status entry for Phase 1.

### TASK D — Handoff §4 refresh + memory + final push

After Task 33: refresh `docs/superpowers/coordination/2026-05-14-pos-fiscal-engine-session-handoff.md` §4 with the synthesis-resolution arc + Pass 2A.PHP.1/PHP.2/TS + Pass 2B + Task 33 closure. Refresh memory at `~/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/project_pos_fiscal_event_engine.md`. Push.

After this: Phase 1 branch is ready for merge to `dev`.

---

## 7. Critical files for Pass 2B (read first when starting)

- `apps/pos/src/lib/offline/receiptService.ts` (641 LOC — the entry point you're refactoring)
- `apps/pos/src/stores/paymentStore.ts` lines 320-510 (the live caller of createOfflineReceipt at line 361)
- `apps/pos/src/stores/terminalStore.ts` (the claim consumer; extend upsertTerminalState here)
- `apps/pos/src/lib/db/repositories/terminalStateRepository.ts` lines 134-191 (upsertTerminalState)
- `apps/pos/src/lib/fiscal/FiscalEventEngine.ts` (already 27-key-aware post-2A.TS)
- `apps/pos/src/lib/db.ts` line 7 (`getDatabase(companyId)` — the precedent for `getFiscalEventEngine(companyId)`)
- `apps/api/app/Modules/POS/Presentation/Controllers/SyncController.php` (the /pos/receipts/sync handler to delete)
- `apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php` (the sync consumer to delete)
- `apps/api/app/Modules/POS/routes.php` (the route to delete)
- `apps/pos/src/lib/offline/.PASS_2B_PENDING` (the marker to delete atomically with Pass 2B engine wiring)
- `apps/pos/scripts/check-pass-2b-pending.sh` (the CI sentinel that enforces atomic removal)
- `apps/pos/src/lib/offline/__tests__/offlineCheckoutService.test.ts` line 275 (Pass 1 source-level guard — extend per Pass 2B)
- `apps/pos/src/lib/db/__tests__/helpers/sqliteTestAdapter.ts` (better-sqlite3 since Task 32 R2)
- `apps/api/scripts/check-saleReceipt-chokepoints.sh` (§14.3 chokepoint gate — MUST still PASS post-Pass-2B)
- `apps/api/scripts/saleReceipt-chokepoint-manifest.json` (Pass 2B updates `retired_in_task` markers from "Task 28" to "Task 27B Pass 2B")
- `.github/workflows/ci.yml` line ~404 (PG-merge-gate filter — extend with new test classes Pass 2B introduces)

---

## 8. The §14.3 chokepoint gate semantics

CI gate at `apps/api/scripts/check-saleReceipt-chokepoints.sh` enforces that EVERY production caller of server-side `ReceiptCreationService::createReceipt()` + `ReceiptFinalizationService::finalize()` is dispositioned per the manifest at `apps/api/scripts/saleReceipt-chokepoint-manifest.json`. Manifest entries have `disposition` (a/b/c) + `retired_in_task` markers.

**Pass 2B does NOT add new server-side createReceipt/finalize callsites.** The device-side `engine.append()` bypass IS the design. But Pass 2B's deletions (the /pos/receipts/sync route + ReceiptSyncService::sync) might affect manifest entries — verify and update `retired_in_task` markers from "Task 28" to "Task 27B Pass 2B" for the relevant entries.

---

## 9. Owner directives (D1-D9 from synthesis v5)

These are LOCKED and load-bearing — do not re-litigate:

- D1. 10-field PHP shape is incomplete; adopt 27-key canonical (DONE in Pass 2A.PHP.1).
- D2. NO DUAL CHAIN — legacy v3 fiscal chain DELETED in Pass 2B (device-side authoring path).
- D3. Specs for all 4 regimes (NF525 + ZATCA + DE + IT); implement NF525 + Tunisia immediately; ZATCA + DE + IT DEFERRED for later.
- D4. v1 rewrite in place — no event_version bump; no production tenants exist.
- D5. Server-side mirror columns (`pos_receipts.fiscal_hash` etc.) stay through Pass 2; Phase-1.5 audit + drop task in roadmap v2.
- D6. DROP feature flag. Pass 2A + 2B = clean commits on dev branch (the `.PASS_2B_PENDING` marker + CI sentinel enforces sequencing without a feature flag).
- D7. B2C Simplified only via Tauri POS. Existing web B2B flow UNTOUCHED. `invoice_subtype_code` DROPPED from payload (synthesis v5 §0).
- D8. B2B / ZATCA Tax Invoice path TBD later (POS-authored vs web-B2B-aggregated).
- D9. Tunisia priority + immediate target. NF525-certifiable canonical satisfies Tunisia by superset.

---

## 10. Status check before starting

Before you begin TASK A, verify:
```bash
cd /Users/houssamr/Projects/syneriva/apps/erp.fiscal-phase1
git status                            # should show clean working tree
git log --oneline -3                  # should show 8f358d5e6 at top
git branch --show-current             # should show feat/pos-fiscal-event-engine-phase1
```

Then read §4 artifacts in order. Then execute §6 TASK A. Move to B + C + D sequentially.

---

## 11. Final notes

- **Push after each task** (after dual review APPROVE).
- **Brief the user once per major task** (don't ask for confirmation; just status).
- **No dispatch-based handoff to a NEW Codex session** unless context overflows — keep all work in the same session if possible.
- **Use Write tool for new files; Edit for modifications** — these are guaranteed to land in the worktree regardless of sandbox config.
- **Per-method skip discipline** — never class-level. Each skip cites the surviving owner.
- **Verify the premise of every deferral** before deferring — grep-confirm the upstream claim.
- **Trust the file** when Codex's wrapper summary diverges from the review file (per project memory standing pattern).

You inherit a solid base. Pass 2A.PHP.1/PHP.2/TS are landed + reviewed + pushed. Pass 2B is the largest remaining cascade; Task 33 is the verification gate. End state: 33-of-33 Phase 1 task slots shipped; branch ready for merge to dev.

Good luck. Sleep well.

— Outgoing controller, 2026-05-20
