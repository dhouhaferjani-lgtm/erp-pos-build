# Codex slice-plan gate r7 — W-CASH-1 rev 7 (gpt-5.6-sol, high, read-only, 2026-09-06)

Input: rev 7 at 780b07b07. Verbatim.

---
Reviewed current HEAD `780b07b075788d682f1921fd193c699c38fb7b20` read-only. No edits, tests, or Git writes were performed.

Result: **1 BLOCKER, 0 MAJOR, 1 MINOR.**

## BLOCKER

### B1 — The three-decimal transfer ceiling is stated in the change log but remains absent from the executable T3/T5 contract

**Plan lines:** [10](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:10), [434](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:434), [436](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:436), [496](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:496), [560](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:560), [357](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:357).

Line 10 states the intended ceiling, but the implementation tasks do not make it dispatchable:

- No task owns an exact production path and public signature for `RepositoryTransferPrecisionPolicy`.

- No typed precision exception is specified in the task-owned exception register.

- `RepositoryTransferErrorRenderer`’s exhaustive union omits such an exception, so the promised `422` `error.code` response cannot be rendered through the specified HTTP contract.

- The proposed red service test’s first assertion uses `$response->json(...)`, although line 436 requires T3 core tests to call `executeTransfer()` directly. It therefore does not prove rejection for direct human/system typed callers.

- T5 line 560 still instructs the service only to reject precision above the company country preset. It never states the required effective transfer limit `min(country preset, 3)`.

- Line 496’s companion refusal uses a TND scale-three fixture, not the required scale-four-company `1.0005` case.

- The removal gate does not enumerate the complete r6 condition: both movement columns, both casts, write/replay precision guards, and their architecture/regression tests.

Current code confirms why this remains blocking: movement storage is scale three at [migration:20](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_07_08_100100_create_repository_movements_table.php:20) and [migration:22](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_07_08_100100_create_repository_movements_table.php:22), with scale-three casts at [RepositoryMovement.php:53](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Domain/RepositoryMovement.php:53). Writes occur at [TreasuryMovementService.php:588](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:588) and [590](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:590); replay comparisons use the currency scale at [682](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:682) and [763](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:763).

**Failure scenario:** an implementer follows T3/T5 rather than the revision note. A scale-four company submits `1.0005`; the service applies only country-preset validation, and document/repository/GL scale-four evidence diverges from scale-three movement rows. An identical retry may conflict. If an exception is added ad hoc, the specified renderer cannot map it and the promised 422 can become a 500.

**Minimum correction:**

1. Move the ceiling into T3/T5’s normative contracts with an exact production file and full callable signature.

2. Specify the exact typed exception, stable response code, renderer-union addition, translations, and status-contract case.

3. Add direct human and system `executeTransfer()` red tests for a scale-four company and `1.0005`, asserting the typed exception and unchanged document/movement/repository/JE/ordinal snapshots. Keep the separate HTTP assertion in `RepositoryTransferEndpointTest`.

4. Change line 560 to require `min(company country preset, RepositoryTransferPrecisionPolicy::OPERATIONAL_SCALE_CEILING)` before every write.

5. State verbatim that removing the ceiling requires a separately accepted follow-up widening `repository_movements.amount`, `repository_movements.balance_after`, both `RepositoryMovement` casts, write/replay precision guards, and their architecture/regression tests.

## MAJOR

None.

## MINOR

### m1 — Revision status still names gate r5

**Plan line:** [1](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:1).

The document identifies rev 7 but says `Status: awaiting gate r5`, while line 11 claims the revision metadata was corrected.

**Failure scenario:** dispatch or handback evidence can be associated with the wrong review round.

**Minimum correction:** change the status to `awaiting gate r7`.

## Verified closures and contract coverage

- All 107 unique full-path anchors, plus their shorthand line continuations, resolve and match current HEAD. Governed production/config/test sources are unchanged from the plan’s `93e9ab1` evidence baseline.

- Q11–Q13 at plan lines 113–115 are byte-for-byte identical to owner-ruling lines 152–154.

- No Q11 drawer-session model, Q12 reason-code enum/mapping, Q13 historical alignment, shift-event booking, v2/v3 adapter, W7 work, or variance activation is shipped.

- Convention 10 uses the required eight-column format and covers create, duplicate, edit, reverse, rerun, second company, second location, permission, and audit. The benchmark claims remain bounded consistently with the official [Odoo 17 internal-transfer documentation](https://www.odoo.com/documentation/17.0/applications/finance/accounting/payments/internal_transfers.html), [ERPNext Payment Entry documentation](https://docs.frappe.io/erpnext/payment-entry), and [Dolibarr Banks and Cash documentation](https://wiki.dolibarr.org/index.php/Module_Banks_and_cash).

- Convention 11 reconciles both new nouns with `Repository`, names one table and writer each, and keeps configuration/history in the existing Treasury → Repositories surface.

- Convention 09 names real registration, a real second `pos_enabled` location with provisioned drawer, and explicit business-mutation reruns for T1–T6 and P0.

- Apart from B1, P0 and T1–T6 provide exact production files, schemas, enums, DTOs, red-first test methods/assertions/commands/lanes, reviewer gates, and rollback boundaries.

- Deployment includes the canonical manifest sentence, all required per-slice variables, topology/backup/flag/web-fingerprint requirements, and a byte-for-byte copy of manifest §4.

## Rejected false positives

- **“The older evidence SHA invalidates the anchors.”** Rejected; all anchors resolve at current HEAD and governed sources have no intervening diff.

- **“Operational fixtures still use `1.0005` successfully.”** Rejected; successful operational fixtures use `1.005`. Remaining `1.0005` cases are raw P0 storage/census evidence or the intended rejection case.

- **“Scale-four document storage itself violates the temporary ceiling.”** Rejected; storage capacity and operational ingress precision are distinct contracts.

- **“The provenance/document enums implement Q12.”** Rejected; they classify document kind, result, and actor provenance, not device cash-movement reasons.

- **“Existing `MovementReasonCode` means this slice ships Q12.”** Rejected; it predates this slice and no new device reason mapping or consumer is introduced.

- **“P0 must widen the movement ledger inside this slice.”** Rejected; the correct repair is the temporary ceiling plus an explicit separately accepted widening prerequisite.

- **“P0 is an illicit seventh WCASH task.”** Rejected; it is a separately gated program prerequisite, leaving six WCASH tasks.

- **“The deployment manifest is incompletely referenced.”** Rejected; the canonical reference, variables, checklist, topology gates, backups, five pushes, and forward-only rollback points are present.

## Preserve during correction

- P0-a census before P0-b widening, with per-tenant evidence and forward-only rollback.

- The bounded four-column P0 `(15,4)` contract and isolated raw `1.0005` proof.

- Successful operational fixtures at `1.005`.

- One document, one transfer group, exactly two cross-linked legs, and zero-or-one posted JE.

- Append-only linked reversals and frozen-transfer evidence/alerts.

- Human/system authority union and stored-document-first replay authorization.

- W1 authorization as a hard prerequisite for HTTP activation.

- Company-scoped composite ownership, immutable schema guards, PHP enums, and typed JSONB evidence.

- Generated DTO migration and the single existing Repository operator surface.

- Exact Q11–Q13 wording and every later-slice deferral.

- Shared-manifest topology, backup, web fingerprint, five-push, and forward-only rollback requirements.

Owner decisions required: **None.** B1 and m1 are engineering corrections.

VERDICT: CHANGES-REQUIRED