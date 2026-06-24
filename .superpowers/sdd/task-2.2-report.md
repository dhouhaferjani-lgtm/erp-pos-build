# Task 2.2 Report: DocumentAttachmentController (contract-preserving)

## Status
COMPLETE — commit `ebc778480`

## TDD Red/Green

**Step 1 — Tests written (red):** `DocumentAttachmentApiContractTest` written with 10 tests before implementation was complete. Initial run showed 2 failures (`test_store_then_index_then_download_then_destroy_pdf`, `test_index_returns_newest_first`) due to UploadedFile stat error from incorrect fake file construction.

**Step 2 — Fix UploadedFile construction:** Replaced manual `new UploadedFile(...)` wrapping with direct `UploadedFile::fake()->create()` (same pattern as `MediaServiceTest`). Rerun: 9/10 pass.

**Step 3 — Failure on description:** `data.description` returned null even though `'Inv desc'` was passed. Root cause: `MediaService::attachUpload()` accepted `$caption` but never persisted it — `MediaAttachmentService::attach()` has no caption parameter, and the `MediaAttachment::create()` in `attach()` doesn't include caption. Fix: added `$attachment->update(['caption' => $caption])` after `attach()` in `MediaService::attachUpload()`.

**Step 4 — All green:**
```
./vendor/bin/phpunit tests/Feature/Modules/Media/DocumentAttachmentApiContractTest.php tests/Feature/Architecture/MediaBoundaryTest.php --no-coverage
..........  10 / 10 (100%)
OK, but there were issues!
Tests: 10, Assertions: 71, PHPUnit Deprecations: 8.
```
(8 deprecations are pre-existing PHPUnit notices in the test suite, not in new code.)

**Step 5 — PHPStan + Pint:**
- `phpstan analyse app/Modules/Media/Presentation/Controllers/DocumentAttachmentController.php app/Modules/Catalog/Application/Services/MediaService.php` → `[OK] No errors`
- `pint` fixed `fully_qualified_strict_types`, `ordered_imports`, `braces_position` on controller and test. Re-ran tests: still 10/10.

## Files Changed

| File | Action |
|---|---|
| `apps/api/app/Modules/Media/Presentation/Controllers/DocumentAttachmentController.php` | Created |
| `apps/api/app/Modules/Media/routes.php` | Modified — controller ref only |
| `apps/api/app/Modules/Catalog/Application/Services/MediaService.php` | Modified — caption fix |
| `apps/api/tests/Feature/Modules/Media/DocumentAttachmentApiContractTest.php` | Created |

## Isolation Cases Folded (R-H2)

All 5 cases from `AttachmentTenantIsolationTest` are now covered in `DocumentAttachmentApiContractTest`:
1. `test_index_rejects_cross_tenant_document` → 404
2. `test_index_rejects_cross_company_same_tenant_document` → 404
3. `test_download_rejects_cross_tenant_attachment` → 404
4. `test_download_rejects_mixed_ids_doc_a_attachment_b` → 404
5. `test_destroy_rejects_cross_tenant_attachment` → 404

The isolation fixture uses the real `MediaServiceInterface` to create attachments (not legacy `DocumentAttachment` rows) so the isolation tests exercise the new stack end-to-end.

## Self-Review

- **Response keys/values byte-match legacy:** `filename` = `originalFilename`, `original_filename` = same, `description` = `caption`, `formatted_file_size` copies the exact `getFormattedFileSizeAttribute()` logic (same byte thresholds and `number_format($x, 2)` precision). `uploaded_by.id`/`name` non-null asserted in happy-path test.
- **Company-scope gate present in every method:** `resolveDocument()` called first in `index`, `store`, `download`, `destroy`. 2 cross-company isolation tests prove it.
- **assetType always `MediaAssetType::Document`:** Verified — `is_pdf`/`is_image` are MIME-derived in `buildView()`, independent of asset type. Status is READY immediately.
- **download headers + bytes asserted:** `Content-Disposition` contains filename, `Content-Type` contains mime type.
- **No Catalog import in controller or Media module files:** `MediaBoundaryTest` passes.
- **Routes names/permissions unchanged:** Only the controller class reference changed in `routes.php`.

## Concerns

1. **Caption was silently dropped before this fix.** `MediaService::attachUpload()` accepted a caption parameter but never persisted it. This affected all callers. The fix (`$attachment->update(['caption' => $caption])`) is minimal and backwards-compatible (null caption = no update = existing behaviour preserved). The `MediaServiceTest` tests didn't assert caption roundtrip so the gap was invisible.

2. **Legacy `AttachmentTenantIsolationTest` remains.** It still passes (legacy controller is removed from routes but the class still exists). Phase 3 will delete the legacy controller; at that point `AttachmentTenantIsolationTest` should also be deleted since its coverage is superseded by this test.

3. **PHPUnit 8 deprecations** are pre-existing (not in new code) — likely `@test` docblock annotations vs `#[Test]` attributes.

---

## Review Minors — commit `e30fd2773`

### Fix 1 — Caption persisted inside the attach transaction
- `MediaAttachmentService::attach()` gains trailing optional param `?string $caption = null`; `'caption' => $caption` added to `MediaAttachment::create([...])` inside the existing `DB::transaction`. All existing callers (product flow) pass no caption → null → backward-compatible.
- `MediaService::attachUpload()` now passes `$caption` as the 7th argument to `attach()` and the post-commit `$attachment->update(['caption' => $caption])` block is removed entirely.

### Fix 2 — Null-user 403 guard in store()
Added `if ($request->user() === null) return response()->json(['error' => 'Unauthorized'], 403);` at the top of `DocumentAttachmentController::store()`, before any document resolution. Unreachable behind `auth:sanctum` but preserves byte-parity with the legacy `AttachmentController`.

### Fix 3 — Cross-company store isolation test
Added `test_store_rejects_cross_company_same_tenant_document` to `DocumentAttachmentApiContractTest`. Uses the existing `seedCrossCompanySameTenantFixture()` helper: userA in companyA POSTs a file to a document owned by companyA2 (same tenant) → expects 404 via the `resolveDocument()` company gate.

### Covering test run (commit `e30fd2773`)
```
./vendor/bin/phpunit \
  tests/Feature/Modules/Media/DocumentAttachmentApiContractTest.php \
  tests/Feature/Modules/Media/MediaServiceTest.php \
  tests/Feature/Modules/Catalog/Media/MediaAttachmentServiceTest.php \
  tests/Feature/Modules/Catalog/Media/ProductImageFacadeTest.php \
  --no-coverage
................................................  48 / 48 (100%)
Tests: 48, Assertions: 163, PHPUnit Deprecations: 9.
```
Product attach path (ProductImageFacadeTest) unaffected by the new optional param.

### PHPStan + Pint
- `phpstan analyse` on all 3 touched service/controller files → `[OK] No errors`
- `pint` on all 4 touched files → `{"result":"pass"}`

---

## Phase 2 Adversarial Review Fixes — commit TBD

### Fix MED-1 — store() ValidationException catch (defense-in-depth)
- `DocumentAttachmentController::store()`: added `catch (ValidationException $e)` BEFORE the existing `catch (\InvalidArgumentException $e)`. Returns `response()->json(['error' => $e->getMessage()], 422)` matching the legacy `{error: ...}` 422 shape.
- `Illuminate\Validation\ValidationException` and `Symfony\Component\HttpKernel\Exception\NotFoundHttpException` imports added.
- Test `test_store_validation_exception_catch_is_defense_in_depth`: documents why the path is defense-in-depth (FormRequest fires first in HTTP flow), verifies the FormRequest layer IS active (rejects disallowed MIME with 422), and confirms valid PDFs return 201. Fabricating an HTTP-reachable path to the service-layer catch would require the FormRequest to allow a MIME the service rejects — this is by design impossible in normal operation (they use the same config key), so the test documents this invariant instead.

### Fix LOW-1 — clean 404s on download
1. `MediaStorageAdapter::download()`: added `if (! Storage::disk($disk)->exists($path)) { throw new \RuntimeException('Attachment file not found on storage.'); }` before `Storage::disk($disk)->download(...)`. Restores legacy parity for orphaned/missing objects.
2. `DocumentAttachmentController::download()`: added `catch (NotFoundHttpException $e) { throw $e; }` BEFORE `catch (\RuntimeException $e)` so a not-found/foreign attachment propagates as a clean Laravel 404 (not swallowed and re-wrapped as `{error: ""}` 404).
- Test `test_download_foreign_attachment_returns_clean_404`: calls `getJson()` (forces `Accept: application/json` so Laravel's exception handler routes to JSON rather than HTML error rendering), asserts status 404, and asserts the body is NOT `{error: ""}`. Note: `$this->get()` (no JSON) triggers the HTML error rendering pipeline in test context — `getJson()` is correct for API contract tests.

### Fix LOW-2 — Eloquent mass forceDelete bypass pattern
- `DocumentHardDeleteBypassTest::BYPASS_PATTERNS`: added `"/\bDocument::[^;]*->forceDelete\(/s"` to catch static Eloquent query-builder chains ending in `forceDelete()` (e.g. `Document::query()->where(...)->forceDelete()`). Does NOT match `$document->forceDelete()` (instance call, not `Document::`) so the observer's own call is not flagged. Does NOT match `TerminalController` or `CoffeeShopSeeder` (they do not call `Document::`).
- Confirmed: zero violations in current codebase (no static Document::...->forceDelete() calls exist).

### Covering test run (phase-2 fixes)
```
./vendor/bin/phpunit \
  tests/Feature/Modules/Media/DocumentAttachmentApiContractTest.php \
  tests/Feature/Architecture/DocumentHardDeleteBypassTest.php \
  tests/Feature/Modules/Media/MediaServiceTest.php \
  tests/Feature/Modules/Document/DocumentMediaCascadeTest.php \
  --no-coverage
.........................  25 / 25 (100%)
Tests: 25, Assertions: 112, PHPUnit Deprecations: 11.
```

### PHPStan + Pint
- `phpstan analyse app/Modules/Media/Presentation/Controllers/DocumentAttachmentController.php app/Modules/Catalog/Infrastructure/Storage/MediaStorageAdapter.php` → `[OK] No errors`
- `pint` on all 4 touched files → `{"result":"pass"}`
