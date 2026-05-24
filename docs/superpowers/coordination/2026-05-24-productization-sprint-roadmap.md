# Productization Sprint Roadmap — Source of Truth (v2)

**Date:** 2026-05-24
**Author:** Houssam + Claude (Opus 4.7) — revised after Codex adversarial review round 1
**Sprint window:** target <10 calendar days, parallel Opus + Codex sessions
**Forcing function:** Nénupharma parapharmacy lead (3 shops, mixed Tunis + Sousse, + 1 warehouse). Features develop regardless — productize the platform.
**Framing:** Productize the platform. Client #1 is the forcing function, not the scope limit. Every track ships generic, gated, reusable across all SaaS tenants.

---

## Why this is v2

A round-1 Codex adversarial review (`apps/erp/docs/superpowers/reviews/2026-05-24-sprint-design-codex-review.md`) found 5 BLOCKERs, 7 P1s, and 6 missing concerns in the original roadmap. The biggest issues:

- T6 was wrongly scoped as a parallel side-track; it's actually a **pre-sprint gate** that owns migration topology
- T3 was anchored on WooCommerce despite the client's platform being unknown; **concrete adapters are deferred to a future sprint**
- POS-touching tracks (T1, T2, T11) collide with in-flight fiscal Phase 1 work; need explicit fiscal session coordination
- T4 has a real dependency on T3 (consumes `ChannelOrder`) that wasn't called out
- Multiple specs cited wrong file paths or invented patterns that don't exist in the codebase

v2 restructures around two waves separated by the fiscal collision boundary, and treats T3 as shared infrastructure only.

---

## Context

- See [v1 handoff](/Users/houssamr/Projects/adam/workspace/plans/erp-sprint-handoff.md) — Adam's original draft, preserved as historical reference
- See [round-1 Codex review](../reviews/2026-05-24-sprint-design-codex-review.md) — the source of v2's restructure
- See [migration topology contract](2026-05-24-migration-topology-contract.md) — constitutional document for the sprint
- See [internal strategy PDF](/Users/houssamr/Downloads/Synerivia_ParaPharmacy_Lead_Strategy_2026-05-20.pdf) for commercial framing
- See [client estimate DOCX](/Users/houssamr/Downloads/Synerivia_Estimation_Parapharmacie_Sousse_2026-05-23.docx) for what's been shared with the client

---

## Sprint structure: Phase 0 gate + two waves

```
─────────────────────────────────────────────────────────────────────
PHASE 0 — PRE-SPRINT GATE (T6 Phase 0)            ~10 PD (~5 working days, Codex+Opus pair)
─────────────────────────────────────────────────────────────────────
  Owner: Codex with Opus design review
  Deliverables:
    1. Migration topology contract published (DONE)
    2. database/migrations/tenant/ directory created
    3. Tenant migrations moved into tenant/
    4. tenancy.php flipped to PostgreSQLDatabaseManager
    5. All existing tests still pass against flipped config
    6. Stancl flip integration test added
    7. Gate-complete marker doc at coordination/2026-05-24-t6-phase0-gate-complete.md

  Until this gate is merged, NO other track writes a new migration.

─────────────────────────────────────────────────────────────────────
WAVE 1 — Server-side work, ZERO Tauri POS touch    ~5-8 days
─────────────────────────────────────────────────────────────────────
  Tauri client = untouched. Backend POS module changes require fiscal
  handshake (T1-S1 + T2-S1 in the coordination log). Plain server work
  (T3 / T4 / T5 / T11 / T6 ops) parallelizes freely.

  T3-infra | T4 | T5 | T11-design | T1-server (handshake on POS file) | T2-server (handshake on POS migrations) | T6 ops

  Run as separate Opus/Codex sessions. Daily merge to dev.
  Each track's adversarial review at end of its work (chunked, headless).

─────────────────────────────────────────────────────────────────────
WAVE 2 — POS-touching, sequenced with fiscal session  ~3-5 days
─────────────────────────────────────────────────────────────────────
  Each item requires fiscal handshake. Items enter Tauri deltas log
  with explicit ownership + sequence.

  Items:
    • T1 receipt template surfacing location.tax_id + branch_code
    • T1 InTransitAvailability POS rendering + offline-mode behavior
    • T2 POS variant picker modal + barcode resolution + cart display
    • T2 SQLite migration for variant_id on receipt lines + cache

  Continued T6 ops (pre-warm pool, backup automation) runs in
  parallel since it doesn't touch POS.

─────────────────────────────────────────────────────────────────────
DEFERRED TO FUTURE SPRINT (separate planning cycle)
─────────────────────────────────────────────────────────────────────
  • Concrete channel adapters (WooCommerce / PrestaShop / Shopify /
    Paradeals) — chosen after Nénupharma confirms platform
  • T11 implementation tracks (T11-impl-A, B, C)
  • Wholesale Sub-Vertical (T9)
  • Field Sales / Van Sales (T10)
  • Paradeals Aggregator service (T7)
```

---

## The 7 specs (after v2 restructure)

| # | Track | Spec file | Wave | Effort | Recommended workflow |
|---|---|---|---|---|---|
| **T6 Phase 0** | Migration topology gate (Stancl flip + 50+ FK rewrites + central conn + reference-data seeding + AuthController rewrite + flip test) | [2026-05-24-t6-tenant-provisioning.md](../specs/2026-05-24-t6-tenant-provisioning.md) | **Pre-sprint** | ~10 PD | Codex + Opus review |
| **T1** | Stock Transfer (Scenarios A + B + per-location tax_id + batch preservation via inventory_batch_movements) | [2026-05-24-t1-stock-transfer.md](../specs/2026-05-24-t1-stock-transfer.md) | 1 server / 2 POS deltas | ~12 PD | Opus (Scenario B) + Codex (Scenario A + migrations) |
| **T2** | Product Variants Module (data model + service + admin matrix + WC mapping deferred + POS picker delta) | [2026-05-24-t2-variants.md](../specs/2026-05-24-t2-variants.md) | 1 server / 2 POS deltas | ~16 PD | Opus (schema + backward compat) → Codex (mechanical) |
| **T3** | Multi-channel Sync Hub — **shared infrastructure only** (interface + framework + admin UI + reconciliation + credentials; NO concrete adapters) | [2026-05-24-t3-sync-hub.md](../specs/2026-05-24-t3-sync-hub.md) | 1 | ~6 PD | Opus (interface) + Codex (framework + UI) |
| **T4** | Order Routing Engine (zone taxonomy + rule engine + scoring + admin UI). **Channel-order integration deferred** until T3 framework merges. | [2026-05-24-t4-order-routing.md](../specs/2026-05-24-t4-order-routing.md) | 1 (Phases 1-4) + sequenced (Phase 5) | ~9 PD | Opus (rule semantics + strategy architecture) + Codex (CRUD + UI) |
| **T5** | Owner Reporting MVP (multi-location + multi-company dimensions + 6 KPI charts as first-chart-surface mirroring POS analytics patterns) | [2026-05-24-t5-reporting.md](../specs/2026-05-24-t5-reporting.md) | 1 | ~5 PD | Codex (mirroring established patterns) |
| **T6 ops** | Pre-warm pool + backup automation + restore drill + monitoring | [2026-05-24-t6-tenant-provisioning.md](../specs/2026-05-24-t6-tenant-provisioning.md) | 1 (parallel after Phase 0) | ~7 PD | Codex |
| **T11** | B2B/B2C Clean Separation (DESIGN ONLY this sprint) | [2026-05-24-t11-b2b-b2c-separation.md](../specs/2026-05-24-t11-b2b-b2c-separation.md) | 1 | ~3 PD | Opus |

**Sprint total:** ~61 PD across 7 tracks (down from 68 — WC implementation deferred).

---

## Deferred (designed-for-extension only)

| Track | Why deferred | Designed-for in which sprint spec |
|---|---|---|
| **Concrete channel adapters** (WC / PrestaShop / Shopify / Paradeals) | Client platform unknown; build only what client uses | T3 ships the `ChannelAdapter` port; future sprint adds adapter classes |
| **T7** Paradeals Aggregator (cross-tenant marketplace) | Platform play, not client-meeting blocker | T3 publication adapter design absorbed into the generic framework |
| **T8** Pricing Engine v2 | Existing `PricingService::getPrice()` handles partner + default-price-list paths | T11 design references the existing path; channel-override hook stubbed |
| **T9** Wholesale Sub-Vertical | Strategic; build after retail stable | T1 + T2 + T3 + T11 lay groundwork; gated via existing module-gating |
| **T10** Field Sales / Van Sales | Truck already modeled as `LocationType::Mobile` | No sprint work; future module consumes catalog/pricing/stock APIs |

---

## Coordination with in-flight POS fiscal Phase 1

`apps/erp/docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md` is in flight (currently at ~Task 21+, NOT at the start). The fiscal plan actively rewrites Tauri files (`apps/pos/src/lib/offline/receiptService.ts`, `offlineCheckoutService.ts`, `receiptApi.ts`, `syncService.ts`, device SQLite migration set) AND server-side files (`ReceiptSyncService.php`, `ReceiptPaymentService.php`, `pos_receipts`/`payments` migrations).

**Wave 1 = zero Tauri POS file touch** by sprint tracks. Tauri-side collision is impossible.

**BUT Wave 1 has real backend-POS collisions (per round-2 R2-B1):**
- T1 Phase 2 modifies `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php` lines 831-883 (the in-transit availability fix) — fiscal Phase 1 Tasks 21/22 simultaneously rewrite sibling services and the `PosCoreReceiptProjection` swallows logic adjacent to that area
- T2 Wave 1 adds `variant_id` to `pos_receipt_lines`, `pos_receipt_line_batch_allocations` (corrected per round-3 P1-1: T2 does NOT touch `payments` — that column ownership belongs entirely to fiscal Phase 1 Task 12 which adds `payments.origin` + `payments.fiscal_event_id`). Tables T2 touches still overlap with fiscal Phase 1's POS-receipt area; coordination still required.

**The two collision classes (Tauri + backend-POS) both go through the unified coordination protocol** documented in `apps/erp/docs/superpowers/coordination/2026-05-24-pos-coordination-log.md` (file renamed from `-tauri-pos-deltas.md` to reflect broader scope per round-2 R2-P2-2):

1. Track lead drafts the coordination entry in the unified log
2. When a Codex POS session opens next (dispatched by user), it consumes pending entries
3. Codex POS session implements, marks "In flight" in the log, then "Done — PR #N" when shipped
4. No human-routing handoff needed; the log IS the protocol

**No Wave 2 item AND no backend-POS Wave 1 item ships without explicit fiscal session sign-off.**

**Specifically requires fiscal handshake (Wave 1 backend-POS, per round-2 R2-B1):**
- T1 Phase 2 `ReceiptCreationService.php` modification (in-transit availability)
- T2 Wave 1 migrations adding `variant_id` to `pos_receipt_lines`, `pos_receipt_line_batch_allocations` (NOT `payments` — corrected per round-3 P1-1)

**Fiscal session pause requirement (per T6 Phase 0):** the in-flight fiscal Phase 1 must pause new migration commits during the Phase 0 PR window so T6 Phase 0 can move the 3 already-committed fiscal migrations into `database/migrations/tenant/`. Clean-slate framing per user direction: those 3 migrations can be REGENERATED under the tenant directory rather than physically moved (no data to preserve). After Phase 0 lands, fiscal Phase 1 resumes targeting `tenant/` directly.

**Fiscal session ownership (per user direction):** all sessions are Opus/Codex; user checks every 3-4 hours. No vacation-week escalation or SLA gymnastics needed. Sprint tracks log POS coordination items to the unified log; when a Codex POS session opens next, it picks them up. No human-routing handoff required between sessions.

---

## Workflow conventions

### Per-spec workflow

Each spec has a **Recommended Workflow** section. Default split:

| Work type | Tool | Why |
|---|---|---|
| New architecture / interface design / backward-compat reasoning | Opus | Long context, deeper reasoning |
| Mechanical work (migrations, CRUD, test scaffolding) | Codex | Workhorse, cheaper per token, headless-friendly |
| Adversarial review of small chunks (<1500 LOC) | Codex headless | Fast, contained, low timeout risk |
| Adversarial review of large chunks (>1500 LOC) | **Opus headless** | Codex headless times out on big chunks |

### Adversarial review rules

Every spec includes an **Adversarial Review Checklist** at the bottom. When dispatching:

1. **Mandatory instruction:** *"Verify your findings against the actual code at the paths cited. Read the files. Do not make assumptions — confirm by reading."*
2. **Chunk the review** — split into 2–3 chunks per round when implementation > 1500 LOC
3. **Asymmetric tool:** if Codex implemented → Opus reviews (headless); if Opus implemented → Codex reviews (headless, chunked)
4. **Save to file** — every review writes to `apps/erp/docs/superpowers/reviews/2026-05-XX-<track>-<tool>-review.md`, NOT inline output
5. **Iterate until clean** — fix findings, re-review, repeat

### Parallelization

After T6 Phase 0 merges:
- T1-server, T2-server, T3-infra, T4 (Phases 1-4), T5, T11 design, T6 ops can all start in parallel
- T4 Phase 5 (channel-order integration) blocks on T3 Phase 1 (framework + ChannelOrder model)
- T3 has no concrete adapter implementation; channel adapters are a future sprint

---

## File-path conventions

- **Specs:** `apps/erp/docs/superpowers/specs/2026-05-24-<track-slug>.md`
- **Adversarial reviews:** `apps/erp/docs/superpowers/reviews/2026-05-XX-<track-slug>-<tool>-review.md`
- **Migration topology contract:** `apps/erp/docs/superpowers/coordination/2026-05-24-migration-topology-contract.md`
- **Session handoffs / coordination:** `apps/erp/docs/superpowers/coordination/2026-05-XX-<topic>.md`
- **Tauri POS deltas log:** `apps/erp/docs/superpowers/coordination/2026-05-24-pos-coordination-log.md`
- **Phase 0 gate-complete marker:** `apps/erp/docs/superpowers/coordination/2026-05-24-t6-phase0-gate-complete.md` (created when T6 Phase 0 merges)

---

## Meeting day (~2 weeks)

**Goal:** Capture detailed requirements + lock the go-live date. Features develop regardless — this client is the forcing function.

**Demo must show:**
- Working POS in their store environment (laptop or tablet)
- Product catalog with their actual products imported (if shared ahead of time)
- Multi-location stock view with transfer between locations
- Variant management for orthopedic SKUs (admin matrix editor; POS picker via fiscal-coordinated delta)
- Owner reporting dashboard (sales + stock + KPIs)
- Tenant provisioning UX (the SaaS sign-up flow)
- Channel configuration UX (generic — explain "this is the integration point; we wire to your platform once you tell us which")

**Out of demo scope (acknowledge as roadmap):**
- Live e-commerce channel sync (defer until adapter chosen)
- Paradeals aggregator (platform initiative)
- B2B/B2C cohabitation (design spec ready for next cycle)
- Wholesale, field sales, mobile app

**Confirm with client:**
- Their existing system + data export format
- Exact SKU volume
- Named users per shop
- E-commerce platform preference (WooCommerce / PrestaShop / Shopify / none yet) — drives Phase 3 adapter sprint
- Tunisian payment gateway preference
- Existing website or build new
- 12–24 month expansion plan

---

## Phase prioritization (per user direction)

**Tier A — Start RIGHT NOW, parallel sessions safe:**
- T6 Phase 0 (~10 PD, Codex+Opus pair)
- T2 Variants Phases 1–3 (domain + service + admin UI; migrations branch-dev until Phase 0 merges)
- T3 Sync Hub shared infra (interface + entities + admin UI)
- T4 Order Routing Phases 1–4 (zone taxonomy + rule engine + scoring + UI)
- T5 Owner Reporting MVP (ECharts widgets mirroring POS Analytics)
- T11 design refinement → 3 impl specs (Opus)
- T1 server-side (Scenarios A + B + per-location tax_id + batch preservation fix via inventory_batch_movements)
- Batch management completion (set `requires_batch_tracking` defaults + E2E test)

**Tier B — Sequenced on Tier A delivery:**
- T3 concrete adapters (WC / Shopify / PrestaShop / Paradeals) — needs T3 infra merged + client platform decision
- T4 Phase 5 (channel-order integration) — needs T3 Phase 2 ChannelOrder model
- T11 impl-A/B (customer model + PricingStrategyResolver + DocumentEmissionPolicy) — needs T11 design merged

**Tier C — Wait for fiscal Phase 1 completion, then quick alignment pass:**
- T1 POS deltas (receipt template tax_id, InTransitAvailability rendering, offline behavior)
- T2 POS deltas (variant picker modal, barcode → variant, cart line display, SQLite variant_id migration)
- T11 impl-C (thin-POS B2B hand-off via holdStore extension)
- POS variant integration (extend existing product/composite-item scoping to handle variants — small rework per user assessment)

**Estimated end-to-end:** ~10 calendar days for Tiers A+B with parallel Opus + Codex sessions. Tier C bounded to ~3 PD when fiscal Phase 1 completes.

---

## How to update this roadmap

Living document. As specs evolve, update the table at the top. When a spec ships, mark it ✅ and link the merged PR. When an adversarial review completes, link the review file. Roadmap = single map; specs = territory.
