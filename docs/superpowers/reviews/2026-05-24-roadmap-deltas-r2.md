# Adversarial Review (Round 2) — Roadmap + Tauri Deltas Log
## Reviewer: Claude general-purpose
## Date: 2026-05-24

## Verdict
- Roadmap v2: **NEEDS-REVISION** (one BLOCKER, two P1s, two P2s — fixable in <1 day)
- Tauri Deltas Log v2: **NEEDS-REVISION** (one BLOCKER inherited from roadmap + protocol gaps)

## Summary

The v2 restructure correctly resolves three of the five round-1 BLOCKERs (B-3 T6 sequencing, B-4 fiscal collision framing, B-5 T4-on-T3 dependency). The two-wave model is sound in principle, and the topology contract is genuinely constitutional-grade. Effort sums check out exactly (61 PD), all 7 specs exist at the cited paths, and most claims about each spec's workflow / dependencies / wave assignment are accurate.

However, the central claim — "Wave 1 = zero Tauri POS file touch by sprint tracks, therefore no collision possible" — quietly elides a more dangerous fact: **Wave 1 collides with fiscal Phase 1 on backend POS files and on the central migrations directory itself**. T1 Phase 2 (Wave 1, server) explicitly modifies `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php` lines 831-883 (the P1-5 fix); fiscal Phase 1 Tasks 21/22 simultaneously rewrite that same file's siblings (`ReceiptSyncService.php`, `ReceiptPaymentService.php`) and the `pos_receipts` migration set. T2 Wave 1 owns `variant_id` additions to `pos_receipt_lines`, `receipt_line_batch_allocations`, and `payments` — all of which fiscal Phase 1 is actively modifying via Tasks 11/12 in the central migrations directory. The deltas log addresses Tauri-side collision; it is silent on backend-POS-module and migrations-directory collision. This is the same defect class as round-1 B-4, just relocated from Tauri to server side.

Secondary issues: the handshake protocol still has unresolved fiscal session ownership (the "default assumption: Houssam personally" carries through into actual operations); the T6 Phase 0 gate doesn't address how fiscal Phase 1's in-flight migrations (already committed at `2026_05_14_100001/100002/100003` under `database/migrations/`, with Tasks 11/12 still pending) get reclassified; T2's POS picker is "Wave 2 delta" but its Wave 1 server work (variant_id columns on pos_receipt_lines) writes migrations against tables fiscal Phase 1 actively modifies.

## Findings (BLOCKER / P1 / P2 / SUGGESTION)

### BLOCKER

**[R2-B1] Wave 1 collides with in-flight fiscal Phase 1 on backend POS module + on the central migrations directory, despite claim of "zero collision"**

**Claim:** `apps/erp/docs/superpowers/coordination/2026-05-24-productization-sprint-roadmap.md:54-56,124-126`: "WAVE 1 — Server-side work, ZERO POS touch. Full parallelization safe. No collision with fiscal Phase 1." and "Wave 1 = zero Tauri POS file touch by sprint tracks. No collision possible." The deltas log at `apps/erp/docs/sessions/2026-05-24-tauri-pos-deltas.md:24` reinforces "Wave 1 is zero-Tauri-touch by design — no entry below applies to Wave 1."

**Reality:**
1. **T1 Wave 1, Phase 2** modifies the backend POS service `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php` lines 831-883 (the P1-5 in-transit availability fix). See `apps/erp/docs/superpowers/specs/2026-05-24-t1-stock-transfer.md:44, 173, 256`.
2. **Fiscal Phase 1 Tasks 21/22** simultaneously rewrite the sibling services `ReceiptSyncService.php` (`apps/erp/docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md:1635, 1623`) and `ReceiptPaymentService.php` (`...:1635, 1699`) — relocating their business-effect logic into the new fiscal projectors. The `PosCoreReceiptProjection` at Task 21 swallows the receipt-creation business logic that T1 wants to extend.
3. **T2 Wave 1** owns `variant_id` column additions to `pos_receipt_lines`, `receipt_line_batch_allocations`, and `payments` (`apps/erp/docs/superpowers/specs/2026-05-24-t2-variants.md:90-91, 285`). Fiscal Phase 1 Tasks 11/12 add columns to the same tables (`pos_receipts.fiscal_event_id`, `pos_receipts.canonical_bytes`, `payments.origin`, `payments.fiscal_event_id`) via `apps/erp/docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md:852-895, 906-947`.
4. **Migration directory collision**: Fiscal Phase 1 has already committed 3 migrations at `apps/api/database/migrations/2026_05_14_100001_create_fiscal_events_table.php`, `..._100002_..._immutability.php`, `..._100003_..._projections_table.php`, with Tasks 11 / 12 / and others still pending. T6 Phase 0 says to **move tenant migrations into `database/migrations/tenant/`** — but the roadmap nowhere addresses how the still-pending fiscal Phase 1 migrations get reclassified, nor whether T6 Phase 0 includes those already-shipped 3 fiscal migrations in its move set, nor what the fiscal session does for new migrations after T6 Phase 0 lands. (The topology contract at line 79 acknowledges `fiscal_events, device_*` as "in flight" but does not specify the handover protocol.)
5. The Treasury module — `apps/api/app/Modules/Treasury/Domain/Payment.php` — is on both fiscal Phase 1's modify list (Task 12) and indirectly on T2's path (because `payments` table FK on `fiscal_events` lands as `variant_id` will too).

**Impact:** Two engineers (one on Wave 1 T1/T2, one on fiscal Phase 1) opening these files simultaneously will collide on every Phase 1 task that still needs to ship (11, 12, 21, 22, 23, plus the route-deletion tasks). The deltas log handshake protocol only covers Tauri-side; the backend-POS handshake is undefined. The "no collision possible" assertion is symmetrically false on the server side — it's the same defect class as round-1 B-4, just relocated.

**Recommended fix:**
1. Add to roadmap a separate **server-side fiscal handshake** section parallel to Wave 2's Tauri handshake. Any Wave 1 backend-POS file change (T1 P1-5 fix on `ReceiptCreationService.php`; T2 variant_id on POS tables) goes through fiscal session triage **first**, not after.
2. Add to T6 Phase 0 acceptance criteria an explicit step: "all in-flight fiscal Phase 1 migrations (`2026_05_14_100001/100002/100003` and any committed Tasks 11/12 increments) are moved into `database/migrations/tenant/` as part of the Phase 0 PR; the fiscal session is paused during the Phase 0 PR window; after Phase 0 lands, the fiscal session resumes with new migrations targeting `database/migrations/tenant/` directly."
3. Update topology-contract line 79 entry for `fiscal_events, device_*` to specify the handover protocol explicitly.
4. The deltas log "From T1" section omits a row for `ReceiptCreationService.php` server-side touch (the spec calls it "Server-only — not actually a Tauri delta" at deltas-log line 36, but then routes it through the fiscal triage column anyway; the routing is correct, the framing as "not a delta" undersells it — call it a server-side fiscal coordination item explicitly).

### P1

**[R2-P1-1] Tauri deltas log handshake protocol leaves fiscal session ownership unresolved in a way that blocks Wave 2 from starting**

**Claim:** `apps/erp/docs/sessions/2026-05-24-tauri-pos-deltas.md:14`: "Sprint track lead notifies fiscal session owner (default assumption: **Houssam personally**, pending confirmation) via Telegram/Adam OR direct message." Roadmap mirrors at `apps/erp/docs/superpowers/coordination/2026-05-24-productization-sprint-roadmap.md:130, 211-213`.

**Reality:** "Pending confirmation" + "default assumption: Houssam personally" + "while running autonomously" creates an unactioned ownership gap. Memory (`project_pos_fiscal_event_engine.md` referenced in the system context) describes the fiscal session as in flight with a sign-off gate; multiple Phase 1 commits since 2026-05-14 (latest at `Phase 4.1.9: Add fiscal integrity policy defaults` per `git log`) show the session continues to advance autonomously. The protocol's "1 working day" SLA on triage cannot be satisfied if the owner is on vacation (which the roadmap itself notes — `roadmap:5` "one vacation week in the middle"). There is no escalation path defined. There is no "if the fiscal session owner is unavailable, what does the sprint track lead do?" answer.

**Impact:** Wave 2 cannot start without explicit fiscal sign-off (`roadmap:134, deltas:22`). If the owner is unreachable, Wave 2 stalls or — worse — track leads improvise and merge POS deltas without coordination.

**Recommended fix:**
- Make ownership unambiguous in the document, not deferred: pick one of (a) Houssam is the sole owner; in his absence Wave 2 is blocked and explicit; (b) Houssam delegates triage authority to a named alternate while away; (c) sprint track leads can self-triage with a documented escalation if no response in N hours.
- Document the vacation-week protocol explicitly. Either (a) Wave 2 work is sequenced AFTER the vacation week, or (b) a triage alternate is named for the vacation window.
- Specify the notification channel concretely: not "Telegram/Adam OR direct message" — pick one as canonical and one as fallback.
- Add an SLA-breach behavior to the protocol (what happens at 24h, 48h, 72h with no triage).

**[R2-P1-2] T6 Phase 0 "until merged, no other track writes a new migration" rule excludes fiscal Phase 1 but does not say so**

**Claim:** `apps/erp/docs/superpowers/coordination/2026-05-24-productization-sprint-roadmap.md:51`: "Until this gate is merged, NO other track writes a new migration." `apps/erp/docs/superpowers/coordination/2026-05-24-migration-topology-contract.md:109`: "No other track in the sprint may write a new migration until T6 Phase 0 is merged. Tracks may write spec drafts and start non-migration work in parallel."

**Reality:** Fiscal Phase 1 is in flight, ships migrations regularly, and is not a "sprint track" in the v2 sense. The rule is silent on whether fiscal Phase 1 is exempt, paused, or expected to coordinate. Git log shows fiscal commits continuing autonomously as recently as today (2026-05-24).

**Impact:** Either fiscal Phase 1 violates the migration rule unknowingly, or the sprint tracks block on Phase 0 while fiscal continues to ship migrations that Phase 0 will then have to move retroactively — a higher-conflict pattern than coordinating once.

**Recommended fix:** Add to the roadmap "Phase 0" section a paragraph clarifying:
- Whether fiscal Phase 1 is paused during the Phase 0 PR window
- Whether fiscal Phase 1's existing migrations are part of the Phase 0 move set
- Whether post-Phase-0, fiscal Phase 1 migrations target `tenant/` directly (yes, they should — `fiscal_events` is per-tenant per topology contract line 79)

**[R2-P1-3] Effort estimate for T6 in roadmap table inconsistent with spec when read literally**

**Claim:** `apps/erp/docs/superpowers/coordination/2026-05-24-productization-sprint-roadmap.md:95, 101`: roadmap shows two separate T6 rows: "T6 Phase 0 ... ~3 PD" and "T6 ops ... ~7 PD". Line 104: "Sprint total: ~61 PD across 7 tracks". But line 91 header says "The 7 specs (after v2 restructure)" — the table has 8 rows because T6 is split.

**Reality:** Total sums correctly to 61 PD (3 + 12 + 16 + 6 + 9 + 5 + 7 + 3 = 61). The header "7 specs" is accurate because T6 is one spec file. But the table reads as 8 items, and casual readers will assume 8 tracks.

**Impact:** Minor confusion that compounds with the "parallel execution" framing — readers may schedule 8 sessions instead of 7 + 1 sequenced gate.

**Recommended fix:** Either (a) restate header as "The 7 specs (T6 listed as two phases for sequencing)" or (b) merge T6 row in the table with parenthetical phase breakdown.

### P2

**[R2-P2-1] Wave 1 / Wave 2 split for T1 in the table is ambiguous — "1 server / 2 POS deltas" reads as a wave straddle**

**Claim:** `roadmap:96-97`: T1 row shows Wave column as "1 server / 2 POS deltas". T2 row identically. T4 row says "1 (Phases 1-4) + sequenced (Phase 5)".

**Reality:** This is information-rich but the visual form is inconsistent. T4's phase-explicit form is the clearer pattern.

**Impact:** A track lead reading the table picks up "T1 = Wave 1 thing" and may schedule the Tauri delta work into their Wave 1 session.

**Recommended fix:** Standardize the format: "Wave 1: Phases 1-4 (server). Wave 2: Phases 5+ (POS deltas, fiscal-coordinated)" for T1 and T2. Match T4's pattern.

**[R2-P2-2] Deltas log T1-D3 entry contradicts itself**

**Claim:** `apps/erp/docs/sessions/2026-05-24-tauri-pos-deltas.md:36`: "T1-D3 | Server-side enforcement: `ReceiptCreationService` ... | Server-only — not actually a Tauri delta; flagged here for visibility".

**Reality:** Per R2-B1 above, this IS the collision point with fiscal Phase 1. Marking it "not actually a Tauri delta" while listing it in the Tauri deltas table sends mixed signals to readers — is this in scope for the protocol or not? If the protocol covers Tauri-only, T1-D3 doesn't belong here; if T1-D3 belongs here, the protocol covers backend-POS too, in which case the protocol section header / scope must say so.

**Impact:** Track leads will either ignore T1-D3 because "not Tauri" or follow the Tauri handshake protocol for a backend change that has a different ownership map.

**Recommended fix:** Either rename the file to "POS coordination log" and explicitly extend protocol to backend-POS changes (preferred — it absorbs the R2-B1 fix), or remove T1-D3 from this file and put it in a separate backend-POS-fiscal coordination section in the roadmap.

### SUGGESTION

**[R2-S1]** The roadmap's "Coordination with in-flight POS fiscal Phase 1" section (`roadmap:120-134`) should cross-link to the fiscal plan's revision history and current task state, so a reader knows fiscal Phase 1 is currently at Task ~21+ (per `git log` Phase 1.33.x and Phase 4.x commits), not at the start. The default mental model of "fiscal Phase 1 is starting / in early phase" is wrong and affects coordination decisions.

**[R2-S2]** The deltas log "Done deltas" section could include a forward-link to where merged delta PRs live so future readers can audit what shipped. Currently `_(none yet)_` is fine; just add a note "PR links will be added under each Done entry as it completes".

**[R2-S3]** Consider adding a "Wave 0" label to T6 Phase 0 in the table column. Currently it shows "Pre-sprint" which is correct but inconsistent with the "Wave 1 / Wave 2" lexicon used everywhere else.

## Round-1 finding resolution check

**B-3 (T6's DB-per-tenant migration plan collides with every tenant-scoped migration in the sprint)** — **RESOLVED.** v2 restructures T6 Phase 0 as the explicit pre-sprint gate (`roadmap:38-52`); the migration topology contract (`coordination/2026-05-24-migration-topology-contract.md`) is published as a constitutional document; every spec defers to it ("Migration placement: all T1/T2/T3/T4/T5/T6 migrations → `database/migrations/tenant/` per topology contract" — verified in each spec). The "no other track writes a new migration until T6 Phase 0 merges" rule is in place.

**Caveat:** the rule does not explicitly address fiscal Phase 1 (R2-P1-2 above). This is a P1, not a B-3 regression.

**B-4 (Roadmap says no POS fiscal conflicts, but sprint changes the exact surfaces fiscal Phase 1 is rebuilding)** — **PARTIALLY RESOLVED.** v2 introduces the Tauri deltas-log handshake protocol (`deltas:8-22`) and the Wave 1 / Wave 2 split, which correctly handles the Tauri-side collision. However, the same defect class persists on the server side (R2-B1 above): Wave 1 backend POS service work and Wave 1 column additions to POS-fiscal-touching tables are not handshaked. The "no collision" framing was relocated from Tauri to server side rather than fully eliminated.

**B-5 (T4 dependency on T3 not surfaced)** — **RESOLVED.** v2 explicitly carves T4 into Phases 1-4 (Wave 1 independent) + Phase 5 (sequenced after T3 Phase 2 ChannelOrder model lands). See `roadmap:99` table row "T4 ... 1 (Phases 1-4) + sequenced (Phase 5)"; `roadmap:166-167` "T4 Phase 5 (channel-order integration) blocks on T3 Phase 1 (framework + ChannelOrder model)"; T4 spec at `specs/2026-05-24-t4-order-routing.md:275, 284`. Clean.

## Wave 1 / Wave 2 split verification

For each Wave 1 track, confirming whether ZERO Tauri POS file paths are touched, cross-checked against the fiscal Phase 1 plan for collision risk:

| Wave 1 track | Tauri POS files touched? | Backend POS module files touched? | Fiscal Phase 1 collision? |
|---|---|---|---|
| **T3-infra (Sync Hub)** | No (verified — spec says "No POS touch this sprint" at `t3-sync-hub.md:203`) | No (new module `app/Modules/Channel/`) | None |
| **T4 (Phases 1-4)** | No (verified — `t4-order-routing.md:162-164` "No new POS screens") | No (new module `app/Modules/OrderRouting/`) | None |
| **T5 (Reporting)** | No (verified — `t5-reporting.md:262` "No Tauri POS changes") | No (new module/feature `app/Modules/Reports/` + frontend) | None |
| **T11 design** | No (design-only, no implementation this sprint per `t11-b2b-b2c-separation.md:5, 152`) | No (design-only) | None |
| **T1 server (Phases 1-4)** | No Tauri ✓ | **YES — `ReceiptCreationService.php` lines 831-883** per `t1-stock-transfer.md:44, 173` | **YES** — same module as fiscal Phase 1 Tasks 21/22 rewriting sibling services |
| **T2 server (Phases 1-3)** | No Tauri ✓ | **YES — adds variant_id columns to `pos_receipt_lines`, `receipt_line_batch_allocations`** per `t2-variants.md:90-91, 285` | **YES** — fiscal Phase 1 Tasks 11/12 also modify these tables (`pos_receipts.fiscal_event_id`, `payments.origin`) |
| **T6 ops (Phase 1+)** | No ✓ | No (central tenants table + new services) | Indirect — uses pgsql_direct connection that may affect fiscal verify-chain command behavior, but topology contract already covers it |

**Tauri-side verdict:** Wave 1 is indeed zero-Tauri-touch as claimed. ✓

**Backend-POS verdict:** Wave 1 has **two real collisions** with fiscal Phase 1 (T1 Phase 2 + T2 Phases 1-2) that the roadmap does not surface. See R2-B1.

## Tauri handshake protocol clarity

The protocol at `deltas:8-22` is concrete on **what** to do (notify, triage, schedule, ship, close) and **what column to fill** (Files touched, Urgency, Dependencies). It is **insufficiently concrete on who and when**:

| Protocol question | Current answer | Sufficient? |
|---|---|---|
| Who is the fiscal session owner? | "default assumption: Houssam personally, pending confirmation" | **No** — must be resolved before Wave 2 can start |
| What channel does the notification use? | "Telegram/Adam OR direct message" | **No** — need one canonical, one fallback |
| What is the triage SLA? | "1 working day" | OK in isolation; not OK when owner is on vacation week per roadmap line 5 |
| What happens if owner is unavailable? | Not specified | **No** — escalation path missing |
| Does the protocol cover backend-POS changes, or Tauri only? | Ambiguous — title says Tauri, but T1-D3 (server-only) appears in the table | **No** — define scope explicitly (and see R2-B1) |
| Once a delta is "Scheduled", who tracks slippage? | Not specified | OK — implicit via the status column |

The fiscal session ownership question is **load-bearing**: if it's not Houssam personally, the entire "Houssam owns it, log to deltas, triage on return" mental model collapses. The roadmap's open question at line 211-213 acknowledges this but defers it — that deferral itself is the gap.

## Effort sum check

Roadmap claim: "**~61 PD across 7 tracks** (down from 68 — WC implementation deferred)" — `roadmap:104`.

Spec-by-spec effort estimates:

| Spec | Effort claimed | Verified from spec |
|---|---|---|
| T6 Phase 0 | 3 PD | `t6-tenant-provisioning.md:53, 253` ✓ |
| T6 ops | 7 PD | `t6-tenant-provisioning.md:98, 255-259` (3+3+1 = 7) ✓ |
| T1 | 12 PD | `t1-stock-transfer.md:6` ✓; phase breakdown 2+3+5+2 = 12 ✓ |
| T2 | 16 PD | `t2-variants.md:6` ✓; phase breakdown 3+5+5+3 = 16 ✓ |
| T3 | 6 PD | `t3-sync-hub.md:6` ✓; phase breakdown 1+2+1+1.5+0.5 = 6 ✓ |
| T4 | 9 PD | `t4-order-routing.md:6` ✓; phase breakdown 2+3+2+1.5+0.5 = 9 ✓ |
| T5 | 5 PD | `t5-reporting.md:6` ✓; phase breakdown 2+2+1 = 5 ✓ |
| T11 design | 3 PD | `t11-b2b-b2c-separation.md:6` ✓ |
| **Sum** | **61 PD** | **3 + 7 + 12 + 16 + 6 + 9 + 5 + 3 = 61** ✓ |

**Sum is exact.** Each spec's internal phase breakdown also sums to its top-line estimate. No discrepancy.

## Spec-roadmap consistency

For each spec, verifying the roadmap table entry matches the spec content (workflow recommendation, effort, dependencies, wave assignment):

| Spec | Roadmap workflow | Spec workflow | Roadmap deps | Spec deps | Match? |
|---|---|---|---|---|---|
| T6 Phase 0 | "Codex + Opus review" | "Codex executes mechanically; Opus reviews migration classification correctness" (`t6:253`) | None (gate) | None (gate) | ✓ |
| T1 | "Opus (Scenario B) + Codex (Scenario A + migrations)" | "Opus for Scenario B + Codex for Scenario A mechanics + per-location tax_id migration" (`t1:5`) | T6 Phase 0; references T2 variant_id | T6 Phase 0; references T2 (`t1:302-304`) | ✓ |
| T2 | "Opus (schema + backward compat) → Codex (mechanical)" | "Opus for schema design + backward-compat reasoning + nullable-variant_id unique-constraint strategy. Codex for mechanical service updates + admin matrix UI" (`t2:5`) | T6 Phase 0 | T6 Phase 0 (`t2:284`) | ✓ |
| T3 | "Opus (interface) + Codex (framework + UI)" | "Opus for `ChannelAdapter` interface + DTOs + outbound/inbound framework design. Codex for domain entities + repositories + admin UI + reconciliation framework" (`t3:5`) | None for Phase 1-4 | T6 Phase 0; hands off to T4 (`t3:327-330`) | ✓ |
| T4 | "Opus (rule semantics + strategy architecture) + Codex (CRUD + UI)" | "Opus for ScoringStrategy interface + RuleEvaluator + OrderRoutingService semantics. Codex for zone CRUD + scoring strategy implementations + admin UI" (`t4:5`) | T6 Phase 0; Phase 5 needs T3 Phase 2 | T6 Phase 0; Phase 5 needs T3 (`t4:283-284`) | ✓ |
| T5 | "Codex (mirroring established patterns)" | "Codex throughout" (`t5:5`) | T6 Phase 0 (if views) | T6 Phase 0 if views (`t5:259`) | ✓ |
| T6 ops | "Codex" | "Codex throughout for migration moves + config flip + pre-warm pool jobs + backup automation. Opus design review on Stancl migration topology and pre-warm race safety" (`t6:5`) | After Phase 0 | After Phase 0 (`t6:267`) | ✓ (roadmap slightly undersells Opus design role) |
| T11 | "Opus" | "Opus produces the design" (`t11:5`) | None for design-only | None for design (`t11:228`) | ✓ |

**All 7 specs match the roadmap table on workflow, effort, and dependencies.** Roadmap-spec consistency is strong.

The one minor gloss: roadmap's T6 ops "Codex" misses the "Opus design review on Stancl migration topology and pre-warm race safety" element of the spec. Not material; design-review tooling is implicit in the spec's adversarial-review pattern.

## What's solid

1. **The migration topology contract is genuinely excellent.** The "constitutional" framing is appropriate; the central-vs-tenant classification table is comprehensive; the Pattern A/B/C/D cross-DB-FK rules give implementers actionable patterns; the partial-unique-index strategy for nullable variant_id columns is technically correct for PostgreSQL.

2. **The two-wave model with explicit Phase 0 gate is the right shape.** Round-1 B-3 forced this restructure and it landed cleanly. The "no migrations until Phase 0 merges" rule is the right blocking constraint.

3. **T4 dependency on T3 is now explicit and correctly carved.** Phases 1-4 parallel, Phase 5 sequenced. The spec, roadmap, and topology contract all agree.

4. **Effort sums are exact and internally consistent.** Per-spec phase breakdowns sum to their top-line estimate; the 61 PD total checks out. This is rare in coordination docs and worth calling out.

5. **Every Round-1 BLOCKER's text-level fix landed in v2** (paths corrected per B-1/B-2/P1-6/P2-2; T6 sequencing per B-3; T4 dep per B-5; deferred WC adapters per P1-2; existence-of-ECharts per P1-1; pricing-resolver order per P1-4; CompositeItemVariant caveat per P1-7). The remaining issue isn't that round-1 findings were ignored — it's that B-4's resolution stopped at Tauri-side without extending to backend-POS-side.

6. **Adversarial review discipline is baked in.** Every spec ends with a "verify findings against actual code at cited paths" instruction; the asymmetric tool rule (Codex implemented → Opus reviews, Opus implemented → Codex reviews) is consistent across specs.
