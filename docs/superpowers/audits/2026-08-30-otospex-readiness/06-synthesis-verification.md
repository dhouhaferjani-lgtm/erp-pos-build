# 06 — Adversarial verification of `00-SYNTHESIS.md`

**Date:** 2026-08-30 · **Mode:** read-only fact-check. No file was modified, no test or server run.
**Scope:** every `path:line` in §1, §2a, §2b, §3 and §6 of `00-SYNTHESIS.md`; three claims re-derived from
code rather than from the reports; internal consistency of the readiness scores against `01`–`05`; an
adversarial assessment of the §4b architecture ruling.

**Baseline read:** `00-SYNTHESIS.md` as of this session. Note the file was edited mid-verification — §1's
G-4 clause changed from *"G-4 import data-loss path"* to *"the products-import G-4 path (landed on
`origin/dev` today, staging-unverified)"*. §2c was **not** updated to match and still reads
*"G-4 products-import duplicate policy withdrawn"* (see C-6).

**Headline:** the two P0 defects are real, but **A1's stated mechanism is wrong**, and the wrongness matters
for how it is fixed and sized. Three further items are unsupported or mis-attributed, two scores are softer
than their source report, and the §4b ruling rests on two infrastructure facts that are false for the app it
places the module in.

---

## 1. Citation table

Verdicts: **C** = confirmed · **LD** = line-drift (claim true, line off) · **NS** = not supported · **FNF** = file not found.
Paths are absolute from `/Users/houssamr/Projects/syneriva`.

### §1 — the one-paragraph answer

| # | Claim | Cited `path:line` | Resolved file | Verdict | Note |
|---|---|---|---|---|---|
| 1.1 | Working garage demo seeder | `DemoTenantSeeder.php:150` | `apps/erp/apps/api/database/seeders/DemoTenantSeeder.php` | **C** | `:150` = `'vertical' => Vertical::Mechanic,`, inside the `Tenant::updateOrCreate` at `:144`. |
| 1.2 | `mechanic` vertical is first-class | `verticals.php:21-33` | `apps/erp/apps/api/config/verticals.php` | **C** | `'mechanic'` block opens `:12`; `:21-33` is its `default_modules` incl. `Vehicle`, `Workshop`. |
| 1.3 | → module resolution | `CompanyConfigService.php:71` | `apps/erp/apps/api/app/Services/CompanyConfigService.php` | **C** | `:71` = `$allEnabledModules = array_values(array_unique(array_merge(...)))`. |
| 1.4 | → backend gate | `RequireModule.php:62` | `apps/erp/apps/api/app/Http/Middleware/RequireModule.php` | **C** | `:62` = `if (! $config->hasModule($module))` → 403 at `:63`. |
| 1.5 | → sidebar | `Sidebar.tsx:318-327` | `apps/erp/apps/web/src/components/organisms/Sidebar/Sidebar.tsx` | **C** (path ambiguity) | Correct file is `components/organisms/Sidebar/Sidebar.tsx`. `components/layout/Sidebar.tsx` is a **2-line re-export** — a reader following the bare filename lands on the wrong file. |
| 1.6 | 11-state work-order machine | (no citation) | `…/Workshop/WorkOrder/Domain/Enums/WorkOrderStatus.php:14-24` | **C** | Exactly 11 cases. |
| 1.7 | Product is a compile-time `VITE_APP_PRODUCT`, every target defaults to `izipos` | see B1 row | — | **C** | Verified below. |
| 1.8 | "**VAT charged on VAT** … on every WO-generated invoice" | see A1 row | — | **NS** | See re-derivation (a). The mechanism named is falsified by the code; the defect is real via a different path. |
| 1.9 | Award is winner-takes-all, mix-and-match structurally unrepresentable | see §3 | `…/Procurement/Application/PurchaseQuoteRequestAwardService.php:65-82` | **C** | See re-derivation (c). |
| 1.10 | No messaging transport / inbound capture / supplier channel model / machine credential / MCP anywhere in the monorepo | see §3 rows | — | **C** | Verified below (with one nuance on `tokenCan`). |

### §2a — money-path defects

| # | Claim | Cited `path:line` | Verdict | Note |
|---|---|---|---|---|
| A1a | `'line_total' => $wol->line_total_incl_tax` | `Workshop/WorkOrder/Infrastructure/Adapters/DocumentGenerationAdapter.php:218` | **C** | Byte-exact at `:218`. (The 2026-08-07 ticket cites `:199`; the file has moved since — the synthesis's line is the current one.) |
| A1b | "…writes the tax-inclusive line amount into the *net* `line_total`, **then the totals calculator taxes it again**. 100.000 @19% invoices wrong." | same | **NS** | `DocumentTotalsCalculator::recalculate()` (`…/Document/Domain/Services/DocumentTotalsCalculator.php:43`) and `TaxCalculationService` (`:151`, `:364`) both use `DocumentLine::calculateTotal()`, which **recomputes from `qty × unit_price − discount` and never reads `line_total`** (`DocumentLine.php:248-257,280-302`). The direct WO→invoice header is arithmetically **correct**. See re-derivation (a) for what is actually wrong. |
| A1c | "Ticketed CRITICAL 2026-08-07, still open" | `docs/superpowers/tickets/2026-08-07-wo-quote-tax-inclusive-line-total.md` | **C** | Ticket exists, severity CRITICAL. **But the ticket's own mechanism is the conversion chain, not the direct invoice** — the synthesis dropped that qualifier that report `01:142` retained ("Conversions … re-read the stored column"). |
| A2a | Reserve on `Approved`, release only on `Cancelled`; `Invoiced`/`Completed` neither release nor consume | `WorkOrderTransitionService.php:116-118` | **C** | `:116` reserve, `:117` generateInvoice, `:118` release-on-Cancelled. Exact. |
| A2b | No expiry; adapter has zero tests | (no citation) | **C** | `ReservationSource.php:36` → `self::WorkOrder => null`; `InventoryReservationAdapter.php:60` passes `expiresAt: null`. No `InventoryReservationAdapter` test file. See re-derivation (b). |
| A3a | Parts never leave stock, no COGS; exemption admitted in code | `DocumentGenerationAdapter.php:107-125` | **C** | `:107-124` is the verbatim admission; `:125` is `post($document, PostingContext::WorkOrderGeneratedInvoice)`. |
| A3b | Ticket | `docs/superpowers/tickets/2026-08-10-workshop-parts-goods-lane-gap.md` | **C** | Exists. |
| A4a | Blade reads `make` / `registration_number` | `totals.blade.php:15-19` | **C** | `apps/erp/apps/api/resources/views/documents/components/totals.blade.php:15` `$vehicle->make`, `:18` `$vehicle->registration_number`. |
| A4b | Writers store `brand` / `license_plate` | `WriteDocumentVehicleContextForWorkOrderInvoice.php:72-80` | **C** | `:73` `'license_plate'`, `:74` `'brand'`. Independently corroborated: `DocumentPdfService.php:199` casts that snapshot array to the `$vehicle` object the blade reads, and `Vehicle.php:76-77` confirms the model columns are `license_plate`/`brand`. **Understated** — `make` is also never printed, not just the plate. |
| A5a | TN stamp duty computed | `DocumentTotalsCalculator.php:47-54` | **C** | `:48` `calculateDocumentTaxes()`, `:53` `'stamp_duty_amount' => $taxResult->documentTaxTotal`. |
| A5b | …but not shown on the normal invoice (only proforma) | `totals.blade.php:60-89` | **C** | `:60-89` is the non-proforma branch: Subtotal / Discount / Tax / Total / Paid / Balance — **no stamp-duty row**. The proforma branch is `@include(...proforma_totals_rows)` at `:59`. |
| A6 | No WO template in `resources/views`, no print button in `features/workshop-work-orders/` | (directory claims) | **C** | `resources/views/documents/templates/` has 8 templates, none for a work order; zero `print`/`pdf` hits in `apps/web/src/features/workshop-work-orders/`. |

### §2b — Otospex delivery path

| # | Claim | Cited `path:line` | Verdict | Note |
|---|---|---|---|---|
| B1a | `VITE_APP_PRODUCT` compile-time | `ProductConfigContext.tsx:59` | **C** | `apps/erp/apps/web/src/contexts/ProductConfigContext.tsx:59` = `import.meta.env['VITE_APP_PRODUCT']`, `:62` returns `'izipos'`. |
| B1b | Build default | `Dockerfile:53` | **C** | `apps/erp/apps/web/Dockerfile:53` = `ARG VITE_APP_PRODUCT=izipos`. |
| B1c | Staging default | `docker-compose.staging.yml:269` | **C** | `apps/erp/docker-compose.staging.yml:269` = `VITE_APP_PRODUCT: ${VITE_APP_PRODUCT:-izipos}`. |
| B1d | Mechanic card hidden on an IziPOS bundle | `auth/config/verticals.ts:38` | **LD** | The gate is `apps/erp/apps/web/src/features/auth/config/verticals.ts:36-37` (`getVerticalsForProduct` → `product === 'otospex' ? otospexVerticals : iziposVerticals`). `:38` is the closing brace. Claim true, cite `:36-37`. |
| B2a | L0 asserts parapharmacy unconditionally | `e2e/campaign/journey.ts:325` | **C** | `apps/erp/apps/web/e2e/campaign/journey.ts:325` = `await expect(selectors.register.vertical, 'Parapharmacy is required…').toBeVisible()`. |
| B2b | Selector is parapharmacy-bound | `selectors.ts:85` | **C** | `vertical: /parapharmacy|parapharmacie/i`. |
| B2c | Campaign doc says parapharmacy-only | `ONBOARDING-CAMPAIGN.md:22` | **C** | `apps/erp/docs/qa/ONBOARDING-CAMPAIGN.md:22` — "registers the **Parapharmacy** vertical … other verticals and countries are unsupported". |
| B2d | "10/13 legs are reusable" | (no citation) | **C** | Matches `02:78` verbatim. |
| B3 | 7 `ImportType` cases, none vehicular; tables exist | `ImportType.php`, vehicle migrations | **C** | `…/Import/Domain/Enums/ImportType.php` — 7 cases at `:9,10,11,39,41,42,43`; none vehicular. |
| B4a | Automotive fields gated on `isOtospex` | `ProductForm.tsx:132` | **C** | `apps/erp/apps/web/src/features/inventory/ProductForm.tsx:132` = `when: (ctx) => ctx.isOtospex`. |
| B4b | Same on detail page | `ProductDetailPage.tsx:217` | **C** | `:217` = `const automotiveSection = isOtospex && hasAutomotiveData ? …`. |
| B5 | Census blind to mechanic tables | `DayOneCensus.php:43-50` | **C** | `…/Tenant/Application/Services/DayOneCensus.php:43-50` — 8 checks (units, tax configs, purposes, refund purposes, repositories, payment methods, numbered drafts, checklist). No vehicle/technician/bundle assertion. |
| B6a | `Appointments`/`Fleet` exist as enum cases | `ModuleName.php:32-33` | **C** | `apps/erp/apps/api/app/Enums/ModuleName.php:32` `Appointments`, `:33` `Fleet`. |
| B6b | Scheduling gated on `Workshop` | `Scheduling/routes.php:52` | **C** | `…/Scheduling/Presentation/routes.php:52` = `'module:Workshop'`. |
| B6c | Reminders log-only | `DispatchAppointmentReminder.php:92-96` | **C** | `:91-93` "Placeholder transport", `:94` mock external id, `:96` `Log::info`. |
| B7a | Demo seeder: WO numbering not advanced → first real WO collides | `DemoTenantSeeder.php:1021` | **C** | `:1014` hand-rolls `'WO-'.$year.'-'.str_pad(...)`; `:1021` writes it as `work_order_number`, bypassing the numbering service. |
| B7b | 16 service-screen keys undefined in all locales | `ServiceCategoryListPage.tsx:228` | **C** (as an instance) | `:228` = `t('services.categories', 'Service Categories')` — a hardcoded English fallback. The count of 16 was not re-derived. |
| B7c | `ar/crm.json` missing | (no citation) | **C** | `apps/erp/apps/web/src/locales/` has `en/crm.json` and `fr/crm.json` only. |
| B8a | Onboarding checklist is POS-shaped, no mechanic steps | `OnboardingStep.php:9-15` | **C** | 7 cases, all POS/retail. |
| B8b | "Four FE workshop routes lack `ModuleGuard`" | `routes/index.tsx` (no line) | **NS as attributed / count is soft** | Two problems: (i) §2b's header says *"all in `02`"*, but **report 02 does not make this claim** and explicitly rates FE gating ✓/✓/✓ (`02:34`); it comes from `01:572`. (ii) The number counts *route areas*, not routes: the permission-only routes are `scheduling` ×3 (`routes/index.tsx:1644,1654,1664`), `workshop/bundles` ×3 (`:1678,1688,1698`), `workshop/technicians` ×2 + `payroll-exports` ×1 (`:2835,2845,2855`) = **9 `<Route>` elements in 4 areas**. Also note `01:411` mis-labels `routes/index.tsx:1536-1589` as bundles — those five guarded routes are **services**. |

### §2c — spot-checked doc citations

| Claim | Cited | Verdict | Note |
|---|---|---|---|
| Stale line says staging is DOWN | `PROMOTION-CHECKLIST-2026-08-26.md:93` | **C** | `:93` = "Staging Dokploy is DOWN (owner)". |
| `LEDGER.md:23` O-11 | `LEDGER.md:23` | **C** (line) / unverified (claim) | `:23` is indeed O-11 ("launch staffing = TWO humans"), status **OPEN**. Whether it was "resolved elsewhere" is not evidenced. |
| G-4 withdrawn | (no citation) | **NS — stale** | See C-6. |

### §3 — sourcing-agent assessment

| Claim | Verdict | Evidence |
|---|---|---|
| `WorkOrderLine.sku_or_code` exists | **C** | `WorkOrderLine.php:36`. |
| "Zero purchase/RFQ references inside `Modules/Workshop` (G7)" | **C with nuance** | No functional coupling, but there **are** references: `WorkOrderPartsNeeded` event, `PartNeed` VO and `LogPartsNeededForProcurement` (a log-only `ShouldQueue` listener registered at `EventServiceProvider.php:141-143`). "Zero references" is literally false; "zero functional coupling" is true. |
| `DocumentType::PurchaseQuoteRequest='purchase_rfq'` | **C** | `…/Document/Domain/Enums/DocumentType.php:20`. |
| 8 RFQ routes | **C** | 8 `purchase-quote-requests` routes in `…/Procurement/Presentation/routes.php` (of 20 total). |
| `markSent()` stamps a timestamp, mails nothing | **C** | `PurchaseQuoteRequestService.php:112-136` — sets `DocumentStatus::Confirmed` and `sentAt: now()`; no mail, no dispatch. |
| `MAIL_MAILER=log` everywhere | **C (partially verified)** | `apps/erp/apps/api/.env.example:124`. No other `MAIL_MAILER` appears in tracked compose/env files, so "everywhere" is unfalsified but also unproven — it is really "nowhere is it set to anything else". |
| `partners` has only `email`+`phone`; no `preferred_channel`, locale, consent | **C** | Zero hits for `preferred_channel` / `whatsapp` / `twilio` across `apps/erp/apps/api/app` and `database/migrations`. |
| `comparisonLogic.ts` lowest-`unit_price` only | **C** | `apps/erp/apps/web/src/features/purchases/quote-requests/comparisonLogic.ts:76-82` — `bccomp` reduce over `cell.unit_price`. |
| `award()` cancels siblings as `lost`; split award structurally impossible | **C** | See re-derivation (c). |
| `DocumentKind` has no `supplier_quote` | **C** | `…/DocumentIngestion/Domain/Enums/DocumentKind.php` — only `SupplierInvoice`, `SupplierDeliveryNote`. |
| "extraction is dark in compose (**no `ANTHROPIC_API_KEY`**, O1)" | **NS as an absolute** | `ANTHROPIC_API_KEY` **is** wired in `docker-compose.yml:497` (data-acquisition) and `:636` (growth-advisor), and in `docker-compose.dokploy.yml:81,115`. What is true is scoped: the **`erp-ml` service block** (`docker-compose.yml:381-422`) sets none. Report `03:232` states it as "in **no** compose/env file" — that source sentence is **false**, and the synthesis inherited it. |
| No `supplier_products` table | **C** | Zero migrations matching `supplier_product`. |
| No MCP anywhere | **C** | Only hits are the vendored `anthropic` SDK inside `apps/erp-ml/.venv` and review `.jsonl` files in stale sibling worktrees. Zero first-party MCP code. |
| No ERP machine credential — `tokenCan` zero hits (M1) | **C with nuance** | 2 hits in the live tree, both **comments inside tests** (`AuthenticationTest.php:693`, `AuditEventSyncTest.php:197`). Zero production call sites — the substantive claim holds; "zero hits" is literally wrong. |
| `ToolRegistry::to_anthropic_schemas()` | **C** | `apps/data-acquisition/app/automotive/toolbelt/registry.py:28`. |
| `article_cross_references` reachable from ERP | **C (existence)** | `apps/platform/app/Modules/Automotive/Domain/Models/ArticleCrossReference.php` + migrations. Row count (~253M) not verifiable read-only. |
| IDOR on platform `TenantOrderController` | **C — and it is real** | `apps/platform/app/Modules/PurchaseHub/Presentation/Controllers/TenantOrderController.php` — `show():70`, `cancel():81`, `confirmReceipt():89` all `PurchaseOrder::findOrFail($id)` with **no tenant/partner scope**, while `index():51-52` does scope. Any valid API key can read/cancel/confirm any tenant's order. |
| Expired API keys still authenticate | **C** | `…/Partners/Infrastructure/Middleware/AuthenticateApiKey.php:31` filters on `is_active` only; no `expires_at` predicate. |
| "roughly **20%** of the sourcing vision exists" | **NS — unsourced** | No percentage, fraction or equivalent appears in `03`, `04` or `05`. It is the orchestrator's own estimate presented in a table of sourced claims. See C-5. |

### §6 — method + caveats

| Claim | Cited | Verdict | Note |
|---|---|---|---|
| Orchestrator re-read `DocumentGenerationAdapter.php:200-235` | `:200-235` | **LD** | The file is **224 lines**; the range overruns EOF by 11. The relevant `DocumentLine` fill is `:202-222`. |
| Orchestrator re-read `WorkOrderTransitionService.php:105-130` | `:105-130` | **C** | Contains the `match ($to)` block `:114-120`. |
| "confirmed A1 and A2" | — | **half NS** | A2 confirmed. A1's line is confirmed but its **mechanism is not** — a re-read of `:200-235` alone cannot establish "the totals calculator taxes it again", because the totals calculator is a different file that the orchestrator did not cite reading. This is the single largest methodological gap in the synthesis. |
| `ModuleName` is an enum of capability tokens; `Sales`/`Appointments`/`Fleet` are valid cases | — | **C** | `ModuleName.php:22` `Sales`, `:32` `Appointments`, `:33` `Fleet`. |

---

## 2. Three re-derivations from code

### (a) VAT-on-VAT — the defect is real, the stated mechanism is wrong

**What the code does.**

1. `DocumentGenerationAdapter::mapLine()` writes `'unit_price' => $wol->unit_price` (`:214`) and
   `'line_total' => $wol->line_total_incl_tax` (`:218`).
2. `WorkOrderLine.unit_price` **is net (HT)** — confirmed at
   `WorkOrderLineService::recomputeLineMoney()` (`:251-285`): `gross = qty × unit_price` (`:262`),
   discount off (`:265-271`) → `line_total_excl_tax`, then `tax = excl × rate/100` (`:274-277`) →
   `line_total_incl_tax = excl + tax` (`:280`). So `line_total_incl_tax` **is** genuinely tax-inclusive.
   The synthesis is right about that half.
3. **But the totals calculator does not read `line_total`.** `DocumentTotalsCalculator::recalculate()`
   (`:43`) sums `$line->calculateTotal($scale)`, and `DocumentLine::calculateTotal()` (`:248-257`) delegates
   to `computeLineTotal()` (`:280-302`), which recomputes `qty × unit_price − discount` from scratch.
   `TaxCalculationService` does the same (`:151`, `:364`). **The header of the directly-generated WO invoice
   is therefore correct**, not "100.000 @19% invoices wrong".

**Where the poison actually surfaces — three places the synthesis does not name:**

- **The conversion chain** (this is the ticket's own probe). `CopiesDocumentData::copyLine():142` copies
  `line_total` verbatim, and `CopiesDocumentData::recalculateTotals():307-317` rebuilds the target header
  **from the stored column** — subtotal `:310` *and* line VAT `:314`. That is the 108.000 → 129.600 path in
  `tickets/2026-08-07-…:Probe D`. It needs a WO **quote** to be converted onward; it is not "every
  WO-generated invoice".
- **The GL.** `AccountingService` credits revenue from the stored column
  (`:349-352` residual plan, `:648-656` the actual `JournalLine` credit) and `groupTaxByRate():1936-1952`
  computes the VAT leg as `line_total × rate` — i.e. **literally VAT on a VAT-inclusive base**. Since
  `residual = documentTotal − (revenue + vat)` (`:383`) and revenue already equals the TTC total, the residual
  is **negative** → `GlResidualRefusal::NegativeResidual` (`:390-391`), thrown by the pre-flight at `:212-220`.
  *Predicted, not executed:* a WO invoice at a non-zero VAT rate should be **refused at GL posting**, not
  silently mis-totalled. Someone must run this before sizing A1 — the symptom the owner will actually see may
  be a hard failure, not a wrong number.
- **The VAT declaration base.** `PostedLineTaxSnapshotBuilder:145-181` builds each rate bucket's `base` from
  `line_total`. On any WO invoice that did post, the declared taxable base is inflated by the VAT.
- **The printed invoice.** `documents/components/line_items.blade.php:68` prints `$line->line_total` per row,
  so the line column is TTC while the totals box is HT — the PDF does not add up. This is a distinct,
  customer-visible defect neither `01` nor the synthesis calls out.

**Verdict.** A1 is a genuine P0 and the one-line fix is right, but the synthesis's causal sentence, its worked
example ("100.000 @19% invoices wrong") and its scope ("on every WO-generated invoice") are **not supported**.
Report `01:142` was more careful ("**Conversions** … re-read the stored column and tax it again"); the
synthesis dropped the qualifier. Fixing the *blast radius* description matters for sizing: the legacy-row
sweep must target GL journals and VAT-period snapshots, not just document headers.

### (b) Reservation leak — fully confirmed, no other path exists

Exhaustive grep of `apps/erp/apps/api/app`:

- `releaseFor(` / `releaseForWorkOrder` appears at exactly four sites: the call
  (`WorkOrderTransitionService.php:118`, `Cancelled` only), the adapter (`InventoryReservationAdapter.php:76,78`),
  the contract (`InventoryReservationServiceInterface.php:49`) and the implementation
  (`StockReservationService.php:854`). **No other caller.**
- **No listener releases.** `EventServiceProvider.php:130-143`: `WorkOrderCompletedV2` →
  `CloseTimeEntry…`, `WriteMileageReading…`, `MirrorAppointment…`; `WorkOrderClosed` →
  `MirrorAppointmentOnWorkOrderClosed`; **`WorkOrderInvoiced` has no listeners registered at all.**
  None touches inventory.
- **No consumption.** Zero `consume*` call sites bridging Workshop → Inventory; every `consume` hit in either
  module is a doc-comment or an unrelated FEFO/receipt path.
- **No expiry, no reaper.** `ReservationSource.php:36` → `self::WorkOrder => null`, and
  `InventoryReservationAdapter.php:60` passes `expiresAt: null`, so `StockReservationService.php:842-843`
  never writes one. No scheduled command sweeps expired reservations
  (`apps/erp/apps/api/routes/console.php` / `app/Console` have none), and — see §4b below — **no scheduler
  process runs anywhere in this repo's compose files**, so even if one existed it would not fire.
- `ReservationSource.php:32-35` documents the opposite intent ("WO cancellation **/ closure** releases them").

**Verdict: CONFIRMED, and the synthesis if anything understates it** — there is no expiry safety net *and*
no scheduler that could ever run one.

### (c) Single-winner award — fully confirmed

`apps/erp/apps/api/app/Modules/Procurement/Application/PurchaseQuoteRequestAwardService.php`, read in full:

- `award()` takes a single `$rfqId` (`:26`), loads that one document as `$winner` (`:30-35`), locks the whole
  `group_id` set `FOR UPDATE` (`:40-47`), refuses if the group already has a live awarded PO (`:54`,
  `assertNoLiveAwardedPo():165-179` → `RFQ_GROUP_ALREADY_AWARDED`), then converts **the winner document as a
  whole** to one PO (`:59-63`).
- Siblings loop `:65-82`: each non-winner gets `status = DocumentStatus::Cancelled` (`:71`) and a rebuilt
  `RfqPayload` with `closedReason: 'lost'` (`:79`). **Exactly as claimed.**
- **No per-line award path anywhere.** `award` across `app/Modules/Procurement` and `app/Modules/Document`
  resolves to: this service, its controller call site
  (`PurchaseQuoteRequestController.php:190`), the duplicate `RFQ_GROUP_ALREADY_AWARDED` guard in
  `PurchaseQuoteRequestService.php:162`, and two doc-comments in
  `PurchaseQuoteRequestToPurchaseOrderConverter.php:92,184`. No line-level selection field, no
  `awarded_line_ids`, no multi-PO emission. Zero `award` hits in `apps/erp/apps/web/src`.

**Verdict: CONFIRMED.** "Split award structurally impossible" is accurate: the unit of award is the document,
and losing siblings are terminally `Cancelled`, so a second PO cannot be raised from the same group afterwards.

---

## 3. Internal consistency against reports 01–05

| # | Finding | Direction |
|---|---|---|
| **C-1** | **Module gating scored a full point softer than its source.** Synthesis: *"Provisioning + module gating for `mechanic` — **4/5** — Works without any provisioning step."* Report `01:540`: *"Module resolution / gating — **3/5** — `Appointments` + `Fleet` are inert; 4 FE routes ungated."* The synthesis keeps the two defects (as B6 and B8) but raises the score, and drops the ungated-routes reason from the score's justification. | **SOFTER than `01`** |
| **C-2** | **Printing: the source contradicts itself and the synthesis papers over it.** `01:292` (§7 header) says **2/5**; `01:542` (§12 table) says **1/5**. The synthesis writes **"1–2 / 5"**, which reads as a considered range rather than as an unresolved contradiction in the source. Flag it to the owner as "report 01 disagrees with itself", not as a range. | **hedged** |
| **C-3** | **B8's second clause is attributed to the wrong report.** §2b's header says *"all in `02`"*; the "Four FE workshop routes lack `ModuleGuard`" claim is in `01:572` and is **absent** from `02`, which rates FE gating as no-defect (`02:34`). | **HARSHER than `02`** |
| **C-4** | **A1's scope is broadened past both its source report and its ticket.** `01:142` and the ticket both scope the wrong total to *conversions*; the synthesis says "on every WO-generated invoice" and puts a worked wrong-number example in the §2a evidence column. | **HARSHER than `01`** |
| **C-5** | **"roughly 20% of the sourcing vision exists" is unsourced.** No such figure or equivalent fraction appears in `03`, `04` or `05`. The step table above it is honestly ✗/partial-scored (0 exists, 2 partial, 4 ✗, ~1.5/7 ≈ 21% if you count that way), so 20% is *defensible* — but it is presented in a document whose stated contract is that every claim carries a citation. Label it as the orchestrator's estimate. | **unsourced** |
| **C-6** | **§2c's G-4 line is stale and collides with a different G-4.** §1 was updated to say the products-import G-4 "landed on `origin/dev` today"; §2c still lists it as *"withdrawn — price-only override writes `sale_price=null`"*. `docs/handoff/LEDGER.md:248` (SG-7) records G-4 as **GATED PASS r1–r9, browser-verified, MERGED local dev `49656a7b0`, PROMOTABLE** after fix round 6. Separately, `LEDGER.md:119` uses the id **G-4 for a completely different item** (TN VAT declaration netting POS refunds, SATISFIED IN CODE) — an owner reading "G-4" against the ledger will hit the wrong row. | **STALE + ambiguous id** |
| **C-7** | **Report `03:232` (O1) is factually wrong and the synthesis inherited it.** "`ANTHROPIC_API_KEY` … in **no** compose/env file" — it is in four places (`docker-compose.yml:497,636`; `docker-compose.dokploy.yml:81,115`). Only the `erp-ml` service block lacks it. | **HARSHER than reality** |
| **C-8** | **§4b overstates the size of its own deviation from `05`.** `05:189` already concedes *"Celery + a Postgres state machine is a viable Phase-1 shortcut"*. The synthesis frames 4b as "one deliberate deviation"; it is closer to "adopts 05's own stated shortcut". It also drops `05`'s attached warning — *"you will rebuild timers, retries, versioning and replay by hand"* — which is the whole cost of the ruling. | **overstated conflict, dropped caveat** |
| **C-9** | Scores that **do** check out: intake→job-done 4/5 (`01:536-538,544` all 4/5); money tail 2/5 (`01:539`); vehicle import 2/5 (`02` blocker 3); campaign 0/5 (`02:78`, "L0 is the wall"); Otospex deployment 0/5 (`02` blocker 1); production hosting 0/5 (`02:178`); RFQ engine 3/5 and sourcing agent 0–1/5 are consistent with `03`/`04`'s ✗-heavy tables. | consistent |

---

## 4. Adversarial assessment of the §4b ruling

**The ruling rests on two infrastructure premises that are false for the app §4a places the module in.**
§4a puts the durable state in `apps/platform/app/Modules/Sourcing/*`. §4b then says to run it "on **Postgres +
Horizon**", copying `EnrichBarcodeSubmissionJob`, with "**the Laravel scheduler** fires chasers/expiry" and
"**zero new infrastructure**". Three checks:

1. **Horizon is not installed in `apps/platform`.** `apps/platform/composer.json` has no `laravel/horizon`
   and `apps/platform/config/` has no `horizon.php`. `docker-compose.dokploy.yml:340` states it outright:
   *"NOTE: Laravel Horizon is NOT installed; use queue:work."* Horizon exists only in the **ERP**
   (`apps/erp/apps/api/config/horizon.php`), which is a different app in a different DB. The ruling's "adds
   zero new infrastructure" is true only for a placement §4a explicitly rejected (Candidate A ran inside the
   platform *with Laravel queues* — note `04` §8 says "Laravel queues" and "platform-worker as-is" and never
   says Horizon; the word is the synthesis's own addition, likely carried over from `05:189`'s incorrect
   "you already run Redis + Horizon").
2. **The queue list is hardcoded, and the guard that catches that is in the other app.** The platform worker
   is `docker-compose.yml:265` / `docker-compose.dokploy.yml:348` =
   `queue:work --queue=enrichment,webhooks,default --tries=3 --sleep=3`. A new `onQueue('sourcing')` is
   **silently never consumed** — exactly the failure mode ERP `CLAUDE.md` rule 20 exists for — but rule 20's
   `HorizonQueueCoverageTest` guards `apps/api/config/horizon.php`, i.e. **the ERP**. The platform has no
   equivalent test and no Horizon config to add the queue to. This is the highest-probability silent failure
   in the whole plan, and §4b does not mention it.
3. **There is no scheduler running anywhere.** `grep -r 'schedule:work|schedule:run'` across every
   `docker-compose*.yml` and both Laravel apps returns **zero hits**. `apps/platform/routes/console.php`
   declares one command (`partners:reset-monthly-counters`, monthly) that nothing invokes. "The Laravel
   scheduler fires chasers/expiry" therefore describes a runner that would have to be built and deployed —
   new infrastructure, on the Dokploy single box, with its own single-point-of-failure and its own
   multi-tenant iteration problem. It is also the same missing piece that makes the A2 reservation leak
   unfixable by expiry.

**The reference pattern is thinner than advertised.** `EnrichBarcodeSubmissionJob` is a *single* enrichment
hop with `$tries = 3` and `$backoff = [30, 120, 600]` (`:27,30`) — it gives up after roughly twelve minutes.
`BarcodeLookupStatus` is a 7-state enum whose only wait is "human reviews it". Calling it "the only durable
multi-step pattern in the repo" is fair; presenting it as a template for a **multi-day, multi-party,
multi-leg** lifecycle is not. Nothing in it demonstrates a timer that survives days, a per-leg fan-out with
independent deadlines, a chase ladder, or versioning of in-flight instances when the state machine changes —
and §4b's own escape hatch ("revisit Temporal past ~10 states") is a state *count* threshold, which is the
wrong metric: the cost driver here is **timers and in-flight versioning**, and a 6-state machine with three
independent multi-day deadlines per leg will hurt long before a 12-state one with none.

**Other risks §4b does not mention.** (i) The Phase-0/1 work it schedules first is *ERP* work (split award,
`supplier_channel`, `supplier_products`), but the state machine is to live in the *platform* DB — so the
program crosses the ERP↔platform seam on day one, and `04` §8 records that the platform↔agent service-token
auth path **does not exist yet** (drift D2), on top of `M1` (no ERP machine credential). Two credentials must
be built before any of this is testable end to end. (ii) "Every LLM call logs into the platform's existing
`agent_runs` / `validation_log`" inherits that schema's **fail-open** budget guard
(`apps/data-acquisition/app/automotive/budget/guard.py:10,40`, per `04` §3) — directly contradicting §4c's
"per-tenant monthly cost caps **failing closed**". (iii) `04` §3 records two ghost Celery workers in
`docker-compose.yml` for services with no `app.tasks` module; the compose file is not a reliable statement of
what actually runs, so "zero new infrastructure" should be re-derived from the Dokploy state, not from compose.
(iv) A Postgres+queue state machine has no replay: when a leg's logic changes mid-flight there is no
deterministic re-execution, so the audit answer to "why did this PO get raised" becomes log archaeology —
which is a poor position for a money-touching, fiscal-adjacent surface.

**Net:** the *direction* of 4b (durable state in Laravel/Postgres for Phases 0–2, stateless Python for
reasoning) is defensible and matches `05`'s own concession. The *claim of zero cost* is not. Before this is
budgeted, the synthesis should replace "Postgres + Horizon" with "Postgres + `queue:work`", add
"install a scheduler runner" and "add a platform queue-coverage guard" as explicit line items, and drop
"zero new infrastructure".

---

## 5. Summary of what should change in `00-SYNTHESIS.md`

1. Rewrite A1's mechanism sentence: the totals calculator does **not** re-tax; the poison surfaces in
   conversions, the GL revenue+VAT legs, the VAT-declaration base, and the printed line column. Re-scope the
   legacy sweep accordingly, and execute a WO→invoice-at-19% probe before sizing (a GL posting refusal is
   plausible).
2. Move the "Four FE routes lack `ModuleGuard`" claim from §2b's "all in `02`" to `01:572`, and say
   "4 route areas / 9 routes".
3. Raise or re-justify the module-gating 4/5 against `01`'s 3/5; note that `01` contradicts itself on printing.
4. Mark "roughly 20%" as an orchestrator estimate, not a sourced figure.
5. Fix §2c's G-4 line (now merged/promotable per `LEDGER.md:248`) and disambiguate it from `LEDGER.md:119`'s
   unrelated G-4.
6. Correct §3's `ANTHROPIC_API_KEY` claim (present in four compose locations; absent only from the `erp-ml`
   service block) — and correct `03:232` upstream.
7. §4b: strike "Horizon" and "zero new infrastructure"; add the platform queue-coverage gap, the missing
   scheduler runner, the fail-open budget guard contradiction, and the missing platform↔agent credential.
8. Cite `components/organisms/Sidebar/Sidebar.tsx` (not the 2-line `components/layout/Sidebar.tsx` shim);
   fix `verticals.ts:38`→`:36-37` and `DocumentGenerationAdapter.php:200-235`→`:202-222` (file is 224 lines).
