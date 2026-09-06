# Codex slice-plan gate r14 — W-CASH-1 rev 14 (gpt-5.6-sol, high, read-only, 2026-09-06)

Input: rev 14 at f7647264c. Verbatim.

---
Reviewed read-only at HEAD `f7647264caeacd8e6bbb9ee513d41b8d7feca238`. No edits, tests, or Git writes were performed. I used the repository-grounded adversarial approach from the `grilling` skill, resolving questions from code rather than asking the user.

Result: **0 BLOCKER, 5 MAJOR, 2 MINOR.**

## BLOCKER

None.

The former r13 blocker is closed: the plan contains exactly one operative binding statement at [plan:1445](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:1445). All earlier sequencing text is explicitly historical or points to that statement.

## MAJOR

### M1 — T1/T2 red-first packets regressed from rev 10

- **Plan lines:** [465–489](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:465), [713–743](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:713).
- **Source:** The authoring contract requires exact file, `Class::method`, first assertion, command, and lane for every red test at [prompt:19](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/CODEX-PROMPT-slice-plan-WCASH-1-custody-config-transfer-doc-2026-09-06.md:19). Rev 10 was confirmed complete on this criterion at [r10:43](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/reviews/2026-09-06-w-cash-1-slice-codex-gate-r10.md:43).
- **Defect:** T1’s migration-guard class has no named lane. T2 labels `CashCustodyConfigurationTest` “command/lane” but supplies no lane or exact file/FQCN. `CashCustodyCensusCommandTest` is reduced to prose coverage with no exact methods or first assertions. The metadata-concurrency test lacks an exact file/FQCN and named lane.
- **Failure scenario:** T1/T2 are in the immediate dispatch set, but implementers can omit census cases or place tests in inconsistent suites while still claiming the prose is satisfied. Reviewers cannot cite exact convention-09 evidence.
- **Minimum correction:** Restore rev-10-style registers: full root-relative test path, FQCN, every `Class::method`, its first failing assertion, exact command, and named lane for all four classes.

### M2 — T3’s compatibility and architecture-ratchet test contracts are incomplete

- **Plan lines:** [939–992](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:939).
- **Source:** [prompt:19](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/CODEX-PROMPT-slice-plan-WCASH-1-custody-config-transfer-doc-2026-09-06.md:19).
- **Defect:** The main `RepositoryTransferDocumentTest` table is adequate, but `RepositoryTransferCompatibilityTest` has no exact path/FQCN, per-test first assertions, command, or lane. The document-per-action ratchet supplies commands but not the required first assertions and lane.
- **Failure scenario:** The scalar-to-typed cutover can activate without proving that the old endpoint remains intact during Push 3 or that scalar fallback is removed atomically in T5.
- **Minimum correction:** Add complete executable registers for `RepositoryTransferCompatibilityTest` and both named `DocumentPerActionBaselineRatchetTest` methods.

### M3 — r13 M2 is only partially closed for T4

- **Plan lines:** [1040–1049](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:1040), compared with the otherwise complete tables at [1078–1107](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:1078).
- **Source:** r13 required every reversal/frozen case to have an executable register at [r13:26–31](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/reviews/2026-09-06-w-cash-1-slice-codex-gate-r13.md:26).
- **Defect:** The full `reverse(...)` signature, files, commands, lanes, rollback, and ordinary reversal/frozen assertions are restored. However, the three event/audit methods at lines 1042–1044 still have no first failing assertions. Rev 10 specified the opposite-leg event IDs, unchanged event shape, real-subscriber audit payload, and retry audit counts.
- **Failure scenario:** T4 can pass while only model relationships are correct and emitted events/audit rows contain wrong reversal linkage or duplicate on replay.
- **Minimum correction:** Add the three event/audit methods to the T4 table with exact first assertions and state that they use the same file, PG command, and named lane.

### M4 — r13 M3 remains open for T5

- **Plan lines:** [1201–1234](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:1201), [1238–1304](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:1238).
- **Source:** [r13:33–46](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/reviews/2026-09-06-w-cash-1-slice-codex-gate-r13.md:33), [convention 11:44](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md:44).
- **Defects:**
  - The production census uses basenames instead of exact paths and includes placeholders such as “web POS repository API `:9`”, “POS payment API `:9`”, and “POS sync service `:1256`”.
  - `AdvancedPaymentsModal.tsx:185` is ambiguous: both the web and POS files exist, and line 185 has unrelated meanings.
  - The required device storage file [paymentRepository.ts:24](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/lib/db/repositories/paymentRepository.ts:24) is not named even though line 1234 directs its type change.
  - The endpoint’s many required cases are prose rather than exact methods/assertions.
  - Web, compile-fixture, POS-storage, unknown-field, history, and history-UI tests lack complete names, first assertions, and named lanes.
- **Failure scenario:** An implementer updates the obvious Treasury UI while leaving a POS/web DTO shadow or storage alias behind, or declares endpoint coverage complete without testing the authorization and retry cases.
- **Minimum correction:** Restore the rev-10 census with full root-relative paths—including the storage adapter—and restore the complete backend/Vitest/typecheck/POS/history tables with exact test names, first assertions, commands, and lanes.

### M5 — r13 M4 remains open for T6

- **Plan lines:** [1312–1354](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:1312).
- **Source:** [r13:48–52](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/reviews/2026-09-06-w-cash-1-slice-codex-gate-r13.md:48).
- **Defect:** Assertions and focused commands were restored, and rollback is now complete at [1394](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:1394). But the three PHP classes lack attached exact paths/FQCNs and named lanes. The unique-index ratchet lacks the two exact `Class::method` entries, their first failures, and a named architecture lane.
- **Failure scenario:** The PG verification task can be accepted without proving the live-table classification and no-new-unique-violation invariants that convention 09 requires.
- **Minimum correction:** Restore the rev-10 T6 register verbatim in substance: path, FQCN, methods, first assertions, focused command, and lane for each class and both ratchet tests.

## MINOR

### m1 — The declared HEAD is stale

- **Plan lines:** [4](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:4), [1447](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:1447).
- **Source:** Actual HEAD is `f7647264caeacd8e6bbb9ee513d41b8d7feca238`, not `55bf3d…`.
- **Failure scenario:** A reviewer attributes the audit to the wrong snapshot.
- **Minimum correction:** Refresh both SHA declarations after rerunning the citation check. Production sources governed by this slice did not change between those SHAs, so this is metadata drift rather than contract drift.

### m2 — WCASH migration annotations are incomplete in the manifest-variable row

- **Plan line:** [1405](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:1405).
- **Source:** The manifest requires every migration to be annotated additive/self-guarding/prerequisite at [manifest:293–300](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:293). The plan establishes self-guarding behavior elsewhere at [463](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:463), but the deployment row labels the two WCASH migrations only “additive.”
- **Failure scenario:** The promotion handback cannot mechanically classify each migration from the required variable.
- **Minimum correction:** Annotate both WCASH migrations individually as additive and self-guarding, and state their P0-b/order prerequisites.

## Prior-gate reconciliation

| Prior item | Status |
|---|---|
| r11 B1 authoritative precision names/five classifications | Closed at plan 219–224 and 291–371 |
| r11 B2 dispatch contradiction | Closed; one binding statement at 1445 |
| r11 M1 scale-two acceptance | Closed; scale two refuses before DDL at 219, 247–257, 289 |
| r13 B1 duplicate binding order | Closed |
| r13 M1 architecture boundary | Closed |
| r13 M2 T4 complete packet | Partially closed; event/audit assertions missing |
| r13 M3 T5 complete packet | Open |
| r13 M4 T6 complete packet | Partially closed |
| r13 M5 deployment census greps | Closed at 1408 |
| r13 m1 internal anchors | Closed; replaced by stable section references |
| No regression from rev 10 | Not met: T1–T6 executable registers were compressed |

## Rejected false positives

- **Factory still violates Deptrac:** rejected. Treasury Application consumes only the Shared contract at [852–900](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:852). Company Infrastructure may depend on Company Domain and Shared Infrastructure under [deptrac.yaml:96–111](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/deptrac.yaml:96); the ratchet fails category or total growth at [deptrac-ratchet.php:178](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tools/deptrac-ratchet.php:178).
- **Amendment A/B is defective:** rejected. The plan has scale-3-only widening, no scale-2 success, all five classifications, the exact `fourth_decimal_present` predicate, red test, hard stop, full-tuple checks, HALF_UP evidence, and scale-three operational ceiling.
- **Q11–Q13 remain open or are implemented:** rejected. [Plan:196–198](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:196) matches [owner rulings:152–154](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:152) verbatim. Line 200 explicitly defers drawer sessions, device reason-code enums/mapping, and alignment. No such implementation ships.
- **Existing `MovementReasonCode` is Q12:** rejected; it is an existing movement projection field, not the deferred device enum/configuration.
- **Schema/state-machine claims contradict HEAD:** rejected. HEAD still creates an optional draft, writes exactly two transfer legs, returns no durable transfer document, posts cross-GL drafts inside the movement transaction, and keeps movements append-only at [RepositoryTransferService.php:64](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Application/Services/RepositoryTransferService.php:64), [TreasuryMovementService.php:299](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:299), and [RepositoryMovement.php:73](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Domain/RepositoryMovement.php:73).
- **Convention-10/11 failure:** rejected. The matrix has the requested eight columns and ten decided rows. Its constrained claims agree with official [Odoo 17 internal-transfer](https://www.odoo.com/documentation/17.0/applications/finance/accounting/payments/internal_transfers.html) and [ERPNext Payment Entry](https://docs.frappe.io/erpnext/payment-entry) documentation; Dolibarr document shape is correctly marked NV. Vocabulary agrees with [glossary:60](/Users/houssamr/Projects/syneriva/apps/erp/docs/glossary.md:60).
- **Shared manifest is otherwise missing:** rejected. The required quotation is exact, §4 is reproduced, census greps and tenant-set checks are supplied, and per-push rollback is present.
- **Broken fully qualified citations:** rejected. All full root-relative citations exist and are in bounds at current HEAD. T5’s shorthand/ambiguous file census is a specification-completeness problem, not a missing-file claim.

## Preserve

- The single dispatch statement and P0-a/P0-b separation.
- Scale-3-only source premise, scale-two refusal, scale-four storage, unchanged `decimal:3` casts, and `fourth_decimal_present` gate.
- The five precision classifications and Geometry treatment.
- The Shared-contract/Company-Infrastructure scale factory boundary and architecture ratchet.
- One document, one group, exactly two linked legs, and zero-or-one posted JE.
- Stored-document-first authorized replay, human/system authority union, and three-decimal operational ceiling.
- Append-only linked reversals, immutable original evidence, and after-commit frozen warnings.
- W1 authorization and permission-rollout prerequisite for T5.
- Exact Q11–Q13 wording and every later-slice deferral.
- Convention-10 matrix, convention-11 vocabulary, shared manifest quotation, census greps, backups, and forward-only rollback.

## Owner decisions required

None.

VERDICT: CHANGES-REQUIRED