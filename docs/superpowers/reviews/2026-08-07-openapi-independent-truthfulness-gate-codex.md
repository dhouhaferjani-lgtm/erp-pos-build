# Independent OpenAPI truthfulness gate — Codex

Review target: `/Users/houssamr/Projects/syneriva/apps/erp.openapi` at `4cd85e8222dabd68e29af9fd59d5613a0470304b` (`codex/openapi-contract-a-to-z`). This review did not rely on the lane's prior verdict as evidence. All `apps/...` and `docs/...` citations below refer to files personally opened in that target worktree except the explicitly identified main-repo precision contract.

Citation notation: a subsequent bare `:N` or `:N-M` in the same parenthetical citation inherits the immediately preceding full file path. It is an exact line citation to that same file, not an uncited assertion.

## 1. No permissive schemas — FAIL

An exhaustive parsed traversal of all three documents found no `additionalProperties: {}` and no `items: {}`. That does not save this property: the tenant document contains two explicit `additionalProperties: true` schemas in the `POST /v1/documents/auto-save` request, once on each line item and once on the root request (`apps/api/openapi/feasibility/tenant-full.json:26817`, `apps/api/openapi/feasibility/tenant-full.json:26827`). These are outside the sanctioned `JsonValue`/`JsonContainer`/`JsonMap`/`JsonMapList` references and are automatic failures.

The traversal also found the following bare `{}` schema nodes:

- Tenant bank-statement `lines_count`: list, create, show, void, complete, and reopen responses (`apps/api/openapi/feasibility/tenant-full.json:4904`, `:5038`, `:5173`, `:5395`, `:5515`, `:5622`).
- Tenant counting `quantity` (`apps/api/openapi/feasibility/tenant-full.json:18960`); ledger `account_filter` and `partner_filter` (`:45010`, `:45011`); split-validation `splits` and `total_required` (`:53938`, `:53939`); payment `allocation_method.anyOf[0]` (`:61433`); and purchase-order dynamic member `anyOf[0]` (`:75964`).
- Admin billing-dashboard `revenue_this_month` and `outstanding_invoices` (`apps/api/openapi/feasibility/admin-full.json:54`, `:55`).
- Admin monitoring-critical billing revenue `today`, `this_month`, `pending_invoices`, and `overdue_invoices` (`apps/api/openapi/feasibility/admin-full.json:2071`, `:2072`, `:2073`, `:2074`) and the duplicated monitoring-dashboard copies (`:3140`, `:3141`, `:3142`, `:3143`).

The external document's empty objects are security requirement objects, not schemas—for example anonymous login at `apps/api/openapi/feasibility/external-full.json:166` and public storefront availability at `:1224`—so they are correctly excluded from this finding.

Three independently checked sanctioned references are grounded:

1. `CreateDocumentRequest.vehicle_context.additional_data` references `JsonContainer` (`apps/api/openapi/feasibility/tenant-full.json:113415-113423`). Production validates only `nullable|array`, without member rules (`apps/api/app/Modules/Document/Presentation/Requests/CreateDocumentRequest.php:68-76`), so arbitrary JSON members are genuinely accepted.
2. `GET /v1/platform/catalog/manufacturers` returns `data` through `JsonContainer` (`apps/api/openapi/feasibility/tenant-full.json:9397-9413`). The service returns the upstream client's array unchanged (`apps/api/app/Modules/PlatformIntegration/Application/Services/CatalogBrowseService.php:313-328`), and the controller relays that value verbatim under `data` (`apps/api/app/Modules/PlatformIntegration/Presentation/Controllers/CatalogBrowseController.php:195-213`).
3. `GET /v1/purchase-hub/offers` uses `JsonMapList` (`apps/api/openapi/feasibility/tenant-full.json:75365-75373`). Production returns the upstream array without member shaping (`apps/api/app/Modules/PurchaseHub/Application/Services/PurchaseHubService.php:34-50`) and puts it directly under `data` (`apps/api/app/Modules/PurchaseHub/Presentation/Controllers/PurchaseHubOfferController.php:19-32`).

Those three are documented-but-ugly truths. They do not excuse the unsanctioned permissive schemas above.

## 2. Precision truth — FAIL

### 2(a). Eight string-schema samples — PASS

The canonical ceilings are money 3 dp, quantity 4 dp, and percentage 2 dp (`/Users/houssamr/Projects/syneriva/apps/erp/docs/architecture/precision-contract.md:30-38`). Eight sampled nodes match:

| Surface/node | Evidence | Result |
|---|---|---|
| Tenant POS sales-by-product `total` | money pattern `\\d{1,3}` (`apps/api/openapi/feasibility/tenant-full.json:1600-1603`) | correct |
| Tenant POS sales-by-product `quantity` | quantity pattern `\\d{1,4}` (`apps/api/openapi/feasibility/tenant-full.json:1605-1608`) | correct |
| Tenant B2B pricing-context `unit_price` | money pattern `\\d{1,3}` (`apps/api/openapi/feasibility/tenant-full.json:45575-45579`) | correct |
| Tenant loyalty preview `amount` | money pattern `\\d{1,3}` (`apps/api/openapi/feasibility/tenant-full.json:48073-48076`) | correct |
| Admin invoice-create item `amount` | money pattern `\\d{1,3}` (`apps/api/openapi/feasibility/admin-full.json:703-706`) | correct |
| Admin invoice-create item `quantity` | quantity pattern `\\d{1,4}` (`apps/api/openapi/feasibility/admin-full.json:708-714`) | correct |
| Admin `Invoice.subtotal` | money pattern `\\d{1,3}` (`apps/api/openapi/feasibility/admin-full.json:6145-6148`) | correct |
| Admin `Invoice.tax_rate` | percent pattern `\\d{1,2}` (`apps/api/openapi/feasibility/admin-full.json:6174-6177`) | correct |

The external document has no ordinary string-typed money/quantity/percent schema eligible for this ceiling sample. Its only precision input is storefront `estimated_price`, which references the explicitly deviation-marked arbitrary-precision input (`apps/api/openapi/feasibility/external-full.json:1672-1674`, `:2241-2257`). Pretending an eight-node sample could be spread across all three files would be false; the eight available ordinary samples are therefore spread across tenant and admin.

### 2(b). Number-typed precision nodes — FAIL

The exhaustive literal-`type: number` inventory contains 72 tenant nodes, 10 admin nodes, and one external node. The following precision-like nodes are properly marked and are not failures:

- Tenant batch quantity input, `shortfall`, and `total_quantity_suggested` (`apps/api/openapi/feasibility/tenant-full.json:6693-6702`, `:6786-6789`, `:6850-6853`); `BatchResource.total_quantity` and `.available_quantity` (`:111844-111847`, `:111883-111886`); arbitrary money input (`:130239-130252`); `BatchSuggestionWireRow.quantity` (`:130345-130348`); counting `percentage` (`:132236-132239`); import `progress_percentage` (`:134072-134075`); and flagged counting `variance` (`:134639-134644`).
- Admin `MonitoringPercentNumber`, `PlanUsagePercentNumber`, and `PlanOverageMoneyNumber` (`apps/api/openapi/feasibility/admin-full.json:7279-7282`, `:7355-7358`, `:7398-7401`).
- External arbitrary money input (`apps/api/openapi/feasibility/external-full.json:2241-2257`).

Three marked cases were independently traced and are genuine JSON numbers:

- Batch suggestion quantity is a PHP `float` and is returned without conversion (`apps/api/app/Modules/BatchExpiry/Application/DTOs/BatchSuggestionDTO.php:13-27`); aggregate `shortfall` and `total_quantity_suggested` are also floats/native sums (`apps/api/app/Modules/BatchExpiry/Application/DTOs/BatchSuggestionResultDTO.php:12-30`).
- Import progress returns `float`, including `0.0`, and performs native arithmetic (`apps/api/app/Modules/Import/Domain/ImportJob.php:143-153`); the controller emits that value directly (`apps/api/app/Modules/Import/Presentation/Controllers/ImportController.php:612-630`).
- Admin plan overage explicitly casts `price_per_user` to float and multiplies it natively (`apps/api/app/Modules/Billing/Application/Services/PlanEnforcementService.php:411-433`), then the super-admin controller returns it unchanged (`apps/api/app/Http/Controllers/Api/Admin/SuperAdminController.php:149-156`).

The following precision-like literal `type: number` nodes have **no** `x-precision-contract-deviation`. Every entry is an automatic failure; paths are operations unless identified as a component plus its operation usage.

- Company reservation settings: `high_value_alert_threshold`, `inventory_count_trigger_threshold`, `goodwill_named_customer_threshold`, and `goodwill_four_eyes_threshold` in `PUT /v1/companies/{companyId}/reservation-settings` (`apps/api/openapi/feasibility/tenant-full.json:14961-14969`, `:15073-15082`). Production treats the goodwill values as currency-scaled amounts (`apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php:425-429`).
- Catalog/document/marketplace: `PATCH /v1/variants/{id}.price_adjustment` (`apps/api/openapi/feasibility/tenant-full.json:17693-17695`); landed-cost `allocated_costs` (`:24291-24299`); `GET /v1/marketplace/price-comparison` query `current_price` (`:49538-49546`); `PATCH /v1/modifiers/{id}.price_adjustment` (`:52080-52082`). Production's modifier request applies a 4-dp precision ceiling (`apps/api/app/Modules/Catalog/Presentation/Requests/StoreModifierRequest.php:33-39`).
- Inventory progress `overall` in nine operations: my-tasks, create draft, update draft, activate draft, create counting, show counting, report, activate, and finalize (`apps/api/openapi/feasibility/tenant-full.json:37395-37398`, `:37747-37750`, `:38786-38789`, `:39413-39416`, `:39931-39934`, `:40342-40345`, `:40773-40776`, `:41687-41690`, `:42200-42203`).
- Loyalty and VAT: preview `points_to_earn` (`apps/api/openapi/feasibility/tenant-full.json:48131-48134`); dynamic VAT `special_items` number member and declaration `fields` number member (`:104806-104820`, `:104830-104844`); `AdjustPointsRequest.points` used by `POST /v1/loyalty/members/{memberId}/enrollments/{enrollmentId}/adjust` (`:111429-111432`).
- POS/Treasury/Taxation: `CloseShiftRequest.actual_cash` used by `POST /v1/pos/shifts/{id}/close` (`apps/api/openapi/feasibility/tenant-full.json:112615-112618`); `ConfirmBankStatementRequest.opening_balance` and `.closing_balance` used by `POST /v1/bank-statements` (`:112877-112885`); `OpenShiftRequest.opening_cash` (`:119487-119490`); `RecordSalesWithholdingRequest.expected_receivable` (`:120529-120532`); and `SyncShiftCloseRequest.actual_cash` (`:122991-122994`). Production itself applies 3-dp regexes to these fields (`apps/api/app/Modules/POS/Presentation/Requests/CloseShiftRequest.php:27-31`, `apps/api/app/Modules/Treasury/Presentation/Requests/ConfirmBankStatementRequest.php:19-26`, `apps/api/app/Modules/Taxation/Presentation/Requests/RecordSalesWithholdingRequest.php:48-51`).
- Loyalty create components: `CreateEarningRuleRequest.reward_value` (`apps/api/openapi/feasibility/tenant-full.json:113717-113720`); `CreateRewardRequest.points_cost` (`:115237-115240`); `CreateTierRequest.qualification_threshold`, `.earning_multiplier`, and `.benefits.birthday_bonus_multiplier` (`:115901-115918`, `:115937-115940`). These are used by the corresponding POST earning-rule/reward/tier operations; production imposes 2-dp ceilings on all three tier values (`apps/api/app/Modules/Loyalty/Presentation/Requests/CreateTierRequest.php:33-42`).
- Other create components: `CreateUnitRequest.conversion_factor` (`apps/api/openapi/feasibility/tenant-full.json:116010-116013`), `CreateWithholdingRuleRequest.rate` (`:116346-116349`), `StoreCouponRequest.discount_value` (`:121958-121961`), `StoreModifierRequest.price_adjustment` (`:122210-122213`), `StorePromotionRequest.discount_value` (`:122318-122321`), and `StoreVariantRequest.price_adjustment` (`:122862-122865`). They are used by their corresponding UOM, withholding, coupon, modifier, promotion, and variant POST operations.
- Update components: `UpdateCompanyRequest.default_target_margin` and `.default_minimum_margin` in `PUT /v1/companies/{companyId}` (`apps/api/openapi/feasibility/tenant-full.json:124250-124260`); `UpdateCouponRequest.discount_value` (`:124607-124610`); `UpdateEarningRuleRequest.reward_value` (`:124948-124951`); `UpdatePromotionRequest.discount_value` (`:126475-126478`); `UpdateRewardRequest.points_cost` (`:126780-126783`); `UpdateTierRequest.qualification_threshold`, `.earning_multiplier`, and `.benefits.birthday_bonus_multiplier` (`:127301-127318`, `:127337-127340`); `UpdateUnitRequest.conversion_factor` (`:127421-127424`); and `UpdateWithholdingRuleRequest.rate` (`:127669-127672`). Production explicitly treats the company margins as 2-dp percentages (`apps/api/app/Modules/Company/Presentation/Requests/UpdateCompanyRequest.php:52-55`).

A broader number-typed scan also found nullable `type: [number, null]` precision nodes without deviation markers. They are not omitted merely because the generator represented nullability as a type array:

- Category margin overrides in create/update (`apps/api/openapi/feasibility/tenant-full.json:12236`, `:12245`, `:12498`, `:12507`); refund/goodwill caps (`:15024`, `:15085`); counting quantity variance (`:19221`); and payment-method fixed fees (`:66068`, `:66453`).
- Document-ingestion `freeQuantity`, `unitPrice`, and `vatRate` (`apps/api/openapi/feasibility/tenant-full.json:112688`, `:112697`, `:112706`); earning-rule maximums (`:113746`, `:113755`, `:124977`, `:124986`); partner credit limits (`:114272`, `:125524`); and product margin overrides/reorder point/weight in create and update (`:114445`, `:114454`, `:114547`, `:114796`, `:125768`, `:125777`, `:125863`, `:126096`).
- Reward `reward_value`, `max_discount`, and `min_order_value` in create/update (`apps/api/openapi/feasibility/tenant-full.json:115244`, `:115253`, `:115262`, `:126787`, `:126796`, `:126805`); enrollment `welcome_bonus` (`:117081`); composite-item `manual_cost` create/update (`:121876`, `:124464`); and stock-transfer `transfer_cost` (`:122693`).

Calling these `non-precision-number` does not cure the omission. The source applies explicit decimal ceilings to representative affected fields, while the contract supplies no deviation disclosure.

## 3. Surface separation — PASS

An exhaustive set comparison of the `paths` objects—rooted at `apps/api/openapi/feasibility/tenant-full.json:14`, `apps/api/openapi/feasibility/admin-full.json:14`, and `apps/api/openapi/feasibility/external-full.json:14`—found zero tenant/admin intersections, zero tenant/external intersections, and zero admin/external intersections.

Three admin spot checks are correctly separated:

- `/v1/admin/dashboard` (`apps/api/openapi/feasibility/admin-full.json:3871`) is mounted inside the `v1` then `admin` prefix and protected by `auth:sanctum-admin`, `super_admin`, and `throttle:admin-sensitive` (`apps/api/routes/api.php:25`, `:56-61`).
- `/v1/admin/monitoring/system` (`apps/api/openapi/feasibility/admin-full.json:1324`) is inside that same protected admin group and its monitoring subgroup (`apps/api/routes/api.php:81-89`).
- `/v1/admin/billing/invoices` (`apps/api/openapi/feasibility/admin-full.json:502`) is inside the protected admin group's billing prefix (`apps/api/routes/api.php:99-115`).

Three external spot checks are correctly separated:

- `/v1/auth/login` (`apps/api/openapi/feasibility/external-full.json:15`) is in the public `api/v1/auth` group with only `web` plus login throttling; the protected auth subgroup starts later (`apps/api/app/Modules/Identity/routes.php:21-40`).
- `/v1/countries` (`apps/api/openapi/feasibility/external-full.json:592`) is declared directly as a public route before protected middleware groups (`apps/api/routes/api.php:25-35`, `:50-54`).
- `/v1/storefront/{company_id}/availability` (`apps/api/openapi/feasibility/external-full.json:1072`) is explicitly documented and mounted as public with `api` plus per-route throttling, with no `auth:sanctum` (`apps/api/app/Modules/Scheduling/Presentation/routes.php:22-30`, `:37-48`).

## 4. `unit_price` semantics — FAIL

The descriptions that do exist are semantically correct:

- POS `OrderLineData.unit_price` says tax-inclusive/TTC (`apps/api/openapi/feasibility/tenant-full.json:129339-129348`). Production's fiscal validator independently confirms that canonical POS `unit_price` is the cart's tax-inclusive gross price and `line_subtotal` is net (`apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:2171-2177`).
- B2B `CreateDocumentRequest.lines[].unit_price` says net/HT (`apps/api/openapi/feasibility/tenant-full.json:113520-113537`).
- These match the architectural ruling that POS is inclusive and B2B/Documents are net (`/Users/houssamr/Projects/syneriva/apps/erp/docs/architecture/precision-contract.md:192-203`).

However, the distinction is omitted at the authoritative SALE_RECEIPT ingress where it matters most. `POST /v1/pos/sync/fiscal-events` points to `IngestFiscalEventsRequest` (`apps/api/openapi/feasibility/tenant-full.json:30448-30460`), whose `payload` is falsely documented as an array of strings (`:118738-118744`). Production says that payload is a sub-object (`apps/api/app/Modules/Fiscal/Presentation/Requests/IngestFiscalEventsRequest.php:10-18`), validates it as an array/object carrier (`:49-56`), and merges its associative members into the fiscal envelope (`apps/api/app/Modules/Fiscal/Presentation/Controllers/FiscalEventIngestionController.php:144-164`). Consequently the OpenAPI contract exposes no SALE_RECEIPT `line_items[].unit_price` field and cannot carry the required TTC description on the actual device-to-server sale contract. A correct POS-adjacent component elsewhere does not repair this omission.

## 5. Random operation truth sample — FAIL

Five operations across different modules/surfaces were traced through runtime code.

### 5.1 `GET /v1/pos/products/{productId}/batches` (BatchExpiry, tenant) — PASS

The controller validates query input and returns `{data: $result->toArray()}` with status 200 (`apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:288-311`). The DTO emits `quantity`, `shortfall`, and the summed total as JSON numbers, plus the documented strings/integers/boolean (`apps/api/app/Modules/BatchExpiry/Application/DTOs/BatchSuggestionDTO.php:13-33`, `apps/api/app/Modules/BatchExpiry/Application/DTOs/BatchSuggestionResultDTO.php:12-31`). The contract matches the `data` envelope, required fields, 200/422/401 statuses, and marked numeric deviations (`apps/api/openapi/feasibility/tenant-full.json:6766-6925`, `:6930-6946`, `:130335-130423`).

### 5.2 `GET /v1/platform/catalog/manufacturers` (PlatformIntegration, tenant) — PASS

The action calls the upstream service and delegates to `respond` (`apps/api/app/Modules/PlatformIntegration/Presentation/Controllers/CatalogBrowseController.php:21-28`). Runtime emits 200 `{data: arbitrary upstream array, meta: {timestamp, request_id}}` or the same envelope with `data: null` and 502 (`:195-213`). The contract documents those two shapes and 401 accurately (`apps/api/openapi/feasibility/tenant-full.json:9397-9479`).

### 5.3 `POST /v1/auth/login` (Identity, external) — PASS

Runtime has two 200 envelopes: organization selection (`apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:204-226`) and normal login (`:310-323`), plus 403 organization-unavailable (`:268-276`) and validation-driven 422 branches (`:197-201`, `:256-265`). `LoginResponseData` makes `deviceId` nullable while user/token/tokenType remain required (`apps/api/app/Modules/Identity/Application/DTOs/LoginResponseData.php:9-17`); `AuthUserData` independently establishes its nullable fields (`apps/api/app/Modules/Identity/Application/DTOs/AuthUserData.php:17-30`). The OpenAPI `anyOf`, statuses, envelope, and nullability match (`apps/api/openapi/feasibility/external-full.json:33-164`, `:2213-2239`).

### 5.4 `GET /v1/admin/tenants/{id}/plan-usage` (Admin/Billing, admin) — FAIL

The top-level `{data: ...}` envelope is correct (`apps/api/app/Http/Controllers/Api/Admin/SuperAdminController.php:149-156`; `apps/api/openapi/feasibility/admin-full.json:4262-4272`). The member schema is not:

- Runtime usage `current` values are database counts typed and assigned as integers (`apps/api/app/Modules/Billing/Application/Services/PlanEnforcementService.php:268-304`), while the contract says `string` for every `current` member (`apps/api/openapi/feasibility/admin-full.json:4343-4348`, `:4363-4368`, `:4383-4388`, `:4403-4408`, `:4423-4428`, `:4443-4448`). Code is correct; the document is wrong.
- Runtime `subscription.current_period_end` and `trial_ends_at` are nullable through `?->`, and `is_on_trial` is a boolean (`apps/api/app/Modules/Billing/Application/Services/PlanEnforcementService.php:457-463`). The contract requires both dates as non-null strings and falsely types `is_on_trial` as string (`apps/api/openapi/feasibility/admin-full.json:4319-4335`).
- `Tenant::findOrFail` produces a 404 for a missing tenant (`apps/api/app/Http/Controllers/Api/Admin/SuperAdminController.php:150-153`), but the operation documents only 200 and 401 (`apps/api/openapi/feasibility/admin-full.json:4262-4264`, `:4638-4641`).

### 5.5 `POST /v1/pos/sync/fiscal-events` (Fiscal, tenant) — FAIL

The 200 response envelope and most result fields match, but `exception_class` does not. The contract requires a non-null string (`apps/api/openapi/feasibility/tenant-full.json:30489-30498`); runtime explicitly returns `?string` and emits null on success (`apps/api/app/Modules/Fiscal/Presentation/Controllers/FiscalEventIngestionController.php:167-182`). Code is correct; the document is wrong.

The status set is also incomplete. The controller explicitly permits a programming-bug `QueryException` to propagate as 500 (`apps/api/app/Modules/Fiscal/Presentation/Controllers/FiscalEventIngestionController.php:119-124`), while the contract lists 200, 422, 403, and 401 only (`apps/api/openapi/feasibility/tenant-full.json:30464-30550`). Separately, the request's false payload shape is documented under property 4.

## Ranked findings

1. **Blocker — unsanctioned permissive schemas.** Two `additionalProperties: true` request schemas and 23 bare response/member schemas violate the explicit no-permissive-schema gate.
2. **Blocker — undisclosed numeric precision deviations.** At least 49 literal `type: number` precision-like nodes, plus 36 nullable number unions, lack `x-precision-contract-deviation`; representative production validators prove these are precision-governed fields.
3. **Major — admin plan-usage response is materially false.** Counts are documented as strings, nullable dates as non-null, a boolean as string, and the runtime 404 is absent.
4. **Major — fiscal ingestion contract is materially false.** `exception_class` nullability and the possible 500 response are wrong; the authoritative SALE_RECEIPT payload is also misrepresented as `array<string>`, erasing `line_items[].unit_price` and its TTC semantics.

TRUTHFULNESS GATE: FAIL
