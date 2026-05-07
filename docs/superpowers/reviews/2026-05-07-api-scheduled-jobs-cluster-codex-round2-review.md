# Codex round-2 adversarial review — api.scheduled-jobs cluster (Section 14)

Review date: 2026-05-07
Branch tip reviewed: d658a956
Reviewer: codex (round-2 cross-agent review of Claude's round-1 follow-up)

Verdict: APPROVE
Commit reviewed: b059bb2c
Round-1 review: docs/superpowers/reviews/2026-05-07-api-scheduled-jobs-cluster-codex-review.md
Round-1 commit reviewed: b059bb2c
Round-2 follow-up commit (round-1 BLOCKER + NICE-TO-HAVE remediation): d658a956

Per the multi-batch fix-commit convention from the kickoff: the
`Commit reviewed:` line above pins the most-common fix_commit across
the cluster's callsites (api.scheduled-jobs.001 and .002 both carry
fix_commit=b059bb2c from initial submission, recorded in inventory at
docs/superpowers/plans/tenant-isolation-sweep-inventory.yml:35179-35205
and docs/superpowers/plans/tenant-isolation-sweep-inventory.yml:35273-35299).
The round-2 follow-up commit d658a956 corrects the round-1 BLOCKER
(ReconcileListingsJob annotation accuracy) and the round-1
NICE-TO-HAVE (recursive trait-graph walker in QueueJobTenantContextTest)
without changing the fix_commit-bearing cat-(a) job source files:
`git show --stat --oneline --no-renames d658a956` reported changes
only to ReconcileListingsJob.php, QueueJobTenantContextTest.php, and
the prior round-1 review file. Both commits combined constitute the
approved fix surface.

## Verdict

APPROVE. The round-1 BLOCKER is closed because ReconcileListingsJob's class-level annotation now states that Product reads are directly scoped by `seller->company_id`, while MarketplaceListing reads/updates are scoped by `seller_id` and depend on the seller_id -> MarketplaceSeller.company_id FK chain, with the no-`company_id` evidence cited in the annotation itself at apps/api/app/Modules/Marketplace/Infrastructure/Jobs/ReconcileListingsJob.php:18-20 and apps/api/app/Modules/Marketplace/Domain/Models/MarketplaceListing.php:52-70. The round-1 NICE-TO-HAVE is closed because QueueJobTenantContextTest now flattens the trait graph recursively with a visited-set at apps/api/tests/Architecture/QueueJobTenantContextTest.php:205-248. The requested phpunit, inventory, phpstan, pint, and POS diff gates all passed or produced the expected empty output, as listed below.

## Round-1 finding closure

1. BLOCKER closed: ReconcileListingsJob's annotation no longer says MarketplaceListing filters by `seller.company_id`; it says Product reads filter by `seller->company_id` and MarketplaceListing reads/updates filter by `seller_id` only, anchored transitively through MarketplaceSeller, at apps/api/app/Modules/Marketplace/Infrastructure/Jobs/ReconcileListingsJob.php:18-20. The code matches that split: Product queries use `where('company_id', $seller->company_id)` at apps/api/app/Modules/Marketplace/Infrastructure/Jobs/ReconcileListingsJob.php:39-52, and the listing update uses `MarketplaceListing::where('seller_id', $seller->id)` at apps/api/app/Modules/Marketplace/Infrastructure/Jobs/ReconcileListingsJob.php:54-58. The annotation cites MarketplaceListing's fillable list at apps/api/app/Modules/Marketplace/Domain/Models/MarketplaceListing.php:53-70, which contains `seller_id` and no `company_id`; the docblock still includes "queue job" at apps/api/app/Modules/Marketplace/Infrastructure/Jobs/ReconcileListingsJob.php:19, so the architecture failure message remains context-correct.

2. NICE-TO-HAVE closed: `classUsesTrait()` walks the class and parent classes at apps/api/tests/Architecture/QueueJobTenantContextTest.php:214-224, then delegates each directly used trait to `collectTraitGraph()` at apps/api/tests/Architecture/QueueJobTenantContextTest.php:220-222. `collectTraitGraph()` is unbounded recursion because it calls itself for every nested trait without a depth cap at apps/api/tests/Architecture/QueueJobTenantContextTest.php:237-247, and the visited-set returns early for already-seen traits at apps/api/tests/Architecture/QueueJobTenantContextTest.php:239-242. Current cat-(a) behavior is unchanged because ProcessImportJob and ProcessProductImageImport still directly use BindsTenantContext at apps/api/app/Modules/Import/Application/Jobs/ProcessImportJob.php:35-38 and apps/api/app/Modules/Import/Application/Jobs/ProcessProductImageImport.php:32-35.

Adversarial trait probe: with hypothetical `TraitA uses TraitB`, `TraitB uses TraitC`, and `TraitC` = BindsTenantContext, a class directly using `TraitA` would call `collectTraitGraph(TraitA)`, add TraitA, recurse to TraitB, add TraitB, recurse to TraitC, and add TraitC; `classUsesTrait()` then returns true from `isset($traits[$traitClass])` at apps/api/tests/Architecture/QueueJobTenantContextTest.php:220-226 and apps/api/tests/Architecture/QueueJobTenantContextTest.php:237-247. If a cycle is introduced in that hypothetical graph, the already-seen guard at apps/api/tests/Architecture/QueueJobTenantContextTest.php:239-242 stops infinite recursion.

## Re-audit at d658a956

| Surface | Round-2 result |
| --- | --- |
| api.scheduled-jobs.001 ProcessImportJob | Still cat-(a) closed: constructor carries `tenantId` at apps/api/app/Modules/Import/Application/Jobs/ProcessImportJob.php:61-65, `handle()` enters `withTenantContext()` before DB access at apps/api/app/Modules/Import/Application/Jobs/ProcessImportJob.php:72-78, ImportJob and Company lookups assert `tenant_id` at apps/api/app/Modules/Import/Application/Jobs/ProcessImportJob.php:75-99, failure paths re-scope lookups at apps/api/app/Modules/Import/Application/Jobs/ProcessImportJob.php:249-307, and the production dispatcher passes tenantId at apps/api/app/Modules/Import/Presentation/Controllers/ImportController.php:434-438. |
| api.scheduled-jobs.002 ProcessProductImageImport | Still cat-(a) closed: constructor carries `tenantId` at apps/api/app/Modules/Import/Application/Jobs/ProcessProductImageImport.php:53-57, `handle()` enters `withTenantContext()` before the ImportJob lookup at apps/api/app/Modules/Import/Application/Jobs/ProcessProductImageImport.php:64-72, and the production dispatcher passes tenantId at apps/api/app/Modules/Import/Presentation/Controllers/ImportController.php:557-561. |
| Cat-(b) annotations | Still accurate for DailyExpiryCheck at apps/api/app/Modules/BatchExpiry/Jobs/DailyExpiryCheck.php:32 with scheduler evidence at apps/api/routes/console.php:32-35 and body evidence at apps/api/app/Modules/BatchExpiry/Jobs/DailyExpiryCheck.php:81-156; ProcessEnrichmentWebhookJob at apps/api/app/Modules/PlatformIntegration/Application/Jobs/ProcessEnrichmentWebhookJob.php:15-38 with downstream listener evidence at apps/api/app/Modules/Product/Application/Listeners/ProcessEnrichmentEventListener.php:20-23; ExpireReservationsJob at apps/api/app/Modules/Inventory/Application/Jobs/ExpireReservationsJob.php:32-68 with service evidence at apps/api/app/Modules/Inventory/Application/Services/StockReservationService.php:267-312; GenerateImageVariants at apps/api/app/Modules/Product/Application/Jobs/GenerateImageVariants.php:15-77 with storage-path dispatcher evidence at apps/api/app/Modules/Product/Application/Services/ProductImageService.php:41-86; ReconcileListingsJob at apps/api/app/Modules/Marketplace/Infrastructure/Jobs/ReconcileListingsJob.php:18-60; and SyncSellerListingsJob at apps/api/app/Modules/Marketplace/Infrastructure/Jobs/SyncSellerListingsJob.php:16-52. |
| BindsTenantContext trait shape | Unchanged for the reviewed invariant: the trait resolves `Tenant::find($this->tenantId)`, throws when missing, and runs the closure through `$tenant->run($fn)` at apps/api/app/Jobs/Concerns/BindsTenantContext.php:37-75. |
| Architecture discovery | Same 9 in-scope job classes: `rg -n "implements ShouldQueue" apps/api/app/Modules -g '*.php' -S \| rg "/(Jobs\|Application/Jobs\|Infrastructure/Jobs)/"` output listed DailyExpiryCheck, SyncSellerListingsJob, ProcessEnrichmentWebhookJob, ReconcileListingsJob, DispatchAppointmentReminder, GenerateImageVariants, ProcessProductImageImport, ProcessImportJob, and ExpireReservationsJob. DispatchAppointmentReminder remains deferred by fixture at apps/api/tests/Architecture/fixtures/queue-job-deferrals.json:1-6. |

## Verification gates

- `./vendor/bin/phpunit tests/Feature/Jobs/ScheduledJobTenantIsolationTest.php` from apps/api — PASS: command output ended with `OK (4 tests, 12 assertions)`.
- `./vendor/bin/phpunit tests/Architecture/QueueJobTenantContextTest.php` from apps/api — PASS: command output ended with `OK (1 test, 2 assertions)`.
- `./vendor/bin/phpunit tests/Architecture/ConsoleCommandTenantContextTest.php` from apps/api — PASS: command output ended with `OK (1 test, 2 assertions)`.
- `php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` from apps/api — PASS: command output was `verified 1538 event(s) across 323 callsite(s); 0 problem(s).`
- `./vendor/bin/phpstan analyse app/Modules/Marketplace/Infrastructure/Jobs/ReconcileListingsJob.php tests/Architecture/QueueJobTenantContextTest.php` from apps/api — PASS: command output ended with `[OK] No errors`.
- `./vendor/bin/pint --test app/Modules/Marketplace/Infrastructure/Jobs/ReconcileListingsJob.php tests/Architecture/QueueJobTenantContextTest.php` from apps/api — PASS: command output was `{"result":"pass"}`.

## POS surface diff

`git diff --stat 9b7a70b8..d658a956 -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher` produced no output, so the POS/Voucher surface diff is empty for this branch range.

## Adversarial round-2 findings

No new blocking findings.

The transitive seller_id scoping claim is accurate for the current scheduled-job contract, not a substitute for future marketplace hardening: MarketplaceServiceProvider dispatches both marketplace jobs only from server-side scheduler closures over `MarketplaceSeller::active()` at apps/api/app/Modules/Marketplace/Providers/MarketplaceServiceProvider.php:32-42, both jobs resolve the seller by unscoped UUID at apps/api/app/Modules/Marketplace/Infrastructure/Jobs/ReconcileListingsJob.php:34 and apps/api/app/Modules/Marketplace/Infrastructure/Jobs/SyncSellerListingsJob.php:32, MarketplaceSeller carries `tenant_id` and `company_id` at apps/api/app/Modules/Marketplace/Domain/Models/MarketplaceSeller.php:21-77, and the deferred cross-cluster audit already records this as LOW defense-in-depth hardening at docs/superpowers/audits/2026-05-07-scheduled-jobs-cross-cluster-observations.md:87-150. I found no user-controlled dispatcher that would let a tenant submit a foreign sellerId into these scheduled jobs: `rg -n "(SyncSellerListingsJob|ReconcileListingsJob)::dispatch|new (SyncSellerListingsJob|ReconcileListingsJob)" apps/api/app apps/api/tests -S` returned only MarketplaceServiceProvider.php:34 and MarketplaceServiceProvider.php:40. A malicious internal dispatch would process whichever seller UUID it names, but that is the already-documented marketplace follow-up rather than a reopened api.scheduled-jobs BLOCKER.
