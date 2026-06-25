# Media Subsystem Unification Plan Review

**Verdict:** NEEDS-REVISION

**Finding counts:** BLOCKER 2, HIGH 3, MED 3, LOW 0

## Findings

### BLOCKER: Non-image document uploads never become `READY`, so they disappear from the new document attachment API

**Plan task:** Task 1.2, Task 1.4, Task 2.2

The plan says generic uploads dispatch renditions only for images and document uploads use `MediaAssetType::Document` (`docs/superpowers/plans/2026-06-24-media-subsystem-unification.md:123`, `docs/superpowers/plans/2026-06-24-media-subsystem-unification.md:166`, `docs/superpowers/plans/2026-06-24-media-subsystem-unification.md:336`). Current upload creation sets every uploaded asset to `MediaStatus::Uploaded` (`apps/api/app/Modules/Catalog/Application/Services/MediaUploadService.php:130`, `apps/api/app/Modules/Catalog/Application/Services/MediaUploadService.php:134`), and only `GenerateRenditions` moves uploaded assets to `READY` (`apps/api/app/Modules/Catalog/Application/Jobs/GenerateRenditions.php:78`, `apps/api/app/Modules/Catalog/Application/Jobs/GenerateRenditions.php:82`). The shared read repository filters out anything not `READY` (`apps/api/app/Modules/Catalog/Infrastructure/Persistence/EloquentMediaAttachmentRepository.php:33`, `apps/api/app/Modules/Catalog/Infrastructure/Persistence/EloquentMediaAttachmentRepository.php:36`).

As written, Task 2.2 can store and attach a PDF, then `index` returns nothing. The proposed Task 1.2 test only asserts the job is not pushed (`docs/superpowers/plans/2026-06-24-media-subsystem-unification.md:129`, `docs/superpowers/plans/2026-06-24-media-subsystem-unification.md:143`), so this can pass while the API is broken.

**Concrete fix:** In Task 1.2, make non-image upload assets `READY` in the upload transaction, or otherwise finalize them immediately before returning. Add assertions that `MediaAssetType::Document` uploads have `MediaStatus::Ready`, that `MediaServiceInterface::attachUpload(...Document...)` is visible from `listForOwner`, and that Task 2.2 store followed by index returns the new attachment.

### BLOCKER: Phase 1's Shared seam still exposes Catalog types, so Phase 2 does not remove the Media/Documents-to-Catalog boundary violation

**Plan task:** Task 1.3, Task 1.4, Task 2.2

The plan claims Phase 1 creates a cross-module seam so consumers use `App\Shared\Contracts\MediaServiceInterface` instead of Catalog internals (`docs/superpowers/plans/2026-06-24-media-subsystem-unification.md:31`, `docs/superpowers/plans/2026-06-24-media-subsystem-unification.md:70`). But the proposed Shared contract imports Catalog enums directly (`docs/superpowers/plans/2026-06-24-media-subsystem-unification.md:199`, `docs/superpowers/plans/2026-06-24-media-subsystem-unification.md:212`). Task 2.2 then requires the new Media-module controller to call `attachUpload(... MediaRole::Datasheet ..., MediaAssetType::Document)` (`docs/superpowers/plans/2026-06-24-media-subsystem-unification.md:336`), which means the Media module must import Catalog domain enums before Phase 4.

The real enum classes are currently Catalog-owned (`apps/api/app/Modules/Catalog/Domain/Enums/MediaOwnerType.php:5`, `apps/api/app/Modules/Catalog/Domain/Enums/MediaAssetType.php:5`, `apps/api/app/Modules/Catalog/Domain/Enums/MediaRole.php:5`). Therefore Phase 2 is functionally shippable only by preserving a cross-module dependency the plan says it removes.

**Concrete fix:** Do not put Catalog classes in `App\Shared\Contracts\MediaServiceInterface`. Either move the media enum/value types to the future owner module in Phase 1 before adding the document consumer, or define Shared-owned value types/scalars for owner type, asset type, and role and map them inside the Catalog implementation. Add an architecture grep/test proving `app/Modules/Media` and `app/Modules/Document` have zero `App\Modules\Catalog\Domain\Enums\Media*` imports after Task 2.2.

### HIGH: Task 2.2 does not preserve legacy download behavior unless the storage contract grows a download mode

**Plan task:** Task 2.2, Task 1.4

Legacy document attachment downloads call `Storage::download($path, $original_filename, ['Content-Type' => $mime])` (`apps/api/app/Modules/Media/Application/Services/AttachmentService.php:93`, `apps/api/app/Modules/Media/Application/Services/AttachmentService.php:101`, `apps/api/app/Modules/Media/Application/Services/AttachmentService.php:105`). The planned new service calls `MediaStorageInterface::serve($attachment, null)` (`docs/superpowers/plans/2026-06-24-media-subsystem-unification.md:273`), and the real adapter serves uploads with `Storage::disk($disk)->response($path)` without filename or attachment disposition (`apps/api/app/Modules/Catalog/Infrastructure/Storage/MediaStorageAdapter.php:48`, `apps/api/app/Modules/Catalog/Infrastructure/Storage/MediaStorageAdapter.php:78`).

The React component currently downloads a blob and sets `link.download = attachment.original_filename` (`apps/web/src/features/documents/components/DocumentAttachments.tsx:115`, `apps/web/src/features/documents/components/DocumentAttachments.tsx:126`), so the SPA path may still name the file if `original_filename` is preserved. Direct endpoint behavior still changes, and Task 2.2's sample test only asserts `assertOk()` on download (`docs/superpowers/plans/2026-06-24-media-subsystem-unification.md:329`), which will not catch header regressions.

**Concrete fix:** Add a `download()` method to `MediaStorageInterface`, or extend `serve()` with an explicit disposition/filename option. Task 2.2 tests must assert `Content-Disposition` contains the original filename, `Content-Type` matches the uploaded MIME type, and the response body matches the stored bytes.

### HIGH: Phase 3.1 is not build-green by itself because legacy tests remain after the table drop

**Plan task:** Task 3.1, Task 3.2

Task 3.1 drops `document_attachments` unconditionally (`docs/superpowers/plans/2026-06-24-media-subsystem-unification.md:363`, `docs/superpowers/plans/2026-06-24-media-subsystem-unification.md:371`) but Task 3.2 deletes/replaces the legacy test only later (`docs/superpowers/plans/2026-06-24-media-subsystem-unification.md:377`, `docs/superpowers/plans/2026-06-24-media-subsystem-unification.md:383`). The existing isolation test imports and creates `DocumentAttachment` rows (`apps/api/tests/Feature/Media/AttachmentTenantIsolationTest.php:17`, `apps/api/tests/Feature/Media/AttachmentTenantIsolationTest.php:206`), whose model points at the dropped table (`apps/api/app/Modules/Media/Domain/DocumentAttachment.php:44`).

That means a checkout after Task 3.1 can pass the new migration test while the existing test suite is red. This violates the plan's claim that each phase/task remains shippable and build-green.

**Concrete fix:** Fold all `AttachmentTenantIsolationTest` cases into `DocumentAttachmentApiContractTest` before the drop, then delete the legacy test in the same commit as the drop migration, or combine Tasks 3.1 and 3.2 into one atomic shippable task. Preserve the current coverage for cross-tenant document, cross-company same-tenant document, mixed document/attachment IDs, and destroy (`apps/api/tests/Feature/Media/AttachmentTenantIsolationTest.php:108`, `apps/api/tests/Feature/Media/AttachmentTenantIsolationTest.php:115`, `apps/api/tests/Feature/Media/AttachmentTenantIsolationTest.php:122`, `apps/api/tests/Feature/Media/AttachmentTenantIsolationTest.php:129`, `apps/api/tests/Feature/Media/AttachmentTenantIsolationTest.php:136`).

### HIGH: Phase 4 misses real importers and underspecifies TypeScript contract churn

**Plan task:** Task 4.1 through Task 4.5

Task 4.5 lists product façade controllers, `CatalogMediaQuery`, Catalog DTOs, `ProductImageImportService`, `GenerateProductImageVariants`, and `Product/routes.php` (`docs/superpowers/plans/2026-06-24-media-subsystem-unification.md:415`, `docs/superpowers/plans/2026-06-24-media-subsystem-unification.md:421`). Grep finds additional app/database importers not called out:

- `ProductController` imports `ProductMediaData` from Catalog (`apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:8`) and uses it when mapping products (`apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:109`, `apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:117`).
- `ProductData` imports Catalog `MediaAttachmentData` and `ProductMediaData` (`apps/api/app/Modules/Product/Application/DTOs/ProductData.php:7`, `apps/api/app/Modules/Product/Application/DTOs/ProductData.php:8`) and exposes media in a `#[TypeScript]` DTO (`apps/api/app/Modules/Product/Application/DTOs/ProductData.php:14`, `apps/api/app/Modules/Product/Application/DTOs/ProductData.php:20`, `apps/api/app/Modules/Product/Application/DTOs/ProductData.php:47`).
- `ProductImagePlaceholderSeeder` imports media services, enums, and model classes from Catalog (`apps/api/database/seeders/ProductImagePlaceholderSeeder.php:7`, `apps/api/database/seeders/ProductImagePlaceholderSeeder.php:11`).

Generated TypeScript currently embeds the Catalog DTO namespace inside generated product media types (`packages/shared/types/generated.d.ts:408`, `packages/shared/types/generated.d.ts:410`, `packages/shared/types/generated.d.ts:1465`, `packages/shared/types/generated.d.ts:1490`). Running `php artisan typescript:transform` is necessary, but not enough as a plan: the generated diff needs to be reviewed for namespace/type alias changes and any frontend imports from `packages/shared/types`.

**Concrete fix:** Add `ProductController`, `ProductData`, `ProductImagePlaceholderSeeder`, and all media tests to the Phase 4 move checklist. Add a required final grep for `App\\Modules\\Catalog\\.*Media`, `App\\Modules\\Catalog\\.*Rendition`, and `App.Modules.Catalog.Application.DTOs.Media` across `apps/api`, `packages/shared`, and `apps/web`. Require `pnpm typecheck` after regenerating types.

### MED: Task 2.2 can preserve JSON keys while still changing contract semantics

**Plan task:** Task 1.3, Task 1.4, Task 2.2

The legacy list is newest-first (`apps/api/app/Modules/Media/Application/Services/AttachmentService.php:83`, `apps/api/app/Modules/Media/Application/Services/AttachmentService.php:86`). The proposed `MediaServiceInterface::listForOwner` docblock also says newest-first (`docs/superpowers/plans/2026-06-24-media-subsystem-unification.md:214`), but Task 1.4 implements it via `MediaAttachmentRepositoryInterface::forOwners` (`docs/superpowers/plans/2026-06-24-media-subsystem-unification.md:273`), and the real repository orders by `sort_order` (`apps/api/app/Modules/Catalog/Infrastructure/Persistence/EloquentMediaAttachmentRepository.php:44`).

Also, the frontend type requires `filename`, `original_filename`, and non-null `uploaded_by.id/name` (`apps/web/src/features/documents/hooks/useAttachments.ts:8`, `apps/web/src/features/documents/hooks/useAttachments.ts:22`), and the component displays `original_filename` and `uploaded_by.name` (`apps/web/src/features/documents/components/DocumentAttachments.tsx:227`, `apps/web/src/features/documents/components/DocumentAttachments.tsx:236`). The plan says `filename` can equal `originalFilename` (`docs/superpowers/plans/2026-06-24-media-subsystem-unification.md:309`), which is acceptable for the current frontend, but the test must lock it.

**Concrete fix:** For document attachments, either make `listForOwner` support ordering or add a document-specific list method that orders `created_at desc`. Task 2.2 tests should use exact JSON path/value assertions for `filename`, `original_filename`, `description`, `uploaded_by.id/name`, `is_image`, `is_pdf`, `formatted_file_size`, and ordering after two uploads.

### MED: Cascade observer is correct for Eloquent `forceDelete()`, but the plan does not guard bypass paths

**Plan task:** Task 2.3

`Document` uses `SoftDeletes` (`apps/api/app/Modules/Document/Domain/Document.php:96`, `apps/api/app/Modules/Document/Domain/Document.php:102`), so `isForceDeleting()` is the right parity check for the legacy hard FK behavior (`apps/api/database/migrations/tenant/2025_12_14_000001_create_document_attachments_table.php:16`; plan at `docs/superpowers/plans/2026-06-24-media-subsystem-unification.md:349`). Existing UI delete paths soft-delete documents (`apps/api/app/Modules/Document/Presentation/Controllers/InvoiceController.php:441`, `apps/api/app/Modules/Document/Presentation/Controllers/QuoteController.php:424`, `apps/api/app/Modules/Document/Presentation/Controllers/SalesOrderController.php:424`, `apps/api/app/Modules/Document/Presentation/Controllers/PurchaseOrderController.php:435`), so the observer will intentionally leave media intact there.

The risk is hard-delete bypass: query-builder deletes, mass deletes, `withoutEvents()`, and future purge commands will not trigger the observer. The old FK handled database-level hard deletes; the new polymorphic owner ID cannot.

**Concrete fix:** Add a Task 2.3 grep/architecture test for hard document deletes outside model `forceDelete()`, and document the rule that hard document deletion must go through Eloquent events or an explicit `MediaServiceInterface::purgeOwner()` call. If a purge/deprovisioning path exists later, call `purgeOwner(MediaOwnerType::Document, ...)` before the hard delete.

### MED: The plan references attachment i18n keys that do not exist in backend language files

**Plan task:** Task 2.1, Task 2.2

The plan says to reuse `messages.attachment.uploaded|deleted` and `validation.attachment.*` (`docs/superpowers/plans/2026-06-24-media-subsystem-unification.md:32`, `docs/superpowers/plans/2026-06-24-media-subsystem-unification.md:292`). The legacy code already references those keys (`apps/api/app/Modules/Media/Presentation/Controllers/AttachmentController.php:102`, `apps/api/app/Modules/Media/Presentation/Controllers/AttachmentController.php:129`, `apps/api/app/Modules/Media/Presentation/Requests/UploadAttachmentRequest.php:46`, `apps/api/app/Modules/Media/Presentation/Requests/UploadAttachmentRequest.php:49`), but the real `messages.php` files do not define an `attachment` group (`apps/api/lang/en/messages.php:30`, `apps/api/lang/en/messages.php:39`, `apps/api/lang/fr/messages.php:30`, `apps/api/lang/fr/messages.php:39`), and the validation files shown around their custom sections likewise have no `attachment` group (`apps/api/lang/en/validation.php:176`, `apps/api/lang/fr/validation.php:176`).

**Concrete fix:** Add explicit Task 2.1 work to create the missing keys in `apps/api/lang/en/messages.php`, `apps/api/lang/fr/messages.php`, `apps/api/lang/en/validation.php`, and `apps/api/lang/fr/validation.php`, or change the plan to assert the current literal-key fallback if that is intentionally part of the frozen contract.

### MED: TDD checks are too shallow in places and can pass for wrong reasons

**Plan task:** Task 2.2, Task 3.1, Task 4.5

Task 2.2 says “Assert each endpoint's status + JSON keys exactly,” but the sample uses only `assertJsonStructure` for the payload and `assertOk()` for download (`docs/superpowers/plans/2026-06-24-media-subsystem-unification.md:311`, `docs/superpowers/plans/2026-06-24-media-subsystem-unification.md:321`, `docs/superpowers/plans/2026-06-24-media-subsystem-unification.md:329`). That would miss wrong `filename`, wrong ordering, null uploader names, wrong file bytes, and wrong download disposition. Task 3.1 also acknowledges SQLite cannot validate PG behavior (`docs/superpowers/plans/2026-06-24-media-subsystem-unification.md:372`) but still leaves the PG check as a note rather than a required verification artifact. Task 4.5 runs `typescript:transform` (`docs/superpowers/plans/2026-06-24-media-subsystem-unification.md:420`) but does not require `pnpm typecheck` even though generated types contain Catalog-qualified media DTO references (`packages/shared/types/generated.d.ts:410`, `packages/shared/types/generated.d.ts:1490`).

**Concrete fix:** Strengthen tests to assert values and bytes, not only shapes/status codes. For PG-only migration/index behavior, require a captured `php artisan tenants:migrate` result against a real tenant database before marking Task 3.1 complete. For Phase 4, require `pnpm typecheck` and a generated-type diff review.

## Verified Non-Issues

- The Datasheet role avoids the current partial unique PRIMARY index: the index only applies `WHERE role = 'PRIMARY'` (`apps/api/database/migrations/tenant/2026_06_12_100003_create_media_attachments_table.php:32`, `apps/api/database/migrations/tenant/2026_06_12_100003_create_media_attachments_table.php:35`), and `MediaRole::Datasheet` is a distinct value (`apps/api/app/Modules/Catalog/Domain/Enums/MediaRole.php:9`, `apps/api/app/Modules/Catalog/Domain/Enums/MediaRole.php:11`).
- Existing routes and permissions can be preserved as planned: current document attachment routes use the frozen names and `can:documents.view|update` middleware (`apps/api/app/Modules/Media/routes.php:21`, `apps/api/app/Modules/Media/routes.php:40`), and the permission seeder includes `documents.view` and `documents.update` (`apps/api/database/seeders/RolesAndPermissionsSeeder.php:95`, `apps/api/database/seeders/RolesAndPermissionsSeeder.php:97`).
- The drop migration ordering is basically safe if kept as a tenant migration after the original create migration: the legacy table is created in tenant migrations (`apps/api/database/migrations/tenant/2025_12_14_000001_create_document_attachments_table.php:13`), and the plan places the drop in `database/migrations/tenant/` (`docs/superpowers/plans/2026-06-24-media-subsystem-unification.md:365`, `docs/superpowers/plans/2026-06-24-media-subsystem-unification.md:371`). The build-green issue is task ordering around tests/code, not the timestamp concept.
