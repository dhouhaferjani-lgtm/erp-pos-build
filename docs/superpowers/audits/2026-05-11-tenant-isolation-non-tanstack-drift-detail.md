# Tenant Isolation Non-TanStack Drift Detail

Audit date: 2026-05-11
Branch: feat/tenant-isolation-sweep-execution
Scope: non-TanStack tenant-isolation sweep drift and open callsites

## Commands run

```bash
cd apps/api
php artisan sweep:inventory:verify-history
php artisan sweep:inventory:status --drift
```

`verify-history` is clean: `6061 event(s)` across `1205 callsite(s)`, `0 problem(s)`.

Current drift gate is not clean:

```text
yaml_says_fixed_code_unsafe: 65
code_safe_yaml_pending:      43
unmapped_in_scanner_output:  0
```

The `code_safe_yaml_pending` count is changing while TanStack review rows are being locked by the other session. It is not treated as part of this non-TanStack implementation scope. The stable blocker is the 65 `yaml_says_fixed_code_unsafe` rows.

## Triage summary

The 65 unsafe-fixed drift rows are not 65 independent fresh code defects.

| Cause | Count | Triage |
|---|---:|---|
| Fixed manual rows still emitted by `ManualScanner` | 62 | Manual rows are intentionally persistent. Once a manual finding is fixed and reviewed, the row must be pruned from `tenant-isolation-sweep-manual-callsites.yml` or the drift gate will continue to report it as unsafe forever. |
| `tax_configurations` scanner false positives | 2 | `tax_configurations` is a country-scoped global reference table, already documented in `2026-05-04-scanner-tax-configurations-false-positive.md`. The scanner still treats it as tenant-scoped. |
| Parent-scoped `modifiers` validator false positive | 1 | `StoreReceiptRequest` scopes `modifiers.id` through `modifier_groups` with tenant/company predicates, but `ExistsRuleVisitor` only recognizes direct `where('tenant_id')` or `where('company_id')` chains. |

## Unsafe-fixed rows by cluster

| Cluster | Count |
|---|---:|
| api.auth-permissions | 5 |
| api.broadcast-channels | 13 |
| api.catalog | 18 |
| api.compliance | 6 |
| api.inventory | 5 |
| api.module-gating | 4 |
| api.platform-integration | 6 |
| api.pos-stabilization | 1 |
| api.scheduled-jobs | 2 |
| api.super-admin-context | 1 |
| api.webhooks-incoming | 3 |
| web.super-admin-frontend | 1 |

## Concrete unsafe-fixed row list

| ID | Cluster | Scanner | File | Symbol / pattern |
|---|---|---|---|---|
| api.auth-permissions.001 | api.auth-permissions | manual | apps/api/app/Observers/TenantObserver.php | Tenant suspend token revocation |
| api.auth-permissions.002 | api.auth-permissions | manual | apps/api/app/Observers/TenantObserver.php | Tenant delete token revocation |
| api.auth-permissions.003 | api.auth-permissions | manual | apps/api/app/Observers/UserObserver.php | User tenant change token revocation |
| api.auth-permissions.004 | api.auth-permissions | manual | apps/api/tests/Architecture/AuthLifecycleTest.php | Sanctum route SetPermissionsTeam contract |
| api.auth-permissions.005 | api.auth-permissions | manual | apps/api/app/Modules/Identity/Presentation/Middleware/EnforceTokenTenantClaim.php | Token tenant claim enforcement |
| api.broadcast-channels.001 | api.broadcast-channels | manual | apps/api/routes/channels.php | Product broadcast channel annotation |
| api.broadcast-channels.002 | api.broadcast-channels | manual | apps/api/routes/channels.php | Imports broadcast channel annotation |
| api.broadcast-channels.003 | api.broadcast-channels | manual | apps/api/routes/channels.php | Partners broadcast channel annotation |
| api.broadcast-channels.004 | api.broadcast-channels | manual | apps/api/routes/channels.php | POS terminal channel annotation |
| api.broadcast-channels.005 | api.broadcast-channels | manual | apps/api/routes/channels.php | POS kitchen channel annotation |
| api.broadcast-channels.006 | api.broadcast-channels | manual | apps/api/app/Modules/Product/Infrastructure/Broadcasting/ProductCostPriceUpdatedBroadcast.php | Broadcast event annotation |
| api.broadcast-channels.007 | api.broadcast-channels | manual | apps/api/app/Modules/Partner/Infrastructure/Broadcasting/PartnerBalanceUpdatedBroadcast.php | Broadcast event annotation |
| api.broadcast-channels.008 | api.broadcast-channels | manual | apps/api/app/Modules/POS/Infrastructure/Broadcasting/OrderSentToKitchenBroadcast.php | Broadcast event annotation |
| api.broadcast-channels.009 | api.broadcast-channels | manual | apps/api/app/Modules/POS/Infrastructure/Broadcasting/OrderReadyBroadcast.php | Broadcast event annotation |
| api.broadcast-channels.010 | api.broadcast-channels | manual | apps/api/app/Modules/POS/Infrastructure/Broadcasting/OrderLineStatusChangedBroadcast.php | Broadcast event annotation |
| api.broadcast-channels.011 | api.broadcast-channels | manual | apps/api/app/Modules/POS/Infrastructure/Broadcasting/TerminalActivatedBroadcast.php | Broadcast event annotation |
| api.broadcast-channels.012 | api.broadcast-channels | manual | apps/api/app/Modules/Import/Infrastructure/Broadcasting/ImportCompletedBroadcast.php | Broadcast event annotation |
| api.broadcast-channels.013 | api.broadcast-channels | manual | apps/api/app/Modules/Import/Infrastructure/Broadcasting/ImportProgressBroadcast.php | Broadcast event annotation |
| api.catalog.018 | api.catalog | manual | apps/api/app/Modules/Catalog/Presentation/Controllers/CompositeItemController.php | company-only CompositeItem chains |
| api.catalog.019 | api.catalog | manual | apps/api/app/Modules/Catalog/Presentation/Controllers/RecipeController.php | company-only Recipe/CompositeItem chains |
| api.catalog.020 | api.catalog | manual | apps/api/app/Modules/Catalog/Presentation/Controllers/RecipeLineController.php | unscoped Recipe lookup |
| api.catalog.021 | api.catalog | manual | apps/api/app/Modules/Catalog/Presentation/Controllers/CompositeItemVariantController.php | company-only CompositeItem chain |
| api.catalog.022 | api.catalog | manual | apps/api/app/Modules/Catalog/Presentation | units tenant-or-system validators |
| api.catalog.023 | api.catalog | manual | apps/api/app/Modules/Catalog/Presentation/Requests/StoreCompositeItemRequest.php | tax configuration country coherence |
| api.catalog.024 | api.catalog | manual | apps/api/app/Modules/Catalog/Presentation/Controllers/ModifierController.php | component_type enum asymmetry |
| api.catalog.025 | api.catalog | manual | apps/api/app/Modules/Catalog/Presentation/Controllers/CompositeItemController.php | location_id validation |
| api.catalog.026 | api.catalog | manual | apps/api/app/Modules/Catalog/Presentation/Rules/NoCircularCompositeItemReference.php | unscoped CompositeItem find |
| api.catalog.027 | api.catalog | manual | apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php | product show company-only static call |
| api.catalog.028 | api.catalog | manual | apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php | product update company-only static call |
| api.catalog.029 | api.catalog | manual | apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php | product destroy company-only static call |
| api.catalog.030 | api.catalog | manual | apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php | product stockLevels company-only static call |
| api.catalog.031 | api.catalog | manual | apps/api/app/Modules/Product/Presentation/Controllers/EnrichmentReviewController.php | enrichment result show company-only static call |
| api.catalog.032 | api.catalog | manual | apps/api/app/Modules/Product/Presentation/Controllers/EnrichmentReviewController.php | enrichment result accept company-only static call |
| api.catalog.033 | api.catalog | manual | apps/api/app/Modules/Product/Presentation/Controllers/EnrichmentReviewController.php | enrichment result reject company-only static call |
| api.unmapped.011 | api.catalog | php_presentation_exists | apps/api/app/Modules/Product/Presentation/Requests/UpdateProductRequest.php | `tax_configurations` false positive |
| api.unmapped.012 | api.catalog | php_presentation_exists | apps/api/app/Modules/Product/Presentation/Requests/CreateProductRequest.php | `tax_configurations` false positive |
| api.compliance.006 | api.compliance | manual | apps/api/app/Modules/Compliance/Presentation/Controllers/Nf525ExportController.php | exportJet body company_id |
| api.compliance.007 | api.compliance | manual | apps/api/app/Modules/Compliance/Presentation/Controllers/Nf525ExportController.php | verifyChains body company_id |
| api.compliance.008 | api.compliance | manual | apps/api/app/Modules/Compliance/Presentation/Controllers/Nf525ExportController.php | reprintLog query company_id |
| api.compliance.009 | api.compliance | manual | apps/api/app/Modules/Compliance/Presentation/Controllers/AuditController.php | AuditController index header company_id |
| api.compliance.010 | api.compliance | manual | apps/api/app/Modules/Compliance/Presentation/Controllers/AuditController.php | AuditController anomalies header company_id |
| api.compliance.011 | api.compliance | manual | apps/api/app/Modules/Compliance/Services/AuditService.php | getEventsForAggregate scope |
| api.inventory.029 | api.inventory | manual | apps/api/app/Modules/Inventory/Presentation/Controllers/CountingItemController.php | inventory_counting_items validator |
| api.inventory.030 | api.inventory | manual | apps/api/app/Modules/Inventory/Application/Services/FraudTriggeredCountingService.php | user fallback |
| api.inventory.031 | api.inventory | manual | apps/api/app/Modules/Inventory/Presentation/Controllers/InventoryCountingController.php | tenant_id create hygiene |
| api.inventory.032 | api.inventory | manual | apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php | StockLevel lock defense-in-depth |
| api.inventory.033 | api.inventory | manual | apps/api/app/Modules/Inventory/Application/Services/StockReservationService.php | releaseBySource caller scope |
| api.module-gating.001 | api.module-gating | manual | apps/api/app/Modules/Progression/Presentation/Controllers | header company_id in progression controllers |
| api.module-gating.002 | api.module-gating | manual | apps/api/app/Modules/Tenant/Presentation/Controllers | header company_id in tenant controllers |
| api.module-gating.003 | api.module-gating | manual | apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php | user audit attribution |
| api.module-gating.004 | api.module-gating | manual | apps/api/app/Modules/Progression/Application/Services/ProgressionService.php | outbound id-match check |
| api.platform-integration.001 | api.platform-integration | manual | apps/api/app/Modules/PlatformIntegration/Infrastructure/Http/PlatformHttpClient.php | outbound tenant headers |
| api.platform-integration.002 | api.platform-integration | manual | apps/api/app/Modules/PlatformIntegration/Application/Commands/CheckPendingEnrichmentsCommand.php | per-iteration tenant rebind |
| api.platform-integration.003 | api.platform-integration | manual | apps/api/database/migrations/2026_05_08_000001_add_unique_to_products_platform_submission_id.php | product external id unique |
| api.platform-integration.004 | api.platform-integration | manual | apps/api/database/migrations/2026_05_08_000002_add_unique_to_billing_payments_provider_payment_id.php | billing payment external id unique |
| api.platform-integration.005 | api.platform-integration | manual | apps/api/app/Modules/Product/Application/Listeners/ProcessEnrichmentEventListener.php | platform_submission_id collision guard |
| api.platform-integration.006 | api.platform-integration | manual | apps/api/app/Modules/Billing/Presentation/Controllers/StripeWebhookController.php | billing payment provider filter/collision guard |
| api.pos-stabilization.012 | api.pos-stabilization | php_presentation_exists | apps/api/app/Modules/POS/Presentation/Requests/StoreReceiptRequest.php | `modifiers` parent-scoped false positive |
| api.scheduled-jobs.001 | api.scheduled-jobs | manual | apps/api/app/Modules/Import/Application/Jobs/ProcessImportJob.php | queue job tenant anchor |
| api.scheduled-jobs.002 | api.scheduled-jobs | manual | apps/api/app/Modules/Import/Application/Jobs/ProcessProductImageImport.php | queue job tenant anchor |
| api.super-admin-context.001 | api.super-admin-context | manual | apps/api/tests/Architecture/ControllerTenantContextTest.php | controller context classification |
| api.webhooks-incoming.001 | api.webhooks-incoming | manual | apps/api/app/Modules/Billing/Presentation/Controllers/StripeWebhookController.php | webhook annotation |
| api.webhooks-incoming.002 | api.webhooks-incoming | manual | apps/api/app/Modules/PlatformIntegration/Presentation/Controllers/EnrichmentWebhookController.php | webhook annotation |
| api.webhooks-incoming.003 | api.webhooks-incoming | manual | apps/api/app/Modules/PurchaseHub/Presentation/Controllers/PurchaseHubWebhookController.php | webhook stub guard |
| web.super-admin-frontend.001 | web.super-admin-frontend | manual | apps/web/src/__tests__/architecture/queryKeyNamespace.test.ts | query key namespace invariant |

## Explicit non-TanStack open callsites

There are 21 non-TanStack rows still not fixed in the YAML. Current source inspection shows these split into three types.

| Cluster | IDs | Current triage |
|---|---|---|
| api.document | 043, 044, 045 | Code appears already fixed in `DraftPersistenceService`: batch product/service queries are tenant+company scoped and persisted FK values use `$product?->id` / `$service?->id`. Inventory state is behind code/review. |
| api.loyalty | 001, 003, 006-013 | Code appears already fixed: stamp-card reward validators are scoped by program/current tenant; member route lookups are tenant-scoped; enroll/optOut/reactivate blind spots were applied. Inventory state is behind code/review. |
| api.taxation | 007-010 | Code is country-scoped, not tenant-scoped. These remain scanner false positives until `tax_configurations` is removed from guarded tenant tables or the scanner learns country-scoped references. |
| api.identity-company | 001 | Deferred known `tax_configurations` false positive. Optional product hardening is to country-scope the selected default tax config by current company country. |
| api.workshop | 005-007 | Real pending code work remains. Repository `findById`/`findForUpdate`, bundle adapter lookups, and quote-source document lookup still use unscoped primary-key reads. |

## Recommended disposition

1. Do not treat all 65 drift rows as production regressions. First close the scanner/source-of-truth drift:
   - prune fixed manual rows from `tenant-isolation-sweep-manual-callsites.yml` after verifying their review docs;
   - remove `tax_configurations` from tenant-guarded scanner tables or create an explicit country-scoped category;
   - teach `ExistsRuleVisitor` to recognize parent-scoped closure/subquery validators, then re-run drift.
2. Fix the real `api.workshop` pending rows.
3. For document/loyalty/taxation, run targeted verification and then close inventory state through the normal review/lock path.
4. Re-run:

```bash
cd apps/api
php artisan sweep:inventory:verify-history
php artisan sweep:inventory:status --drift
```

Exit criterion: `yaml_says_fixed_code_unsafe: 0` and no non-TanStack `pending`, `needs_recheck`, `deferred`, or `blocked` rows unless explicitly accepted in the final PR notes.
