# Codex slice-plan gate r16 — W-CASH-1 rev 16 (gpt-5.6-sol, high, read-only, 2026-09-06)

Input: rev 16 at e55aadf1f. Verbatim.

---
# Review result

Reviewed read-only at local `dev` HEAD `4379f91a954ddd920517dd5bc6f0e3ef132468cc`. No edits, tests, or Git writes were performed.

Result: **0 BLOCKER, 2 MAJOR, 2 MINOR.**

All existing root-relative `path:line` references resolve and are in bounds at HEAD. Proposed `NEW` paths were treated as specifications, not existing files. Changes since the plan’s declared `44868d82c…` snapshot are documentation-only; governed production code has not drifted.

## BLOCKER

None.

## MAJOR

### M1 — T3 contradicts its mandatory authorized-replay order

- **Plan lines:** The mandatory sequence requires locks, stored-document lookup, and historical/current source authorization before semantic comparison at [plan:678](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:678), and promises identical authorized retries return the original at [plan:680](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:680). T3 instead mandates current company-scale precision validation before operation locks or replay lookup at [plan:775](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:775) and [plan:792](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:792).
- **Source:** The current lower port deliberately resolves an idempotent hit before applying mutable policy to new writes at [TreasuryMovementService.php:299](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:299). The plan itself reiterates document replay before mutable rules at [plan:813](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:813).
- **Failure scenario:** A transfer originally accepted as `1.005` under a three-decimal company preset is retried after the company’s effective country scale becomes two. The pre-lookup precision check returns the new precision failure instead of `AlreadyRecorded`, despite an existing immutable document. It also prevents the stipulated stored-source authorization/error precedence from controlling the replay.
- **Minimum correction:** Split new-write validation from replay handling. After authentication/basic permission, acquire the documented locks, resolve and authorize any stored document, and complete replay/conflict handling using its immutable amount/currency evidence. Apply the current company precision policy only when no stored operation exists. Add an exact red test proving an identical retry remains `AlreadyRecorded` after effective-scale change, with unchanged document, legs, JE, balances, and ordinals.

### M2 — Required red-first packets remain mechanically incomplete

- **Plan lines:** [T1 migration guard:475](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:475), [T4 event/audit additions:890](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:890), [T4 warning additions:892](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:892), [T5 additional endpoint tests:1006](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:1006), [T5 history tests:1008](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:1008), [T5 history UI tests:1010](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:1010).
- **Source:** The scope contract requires every red test to have an exact file, `Class::method`, first failing assertion, exact command, and named lane at [authoring prompt:19](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/CODEX-PROMPT-slice-plan-WCASH-1-custody-config-transfer-doc-2026-09-06.md:19). Gate r14 specifically required this restoration for T1, T4, and T5 at [r14:18](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/reviews/2026-09-06-w-cash-1-slice-codex-gate-r14.md:18), [r14:34](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/reviews/2026-09-06-w-cash-1-slice-codex-gate-r14.md:34), and [r14:42](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/reviews/2026-09-06-w-cash-1-slice-codex-gate-r14.md:42).
- **Defects:**
  - T1’s migration guard has no FQCN, uses unqualified method names, and says “PG lane command” without naming the lane.
  - T4’s event/audit and warning methods are outside the executable tables, are not class-qualified, and rely on “Same T4 PG command” without attaching the named lane to those tests.
  - T5’s additional endpoint/history methods are not class-qualified; `RepositoryTransferHistoryTest` lacks an FQCN; the history UI packet has a command but no named lane.
- **Failure scenario:** An implementer can omit these ancillary but contract-critical cases while satisfying the principal tables. In particular, reversal event/audit payloads, migration liveness, unknown-field rejection, and persisted-history authorization can be accepted without traceable red evidence.
- **Minimum correction:** Convert each group into an executable register giving root-relative file, FQCN or exact Vitest suite, every qualified `Class::method`, literal first failing assertion, exact working-directory command, and named lane. Rev-10 byte identity does not close this: these omissions were already latent in rev 10.

## MINOR

### m1 — Reviewed-HEAD metadata remains internally inconsistent

- **Plan lines:** The header declares `44868d82c…` at [plan:1](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:1), but the evidence baseline and final checklist still claim `55bf3d142…` at [plan:14](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:14) and [plan:1105](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:1105). Actual gate HEAD is `4379f91a9…`.
- **Failure scenario:** Review or implementation evidence is attributed to three different snapshots.
- **Minimum correction:** Declare one reviewed source baseline consistently and separately note the gate HEAD if the plan commit itself advanced HEAD. Re-run the citation check before updating all three declarations. This is metadata drift only; no governed production source changed.

### m2 — Two required manifest variables are present elsewhere but omitted from their variable rows

- **Plan lines:** The flags row omits the config file at [plan:1064](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:1064). The commands row does not state whether `treasury:census-cash-custody` runs under `tenants:run` at [plan:1065](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:1065).
- **Source:** The manifest requires the config file and command tenancy mode at [manifest:299](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:299) and [manifest:300](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:300).
- **Failure scenario:** A deployment handback consuming only the canonical variable table must rediscover those values.
- **Minimum correction:** Add `apps/api/config/treasury.php` to Flags and “standalone; do not run under `tenants:run`” to Commands. Those values already appear at [plan:817](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:817) and [plan:618](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:618).

## Prior-gate reconciliation

| Prior item | Rev-16 status |
|---|---|
| r11 B1 authoritative precision package and five classifications | Closed |
| r11 B2 contradictory dispatch order | Closed; exactly one operative statement at plan 1103 |
| r11 M1 scale-two acceptance | Closed; scale two is refusal-only |
| r11 m1 revision/anchor metadata | Historical anchors closed; current SHA inconsistency remains under minor m1 |
| r13 B1 duplicate binding statements | Closed |
| r13 M1 Deptrac-unsafe scale factory | Closed by Shared contract, Company Infrastructure implementation, provider binding, and architecture gates |
| r13 M2 / r14 M3 T4 packet | Partially closed; principal tables/signature restored, additional event/audit/warning register remains incomplete |
| r13 M3 / r14 M4 T5 packet | Partially closed; production census and principal tables restored, additional backend/history/UI register remains incomplete |
| r13 M4 / r14 M5 T6 packet | Closed |
| r13 M5 deployment census greps | Closed |
| r13 m1 historical anchors | Closed |
| r14 M1 T1/T2 packets | T2 closed; T1 migration-guard packet remains incomplete |
| r14 M2 T3 compatibility/architecture registers | Closed; the replay/precision contradiction above is a separate semantic defect |
| r14 m1 declared HEAD | Not fully closed |
| r14 m2 migration annotations | Closed |

## Rejected false positives

- **Q11–Q13 are stale or implemented by this slice:** Rejected. Plan [206–208](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:206) matches owner rulings [152–154](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:152) verbatim. Plan [210](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:210) expressly defers the drawer-session model, device reason-code enum/mapping, and counted alignment. Transfer kind, initiator, system-authority, and census enums are not Q12 reason codes.
- **P0 is WCASH scope creep:** Rejected. It is a separately packaged and gated prerequisite expressly required by Amendments A/B.
- **T1 schema is incomplete:** Rejected. It specifies every column, type, null/default, ownership FK/action, check, index, company-scoped unique, immutable/deferred trigger, JSONB DTO, and required PHP enum.
- **The Company scale factory still violates Deptrac:** Rejected. Treasury Application consumes only the Shared contract; Company Infrastructure owns the Company-model implementation.
- **HEAD already has a transfer document:** Rejected. The current wrapper creates an optional draft at [RepositoryTransferService.php:76](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Application/Services/RepositoryTransferService.php:76), invokes paired movements at [RepositoryTransferService.php:89](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Application/Services/RepositoryTransferService.php:89), and returns only group/JE/legs at [RepositoryTransferService.php:108](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Application/Services/RepositoryTransferService.php:108).
- **Convention 09 substance is absent:** Rejected. Every task names real second-company, second-`pos_enabled`-location, and actual mutation-rerun evidence. The finding above concerns incomplete executable-register notation, not missing scenarios.
- **Convention 10/11 are wrong:** Rejected. The matrix has the required eight columns and all required guarantee rows; the vocabulary uses the existing Repository concept and assigns one table, writer, and surface to each new term. Odoo’s destination payment and paired accounting entries are supported by the [Odoo 17 internal-transfer documentation](https://www.odoo.com/documentation/17.0/applications/finance/accounting/payments/internal_transfers.html), while ERPNext documents Internal Transfer between source and destination cash/bank accounts in its [Payment Entry documentation](https://docs.frappe.io/erpnext/payment-entry). Dolibarr document shape remains explicitly NV.
- **Shared manifest quotation/checklist are missing:** Rejected. The quotation is exact, §4 is copied verbatim, migration annotations and census greps are complete, and rollback is forward-only. Only the two minor variable-row omissions remain.
- **Broken source citations:** Rejected. Existing source citations resolve at HEAD; `NEW` paths are clearly proposals.
- **Production drift invalidates the review:** Rejected. The commits after the declared baseline changed this plan and an unrelated W-LOT review only.

## Preserve

- Exact Q11–Q13 wording and all explicit later-slice deferrals.
- The single binding dispatch statement and P0-a/P0-b separation.
- Scale-three premise, scale-two refusal, `(15,4)` storage widening, provisional `decimal:3` casts, five classifications, and `fourth_decimal_present` hard gate.
- Shared-contract/Company-Infrastructure scale factory and Deptrac ratchets.
- Stored-document-first authorized replay; correct only the placement of mutable precision validation.
- One document, one group, exactly two linked legs, and zero-or-one posted JE.
- Human/system authority union without forged system users.
- Append-only linked reversals, immutable original evidence, and after-commit frozen warnings.
- W1 authorization prerequisite, one Repository operator surface, and generated DTO replacement.
- Convention-09 substantive scenarios, convention-10 matrix, convention-11 vocabulary, manifest quotation/checklist, census greps, backups, and forward-only rollback.

## Owner decisions required

None.

VERDICT: CHANGES-REQUIRED