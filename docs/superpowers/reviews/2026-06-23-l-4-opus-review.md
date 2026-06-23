# L-4 Orphaned DTO/Service Cleanup — Opus Adversarial Review

- Item: L-4 (Orphaned DTO/service cleanup)
- Commit: 4ab069189
- Reviewer: Opus (adversarial cross-model, post-merge)
- Date: 2026-06-23

## Summary

L-4 deletes four genuinely-orphaned PHP definitions (`InvoiceConsolidationService`,
`ExpenseData`, `LoginData`, `PartNeedData`), refreshes `packages/shared/types/generated.d.ts`
to drop the two TypeScript exports that were emitted from `LoginData`/`PartNeedData`, and
updates current docs. It is a deletion-only, behavior-neutral cleanup. I tried to break it on
the money/precision, event-immutability, GL, migration, and cross-app-deprecation lenses and
could not find a defect. Every adversarial check passed. The single substantive criticism is
test quality: the architecture guard only asserts file absence and does not pin "no live
references," so it is a weak characterization test. No fiscal, money, GL, schema, or migration
surface is touched.

## Verification performed in this checkout

- Repo-wide reference scan (`apps/api`, `apps/web`, `apps/pos`, `packages`, all extensions,
  excluding vendor/node_modules): the only remaining occurrences of the four names are inside
  the new `OrphanedTypesCleanupTest.php` data provider and historical docs. Zero runtime
  consumers.
- `ExpenseData`: Expense module uses `ExpenseService` + array payloads + `ExpenseRequest`
  FormRequest; no DTO consumer. Confirmed (`apps/api/app/Modules/Expense/...`).
- `LoginData`: `AuthController::login()` consumes
  `App\Modules\Identity\Presentation\Requests\LoginRequest`
  (`AuthController.php:183`), not the DTO. Confirmed orphan.
- `PartNeedData` vs event immutability: the live WorkOrder events
  `WorkOrderPartsNeeded` / `WorkOrderWaitingParts` carry the **value object**
  `App\Modules\Workshop\WorkOrder\Domain\ValueObjects\PartNeed`
  (`WorkOrderPartsNeeded.php`), NOT the deleted `PartNeedData` DTO. The DTO was a never-wired
  "wire DTO." Deleting it does not touch any event class, payload shape, or hash chain.
- `InvoiceConsolidationService`: no ServiceProvider binding; the related `ConsolidationFrequency`
  enum and `partner.invoice_consolidation` / `consolidation_frequency` columns remain in use
  for persistence/validation (`CreatePartnerRequest`, `UpdatePartnerRequest`, `PartnerData`,
  `Partner`), so no dangling wiring was introduced by the deletion.
- Ran `php artisan typescript:transform` fresh: 349 types, and `git diff --stat
  packages/shared/types/generated.d.ts` is **empty** — committed generated types are exactly in
  sync; nothing else was accidentally dropped.
- Ran the test: `OrphanedTypesCleanupTest` — 4 passed, 4 assertions.
- Ran `phpstan analyse tests/Architecture/OrphanedTypesCleanupTest.php --level=8` — OK.

## BLOCKER

None.

## HIGH

None. (No money/precision, sign-convention, GL, event-immutability, or migration surface is
touched. There are no migrations, no SQL, no `(float)` casts, no `number_format`, no balance
math in this commit.)

## MEDIUM

- M1 — Weak architecture guard. `tests/Architecture/OrphanedTypesCleanupTest.php` only asserts
  `assertFileDoesNotExist(...)` for the four paths. It pins the deletion but does NOT assert the
  stronger property the task actually relied on ("no live references exist"). A future engineer
  could re-introduce any of these files *with a real consumer* and the test would still pass (it
  fails only on file presence, which is exactly what re-adding would do — so it would catch a
  re-add, but it cannot distinguish an orphan re-add from a wired re-add). The genuinely valuable
  guard would be a grep/architecture assertion that the class names have no references outside
  the test. Low practical risk for a LOW-severity cleanup, but the test gives less confidence
  than the verification log implies.

## LOW

- L1 — Doc fudge. `docs/conventions/04-FRONTEND-TYPES.md` changed a precise count
  ("69 of 73 existing tagged DTOs") to the vague "Most existing tagged DTOs." Acceptable given
  `LoginData` was removed (so the old count is stale), but the new phrasing loses an auditable
  number. Cosmetic.
- L2 — The two "review" artifacts committed alongside (Codex review + "opus-fallback" review)
  both self-report `opus-review: PENDING` and find nothing; they are not an independent Opus
  pass. This review supplies the missing cross-model pass. No action on the code.

## Verdict

APPROVE. The claim holds under refutation: all four definitions are genuinely orphaned across
every app and package, the event-sourcing path uses the live `PartNeed` VO (not the deleted
DTO), generated types are exactly in sync after a clean transform, and there is zero
money/GL/migration surface. The only real critique (M1, weak test) does not affect correctness
of the change itself. Recommend, as a non-blocking follow-up, strengthening the guard to assert
"no live references" rather than mere file absence.
