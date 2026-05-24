# Codex Handover Brief — Phase 4 + Z-Report Chain Clean Rebuild (End-to-End, Parallel)

**Created:** 2026-05-22
**Author:** Outgoing controller (transitioning to Codex-led continuation)
**Purpose:** Single comprehensive handover for the next two fiscal-event-engine workstreams — Phase 4 (Account Status + Overrides + Cash-Out + Approval Primitive) and the parallel Z-Report Chain Clean Rebuild. These two workstreams are **independent** at the chain-protocol level (per roadmap v2 + SoT v3 §12) and can run on separate branches simultaneously. Codex chooses the order based on capacity. Once both ship, the fiscal foundation is functionally complete for the production hardening + multi-jurisdiction expansion path.

This brief is **self-contained** — Codex starts cold with no prior session context. Read top-to-bottom, then execute §5/§6 work. **Two owner-input questions are pre-surfaced inline (§5 D-Q1 + §6 D-Q1) — Codex should flag both during the corresponding spec reviews.**

---

## 1. Mission summary

Two large workstreams remain in roadmap v2 before the deferred Phase 5 + customer-facing go-live items. They are independent:

- **Phase 4 — Account Status + Overrides + Cash-Out + Approval Primitive.** Operational hardening: lifecycle states for customer accounts (active/suspended/closed/disputed), supervisor override flows for constrained POS actions, cash drawer events (CASH_IN / CASH_OUT / SAFE_DROP / CASH_CORRECTION per fiscal_chain_architecture_strategy.md §19), and a PIN-based approval primitive at the POS (push notifications to a manager's phone deferred to a later iteration). Plus the per-anomaly-class policies for high-severity classes as more jurisdictions/providers come online.
- **Z-Report Chain Clean Rebuild — parallel workstream.** Independent at the chain-protocol level from the receipt chain (per SoT v3 §12). Currently server-recomputes the Z-report (same broken pattern Phase 1 fixed for receipts). Needs the same device-authority rebuild treatment: device-authored + sealed `SESSION_OPEN` / `SESSION_CLOSE` / `X_REPORT` / `Z_REPORT` events flowing through the Phase 1 engine, server-verify-only mirror, canonical Z_REPORT payload aligned with NF525 GRANDTOTAL_DAY/MONTH/YEAR + DSFinV-K + IT daily XML. **Coordination caveat:** shared `terminal_state` table + `Nf525DataProvider` export surface. Coordinate with any in-flight Phase 4 work.

Workflow throughout: **Codex implements + Codex self-adversarial review + Opus second-pass adversarial review + fix + iterate until APPROVE.** Same pattern that landed Phase 1 final continuation + Phase 2 + Phase 1.5.2 + Phase 1.5.3 + Phase 3 cleanly.

Codex may run both workstreams in parallel on separate branches if capacity permits. The standing-pattern around shared `terminal_state` + `Nf525DataProvider` makes serial execution safer for a single Codex agent; parallel via multiple branches is fine if two agents.

---

## 2. Worktree + branch state (CRITICAL)

- **`origin/dev` HEAD:** `2bdabd41f` (Phase 3 merge, 2026-05-22). All prior phases + Phase 3 + Phase 1.5.2 + Phase 1.5.3 integrated.
- **Main worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/` — detached HEAD. NEVER cd here.
- **Active worktrees (do not disturb):**
  - `apps/erp.fiscal-phase1/` — Phase 1 branch (merged).
  - `apps/erp.customer-accounts-phase2/` — Phase 2 branch (merged).
- **Tunisia + France soft-launch dry run scheduled 2026-05-25.** Phase 4 + Z-Report work landing before that date is welcome but not blocking; if either workstream is mid-merge on 2026-05-25, just don't merge during the dry-run window.

**Create two new branches off latest `origin/dev`** — one per workstream:
- `feat/fiscal-phase-4-account-status-overrides-approval` (full phase cycle).
- `feat/fiscal-z-report-chain-clean-rebuild` (full phase cycle).

Optional: dedicated worktrees mirroring the Phase 1/Phase 2 pattern:
```bash
cd /Users/houssamr/Projects/syneriva/apps/erp
git fetch origin
git worktree add /Users/houssamr/Projects/syneriva/apps/erp.phase-4 -b feat/fiscal-phase-4-account-status-overrides-approval origin/dev
git worktree add /Users/houssamr/Projects/syneriva/apps/erp.z-report -b feat/fiscal-z-report-chain-clean-rebuild origin/dev
# Verify each apps/api/vendor is a real copy not a symlink (Phase 1/2 pattern)
```

**Stage explicit files only — NEVER `git add -A`. Use absolute paths in Bash.**

---

## 3. Authoritative artifacts (READ in order)

1. **Roadmap v2** — `docs/superpowers/specs/2026-05-14-pos-customer-accounts-roadmap-v2.md` — especially §Phase 4 + the parallel Z-Report Chain Clean Rebuild section. "Locked inputs every phase inherits" + "Per-phase process."
2. **Phase 1 session handoff** — `docs/superpowers/coordination/2026-05-14-pos-fiscal-engine-session-handoff.md` — 29+ standing patterns. Read §4.2 in full. The HOT patterns for Phase 4 + Z-Report: dead-path rebuild, cross-tenant FK safety, fail-loud over silent-downgrade, discriminated-union test matrix in round-1, per-method `markTestSkipped`, CI gate dependency same-commit, D16 grep guard pattern.
3. **Source-of-truth v3** — `docs/superpowers/research/2026-05-14-offline-first-fiscal-source-of-truth-v3.md` — LOCKED architecture. Especially §1 device-authority pattern (Z-report rebuild MUST follow this), §6 clock/time model (closure period rule is normative for Z-report), §7.1 per-anomaly-class integrity exceptions (Phase 4 surfaces high-severity policies), §12 (Z-report chain is independent at chain-protocol level), §13.6 + D16 bounded-modules asymmetric seam.
4. **Codebase reality audit** — `docs/superpowers/research/2026-05-14-pos-fiscal-codebase-reality.md` — every codebase claim in your spec must trace here. Especially the existing Z-report surface (`Nf525DataProvider` + `terminal_state` shared with receipt chain).
5. **Phase 1 spec v7** — `docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md` — §11.0 server-authoring carve-out (`SESSION_OPEN`/`SESSION_CLOSE`/`X_REPORT`/`Z_REPORT` are DEVICE-authored per §1 default, NOT in the §11.0 carve-out; verify), §11.1 implemented/reserved event types.
6. **Phase 1 synthesis v5** — `docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md` — the 27-key SALE_RECEIPT canonical pattern. Z_REPORT payload should follow the same compliance-rich design pattern (multi-country superset, not minimum).
7. **External multi-country research** — `docs/superpowers/research/2026-05-20-multicountry-fiscal-research.md` — NF525 / ZATCA / DE / IT field citations. For Z_REPORT, **NF525 §3.5.2 GRANDTOTAL Event** is the primary canonical reference: period type (DAY/MONTH/YEAR) + period start/end + aggregated VAT breakdown (rate, totalNetAmount, totalVatAmount, totalGrossAmount, transactionCount per rate) + period totals + returns/refunds + signature/chain fields. Plus DSFinV-K Z-report fields (different shape per German jurisdiction) and IT Tipi Dati XML aggregates.
8. **Owner strategy doc — fiscal chain architecture** — `~/Downloads/fiscal_chain_architecture_strategy.md` — §19 cash drawer event types (OPENING_FLOAT, CASH_IN, CASH_OUT, SAFE_DROP, CASH_CORRECTION); §20 session event types (SESSION_OPEN, SESSION_CLOSE, X_REPORT, Z_REPORT); §21 Z-report logic (close session + freeze totals + aggregate VAT + aggregate payments + archive session hash + prevent retroactive modification). This is the architectural intent for both Phase 4 cash-drawer scope AND Z-Report chain rebuild.
9. **Phase 2 + Phase 3 handover briefs** — `docs/superpowers/coordination/2026-05-20-codex-handover-brief.md` + `2026-05-21-codex-handover-phase-1-5-and-phase-3.md` — workflow grounding (Codex implements + self-adversarial + Opus second-pass). Read both for context on how Phase 2 + Phase 3 cycles were structured.
10. **`/Users/houssamr/Projects/syneriva/apps/erp/CLAUDE.md`** — project rules. Especially rule 5 (verification is law, end-to-end) + rule 13 (constructor injection only).

---

## 4. Workflow (per roadmap v2 "Per-phase process" + Codex-led continuation)

Same pattern that worked for Phase 1.5.2 + Phase 1.5.3 + Phase 3. For EACH workstream:

### Stage A — Spec

1. Codex writes spec at `docs/superpowers/specs/2026-05-2X-<workstream>-spec-v1.md`. Grounding cited explicitly.
2. Codex self-adversarial review → review file. Iterate to v2/v3.
3. Opus second-pass adversarial review of the spec. Iterate.
4. Once both APPROVE: commit spec + reviews.

### Stage B — Plan

5. Codex writes implementation plan via `writing-plans` skill.
6. Codex self-adversarial review of the plan. Iterate.
7. Opus second-pass adversarial review of the plan. Iterate.
8. Once both APPROVE: commit plan + reviews.

### Stage C — Execution

9. Codex executes plan task-by-task. Per-task: implement → Codex self-adversarial → Opus second-pass → fix → push.
10. R2 standing pattern: R2 fixes occasionally introduce new defects. ALWAYS re-self-review R2.

### Stage D — Closure

11. Full-flow verification test analogous to Phase 2 Task 11 / Phase 3 Task 11.
12. Refresh handoff §4 + memory + roadmap v2 status.
13. Open PR to base `dev`. Auto-merge via `gh pr merge --auto --merge` once CI passes.

---

## 5. Phase 4 — Account Status + Overrides + Cash-Out + Approval Primitive

**Branch:** `feat/fiscal-phase-4-account-status-overrides-approval` off `origin/dev`.

### 5.1 — Scope (per roadmap v2 §Phase 4)

> The account-status lifecycle; override flows; cash-out controls; the approval primitive (PIN now, push later); the per-anomaly-class policies for high-severity classes as more jurisdictions/providers come online.

Concretely:

1. **Account-status lifecycle for customer accounts.** Customer's account moves through `active / suspended / closed / disputed` states. State transitions are auditable + reason-required. POS reflects the status on customer attach (e.g. cannot charge-to-account a suspended customer). Backend reconciliation flows for status changes.

2. **Override flows for constrained POS actions.** Catalogue of actions that today fail-closed (per Phase 1/2/3 strict guardrails) but operationally need a supervisor-authorized override path:
   - Over-credit-limit charge (Phase 3's rules engine rejects; override allows).
   - Voiding a finalized receipt (per Phase 1 §14.2 carve-out — server-side void/return retained for Phase 1; Phase 4 adds the device-side authorization flow).
   - Applying discounts beyond cashier authority (existing discount permission chain per memory).
   - Operator manually adjusting tendered amount tolerance beyond configured limits.
   Each override is its own fiscal event type (e.g. `OVERRIDE_CREDIT_LIMIT`, `OVERRIDE_DISCOUNT_LIMIT`) sealed via the engine.

3. **Cash drawer events** per owner strategy doc §19:
   - `OPENING_FLOAT` — at shift start, cash put into drawer.
   - `CASH_IN` — non-sale cash added (e.g. bank deposit, change).
   - `CASH_OUT` — operator removes cash (e.g. small expense paid from drawer).
   - `SAFE_DROP` — operator drops cash to safe mid-shift.
   - `CASH_CORRECTION` — adjustment for reconciliation discrepancy.
   Each is a device-authored fiscal event sealed via the engine. Per anti-fraud language in strategy doc §19 + §25 (audit & fraud detection).

4. **Approval primitive (PIN now, push later).**
   - PIN: at the POS, supervisor enters a PIN at the terminal to authorize a constrained action. PIN validated against the supervisor's user record (hashed) with rate limits + audit logging.
   - Push deferred: future iteration delivers a push notification to the supervisor's phone for remote approval (out of Phase 4 scope; design the PIN flow so push can plug into the same approval-resolved event later).
   - Approval is itself a fiscal event (e.g. `OPERATOR_APPROVAL_GRANTED`) sealed in the same chain. Cross-references the constrained action's fiscal event id.

5. **Per-anomaly-class policies for high-severity classes.** Per SoT v3 §7.1, the existing anomaly classes (`canonical_hash_mismatch`, `canonical_parse_failure`, `time_anomaly`, `sequence_gap`) have Phase 1 reconciliation paths. Phase 4 adds policy refinements:
   - High-severity classes (e.g. `canonical_hash_mismatch` on a fiscal_event with a non-trivial monetary value) trigger operator-blocking incident state, not just quarantine.
   - Per-jurisdiction overrides (e.g. NF525 vs ZATCA may treat the same anomaly class differently for legal reporting).
   - Operator-acknowledgment workflow (already partly in place per Phase 1 §8 quarantine + Task 24 resolution).

### 5.2 — Owner decision required (surface in Phase 4 spec review)

**D-Q1 — Approval Primitive Identity Model.** PIN authorization can be implemented two ways:
- (a) **Per-supervisor PIN** stored hashed against the user record. Supervisor enters their PIN at the POS; system validates against their account. Audit trail records WHICH supervisor approved.
- (b) **Per-terminal shared override PIN** rotated periodically. Simpler operationally; weaker audit (can't pin which specific manager authorized).

Recommendation: **(a) per-supervisor PIN.** Stronger audit; matches the device-loss incident register pattern (per Phase 1 Task 32 §12) which tracks per-user evidence. Push deferred but designed-for: the supervisor's user record already carries push-token fields per existing User model.

Codex should LOCK this in the spec unless owner overrides. Flag it explicitly in the spec review for owner sign-off before plan-writing.

### 5.3 — Specific implementation notes

- **Customer account-status enum:** new `CustomerAccountStatus` enum (`active`, `suspended`, `closed`, `disputed`). Add column to the Customer / Partner mirror table.
- **Override event types:** new `FiscalEventType` enum cases (`OVERRIDE_CREDIT_LIMIT`, `OVERRIDE_DISCOUNT_LIMIT`, `OPERATOR_APPROVAL_GRANTED`, etc.). Each carries the reference event ID it authorizes.
- **Cash drawer event types:** new `FiscalEventType` enum cases per §5.1 #3. Same canonical payload pattern as other Phase 1 events.
- **PIN authentication:** server-side User model gains `supervisor_pin_hash` (nullable, bcrypt). Device queries server at supervisor-attach time OR caches authorized supervisors locally (offline-friendly with sync). Decide in spec.
- **D16 enforcement:** override flows + cash drawer events MUST NOT call cross-module operational paths from the POS-core projector. New `App\Shared\Contracts\Fiscal\OperatorApprovalResolver` interface pattern if cross-module reads needed.
- **Test coverage:** discriminated-union test matrix for the override resolution lifecycle (granted / denied / expired / unsigned / cross-tenant).

### 5.4 — Pre-commit verification + commit + PR

Standard from Phase 1/2/3:
```bash
cd <worktree>/apps/api
./vendor/bin/phpunit tests/Feature/Fiscal/ tests/Unit/Fiscal/ tests/Feature/POS/
./vendor/bin/phpstan analyse --level=8 [touched paths]
./vendor/bin/pint --test [touched files]

cd ../apps/pos
pnpm test
pnpm typecheck
pnpm lint

bash <worktree>/apps/api/scripts/check-saleReceipt-chokepoints.sh
bash <worktree>/apps/pos/scripts/check-pass-2b-pending.sh
```

CI PG-merge-gate filter at `.github/workflows/ci.yml:~404` extended in same commit for any new PG-specific fiscal test class.

PR to base `dev` via `gh pr create`. Auto-merge via `gh pr merge --auto --merge` once CI passes.

**Expected scope:** ~6-9K LOC across spec + plan + multi-task implementation. ~12-20 plan tasks. ~3-5 review rounds per task on average.

---

## 6. Z-Report Chain Clean Rebuild — parallel workstream

**Branch:** `feat/fiscal-z-report-chain-clean-rebuild` off `origin/dev`.

### 6.1 — Scope (per roadmap v2 + SoT v3 §12)

> The Z-report chain is **independent at the chain-protocol level** from the receipt chain and also currently server-recomputes. It needs the same clean-rebuild treatment as its own task — schedulable alongside or just after Phase 1, not blocking the customer-facing phases.
>
> **Coordination caveat:** "independent" holds for chain *semantics* only — it is not physically isolated. The receipt and Z-report chains share the local `terminal_state` table + its repository code and the `Nf525DataProvider` export/verification surface. A parallel Z-chain task must coordinate its `terminal_state` migrations and those shared repository/export touchpoints with Phase 1 to avoid racing on the same code.

Concretely, Z-Report Chain Clean Rebuild = a parallel version of the Phase 1 receipt-chain rebuild, applied to Z-report (period closure) events:

1. **Move Z-report from server-recompute → device-authored + server-verify-verbatim.** Same D1+D2 pattern as Phase 1 SALE_RECEIPT. Device authors `Z_REPORT` (and its cousins `SESSION_OPEN`, `SESSION_CLOSE`, `X_REPORT`) locally with JCS-canonical bytes + SHA-256 chain hash via the Phase 1 engine. Server is verify-only mirror.

2. **New event types** per owner strategy doc §20:
   - `SESSION_OPEN` — operator starts a shift; opening cash float reference.
   - `SESSION_CLOSE` — operator ends a shift; counted-cash + drawer-state snapshot.
   - `X_REPORT` — informational session snapshot (does NOT close session; reversible).
   - `Z_REPORT` — closes session; freezes totals; aggregates VAT; aggregates payments; archives session hash; prevents retroactive modification.

3. **Canonical Z_REPORT payload — multi-country compliance superset.** Per multi-country research §1 (NF525 GRANDTOTAL Event) + §3 (DSFinV-K Z-report Bonkopf_Abschluss) + §4 (IT daily XML Tipi Dati):
   - Period type (`DAY` | `MONTH` | `YEAR`).
   - Period start / end timestamps (per SoT v3 §6 normative closure-period rule).
   - Aggregated VAT breakdown per rate: rate, totalNetAmount, totalVatAmount, totalGrossAmount, transactionCount.
   - Period totals: total transactions, total net, total VAT, total gross.
   - Returns / refunds aggregate (included in totals as negative per NF525 v2.1).
   - Payment method aggregate (per type: cash, card, electronic — NF525 v2.1 + DSFinV-K Bonkopf_AbrKreis).
   - Cash drawer state at close (opening float, cash in, cash out, safe drops, counted close, variance).
   - Sequence number + previous_hash + current_hash (chain integrity).
   - Operator identification (cashier who closed).
   - Terminal identification.
   - Training-flag (closure of a training session is NOT signed to production chain per SoT v3 + DSFinV-K Trainingsbuchung pattern).

4. **Z_REPORT chain semantics independent at chain-protocol level.** Z-report sequence numbers are SEPARATE from receipt sequence numbers. Two chains, same engine. Verify Phase 1 engine supports multi-chain authoring by event-type partition (it should per the per-type sequence column on `fiscal_events`).

5. **`terminal_state` coordination.** The receipt chain wrote `fiscal_event_*` columns + Phase 1.5.1 audit retained legacy `last_hash` / `hash_sequence` columns (decision: keep, drop deferred). Z-Report rebuild needs its OWN chain-head columns (`z_chain_genesis_seed`, `z_chain_last_hash`, `z_chain_sequence`) added via a new SQLite + PostgreSQL migration. The existing `terminal_state` repository code is shared — coordinate edits.

6. **`Nf525DataProvider` coordination.** The existing NF525 export reads from canonical_bytes for receipts (Phase 1 Task 30 + Pass 2A.PHP.2). Extend to read Z_REPORT canonical_bytes for the GRANDTOTAL_DAY/MONTH/YEAR JET export sections. Same bifurcation pattern as receipts (`fiscal_event_id IS NOT NULL` → canonical; `IS NULL` → legacy fallback retained briefly).

7. **DSFinV-K Z-report export.** Phase 1 deferred DSFinV-K to a future SA-specific phase, but the Z_REPORT canonical-byte readability should support a future DSFinV-K adapter without further canonical changes. Canonical-only fields per multi-country research §3 (Bonkopf_Abschluss, Bonkopf_AbrKreis, TSE_Transaktionen variant for closure).

### 6.2 — Owner decision required (surface in Z-Report spec review)

**D-Q1 — Cash drawer event types: Phase 4 OR Z-Report rebuild?** The cash drawer event types (`OPENING_FLOAT`, `CASH_IN`, `CASH_OUT`, `SAFE_DROP`, `CASH_CORRECTION` per §5.1 #3 + strategy doc §19) are logically part of the session lifecycle (a Z_REPORT closes a session that contains cash-drawer events). The roadmap places "cash-out controls" under Phase 4 BUT a clean session model puts the cash drawer events under the Z-report chain.

Two valid interpretations:
- (a) **Phase 4 owns cash drawer events** as ad-hoc fiscal events sealed via the receipt chain (default chain). Z-Report rebuild only owns the closure aggregate.
- (b) **Z-Report rebuild owns cash drawer events** as session-lifecycle children of `SESSION_OPEN` / `SESSION_CLOSE`. Cash drawer events are part of the Z-chain.

Recommendation: **(b) Z-Report rebuild owns cash drawer events.** Cleaner semantic. Cash drawer events should be summarized in the Z_REPORT canonical payload (per multi-country research §3 DSFinV-K Bonkopf_AbrKreis + NF525 GRANDTOTAL cash totals). A session's cash drawer history is intrinsically session-scoped.

Codex should LOCK this in the Z-Report spec unless owner overrides. Flag in spec review.

### 6.3 — Specific implementation notes

- **New SQLite + PostgreSQL migration** for `terminal_state` Z-chain columns. Coordinate the migration ordering with any concurrent Phase 4 migrations (Phase 4 likely adds account-status columns to customer mirror; non-overlapping with terminal_state).
- **Phase 1 engine reuse:** confirm `FiscalEventEngine.append()` supports per-event-type chain partitioning. Read engine source carefully — if the engine assumes single-chain per terminal, Z-Report rebuild may need an extension. (Hypothesis: engine already partitions by event_type via the per-type sequence column on `fiscal_events`, but verify.)
- **POS-core Z-Report projector:** new `PosCoreZReportProjection` analogous to `PosCoreReceiptProjection`. Per-receipt aggregation queries against the SAME terminal's same-session `SALE_RECEIPT` projection rows; writes `pos_session_closures` mirror table + `pos_session_aggregates` line table.
- **NF525 GRANDTOTAL_DAY/MONTH/YEAR JET export** integration: extend `Nf525XmlBuilder` for the closure-period XML sections. Same JET byte-stability discipline as Phase 1 Task 30.
- **§14.3 chokepoint gate analog?** Phase 1's chokepoint gate guards server-side `createReceipt` + `finalize` callers. Z-Report rebuild should consider if an equivalent gate is needed for server-side `closeSession` / Z-report-generation callers. Codebase reality audit needed — check current server-side Z-report flow surface.
- **D16 enforcement:** Z-Report projector MUST follow the same Shared/Contracts pattern as receipt projector. Cross-module reads via interfaces.
- **Multi-deployment regression:** POS-only deployment seals Z_REPORT events + prints Z-report tape locally. Treasury-active deployment additionally projects to GL closing entries (out of scope? if so, defer to future GL-closure task).

### 6.4 — Pre-commit verification + commit + PR

Same as §5.4. Additionally:
- **Pass 2B sentinel** at `apps/pos/scripts/check-pass-2b-pending.sh` should continue to exit 0 (marker absent).
- **§14.3 receipt chokepoint gate** untouched.
- **New** Z-Report chokepoint gate if introduced per §6.3.

**Expected scope:** ~5-8K LOC. ~10-18 plan tasks. ~3-4 review rounds per task on average.

---

## 7. Workstream sequencing

Recommended:
- **Phase 4 first.** It's directly customer-facing (account status affects daily POS operation). Z-Report rebuild improves audit hygiene but Z-report works today (server-recompute, with the chain-break risk SoT v3 §12 acknowledges).
- **Z-Report rebuild after Phase 4 ships,** OR in parallel on a separate Codex agent if capacity permits + the two specs can be locked simultaneously.

If Codex chooses parallel, watch the shared surfaces:
- `terminal_state` repository code (both touch).
- `Nf525DataProvider` (both touch).
- Phase 1 engine's chain-partitioning (Z-Report verifies; Phase 4 doesn't directly touch).

Migration ordering: if migrations conflict, the second branch should rebase onto the first and update its migration timestamps. Standard merge-conflict resolution.

---

## 8. Standing patterns (29+ from Phase 1; carry unchanged)

Read handoff §4.2 in full. HOT for the remaining work:

- **Dead-path rebuild (Tasks 30 + 32 + PHP.1/PHP.2/Pass 2A.PHP.2 R1):** every new code path needs a live caller. **Z-Report rebuild's new projector + new NF525 export sections are HIGH risk for this pattern.**
- **Cross-tenant FK safety (Task 21 R2 + Pass 2A.PHP.2 BLOCKER-1):** scope all FK lookups by tenant_id. **Phase 4's supervisor PIN lookup MUST be tenant-scoped.**
- **Fail-loud over silent-downgrade (Task 22 + Pass 2A.PHP.2 BLOCKER-2):** projection failures throw typed exceptions. **Phase 4's override-resolution path MUST fail-loud when prerequisites missing.**
- **Discriminated-union test matrix in round-1 (Task 20):** **Phase 4's override-lifecycle has many variants (granted/denied/expired/unsigned/cross-tenant/over-rate-limited) — exhaustive in round-1.**
- **DB primitives load-bearing (Task 19):** raw INSERT ON CONFLICT for chain inserts.
- **R2 introduces new defects (Tasks 23/24/25/30/Pass 2A.PHP.2):** ALWAYS re-self-review R2.
- **Per-method `markTestSkipped` ONLY (Task 29):** never class-level.
- **Skip-citation accuracy (Task 29):** grep-verify the surviving owner.
- **CI gate dependency setup in same commit (Task 30):** extending a gate updates job deps.
- **D16 grep guard pattern (Pass 2A.PHP.2):** projector files MUST NOT import Customer/Contact/B2B/Treasury/Accounting directly. Use `App\Shared\Contracts\<X>\<Resolver>` interface pattern.
- **Bounded-modules asymmetric seam (D16):** inbound mirror data permitted; outbound operational dependency forbidden.

---

## 9. Owner directives D1-D16 (LOCKED — do not re-litigate)

- **D1**: Compliance-rich canonical payload — Phase 4 + Z-Report event types follow the multi-country superset pattern.
- **D2**: NO DUAL CHAIN — Z-Report rebuild eliminates server-recompute path entirely (same pattern as Phase 1 receipt rebuild).
- **D3**: NF525 + Tunisia FIRST. ZATCA + DE + IT deferred. Z-Report rebuild's primary target: NF525 GRANDTOTAL_DAY/MONTH/YEAR.
- **D4**: New event types use `event_version: 1`. Z_REPORT + SESSION_OPEN + SESSION_CLOSE + X_REPORT + cash drawer event types all start at v1.
- **D5**: Post-phase audit + drop unused mirror code. Any new mirror columns Phase 4 / Z-Report introduce get a post-phase cleanup analog.
- **D6**: No feature flags on dev. Atomic commits + `.PASS_*_PENDING` sentinel pattern if cross-commit sequencing needed (Z-Report rebuild may need this for the terminal_state migration → device authoring sequence).
- **D7**: B2C only via Tauri POS. Phase 4 supervisor PIN is B2C operator authority. Z-Report B2C operator closure.
- **D8** (deferred): ZATCA Tax Invoice path TBD later. Locked in Phase 3 as web-B2B-aggregates model. Phase 4 + Z-Report don't change this.
- **D9**: Tunisia priority. NF525 satisfies Tunisia. Z-Report rebuild primary target NF525 GRANDTOTAL.
- **D16** (SoT v3 §13.6): asymmetric bounded-modules seam. Phase 4 + Z-Report enforce via Shared/Contracts interface pattern.

---

## 10. Status reporting + autonomy

- **Brief the owner once per major milestone:** Phase 4 spec landed → Phase 4 plan landed → each Phase 4 task shipped → Phase 4 closure → Z-Report spec landed → ... etc. Concise: 1 paragraph + DONE / files touched / test counts / standing patterns verified.
- **Do NOT ask for confirmation between tasks.** Execute autonomously.
- **Only stop and surface if:**
  - You hit a BLOCKED you cannot resolve.
  - D-Q1 from §5.2 (supervisor PIN identity model) needs owner sign-off → flag in Phase 4 spec review.
  - D-Q1 from §6.2 (cash drawer event types ownership) needs owner sign-off → flag in Z-Report spec review.
  - Context fills to ~70% — write a fresh handover brief mirroring this one.

- **Open PRs to base `dev`** for each workstream. Auto-merge via `gh pr merge --auto --merge` once CI passes.

- **2026-05-25 Tunisia + France soft-launch dry run window:** if either Phase 4 or Z-Report PR is mid-merge on 2026-05-25, defer the merge until the dry-run window closes (probably end of day 2026-05-25). Don't disturb dev during the dry run.

---

## 11. Pre-commit verification (carry from Phase 1/Phase 2/Phase 3)

For EVERY commit:
```bash
cd <worktree>/apps/api
./vendor/bin/phpunit tests/Feature/Fiscal/ tests/Unit/Fiscal/ tests/Feature/POS/
./vendor/bin/phpstan analyse --level=8 [touched paths]
./vendor/bin/pint --test [touched files]

cd ../apps/pos
pnpm test
pnpm typecheck
pnpm lint

bash <worktree>/apps/api/scripts/check-saleReceipt-chokepoints.sh
bash <worktree>/apps/pos/scripts/check-pass-2b-pending.sh
```

CI PG-merge-gate filter at `.github/workflows/ci.yml:~404` extended in same commit for any new PG-specific fiscal test class.

---

## 12. Codex sandbox workaround (carried from Phase 1/2/3)

If Codex's `apply_patch` is sandbox-blocked from worktree writes:
- (a) Pre-create stub files via Write tool, then Codex modifies.
- (b) Return review content INLINE; controller (or user) transcribes.

For Codex-acting-as-implementer with full Write tool access via Claude Code CLI, less of an issue.

---

## 13. Final notes

- **Push after each task** (after dual review APPROVE).
- **Use Write tool for new files; Edit for modifications.**
- **Per-method skip discipline.**
- **Verify the premise of every deferral.**
- **Trust the file** when Codex's wrapper summary diverges from the review file.

You inherit a fully-landed Phase 1 + 2 + 1.5.2 + 1.5.3 + 3 state on `origin/dev`. Tunisia + France soft-launch dry run scheduled 2026-05-25. Phase 4 + Z-Report Chain Clean Rebuild are the next two workstreams. After they land, the fiscal foundation is functionally complete for production hardening + multi-jurisdiction expansion (Phase 5 deposits/AML/store-credit + future ZATCA / DE TSE / IT RT adapter implementations).

Good luck.

— Outgoing controller, 2026-05-22
