# ERP-side gate — erp-mobile lane `codex/inventory-alignment-2026-09` (HEAD `e420c50`)

**Date:** 2026-09-04 · **Gate:** ERP coordinator (adversarial), read-only on both repos
**Mobile repo:** `/Users/houssamr/Projects/syneriva/erp-mobile` (branch `codex/inventory-alignment-2026-09`, target `main`)
**ERP repo:** `/Users/houssamr/Projects/syneriva/apps/erp` (local `dev`, includes N-1 `de31017e0`, also on staging)

---

## VERDICT: **CHANGES** — 2 blocking findings, both small; everything else in M-1..M-9 verifies clean against the ERP at file:line.

Merge to `main` after B-1 and B-2 land. No contract mismatch, no phantom-serverId path, no auth/pagination regression was found; the two blockers are places where the lane's own output does not reach its stated acceptance criterion and will each seed a false bug report in Dhouha's imminent A-to-Z run (`docs/handoff/HANDOVER-DHOUHA-INVENTORY-COUNTING-A2Z-2026-09-02.md:50` and `:75` tell her to expect exactly the opposite of what ships).

---

## 1. Blocking findings

### B-1 · "En vérification" home tile is structurally always `0` (dead operator signal)

- **Mobile:** `src/features/counting/lib/taskView.ts:29-31` (`isReviewTask` → `task.status === 'pending_review'`), consumed at `app/(app)/index.tsx:57` and rendered as a StatTile at `app/(app)/index.tsx:216-219`. The only data source is `useCountingTasks()` → `countingApi.getTasks()` (`src/features/counting/api/countingApi.ts:295-298`) → `GET /inventory/countings/my-tasks`.
- **ERP:** `apps/api/app/Modules/Inventory/Presentation/Controllers/InventoryCountingController.php:542-546` — `myTasks()` filters `whereIn('status', [Count1InProgress, Count2InProgress, Count3InProgress])`. `pending_review` can never appear in that payload.
- **Falsifying scenario:** counter finishes the last item of a single-count session → server auto-transitions `count_1_completed` → `pending_review` (`InventoryCountingService::checkPhaseCompletion`, :989-1019). Home screen: "Actives 0 · En attente 0 · **En vérification 0**", while the web review page shows the counting in review. The tester was told (`HANDOVER-DHOUHA…:75`) "mobile shows no 'en vérification' state (M-4)"; she will now see a tile asserting zero and report it.
- **Note:** `isPendingTask` (`taskView.ts:25-27`, `'scheduled' | 'activated'`) is dead for the same reason — pre-existing, not introduced here, but it means two of the three tiles are permanently zero.
- **Exact fix (pick one):**
  1. Preferred, no server work: delete `isReviewTask` and the StatTile at `app/(app)/index.tsx:216-219`; keep the terminal banner on the session screen (which *is* correct — see §3 M-4) as the sole review signal. The brief marked M-4(d) optional/P2, so dropping it is in scope.
  2. If the tile is wanted, it needs a real source: add `pending_review` to the `whereIn` at `InventoryCountingController.php:542-546` — an ERP change that must be gated separately, and would also put the counting back in `isActiveTask`'s neighbourhood, so option 1 is the recommendation for this lane.

### B-2 · Zone label shows the CODE path and drops the zone NAME (M-1 acceptance not met)

- **Mobile:** `app/(app)/counting/[id]/index.tsx:61-66` — `.map((zone) => zone.path.split('/').join(' / '))`; same substitution in the picker at `app/(app)/counting/create-draft.tsx:397` (`{zone.code} · {zone.path.split('/').join(' / ')}`). Before the lane, both rendered `zone.name`.
- **ERP:** `apps/api/app/Modules/Inventory/Domain/LocationNode.php:26` — "`path` is the materialized ancestor **code** chain ('A1/R2/B7')"; built at `apps/api/app/Modules/Inventory/Application/Services/LocationNodeService.php:56` as `$parent->path.'/'.$code`. `name` is a separate column and is the only human label.
- **Falsifying scenario:** a shelf named "Étagère 1", code `S1`, under rack `R2` under aisle `A1`. Session header renders `A1 / R2 / S1`; the picker row renders `S1· A1 / R2 / S1` (leaf code printed twice, name never). M-1's acceptance in the handover reads "session screen shows the **zone names** in the label", and `HANDOVER-DHOUHA…:50` tells the tester "the session header shows the zone **name**".
- **Exact fix:** keep `path` as the hierarchy hint and restore the name at both sites, e.g.
  - `app/(app)/counting/[id]/index.tsx:64` → `.map((zone) => zone.name)` (path is not needed in a comma-joined header), and
  - `app/(app)/counting/create-draft.tsx:397` → `{zone.path.split('/').join(' / ')} · {zone.name}`.
  Update the two assertions that pin the current strings (`src/features/counting/__tests__/countingSessionScreenLiveInventory.test.tsx`, `createDraftZoneScopeScreen.test.tsx`).

---

## 2. Non-blocking findings (tickets, not merge blockers)

| # | Where | What | Recommended owner |
|---|---|---|---|
| N-1 | mobile `src/features/counting/services/draftSyncService.ts:131-135, 446-489` + ERP `InventoryCountingController.php:1052-1245` | **Duplicate drafts on a lost response.** `DraftSyncService` guards concurrency with a per-instance `isSyncing` flag (`:132`) and is instantiated per hook consumer (`useRef(new DraftSyncService())`, `:453`); `useDraftSync()` is mounted by BOTH `app/(app)/counting/sync-errors.tsx:47` and `app/(app)/counting/draft/[id]/index.tsx`, which can be on the stack simultaneously → two independent 30 s timers, two concurrent `POST /inventory/countings/drafts/batch`. Server-side, `localId` is echoed back but never persisted and never used for dedupe (`:1157-1181` sets no idempotency column), so both requests create rows. Same shape after any lost response: the draft stays `status:'draft', id:null` and is re-posted next tick. Compare `pendingCountSyncService.ts:90-102`, which DOES serialize via a module-level `syncChain`. | Both. Mobile: module-level chain like the count queue. ERP: treat `localId` as an idempotency key scoped to (company, user) on `drafts/batch`. |
| N-2 | ERP `InventoryCountingService.php:847-850` + `InventoryCountingAssignment.php:162-165` | **`assignment.counted_items` inflates on any re-submit.** `$assignment?->incrementProgress()` → `increment('counted_items')` fires unconditionally, including a queue replay of an already-counted item or a counter correcting a quantity. Contained: `getProgress()` (`InventoryCounting.php:310-341`) and `checkPhaseCompletion()` (`:989-997`) both derive from item rows, so progress and the phase transition are safe; only the assignment stat row is wrong. | ERP. Increment only when the phase column was `null` before the write. |
| N-3 | mobile `src/features/receiving/api/receivingApi.ts:18-23` + ERP `PurchaseOrderController.php:222-224, 259-262` and `apps/api/app/Support/Traits/PaginatesResults.php:16-22` | **`listConfirmed()` reads only page 1 of a cursor-paginated list.** `getPaginationParams` defaults `per_page` to 25; the mobile sends no `cursor` and drops `links.next`. A tenant with >25 confirmed POs silently hides the rest from the phone. Pre-existing, NOT a request-hygiene regression. | Mobile (follow the cursor) — flag for Dhouha's S6 if the tenant has many confirmed POs. |
| N-4 | mobile `src/features/counting/api/countingApi.ts:255-259` | `getZones` sends `include_deleted: 1` and then discards tombstones at `:261`. Harmless but pointless over-fetch; drop the param (the endpoint already excludes tombstones by default — `LocationNodeController.php:57-59`). | Mobile. |
| N-5 | mobile `app/(app)/counting/[id]/scan.tsx:28-29` and `app/(app)/receiving/[id]/scan.tsx:31-33` | **Stale scanner after a back-navigation.** `handleBarcodeScan` early-returns while `scanned === true`, and `scanned` is only reset from the two `Alert` callbacks. The happy path pushes the item screen; "Enregistrer" without "scanner suivant" does `router.back()` (`app/(app)/counting/[id]/item/[itemId].tsx:106-110`) and lands back on the *same* scanner instance with `scanned === true` — camera AND the new manual-entry modal are both inert until the screen is popped. The "scanner suivant" path uses `router.replace` (`:107`) and is fine. Pre-existing for the camera; the M-7 modal now inherits it. | Mobile. Reset `scanned` on focus (`useFocusEffect`). Must be in the physical Android test (see §5). |
| N-6 | mobile `src/features/counting/api/countingApi.ts:347, 353` | `idempotency_key` is still sent on submit-count. Confirmed harmless: `SubmitCountRequest` (`apps/api/app/Modules/Inventory/Presentation/Requests/SubmitCountRequest.php:29-47`) validates only `quantity/notes/counted_at_device/device_now` and the controller reads typed accessors only (`CountingItemController.php:104-112`) — Laravel ignores the extra key, no 422. There is no strict-payload middleware on this route. M-9's "note it in the hardening report" was done (`docs/handoff/mobile-counting-hardening-report.md`). Keep or drop; no action required. | — |
| N-7 | mobile `src/features/counting/services/pendingCountSyncService.ts:73-78` | The park reason is the hardcoded `'Comptage fermé'`, discarding the server-localised `error.message`. This matches the M-4(b) brief, but `HANDOVER-DHOUHA…:59` says the row parks "with the server message". Either is defensible — just align the tester doc. | Docs. |
| N-8 | mobile `src/features/counting/lib/serverDate.ts:1-8` | The legacy branch requires an offset (`([+-]\d{2})`); a bare `"YYYY-MM-DD HH:MM:SS"` with no offset falls through to `new Date(value)`, which is implementation-defined on Hermes. The ERP always emits an offset (`InventoryCountingController.php:738`, `toIso8601String()`), so unreachable today. | Mobile, optional. |
| N-9 | repo | No ESLint config in `erp-mobile` (`ls -a | grep eslint` → empty). The quality gate is jest + `tsc --noEmit` only. Not this lane's problem; worth a ticket given the ERP repo's lint discipline. | Mobile. |

---

## 3. Per-finding verification (M-1..M-9 + `fcfa5be`) — both sides cited

**M-6 · receiving barcode envelope — PASS.**
Mobile `src/features/receiving/api/receivingApi.ts:68` now `return response.data.data`. ERP `LineEntryController::resolveCode` (`apps/api/app/Modules/Product/Presentation/Controllers/LineEntryController.php:31-72`) returns via `$this->success([...])` → `{data:{kind, matched_code_type, product}, meta}`. `kind:'not_found'` is a **200** (`:68-71`), and the mobile branches on `result.kind !== 'not_found'` (`app/(app)/receiving/[id]/scan.tsx:37-40`) rather than on a status — correct. Variant hits also carry `product` (`:195-199`), and an orphaned variant degrades to `not_found` (`:186-191`), which the mobile maps to `PRODUCT_NOT_FOUND`. Route: `apps/api/app/Modules/Product/routes.php:46`.

**M-1 · zone scope from the node hierarchy — PASS (label regression = B-2).**
Mobile `src/features/counting/api/countingApi.ts:255-274` calls `GET /inventory/locations/{locationId}/nodes` and filters `deleted_at === null && is_active`. ERP route `apps/api/app/Modules/Inventory/Presentation/routes.php:338-340` (`can:inventory.view`); controller `LocationNodeController.php:39-66` (uuid guard `:43-45`, company-scoped `findOrFail` `:48`, tombstones only under `?include_deleted` `:57-59`). Field-for-field match against `LocationNodeDto` (`apps/api/app/Modules/Inventory/Application/DTOs/LocationNodeDto.php:15-30`): `id, location_id, parent_id, node_type, name, code, path, depth, sort_order, is_active, product_count, deleted_at, created_at, updated_at` — the mobile's `ApiLocationNode` (`countingApi.ts:69-84`) is identical. Consistent with the placements feature's existing call (`src/features/placements/api/placementApi.ts:48-54`).

**M-2 · `warehouse` removed, `zone` labelled — PASS.**
`DraftScopeType` (`countingApi.ts:10-16`) and `DraftCounting['scopeType']` (`src/features/counting/store/draftCountingStore.ts:20`) now carry exactly the six server values; picker `app/(app)/counting/create-draft.tsx` no longer offers Entrepôt; `scopeLabel` gained `case 'zone'` (`app/(app)/tasks.tsx:69-70`). ERP enum: `apps/api/app/Modules/Inventory/Domain/Enums/CountingScopeType.php` (`product_location, product, location, category, full_inventory, zone`), enforced at `CreateDraftCountingRequest.php:47` and — whole-batch, by design — at `InventoryCountingController.php:1068`.

**M-3 · `product_location` carries a location — PASS (the strongest leg).**
Mobile: `CreateDraftInput` makes `locationId` required for `product_location` (`countingApi.ts:113-132`); `createDraftScopeFilters` emits `{product_ids, location_id}` (`:142-147`); the offline twin does the same and refuses without it (`draftSyncService.ts:88-97`); the create-draft screen shows the location picker for both location-scoped types (`create-draft.tsx:90-92, 102, 147, 189-200, 310`).
ERP, three enforcement points all present on `dev`:
- single POST — `CreateDraftCountingRequest.php:79-85` (`required_if:scope_type,product_location`, `required_if:scope_type,zone`, `ScopedExists::company('locations', …)`) with the message at `:96`;
- batch — per-row refusal, never a whole-batch 422: `InventoryCountingController.php:1112-1122` (missing location) and `:1133-1140` (non-uuid, the PG 22P02/25P02 phantom-serverId guard), per-row savepoints at `:1151-1182`, company check at `:1152-1155`, and the deadlock rethrow at `:1417-1421` that prevents answering 201 with ids that were rolled back. **No phantom-serverId path found.**
- activation — `:984-989` (`product_location`) and `:999-1008` (`zone`), plus the zero-item `\DomainException('Nothing to count in this scope — no stock rows matched')` at `InventoryCountingService.php:662, 725`, which renders as `{error:{code:'BUSINESS_ERROR', message}}` and is surfaced by the mobile via M-5.
Cross-check that could have stranded every mobile draft and does not: `addProduct` (`:786-802`) and `batchAddProducts` (`:1408-1413`) both **merge** into the existing `scope_filters` array rather than replacing it, so `location_id` survives incremental product addition — necessary, because `PATCH …/draft` cannot set `scope_filters`.

**M-4 · lifecycle awareness — PASS (except the B-1 tile).**
Mobile `src/features/counting/lib/countingLifecycle.ts:3-11` gates on the three in-progress statuses; the session screen hides the scan CTA (`app/(app)/counting/[id]/index.tsx:195-212`), makes rows untappable (`:164-173`) and shows the terminal banner (`:116-121`). ERP confirms the design works: `counterView` (`InventoryCountingController.php:321-381`) answers **200 for a terminal counting** — the only refusal is 403 for an unassigned user (`:330-332`) — and returns `counting.status` (`:366`) and `includes_zero_stock` (`:371`).
Typed refusal: `isCountingTransitionRefused` (`countingLifecycle.ts:26-40`) matches on `422 + error.code === 'COUNTING_TRANSITION_REFUSED'` and never string-matches the message — correct, because the message is localised at render time (`apps/api/bootstrap/app.php:933-951`, `CountingTransitionException::TRANSLATION_KEY`). The refusal is genuinely thrown for a closed session at `InventoryCountingService.php:790-796`, and re-asserted under the header lock at `:827`.
Whole-counting park in one round-trip: `pendingCountSyncService.ts:73-79` adds the id to `closedCountingIds` (`:34, 37`) and calls `parkPendingCountsForCounting` (`countingStore.ts:132-143`); the online path does the same (`useSubmitCount.ts:82-87`). Bulk discard: `removePendingCountsForCounting` (`countingStore.ts:183-189`) behind the grouped UI at `app/(app)/counting/sync-errors.tsx:56-64, 157-169, 376-391`.

**M-5 · error envelopes — PASS.**
`getErrorMessage` (`src/lib/api.ts:101-124`) now handles all three server shapes: bare string (`:104-106`), `{error:{code,message,errors}}` with the first field message appended (`:107-118`, helper `:77-99`), and top-level `message` (`:119-121`). Draft detail routes through it (`app/(app)/counting/draft/[id]/index.tsx:48-51`). Bare-string producers verified: `InventoryCountingController.php:756-758, 780-783, 949-951, 958-960, 973-975, 986-988, 994-996, 1005-1007, 1012-1014`. Structured producers verified: validation envelope from the FormRequests above, `BUSINESS_ERROR` from the two `\DomainException`s, `COUNTING_TRANSITION_REFUSED` from `bootstrap/app.php:933-951`.

**M-7 · Android manual entry — PASS (code); physical run owed.**
`src/ui/organisms/ManualBarcodeEntryModal.tsx:1-118` replaces the iOS-only `Alert.prompt` at both call sites (`app/(app)/counting/[id]/scan.tsx:143-147`, `app/(app)/receiving/[id]/scan.tsx:140-144`); `Alert` is still imported and still used for `Alert.alert`, so no dead import. Trimmed input, disabled Valider on empty, `onSubmitEditing`, `testID="manual-barcode-input"`. Two runtime risks the physical run must clear: `autoFocus` inside an RN `Modal` on Android, and N-5 above.

**M-8 · draft timestamps — PASS.**
`parseServerDateTime` (`src/features/counting/lib/serverDate.ts:1-14`) tolerates both forms and is wired at `app/(app)/tasks.tsx:41`. ERP now emits ISO-8601 on both fields: `InventoryCountingController.php:737-738` (`toIso8601String()`), i.e. N-1 landed.

**M-9 · residual drift — PASS.**
`includes_zero_stock` is now rendered (`app/(app)/counting/[id]/index.tsx:135-141`); source `InventoryCountingController.php:371`. Quantity ceiling: mobile `/^\d+(\.\d{1,4})?$/` (`src/features/counting/lib/quantity.ts:10`, enforced client-side at `countingApi.ts:340-342`) against ERP `['bail','required','numeric','regex:/^-?\d+(\.\d{1,4})?$/','min:0']` (`SubmitCountRequest.php:39`) — the mobile set is a strict subset, so no client-valid value is server-refused. `idempotency_key` → see N-6.

**`fcfa5be` · legacy-draft isolation — PASS.**
`VALID_DRAFT_SCOPE_TYPES` (`draftSyncService.ts:34-41`) plus the two throws at `:73-75, 78-80, 89-91` are caught per row in `syncNewDrafts` (`:229-241`), which parks the offender via `markSyncError` and sends only the valid rows. `markSyncError` sets `status:'sync_error'` (`draftCountingStore.ts:278-284`), which drops the row out of the `status === 'draft'` selector at `:153` — so a poison row parks once instead of retrying forever. This closes the real hole: an unknown `scopeType` is a **whole-batch** 422 server-side (`InventoryCountingController.php:1068`), so one persisted `warehouse` row from an older build would otherwise have blocked all 49 siblings permanently.

---

## 4. Pagination sweep (request-hygiene Phase A exposure) — **no mobile consumer is affected**

Every path in the mobile API client was enumerated (`grep -rn "api\.\(get\|post\|patch\|put\|delete\)" src/ app/`) and checked against the four endpoints the programme bounded (Tasks 2/3/4 — `docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:385, 896, 1193`):

- `/stock-movements` — **not called** by the mobile app.
- `/payments` — **not called**.
- `/audit/events` — **not called**.
- `GET /documents?limit=` — **not called**. The mobile touches only the sub-resource `/documents/{expenseId}/attachments` (`src/features/expenses/api/expensesApi.ts:98-110`), which lives in the Media module and was untouched by T4 (`git show --name-only 817ac93e2`).

Unbounded-by-design reads the mobile relies on, all verified to return the full set: `/locations` (`LocationController.php:81-100`, `->get()`), `/inventory/locations/{id}/nodes` (`LocationNodeController.php:50-65`, `->get()`), `/inventory/countings/my-tasks` (`:536-548`), `/inventory/countings/my-drafts` (`:722-727`). `/expenses` is offset-paginated and the mobile pages it correctly (`expensesApi.ts:23-30`). The one real cap is `/purchase-orders` — see N-3.

**Auth/tenancy:** the client attaches `Authorization: Bearer` and `X-Company-Id` on every request (`src/lib/api.ts:37-48`), which is what `CompanyContextMiddleware` reads (`apps/api/app/Http/Middleware/CompanyContextMiddleware.php:194`). The whole inventory group runs `['api','auth:sanctum', SetPermissionsTeam, EnforceTokenTenantClaim, 'module:Inventory']` (`apps/api/app/Modules/Inventory/Presentation/routes.php:31`) — note the `module:Inventory` gate: a tenant without the Inventory module gets 4xx on every counting call, which is correct and unchanged.

---

## 5. The four owed items are tester tasks, not code gaps — exact steps for the owner

All four are verified as *unexercisable in Jest/Expo-web*, with the code path present and unit-covered. Give Dhouha these on a physical Android phone (Expo Go, `EXPO_PUBLIC_API_URL=https://api.erp.otospex.dev/api/v1`, login `counter`):

1. **Physical camera scan (receiving).** Réception → a confirmed PO → **Scanner** → point the camera at a real barcode (e.g. `6190000008688`). Expect: returns to the PO detail with that line highlighted. Covers M-6 end-to-end (Expo web only reached the permission gate — `docs/verification/inventory-alignment-05-expo-web-camera-gate.png`).
2. **Android manual entry, both scanners.** (a) Comptage session → **Scanner un article** → **Saisir manuellement** → type a barcode → Valider. (b) Réception → PO → **Scanner** → **Saisie manuelle** → same. Expect: a real modal with a focused text field on Android (never a silent no-op). **Then the N-5 regression step:** after (a) resolves and you land on the item screen, press **Enregistrer** *without* "scanner suivant", which returns you to the scanner — reopen **Saisir manuellement** and enter a second code. If nothing happens, that is N-5, report it.
3. **Bulk discard with a real parked row.** Phone in airplane mode → count 2 items in an active session (they queue) → on the web, **cancel** that counting → phone back online → wait for a sync pass. Expect: both rows land in *Erreurs de synchronisation*, grouped under one "Session #…" header with a **"Supprimer tout pour ce comptage"** button; tapping it and confirming clears both. Server side this is the `COUNTING_TRANSITION_REFUSED` 422 from `InventoryCountingService.php:790-796`.
4. **Live `includes_zero_stock: true`.** On the web, create a counting with "include zero-stock products" ticked, assign `counter`, activate. On the phone, open the session. Expect the blue banner **"Produits sans stock inclus"** above the instructions. The live fixture on 2026-09-02 only ever returned `false`.

Also worth adding to her sheet, because the lane changes what she was told to expect: `HANDOVER-DHOUHA…:50` ("session header shows the zone name") and `:75` ("mobile shows no 'en vérification' state") are both stale once B-1/B-2 are resolved — update the handover in the same pass.

---

## 6. Tests, typecheck, merge readiness (commands + output)

```
$ cd /Users/houssamr/Projects/syneriva/erp-mobile && npx jest --ci --runInBand
Test Suites: 58 passed, 58 total
Tests:       411 passed, 411 total
Snapshots:   0 total
Time:        7.512 s
EXIT=0
```
Claim of 58 suites / 411 tests **confirmed exactly**. (Run in the existing checkout, which already has `node_modules`; a detached worktree would have had none and installing was out of scope.)

```
$ npx tsc --noEmit
TSC_EXIT=0
```

```
$ git diff main...codex/inventory-alignment-2026-09 -- '*.ts' '*.tsx' | grep -n "^+.*\bany\b"
(no output)
```
No `any` annotation or cast introduced. **"Batch casing unchanged" confirmed:** `countingApi.batchCreateDrafts` still sends camelCase keys with a snake_case `scopeFilters` value (`src/features/counting/api/countingApi.ts:513-530`), exactly what `InventoryCountingController.php:1059-1087` validates.

```
$ git merge-tree --write-tree main codex/inventory-alignment-2026-09
2c51ca7efce54a0549b613a8fc6bb8f5f3968167
(exit 0, no conflict section)

$ git merge-base --is-ancestor main codex/inventory-alignment-2026-09
FAST-FORWARD possible

$ git status --porcelain
(clean)
```
**No conflicts; `main` can fast-forward.**

**Commit order — required order met.** `git log main..codex/inventory-alignment-2026-09 --oneline` (oldest first):
`da82901` M-6 → `7d77f31` M-1 → `10a8c09` M-2 → `97bc4e0` M-3 → `057b142` M-4 → `ddfaa17` M-5 → `366b852` M-7 → `02df545` M-8 → `b0d082c` M-9, then `e22f945` (harness), `1440a7a` (evidence), `fcfa5be` (review follow-up), `e420c50` (docs). One commit per finding, M-6 first, M-1..M-9 in order. ✅

---

## 7. Harness files — keep `metro.config.js` on `main`, `cors-proxy.js` is already there

- `metro.config.js` (`e22f945`, 6 lines): `config.resolver.assetExts.push('wasm')`. This is the **documented Expo setup for `expo-sqlite` on web**, not a local hack — without it any web build of the app fails to load the SQLite WASM asset, dev *or* production. It is shipped config, and shipped config belongs on `main`. **Keep.** (Optional follow-up: Expo also recommends the COOP/COEP dev-server headers for SharedArrayBuffer; not needed for the current read paths.)
- `scripts/cors-proxy.js` is **already on `main`** (`git ls-tree main --name-only scripts/` → `scripts/cors-proxy.js`) and is not touched by the lane; the `:8016` retarget was local-only and was reverted (working tree clean). No action.
- Rule applied: a file the *bundler* needs to produce a correct build ships; a file only a human runs to point at an ad-hoc port does not get its port hardcoded. Both sides of that rule are satisfied.

---

## 8. What held up under attack

Things I actively tried to falsify and could not:

- **Phantom serverIds on the batch endpoint.** Covered three ways — uuid pre-check before the FK (`:1133-1140`), per-row savepoints (`:1151`), and an explicit `DeadlockException`/transaction-level rethrow (`:1417-1421`) so an aborted PG transaction fails loudly instead of returning 201 with rolled-back ids.
- **`location_id` being wiped by incremental product adds** — both add paths merge (`:799`, `:1409`), so a mobile `product_location` draft cannot be stranded at activation.
- **String-matching a localised refusal message** — the mobile branches on `error.code` only (`countingLifecycle.ts:36-39`).
- **A replayed count corrupting progress or the phase transition** — both derive from item rows (`InventoryCounting.php:334`, `InventoryCountingService.php:992-997`), not from the assignment counter (which does inflate — N-2).
- **A poison legacy draft blocking a whole batch** — `fcfa5be` parks it client-side before the request, and the row leaves the sync selector.
- **A silently-truncated 25-row list** from the request-hygiene programme — no mobile consumer touches a bounded endpoint (§4).

---

## 9. Message for the owner to paste into the Codex thread

```
Branch codex/inventory-alignment-2026-09 (e420c50) — ERP-side gate: CHANGES. Full report:
apps/erp/docs/superpowers/reviews/2026-09-04-erp-mobile-inventory-alignment-erp-side-gate.md
M-1..M-9 + fcfa5be all verify against the ERP (N-1 de31017e0). Jest 58/411 and tsc 0 reproduced;
merge-tree is conflict-free and main fast-forwards. Two blockers, both small — fix, then merge to main.

1. Delete isReviewTask (src/features/counting/lib/taskView.ts:29-31) and the "En vérification"
   StatTile (app/(app)/index.tsx:57, 216-219). GET /inventory/countings/my-tasks returns ONLY
   count_{1,2,3}_in_progress (InventoryCountingController.php:542-546), so the tile is always 0.
   M-4(d) was optional; drop it rather than ship a false zero.
2. Restore the zone NAME in the label. LocationNode.path is the ancestor CODE chain
   ("A1/R2/S1" — LocationNode.php:26), so app/(app)/counting/[id]/index.tsx:64 and
   create-draft.tsx:397 now print codes and never the name. Use zone.name in the session header
   and "<path> · <name>" in the picker; update countingSessionScreenLiveInventory.test.tsx and
   createDraftZoneScopeScreen.test.tsx.

Re-verify: npx jest --ci --runInBand && npx tsc --noEmit, then merge to main (fast-forward).
Non-blocking tickets (do NOT fix in this lane) are listed in §2 of the report — the duplicate-draft
race (N-1) and the stale scanner after back-navigation (N-5) are the two worth filing next.
```
