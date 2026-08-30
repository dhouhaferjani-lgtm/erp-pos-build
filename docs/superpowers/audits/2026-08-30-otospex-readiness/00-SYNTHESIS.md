# Otospex first-customer readiness + AI sourcing agent — orchestrator synthesis

**Date:** 2026-08-30 · **Orchestrator:** Fable (this file) · **Evidence:** five read-only Opus audits in this folder (`01`–`05`), each claim there carries `path:line`. Nothing in the repo was modified. Two headline defects (VAT-on-VAT, reservation leak) were re-verified by the orchestrator directly in code, and the whole page was then adversarially fact-checked ([`06-synthesis-verification.md`](06-synthesis-verification.md)); its corrections are applied below and marked **[06]**.

| Report | Question it answers |
|---|---|
| [`01-workshop-job-to-cash.md`](01-workshop-job-to-cash.md) | Does the garage flow (customer → vehicle → appointment → work order → invoice → payment) work? |
| [`02-onboarding-journey.md`](02-onboarding-journey.md) | Can a mechanic tenant be provisioned, seeded, imported, rehearsed and supported today? |
| [`03-rfq-sourcing-current-state.md`](03-rfq-sourcing-current-state.md) | What already exists for RFQ / supplier channels / quote extraction / part identity / LLM plumbing? |
| [`04-platform-seam-and-plumbing.md`](04-platform-seam-and-plumbing.md) | Where does a sourcing service sit in the Synerivia platform, what can it reuse, what ERP endpoints are missing? |
| [`05-rfq-agent-research.md`](05-rfq-agent-research.md) | External research: prior art, channel feasibility (WhatsApp/email/SMS), extraction, part identity, optimiser, agent architecture, phased roadmap |

---

## 1. The one-paragraph answer

**The Otospex product is more ready than its delivery path.** The garage domain model is real and well built — vehicles, scheduling, an 11-state work-order machine with approvals and time tracking, bundles, full FR/AR translation on the workshop namespaces, a working garage demo seeder (`DemoTenantSeeder.php:150`) — and the `mechanic` vertical is first-class end to end (`verticals.php:21-33` → `CompanyConfigService.php:71` → `RequireModule.php:62` → `components/organisms/Sidebar/Sidebar.tsx:318-327`). Intake-through-job-completion scores **4/5**. But **no automated journey has ever run this vertical** (the promotion-gate campaign is hard-wired to parapharmacy), and in that blind spot the *billing tail* acquired three silent-wrong-number defects: a **gross amount stored as the net `line_total`** on every WO-generated invoice (the printed header total is right, but the GL revenue/VAT legs, the VAT-declaration base and the printed line column are wrong — **[06]**), **parts that never leave stock** (no COGS), and **stock reservations that never release** on completed jobs. Around the product, **no Otospex build is deployed anywhere** (product is a compile-time `VITE_APP_PRODUCT`, every target defaults to `izipos`), and the vertical-agnostic launch blockers that already gate the parapharmacy tenant — **no production environment**, P0-2 registration timeout, the products-import G-4 path (landed on `origin/dev` today, staging-unverified), CI blind since 2026-08-21 — gate a garage too. **The AI sourcing agent is not greenfield**: the ERP already ships a buyer-initiated multi-supplier RFQ document type with a comparison grid and award-to-PO, but *both ends are manual* (`send` only stamps a timestamp; quotes are retyped), award is **winner-takes-all** (mix-and-match is structurally unrepresentable), and there is **no messaging transport, no inbound capture, no supplier channel model, no machine credential and no MCP** anywhere in the monorepo. The orchestrator's own estimate — not a figure from the reports — is that roughly a fifth of the sourcing vision exists.

**Readiness verdicts**

| Area | Score | Verdict |
|---|---|---|
| Garage intake → job done (vehicles, scheduling, WO, bundles, i18n) | 4 / 5 | Demo-able today on the demo tenant |
| Estimate → invoice → payment (the money tail) | 2 / 5 | **Wrong numbers silently** in GL/VAT/print — must not reach a customer as-is |
| Printing (invoice PDF, job card) | 1 / 5 | Plate never prints, no job card, TN timbre hidden (`01` §12) |
| Provisioning + module gating for `mechanic` | 3 / 5 | Provisioning works without any step (`02`); two sold extras are inert (`01` §12 scores this 3) |
| Otospex deployment target | 0 / 5 | Does not exist |
| Data migration in (customers/parts ✓, **vehicles ✗**, services ✗) | 2 / 5 | Vehicle book has no import path |
| Rehearsal / promotion gate for a garage | 0 / 5 | Campaign is parapharmacy-only |
| Production hosting for a paying customer | 0 / 5 | Owner-gated (AX42-1 unordered, design unapproved, backups unbuilt) |
| RFQ engine (manual) | 3 / 5 | Shipped, manual both ends, single-winner |
| Sourcing agent (channels, extraction, split, MCP) | 0–1 / 5 | Only reusable parts: extraction service (dark in compose), `data-acquisition` tool loop, `article_cross_references` |

---

## 2. What blocks a garage going live — ordered

Sizes: **S** ≤ 1 day · **M** 2–5 days · **L** > 1 week. Otospex-specific items are engineering-gated; the production-environment items are owner-gated and are the longer pole.

### 2a. Money-path defects (fix before any invoice reaches a customer) — all in `01`

| # | Defect | Sev | Size | Evidence |
|---|---|---|---|---|
| A1 | **Gross stored as net `line_total` ("VAT on VAT").** WO→invoice writes the tax-inclusive line amount into the *net* `line_total`. **[06] corrected mechanism:** the invoice *header* totals are recomputed from `qty × unit_price − discount` (`DocumentTotalsCalculator.php:43`, `TaxCalculationService.php:151,364`) and never read `line_total`, so the printed total is right. The corrupted value bites downstream: GL revenue + VAT legs are computed on a TTC base (`AccountingService.php:649-654,1940-1944`), the residual can go negative and refuse posting (`:383`), the VAT-declaration base is wrong (`PostedLineTaxSnapshotBuilder.php:145-181`), the printed line column is wrong (`line_items.blade.php:68`), and the quote→invoice conversion chain copies it (`CopiesDocumentData.php:142,307-317`). Ticketed CRITICAL 2026-08-07, still open. | P0 | S fix / M with regression test + **GL + VAT-snapshot legacy sweep** | `Workshop/WorkOrder/Infrastructure/Adapters/DocumentGenerationAdapter.php:218` (`'line_total' => $wol->line_total_incl_tax`) — **re-verified by orchestrator**; blast radius per `06` |
| A2 | **Reservations leak.** Reserved on `Approved`, released only on `Cancelled`; `Invoiced`/`Completed` neither release nor consume; no expiry; adapter has zero tests. Available stock falls monotonically. *New, unticketed.* | P0 | S | `WorkOrderTransitionService.php:116-118` — **re-verified by orchestrator** |
| A3 | **Parts fitted never leave stock, no COGS.** Admitted in code; fiscal delivery-compliance gate bypassed via `PostingContext::WorkOrderGeneratedInvoice`. Needs an owner ruling (customer delivery note vs internal WO-consumption document) per the document-per-action principle. | P0 | L | `DocumentGenerationAdapter.php:107-125`; ticket `docs/superpowers/tickets/2026-08-10-workshop-parts-goods-lane-gap.md` |
| A4 | **Invoice PDF not garage-usable.** Blade reads `make`/`registration_number`, writers store `brand`/`license_plate` → plate never prints; mileage captured and dropped; copied into 2 more templates. No PDF render test exists. | P1 | S | `totals.blade.php:15-19` vs `WriteDocumentVehicleContextForWorkOrderInvoice.php:72-80` |
| A5 | **TN stamp duty charged but not shown** on the normal invoice (only on proforma); no legal-mentions concept; no country templates. Needs TN accountant ruling. | P1 | S / M | `DocumentTotalsCalculator.php:47-54`, `totals.blade.php:60-89` |
| A6 | **No work-order sheet / job card PDF** — how `ApprovalMethod::SignedDocument` is supposed to be evidenced. First thing a garage owner asks for. | P1 | M | no WO template in `resources/views`, no print button in `features/workshop-work-orders/` |

### 2b. Otospex delivery path — all in `02`

| # | Blocker | Size | Evidence |
|---|---|---|---|
| B1 | **No Otospex build deployed.** `VITE_APP_PRODUCT` compile-time; mechanic card hidden on an IziPOS bundle → garage cannot self-register. | M | `ProductConfigContext.tsx:59`, `Dockerfile:53`, `docker-compose.staging.yml:269`, `auth/config/verticals.ts:36-37` |
| B2 | **Promotion campaign cannot run for a mechanic.** L0 asserts parapharmacy unconditionally; L1–L10 serial behind it. 10/13 legs are reusable; L3 (lot expiry) must be dropped; needs new legs M6–M8 (vehicle → appointment → WO → invoice → payment). | M | `e2e/campaign/journey.ts:325`, `selectors.ts:85`, `ONBOARDING-CAMPAIGN.md:22` |
| B3 | **No vehicle import.** 7 `ImportType` cases, none vehicular; tables exist. A garage's vehicle book has no path in. | M | `ImportType.php`, `vehicles`/`vehicle_ownership_history`/`vehicle_mileage_readings` migrations |
| B4 | Automotive product fields gated on build-time `isOtospex`, not `hasModule('Vehicle')` (MED-3, 2026-06-15, still live). | S | `ProductForm.tsx:132`, `ProductDetailPage.tsx:217` |
| B5 | `tenant:census-day-one` blind to mechanic tables — a garage with no technician/bundle censuses CLEAN, yet census is a promotion precondition. | S | `DayOneCensus.php:43-50` |
| B6 | `Appointments` / `Fleet` extras are inert (zero gating call sites; Scheduling gated on `Workshop`). **Do not sell either.** Reminders log-only. | S | `ModuleName.php:32-33`, `Scheduling/routes.php:52`, `DispatchAppointmentReminder.php:92-96` |
| B7 | Demo seeder seeds no stock and no documents; WO numbering not advanced → first real WO collides. Service screens have 16 keys undefined in all locales; `ar/crm.json` missing. | M + S | `DemoTenantSeeder.php:1021`, `ServiceCategoryListPage.tsx:228` |
| B8 | Onboarding checklist is POS-shaped, no mechanic steps (`02`). **Nine** FE workshop routes across four areas (scheduling, bundles, technicians, payroll exports) carry `RequirePermission` but no `ModuleGuard` (`01` §13 #11, count per `06`). | S | `OnboardingStep.php:9-15`; `routes/index.tsx:1644-1698,2835-2855` |

### 2c. Vertical-agnostic launch gates (already tracked in `docs/handoff/LEDGER.md`; listed here only because they bind a garage too)

- **No ERP production host** (O-10/E-10: AX42-1 unordered; prod design unapproved after 5 REJECTs; no image-push pipeline; backups designed-not-built; Dokploy ≥0.29.13 upgrade blocks registering a prod server).
- **P0-2** registration timeout orphans tenants (300 s hotfix only; queued provisioning owner-gated).
- **G-4** products-import duplicate policy: report `02` recorded it as *withdrawn* (price-only override wrote `sale_price=null`); **superseded during this audit** — fix rounds 6–7 + gate r9 PASS landed on `origin/dev` at `70968fbf2` (2026-08-30). Staging verification of the sparse-row override path is still owed, and the `unit_id` fix (D-J0-6) that was trapped behind it should now be unblocked — confirm on the ledger. A garage imports a parts catalogue on day one, so this path must be exercised in the mechanic campaign (B2).
- **G-14** second opening-balance import silently drops balances.
- **CI blind since 2026-08-21** (S-14/S-17); **O-35** campaign-on-push never enabled → rule-22 gate inert; **S-28** census drift on all staging companies.
- **O-33** live `sk_live` platform key in staging env; **S-23** VAT backfill; **S-6** impersonation seeder never run; E-4 TN legal unrecorded.
- Stale doc lines: `PROMOTION-CHECKLIST-2026-08-26.md:93` says staging is DOWN (it isn't); `LEDGER.md:23` O-11 resolved elsewhere.

**Estimate.** Otospex-specific engineering (2a S-items + 2b) reads as **2–3 weeks for a small team** if run as gated lanes; A3 (goods lane) and A6 (job card) are the two M/L items that can land after a first customer is live *only if* the owner accepts "inventory is indicative" for that period. The production environment is the longer pole and is mostly owner decisions, not engineering.

---

## 3. AI sourcing agent — assessment of progress

Progress against the vision, step by step (details `03` §1–2, `04` §2–4):

| Vision step | Exists? | What's there | Gap |
|---|---|---|---|
| 0. Need originates from a work order | ✗ | `WorkOrderLine.sku_or_code`, `Replenishment` module (need→PO, operator picks supplier) | Zero purchase/RFQ references inside `Modules/Workshop` (G7) |
| 1. Understand the need / resolve parts | partial | platform `article_cross_references` (~253M rows, TecDoc dump, one hop, reachable from ERP); VIN decoding module | Recursive equivalence unsolved (Gap #2 in `05-automotive-catalog-lookup.md`); identity spec v0.2 unsigned with 2,665 collision groups; two competing cross-ref surfaces in the ERP (G12/G13); RFQ lines require a resolved `product_id` (G9) |
| 2. Send RFQs over supplier-linked channels | ✗ | `DocumentType::PurchaseQuoteRequest='purchase_rfq'`, fan-out by `group_id`, 8 routes, FE list/create/detail/compare with Playwright | `markSent()` stamps a timestamp, mails nothing; `MAIL_MAILER=log` everywhere; no WhatsApp/SMS/Twilio/Meta anywhere; `partners` has only `email`+`phone` — no `preferred_channel`, locale, consent (G1/G2) |
| 3. Receive replies, extract quote lines | partial | `DocumentIngestion` + erp-ml Claude extractor with per-field confidence | No inbound email/IMAP/message webhook (G3); `DocumentKind` has no `supplier_quote`; **extraction is dark in compose** — `ANTHROPIC_API_KEY` is set for other services (`docker-compose.yml:497,636`) but not in the `erp-ml` block, so the route returns 503 (O1, corrected per `06`) |
| 4. Normalise identities across suppliers | ✗ | see step 1 | **No `supplier_products` table and no per-supplier purchase price** — highest-leverage single missing table (G5) |
| 5. Compare + mix-and-match | ✗ | `comparisonLogic.ts` lowest-`unit_price` only, and only when every supplier quoted every line | `award()` converts one winner and cancels siblings as `lost` — **split award structurally impossible** (G4); no landed cost/MOQ/lead-time fields on the response DTO (G10) |
| 6. Human approval → POs | partial | single-winner `PurchaseQuoteRequestToPurchaseOrderConverter` with ordered lock | needs per-line award + N POs per group + idempotency keys (M2, M11) |
| 7. Expose as API + MCP | ✗ | platform `Partners` API keys + tiered quota middleware; `data-acquisition` has a real tool-calling loop, `ToolRegistry::to_anthropic_schemas()`, `Agent` base with run persistence, budget guard | **No MCP anywhere**; no ERP machine credential (`tokenCan` zero hits — M1); LLM stack unreachable from the ERP (G16) |

**Bottom line (orchestrator estimate, not a report figure):** roughly **a fifth of the sourcing vision exists**, and that fifth is the manual RFQ skeleton plus scattered plumbing in three different apps. The two things that must be built first are not AI at all: **(i) split award** and **(ii) a supplier-channel model + a real outbound transport**.

Incidental findings the owner should act on regardless (`03` §2.3): IDOR on platform `TenantOrderController` (any API key can read/cancel any tenant's order), expired API keys still authenticate, PurchaseHub routes unmetered/unlogged on the platform and un-gated in the ERP, billing sells "SMS Alerts" against a mock transport.

---

## 4. Recommended approach for the sourcing service

### 4a. Placement — where it lives (`04` §8, endorsed)

**Candidate C: a thin platform Laravel module `apps/platform/app/Modules/Sourcing/*` that owns durable state and the public seam, plus a new Python service `apps/sourcing-agent/` for LLM steps, channel adapters, the optimiser and the MCP server.** Rejected: everything-in-Laravel (no first-class LLM SDK, optimiser libs are Python); extending `PurchaseHub` (supplier-*push* campaign marketplace, parapharmacy-bound, opposite flow) or running the agent inside `data-acquisition` (untrusted scraping service should not hold tenant money-touching data). This matches the repo's own rules: universal capability → platform (`claude/architecture.md:30-37`), new top-level module sanctioned by `claude/vertical-boundaries.md:52`, platform-as-source-of-truth (`claude/shared-patterns.md:455-470`). Sourcing is **platform-core, not vertical-specific** — auto parts is the first vertical; a parapharmacy sourcing from wholesalers is the same loop.

### 4b. Durability — one deliberate deviation from `05`

`05` recommends **Temporal** for the multi-day suspend/chase/close workflow. `04` found **no durable workflow state in any Python service** (zero temporal/prefect/statemachine hits; `data-acquisition` job state is an in-process dict). Both are right about the problem. The orchestrator's ruling on the solution:

- **Phases 0–2: put the durable state machine in the platform Laravel module, on Postgres rows + queued jobs, copying the shape of `EnrichBarcodeSubmissionJob` + `BarcodeLookupStatus`** (`04` calls it "the only durable multi-step pattern in the repo": restart-safe, retrying, tracking-id-addressable, human-review-gated, HMAC webhook callback). The *wait* lives in the rows, never in a job: each job is a short idempotent step, and multi-day waits are *event-driven* (an inbound webhook flips a `sourcing_request_legs.status` row; a scheduled command fires chasers/expiry). **[06] premises corrected:** `apps/platform` has **no Horizon** (`docker-compose.dokploy.yml:340`) — its worker hardcodes `--queue=enrichment,webhooks,default`, so a new `sourcing` queue must be added to that command *and* guarded by a platform-side queue-coverage test mirroring the ERP's `HorizonQueueCoverageTest` (rule 20); **no `schedule:work`/`schedule:run` runner exists anywhere in the repo**, so a scheduler container is a new piece; `EnrichBarcodeSubmissionJob` itself gives up after ~12 min (`tries=3`, backoff `[30,120,600]`), which is fine for a *step* but is not a wait mechanism; the platform↔agent service credential does not exist (`04` drift D2) on top of ERP M1; and the existing `agent_runs` budget guard is **fail-open** and must be made fail-closed before it counts as a guardrail. So the honest delta is *one scheduler container + queue registration + a coverage test + a service credential* — still far less than Temporal (server + its DB + worker SDK + versioning discipline), which is why the ruling stands for Phases 0–2.
- **The Python service is stateless**: `resolve_parts`, `extract_quote`, `optimise_split`, `send_*` adapters, MCP server — each an idempotent HTTP endpoint the Laravel state machine calls (mirror of how the ERP already calls erp-ml). Every LLM call logs model id, prompt hash and `usage` into the platform's existing `agent_runs` / `validation_log` tables.
- **Revisit Temporal at Phase 3** only if the Laravel state machine grows past ~10 states or needs replay/versioning. `05`'s decomposition (workflow = deterministic lifecycle; activities = every side effect; webhooks persist-then-signal, never work in the handler) still applies verbatim to the Laravel job/state design.

### 4c. Everything else in `05` is endorsed as-is

- **Model layer:** single schema-constrained `messages.create` calls per step (work order → parts list; reply → quote lines; solver → narration). *The "agent" is the orchestration, not the model.* Claude Agent SDK, Managed Agents and LangGraph are the wrong tools for this shape.
- **Optimiser:** **OR-Tools CP-SAT** — booleans for supplier fixed cost / free-shipping thresholds / lead-time SLA / max-suppliers; three scenarios (Cheapest / Fastest / Simplest). The LLM never does the arithmetic.
- **Channels:** **email first** (Postmark inbound + per-leg unguessable reply addresses; SPF/DKIM/DMARC). WhatsApp second — Meta will likely categorise supplier-initiated RFQs as Marketing (most expensive tier), and **whether a BSP will onboard a Tunisian-registered entity with TND billing is UNVERIFIED and a go/no-go** — start Meta Business verification during Phase 1. Two-way SMS is not available in TN: nudge only. Tunisian Derja voice notes: best published WER ~25% → **always human-review, never auto-accept a price from voice**.
- **Part identity:** don't wait for TecDoc licensing (opaque, months). Build the cross-ref graph from the RFQ loop itself: exact → `pg_trgm`/Meilisearch fuzzy → LLM judge → one-time human confirm, persisted tenant-scoped then promoted platform-wide. Buy one self-serve TecDoc Catalogue seat in Phase 1 as ground truth. Skip French SIV (ANTS habilitation); use a commercial VRM.
- **MCP surface (FastMCP, Streamable HTTP):** `create_sourcing_request` (idempotent), `get_status`, `list_quotes`, `recommend_split` (read-only), `approve_split` (**the only mutating tool**). Tenant resolved from the credential, never from a tool argument. API keys for machine callers, OAuth 2.1 for interactive clients.
- **Guardrails enforced in code, not prompts:** no outbound message without an approved template + opt-in record; no PO without an approved `scenario_id`; per-tenant monthly cost caps failing closed; immutable storage of every inbound artefact linked to its extracted line.

### 4d. Phased roadmap (merged from `05` §7 and `04` §10)

| Phase | Scope | ERP endpoints needed (`04` M-list) | External lead items | Duration |
|---|---|---|---|---|
| **0 — make the manual RFQ honest** | Split/per-line award → N POs per group; `supplier_channel` + opt-in on Partner; landed-cost/MOQ/lead-time on the response DTO; **work order → parts-need draft**; `supplier_products` (+ per-supplier purchase price); glossary entries for RFQ/quote/award (rule 22) | M2 split award, M5 channel prefs, M6 inbound-quote event, M11 idempotency, M12 permissions | none | 2–4 wks, **pure ERP, ships value to garage #1 with zero AI** |
| **1 — email out, extraction in** | Postmark inbound domain; outbound RFQ email FR/AR; Claude extraction → draft lines → confidence gate → review queue; `DocumentKind::SupplierQuote`; turn the extraction service ON in compose (O1) | M3 real send, M9 inbound email endpoint, **M1 scoped machine credential** | sending domain + DKIM (days); Postmark; Anthropic key; **start Meta Business verification now** | 4–6 wks |
| **2 — WhatsApp** | Cloud API via a BSP; RFQ + chase templates; inbound media → same pipeline; voice STT with mandatory review | M8 outbound RFQ webhooks | Meta verification (1–4 wks, can bounce); WABA + BSP onboarding; template approval + re-categorisation rework; **TN entity acceptance — resolve first** | 6–8 wks |
| **3 — optimiser + MCP** | CP-SAT service; 3-scenario UI; FastMCP server + per-tenant auth; `approve_split` → multi-PO; supplier ranking (invert `TargetingService`) | M4 needs aggregate, M7 purchase-price history, M10 policies for machine callers | optional TecDoc commercial talk (assume 2–6 months) | 4–6 wks |

**Open questions to resolve before committing budget** (`05` §8): BSP onboarding of a TN entity; actual TN/FR WhatsApp per-message rates; TecDoc price with per-tenant redistribution; INPDP declaration requirement in Tunisia.

---

## 5. Recommended sequencing across both programmes

1. **Now (this week):** A1, A2, A4 (all S, all money-path, all with regression tests — the absent test is the root cause in each). B4 + B5 (S). Ticket A2/A4/A5/A6 (currently unticketed).
2. **Before the first demo:** B7 (seeder stock + documents + numbering; services i18n). Decide the demo language (FR vs AR — AR is an L-sized programme app-wide, but workshop namespaces are already at parity).
3. **Before a garage can even try it:** B1 Otospex build target on staging. B2 mechanic campaign variant (this is what stops the next A1). B3 vehicle import.
4. **Owner rulings needed to size:** A3 goods lane shape; A5 TN timbre line; `line_total` net-vs-gross semantics in the glossary; whether inventory may be "indicative" for the first customer's first month.
5. **Owner-gated, longest pole:** production environment (AX42-1, design approval, backups, Dokploy upgrade, secrets rotation), P0-2 proper fix, G-4, CI quota.
6. **Sourcing Phase 0** can start in parallel as a Codex lane once the spec passes a benchmark-first gate (`docs/conventions/10`) — it touches Procurement/Partner only and does not collide with the workshop fixes. Phases 1–3 wait for the first customer to be live; nothing in them is a launch dependency.

---

## 6. Method + caveats

- Five parallel read-only Opus agents; each report opened every `path:line` it cites; then a sixth agent adversarially re-checked ~55 citations in this page (`06`) — four claims were corrected above (A1 mechanism, B8 count/source, the API-key statement, the "20%" attribution), three line drifts fixed, two scores re-aligned to `01`. The orchestrator independently re-read `DocumentGenerationAdapter.php:202-222` and `WorkOrderTransitionService.php:105-130` and confirmed the A1 write and A2; and confirmed `ModuleName` is an enum of capability tokens (`Sales`/`Appointments`/`Fleet` are valid cases) so the `verticals.php` names are not a boot hazard.
- Not verified from this repo: TN/Maghreb coverage of the platform parts catalogue (a platform-data question), and the four `UNVERIFIED` items in `05` §8.
- Staleness: audits reflect `dev` at `6f157e924` (2026-08-30 morning); `origin/dev` moved to `70968fbf2` (G-4 + SG-3c-FU) while they ran — the G-4 line above was corrected for that, other ledger items were not re-swept. `LEDGER.md` is authoritative for vertical-agnostic items; where this synthesis and the ledger disagree, the ledger wins.
