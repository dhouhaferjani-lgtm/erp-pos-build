# Codex slice-plan gate r6 — W-CASH-1 rev 6 (gpt-5.6-sol, high, read-only, 2026-09-06)

Input: rev 6 at d59626407. Verbatim.

---
Reviewed current HEAD `c91cbd151afd3784c62a44876db30891aa91ca79` read-only. No edits, tests, or Git writes were performed.

Result: **1 BLOCKER, 0 MAJOR, 1 MINOR.**

## BLOCKER

### B1 — Four-decimal operational transfers remain enabled despite the stated deferral

**Plan lines:** [10](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:10>), [123](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:123>), [144](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:144>), [553](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:553>).

Revision 6 correctly changes the three named operational fixtures to `1.005`, but it does not actually defer four-decimal operational transfers:

- The HTTP contract accepts four fractional digits.
- The service contract rejects only precision exceeding the company’s country preset.
- `CurrencyScaleResolver` returns that persisted preset directly at [CurrencyScaleResolver.php:54](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Shared/Infrastructure/CurrencyScaleResolver.php:54>).
- The plan explicitly provisions a scale-four company fixture, and owner A1 makes scale-four country precision supported.
- No temporary three-decimal ceiling is specified for the typed T3 service boundary or T5 HTTP path.

The unchanged ledger still stores `repository_movements.amount` and `balance_after` as scale three at [2026_07_08_100100_create_repository_movements_table.php:20](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_07_08_100100_create_repository_movements_table.php:20>) and [line 22](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_07_08_100100_create_repository_movements_table.php:22>), with scale-three casts at [RepositoryMovement.php:53](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Domain/RepositoryMovement.php:53>). Writes and retry comparisons remain at [TreasuryMovementService.php:588](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:588>), [590](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:590>), [682](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:682>), and [763](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:763>).

**Failure scenario:** a company with country precision four submits `1.0005`. The document, repository balance, and GL columns can retain four decimals after P0, while both movement columns round to scale three. Repository, document, movement, and GL evidence disagree; an identical retry may return an idempotency conflict.

**Minimum correction:** retain the `1.005` fixtures and bounded four-column P0, but add a temporary service-level operational ceiling of three fractional digits for repository transfers, including direct typed callers, even when the country preset is four. Add a scale-four-company red test asserting `1.0005` is refused with unchanged document/movement/repository/JE snapshots. State that the ceiling may be removed only after a separately accepted follow-up widens:

- `repository_movements.amount`
- `repository_movements.balance_after`
- both `RepositoryMovement` casts
- write/replay precision guards and their architecture/regression tests

Do not silently add that widening to this slice.

## MAJOR

None.

## MINOR

### m1 — Revision metadata still identifies the rejected revision 5

**Plan lines:** [1–2](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:1>).

The file and commit are revision 6, but the comment says “rev 5” and “awaiting gate r5,” while the title also says “rev 5.”

**Failure scenario:** dispatch evidence or a handback can identify the pre-correction revision and make it unclear whether gate-r5 B1 was incorporated.

**Minimum correction:** identify the document as revision 6 and update the status to the current gate round.

## Verified closures and contract coverage

- The three operational fixtures cited by gate r5 were changed from `1.0005` to `1.005`.
- `1.0005` remains confined to isolated raw precision/census evidence, not a successful transfer fixture.
- Q11–Q13 at plan lines 106–108 are byte-for-byte identical to owner-ruling lines 152–154.
- No Q12 reason-code enum or mapping, Q11 drawer-session model, Q13 alignment, shift-event booking, v2/v3 adapter, W7 work, or variance activation is shipped.
- Convention 10 uses the required eight-column matrix and covers create, duplicate, edit, cancel/reverse, rerun, second company, second location, permission, and audit.
- Convention 11 reconciles both new nouns with the existing Repository surface and assigns one table, writer, and operator surface to each.
- Convention 09 coverage names real registration, a real second `pos_enabled` location, and explicit business-mutation reruns per task.
- P0 and T1–T6 otherwise specify exact production files, complete schema contracts, PHP enums, signatures, red-first test file/class/method/assertion/command/lane, reviewer gates, and rollback.
- The deployment section contains every required per-slice manifest variable. Its copied §4 checklist is byte-for-byte identical to the shared manifest.
- All 106 explicit full `path:line` references exist and are in range at HEAD. Governed production sources have no changes between the plan’s `93e9ab1` baseline and current HEAD.

## Rejected false positives

- **“The three r5 operational fixtures still use `1.0005`.”** Rejected; they now use `1.005`.
- **“The census drift fixture is an operational four-decimal transfer.”** Rejected; it is isolated raw evidence intended to detect lost precision.
- **“The new document/provenance enums implement Q12.”** Rejected; they classify document state and actor provenance, not device cash-movement reasons.
- **“Cash custody defaults encode the later Q12 reason mapping.”** Rejected; drawer→safe and safe→bank custody defaults are the explicit prerequisite in this slice, without reason-code classification.
- **“P0 must widen the movement ledger now.”** Rejected; owner A1 bounds P0 to four existing columns. The correct repair is a temporary operational cap plus an explicit later prerequisite.
- **“P0 creates a seventh ungoverned slice task.”** Rejected; it is separately gated owner-A1 precision work, ordered before the six WCASH tasks.
- **“The older evidence SHA invalidates the anchors.”** Rejected; production and governed contract files are unchanged, and all anchors resolve at current HEAD.
- **“The shared deployment manifest is incompletely referenced.”** Rejected; the canonical sentence, variables, web fingerprint, topology gates, backup requirements, rollback points, and verbatim checklist are present.

## Preserve during correction

- The four-column P0 `(15,4)` contract and isolated raw `1.0005` proof.
- The `1.005` operational fixtures.
- P0-a census before P0-b ALTER, with per-tenant evidence and forward-only rollback.
- One document, one transfer group, two cross-linked legs, and zero-or-one posted JE.
- Append-only linked reversals and frozen-transfer evidence/alerts.
- Human/system authority union and stored-document-first replay authorization.
- W1 authorization as a hard prerequisite for HTTP activation.
- Generated DTO migration and the single existing Repository operator surface.
- Exact Q11–Q13 wording and all later-slice deferrals.
- Shared-manifest topology, backup, web fingerprint, five-push, and forward-only rollback requirements.

Owner decisions required: **None.** This is an engineering correction.

VERDICT: CHANGES-REQUIRED