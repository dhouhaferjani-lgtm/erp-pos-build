# Ticket — `{attachment}` route parameter bound unguarded into a uuid column

**Opened:** 2026-09-09 (gate r3 finding N4 on PR #210 "fix(authz): gate supplier-invoice posting behind a dedicated permission (F-W2-14)")
**Module:** Media (document attachments)
**Severity:** Minor — PostgreSQL 500 on a malformed id, not on the authorization path
**Status:** Open

## Problem

`apps/api/app/Modules/Media/routes.php:34` and `:38` declare the download and
delete attachment routes with an `{attachment}` parameter that carries **no**
`whereUuid` constraint:

```php
Route::get('attachments/{attachment}/download', [DocumentAttachmentController::class, 'download'])
    ->middleware('can:documents.view')
    ->name('documents.attachments.download');
// ...
Route::delete('attachments/{attachment}', [DocumentAttachmentController::class, 'destroy'])
    ->middleware('can:documents.update')
    ->name('documents.attachments.destroy');
```

`DocumentAttachmentController::download()` (`apps/api/app/Modules/Media/Presentation/Controllers/DocumentAttachmentController.php:122`)
and `::destroy()` (`:143`) both pass the raw `$attachment` string straight
into `MediaService`, which queries it directly against `media_attachments.id`:

- `MediaService::download()` — `apps/api/app/Modules/Media/Application/Services/MediaService.php:150` (`->where('id', $attachmentId)`)
- `MediaService::detach()` — `apps/api/app/Modules/Media/Application/Services/MediaService.php:195` (`->where('id', $attachmentId)`)

`media_attachments.id` is a PostgreSQL `uuid` column (`apps/api/database/migrations/tenant/2026_06_12_100003_create_media_attachments_table.php:15`).
A malformed (non-UUID) `{attachment}` value therefore raises `SQLSTATE 22P02`
on PostgreSQL — an uncaught 500 — instead of a clean 404. SQLite is
permissive about the column affinity and never reproduces this, so it passes
silently under the project's default (sqlite) test suite.

This is the **exact same defect class** as gate r2 finding 5 on this same PR,
which fixed the sibling `{document}` route parameter one hop earlier in the
same URL (`resolveDocument()`,
`apps/api/app/Modules/Media/Presentation/Controllers/DocumentAttachmentController.php:190-203`,
guarded with `Str::isUuid($documentId)` before the query). `{attachment}` was
not in scope for that fix and is still unguarded.

## Proposed fix

Either:
1. Add `->whereUuid('attachment')` to both route declarations
   (`apps/api/app/Modules/Media/routes.php:34`, `:38`), matching Laravel's
   built-in route-constraint helper; or
2. Add an `Str::isUuid($attachmentId)` guard in the controller (`download()`
   and `destroy()`) before calling into `MediaService`, mirroring the
   `resolveDocument()` guard added for gate r2 finding 5.

Either fix should return a 404, not a 500, for a malformed attachment id —
consistent with the fixed `{document}` sibling. Add a PostgreSQL-lane test
(the existing `DocumentAttachmentApiContractTest::test_malformed_document_id_is_a_404_not_a_database_error`
runs on sqlite, which cannot reproduce the 500 this ticket describes; a
parallel `test_malformed_attachment_id_is_a_404_not_a_database_error` should
be added and — ideally — run against PostgreSQL to actually exercise the
`SQLSTATE 22P02` path, not just the guard's presence).

## References

- Gate report: `docs/superpowers/reviews/2026-09-09-dhouha-pr-210-gate-r3.md`, finding N4.
- Sibling fix (gate r2 finding 5): same gate report, Part 1.
