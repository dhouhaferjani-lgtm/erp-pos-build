# M-10 Opus Adversarial Review — Workshop Module Route-Guard Alignment

Item: M-10 (Module gating inconsistencies)
Commit: fb09a09975123294b4e6161bedfc8bc57db88fee
Reviewer: Opus (adversarial cross-model pass; the merged item only had Codex + a self-declared "opus-fallback" review where Opus was unavailable)
Date: 2026-06-23

## Summary

The commit adds `module:Workshop` middleware to the Service catalog API route group
(`apps/api/app/Modules/Service/Presentation/routes.php:20`) and wraps the three
workshop work-order frontend routes in `<ModuleGuard module="Workshop">`
(`apps/web/src/routes/index.tsx:1286-1320`). It adds a backend denial-matrix test for
`/api/v1/services` and `/api/v1/service-categories`, and a source-string frontend test.

This is a pure routing/middleware change. **No money, precision, sign-convention, GL,
event-sourcing, hash-chain, or migration code is touched**, so the heavy fiscal lenses do
not apply — no BLOCKER on those axes. The core claim ("service catalog + workshop
work-order routes fail closed behind the Workshop module, backend + frontend") is
**true for the routes named**, and the backend test genuinely asserts the module-gating
cause (verified the `EnforceTokenTenantClaim` middleware grandfathers `Sanctum::actingAs`
tokens, so the 403 cannot be a false-positive from a tenant-claim mismatch — it is the
unique `RequireModule` message).

However, the claim "fail closed **consistently**" overstates what shipped: a second,
un-gated frontend consumer of `/api/v1/services` (the document line editor Service tab)
is reachable from **every** vertical and now receives a 403 it did not before. That is a
real, if graceful, behavioral regression that the item did not address and neither prior
review caught.

## BLOCKER

None. No fiscal/money/data-integrity logic and no migration are in scope.

## HIGH

### H-1 — New 403 regression on the document line editor "Service" tab for non-Workshop verticals
`apps/web/src/features/documents/components/DocumentLineEditor.tsx:127-136` fetches
`/services` whenever the user opens the line-item search dropdown and selects the
`service` tab:

```ts
queryFn: async () => {
  const response = await api.get<ServicesResponse>(`/services${params}`)
  return response.data
},
enabled: tenantId !== null && companyId !== null && showProductSearch && searchTab === 'service',
```

The Service tab is rendered **unconditionally** — there is no `hasModule('Workshop')`
gate on it (`DocumentLineEditor.tsx:507-508` render the tab button with no module check):

```tsx
onClick={() => { setSearchTab('service'); setSearchQuery('') }}
```

DocumentForm (which embeds DocumentLineEditor) is reached via the Sales document routes
`quotes/new`, `orders/new`, `invoices/new`, which are gated only by
`RequirePermission moduleKey="sales"` / `sales.create`
(`apps/web/src/routes/index.tsx:540-548`) — **not** Workshop. Every vertical has the
`Sales` module (`apps/api/config/verticals.php`), so a retail / pharmacy / parts_retailer /
car_glass / tire_shop user creating a quote can click the "Service" tab. Before this
commit that call returned 200 (empty/valid list); after this commit it returns
`403 Module 'Workshop' is not enabled for this business type`.

Impact is bounded — React Query surfaces the error, no data is corrupted, and the flow is
an edge case (service line on a document in a non-service vertical) — so this is HIGH, not
BLOCKER. But it directly contradicts the merged claim of "fail closed **consistently**":
the backend now rejects a surface the frontend still openly offers, which is exactly the
class of backend/frontend inconsistency M-10 set out to remove. Codex's review asserted
the change matches "the existing sidebar and page-level frontend treatment of services";
it did not enumerate the DocumentLineEditor consumer.

Suggested remediation (small, in-spirit-of-M-10): gate the Service tab in
DocumentLineEditor behind `hasModule('Workshop')` (hide the tab / skip the query) so the
two layers agree, OR document explicitly that the document Service tab is intentionally
out of scope and is expected to 403 for non-Workshop verticals.

## MEDIUM

### M-1 — Frontend `routes.test.tsx` is a source-string presence check, not a behavioral guard
`apps/web/src/routes/routes.test.tsx` reads `index.tsx` as text and asserts the substring
`<ModuleGuard module="Workshop">` appears inside the `services` and `workshop/work-orders`
branches. It would pass even if `ModuleGuard` were imported from the wrong module, were a
no-op, or were rendered as a sibling rather than an ancestor of the page. It cannot detect
a guard placed in the wrong position in the element tree. The behavioral safety net comes
entirely from `ModuleGuard.test.tsx` (which is solid). The new test only prevents textual
removal of the guard token. The branch-boundary heuristic (`indexOf('\n        {/*')`)
also silently depends on each route block being preceded by an 8-space-indented JSX
comment; a future reorder that drops the trailing comment would make `routeBranch()` slice
into an adjacent block and could yield a false green/red. Acceptable as a regression
tripwire, but the review docs overstate it as "frontend route guard coverage."

### M-2 — No positive (allow) backend test — over-blocking is uncovered
`WorkshopModuleAccessControlTest` only asserts denial (403) for retail/pharmacy/restaurant/
parts_retailer. There is no test that a Workshop-enabled vertical (e.g. `mechanic`) still
receives a non-403 from `/api/v1/services` and `/api/v1/service-categories`. If the module
name had been mistyped (e.g. `module:Workshops`) every vertical would 403 and the entire
denial matrix would still pass green — silent over-blocking would ship undetected. The
config check (services gated under `Workshop`, which exists in mechanic/body_shop/car_glass
default_modules — confirmed `apps/api/config/verticals.php:21-41,198`) makes the chosen
module name correct here, but the test suite does not lock that in.

### M-3 — `module:Workshop` is the correct gate, but note car_glass/tire_shop/parts_retailer have services-like work without the Workshop module
`car_glass`, `tire_shop`, `service_station`, and `parts_retailer` are automotive verticals
with `Vehicle` but **without** `Workshop` in `default_modules`
(`apps/api/config/verticals.php:228-247,287-300`). They will now be 403'd on the entire
service catalog API. The frontend already gated the services *pages* under Workshop
(pre-existing, since `9ee3132ab`), and there is no separate "Service" module anywhere in
config, so backend-aligning to `Workshop` is internally consistent. Flagging only because
this is a genuine behavioral narrowing for those verticals, not a pure no-op "consistency"
change as the commit framing implies — owner should confirm these verticals are not
expected to manage a service catalog.

## LOW

### L-1 — Whole Service route group gated, including write routes — verify intent
The middleware is applied at the group level, so `POST/PATCH/DELETE /services` and
`/service-categories` are now Workshop-gated too (not just the index reads named in the
test). This is almost certainly desired (fail closed > fail open), and the existing
`services.create` / `services.edit` permission layer is unaffected. No action needed; noted
for completeness since the test only exercises the two index GETs.

### L-2 — Other `/services` consumers verified safe
`ServicePicker` (`apps/web/src/components/molecules/pickers/ServicePicker.tsx:96`) is only
rendered inside workshop-bundles (`BundleComponentFormModal.tsx:334`), which is itself
Workshop-gated, so no regression there. The dedicated `features/services/*` pages are
route-guarded. The only un-gated consumer is the DocumentLineEditor tab in H-1.

## Verdict

APPROVE-WITH-MINOR-EDITS.

The named claim is true and the backend test is meaningful (not false-confidence). No
fiscal/data-integrity risk. The "consistently fail closed" wording is the one substantive
gap: the document line-editor Service tab is now a backend/frontend inconsistency (H-1)
that is the same bug-class M-10 targeted. Recommend either gating that tab behind
`hasModule('Workshop')` or explicitly scoping it out in the work-list, plus adding a
positive allow-test (M-2) so over-blocking is detectable. None of these block the merge;
they are follow-ups. The self-declared "opus-fallback" review should not be counted as a
genuine cross-model Opus pass — this review supersedes it.
