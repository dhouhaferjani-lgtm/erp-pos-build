# Adversarial Merge-Gate Register — M3 (View B: global "To bill" work queue), round 1

**Range reviewed:** `60df88a01..87f4b75db` (M3 commits `aed019e3b..87f4b75db`), against the M3 row of `docs/handoff/CODEX-DISPATCH-dn-consolidation-build-2026-08-12.md:114`, spec §3.2 (`SPEC-dn-consolidation-billing-2026-08-11.md:643-706`), and the amending `docs/handoff/progress/dn-consolidation-build.progress.yaml:106-113` (M3 title, and the M2-round-2 P2 reassigned to M3).
**Lenses:** frontend-conventions, tenancy-authz, treasury, general. **Treasury applies only indirectly** — M3 adds no GL/payment/partial-write path of its own; it reuses M1's atomic consolidation endpoint. Its treasury exposure is finding 2 (a fiscal refusal that reaches the operator as nothing at all).

---

### 1. **P1 — CONFIRMED — `apps/web/src/features/documents/api/deliveryNotes.ts:211-223`** — both new queue endpoints are read through `apiGet`, which strips `meta` and `summary`; `/sales/to-bill` throws on its first successful response

`apiGet` returns `response.data.data` (`apps/web/src/lib/api.ts:269-272`). The two new endpoints return a **top-level** `{data, meta, summary}` body — `response()->json($this->uninvoicedDeliveryNoteService->getToBillSummary(...))` (`apps/api/.../DeliveryNoteController.php:169-178`), proven by the backend's own top-level assertions `assertJsonPath('meta.current_page', 1)` / `assertJsonPath('summary.grand_total', …)` (`apps/api/tests/Feature/Document/DeliveryNoteToBillQueueTest.php:134-140`). There is no response-wrapping middleware (`apps/api/bootstrap/app.php` registers aliases only) and the axios success interceptor is the identity (`apps/web/src/lib/api.ts:186-187`).

So `getToBillQueue()` resolves to `ToBillPartnerGroup[]` while its signature claims `ToBillQueueResponse` — tsc cannot see it because the lie is in the `apiGet<ToBillQueueResponse>` type argument.

**Failure scenario:** a clerk with Sales + `deliveries.view` opens `/sales/to-bill`. The query resolves. `ToBillPage.tsx:242` evaluates `query.data?.summary.buckets.find(...)`; `query.data` is an array (truthy), `.summary` is `undefined`, `.buckets` throws `TypeError`. The page white-screens on **every** load, including the zero-rows case. Expanding a group hits the same at `ToBillPage.tsx:165` (`rows.data.meta.last_page`), and "Create invoice" hits it at `deliveryNotes.ts:230-234` (`first.meta.last_page`). This is a direct violation of `docs/conventions/01-API-RESPONSES.md` — and the same file already does it correctly for the M2 endpoint at `deliveryNotes.ts:185-196` (`api.get` + `response.data`).

---

### 2. **P1 — CONFIRMED — `apps/web/src/features/documents/to-bill/ToBillPage.tsx:218-230`** — OI-8 ratified conditions 1–4 are absent on the surface M3 introduces; an attributed refusal is rendered as *nothing*

Brief §3 condition 1 binds "an inline error region **on the page that made the attempt**". `useConsolidateDeliveryNotes` deliberately suppresses the toast for attributed refusals — `if (parseDeliveryNoteBillingRefusal(error) === null) { toast.error(...) }` (`apps/web/src/features/documents/hooks/useDeliveryNotes.ts:228-233`, added by M1 `c517635cb`) precisely because the consuming component is expected to render the attribution inline (as `DeliveryNoteConsolidation.tsx` and `PartnerDeliveryNotesTab.tsx` do). `ToBillPage` holds no refusal state, renders no inline region, and `confirmCreate` has no `catch` — only a `finally` that clears the spinner. `ConfirmDialog` does not auto-close and has no error slot (`apps/web/src/components/ui/ConfirmDialog.tsx:94-99`).

**Failure scenario:** the SO lane bills DN-0042 a second before the clerk clicks "Create invoice" on the To-bill queue. The server returns the attributed 422 `DELIVERY_NOTE_ALREADY_INVOICED` with `details.documents[]`. The toast is suppressed; the promise rejects into an unhandled rejection; the dialog stays open, unchanged, with the spinner stopped. The operator sees **no error, no named lost DN, no taking invoice, no "no invoice was created, no number used", no recovery action** — and cannot distinguish "refused" from "nothing happened". Conditions 1, 2, 3 and 4 all fail on this surface, and the wave's governing principle ("nothing drops silently") fails with them. No M3 test exercises the error path (`ToBillPage.test.tsx:115-215` has no refusal case).

---

### 3. **P2 — CONFIRMED — `apps/api/.../DeliveryNoteController.php:234` vs `ToBillPage.tsx:275-280`** — the partner-search box 422s on its own first keystroke

Backend validates `partner_search` as `min:2` (asserted deliberately: `DeliveryNoteToBillQueueTest.php:226` expects 422 for `partner_search=a`). The FE sends the raw field value on every change with no debounce and no minimum: `changeFilter(setPartnerSearch, event.target.value)` → `partner_search: params.partnerSearch || undefined` (`deliveryNotes.ts:202`).

**Failure scenario:** the clerk types "a" as the first letter of "Atlas". The query key changes, the request goes out with `partner_search=a`, the server 422s, `query.error` becomes truthy, and `ToBillPage.tsx:317-318` replaces the entire queue with `QueryError` ("Unable to load the to-bill queue"). Typing continues to fix it, so it reads as a random flash of failure. Secondary: one request per keystroke.

---

### 4. **P2 — CONFIRMED — `ToBillPage.tsx:252` and `:265`** — the aging strip and grand-total tile label **partner-group** counts as "delivery notes"

`summary.buckets[].count` is incremented once per partner group (`UninvoicedDeliveryNoteService.php:185-190`) and `grand_count` is `$totalGroups` (`:192, :215`) — consistent with the spec, whose `meta.total` and `summary.grand_count` are both 61 for 61 groups (`SPEC…:686-691`). The FE renders both through `t('toBill.groupCount', {count})`, whose string is `"{{count}} delivery notes"` / `"{{count}} bons de livraison"` (`apps/web/src/locales/{en,fr}/sales.json`).

**Failure scenario:** 4 customers hold 37 un-invoiced DNs, all 90+ days old. The 90+ tile reads "4 delivery notes" and the grand tile reads "4 delivery notes" against a total of 37 documents. On a billing-completeness screen whose whole purpose is answering *"am I done?"*, the headline count is off by an order of magnitude and looks authoritative. No test asserts either string.

---

### 5. **P2 — CONFIRMED — `apps/api/.../DeliveryNoteController.php:231, 254-256`** — a malformed `location_id` returns 500, not the specced 422

Validation is `['nullable', 'string']` — not `uuid`. Any non-`all` value falls to `Location::query()->where('id', $locationId)` (`:254-256`), and `locations.id` is a PG `uuid` column (`apps/api/database/migrations/tenant/2025_11_30_105000_create_locations_table.php:24`).

**Failure scenario:** `GET /api/v1/delivery-notes/uninvoiced?location_id=foo` → PostgreSQL `22P02 invalid input syntax for type uuid` → 500 with a driver message. Spec B-1 is explicit: *"must exist and belong to the company; **422 otherwise**"* (`SPEC…:672`). The test that claims to cover this (`test_location_and_filter_validation_never_silently_widens_the_queue`) only exercises a well-formed foreign-company UUID. Same class as the repo's own documented "validate `Str::isUuid()` before `where('uuid', …)` or it 500s" pitfall; the sibling route protects itself with `->whereUuid('partner')` (`routes.php:279`).

---

### 6. **P2 — CONFIRMED (mechanism) / PLAUSIBLE (blast radius) — `useDeliveryNotes.ts:125`, `UninvoicedDeliveryNoteService.php:310, 331-333`, `ToBillPage.tsx:319-326`** — the "everywhere" queue has three silent exclusions and no escape hatch

The spec's stated emphasis is *"what is left to bill, **everywhere**, oldest first"* (`SPEC…:645`). As built:
- **`location_id=all` is unreachable.** The backend implements it (`DeliveryNoteController.php:244-252`) but nothing in the FE ever sends it — `toBillApiParams` only ever forwards `currentLocationId` (`deliveryNotes.ts:200`), and the store always resolves that to a single location (`apps/web/src/stores/locationStore.ts:82-89`). An unrestricted user has no way to see the whole company's backlog.
- **No active location ⇒ blank page.** `enabled: … && params.locationId !== null` (`useDeliveryNotes.ts:125`) disables the query; `isLoading` is then false, `query.data` is `undefined`, so `query.data?.data.length === 0` is false and the empty-state branch is skipped — the page renders an empty div with no message. `LocationContext`'s own docblock names "central invoicing" (no location) as a supported shape (`LocationContext.php:20`).
- **`documents.location_id` is nullable with `onDelete('set null')`** (`apps/api/database/migrations/tenant/2025_11_30_130000_add_company_id_to_existing_tables.php:68-70`), and non-company-currency DNs are dropped outright by `->where('documents.currency', $company->currency)` (`UninvoicedDeliveryNoteService.php:310`) with no on-screen disclosure.

**Failure scenario:** a location is deleted; its un-billed DNs get `location_id = NULL` and disappear from every reachable variant of the queue. Nothing in the UI says work was filtered out, so the clerk reads "Nothing left to bill" and stops. The handback's "Location is required and validated against the active membership" also mis-describes the code — the param is `nullable` and silently falls back to the company **default** location via `resolveLocationId(null, …)` (`LocationContext.php:128-150`).

---

### 7. **P3 — CONFIRMED — `ToBillPage.test.tsx:14-18`, `deliveryNotes.test.ts:12-19`, `hooks/__tests__/toBillQueueTenantScope.test.tsx:11-14`** — the transport seam is mocked at every level, which is why finding 1 shipped

`ToBillPage.test.tsx` mocks `../hooks/useDeliveryNotes`; the tenant-scope test mocks `getToBillQueue`; the API test mocks `apiGet` itself and only asserts the URL/params. No test ever runs a real response body through `apiGet` into the page. 74/74 green is therefore compatible with a page that crashes on every load.

### 8. **P3 — CONFIRMED — `UninvoicedDeliveryNoteService.php:124-194` and `deliveryNotes.ts:225-239`** — group pagination is in-memory, and "bounded parallel" is inaccurate

`getToBillSummary` materializes **every** partner group (plus a `whereIn` over every partner id), computes the summary, then slices the page in PHP (`:194`) — `meta.total` is honest but the DB does the work for all groups on every page request. `getAllToBillPartnerRows` fires `last_page - 1` requests with `Promise.all` and no concurrency cap; the handback calls this "bounded parallel page requests" — 100 is the page size, not a bound on fan-out.

### 9. **P3 — CONFIRMED — `DeliveryNoteController.php:248, 261`** — the two new validation messages are hardcoded English, not translated.

### 10. **P3 — CONFIRMED — `DeliveryNoteToBillQueueTest.php:174-199`** — the specced reconciliation test is literal-matched, not compared. Spec B-2 requires *"a test asserts the group's row count and summed total **equal the B-1 summary row** for the same filters"*; the build asserts `summary.count = 2 / total = '150.000'` in the B-2 test and the same literals in a separate B-1 test. It passes today and would keep passing if the two endpoints drifted apart.

### 11. **P3 — CONFIRMED — `ToBillPage.tsx:71, 120`** — `new Date('YYYY-MM-DD').toLocaleDateString()` parses as UTC midnight, rendering the previous day west of UTC. Shared with the M2 pattern at `PartnerDeliveryNotesTab.tsx:82`, so not an M3 regression — recorded for M5.

### 12. **P3 — CONFIRMED — `UninvoicedDeliveryNoteService.php:150-152`** — an unresolvable partner throws `LogicException` → 500. Narrow (needs a partner deleted between the two queries) but it is a 500 on a read path.

---

## Bypasses attempted that FAILED (i.e. I tried to exonerate the code and could not)

| Attempt | Result |
|---|---|
| Looked for a response-wrapping middleware/interceptor that would make `apiGet` correct for finding 1 | None. `bootstrap/app.php` registers aliases only; `api.ts:186-187` is the identity on success |
| Checked whether the backend actually emits a second envelope | It does not — `DeliveryNoteToBillQueueTest.php:124-140` and `DeliveryNoteConsolidationAccessControlTest.php:87-89` assert `data.0.*` / `meta.*` / `summary.*` at the top level |
| Checked whether some fallback toast still surfaces the refusal for finding 2 | Explicitly suppressed at `useDeliveryNotes.ts:229-232`; `ConfirmDialog` neither closes nor renders errors |
| Checked whether `Query\Builder::sum()` leaks a float into `bcformatStrict` (Rule 19) | It returns the raw aggregate; `numericAggregate` is not used by `sum()` (`vendor/…/Query/Builder.php:3963-3968`). **No Rule 19 violation anywhere in M3** — every money path is `bcadd`/`bcformatStrict` at the resolver scale from the company currency, and the FE has no `parseFloat`/`Number()` on money |
| Checked whether the new sidebar entry is missing its module gate (rule 12) | No finding — the parent `sales` group carries `module: 'Sales'` and children are filtered under it (`Sidebar.tsx:162, 455-462`) |
| Checked whether the four independent per-layer module-gating tests exist for the new route | They do: API-with-Sales-disabled on **both** routes (`DeliveryNoteConsolidationAccessControlTest.php:107-112`), FE route under `ModuleGuard` for an all-permission admin (`ToBillRoute.gates.test.tsx:30`), FE action hidden (`ToBillPage.test.tsx:188`) |
| Checked whether the M1 access-control assertions were weakened when adapted to the roll-up shape | They were correctly re-pointed to the group shape, and two new `uninvoicedForPartner` assertions were added (`2e767bbac`) |
| Checked red-first discipline | Honoured: `aed019e3b` and `069912e62` are tests-only commits preceding `2e767bbac` / `aa4332252` |
| Checked tenant/company/partner scoping and constructor injection | Clean — `forTenant()` + `company_id` on every query, partner `findOrFail` scoped by tenant+company+customer type, no `app()` in production code, no migrations and no new queues in M3 |
| Checked the M2-round-2 close-before-merge P2 (mixed-currency batch) | **Closed** with a test (`PartnerDeliveryNotesTab.tsx:127-134, 301-308`; `PartnerDeliveryNotesTab.test.tsx:270-285`) |
| Checked en+fr parity for every new string | Complete — zero one-sided keys across `sales.json` and `finance.json` |

---

## Disposition

Findings 1 and 2 are P1 and block: as committed at `87f4b75db`, `/sales/to-bill` — the entire M3 deliverable — throws on its first successful response, and the one failure mode the wave's closed owner ruling (OI-8) exists to govern reaches the operator as silence. Findings 3–6 are P2, close-before-merge. Findings 7–12 are P3 and may ship recorded.

VERDICT: CHANGES-REQUIRED
