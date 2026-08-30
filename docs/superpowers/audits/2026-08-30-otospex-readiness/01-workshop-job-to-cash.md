# Otospex readiness audit — garage job-to-cash path

> **Scope:** single-site independent garage, Tunisia (FR/AR UI, TND, TN VAT).
> **Method:** read-only. Every claim below is cited `path:line` and was read, not inferred.
> **Date:** 2026-08-30 · **Branch:** `dev` @ `6f157e924`
> **Verdict: 2.9 / 5 overall — the domain model is genuinely built; the money path is not safe to invoice on.**

Paths are relative to `/Users/houssamr/Projects/syneriva/apps/erp/`.

---

## 0. Benchmark-first baseline

What a garage owner gets on day one from Odoo (Repair + Fleet + Accounting) or a
garage-management SaaS of the Garage Hive / Autoflow / Tekmetric class, versus AutoERP today.

| # | Day-one guarantee (Odoo Repair / Tekmetric-class) | AutoERP today (`path:line`) | Verdict |
|---|---|---|---|
| 1 | Customer record with N vehicles; vehicle by plate/VIN | `Vehicle/Presentation/routes.php:23-62`, `PartnerVehiclesController` | **MEETS** |
| 2 | VIN decode auto-fills make/model/engine | `PlatformIntegration/.../VinDecodeController.php:23-31` returns a hardcoded "not yet configured" stub | **FAILS** |
| 3 | Vehicle service history on the vehicle record | Ownership + mileage only; no WO/document list — `audits/2026-07-02-cross-linking-navigability-audit.md` finding 3 | **PARTIAL** |
| 4 | Book an appointment to a bay, calendar day/week/month | `Scheduling/Presentation/routes.php:55-85`, FE `routes/index.tsx:1640-1665` | **MEETS** |
| 5 | Appointment reminder (SMS/email) to the customer | `Scheduling/Infrastructure/Jobs/DispatchAppointmentReminder.php:92-96` — `Log::info` only, no send | **FAILS** |
| 6 | Appointment → work order in one click | `AppointmentConversionService.php:47-80`; route `Scheduling/.../routes.php:79` | **MEETS** |
| 7 | WO status board: received → diagnosed → quoted → approved → in progress → done | `WorkOrderStatus.php:14-24`, `StatusMachine.php:44-95` (11 states, full adjacency map) | **MEETS** |
| 8 | Parts + labour lines with technician, estimated vs actual hours | `WorkOrderLineType.php:14-21` (8 types), `WorkOrderLine.php:46-48` (`labor_hours_estimated/actual`, `assigned_technician_profile_id`) | **MEETS** |
| 9 | Customer approves the estimate; approval is recorded with method | `CaptureApprovalCommand`, `ApprovalMethod.php:13-17` (in_person/phone/email/sms/signed_document) | **MEETS** |
| 10 | Parts reserved when the job is approved | `InventoryReservationAdapter.php:40-70`, called at `WorkOrderTransitionService.php:116` | **MEETS** |
| 11 | **Parts deducted from stock when fitted; COGS booked** | **No code path.** `tickets/2026-08-10-workshop-parts-goods-lane-gap.md`; admitted in `DocumentGenerationAdapter.php:107-125` | **FAILS** |
| 12 | Invoice totals equal the approved quote | **Broken** — `DocumentGenerationAdapter.php:218` writes tax-inclusive into the net column; `tickets/2026-08-07-wo-quote-tax-inclusive-line-total.md` | **FAILS** |
| 13 | Invoice is fiscally sealed / hash-chained | `DocumentGenerationAdapter.php:125` → `DocumentPostingService::post()` | **MEETS** |
| 14 | Take payment: cash, card, part payment, deposit | `Treasury/Presentation/routes.php:189,220,224,236,257` (split, deposit, on-account, smart allocation) | **MEETS** |
| 15 | Print a job card / WO sheet for the technician | No blade template — `resources/views/documents/templates/` has 8 templates, none for a WO | **FAILS** |
| 16 | Invoice PDF shows the vehicle (plate, VIN, mileage) | Block exists at `components/totals.blade.php:11-23` but reads the **wrong keys** — see D-3 | **FAILS** |
| 17 | Country-correct legal mentions (TN timbre line, FR statutory footer) | Timbre *computed* (`DocumentTotalsCalculator.php:47-54`) but **not rendered** on a normal invoice (`totals.blade.php:60-89`); no `legal_mentions` concept exists anywhere; no FR SIRET/RCS footer | **FAILS** |
| 18 | Service packages (oil change = parts + labour, fixed price) | `Workshop/Bundle/**` — 40 files, pricing modes, vehicle applicability | **MEETS** |
| 19 | Bulk-import existing customer vehicles / labour catalogue | `Import/Domain/Enums/ImportType.php` — no `Vehicles`, no `Services`, no `Technicians` case | **FAILS** |
| 20 | Demo dataset a salesperson can show | `DemoTenantSeeder.php` seeds vehicles/bays/appointments/technicians/6 bundles/5 WOs — but **no stock and no documents at all**, 4 of 5 WOs are line-less, not auto-wired, collides on WO numbering | **PARTIAL** |

**Baseline score: 10 MEETS / 3 PARTIAL / 7 FAILS.** The failures cluster on exactly one
axis — **everything downstream of "the job is done"**: stock, invoice arithmetic, print.

---

## 1. Customer + vehicle records — **EXISTS** · score **4 / 5**

**Backend.** `Vehicle` module is complete and hexagonal (42 files).
- Routes `Vehicle/Presentation/routes.php:22` — correct middleware stack per rule #12, gated `module:Vehicle`.
- CRUD `:23-39`; ownership history `:44-48`; mileage log `:53-57`; vehicles-by-partner `:62`.
- Fields `CreateVehicleRequest.php:33-54`: `license_plate`, `brand`, `model`, `year`, `color`, `mileage`, `vin`, `engine_code`, `fuel_type`, `transmission`. VIN validated to 17 chars with the I/O/Q exclusion (`:64-65`).
- **Enums:** `BodyType`, `FuelType`, `TransmissionType`, `MileageSource`, `OwnershipReason`.
- Ownership is a first-class timeline (`VehicleOwnership`, `VehicleOwnershipService`), not a nullable FK — better than Odoo's `res.partner` link.
- Mileage auto-captured on WO completion: `WriteMileageReadingFromWorkOrderCompleted.php:29`, registered `EventServiceProvider.php:132`.

**FE.** `features/vehicles/` — `VehicleListPage`, `VehicleDetailPage`, `VehicleForm`, plus `OwnershipHistoryTimeline`, `MileageLogList`, `TransferOwnershipModal`. Routes `routes/index.tsx:1487,1499,1511,1523`, **all four wrapped in `ModuleGuard module="Vehicle"` + `RequirePermission`**. Nav `Sidebar.tsx:318`. `VehiclesTab` also mounts inside the partner page (`PartnerDetailPage.tsx:866`).

**Tests.** 12 files under `tests/Feature/Vehicle/` + `tests/Unit/Vehicle/`. These assert business meaning, not just status codes — e.g. `VehicleCreationOpensOwnershipHistoryTest`, `WriteMileageReadingFromWorkOrderCompletedTest`, `TransferVehicleOwnershipTest`.

**i18n.** `vehicles` 47 keys and `vehicle-ownership` 75 keys — **full en/fr/ar parity**.

**Gaps.**
- **VIN decoding is a stub.** `VinDecodeController.php:23-31` returns `"VIN decoding is not yet configured for this country."` with zero DB or service access — the `#[CrossTenantRoute]` attribute says so explicitly. `VinResolverInterface` exists (`Application/Contracts/VinResolverInterface.php:13-15`) with no production implementation.
- Vehicle detail shows no work orders / invoices / service history.
- `VehicleForm.tsx:348` has one un-translated `placeholder="45000"`.
- No `vehicles` import type — a garage with 400 existing customer vehicles types them all by hand.

---

## 2. Appointment / scheduling — **EXISTS** · score **4 / 5**

**"Appointments" is NOT a module directory — but scheduling is real.** `app/Modules/Scheduling/` has 81 files: `Appointment`, `Bay`, `ScheduleConfig`, `AppointmentReminder`, a status machine, capacity calculation, week/month/day/free-slot queries, and a public storefront booking controller with captcha.

- Routes `Scheduling/Presentation/routes.php:51-86` — bays CRUD, per-location schedule config, appointments CRUD, transitions (confirm/reschedule/check-in/cancel), conversion, calendar day/week/month/free-slots.
- Conversion to WO: `AppointmentConversionService.php:47-80` — row-locked, guards status ∈ {Confirmed, CheckedIn}, guards not-already-converted, maps planned services, requires vehicle + partner.
- Bidirectional mirroring: 4 listeners (`MirrorAppointmentOnWorkOrder{Started,Completed,Cancelled,Closed}`), registered `EventServiceProvider.php:132,139`.
- FE `features/scheduling/` — `SchedulerPage`, `AppointmentDetailPage`, `CapacityReportPage`, `DayViewBoard`, `WeekViewBoard`, `AppointmentFormDrawer`. Routes `routes/index.tsx:1640,1652,1662`.
- i18n `scheduling` — 141 keys, **full en/fr/ar parity**.
- Tests: 9 files under `tests/Feature/Scheduling/` + `tests/Unit/Scheduling/AppointmentStatusMachineTest.php`.

**Gaps.**
- **Reminders never send.** `DispatchAppointmentReminder.php:92-96` logs `scheduling.reminder.dispatched` and stops; the doc-block says the Notification module "will land". `ReminderChannel` enum has `Email`/`Sms` cases with no transport. A no-show-heavy garage gets nothing.
- **The `Appointments` extra is inert** — see §5.
- `/scheduling/capacity` is routed (`routes/index.tsx:1662`) but has no sidebar entry.
- `features/scheduling/components/organisms/AvailabilityFinderPanel.tsx` is dead code — zero references in `src/`.
- Scheduling FE routes carry **no `ModuleGuard`** (only `RequirePermission`) — see D-7.
- Deferred: `StoreAppointmentRequest.php:33` validates `vehicle_id` as `['nullable','uuid']` only; the cross-tenant root-cause fix was ruled out of scope in `reviews/2026-05-04-api-workshop-cluster-codex-review.md` and no sibling ticket exists. A defense-in-depth `assertVehicleInScope` covers it downstream.

---

## 3. Work order lifecycle — **EXISTS** · score **4 / 5**

The strongest part of the vertical. `Workshop/WorkOrder/` = 3 sub-modules, ~95 files.

**State machine.** `WorkOrderStatus.php:14-24` — `received, diagnosed, quoted, approved, in_progress, paused, waiting_parts, completed, invoiced, closed, cancelled`. Adjacency in a pure, side-effect-free domain service, `StatusMachine.php:44-95`: terminal states have no outgoing edges, self-loops forbidden, re-quote allowed from `Approved`. Covered by `tests/Unit/Workshop/WorkOrder/StatusMachineTest.php`.

**Single write path.** `WorkOrderTransitionService.php:114-121` — one `match` on the target state drives every side effect inside one transaction:

```php
WorkOrderStatus::Quoted    => $quoteDocumentId  = $this->documents->generateQuote($wo),
WorkOrderStatus::Approved  => $partNeeds        = $this->reservations->reserveFor($wo),
WorkOrderStatus::Invoiced  => $invoiceDocumentId= $this->documents->generateInvoice($wo),
WorkOrderStatus::Cancelled => $this->reservations->releaseFor($wo, …),
```

**Lines.** 8 types (`WorkOrderLineType.php:14-21`): part, labor, core_charge, core_return, sublet, environmental_fee, misc_fee, bundle_header. `WorkOrderLine.php:46-48` carries `labor_hours_estimated`, `labor_hours_actual`, `assigned_technician_profile_id`. `CoreDepositStatus.php:14-17` models refundable core deposits.

**Time tracking.** Auto time entries: `CreateTimeEntryOnWorkOrderStarted`, `CloseTimeEntryOnWorkOrderCompleted`, `CloseTimeEntryOnWorkOrderPaused`, `ReopenTimeEntryOnWorkOrderResumed`. Payroll export controller exists.

**Approval.** `CaptureApprovalCommand` + `ApprovalMethod.php:13-17` + `ApprovalEvidenceData`.

**Intake.** `CreateWorkOrderRequest.php:27-38` — type, customer, vehicle, primary technician, `mileage_at_intake`, `customer_complaint`, `promised_at`, currency.

**FE.** `WorkOrderListPage`, `WorkOrderDetailPage`, `WorkOrderCreatePage` + `TransitionBar`, `StatusPill`, `ApprovalBadge`, `TotalsPanel`. Routes `routes/index.tsx:1600-1627`, **fully gated** (`ModuleGuard module="Workshop"` + `work-orders.view/.create`). Invoice back-link present at `WorkOrderDetailPage.tsx:153-158`. Nav `Sidebar.tsx:323`.

**Permissions.** 9 permissions used (`work-orders.{view,create,update,approve,assign,transition,cancel,complete,view_financials}`) — **all 9 seeded**, `RolesAndPermissionsSeeder.php:316-324`, granted per-role at `:603-605,684,729,750,787,842`. Financial redaction is real (`WorkOrderController.php:80,122,162,198` gate on `view_financials`).

**i18n.** `workshop-work-orders` 89 keys, `workshop-technicians` 131 — **full en/fr/ar parity**.

**Gaps.**
- **No stock consumption at any transition** — see §4 / B-2.
- **`InventoryReservationAdapter` has ZERO test files** (verified by grep across `tests/`). This is precisely the code holding the reservation leak (D-2).
- `WorkOrderAuthoringService` also has zero direct test references.
- Diagnostic/inspection is a free-text `diagnosis` field + `RecordDiagnosisCommand`; there is no structured multi-point inspection checklist (the Tekmetric-class differentiator).
- Workshop's nested submodules escape deptrac entirely — `tickets/2026-08-06-l6-partners-followups.md` T15: the glob is one level shallow, so **no hexagonal boundary in Workshop is enforced**.

---

## 4. Estimate → WO → invoice → payment — **PARTIAL** · score **2 / 5** ← *the blocking capability*

**What works.** `DocumentGenerationAdapter.php` implements two clean paths (`:54` quote, `:72` invoice) onto the unified `documents` table with `type` = `DocumentType::Quote|Invoice`. Both preserve `work_order_line_id` back-references (`:220`) and stamp `work_order_id` + `vehicle_id` on the header (`:143,154`). The quote is deliberately non-fiscal (`:26-29`); the invoice goes Draft → Confirmed → `DocumentPostingService::post()` which signs and writes the fiscal hash chain (`:125`). The Phase-4 sub-tolerance discount strip runs before posting (`:98-101`). Vehicle context is snapshotted onto the invoice by `WriteDocumentVehicleContextForWorkOrderInvoice` (registered `EventServiceProvider.php:97`). TN stamp duty (timbre) **is** computed on this path — `DocumentTotalsCalculator.php:47-54` delegates to `TaxCalculationService`, which handles `is_stamp_duty` (`TaxCalculationService.php:469`).

**Payment is strong.** `Treasury/Presentation/routes.php` — `POST /payments` `:189`, split payment against a document `:220`, deposits `:224`, apply-deposit `:228`, on-account `:236`, smart allocation preview/apply `:253,257`, open invoices per partner `:262`, tolerance write-off close `Document/.../routes.php:213`. Partial payments, deposits and over/under tolerance are all modelled (`PaymentAllocation`, `PaymentToleranceService`).

### B-1 — CRITICAL: the invoice charges VAT on VAT

`DocumentGenerationAdapter.php:218`:

```php
'line_total' => $wol->line_total_incl_tax,
```

`document_lines.line_total` is the **net** column by contract; the WO adapter writes the
**tax-inclusive** value. Conversions (quote → order → invoice) re-read the stored column and
tax it again. The ticket's probe: a 100.000 line at 10% discount and 20% VAT invoices at
**129.600 instead of 108.000**.

- Ticket: `docs/superpowers/tickets/2026-08-07-wo-quote-tax-inclusive-line-total.md` — severity **CRITICAL**, pre-existing, "**Recommendation: its own urgent pre-launch lane**".
- **Still open — I re-read line 218 today; unchanged.**
- **Why it survived:** no test asserts WO→invoice totals. The 7 tests touching `DocumentGenerationAdapter` cover designation, `work_order_line_id` retention, the discount strip, the zero-line refusal, the delivery exemption and fillable regressions — **none asserts the arithmetic**.
- Aggravating: `line_total` semantics are genuinely inconsistent repo-wide — `CreditNoteService.php:777` writes net (`targetNet`), `POSAccountChargeDraftService.php:92` writes gross (`bcadd($lineSubtotal, $lineVat, …)`). That ambiguity is itself a rule-22 "one surface per concept" violation and should be settled in `docs/glossary.md` before the fix lands.
- Owes a **legacy-row assessment**: any WO quote already converted has an over-stated invoice.

### B-2 — HIGH: work-order parts move no stock, produce no COGS

A WO that fits physical parts produces an invoice **and nothing else**: no delivery note,
no `stock_movements` row, no COGS. On-hand never decrements for parts fitted to vehicles.

This is admitted verbatim in production code, `DocumentGenerationAdapter.php:107-125`:

> *"the Workshop module has no stock-issuance lane at all (a WO part moves no stock and produces no delivery note anywhere) … Refusing here would block a real repair job on a fact the system has no way to record; generating a delivery note here would fabricate a goods movement that never happened."*

The fiscal delivery-compliance gate is therefore bypassed by a typed, recorded exemption —
`PostingContext::WorkOrderGeneratedInvoice` (`PostingContext.php:60-70`), honoured only when
the document also carries `work_order_id` (`DocumentPostingService.php:924`), exempting exactly
one verdict, and stamped on the audit record. It is pinned by
`tests/Feature/Document/WorkOrderInvoiceDeliveryExemptionTest.php`.

- Ticket: `docs/superpowers/tickets/2026-08-10-workshop-parts-goods-lane-gap.md` — severity HIGH (fiscal + inventory + GL), *"**The exemption is a holding action, not a resolution.**"*
- Confirmed still open by `docs/follow-ups/2026-08-10-dpa-wave3-cogs-at-exit-release-note.md:105-111`.
- Open design question for the owner (from the ticket): is the justifying document a customer delivery note, or an internal consumption/issue document against the WO? Per the document-per-action principle it must be *some* document.
- **Consequence for a garage:** inventory drifts upward forever, margin per job is unknowable, and the parts stock report is fiction after week one.

### D-2 — NEW, unticketed: stock reservations leak on every completed job

`WorkOrderTransitionService.php:118` releases reservations **only** on `Cancelled`.
There is no release on `Invoiced` or `Closed`, and no listener does it either — I grepped
every call site of `releaseFor` / `releaseForWorkOrder` across `app/`:

```
WorkOrderTransitionService.php:118   (Cancelled only)
StockReservationService.php:854      (the implementation)
```

But `ReservationSource.php:35` documents the opposite intent:

> *"WO cancellation **/ closure** releases them explicitly via `releaseForWorkOrder`."*

And `ReservationSource::WorkOrder` gets **no default expiry** (`ReservationSource.php` `getDefaultExpiry` → `null`) and the adapter passes `expiresAt: null` (`InventoryReservationAdapter.php:60`), so nothing ever reaps them.

**Net effect:** every job that completes normally leaves its parts reserved forever.
Combined with B-2 (on-hand never decrements), *available* quantity falls monotonically while
*on-hand* stays flat — the two numbers diverge without bound. A garage doing 15 jobs a week is
blocked from selling parts it physically has within a month.

**Why no test catches it.** `InventoryReservationAdapter` has zero test files. The one test that
looks like coverage, `tests/Feature/Workshop/WorkOrder/PartsNeededEventTest.php:31-58,102-120`,
**substitutes an anonymous stub for the stock-reservation port** — its own doc-block says *"fake
StockReservation without hitting StockLevel lookups"* — and then asserts only
`Event::assertDispatched(WorkOrderPartsNeeded::class)`. Nothing anywhere verifies that approving
a WO actually reserves inventory, that completing it releases or consumes it, or that oversell
is refused.

### D-4 — NEW, unticketed: WO discount residual

Recorded inside the goods-lane ticket but never given its own: the adapter derives
`discount_amount` from `discount_percent` (`DocumentGenerationAdapter.php:191-200`) while the
WO's own `line_total` was computed separately, leaving a `-6.00` residual on above-tolerance
discounts. The ticket calls it *"a second, unticketed WO totals defect."*

**Deposits.** There is no customer deposit/advance on the work order itself (`WorkOrder.php`
has no deposit field; `CoreDepositStatus` is the refundable *part-core* deposit, a different
concept). A garage taking 30% up front must use the partner-level lane —
`Partner/routes.php:33` `POST /partners/{partner}/deposits` or Treasury `POST /payments/deposit`
— and the two are not linked to the WO.

---

## 5. Module resolution: `Sales`, `Appointments`, `Fleet` — **would boot fine; two extras are inert**

**Answering the question directly: a missing module directory cannot break boot.**

`ModuleName` is a *capability-flag namespace*, not a directory registry. Gating is a pure
string comparison — `RequireModule.php:69` → `CompanyConfig::hasModule()` →
`in_array($module, $this->allEnabledModules, true)` (`CompanyConfig.php:56`). Service providers
are registered explicitly in `bootstrap/providers.php`; I confirmed there is **no** dynamic
`glob`/`scandir` module loading anywhere in `app/Providers/` or `bootstrap/`.

Comparing `app/Enums/ModuleName.php:16-39` against `ls app/Modules/`, **11 of 24 enum cases have no directory**:

```
Sales, Tables, CompositeItems, Parapharmacy, Appointments,
Fleet, Prescription, Reservation, Ecommerce, Merchandising, PurchaseBonus
```

Per-name verdict for the `mechanic` vertical:

| Name | Directory? | Gate consumers | Verdict |
|---|---|---|---|
| **`Sales`** | No | **3 live routes** — `Document/Presentation/routes.php:317,322,340` (`deliveries.view` ×2, `invoices.create`) | **Harmless.** `Sales` is an entitlement flag served by the Document/POS/Cart modules. `mechanic` lists it (`verticals.php:32`), so the gate passes. Every vertical lists it. |
| **`Appointments`** | No | **Zero.** No `module:Appointments` anywhere in `app/`. | **Inert.** Scheduling is gated on `module:Workshop` (`Scheduling/.../routes.php:52`). Selling this extra changes nothing; a `mechanic` gets full scheduling without it. |
| **`Fleet`** | No | **Zero.** Enum case + `verticals.php:17` only. | **Vapourware.** No implementation of any kind. |

**The gating bug this creates.** `tire_shop` lists `Appointments` as its only compatible extra
(`verticals.php:283`) but does **not** have `Workshop` in its defaults — so a tire shop that
*buys* Appointments still gets 403 on every scheduling route. Cross-check of all 12 verticals:

```
mechanic        Workshop=Y Vehicle=Y  extras=Appointments,Fleet
body_shop       Workshop=Y Vehicle=Y  extras=Appointments,Fleet
car_glass       Workshop=Y Vehicle=Y  extras=Appointments,Fleet
tire_shop       Workshop=n Vehicle=Y  extras=Appointments      ← broken
parts_retailer  Workshop=n Vehicle=Y  extras=Ecommerce
service_station Workshop=n Vehicle=n  extras=(none)
```

Already logged as **MED-1** in `docs/superpowers/audits/2026-06-15-vertical-module-gating-audit/02-module-gating-backend.md:131-142` — *"the `Appointments` extra does nothing, and Appointments-compatible `tire_shop` (no Workshop default) is wrongly blocked."* That audit is **report-only, never implemented**.

**Documentation drift.** `docs/architecture/vertical-module-gating.md:277-278` describes both
`Appointments` ("Booking / scheduling") and `Fleet` ("Fleet management") as shipped
`upgrade-extra`s. `docs/architecture/module-loading.md` additionally lists phantom modules
(`Communication`, `Media`, `Recipe`) that are not in `ModuleName.php` at all
(audit report 06, severity HIGH, doc dated 2025-12-30, never updated).

**Not a boot risk. It is a sales risk:** two of the three things the price list can upsell an
Otospex garage do not exist.

---

## 6. Parts catalog — **PARTIAL** · score **3 / 5**

**Automotive product model is real.** `Product/Domain/AutomotiveProductMetadata.php` and
`AutomotiveProductCrossReference.php`, migration
`database/migrations/tenant/2026_03_09_300000_create_automotive_product_metadata_tables.php`,
DTOs `AutomotiveProductMetadataData` / `AutomotiveCrossReferenceData`. OEM numbers and
cross-references are modelled.

**FE is the most built-out surface in the app.** `features/parts-catalog/` — 2 pages,
13 organisms (`VehicleNavigator`, `CategoryBrowser`, `ArticleGrid`, `CrossReferenceList`,
`VehicleCompatibilityList`, `TireDimensionSearch`, `AddToInventoryModal`), 2 zustand stores,
12 hooks. Routes `routes/index.tsx:1464,1474`. i18n `parts-catalog` 145 keys, en/fr/ar parity.

**Gaps.**
- **Depends on the Synerivia platform, not local data.** Gated `ModuleGuard module="PlatformIntegration"`; backed by `PlatformIntegration/Infrastructure/Http/PlatformHttpClient.php` and `CatalogBrowseController`. If the platform catalogue has no TN/Maghreb coverage, this screen is empty for the first customer. **Not verified in this audit — needs a platform-side answer before it is promised in a demo.**
- **No permission gate at all** on those routes — deliberate per the comment at `routes/index.tsx:1459-1463`, but it means any role in a `PlatformIntegration` tenant reaches the catalogue.
- **Automotive fields are gated on build-time env, not tenant module** — `ProductForm.tsx:95,552`, `ProductDetailPage.tsx:303` use `isOtospex` (`VITE_APP_PRODUCT`) rather than `hasModule`. Audit MED-3, open.
- `CreateProductRequest`/`UpdateProductRequest` accept `automotive_metadata.*` with **no vertical guard**; the controller silently discards it. Audit HIGH-C, open, owner-flagged as "priority hardening".
- `PartsCatalogPage.tsx:28` defaults an unknown vertical to `'mechanic'` — fail-open.
- **Seeding a garage's parts:** `ImportType` (`Import/Domain/Enums/ImportType.php:9-43`) has `Products`, `StockLevels`, `OpeningBalances`, `ProductImages`, `Parties`, `Partners`, `CompositeItems` — enough to load a parts list and its stock. Fine for parts; see D-5 for what is missing.

---

## 7. Printing / PDF — **PARTIAL** · score **2 / 5**

`resources/views/documents/templates/` has 8 templates: `invoice`, `quote`, `credit_note`,
`sales_order`, `delivery_note`, `return_note`, `purchase_order`, `purchase_rfq`. Shared
components: `header`, `parties`, `line_items`, `totals`, `payment_info`, `posting_marker`.
POS receipt (`views/pos/receipt.blade.php`) and Z-report exist. `FacturXWorkOrderInvoiceTest`
shows Factur-X (FR e-invoicing) reaches the WO invoice.

### D-3 — NEW, unticketed: the vehicle block on the invoice PDF reads the wrong keys

`DocumentPdfService.php:199` casts the stored snapshot to an object:

```php
'vehicle' => $document->vehicleContext ? (object) $document->vehicleContext->getVehicleSnapshot() : null,
```

The snapshot is written by `WriteDocumentVehicleContextForWorkOrderInvoice.php:72-80` with keys:

```php
['license_plate', 'brand', 'model', 'year', 'vin', 'color', 'fuel_type']
```

But `resources/views/documents/components/totals.blade.php:15-19` reads:

```blade
@if($vehicle->make || $vehicle->model)
    <strong>{{ $vehicle->make }} {{ $vehicle->model }}</strong><br>
@endif
@if($vehicle->registration_number)
    {{ __('Registration') }}: {{ $vehicle->registration_number }}<br>
@endif
```

`make` and `registration_number` **do not exist** in the snapshot (they are `brand` and
`license_plate`). On a `stdClass` these are undefined-property reads: PHP 8 emits a warning and
yields `null`.

The domain's own accessor agrees with the *writers*, not the blade —
`DocumentVehicleContext::getVehicleDisplayString()` reads `brand`/`model`/`license_plate`
(`DocumentVehicleContext.php:103-107`) — and a second writer,
`Vehicle/Application/Services/VehicleContextBuilder.php:48-56`, uses the same keys. So the blade
is unambiguously the wrong side.

**Effect:** the manufacturer is dropped (renders as `" Clio"`) and **the licence plate — the
single most important identifier on a garage invoice, and the one a TN customer checks —
never prints.** VIN prints correctly. This affects `invoice`, `quote`, `credit_note`,
`sales_order` and `purchase_order` (all include `components.totals`), and the block is
**hand-copied** into `delivery_note.blade.php:53-61` and `return_note.blade.php:60+` with the
same bug — a rule-22 duplicate-surface violation on top of the defect. Cheap fix, high visibility.

**And the mileage is captured then dropped.** `mileage_at_service` is persisted
(`DocumentVehicleContext.php:52`, written from `$workOrder->mileage_at_intake` at the listener
`:88`) and has a getter (`:112-118`), but `grep -rin "mileage|odometer|kilom"` over
`resources/views/` returns **zero matches**. Odometer at service is a standard line on a garage
invoice and on any warranty claim.

**Other gaps.**
- **No work-order sheet / job card PDF.** Confirmed three ways: zero matches for `work_order|job card|fiche.*travaux` across `resources/views/`; zero matches for `Pdf` across `app/Modules/Workshop/`; zero matches for `print|Pdf` across `apps/web/src/features/workshop-work-orders/`. The Workshop module has no PDF service, no print controller, no route and no frontend print button. A technician cannot be handed a job card; a customer cannot sign a printed estimate — which is how `ApprovalMethod::SignedDocument` is meant to be evidenced.
- **TN timbre is computed but never shown on the invoice.** `stamp_duty_amount` is stored and correctly calculated (`DocumentTotalsCalculator.php:47-54`, `TunisiaVatStrategy.php:96-120`), but the only blade rendering it as its own row is the **proforma** partial (`components/proforma_totals_rows.blade.php:50`). On a normal TN invoice, `components/totals.blade.php:60-89` renders Subtotal / Discount / Tax / Total / Paid / Balance Due — **no stamp-duty line**; the timbre is silently folded into `total`. Whether that satisfies TN law is an accountant's call, not a developer's, but it is at minimum a customer-trust problem: the total does not reconcile to the visible rows.
- **No `legal_mentions` concept exists anywhere.** `grep -rn "legal_mentions|legalMentions"` across `app/ resources/ database/ config/` → zero matches.
- **No country-specific template.** `DocumentPdfService::resolveTemplate():139-152` prefers `documents.country.{code}.{type}`, but `resources/views/documents/country/` **does not exist** — stated in the codebase itself at `components/posting_marker.blade.php:17-20`. TN and FR render the identical PDF.
- **No FR statutory footer.** `grep` for `siret|siren|rcs|capital|escompte|penalit` across the blades → zero. SIRET, RCS, share capital, late-payment penalty rate and the €40 recovery indemnity are all mandatory on a French invoice. Country divergence today is only Factur-X XML embedding (`DocumentPdfService::generateContent():70-98`, FR + B2B + invoice) and NF525 markers on the **POS receipt** (`views/pos/receipt.blade.php:492-512`), not on documents.
- **`matricule fiscal`** appears literally only on the withholding certificate (`taxation/withholding-certificate-pdf.blade.php:181,199`); invoices use a generic `Tax ID` label (`components/parties.blade.php:21,46-47`).
- **No PDF-rendering test exists for any document template**, which is why D-3 has survived.
- `emails/documents/fr/document.blade.php` exists; there is no `ar` variant.

Engine is `barryvdh/laravel-dompdf ^3.1` (`composer.json:11`); only two PDF routes exist
(`Document/Presentation/routes.php:451,455`).

---

## 8. Demo / seed data — **PARTIAL** · score **2 / 5**

**The screens have data; the money story has none.**

`database/seeders/DemoTenantSeeder.php` is a genuine automotive demo. Its garage tenant is
`createUnlimitedDemoTenant()` (`:130`) — slug `demo-unlimited`, `Vertical::Mechanic` (`:151`),
country TN / TND / locale `fr`, login `admin@demo.local` (`:213-221`). It seeds
(`:229-236`): an automotive catalog (`seedAutomotiveCatalog():253`, products + services + L/EA/HR/KG
units), 3 technician profiles + 2 certifications (`:456,544`), 6 named bundles
(`seedWorkshopBundles():671` — `VIDANGE-10K-ESSENCE`, `VIDANGE-10K-DIESEL`, `FREINAGE-AV`,
`REVISION-40K`, `PNEUS-REMPLACEMENT-4`, `DIAGNOSTIC-OBD`, pinned by
`tests/Feature/Workshop/Bundle/DemoSeederTest.php`), bays + schedule config + appointments some
of which are driven through `AppointmentConversionService` (`seedScheduling():1195`, `:1433-1438`),
vehicles with plates (`:987,1306,1373`), and 5 work orders across statuses (`:926-1077`).

There is no `GarageSeeder` sibling to `CoffeeShopSeeder` / `DemoPharmacySeeder` /
`ParapharmacySeeder`; `DemoTenantSeeder` fills that role.

**Gaps — and they are worse than the pharmacy vertical's.**
- **No stock.** `grep` for `StockLevel|stock_level|quantity_on_hand|Inventory` in `DemoTenantSeeder.php` → **zero hits**. The automotive products are seeded with no on-hand quantity. There is nothing to consume, reserve or watch decrement. Contrast `DatabaseSeeder.php:112,142`, which calls `StockLevelSeeder` for the accounting tenants, and `CoffeeShopSeeder`/`ParapharmacySeeder`/`DemoPharmacySeeder`, which all author stock.
- **No documents, no GL.** `grep` for `Document|Invoice|JournalEntry|Payment` → one hit, and it is a *comment* (`:1069`). The mechanic tenant opens with an empty Documents list and an empty ledger. `DemoPharmacySeeder` by contrast has a dedicated sales-invoice path (`tests/Feature/Seeders/DemoPharmacySeederSalesInvoicesTest.php`).
- **Only 1 of the 5 work orders has lines** (`:1069-1075`) — the other four show 0.000 totals on the list screen.
- **Not auto-wired.** `DatabaseSeeder::run():43-157` does not call it (nor any other demo seeder), and `config/tenancy.php:204-206` pins `tenants:run db:seed` to `DatabaseSeeder`. It needs an explicit `--option='class=Database\Seeders\DemoTenantSeeder'`. **No runbook documents that command** — every checklist in `docs/handoff/` names `DemoPharmacySeeder` or `ParapharmacySeeder` (e.g. `STAGING-RUNBOOK-first-tenant-2026-07-31.md:401`, `STAGING-DEPLOY-RUNBOOK-2026-07-28.md:145`).
- The seeder self-declares *"DO NOT run this seeder in production environments"* (`:66-76`) and hard-codes `password` for every account.
- The second garage-named tenant `pro-garage` (`:1548-1556`) gets **no** workshop/vehicle/scheduling data — company + admin only. Likewise `TenantSeeder.php:30-35`'s `demo-garage` and `DatabaseSeeder.php:55`'s "Demo Multi-Country Garage" are bare shells.
- TN only; there is no FR garage demo tenant.
- **D-6 — WO numbering collision, still live and never ticketed.** The seeder assigns `work_order_number` directly (`:1021`) without advancing `workshop_work_order_sequences`. Appointments do it correctly via the sequencer (`:1325-1326,1365`); work orders do not. Flagged in `docs/sessions/2026-04-21-autospecs-cluster-smoke-6.md`: *"`DemoTenantSeeder` does NOT populate `workshop_work_order_sequences`" … "worth a follow-up ticket"* — no such ticket exists. **The first real work order a demo tenant creates collides with a seeded one.**
- Same session records an ops trap: the event cache must be cleared on deploy or `WriteDocumentVehicleContextForWorkOrderInvoice` silently fails to register — i.e. invoices lose their vehicle context with no error.

**Net: a salesperson can show the workshop, scheduling and vehicle *screens* with plausible
data, but cannot walk the money story (parts → stock → invoice → payment → GL) end to end
without hand-entering it live in front of the prospect.**

---

## 9. Bundles / services — **EXISTS** · score **4 / 5**

**Bundles.** `Workshop/Bundle/` — 40 files. `BundlePricingMode` and `BundleComponentType`
enums, `ServiceBundleVehicleApplicability` (bundle → vehicle fitment),
`BundleExpansionService`, `BundleResolutionService`, cycle detection
(`BundleCycleException`), and `MixedVatInFixedBundleException` — a fixed-price bundle
mixing VAT rates is refused, which is the correct fiscal call. Added to a WO via
`POST work-orders/{id}/lines/bundle` (`WorkOrder/.../routes.php:35`) with informational child
lines skipped at invoicing (`DocumentGenerationAdapter.php:168`). 8 test files.
i18n `workshop-bundles` 107/107/111 keys en/fr/ar.

**Services.** `Service` module — `Service`, `ServiceCategory`, `PricingType` enum, category
tree endpoint. Routes `Service/Presentation/routes.php:20` gated `module:Workshop`.
FE routes `routes/index.tsx:1536-1589`, **fully gated** (`ModuleGuard module="Workshop"`).

**Gaps.**
- **Services has no i18n namespace of its own.** Strings live under a `services.*` prefix inside `common.json` — 54 keys in `en`, 54 in `ar`, **68 in `fr`** (the source of truth diverged). Worse: **16 `services.*` keys are undefined in all three locales** and only render via inline English `t()` defaults — `addCategory`, `categories`, `createCategory`, `editCategory`, `noCategories`, `noCategoriesDescription`, `parentCategory`, `pricing`, `sortOrder`, `details`, `metadata`, `noParent`, `categoryNamePlaceholder`, `categoryCount.{singular,plural}`. Examples: `ServiceCategoryListPage.tsx:228,240,260`, `ServiceForm.tsx:215`. **A French garage owner sees English on the service-catalogue screens.** `ServiceCategoryListPage.tsx:231` also pluralises by ternary, which is wrong for Arabic's six plural forms.
- `ServiceForm.tsx` calls `t('settings:company.currencies.TND')`, but `ar/settings.json` has no `company.currencies` subtree.
- `features/services/` is flat (no `pages/`/`components/`), unlike every other workshop feature.
- Bundle applicability XOR validation is `⚠️ PARTIALLY FIXED` pending PM confirmation (`docs/sessions/2026-04-21-autospecs-cluster-smoke-6.md`, 🔴-3).

---

## 10. Known defects on record

Everything below was cross-checked against current code where a code claim was made.

| # | Defect | Source | Status |
|---|---|---|---|
| 1 | WO quote persists tax-inclusive `line_total` → VAT-on-VAT | `tickets/2026-08-07-wo-quote-tax-inclusive-line-total.md` | **OPEN — `DocumentGenerationAdapter.php:218` unchanged today** |
| 2 | WO parts have no goods lane; fiscal exemption is a holding action | `tickets/2026-08-10-workshop-parts-goods-lane-gap.md` | **OPEN — `PostingContext::WorkOrderGeneratedInvoice` still live** |
| 3 | WO discount residual (`-6.00`) | same ticket, "Blocked-adjacent" | **OPEN, never ticketed** |
| 4 | Scheduling gated on `Workshop` not `Appointments`; extra inert; `tire_shop` wrongly blocked | `audits/2026-06-15-vertical-module-gating-audit/` MED-1 | **OPEN (audit is report-only)** |
| 5 | `Fleet` extra has zero implementation | (undocumented; found here) | **OPEN** |
| 6 | Partner-delete guard blind to `workshop_work_orders`, `scheduling_appointments`, `vehicles` | `tickets/2026-08-06-l6-partners-followups.md` T1 | **OPEN** |
| 7 | Workshop submodules invisible to deptrac — no hexagonal enforcement | same, T15 | **OPEN** |
| 8 | Missing indexes on `vehicles.partner_id`, `workshop_work_order_lines.core_deposit_partner_id` | same | **OPEN** |
| 9 | `vehicle_id` unscoped at `StoreAppointmentRequest.php:33` (root cause deferred) | `reviews/2026-05-04-api-workshop-cluster-codex-review.md` | **OPEN, no sibling ticket** |
| 10 | Automotive product fields gated on build-time env not tenant module | audit `04-frontend-gating-web.md` MED-3 | **OPEN** |
| 11 | `automotive_metadata.*` accepted with no vertical guard, silently discarded | audit HIGH-C | **OPEN, owner-flagged** |
| 12 | `VehicleDetailPage` has no WO / document / service-history links | `audits/2026-07-02-cross-linking-navigability-audit.md` | **OPEN (~1-1.5d)** |
| 13 | `DemoTenantSeeder` doesn't advance `workshop_work_order_sequences` → number collision | `sessions/2026-04-21-autospecs-cluster-smoke-6.md` | **OPEN, never ticketed — re-verified at `:1021` today** |
| 14 | Zero-line WO invoice error swallowed by the UI; UUID-leaking form labels | `sessions/2026-04-22-autospecs-playwright-e2e.md` (**NEEDS-FIXES**) | Backend refusal now exists (`WorkOrderTransitionService.php:102`) + `InvoiceZeroLineWorkOrderFailsTest`; FE handling unverified |
| 15 | `module-loading.md` lists phantom modules + wrong Otospex extras matrix | audit report 06 (HIGH) | **OPEN** |
| 16 | Event cache must be cleared on deploy or vehicle context silently unregisters | `sessions/2026-04-21-autospecs-cluster-smoke-6.md` | **OPEN ops trap** |
| — | **NEW (this audit):** reservations never released on Invoiced/Closed | §4 D-2 | **OPEN, unticketed** |
| — | **NEW (this audit):** invoice PDF vehicle block reads `make`/`registration_number` | §7 D-3 | **OPEN, unticketed** |
| — | **NEW (this audit):** no automated journey covers the mechanic vertical | §11 D-1 | **OPEN, unticketed** |

**`docs/PRODUCT-BIBLE.md` (2026-07-04, DRAFT) is candid about this.** §5.1 ranks automotive
**priority 2**, not 1 — *"France client waiting; needs e-facture (France); Workshop + Vehicle
modules substantial"*. §4's flow table says of Workshop: **`not re-traced`**. The Bible has
never traced this flow, so its own "substantial" claim was unverified until now. No workshop
item appears in its §8 technical-debt register. `docs/otospex/ROADMAP.md` is `Status: Planning`,
its §6.2 explicitly superseded, and its Go-Live checklist §10 entirely unticked.
`docs/new_docs/COMPLETE-MODULE-REGISTRY.md` claims Workshop `✅ Complete` — **unreliable, ignore it.**

---

## 11. Process gates — the finding that reframes all the others

### D-1 — NEW: the promotion gate cannot be run for this vertical

Per CLAUDE.md rule 22, a green run of `scripts/campaign-onboarding.sh` is a **promotion
precondition**. `docs/qa/ONBOARDING-CAMPAIGN.md:22`:

> *"The campaign registers the **Parapharmacy** vertical … with **Tunisia** fixtures … **other verticals and countries are unsupported** until the fixtures are parameterised."*

Its legs L0a–L9 are POS/retail (sale receipt, shift, cash count, Z-report). **No leg touches a
vehicle, a work order, an appointment or a WO invoice.** So:

- the mechanic vertical has **zero automated journey coverage**;
- the standing promotion gate is structurally incapable of catching a garage regression;
- B-1 (VAT-on-VAT) has been shippable for 23 days precisely because nothing exercises it.

I also found no e2e or Playwright artefact covering the workshop path — a `grep -rl` for
`work.order|workshop|vehicle` across `docs/e2e/`, `docs/qa/` and `scripts/campaign-onboarding.sh`
returns only three unrelated QA docs.

### Test coverage: broad and rigorous per-seam, blind at the money seam

The workshop path has **61 backend test files** and **44 `apps/web` vitest specs** — more than
most of the codebase. Much of it is genuinely good: `tests/Unit/Workshop/WorkOrder/TotalsCalculatorTest.php`
(TND 3-dp, bcmath truncation, negative core returns), `StatusMachineTest.php`,
`tests/Feature/Workshop/IngressPrecisionTest.php` (rejects 5-dp qty / 4-dp price at the boundary),
`PayrollExportPrecisionTest.php`, the Scheduling suite (conflict detection, capacity window
subtraction, mirror idempotency), and `WorkshopHttpTenantIsolationMatrixTest.php`.

But the coverage is **layered per seam and never walks the chain**:

1. **No test asserts that invoicing a work order posts a journal entry.** `InvoiceZeroLineWorkOrderFailsTest.php` covers the refusal path thoroughly; its success case stops at "it didn't throw".
2. **No test asserts that a work order moves stock** — see D-2 above; the one candidate stubs the port out.
3. **No end-to-end WO → invoice → GL/stock test exists.** `grep -rln "journal_entr|JournalEntry|stock_movement|StockMovement|GeneralLedger"` across `tests/Feature/{Workshop,Scheduling,Vehicle}/` matches three files, none for ledger assertions.
4. **Totals are verified only in isolation** — nothing checks they survive into a posted document. That is exactly the hole blocker #1 fell through.
5. **No PDF-rendering test for any template**, which is why D-3 (missing plate) is invisible.

Tests that assert HTTP status without business meaning (status-assertions : semantic-assertions):
`tests/Feature/Workshop/Bundle/PermissionsTest.php` (7 : 0 — never checks *which* bundles came
back or that a denied write was not persisted), `Workshop/Technician/TechnicianProfileControllerTest.php`
(7 : 0 — `test_show_returns_profile_with_pay_and_pii_for_manager` never asserts the pay/PII values
are present), `Vehicle/Mileage/LogMileageEndpointTest.php` (3 : 0),
`Vehicle/Ownership/TransferVehicleOwnershipEndpointTest.php` (4 : 0),
`Vehicle/Ownership/PartnerVehiclesListTest.php` (1 : 0), `Vehicle/DeleteVehicleTest.php` (4 : 1).
In the Vehicle cases the service-level siblings *do* check state, so it is the endpoint layer
that is uncovered rather than the domain. The Scheduling storefront rate-limit/captcha tests are
status-only by nature and that is defensible.

On the FE side, a large share of the 44 specs (`*.tenantScope.test.tsx`, `*.canonical.test.tsx`)
are architectural conformance tests — tenant-header plumbing and page-shell conventions — rather
than workshop business behaviour.

### Glossary — rule-22 "one surface per concept" violation

`docs/glossary.md` defines 36 nouns: Tenant, Company, Location, Membership, Party, Customer,
Supplier, Contact, Party balance, Product, SKU, Unit, Category/Brand/Attribute, Lot, Stock
level, Account, Purpose, Tax configuration, Payment method, Repository, Payment, Allocation,
Opening batch, Historical document, Journal entry, Document, Draft, Document number, Terminal,
Shift, Receipt, Import type, and the four process nouns.

**Not one automotive noun is defined.** No *Work Order*, *Vehicle*, *Appointment*, *Bay*,
*Technician*, *Service*, *Bundle*, *Core charge*. The only Otospex mention is a parenthetical
under **Contact** ("driver — Otospex fleet"). Rule 22 requires every noun to have one table,
one primary write path and one operator surface declared there. That is exactly the discipline
whose absence let `line_total` mean *net* in `CreditNoteService.php:777` and *gross* in
`POSAccountChargeDraftService.php:92` and `DocumentGenerationAdapter.php:218` — which **is**
blocker B-1.

Also missing per rule 22: no benchmark-first baseline table exists for any workshop spec
(this document is the first).

---

## 12. Readiness scores

Single-site independent garage, Tunisia, FR/AR UI, TND, TN VAT.

| # | Capability | Score | One-line justification |
|---|---|---|---|
| 1 | Customer + vehicle records | **4 / 5** | Complete CRUD, ownership timeline, mileage; VIN decode is a stub; no vehicle import |
| 2 | Appointment / scheduling | **4 / 5** | Real 81-file module with bays + calendar + conversion; reminders never send |
| 3 | Work order lifecycle | **4 / 5** | 11-state machine, 8 line types, approvals, time tracking; no inspection checklist |
| 4 | **Estimate → invoice → payment** | **2 / 5** | **Invoice arithmetic is wrong; stock never moves; reservations leak.** Payment leg itself is strong |
| 5 | Module resolution / gating | **3 / 5** | Boots correctly; `Sales` is a harmless flag; `Appointments` + `Fleet` are inert; 4 FE routes ungated |
| 6 | Parts catalog | **3 / 5** | Rich FE + OEM cross-refs, but platform-dependent and coverage unverified for TN |
| 7 | Printing / PDF | **1 / 5** | No job card; plate and mileage absent from the invoice; TN timbre computed but never shown; no country template; no FR statutory footer; no `legal_mentions` concept |
| 8 | Demo / seed data | **2 / 5** | Screens have data, but zero stock and zero documents — the money story cannot be demoed; manual to run; WO numbering collides |
| 9 | Bundles / services | **4 / 5** | Excellent bundle engine; services i18n is broken in FR/AR |
| 10 | Known-defect hygiene | **2 / 5** | Two critical tickets open ~3 weeks; 5 new unticketed defects found here |
| — | **Overall** | **2.9 / 5** | **Screen-demo-ready. Not invoice-ready, and not money-demo-ready.** |

**The shape of the problem.** This is not a half-built vertical — the domain model
(states, enums, events, hexagonal boundaries, permissions, FR/AR i18n on the five core
namespaces) is better than most of the rest of the codebase. Everything from *customer walks in*
to *job is finished* scores 4/5. Everything from *now bill them* down scores 2/5, and it fails
in ways that produce **wrong numbers silently** rather than errors. That is the worst failure
mode for a first customer, and it is a direct consequence of D-1: no automated journey has ever
run this path.

---

## 13. Top blockers, by severity

| # | Blocker | Sev | Size | Module |
|---|---|---|---|---|
| **1** | **VAT charged on VAT.** `DocumentGenerationAdapter.php:218` writes tax-inclusive into the net `line_total`; a 100.000 line at 10%/20% invoices at 129.600 vs 108.000. Ticketed 2026-08-07 as CRITICAL, still open. **Fix is ~1 line; the work is the regression test, the `line_total` semantics ruling in `docs/glossary.md`, and the legacy-row assessment.** | 🔴 P0 | **S** (fix) / **M** (with test + legacy sweep) | Workshop/WorkOrder + Document |
| **2** | **Parts fitted to vehicles never leave stock and book no COGS.** Needs a real goods lane (owner ruling required: customer delivery note vs internal WO consumption document), after which the `PostingContext::WorkOrderGeneratedInvoice` exemption is deleted. Until then inventory is fiction and per-job margin is unknowable. | 🔴 P0 | **L** | Workshop/WorkOrder + Inventory + Accounting |
| **3** | **Reservations leak on every completed job.** `WorkOrderTransitionService.php:118` releases only on `Cancelled`; no expiry (`InventoryReservationAdapter.php:60`); `ReservationSource.php:35` documents the opposite. Available stock falls monotonically. `InventoryReservationAdapter` has **zero tests**. *New, unticketed.* | 🔴 P0 | **S** | Workshop/WorkOrder + Inventory |
| **4** | **No automated journey covers the mechanic vertical.** The promotion-gate campaign is Parapharmacy-only (`docs/qa/ONBOARDING-CAMPAIGN.md:22`); no leg touches a vehicle or work order. This is *why* #1 shipped. Needs a garage campaign: customer → vehicle → appointment → WO → quote → approve → invoice → payment, asserting money as scale-3 strings. | 🔴 P0 | **M** | QA / campaign |
| **5** | **The invoice PDF is not garage-usable.** Three defects in one file: the **licence plate never prints** and the manufacturer is dropped — `totals.blade.php:15-19` reads `$vehicle->make`/`->registration_number` while both writers store `brand`/`license_plate` (`WriteDocumentVehicleContextForWorkOrderInvoice.php:72-80`, `VehicleContextBuilder.php:48-56`); **mileage is captured and never rendered** (zero `mileage` matches in `resources/views/`); and the block is hand-copied into `delivery_note.blade.php:53-61` and `return_note.blade.php:60+`. No PDF-rendering test exists for any template. *New, unticketed.* | 🟠 P1 | **S** | Document (blades) |
| **6** | **TN stamp duty is charged but never shown on the invoice.** Computed correctly (`DocumentTotalsCalculator.php:47-54`) and rendered only on the **proforma** partial (`proforma_totals_rows.blade.php:50`); the normal invoice totals block (`totals.blade.php:60-89`) has no timbre row, so it is silently folded into `total` and the visible rows do not reconcile. Compounded by: no `legal_mentions` concept anywhere, no `documents/country/` templates (TN and FR render identically), and no FR statutory footer (SIRET/RCS/capital/penalty). **Needs a TN accountant's ruling before it is sized.** | 🟠 P1 | **S** (timbre row) / **M** (country templates) | Document + Taxation |
| **7** | **No work-order sheet / job card PDF.** Confirmed by three independent greps: no WO template in `resources/views`, no `Pdf` anywhere in `app/Modules/Workshop/`, no print button in `features/workshop-work-orders/`. A technician gets no printed job card; a customer cannot sign a printed estimate, which is how `ApprovalMethod::SignedDocument` is supposed to be evidenced. Table stakes for every garage SaaS in the benchmark. | 🟠 P1 | **M** | Workshop/WorkOrder + Document |
| **8** | **The demo cannot show the money story.** `DemoTenantSeeder` seeds **no stock and no documents** for the mechanic tenant (zero `StockLevel` hits; the only `Invoice` hit is a comment at `:1069`), 4 of its 5 WOs are line-less, it is not wired into `DatabaseSeeder`, no runbook documents its invocation, and `:1021` sets `work_order_number` without advancing `workshop_work_order_sequences` so the first real WO collides. Plus the deploy needs an event-cache clear or invoices silently lose vehicle context. | 🟠 P1 | **M** | Workshop/WorkOrder (seeder) + docs |
| **9** | **Service-catalogue screens render English to FR/AR users.** No `services` i18n namespace; 16 `services.*` keys undefined in all three locales, surviving on inline `t()` English defaults (`ServiceCategoryListPage.tsx:228,240,260`); `fr` has 68 keys vs 54 in en/ar. Also `ar/crm.json` does not exist and `i18n.ts:428` silently serves the English bundle to Arabic. Blocks a Tunisian FR/AR demo. | 🟠 P1 | **S** | web/services + i18n |
| **10** | **Appointment reminders never send** (`DispatchAppointmentReminder.php:92-96` logs only) and the **`Appointments` extra is inert** while **`Fleet` has no implementation** — yet both are on the price list (`verticals.php:17`) and described as shipped (`vertical-module-gating.md:277-278`). `tire_shop` can buy Appointments and still get 403. | 🟠 P1 | **M** (reminders) / **S** (regate) | Scheduling + Notification + config |
| **11** | **Four FE routes are URL-reachable without their module.** `scheduling`, `workshop/bundles`, `workshop/technicians`, `workshop/payroll-exports` carry `RequirePermission` but **no `ModuleGuard`**, while their sidebar entries declare `module: 'Workshop'` — nav-hidden, still reachable. Violates rule #12's both-layers requirement. Related: `parts-catalog` has no permission gate; Workshop submodules escape deptrac entirely (T15). | 🟡 P2 | **S** | web/routes + deptrac |

### Recommended sequencing

- **Before any customer sees an invoice:** #1, #3, #5 — all size **S**, all in the money path. #1 and #3 must land *with tests*, since the absent test is the root cause in both cases.
- **Before the first demo:** #8 and #9 (**M**/**S**) — otherwise the demo shows English service screens, has no stock or invoices to walk the money story with, and collides on the first work order the prospect creates.
- **Owner rulings needed before sizing:** #2 (delivery note vs internal consumption document?), #6 (does TN law require a visible timbre line?), and the `line_total` net-vs-gross semantics for #1 — all three belong in `docs/glossary.md` per rule 22.
- **Before go-live:** #2 (**L**) and #4 (**M**). #4 is what stops the next #1.
- **#7** is what a garage owner asks for in the first ten minutes of a demo. Size it early even if it lands after go-live.

### One-line summary for the owner

The Otospex domain model is real and well-built — intake through job completion scores 4/5 and
is ahead of where the PRODUCT-BIBLE assumes it is (§4 there still says Workshop is
*"not re-traced"*). But **no automated journey has ever run this vertical**, and in that blind
spot the billing path acquired three defects that produce wrong numbers silently: VAT charged on
VAT, parts that never leave stock, and reservations that never release. Fix #1/#3/#5 (all small)
before an invoice reaches a customer; fix #4 so the next one is caught.

---

*Read-only audit. No source file was modified. Every `path:line` was opened and read.*
