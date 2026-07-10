# Adversarial Review — Replenishment Requests Design Spec (2026-07-10)

- **Spec under review:** `docs/superpowers/specs/2026-07-10-replenishment-requests-design.md` (Rev 1)
- **Reviewers:** 3 parallel Claude adversarial domain reviewers (inventory-costing, fiscal-POS, tenancy-authz), all verifying claims against code with file:line citations. *Note: the review was first dispatched to Codex CLI; the Codex job hung after 2h (silent 1.5h after "writing the review") and was cancelled — Claude fallback per established convention.*
- **Combined verdict:** **APPROVE-WITH-CONDITIONS** (3× independently). 2 BLOCKER, 7 MAJOR, 7 MINOR. All resolved in spec **Rev 2** (same file, changelog at top).

## Verdict summary

| Lens | Verdict | Blockers | Majors |
|---|---|---|---|
| Inventory / transfers / costing | APPROVE-WITH-CONDITIONS | B1 | M1, M2, M3, M4 |
| POS device / offline sync | APPROVE-WITH-CONDITIONS | — | P1, P2 |
| Tenancy / authz / gating | APPROVE-WITH-CONDITIONS | T1, T2 | T3, T4, T5 |

## Findings

### BLOCKERS

**[B1] (inventory) `StockTransferInitiated` carries no line data — the auto-settlement matcher as specced cannot match.** Event payload is header-only (`StockTransferInitiated.php:13-23`: transferId, tenant/company, source/destination, initiator). Product/variant per line are not in the event; `StockTransferLine` cannot be imported across modules (rule 6) and no `Shared/Contracts` reader exposes transfer lines.
→ **Resolution (Rev 2 §5):** add a `Shared/Contracts` `TransferLineReader` (shape: `linesForTransfer(transferId): [{product_id, variant_id, quantity}]`, mirroring `LocationStockReader`); the listener takes destination/company from the event and lines from the reader. No event mutation (rule 8 untouched).

**[T1] (tenancy) §7 granted the new permissions to nobody but admin → silent 403 for the actual reviewer.** `admin` gets all permissions (`RolesAndPermissionsSeeder.php:427`); every other role is an explicit allow-list. The purchaser/warehouse-manager persona maps to `manager`, which would not hold `replenishment.*`.
→ **Resolution (Rev 2 §7):** explicit role grants enumerated — Manager: view+create+process; the web-capture staff role: view+create; Cashier: nothing new (POS path is terminal-gated).

**[T2] (tenancy) Web capture location picker had no allowed-location authorization (cross-location write).** A location-restricted user could inject requests into another shop's queue. Enforcement machinery exists and is used by `StockTransferController.php:48` (`LocationContext::validateLocationAccess()`, `LocationContext.php:224,253`).
→ **Resolution (Rev 2 §4):** web endpoint validates the submitted `location_id` via `LocationContext::validateLocationAccess()`; picker constrained to `getAllowedLocationIds()`.

### MAJORS

**[M1] (inventory) Settle-on-Initiated + transfer cancellation = permanently-fulfilled-but-never-delivered line.** `cancel()` from in_transit returns stock to source (`StockTransferService.php:310-351`) and emits `StockTransferCancelled`, which Rev 1 did not listen to; combined with "never resurrect," the shop's demand signal died silently.
→ **Resolution (Rev 2 §3/§5):** compensating listener on `StockTransferCancelled` re-opens matched `fulfilled` lines to `pending` (the single, explicit exception to never-resurrect), clearing the fulfillment link and noting the cancellation.

**[M2] (inventory) "Reviewer sets final quantities on the transfer" is unsupported — no editable draft transfer stage exists.** `initiate()` flips Draft→InTransit in one transaction and moves stock immediately (`StockTransferService.php:129-177`); the `autoInitiate` docblock in `InitiateTransferData.php:14-16` is stale.
→ **Resolution (Rev 2 §5):** quantities are set in the **review queue UI before** calling `initiate()` (prefilled from requested qty / min-max); wording corrected — there is no post-initiate quantity edit.

**[M3] (inventory) Dedupe index deviated from the repo pattern + bump concurrency unspecified.** Repo precedent is **two** partial unique indexes split on variant nullness (`2026_06_09_120000_add_variant_id_to_stock_transfer_lines.php:33-53`; same in channel mappings), not a `coalesce` sentinel. A read-then-update bump races concurrent POS syncs.
→ **Resolution (Rev 2 §3):** two partial unique indexes (variant NULL / NOT NULL) over open statuses; bump implemented as an atomic upsert with a unique-violation catch-and-retry fallback (PG `ON CONFLICT` arbitration against a partial index predicated on mutable `status` is flagged for the plan as the tricky bit).

**[M4] (inventory) "Composites are requestable/transfer-safe" overstated.** No `Composite` ProductType exists; composite availability is derived from components (`CompositeItemAvailabilityService.php:27-60`). Transfers move only real finished-good `stock_levels` rows; a made-to-order composite has none → `initiate()` throws `InsufficientStockException`.
→ **Resolution (Rev 2 §1/§2):** claim qualified — requesting any product is fine (the request is a signal); the **transfer path works only for produced-into-stock composites**; made-to-order composites surface as un-transferable at fulfillment time. F&B phase must pair with production stocking (composite inventory work is deferred post-parapharmacy-launch).

**[P1] (POS) Dedupe collision must resolve to HTTP-success bump, never 409.** The template's push driver rethrows non-contract errors → retry forever (`pendingCustomerSyncService.ts:99-116`; `runFullSync` aborts and retries). A 409 on natural-key collision (cross-device duplicate) would permanently wedge the outbox.
→ **Resolution (Rev 2 §4):** endpoint contract stated — insert-or-bump returns **200 with the bumped line**; only genuine contract violations (cross-tenant/company) return 4xx → client `markFailed` (permanent).

**[P2] (POS) "Location server-resolved, never trusted from payload" overstated.** The pending-customer template resolves no location; the terminal→location pattern lives in `PosStockLevelController.php:69-88`, where `terminal_id` is a **client-supplied payload field validated to the company** (token is not terminal-bound, `PosAuthController.php:136-148`).
→ **Resolution (Rev 2 §4):** contract reworded — capture takes `terminal_id`, validated to the authenticated company; `location_id` derived from the terminal row server-side and never accepted directly; test added asserting a payload `location_id` is ignored.

**[T3] (tenancy) Requester-read scoping was undefined** (cashier at shop A could read shop B's lines under the naive implementation).
→ **Resolution (Rev 2 §6):** POS reads scoped to the terminal's location; web reads scoped to `getAllowedLocationIds()` (fail-closed); unrestricted users (reviewers) see all locations by design.

**[T4] (tenancy) `replenishment.process` alone would authorize transfer/PO creation (privesc side-door).** `StockTransferService::initiate()` has no internal permission check; enforcement lives at route middleware, which a Replenishment controller bypasses.
→ **Resolution (Rev 2 §5/§7):** at the point of creating the downstream doc, the controller additionally `Gate::authorize`s `inventory.transfers.create` / `purchase-orders.create`. `replenishment.process` does not subsume downstream permissions.

**[T5] (tenancy) Company derivation / location-belongs-to-company / settlement match key unspecified.**
→ **Resolution (Rev 2 §4/§5):** `tenant_id`/`company_id` from `CompanyContext` (never payload); submitted/derived `location_id` validated to belong to the active company; auto-settlement match predicate includes `company_id`.

### MINORS (all applied in Rev 2)

- **[P3]** "Already requested {date}" needs a **net-new pull feed** (endpoint + pull step after push phase); precedent `PosStockLevelController` + `pullLocationStock`. Budgeted explicitly in §4/§9.
- **[P4]** Outbox timestamps: follow the template exactly — JS-supplied ISO, **no `DEFAULT (datetime('now'))`**; any device-side comparison via `toSqliteUtc()` (§4/§8).
- **[P5/M3-adjunct]** PG `ON CONFLICT` + partial-index inference is finicky — unique-violation catch fallback mandated (§3).
- **[m1]** `requested_qty` pinned to `decimal(15,4)` + FormRequest regex `/^\d+(\.\d{1,4})?$/` (§3/§8).
- **[T6]** POS endpoint gating pattern: controller-level `Gate::authorize('pos.operate_terminal')` (POS routes.php has one shared group, no per-route `can:`) (§7).
- **[T7]** Permissions go in `RolesAndPermissionsSeeder` ONLY — `PermissionSeeder.php` exists but is not called during tenant provisioning (dead for tenants) (§7).
- **[T8]** New module needs its ServiceProvider registered (`bootstrap/providers.php`) — added to §7.

## Facts confirmed by reviewers (useful for the plan)

- `StockTransferService::initiate(InitiateTransferData)` multi-line + variant-aware + idempotency key (`InitiateTransferData.php:29,36`; idempotency short-circuit `StockTransferService.php:104-114`); transfer lines have the two-partial-index variant uniqueness pattern.
- `GET /products/{id}/stock-levels` (`Product/routes.php:62` → `ProductController::stockLevels()` :967) returns per-location `min_quantity`/`max_quantity` + `is_below_minimum` — review-queue context is served as specced.
- Pending-customer offline template confirmed end-to-end (`PosPendingCustomerController` idempotent replay 200 @51-72, unique-violation catch @97-103, cross-company 409 @42-49; outbox `ON CONFLICT DO UPDATE` repo; push-before-pull `runFullSync` @2047-2059; permanent-vs-transient error taxonomy).
- `pos.operate_terminal` is the correct ability (seeder :278; cashier holds it :544) — earlier recon's `pos.operate` was wrong.
- Procurement un-gated pattern verified (`Procurement/Presentation/routes.php:20-32`); middleware stack + per-route `can:` as specced; `permission:cache-reset` landmine correctly present in spec.
- No fiscal-chain involvement confirmed — non-fiscal POS write path has zero hash-chain side effects.

---

# Round 2 — Implementation-Plan Adversarial Review (2026-07-10)

- **Under review:** `docs/superpowers/plans/2026-07-10-replenishment-requests.md` (+ spec Rev 2) — 3 Claude domain reviewers (inventory, POS, tenancy). Executor context: Codex desktop autonomous.
- **Combined verdict:** CHANGES-REQUESTED → **all findings applied**; spec bumped to **Rev 3**, plan updated in place.

## Blockers found & resolutions

- **[R2-B1/B2/B3] (inventory) `DraftPurchaseOrderService` field omissions = guaranteed insert failures**: `documents.document_date` NOT NULL no default; `document_lines.line_number`+`description` NOT NULL; append path collides on `UNIQUE(document_id,line_number)` and left header totals stale. → Plan Task 7 now enumerates the full NOT-NULL field sets, append continues from `max(line_number)+1` and recomputes totals, with pinning tests.
- **[R2-B4] (inventory) POS bump path was NOT idempotent** — a retried bump (lost HTTP response) double-counted `requested_qty`/`request_count`; the uuid-replay guard only covered the insert path (natural key ≠ idempotency key on bumps, unlike the pending-customer template). → **Spec Rev 3**: new `replenishment_capture_receipts` ledger (tenant-scoped unique on `client_request_uuid`, records every applied capture, replay served from the ledger; cross-company uuid reuse → 409). Plan Tasks 1/2/4 updated + `test_bump_path_uuid_replay_is_idempotent`.
- **[R2-B5] (POS) Wire casing unspecified** — camelCase `#[TypeScript]` DTO vs snake_case TS clients; the exact class of bug that dead-shipped the pricing verdict endpoint. → Global Constraints now lock **snake_case wire keys** for all read resources + a casing round-trip item in the self-review checklist.

## Majors found & resolutions

- **[R2-M1] (inventory) Transfer idempotency key was selection-wide** → only the first destination group's transfer would ever be created. → Key now explicitly per (source, destination, group's request ids).
- **[R2-M2] (inventory) cross-company uuid test had no implementing logic** → implemented via the Rev 3 receipts ledger + `CrossCompanyReplayException` → 409.
- **[R2-M3] (inventory) settlement listener throw inside `DB::afterCommit` unguarded** → per-line try/catch log-and-continue mandated.
- **[R2-M4 / T-M2] (inventory+tenancy) `getAllowedLocationIds()` is three-valued (`null`=all, `[]`=none, `[ids]`)** — plan conflated null with empty → explicit branch code added to Task 3; processor bypass documented as deliberate (spec §6).
- **[R2-T1] (tenancy) cancel route missing `->whereUuid('id')`** (UUID-500 pitfall) → added, plus explicit in-controller ownership/permission/status checks.
- **[R2-T3] (tenancy) create-po references unscoped** (`existing_document_id` cross-company write vector; `supplier_id`, `destination_location_id`) → ScopedExists + company/type/status/supplier guards specified.
- **[R2-T4] (tenancy) Task 7 inline example risked an illegal `Product` import in Replenishment** → `DraftPurchaseOrderData` contract locked: Replenishment passes IDs only; Document module resolves price/tax/totals (via `DocumentLineTaxResolver`).
- **[R2-P1] (POS) `replaceOpenRequests` "delete-then-insert in one transaction"** — no transaction primitive exists; convention is upsert-first/delete-absent-after (avoids the empty-window read) → plan now clones `replaceAllStock`.
- **[R2-P2] (POS) error taxonomy self-contradictory** ("verbatim from template" vs status-based; a literal clone retries 409/422 forever) → explicit status-based catch block written into Task 10.
- **[R2-P3] (POS) out-of-stock search entry point had no task** → Task 11 now specifies `ProductCard.tsx` (`isOutOfStock` @22) + `ProductGrid` prop threading.

## Minors applied

Pull-site tenant/company scope note; POS pull `truncated` flag; `getOpenRequestForProduct` open-status filter; deploy commands named (`tenants:migrate` → `tenants:run "db:seed --class=RolesAndPermissionsSeeder"` → `permission:cache-reset` per tenant); listeners must not be `ShouldQueue` (global constraint); `decimal(15,2)` documents-money note (pre-existing; do not "fix").

## Verified-true highlights (round 2)

SQLite v60 is the next free version; outbox DDL rule-20-safe; 401-as-transient correct; no server-side audit-type whitelist; push-body symmetry; provider/seeder/route anchors all exact; `purchase-orders.create` + `inventory.transfers.create` already granted to Manager; no fiscal-chain contact anywhere in the plan.
