I reviewed `60df88a01..HEAD` (M3 = `aed019e3b..872e2a8e0`), holding the build to the M3 row of the brief (`CODEX-DISPATCH-…-2026-08-12.md:114`), spec §3.2 / §6.3, and the amending `docs/handoff/progress/dn-consolidation-build.progress.yaml` (M3 title + the reassigned M2-round-2 P2 + the `preflight_policy` amendment). Lenses: **frontend-conventions, tenancy-authz, treasury, general** — treasury applies only indirectly (M3 adds no GL/payment/partial-write path; it consumes M1's atomic endpoint), and its exposure here is the OI-8 refusal surface, which is now built.

---

## A. Round-1 register — disposition, verified against code and by running the gates myself

| R1 | Severity | Status |
|---|---|---|
| 1 — `apiGet` strips `meta`/`summary`; page throws on first load | P1 | **CLOSED — CONFIRMED.** `deliveryNotes.ts:215-220, 222-231` now use `api.get` + `response.data` (the `getPartnerDeliveryNotes` pattern). Backed by a genuine transport test that mocks *nothing* between page and wire — only `api.defaults.adapter` (`ToBillPage.transport.test.tsx:88-100`) — replaying a verbatim server body through the real interceptors, hooks and page, asserting `data`, `summary` and `meta` all survive. This is the right fix for R1-7 too. |
| 2 — OI-8 conditions 1–4 absent on View B; refusal rendered as nothing | P1 | **CLOSED — CONFIRMED.** `ToBillRefusalAlert` (`ToBillPage.tsx:52-145`) is a persistent `role="alert"` region on the attempting page: cond. 1 inline+persistent (state at `:311`, not a toast); cond. 2 lost DN named with its taker (`:100`, `:106-118` "Open INV-TAKER" + lane label `:82-86`); cond. 3 `billingRefusal.guarantee` = "No invoice was created. No invoice number was used." (en+fr); cond. 4 no bare retry — remainder-only resubmit (`retryAfterRefusal` `:372-384`), suppressed when nothing remains (`:130`). `submitAttempt` (`:339-352`) catches, parses and stores. Test at `ToBillPage.test.tsx:251-300` asserts the named DN, the taker link href, the guarantee sentence, and `mutateAsync` last called with `['dn-2']`. |
| 3 — `partner_search` 422s on its own first keystroke | P2 | **CLOSED — CONFIRMED.** `ToBillPage.tsx:317-320`: `useDebouncedValue(…, 300)` + a `PARTNER_SEARCH_MIN_LENGTH = 2` floor mirroring the server rule. Two tests, one asserting `'a'` is never sent *after the debounce window provably elapsed*, one asserting no intermediate prefix is requested. |
| 4 — aging/grand tiles label partner groups as "delivery notes" | P2 | **CLOSED — CONFIRMED.** New `toBill.summaryGroupCount` ("{{count}} customer group(s)" / "groupe(s) client(s)") on both tiles (`:406`, `:419`); the per-group card correctly keeps `groupCount` because `delivery_note_count` really is a DN count (`:227`). Asserted at `ToBillPage.test.tsx:140`. |
| 5 — malformed `location_id` → 500 | P2 | **CLOSED — CONFIRMED.** `DeliveryNoteController.php:237-240` adds `uuid` conditionally (skipped only for the literal `all`). Test asserts 422 + `error.errors.location_id.0` (`DeliveryNoteToBillQueueTest.php:235-238`). I ran it: 4 passed / 49 assertions. |
| 6 — three silent exclusions, no escape hatch | P2 | **CLOSED — CONFIRMED.** (a) `all` is now reachable through a server-entitled toggle (`scope.can_view_all_locations`, controller `:256`, `:282`; UI `:441-453`) — entitlement is computed server-side and independently re-enforced at `:258-263`, so the FE control cannot widen scope. (b) the no-active-location blank region is gone: `enabled` no longer gates on `locationId` (`useDeliveryNotes.ts:127`) and an idle query renders an explicit `noScope` EmptyState (`:515-522`). (c) the currency and no-location exclusions are now *disclosed*, and the disclosure is driven by `scope.location_id` — what the server actually served — not by the local toggle (`:427-440`), which is the correct fix for the handback's mis-description. |
| 10 — B-2 reconciliation literal-matched, not compared | P3 | **CLOSED.** `DeliveryNoteToBillQueueTest.php:207-208` now compares the B-1 group row against the B-2 summary across endpoints. |
| 8 — unbounded page fan-out | P3 | **Deliberately reverted** (2de9df339 removed the 4-way cap and its test) and recorded. See N1. |
| 9, 11, 12 | P3 | Unfixed, recorded. Re-listed below. |

**Gates I ran myself (not taken from the handback):** focused Vitest `5 files / 23 tests` green; `tsc --noEmit` clean; ESLint on every touched file `0 errors`; `audit-tanstack-keys` Gate C `0 new`; `audit-design-system` `0 new` (734 baselined); `audit-quantity-display` `0 new`; PHPStan on both touched PHP files `[OK] No errors`; `DeliveryNoteToBillQueueTest` 4/49 green; **`LaneSeparationReportTest` 8/30 green** (the M3 gate's named regression). I did **not** re-run the whole-repo sweep required by the `preflight_policy` amendment — that residual-set comparison remains the executor's recorded evidence, which M5 must re-derive.

---

## B. New findings, round 2 — all P3, none blocking

**N1 — P3 — CONFIRMED — `apps/web/src/features/documents/api/deliveryNotes.ts:239-244`** — the R1-8 concurrency cap was added in `91468b98d` then reverted in `2de9df339` as out-of-scope. `getAllToBillPartnerRows` fires `last_page - 1` concurrent requests with a bare `Promise.all`, and this is now the *billing* path (every "Create invoice" runs it). A periodic customer with 900 outstanding DNs issues 8 parallel requests; the browser's per-host cap absorbs it, so this is a load smell, not a defect. The revert is defensible (R1 graded it P3, record-only) and it is disclosed in the YAML `findings:`. Recorded, not re-litigated.

**N2 — P3 — CONFIRMED — `ToBillPage.tsx:354-370` vs `:560-564`** — the confirmation dialog states `confirmation.delivery_note_count` / `.total` from the (possibly minutes-old) summary query, while `confirmCreate` bills the **live** set returned by `getAllToBillPartnerRows`. Failure scenario: the dialog says "7 delivery notes totalling 12 430.750"; three more DNs are confirmed for Atlas while the dialog is open; the operator confirms and a sealed, numbered invoice for 10 DNs is created. Intent-preserving ("bill everything outstanding for this customer") but the approved number is not the created number, on an irreversible fiscal document. Predates this round (present at `87f4b75db`); recording rather than blocking.

**N3 — P3 — CONFIRMED (mechanism) / PLAUSIBLE (reach) — `deliveryNotes.ts:233-246`** — `getAllToBillPartnerRows` offset-paginates a live query. If the SO lane bills a page-1 DN between page fetches, offsets shift and one DN silently falls into the gap; needs >100 DNs for one partner. Fail-safe in both directions: a dropped DN stays in the queue (under-bill, no wrong invoice), and a duplicated DN is rejected by M1's count-guarded `finalise()` rather than double-billed.

**N4 — P3 — CONFIRMED — `CopiesDocumentData.php:60` reached via `ToBillPage.tsx:319`** — making `all` reachable makes cross-location batches reachable, and the converter copies the **first** DN's `location_id` onto the invoice, so a Tunis+Sfax batch books all revenue under one location. Spec §3.2 explicitly authorises `all` for entitled users, so this is a consequence of the ruling, not a deviation — but it is an *owner-visible* consequence that no earlier round could surface, because `all` was unreachable. Worth one line in the M5 report.

**N5 — P3 — CONFIRMED — `ToBillPage.tsx:345-351, 503-509`** — `submitAttempt`'s catch only clears/sets `billingRefusal` on an *attributed* error. A subsequent generic failure on a different group leaves the previous group's refusal alert on screen next to a fresh toast, attributing the wrong DNs to the wrong attempt.

**N6 — P3 — CONFIRMED — `ToBillPage.tsx:103`** — `document.invoice_date` is rendered raw (`2026-08-19`) rather than localized; the test pins the ISO form (`:271`). Same class as the M2-round-2 "raw refusal dates" ticket, now on a second surface. Related: `:182` and `:231` still do `new Date('YYYY-MM-DD').toLocaleDateString()`, which parses as UTC midnight and renders the previous day west of UTC — R1-11, still open, correctly deferred to M5 as a branch-wide pattern.

**N7 — P3 — CONFIRMED — `ToBillPage.tsx:425-455`** — the whole scope row, including the All-locations toggle, is inside `query.data ? … : null`. If an `all`-scoped request ever fails (entitlement revoked mid-session, or a company switch that does not remount the page), `query.data` is `undefined`, `QueryError` renders, and the toggle that would let the operator switch back is not on screen — the only exit is a reload. Narrow, since the toggle only appears when the server already said the scope is entitled.

**N8 — P3 — CONFIRMED — `Sidebar.test.tsx:127-135`** — the to-bill nav test asserts only that `canAccessModule('deliveries.view')` was *called*; it has no negative case. The lane-separation test 60 lines below does mount/unmount/re-mount with the permission denied and asserts absence. The route-level gate is properly covered four ways (`ToBillRoute.gates.test.tsx`, `ToBillPage.test.tsx:353`, backend `DeliveryNoteConsolidationAccessControlTest`), so this is test hygiene, not a gap in enforcement.

**N9 — P3 — CONFIRMED (mechanism) / PLAUSIBLE (reach) — `DeliveryNoteController.php:266-278` + `LocationContext.php:128-150`** — the membership check is inside `if ($locationId !== null)`. `resolveLocationId(null, …)` returns `null` when the company has no *active* location (`getDefaultLocation` filters `is_active`), and on that branch neither `belongsToCompany` nor `canAccessLocation` runs, so a membership with `allowed_location_ids = [X]` gets an unscoped queue. Requires a company whose locations are all deactivated. Pre-existing shape of the `LocationContext` contract, not introduced here.

**N10 — P3 — CONFIRMED — `DeliveryNoteController.php:261, 274`** — R1-9 stands: both validation messages are hardcoded English rather than translation keys, so a French operator gets "All locations is outside your allowed scope."

**N11 — P3 — CONFIRMED — `UninvoicedDeliveryNoteService.php:151`** — R1-12 stands: an unresolvable partner throws `LogicException` → 500 on a read path.

---

## C. Bypasses I tried that FAILED (attempts to exonerate or to break the code that did not succeed)

| Attempt | Result |
|---|---|
| Tried to make the refusal alert unreachable by arguing `onError` swallows the rejection before `mutateAsync` rejects | No — `useDeliveryNotes.ts:232-236` only suppresses the *toast*; `mutateAsync` still rejects into `submitAttempt`'s catch, and the test proves the end-to-end path |
| Tried to bypass the `uuid` rule with `location_id[]=all`, `ALL`, `All` | All 422: the array fails `string`, and any non-literal-`all` value takes the `uuid` rule (`:237-240`) |
| Tried to widen scope client-side by forcing `allLocations` for a restricted user | Server re-derives entitlement independently (`:258`) and 422s at `:259-263`; the FE flag is presentation only |
| Checked whether `scope` could be absent and re-throw R1-1's `TypeError` (`:441` has no optional chain) | Controller unconditionally sets it (`:181-184`), the backend test pins both sub-keys, and the transport test carries it |
| Checked Rule 19 across every new money path | Clean. `Query\Builder::sum()` returns the raw aggregate (`vendor/…/Query/Builder.php:3963-3968`, no `numericAggregate`), `'0.000'` is truthy so the `?: 0` fallback only fires on NULL; every total goes through `bcadd`/`bcformatStrict` at `$scaleResolver->getScale($company->currency)` — explicit currency, constructor-injected, no `app()`. FE uses `formatAmount`/`useCurrency().format`, zero `parseFloat`/`Number()` on money |
| Checked whether the queue can emit a mixed-currency batch (the M2-round-2 P2 reassigned to M3) | Cannot — `queueQuery` pins `documents.currency = company.currency` (`:310`); the View A guard it was actually assigned to is in place at `PartnerDeliveryNotesTab.tsx:127-134` with its test |
| Checked both-layer gating (rule 12) for the new surface | Complete: backend `['module:Sales','can:deliveries.view']` on both routes (`routes.php:274-281`), FE `ModuleGuard module="Sales"` + `RequirePermission "deliveries.view"` (`routes/index.tsx:584-593`), sidebar entry under the `module: 'Sales'` sales group with `permission: 'deliveries.view'`, action gated on `invoices.create`, manifest updated |
| Checked tenant/company/partner scoping | `forTenant()` + `company_id` on every query; `whereHas('partner')` re-asserts tenant+company+customer type inside the service, independently of the controller's `findOrFail`; `whereUuid('partner')` on the route; query keys via `locationScopedKey` → `tenantScopedKey` (Gate C 0 new) |
| Checked red-first discipline | Honoured: `19e0332bc` and `47d982037` are tests-only "Reproduce M3 bridge findings" commits preceding the `91468b98d` / `2de9df339` fixes; `2a983c621` tightens an assertion after |
| Checked for new migrations, queues, events | None in M3 — no migration, no `onQueue`, no new/renamed event (rule 8 untouched) |
| Checked en+fr parity for every new key | Zero one-sided keys in `sales.json` / `finance.json`; the French is genuine translation, not copied English |

---

## Disposition

Both round-1 P1s are closed with the right fix at the right layer, and — more importantly — the *reason* R1-1 shipped green (finding 7: every layer mocked) is closed by a real transport-seam test rather than by another mock. All four P2s are closed, each with an assertion that would fail if the fix were reverted. The one deliberate non-fix (the fan-out cap) was correctly graded P3 by round 1 and is disclosed in the YAML. Everything remaining is P3 and may ship recorded; N2, N4 and N6/R1-11 belong in the M5 report, and N4 is the one an owner should actually read.

VERDICT: ACCEPT
