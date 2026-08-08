# OpenAPI Lane — Codebase Findings Register

**Purpose:** collection point for genuine codebase defects/improvements surfaced by the zero-behavior OpenAPI contract lane (`codex/openapi-contract-a-to-z`). Per owner ruling 2026-08-07: findings are **collected here, never fixed in the lane** — fixing in-lane would break the `route:list` byte-identity gate and the zero-behavior proof. This register becomes a **separate dispatch proposal** when the lane completes (or earlier if something severe surfaces).

**Maintained by:** the lane's governance orchestrator (outside the lane).
**Disposition rule:** if closing a finding would change a single byte of `route:list` output or any runtime behavior, it belongs here, not in the lane.

---

## F-1 — Free-object schema nodes at the serialization boundary (class is far larger than first measured)

- **Evidence:** originally 37 free-object nodes (`Invoice.metadata`, `Invoice.billing_address` — see `2026-08-07-openapi-directional-budget-correction.md`). **Update 2026-08-07 (Phase 10.1.30 `f5b2b7465`):** the lane's predicate missed `additionalProperties: {}` leaks; the corrected measurement adds **248 free-object target identities** (244 tenant / 4 admin) across 234 operations. Leaked components include `AccountData`, `CategoryData`, `PartnerData`, `RecipeLineData`, `UnitData`, `VehicleData`, workshop DTOs, inventory-counting collections, financial-report projections, and the admin failed-job list (`MonitoringService.php:575-588`).
- **Important split (changes the disposition):** the class has two distinct sub-causes —
  1. **Genuinely untyped data** (e.g. `Invoice.metadata` JSONB with no visible DTO) → CLAUDE.md rule-3 gap, needs DTOs written. Code defect.
  2. **Fully-typed DTOs invisible to the generator** (verified: `RecipeLineData` is a complete Spatie Data DTO with 15 typed properties, yet Scramble emits `{}`) → NOT a code defect; a generator-inference gap the spec lane closes with source-derived overlays. Only the rule-19 aspects (if any) need code work.
- **Proposed disposition:** the typing session should first partition the leaked-component list by sub-cause using the lane's evidence manifest, then write DTOs only for sub-cause 1. Sub-cause 2 needs no code change.
- **Severity:** Medium (contract truthfulness + rule-3 compliance; no known runtime bug).

## F-2 — 342 empty-items nodes (response array item types exist only in convention)

- **Evidence:** lane inventory: 342 empty-items nodes (e.g. `LengthAwarePaginator` item types — admin and tenant consumers listed in the budget-correction ruling).
- **Why it matters:** response array item shapes are undeclared — the class of bug (double-unwrap, `{data,meta}` envelope drift) that currently has no systematic guard. The burn-down is a genuine typing improvement to the response layer.
- **Proposed disposition:** separate burn-down lane, priority-ordered by traffic/surface (external/admin first). Large; needs its own plan.
- **Severity:** Medium (systemic, no single acute bug).

## F-3 — 84 action bodies hand-compose the same `X-Request-ID` metadata blob inline

- **Evidence:** lane breadth-cap proof (`docs/superpowers/reviews/2026-08-07-openapi-breadth-cap-no-go.md`, union SHA-256 `eecd1660…`): 84 operations compose the identical `request_id` metadata shape inline with no shared producer; reflection-proven shape-identical.
- **Why it matters:** textbook DRY defect. The lane models it with a spec-level `$ref` carrier (Ruling 1, `b5e4bda00`) and is FORBIDDEN from refactoring the action bodies.
- **Proposed disposition:** separate behavior-preserving refactor lane (extract a shared producer), if the owner wants it. The lane's carrier membership manifest is the ready-made work list.
- **Update 2026-08-07 (Phase 10.1.27 freeze):** the full reflection pass proved the pattern is broader than the 84-op lower bound — the `RequestId` carrier has **121 member operations / 199 target identities** (op-set SHA-256 `bc537c32…`, manifest in `apps/api/openapi/feasibility/inventory.json` on the lane branch). The refactor work list is 121 ops, not 84.
- **Severity:** Low-Medium (maintainability; consistent today by luck/copy-paste).

## F-6 — Withholding controller `streamPDF` method actually downloads (naming/behavior mismatch)

- **Evidence:** lane breadth-classification freeze (`docs/superpowers/reviews/2026-08-07-openapi-breadth-classification-freeze.md`): the withholding controller's "misleadingly named `streamPDF` delegation" was admitted to the `PdfAttachmentResponse` (attachment-disposition) carrier only because the reflected service method ends in `download()`. Contrast POS `ReceiptController::streamPdf` (`app/Modules/POS/Presentation/Controllers/ReceiptController.php:704-730`), which genuinely streams inline.
- **Why it matters:** method name promises inline streaming, behavior is attachment download — a trap for the next caller and for spec readers.
- **Proposed disposition:** rename (or switch behavior deliberately) in a behavior-aware session; trivially small but touches response headers, so out of scope for the zero-behavior lane.
- **Severity:** Low.

## F-4 — Undocumented / orphan routes (pending lane handback)

- **Evidence:** owed by the lane handback per brief §"done" item 4: every undocumented/orphan route discovered, reported, never deleted.
- **Proposed disposition:** append the lane's list here verbatim on handback; deletion/documentation decisions are a separate lane.
- **Status:** ⏳ awaiting handback.

## F-5 — Spec-vs-code contradictions where the code is wrong (pending)

- **Evidence:** none yet; category reserved per handover §8.
- **Status:** ⏳ awaiting lane reports.

## F-7 — BatchExpiry FEFO suggestion pipeline runs quantities on native floats end-to-end (precision-contract violation, POS-serving endpoint) ✅ FIXED ON BRANCH

> **STATUS (2026-08-08): FIXED ON BRANCH `fix/f7-fefo-quantity-precision` — pending merge.**
> Worktree `apps/erp.fix-f7-fefo`. Both adversarial gates returned APPROVE-WITH-FIXES (inventory-costing, fiscal-pos); no criticals; all required fixes applied on-branch.
>
> **The defect was user-visible, not merely a contract violation.** Runtime proof captured on the pre-fix code: requesting `1.1` against lots `[0.7, 0.4]` — which exactly cover it — returned
> `{"fully_fulfilled":false,"shortfall":1.1102230246251565e-16,"total_quantity_suggested":1.1}`,
> a false shortfall, because `1.1 - 0.7 == 0.4000000000000001` in IEEE 754. A second case (`1.2505` against a `0.3` lot) emitted `shortfall: 0.9504999999999999`.
>
> **Fix:** quantities run on bcmath decimal strings end-to-end (`QuantityScale::round` at the boundaries, `bcadd`/`bcsub`/`bccomp` throughout); the rule-19 regex ceiling `/^\d{1,11}(\.\d{1,4})?$/` is on the validator; both DTOs and the wire emit canonical scale-4 numeric strings. The four in-process callers (3 document converters + `StockReservationService`) already re-cast to string, so only their argument casts were dropped.
>
> **Device-consumer audit (mandatory pre-fix gate) — CLEARED.** The **POS device (`apps/pos`) has ZERO consumers** of this endpoint; exhaustive grep for the route, `batches`, `fefo`, `shortfall`, `suggestion` returned only unrelated hits (chunking `BATCH_SIZE` constants, image batching, a module-gating fixture). The only device call on that path prefix is `apps/pos/src/api/stockDistributionApi.ts:24` → `/pos/products/{id}/stock-distribution`, a different endpoint. On the web side the single caller chain (`features/batches/api/batches.ts:124` → `hooks/useBatches.ts:125`) has **no rendering surface** — only a tenant-scope test — so number→string broke nothing. Web types and the request path were converted to decimal strings in the same branch.
>
> **Residuals, disclosures and the scope extension below → [`docs/superpowers/tickets/2026-08-08-f7-fefo-residuals.md`](../tickets/2026-08-08-f7-fefo-residuals.md)**, which carries: the merge disclosures (validation tightening D-1; B2B document-composition change D-2), the still-unfixed float compare on the `BatchStockService` issue/transfer **guard** path, the owed Postgres integration test for the `getRawOriginal()` line, and the 10-endpoint `BatchResource` scope extension.

- **Evidence (independently re-verified 2026-08-07):** `GET /api/v1/pos/products/{productId}/batches` → `BatchController::posAvailableBatches`:
  - `app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:295-310` — validates `quantity` as bare `numeric` (no regex ceiling, violating rule 19's FormRequest requirement) and casts `(float) $request->input('quantity')`;
  - `app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:35-91` — `float $quantity` parameter, native float arithmetic (`$remaining -= $takeQuantity`, `min()`, `max(0, $remaining)`);
  - `app/Modules/BatchExpiry/Application/DTOs/BatchSuggestionDTO.php` — `public float $quantity`, serialized unchanged;
  - `app/Modules/BatchExpiry/Application/DTOs/BatchSuggestionResultDTO.php` — `public float $shortfall`, `total_quantity_suggested` via native `array_sum()`;
  - runtime witness (lane, production serializer path): `{"suggestions":[],"fully_fulfilled":false,"shortfall":1.25,"total_quantity_suggested":0}` — JSON numbers on the wire.
- **Why it matters:** direct violation of the post-2026-05-28 precision contract ("never let a float touch money or quantity") on an endpoint the POS device consumes for FEFO batch suggestions. Fractional quantities (pharmacy units) are exposed to float representation error in suggestion math. This stalled the OpenAPI lane (NO-GO `3546dfe7d`) until superseding ruling `2cbc21555` (truth-dominates + deviation marker).
- **Proposed disposition:** dedicated behavior-change lane: convert the pipeline to numeric strings + bcmath (`QuantityScale`), add the rule-19 regex ceiling to the validator, **with POS client compatibility work** (wire format flips number→string; device consumers must be audited first). After the fix, the OpenAPI spec regenerates, the `x-precision-contract-deviation` marker disappears, and the CI drift gate proves the improvement.
- **Severity:** High (contract violation + float arithmetic on quantities in production; launch-relevant surface). Related: [project_precision_drift_remediation] Ph2-12 remain — this pipeline should join that program's scope if not already in it.
- **Note:** the lane's deviation register (owed in the handback per ruling `2cbc21555`) will enumerate any FURTHER float-wire nodes among the 305 remaining targets — expect this finding to grow a work list.
- **Scope extension (2026-08-07, full-contract closure `fe0c9f33e`):** the deviation register proves the float-quantity wire is module-wide, not FEFO-only — `BatchResource.available_quantity` (float accessors `Batch::getTotalQuantityAttribute`/`getAvailableQuantityAttribute`, `Batch.php:127-135`; `BatchResource.php:20-65`) affects **10 batch endpoints** (`/v1/batches` list/show/create/patch, expired, expiring, recall, transfer, write-off, `/v1/products/{id}/batch-stock`). The fix lane should target the whole BatchExpiry module's quantity path.
  - **NOT done by the `fix/f7-fefo-quantity-precision` lane (2026-08-08)** — deliberately deferred. Unlike the FEFO endpoint, these 10 endpoints have real web consumers and `apps/web/src/features/batches/types.ts` documents `ExpiredBatch.total_quantity`/`.available_quantity` as JSON numbers *on purpose*, so flipping them needs its own consumer audit. Tracked as residual (c) in [`2026-08-08-f7-fefo-residuals.md`](../tickets/2026-08-08-f7-fefo-residuals.md).

## F-8 — Scheduling appointment money accepted as unbounded numeric input (rule-19 gap, external surface) 

- **Evidence:** lane wire-format deviation register (`full-closure-manifest.json`, `fe0c9f33e`): `money-unbounded-numeric-input` on `POST /v1/storefront/{company_id}/appointments` (external) and `POST /v1/scheduling/appointments` (tenant). Proving ranges: `StoreAppointmentRequest@rules` (34-67), `BookAppointmentStorefrontRequest@rules` (41-78), `AppointmentController@store` (114-159), `StorefrontBookingController@store` (44-118).
- **Why it matters:** money fields validated as bare `numeric` with no regex ceiling (CLAUDE.md rule 19 requires the money ceiling on FormRequests), accepting unbounded JSON numbers — on a **public storefront surface**.
- **Proposed disposition:** small bounded fix — add rule-19 regex ceilings to both FormRequests and string-path the downstream handling; belongs with the F-7 precision fix lane or the precision-drift remediation program.
- **Severity:** Medium-High (public external surface + money precision).

## F-9 — Arbitrary-JSON boundary fields (deliberate raw-JSON pass-throughs, rule-3 review candidates)

- **Evidence:** closure manifest `non_target_truth_corrections` ([4]/[5]): 14 nodes closed with source-proven `JsonContainer`/`JsonMap`/`JsonMapList` carriers because production genuinely accepts/emits arbitrary JSON: `vehicle_context.snapshot` + `additional_data` (Create/UpdateDocumentRequest, VehicleContextData), `GoodsReceiptData.payload`, `OrderLineData.modifiers`, and the platform-catalog relay endpoints (manufacturers/model-series/vehicles pass upstream JSON through).
- **Why it matters:** these are deliberate unconstrained boundaries, now honestly documented. Each deserves a one-time review: is arbitrary JSON intended (relay: yes) or a missing DTO (rule 3: `snapshot`/`payload`/`modifiers` likely have real shapes in practice)?
- **Proposed disposition:** fold into the F-1 typing session's partition step.
- **Severity:** Low-Medium.

## F-10 — 13 direct module test paths are RED in the current tree (pre-existing; blocks lane final acceptance + CLI/MCP stretch) ⚠️ GATE-BLOCKING

- **Evidence:** lane adversarial verdict (`2026-08-07-openapi-full-contract-adversarial-verdict.md`, `4cd85e822`), scoped module-test matrix run path-by-path (never as a monolith): red paths are **Accounting** (fresh PG: 3E/11F — fixture CHECK constraints, persistent journal/chart state), **Catalog** (5F), **Company** (7F — all the same decimal-formatting failure on company-creation responses), **Document** (13E — stale direct `CreateDocumentRequest` construction), **Fiscal** (13E/14F — Treasury bridge construction + chokepoint completeness), **Identity** (1F), **Inventory** (1F), **Product** (3F), **Service** (10E/32F), **Taxation** (1F), **Tenant** (1F), **Treasury** (fresh PG: stopped 484/895 after 1E/3F), **Workshop** (8E). 31 module paths are green; BatchExpiry green under correct PG-split invocation.
- **Why it matters:** these are pre-existing failures on the dev tree — NOT caused by the lane (zero production/test files edited; route proof byte-identical). They make the lane's "existing tests green" acceptance gate red and block the CLI/MCP stretch. They are also live test debt on a launch-bound codebase.
- **Proposed disposition:** dedicated test-remediation session(s), module-clustered (the failure signatures suggest ~6 distinct root causes, e.g. one decimal-formatting fix likely clears all 7 Company failures). Re-run the lane's final acceptance after.
- **Severity:** High as a program blocker (individual failures vary).

## F-11 — Response-evidence gap: modules without real JSON-response tests

- **Evidence:** `2026-08-07-openapi-response-evidence-audit.md` (`4cd85e822`): **Billing, Communication, Income have ZERO direct JSON assertion sites**; Media has no direct module test directory; several modules concentrate JSON assertions in <3 files. The lane's acceptance requirement (3 real JSON-response tests per module) is unprovable from the current tree.
- **Proposed disposition:** targeted test-writing session, prioritized by surface criticality (Billing first — admin money endpoints). Pairs naturally with F-10 remediation.
- **Severity:** Medium-High (assurance gap, not a proven defect).

---

## Append log

| Date | Finding | Source (lane report/commit) | Routed |
|---|---|---|---|
| 2026-08-07 | F-1..F-3 seeded from handover §8 + ruling chain | `06e6b3309`, `64dc4e39d`, `b5e4bda00` | register created |
| 2026-08-07 | F-3 updated (84→121 ops), F-6 added (`streamPDF` misnomer) | Phase 10.1.27 `f9cd99713` | triage: progress report, GO, no ruling |
| 2026-08-07 | No new findings. 32-op tranche closed exactly (78 selected / 305 remainder, hashes verified, zero deletions, money-as-string verified) | Phase 10.1.28 `01630f0f9` | triage: progress report, GO, no ruling |
| 2026-08-07 | F-7 added (BatchExpiry float-quantity wire, High). Mandatory-stop NO-GO judged REAL; superseding ruling issued | Phase 10.1.29 `3546dfe7d` → ruling `2cbc21555` | triage: mandatory stop, ruled; resume dispatched by owner |
| 2026-08-07 | F-1 expanded (+248 free-object identities; typed-DTO-vs-untyped split documented). Truthfulness NO-GO judged REAL (lane's own gate bug); inventory superseded 383→631 | Phase 10.1.30 `f5b2b7465` → ruling `1adaf19cd` | triage: mandatory stop, ruled; resume dispatched by owner |
| 2026-08-07 | Sweep #2 RECONCILED clean — remainder frozen 553/412/410-2-0, no underivable constant, arithmetic verified. F-4 likely closes empty (zero orphan routes at full-surface gate, 38 reviewed exclusions owed in handback) | Phase 10.1.31 `4359941a4` | triage: deliverable per Ruling 4, no stop, lane continues |
| 2026-08-07 | Corrected breadth frozen: 70 carriers / 262 overlay identities (reported measures); 501/553 remainder closed, 52 pending. CANDIDATE finding: admin billing endpoints serialize nested Eloquent models (Invoice/Payment/Plan/TenantSubscription/Tenant) not DTOs — hold for handback shapes, then route to tenancy reviewer | Phase 10.1.32 `798195de9` | triage: progress report, GO, no ruling |
| 2026-08-07 | FULL CONTRACT generated: 553/553 closed, 0 pending, 0 permissive under corrected predicate; JsonValue pass-through carriers inspected and judged truthful (provably arbitrary-JSON boundaries); deviation register live (2 grouped entries, 11 spec markers). F-7 scope extended (10 batch endpoints), F-8 added (storefront money numeric), F-9 added (arbitrary-JSON boundaries) | Phase 10.1.33 `fe0c9f33e` | triage: progress report, no ruling; final zero-behavior gates + handback owed |
| 2026-08-08 | **F-7 FIXED ON BRANCH** `fix/f7-fefo-quantity-precision` (pending merge). Float defect proven user-visible (false 1.11e-16 shortfall on exactly-covered requests). Device-consumer audit CLEARED: POS device has zero consumers. Both adversarial gates APPROVE-WITH-FIXES, fixes applied. Residuals + merge disclosures → `docs/superpowers/tickets/2026-08-08-f7-fefo-residuals.md` | fix lane `fix/f7-fefo-quantity-precision` | F-7 status → fixed-on-branch; scope extension re-routed to residuals ticket |
| 2026-08-07 | HANDBACK. Contract GO / lane-complete NO-GO (honest). F-10 added (13 red pre-existing module paths, gate-blocking), F-11 added (response-evidence gap). Orchestrator dual gate: zero-behavior INDEPENDENTLY GREEN (route hash `ea1a35bca…` reproduced, diff scope clean, 78 OpenAPI unit tests green); truthfulness gate dispatched to independent Codex review | Phase 10.1.34 `4cd85e822` | triage: handback row; promotion pending truthfulness gate |
| 2026-08-07 | DUAL GATE ROUND 1: truthfulness FAIL (independent Codex review; all 4 ranked findings orchestrator-confirmed at cited lines — additionalProperties:true, 23 bare {}, 49+36 unmarked number nodes, false plan-usage + fiscal-ingestion contracts). PROMOTION BLOCKED; fix round scoped on-branch | Codex gate + verdict `94db998dd` | triage: REJECT to lane; resume prompt awaiting owner dispatch |
