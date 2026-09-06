# Codex slice-plan gate r8 — W-CASH-1 rev 8 (gpt-5.6-sol, high, read-only, 2026-09-06)

Input: rev 8 at caf28e6ad. Verbatim.

---
Reviewed current HEAD `caf28e6ad5e03bd01740fcd9bb52ec401a4acd72` read-only. No edits, tests, or Git writes were performed.

Result: **1 BLOCKER, 0 MAJOR, 0 MINOR.**

## BLOCKER

### B1 — The r7 precision correction remains internally contradictory and is not dispatchable

**Plan lines:** [10](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:10), [364](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:364), [366](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:366), [441](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:441), [443](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:443), [448](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:448), [462](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:462), [569](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:569), [581](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:581).

Four defects remain:

1. T3 lines 441/443/448 require `executeTransfer()` to return `DocumentedRepositoryTransferResult` until the Push-5 rename, while the new normative line 462 declares `executeTransfer(...): RepositoryTransferResult`. These cannot both be implemented.

2. `RepositoryTransferPrecisionCeilingException` has no full constructor signature and is said to extend a “Treasury domain exception base” that does not exist. The cited precedent extends PHP `DomainException` directly at [InsufficientRepositoryBalanceException.php:26](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Domain/Exceptions/InsufficientRepositoryBalanceException.php:26).

3. The plan never supplies an executable source for `$countryScale` for both authority branches. The current resolver uses the static ISO map whenever currency is supplied and reaches `countries.currency_decimal_places` only through a bound `CompanyContext`; without context it throws ([CurrencyScaleResolver.php:36](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Shared/Infrastructure/CurrencyScaleResolver.php:36), [CurrencyScaleResolver.php:43](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Shared/Infrastructure/CurrencyScaleResolver.php:43), [CurrencyScaleResolver.php:54](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Shared/Infrastructure/CurrencyScaleResolver.php:54)). T3 simultaneously requires system calls with no `CompanyContext`.

4. The red tests use `expectException()` and then require post-call snapshot assertions. Normal PHPUnit control flow exits the test body when the expected exception is thrown, so those snapshots cannot execute. The renderer contract is also contradictory: line 364 promises `error.details`, while line 366 allows only `current_revision` or `document_id` extras; line 581 contains no precision-specific status/envelope case despite line 364 claiming one was added.

This remains safety-critical because movement storage and casts are scale three ([migration:20](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_07_08_100100_create_repository_movements_table.php:20), [RepositoryMovement.php:53](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Domain/RepositoryMovement.php:53)); writes and replay comparisons occur at [TreasuryMovementService.php:588](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:588), [TreasuryMovementService.php:590](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:590), [TreasuryMovementService.php:682](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:682), and [TreasuryMovementService.php:763](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:763).

**Failure scenario:** an implementer must guess the T3 return type, exception inheritance, company-scale lookup, and HTTP envelope. A system caller can use the wrong scale or fail from missing context; a malformed renderer path can become a 500; and the specified tests can pass after seeing the exception without proving that document, movement, repository, JE, and ordinal snapshots stayed unchanged.

**Minimum correction:**

1. Declare T3 exactly as `executeTransfer(RepositoryTransferIntent $intent): DocumentedRepositoryTransferResult`; reserve `RepositoryTransferResult` for the atomic T5 rename.
2. Add the precision exception to the task-owned exception register with `extends \DomainException` and its complete `__construct(public readonly string $amount, public readonly int $effectiveScale)` contract.
3. Name the exact company-bound scale resolver file, binding, and public signature usable without `CompanyContext`, then show the first-statement policy call using it.
4. Replace `expectException()` with captured-exception assertions so both typed-exception fields and every unchanged snapshot are executed for human and system callers.
5. Make one exact precision response envelope normative, reconcile lines 364/366/581, name backend and frontend translation paths/keys, and assert the complete 422 envelope in both renderer and endpoint tests.

## R7 five-point closure check

| R7 requirement | Result |
|---|---|
| 1. Normative policy path and callable signature | **PARTIAL** — policy exists, but T3 return type and country-scale source conflict |
| 2. Exception, renderer, response, translations, status case | **PARTIAL** — incomplete exception and contradictory/missing envelope case |
| 3. Direct human/system tests plus unchanged snapshots | **PARTIAL** — named, but the prescribed exception flow skips snapshots |
| 4. `min(country preset, OPERATIONAL_SCALE_CEILING)` in T5 | **CLOSED** at line 569 |
| 5. Verbatim widening/removal condition | **CLOSED** at line 462 |

## MAJOR

None.

## MINOR

None. The r7 metadata minor is closed: revision 8 correctly says `awaiting gate r8`.

## Verified coverage

- All 107 unique full `path:line` anchors, plus shorthand continuations, resolve and match current HEAD. Governed source files have no intervening diff from the plan’s `93e9ab1` evidence baseline.
- Convention 10 uses the required eight-column format and covers create, duplicate, edit, cancel/reverse, rerun, second company, second location, permission, and audit.
- Convention 11 reconciles Repository, Cash custody configuration, and Repository transfer document with one table, writer, and existing operator surface.
- Convention 09 coverage is named per task with real registration, second company, second `pos_enabled` location, and explicit rerun outcomes.
- Q11–Q13 at plan lines 120–122 are byte-for-byte identical to owner-ruling lines 152–154 and are correctly marked RULED.
- No drawer-session model, Q12 reason-code enum/mapping, historical alignment, shift-event booking, v2/v3 adapter, W7 work, or variance activation ships.
- The shared-manifest reference, required variables, topology/backup gates, web fingerprint, five pushes, forward-only rollback, and verbatim §4 checklist are present.

## Rejected false positives

- **“The plan must still label Q11–Q13 OPEN because the original prompt said so.”** Rejected. The later owner-ruling record and this gate’s scope supersede that stale instruction.
- **“Existing `MovementReasonCode` means Q12 ships here.”** Rejected. It predates this slice and remains only a projected response field; no new device reason mapping is introduced.
- **“Scale-four document storage violates the temporary ceiling.”** Rejected. Storage capacity and operational ingress precision are separate contracts.
- **“Successful operational fixtures still use `1.0005`.”** Rejected. Operational success uses `1.005`; `1.0005` is limited to raw P0 storage proof and required refusal tests.
- **“P0 must widen repository movements in this slice.”** Rejected. The temporary ceiling plus separately accepted widening condition is the correct scope.
- **“The older evidence SHA invalidates every anchor.”** Rejected. Current HEAD moved, but the governed sources did not.

## Preserve during correction

- P0-a census before P0-b widening, with per-tenant evidence and forward-only rollback.
- Four-column P0 `(15,4)` storage contract and isolated raw `1.0005` proof.
- One document, one transfer group, exactly two cross-linked legs, and zero-or-one posted JE.
- Append-only linked reversals, frozen-transfer evidence, and after-commit alerts.
- Human/system authority union and stored-document-first replay authorization.
- W1 authorization as the hard HTTP-activation prerequisite.
- Company-scoped ownership, immutable schema guards, enums, and typed JSONB evidence.
- Generated DTO migration and the existing Repository operator surface.
- Exact Q11–Q13 wording and every later-slice deferral.
- Shared-manifest topology, backup, fingerprint, five-push, and forward-only rollback requirements.

Owner decisions required: **None.** B1 requires engineering-contract corrections only.

VERDICT: CHANGES-REQUIRED