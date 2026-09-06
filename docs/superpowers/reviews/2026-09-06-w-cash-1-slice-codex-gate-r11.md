# Codex slice-plan gate r11 — W-CASH-1 rev 11 amendment A (gpt-5.6-sol, high, read-only, 2026-09-06)

Input: rev 11 at 8d9255f21. Verbatim.

---
Reviewed read-only at HEAD `965931a32849f4d3cd5eb3cf7297ff646bff4f00`. No edits, tests, or Git writes were performed.

Result: **2 BLOCKER, 1 MAJOR, 1 MINOR.**

## Findings

### BLOCKER 1 — P0-a does not actually reuse the referenced precision brief

- **Plan line:** [173](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:173), contradicted by [202–225](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:202) and [732–736](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:732).
- **Source:** The reused brief defines `MoneyPrecisionCensusCommand`, `treasury:census-money-precision`, `MoneyPrecision*` DTOs, `MoneyColumnClassification`, and marker `MONEY-PRECISION CENSUS` at [precision brief:50–68](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-07-precision-widening-gl-repository-scale-4.md:50). Its deploy variables are at [103–117](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-07-precision-widening-gl-repository-scale-4.md:103).
- **Mismatch:** WCASH still specifies `RepositoryPrecisionCensusCommand`, `treasury:census-repository-precision`, `RepositoryPrecision*` DTOs, marker `P0-PRECISION-CENSUS:`, different migration/test filenames, and different deployment identifiers.
- **Additional mismatch:** Amendment A lists `Percent/Quantity/Money/Other`, but the referenced allowlist has five classifications, including `Geometry`; `pos_tables.*` is explicitly Geometry at [precision brief:57–64](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-07-precision-widening-gl-repository-scale-4.md:57).
- **Failure scenario:** Two implementers can legitimately build incompatible P0-a commands and DTOs. Deployment then invokes or greps a command/marker that does not exist. Omitting Geometry can also misclassify POS geometry columns as Money and contaminate the follow-up ticket.
- **Minimum correction:** Make the referenced brief authoritative by replacing the old P0-a file, DTO, enum, CLI, marker, migration/test and deployment-variable names throughout the plan. Include all five classifications. Alternatively, remove “reuse, do not re-derive” and provide an explicit one-to-one compatibility map for every intentional rename.

### BLOCKER 2 — The operative dispatch order contradicts Amendment A

- **Plan line:** Amendment A authorizes P0-a/T1/T2 now and T3+ only after P0-b at [174](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:174).
- **Contradictory operative lines:** [176](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:176), [219](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:219), [740](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:740), [774](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:774), and [782](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:782) still prohibit starting T1/T2 until P0-b.
- **Failure scenario:** Dispatchers either hold T1/T2 contrary to the amendment or treat the final dispatch checklist as authoritative over Amendment A. The requested immediate dispatch set is therefore not deterministic.
- **Minimum correction:** State consistently in every normative dependency, deployment and final-checklist location: **dispatch P0-a, T1 and T2 now; hold P0-b until the benchmark and cast ruling; dispatch T3–T6 only after accepted P0-b.** Historical change-log text may remain if explicitly labelled superseded.

### MAJOR 1 — P0-b still accepts the forbidden scale-2 input tuple

- **Plan line:** Amendment A says accepted inputs are exact `(15,3,…)` or already-compliant `(15,4,…)`, and anything else aborts at [171](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:171).
- **Contradictory task contract:** [189](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:189), [191](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:191), and [217](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:217) still accept and repair `(15,2)`.
- **Source:** HEAD’s March migration widens journal columns at [27–30](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_03_11_200000_widen_monetary_columns_to_scale_3.php:27) and repository columns at [113–116](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_03_11_200000_widen_monetary_columns_to_scale_3.php:113). The reused brief accepts only `(15,3)` or `(15,4)` at [75–77](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-07-precision-widening-gl-repository-scale-4.md:75).
- **Failure scenario:** A tenant missing the March migration is silently widened instead of failing closed, concealing migration-history drift and violating the ruled source-shape prerequisite.
- **Minimum correction:** Remove scale 2 from accepted inputs and successful fixtures. Replace the scale-2 repair test with a red test asserting `unexpected_column_shape`, no DDL, and unchanged rows/schema.

### MINOR 1 — Amendment insertion regressed revision metadata and internal anchors

- **Plan line:** The header still begins “W-CASH-1 rev 10” at [1](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:1), despite the rev-11 title.
- **Stale anchors:** [19–23](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:19) point to pre-insertion line numbers. The corresponding contracts are now at lines 498, 396, 404, 653 and 400/402.
- **Failure scenario:** Reviewer handoffs navigate to unrelated contracts and may incorrectly report a missing requirement.
- **Minimum correction:** Change the header to rev 11 and re-anchor the five internal plan-line references.

## Verified and rejected false positives

- All **134 root-relative path:line occurrences, 111 unique references**, exist and are in bounds at HEAD. Production code has not changed since the plan’s `b8b2b8e…` evidence baseline; the intervening committed changes are documentation-only.
- The missing benchmark note named at plan line 172 is not a broken current-source citation: it is explicitly a future P0-b dispatch prerequisite.
- **The remaining `decimal:4` cast instructions are not authoritative.** Plan line 172 expressly supersedes every P0-b statement changing casts. HEAD confirms repository casts remain `decimal:3` at [PaymentRepository.php:214](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Domain/PaymentRepository.php:214) and GL casts at [JournalLine.php:54](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Accounting/Domain/JournalLine.php:54). They must remain so provisionally.
- The T3 operational ceiling remains internally correct: plan [498](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:498) retains `OPERATIONAL_SCALE_CEILING = 3`, both authority-branch tests, unchanged snapshots, and the exact removal condition. HEAD movement storage remains scale 3 at [repository movement migration:20–22](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_07_08_100100_create_repository_movements_table.php:20).
- Q11–Q13 are verbatim at plan [149–151](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:149) versus owner rulings [152–154](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:152). Line 153 correctly defers drawer sessions, device reason codes/mappings and historical alignment. No such feature is added by this slice.
- Convention 09, the exact convention-10 matrix, convention-11 vocabulary/glossary additions, task schemas/enums/tests/lanes/reviewer gates/rollbacks, and the shared-manifest contract did not regress from r10.
- The deployment section retains the manifest’s required sentence at plan [725–728](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:725), supplies the required variables, reproduces the §4 checklist, and preserves forward-only rollback.

## Preserve

- P0-a, T1 and T2 may dispatch now.
- P0-b remains conditional on the benchmark and owner cast ruling.
- T3–T6 remain held until P0-b acceptance.
- Widen only the four ruled columns from exact `(15,3)` to `(15,4)`; exact `(15,4)` is an idempotent no-op; every other tuple refuses.
- Keep the four Eloquent casts at `decimal:3` provisionally.
- Keep the temporary three-decimal transfer ceiling and its full removal condition.
- Preserve Q11–Q13 verbatim and defer drawer sessions, reason codes/mappings, alignment, shift booking, v2/v3 adapters, W7 and variance activation.
- Preserve convention-09 coverage per task, one Repository surface, generated DTO discipline, shared-manifest deployment variables, backups and forward-only rollback.

Owner decisions required: **None introduced by this review.** The already-declared future cast ruling remains the intentional P0-b dispatch condition.

VERDICT: CHANGES-REQUIRED