# H-7.4 Batch Write-Off Posting — Codex Adversarial Review

## Scope

Reviewed the H-7.4 diff for batch write-off GL lifecycle hardening:

- `BatchWriteOffService::writeOff()`
- `GeneralLedgerService::createInventoryWriteOffEntry()`
- `BatchWriteOffScalingTest`
- H-7 progress notes in the work-list and coordination file

Review lens: actor attribution, transaction timing, precision preservation, and regression risk for tenant-scoped product lookup.

## Findings

No BLOCKER/HIGH/MEDIUM findings.

## Checks

- The red test exercises the production `BatchWriteOffService::writeOff()` path with a real user id.
- The GL helper validates `postedByUserId` before journal creation, so an invalid actor does not create an orphaned draft write-off entry.
- Posting uses the canonical after-commit helper, preserving balanced-entry validation, fiscal hash stamping, `posted_at`, `posted_by`, and `JournalEntryPosted` dispatch.
- The company currency is passed explicitly from `BatchWriteOffService`, so posting does not depend on ambient context for scale.
- The existing TND precision assertion remains load-bearing and still verifies `124.017` for `100.5000 x 1.234`.

## Residual Risk

Direct legacy callers that omit `postedByUserId` still receive draft write-off entries. The confirmed production service path now supplies the actor.
