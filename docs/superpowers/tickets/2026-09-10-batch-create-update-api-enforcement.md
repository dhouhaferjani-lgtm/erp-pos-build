# A-1b — Align batch create/update with staged action middleware

Status: deferred to W-LOT-A-1b. Owner: BatchExpiry / tenancy lane.
Authority: W-LOT-A-1a plan rev 10 §0 and §14, frontend gate r1 B-7.

The web gates create/edit with `batches.create` / `batches.update`. A-1a deliberately adds no `BatchActionAccess` middleware to create/update/transfer/write-off. Widen the middleware allow-list to create/update and attach those two exact action names behind `LOT_ACTION_PERMISSIONS_ENFORCE` in A-1b. Preserve the activation and promotion sequence.

Code qualification to the review: the API is not wholly unprotected today. `CreateBatchRequest::authorize()` and `UpdateBatchRequest::authorize()` already call the user's `can('batches.create')` / `can('batches.update')`, respectively. See `apps/api/app/Modules/BatchExpiry/Presentation/Requests/CreateBatchRequest.php:23` and `apps/api/app/Modules/BatchExpiry/Presentation/Requests/UpdateBatchRequest.php:11`. The outstanding asymmetry is the new staged middleware boundary; do not remove these existing checks or describe authenticated module access alone as sufficient authorization.

Acceptance: real HTTP denied/allowed cases for both actions, both flag states, company and location isolation, unchanged stock/history/GL on denial, and corresponding direct-route browser tests. A-1a changes no authorization on these routes. Transfer/write-off remain outside this ticket unless separately scoped.
