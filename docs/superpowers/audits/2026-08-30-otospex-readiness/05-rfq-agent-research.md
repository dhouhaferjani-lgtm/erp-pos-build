# AI Sourcing Agent for Auto-Parts Procurement — Research & Architecture Recommendation

**Date:** 2026-08-30 · **Scope:** feasibility + architecture + phased plan for the Otospex/IziPOS "AI sourcing agent" platform service.
**Legend:** claims are cited inline. `UNVERIFIED` = could not confirm from a primary source in this pass.

---

## 0. What already exists in the ERP (grounding for Phase 0)

The RFQ spine is already built, on the unified `documents` table:

| Asset | Path |
|---|---|
| `DocumentType::PurchaseQuoteRequest = 'purchase_rfq'` | `apps/api/app/Modules/Document/Domain/Enums/DocumentType.php:20` |
| RFQ CRUD + fan-out to suppliers (`rfq.group_id`) | `apps/api/app/Modules/Procurement/Application/PurchaseQuoteRequestService.php` |
| Award → PO conversion | `apps/api/app/Modules/Procurement/Application/PurchaseQuoteRequestAwardService.php`, `.../Document/Domain/Services/Conversion/Converters/PurchaseQuoteRequestToPurchaseOrderConverter.php` |
| RFQ payload DTO (`validity_date`, `supplier_reference`, `lead_time_days`, `response_recorded_at`, `sent_at`, `closed_reason`) | `apps/api/app/Modules/Procurement/Domain/Dto/RfqPayload.php` |
| Work order / vehicle context | `apps/api/app/Modules/Workshop/WorkOrder/`, `apps/api/app/Modules/Vehicle/` |

**Critical gap found:** `PurchaseQuoteRequestAwardService::award()` is **winner-takes-all** — it converts one RFQ sibling to a PO and sets every other sibling to `Cancelled` with `closed_reason: 'lost'` (`PurchaseQuoteRequestAwardService.php:64-80`). Mix-and-match (split award across suppliers, per line) is **not** representable today. This is the single biggest domain change the vision requires, and it is orthogonal to the AI work — do it in Phase 0.

---

## 1. Prior art: what is proven vs novel

### 1a. Garage-side parts sourcing (US-centric, mature)

| Player | What it actually automates | Gap vs. this vision |
|---|---|---|
| [PartsTech](https://www.partstech.com/) | Single catalogue search across many aftermarket + OEM suppliers; **live** price/availability via supplier API integrations; cart, order push into ~40 shop-management systems ([review](https://servicemag.org/software/partstech), [Tekmetric setup](https://support.tekmetric.com/hc/en-us/articles/360041671633-Part-Supplier-Integration-Setup)) | No RFQ. Requires the supplier to expose an API/e-catalogue. No free-text/negotiated quotes. |
| [Nexpart](https://www.nexpart.com/) (WHI/Epicor) | Same model — one login across aftermarket, OEM, heavy duty, salvage distributors | Same |
| [RepairLink](https://wpreset.com/repairlink-review-features-pricing-auto-parts-ordering-alternatives/) (OEC) | OEM/dealer parts ordering with fitment | Same, dealer-side only |

**Read:** the entire US garage-parts category is *catalogue + live API punch-out*. Nobody in it does "send an RFQ, read a free-text reply". That model presumes digitised suppliers — which is exactly the assumption that fails in Tunisia and for the long tail in France.

### 1a-bis. Europe / France — same shape, network-locked

| Player | What it is |
|---|---|
| [Autossimo](https://pro.autodistribution.fr/les-plus-aux-professionnels/autossimo) (Autodistribution/PHE) | The FR garage cockpit: plate-based vehicle ID, multi-brand catalogue, HaynesPro-class technical data, real-time ordering — but **only from your AD distributor**. Multi-*equipmentier*, not multi-*supplier*. |
| [le Hub](https://lehub.allianceautomotive.fr/) (Alliance Automotive / Groupauto / Precisium) | Same model, AAG's own logistics platforms |
| [AUTODOC PRO](https://autodoc.pro/about) | Pan-EU B2B: 7.8M items, 2,700 brands, search by article/OEM/**plate/VIN**; B2B grew 110.5% in 2025 and went [all-digital in June 2026](https://autodoc.group/en/news-room/press-releases/autodoc-introduces-all-digital-strategy-for-its-workshop-business/) — single vendor, no RFQ |
| [Mister-Auto Pro](https://www.mister-auto.com/avantages-pro/) (Stellantis), [Oscaro Pro](https://auto.zepros.fr/4767-exclusif-quand-oscarocom-drague-des-reparateurs) | Pro *accounts* (SIRET check, discount, 30-day terms) layered on a B2C catalogue — not portals |
| [partslink24](https://www.partslink24.com/portal-ui/subscription-menu/subscription) | One subscription across 15+ OEM catalogues, VIN→parts, ordering from authorised dealers ($23–35/mo) |
| [TecDoc Catalogue](https://www.tecalliance.net/tecdoc-catalogue/) tiers (Classic / Garage Data / Pro / Truck) | Self-serve licence with a 14-day trial — cheaper entry than the enterprise data feed |

**RFQ-with-free-text-replies exists in Europe, but only outside new parts:** [Opisto/Opisto.pro](https://www.opisto.pro/fr) (~200 VHU salvage yards FR+ES, 4M used parts, quote generation) and the generic FR B2B marketplace [Hellopro](https://www.hellopro.fr/qui-sommes-nous) (23,000 suppliers; buyer posts a *demande*, gets up to 3 quotes). Neither touches garage new-parts sourcing.

### 1a-ter. Tunisia / Maghreb — no incumbent

No VC-backed garage-side sourcing platform found. What exists is distributor-owned B2B portals ([F.A.D](https://www.fad.com.tn/), [PREMA GROS](https://www.premagros.com/), [AD Tunisie](https://www.ad-tunisie.com/), [SOCOFA Gros](https://www.socofagros.com/)), B2C webshops (autopart.tn, karhabtk.tn, piecesautos.tn) and one local parts-ERP incumbent, [Mosaique Auto](https://www.mosaique-auto.com/). Morocco's [Parts24.ma](https://parts24.ma/en) takes contact **over WhatsApp** — a used-parts marketplace, but the behavioural precedent. Egypt has [Odiggo](https://disruptafrica.com/2021/08/24/egyptian-car-parts-marketplace-odiggo-raises-2-2m-expansion-funding/) (YC S21, $2.2M) on the marketplace side. **The category is open.**

### 1b. Generic AI sourcing / RFQ agents (enterprise procurement)

| Player | Automates | Notes |
|---|---|---|
| [Fairmarkit](https://suplari.com/blog/top-10-ai-procurement-tools) | Tail-spend RFQ automation: supplier matching, event creation, threshold-based auto-award | Closest analogue to the vision, but structured supplier portal, not free-text channels |
| [Keelvar](https://procurementaiagents.com/blog/keelvar-autonomous-sourcing-tested) | Sourcing *optimisation* bots — award scenarios over large bid sets (freight-heavy) | Proves the MILP/award-optimisation half |
| [Arkestro](https://procurementaiagents.com/compare/pactum-vs-arkestro-negotiation-ai) | Predictive price anchoring before first quote | Not needed at Phase 1 |
| [Pactum](https://procurementaiagents.com/agents/pactum-ai) | Autonomous chat negotiation with suppliers inside buyer guardrails | Proves agent-negotiates-over-chat |
| [Levelpath](https://tryxlr8.ai/blogs/best-ai-procurement-sourcing-automation-platforms), Zip | Intake/front-end orchestration | Not a sourcing engine |

### 1c. Proven vs novel

| Vision component | Status |
|---|---|
| Multi-supplier catalogue search + price compare | **Proven & commoditised** (PartsTech/Nexpart) |
| RFQ fan-out + optimisation-based award | **Proven** (Fairmarkit, Keelvar) |
| Chat-based supplier negotiation by an agent | **Proven at enterprise scale** (Pactum) |
| LLM extraction of quotes from PDF/email into lines | **Proven as a category** — invoice/PO IDP is commodity ([Rossum](https://www.extend.ai/resources/nanonets-review-features-pricing-alternatives), [Nanonets](https://nanonets.com/buyers-guide/ocr-software): 93–99% field accuracy, 70–90% straight-through). *Quote*-shaped extraction (multi-line, part-number-keyed, matched to a requested basket) is served only by small players — [Airparser](https://airparser.com/blog/how-to-extract-data-from-supplier-quotes-automatically/), [DigiParser](https://www.digiparser.com/solutions/purchase-order-parser) — i.e. underserved |
| B2B ordering over WhatsApp | **Proven in Maghreb/Africa** — [Chari (MA)](https://disruptafrica.com/2025/10/16/moroccan-b2b-e-commerce-startup-chari-closes-12m-series-a-round/) $12M Series A Oct 2025, 20k shops, WhatsApp ordering channel; [Wasoko](https://www.thecatalystfund.com/portfolios/wasoko) retailers order via WhatsApp/SMS/app, $125M Series B. **But all one-directional: buyer orders from a known catalogue.** |
| **RFQ over WhatsApp to informal suppliers, replies as free text / photos / voice notes in Darija, auto-extracted into comparable lines** | **Novel.** No precedent found of *suppliers replying with prices* over WhatsApp parsed back into structured quote lines. This is the defensible wedge. |
| Mix-and-match split award under shipping thresholds + lead-time SLA for a *single work order* | **Novel in this vertical** (Keelvar does it at freight scale, nobody at garage scale) |

The differentiator is not the AI. It is **serving suppliers who have no API and no portal** — the exact Tunisian/Maghreb reality and the French long tail.

---

## 2. Channel feasibility

### 2a. WhatsApp Business Platform (Cloud API)

| Constraint | Fact | Source |
|---|---|---|
| Only supported API | On-Premises API **fully sunset 2025-10-23**; Cloud API is the sole path | [Meta sunset doc](https://developers.facebook.com/docs/whatsapp/on-premises/sunset) |
| Business App ≠ API | The consumer WhatsApp Business *app* has no automation surface — Cloud API requires a separate, dedicated phone number | [Meta Cloud API](https://developers.facebook.com/docs/whatsapp/cloud-api/guides/send-messages) |
| Business-initiated | Outside an open window you may send **only pre-approved template messages** | ibid. |
| Customer Service Window | 24h, opened when the user messages/calls you, **reset** on each further inbound. Inside it, all non-template messages are free | [pricing](https://developers.facebook.com/docs/whatsapp/pricing) |
| Opt-in | "You can only send messages to WhatsApp users who have **opted in**" — applies regardless of window. **No B2B carve-out**: Meta's policy makes no business-vs-consumer distinction | [Meta send-messages](https://developers.facebook.com/docs/whatsapp/cloud-api/guides/send-messages) |
| Billing | **Per-message since 2025-07-01**, charged on template delivery; Service category free since 2024-11-01; current rate card effective **2026-07-01** | [pricing](https://developers.facebook.com/documentation/business-messaging/whatsapp/pricing) |
| TN/FR rates | Both supported (codes +216 / +33). Exact per-message rates are published only as CSV/PDF rate cards — `UNVERIFIED` numerically. FR marketing is among the most expensive markets; Jan-2026 lowered FR | [Blueticks](https://blueticks.co/blog/whatsapp-business-api-pricing-europe-2026) |
| Throughput | Tiers 250 → 2,000 → 10,000 → 100,000 → unlimited unique recipients/24h; since 2025-10-07 evaluated at **business-portfolio** level, upgrades every 6h; business verification jumps you off 250 | [Meta messaging limits](https://developers.facebook.com/docs/whatsapp/messaging-limits/) |
| Inbound media | Webhook delivers a `media_id`; `GET /{media-id}` returns a URL **valid 5 minutes**, download requires `Authorization: Bearer`. Limits: image 5 MB, audio 16 MB, video 16 MB, **document/PDF 100 MB** | [Media reference](https://developers.facebook.com/docs/whatsapp/cloud-api/reference/media) |

**Template categorisation is the load-bearing risk.** An RFQ template ("Bonjour, demande de prix pour…") is *not* triggered by a user action, so it will not cleanly qualify as **Utility** — Utility requires "specificity about the active or ongoing transaction… triggered by a user action or request" ([Meta template categorisation](https://developers.facebook.com/documentation/business-messaging/whatsapp/templates/template-categorization)). Any solicitation language ("offer", "book now") forces **Marketing**, the most expensive tier, and Meta re-categorises automatically. **Mitigation:** frame the template as a transaction reference the supplier already agreed to — the supplier signs a supply agreement in the ERP (that is your opt-in record), and the template cites an RFQ number + garage name and nothing promotional. Expect some templates to be re-categorised; budget for Marketing rates in FR.

**Provider choice.** BSPs serving Tunisia exist ([Messaggio TN](https://messaggio.com/whatsapp-business-api/tunisia/)); 360dialog is the EU-data-residency, low-markup, WhatsApp-only specialist ([360dialog docs](https://docs.360dialog.com/docs)); Twilio is the multi-channel generalist. **Whether Meta direct / 360dialog / Twilio will onboard a Tunisian-registered entity with TND billing is `UNVERIFIED`** — this is a go/no-go you must resolve before Phase 2. Practical hedge: register the WhatsApp Business Account under a French/EU entity if one exists, or use 360dialog with EUR billing.

### 2b. Email — the correct Phase 1 channel

| Provider | Inbound mechanism | Attachments | Note |
|---|---|---|---|
| **Postmark** (recommended) | Inbound domain/address → **JSON webhook**; `MailboxHash` extracts `user+hash@domain`; `StrippedTextReply` strips quoted history; SPF/SpamAssassin results in headers; retries up to 10× | base64 in payload, **35 MB total** | [docs](https://postmarkapp.com/developer/user-guide/inbound/parse-an-email) |
| SendGrid Inbound Parse | Single endpoint, multipart POST | 30 MB | [comparison](https://www.suprsend.com/post/sendgrid-vs-mailgun-vs-ses) |
| Mailgun Routes | Rule-based routing (sender/recipient/subject) to different webhooks | lower limits | ibid. |
| AWS SES + S3/Lambda | Raw MIME to S3, you parse | attachments billed $0.12/GB; inbound $0.10/1k | ibid. |

**Reply routing:** use a **dedicated inbound subdomain** with a unique local part per RFQ-leg (`rfq-<uuid>@inbound.otospex.com`) as `Reply-To`, not plus-addressing — some corporate mail systems mangle `+`, and Postmark's `MailboxHash` gives you both options natively. Keep `From:` on the garage's verified sending domain with SPF+DKIM+DMARC aligned; Google/Yahoo bulk-sender rules formally bite at 5,000/day but authentication is now table stakes for any B2B deliverability.

**Threading:** store `Message-ID` on send; match inbound `In-Reply-To`/`References` **and** the unique reply address — belt and braces, because suppliers forward and re-compose.

### 2c. SMS in Tunisia — do not plan on it as a reply channel

Alphanumeric sender ID registration takes **~18 days** and requires business documentation; approved IDs work across Ooredoo/Orange/Tunisie Telecom; domestic senders must pre-register above 30,000 SMS/month. International aggregators charge ~$0.20–0.28/SMS (Infobip cheapest at ~$0.202, Twilio ~$0.280) vs 0.10–0.20 TND (~$0.034–0.068) direct from local operators. **Critically: two-way SMS is not supported in Tunisia — outbound only** ([Sent.dm Tunisia guide](https://www.sent.dm/resources/tools/tunisia-sms-guide), [Twilio TN sender ID](https://support.twilio.com/hc/en-us/articles/4416872358043-Documents-Required-and-Instructions-to-Register-Your-Alphanumeric-Sender-ID-in-Tunisia)). Use SMS only as a *nudge* ("check WhatsApp/email for RFQ #123"), never as a quote channel.

### 2d. GDPR / France

Messaging a supplier's professional email or mobile about an existing commercial relationship is **transactional, not prospection**. Even for genuine B2B *prospection*, CNIL applies **opt-out, not opt-in**: legitimate interest suffices provided the person is informed and can easily object, sender is identified, and data is kept ≤3 years after last contact ([CNIL — la prospection commerciale](https://www.cnil.fr/fr/la-prospection-commerciale)). Phone prospection rules change 2026-08-11 (does not affect email). **Practical requirements:** (1) the garage, not Synerivia, is the data controller for its supplier contacts — Synerivia is processor, so a DPA is mandatory; (2) every outbound message names the garage and carries an unsubscribe/objection route; (3) store the opt-in artefact (supplier agreement, contact card consent) per contact, because it is *also* your WhatsApp opt-in evidence. Tunisia: loi 2004-63 / INPDP registration applies to processing in TN — `UNVERIFIED` whether a declaration is required for this processing class.

---

## 3. Extraction pipeline (email body / PDF / photo / voice → quote lines)

**Recommendation — one LLM pass with vision, schema-constrained, with a cheap deterministic pre-step and a confidence gate.**

```
inbound (email JSON | WhatsApp media_id)
  ├─ normalise: strip quoted reply (Postmark StrippedTextReply), detect language
  ├─ voice note (.ogg/opus) ──► STT ──┐
  ├─ PDF / image ─────────────────────┤
  └─ text body ───────────────────────┴──► Claude (claude-opus-5) with:
         • document/image content blocks (native PDF + image input)
         • output_config.format = JSON schema for QuoteLine[]
         • the RFQ's own requested lines as context (anchors the mapping)
         • per-line self-reported confidence + verbatim source span
  ──► confidence gate ──► auto-accept | human review queue (side-by-side original)
```

- **Model:** `claude-opus-5` ($5/$25 per MTok, 1M context), adaptive thinking, `output_config.effort: "high"`. Use `claude-haiku-4-5` ($1/$5) only for the language-detect/classify pre-step. Native PDF input: base64 `document` blocks, 32 MB request / 600 pages. Prices and IDs per the `claude-api` skill (cached 2026-06-24).
- **Structured output:** `output_config: {format: {...}}` — *not* the deprecated `output_format`; and `strict: true` on tool schemas. Do **not** prefill assistant turns (400 on Opus 5).
- **Prompt caching:** the extraction system prompt + brand/reference dictionary is a stable prefix → `cache_control: {type: "ephemeral"}`; verify `usage.cache_read_input_tokens > 0`.
- **OCR fallback** for scanned/handwritten price lists where the LLM under-performs: Mistral OCR (Arabic + handwriting supported, ~$1/1,000 pages batch, claimed 88.9% on complex handwriting vs Azure 78.2% — *vendor-internal benchmark*, treat as marketing) ([Azure AI Foundry](https://techcommunity.microsoft.com/blog/azure-ai-foundry-blog/unlocking-document-intelligence-mistral-ocr-now-available-in-azure-ai-foundry/4401836)); Google Document AI / Azure Document Intelligence / Amazon Textract all support handwriting ([Artificial Analysis OCR comparison](https://artificialanalysis.ai/agents/ocr)). Run OCR→text→LLM as the *second* attempt, not the default.
- **Voice notes:** French is easy. **Tunisian Derja is the hard case** — the best published result on the TuniSpeech-21h corpus is Whisper large-v2 at **24.74% WER** ([paper](https://www.researchgate.net/publication/402262302_A_New_Tunisian_Arabic_Corpus_and_Benchmark_for_Automatic_Speech_Recognition)). Deepgram Nova-3 Arabic (Jan 2026) covers 17 regional variants but trails specialist Arabic models by up to 20 points on some dialects ([benchmarks](https://aimultiple.com/speech-to-text)). **Design consequence:** at ~25% WER you cannot auto-accept prices from a Darija voice note. Transcribe, extract, then **always** route voice-sourced lines to human review, and show the operator the audio player next to the extracted line. Numbers and part references are the recoverable part — prompt the LLM to treat the transcript as noisy and to flag any digit it cannot corroborate.
- **Confidence & review:** score per field (ref, brand, price, qty, lead time). Auto-accept only when the part reference matches an RFQ line *and* the price parses cleanly *and* the source is text or a digital PDF. Everything else queues. Track a per-supplier auto-accept rate — it is your ROI metric.

---

## 4. Part identity normalisation

**TecDoc (TecAlliance)** is the industry reference: SOAP/REST web services, taxonomy make→model→engine→part-group→part with OE↔aftermarket linkage. **No public price** — licence is negotiated annually with TecAlliance or an authorised partner, priced on data volume, end-user count and distribution model, and the data must be mapped into your own model ([TecAlliance data delivery](https://www.tecalliance.net/tecdoc-data-delivery/), [integration cost analysis](https://www.mecaparts.app/blog/integrating-tecdoc-with-your-online-store)). Assume a multi-month commercial cycle and a five-figure EUR annual floor for a redistribution licence (`UNVERIFIED`).

**But there is a cheap way in:** TecAlliance sells the **TecDoc Catalogue** self-serve in tiers — *Classic* (article numbers, images, **OE/IAM cross-references**, vehicle applications), *Garage Data* (+ repair & maintenance), *Pro*, *Truck* — with a **14-day trial on card payment** and volume discounts at 5+/10+ licences ([shop.tecalliance.net Classic](https://shop.tecalliance.net/tecdoc-catalogue-classic/5637251092.p), [Garage Data](https://shop.tecalliance.net/tecdoc-catalogue-garage-data/5637251097.p)). Search supports **VIN, VRM (plate) and OE part number** ([TecDoc Catalogue](https://www.tecalliance.net/tecdoc-catalogue/)). Buy one seat in Phase 1 to *evaluate coverage and validate your own graph against ground truth*, before committing to a redistribution licence.

Alternatives: ACES/PIES catalogues (Epicor PartExpert, Nexpart, ShowMeTheParts) are US-market; [partslink24](https://www.partslink24.com/portal-ui/subscription-menu/subscription) covers the OEM side at $23–35/mo; 7zap and parts-crossreference.com are consumer-grade with no licensed API. For repair/technical data the FR reseller channel is [HaynesPro (Infopro Digital Automotive)](https://www.infopro-digital-automotive.com/haynespro/).

**Recommended pragmatic path — build the cross-ref graph as a by-product of the RFQ loop, licence TecDoc later:**

1. **Seed** from what you already have: supplier price lists (imported through the existing Import module), OE refs the garage types on the work order, and manufacturer catalogues the garage already owns.
2. **Model** it as a platform-scoped graph: `part_identity(canonical_id)` ← `part_reference(system, value, brand, tier)` with edges `equivalent_to(confidence, evidence, tenant_scope)`. Edges are **platform-scoped when confirmed by ≥N independent tenants**, tenant-scoped otherwise — that is the network effect and the moat.
3. **Match** in three stages: (a) exact normalised-string match (strip separators, uppercase); (b) trigram/fuzzy via Postgres `pg_trgm` + Meilisearch for typo-tolerance — you already run both; (c) **LLM judge** only on the survivors, given brand, description, vehicle application and the OE ref, returning `equivalent | supersedes | unrelated` + confidence.
4. **Confirm** with a human once, then never ask again: an operator accepting a supplier's substitute is the strongest possible label. Write the edge with `evidence: {rfq_id, supplier_id, operator_id}`.
5. **Quality tiers:** store `tier ∈ {oe, oes, aftermarket_premium, aftermarket_standard, remanufactured, used}` on the *reference*, not the identity. Suppliers self-declare; the garage's acceptance rules filter.

This is the classic entity-resolution play and it degrades gracefully: with zero cross-refs the agent still works (it just can't offer equivalents); with TecDoc later it bootstraps instantly.

---

## 5. Mix-and-match optimisation

**The LLM must not do the arithmetic.** It is a constrained combinatorial optimisation with a provably optimal answer; an LLM gives a plausible one, and a garage that finds a €40 cheaper split by hand never trusts the product again. The result must also be auditable and reproducible for a purchase decision. Use the LLM to *explain* the solver's answer, never to produce it.

**Model (MILP / CP-SAT):**

- Vars: `x[l,s] ∈ {0,1}` line *l* awarded to supplier *s*; `y[s] ∈ {0,1}` supplier *s* used; `f[s] ∈ {0,1}` supplier *s* hits its free-shipping threshold.
- Objective: `min Σ price[l,s]·qty[l]·x[l,s] + Σ shipping[s]·(y[s] − f[s]) + λ·Σ y[s] + μ·max_lead_time`
- Constraints: `Σ_s x[l,s] = 1` (each line sourced once); `x[l,s] ≤ y[s]`; `lead_time[l,s]·x[l,s] ≤ sla[l]`; `Σ_l value[l,s]·x[l,s] ≥ threshold[s]·f[s]` (free-shipping big-M); `Σ_s y[s] ≤ max_suppliers`; `min_order[s]·y[s] ≤ Σ_l value[l,s]·x[l,s]`; preferred-supplier bonus as a negative cost term.

**Solver: OR-Tools CP-SAT.** It is a hybrid CP/SAT/MIP solver, handles the boolean-heavy structure (`y`, `f`, indicator constraints) natively without big-M tuning, and in published comparisons solved 50/50 benchmark instances inside 180s where CBC solved 9/50 inside 1s ([CP-SAT primer](https://d-krupke.github.io/cpsat-primer/), [solver comparison](https://github.com/EthanJamesLew/pulp-sat-vs-ilp)). It is Apache-2.0, pip-installable, no licence server. PuLP+CBC is the fallback if you want solver-agnostic modelling; HiGHS is excellent at pure LP/MIP but you have booleans everywhere. At garage scale (≤50 lines × ≤10 suppliers) any of them solves in milliseconds — **pick CP-SAT for the modelling ergonomics and the indicator constraints, not for speed.**

**Present three scenarios, not one number.** Re-solve with different objective weights and show them side by side: **Cheapest** (λ=μ=0), **Fastest** (minimise `max_lead_time` lexicographically, then cost), **Simplest** (`max_suppliers = 1`, or large λ). Show Δcost and Δdays against Cheapest. Then use the LLM to write one sentence per scenario ("Simplest costs €18 more but everything arrives Tuesday from Sadok"). Always show the per-line winner and runner-up so the operator can override one line without re-running the whole thing.

---

## 6. Agent architecture — recommendation

**Pick (a): a Python FastAPI service using the Anthropic SDK with tool use, orchestrated by Temporal, exposed as a FastMCP server.** Not LangGraph, not Claude Agent SDK, not Managed Agents.

**Why:**

- The defining property of this workflow is **multi-day suspension**: send RFQ Monday, chase Wednesday, close Friday. That is durable execution, not agent looping. Temporal persists workflow state server-side so a crashed worker resumes exactly where it died without re-paying for completed LLM calls, and it makes days-long waits and human approval steps first-class ([Celery vs Temporal](https://suhasbhairav.com/blog/celery-vs-temporal-for-ai-agent-tasks-background-jobs-vs-durable-execution)). Celery + a Postgres state machine is a viable Phase-1 shortcut — you already run Redis + Horizon — but you will rebuild timers, retries, versioning and replay by hand.
- **Claude Agent SDK is the wrong product** — it is Claude Code as a library (built-in file/bash tools) for coding agents. **Managed Agents** hosts the loop and a sandbox, but this workflow's state lives in *your* Postgres and its side effects are *your* PO writes; you gain nothing and lose control of the fiscal audit trail. **LangGraph** adds a graph abstraction on top of a durability story you still have to build.
- The per-step reasoning is small and bounded: work order → parts list; reply → lines; solver result → explanation. Each is a single schema-constrained `messages.create`, not an agent loop. **Keep the "agent" in the orchestration layer, not the model layer.**

**Decomposition:** Temporal *workflow* = the RFQ lifecycle (deterministic, no I/O). Temporal *activities* = every side effect: `resolve_parts`, `send_rfq_email`, `send_rfq_whatsapp`, `extract_quote`, `optimise_split`, `create_purchase_orders`. Inbound webhooks (Postmark, Meta) land on FastAPI, are persisted immediately, then **signal** the workflow. Never do work in the webhook handler — Meta retries and Postmark retries up to 10×; every handler must be idempotent on provider message ID.

**MCP surface (FastMCP, Streamable HTTP):**

| Tool | Semantics |
|---|---|
| `create_sourcing_request` | idempotent on `(tenant_id, work_order_id, client_request_id)`; returns `sourcing_request_id` |
| `get_status` | RFQ legs, per-channel delivery state, replies received |
| `list_quotes` | extracted lines + confidence + source artefact links |
| `recommend_split` | runs the solver; returns 3 scenarios; **read-only, never sends** |
| `approve_split` | the only mutating tool; requires an explicit `scenario_id` + `approver_id`; creates POs |

Use Streamable HTTP, not bare SSE, for anything internet-facing ([FastMCP HTTP deployment](https://gofastmcp.com/deployment/http)). Auth: **API keys per tenant for machine callers, OAuth 2.1 for interactive clients** — FastMCP v3 ships an OAuth proxy and a middleware layer for injecting tenant context ([FastMCP auth](https://www.alexdunlop.com/writing/mcp-server-authentication-fastmcp-end-to-end)). Resolve `tenant_id` **from the credential, never from a tool argument** — a tool parameter is attacker-controlled.

**Guardrails (non-negotiable):**
1. **No outbound message without a pre-approved template + an opt-in record** for that contact. Enforce in the send activity, not the prompt.
2. **No PO without human approval** of a specific scenario id. `approve_split` is the only write.
3. **Cost caps** per tenant per month on LLM + WhatsApp spend, checked in the activity, failing closed.
4. **Full audit trail**: every inbound artefact (raw MIME, media blob) stored immutably and linked to the extracted line; every LLM call logged with model id, prompt hash, `usage`.
5. **Reply-address secrets**: RFQ reply addresses must be unguessable UUIDs — anyone who guesses one can inject a quote.

---

## 7. Delivery roadmap

| Phase | Scope | Duration | External accounts / approvals (lead time) |
|---|---|---|---|
| **0 — split award + supplier channels** | Extend `PurchaseQuoteRequestAwardService` to **partial/split award** (per-line, multi-PO from one RFQ group); add `supplier_channel` (email/whatsapp/phone) + opt-in record on the Partner module; manual quote entry UI with side-by-side comparison; work-order → parts-list draft | **2–4 wks** | **None.** Pure ERP work — ships value alone. |
| **1 — email RFQ out + LLM extraction in** | Postmark inbound domain; per-leg reply addresses; outbound RFQ email (FR/AR templates); Claude extraction → draft quote lines → human confirm; confidence gate + review queue | 4–6 wks | Sending domain + **SPF/DKIM/DMARC** (hours–2 days); Postmark account (same day); Anthropic API key (immediate) |
| **2 — WhatsApp** | Cloud API via 360dialog or Twilio; RFQ + chase templates; inbound webhook → media download (5-min URL!) → same extraction pipeline; voice-note STT with mandatory review | 6–8 wks | **Meta Business verification (1–4 wks, can bounce)**; dedicated phone number not on WhatsApp; WABA + BSP onboarding (days–2 wks); **template approval (minutes–48h per template, expect re-categorisation rework)**; TN entity onboarding acceptance by BSP — **resolve before starting** |
| **3 — optimiser + MCP** | CP-SAT solver service; 3-scenario UI; FastMCP server + per-tenant auth; `approve_split` → multi-PO | 4–6 wks | None technical. Optional **TecDoc licence — start the commercial conversation at Phase 1, assume 2–6 months** |

Sequence note: **start Meta Business verification during Phase 1**, not Phase 2 — it is the longest-lead, highest-variance item and it blocks nothing else.

---

## 8. Open questions to resolve before committing

1. Will a BSP (Meta direct / 360dialog / Twilio) onboard a Tunisian-registered entity? `UNVERIFIED` — ask all three now.
2. Actual TN/FR per-message rates from the 2026-07-01 CSV rate card. `UNVERIFIED`.
3. TecDoc price for a small ERP vendor with per-tenant redistribution. `UNVERIFIED`.
4. INPDP declaration requirement in Tunisia for this processing. `UNVERIFIED`.

**Answered in this pass — France plate→vehicle (SIV):** there is **no open public SIV API**. Access is gated by an **ANTS habilitation** (dossier + legitimate professional purpose + signed confidentiality/data-processing convention), obtained directly or via an agreed *tiers de confiance*; returned data is technical/administrative only — **no holder identity** under RGPD ([auto-ways.net](https://auto-ways.net/api-siv-immatriculation-acces-limites-alternatives/), [api-plaque-immatriculation.com](https://www.api-plaque-immatriculation.com/blog/guide-donnees-siv) — both commercial resellers, so **verify with ANTS directly**; the state-run consent-gated route is [API Particulier / ANTS](https://particulier.api.gouv.fr/catalogue/ants/extrait_immatriculation_vehicule)). **Recommended shortcut: skip SIV entirely** and use a commercial VRM service — TecDoc's own VRM search, [Infopro Digital VRM](https://www.infopro-digital-automotive.com/vrm-vehicle-identification-service/) (17 countries incl. FR, returns make/model/year/engine, KType/ACES-compatible) or [DriveRightData Global VRM](https://www.driveright-data.com/en/globalvrm). This removes a multi-week regulatory dependency from Phase 0.

**Also worth noting for FR go-to-market:** the incumbent FR garage DMS vendors (FIDUCIAL [Vulcain](https://www.fiducial.fr/Automobile-Motocycle-et-Machinisme-Agricole/Logiciel-et-DMS-pour-garage-mecanique/Vulcain), [GAD Garage](https://www.logiciel-garage.fr/), [Sphinx Manager](https://www.sphinx-manager.com/logiciel-pieces-auto), [MCA Garage](https://mca-concept.com/logiciels-metiers/mca-garage/)) already integrate supplier interfaces and the **Darva / Golda** electronic catalogues. Those are the integration points a French garage will expect — and the reason the RFQ agent is better positioned as an *MCP/API service other DMSs can call* than as a rip-and-replace.
