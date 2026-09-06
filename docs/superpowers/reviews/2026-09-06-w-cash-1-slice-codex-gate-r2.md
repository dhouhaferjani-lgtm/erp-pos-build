# Codex slice-plan gate r2 — W-CASH-1 rev 2 (gpt-5.6-sol, high, read-only, 2026-09-06)

Input: rev 2 at 5fe262c84. Verbatim.

---
Changes are required before dispatch. I found 2 BLOCKERs, 5 MAJORs, and 4 MINORs.

Audit baseline: reviewed HEAD is `5fe262c84527d90a6124e467978b282806630d50`. Production code is unchanged from the plan’s `f13b923…` baseline; only plan documents changed. No edits, tests, or git writes were performed. The two unrelated untracked files remain untouched.

## BLOCKER

### B1. The transfer contract cannot represent required system-authority callers

- Plan: [lines 122, 234, 246](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:122)
- Governing spec: [W1 line 141](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:141)
- Failure scenario: `RepositoryTransferIntent` and `RepositoryTransferAuthorizationIntent` require a user actor, actor/company membership, and a non-null `created_by` FK. W1 explicitly requires queued/console callers to use system authority with persisted provenance and without manufacturing an HTTP/user principal. Later cash projectors therefore cannot use this purported prerequisite without inventing a tenant user or bypassing its contract.
- Minimum correction: Define a discriminated human/system authorization intent. Persist initiator kind and stable system-authority identity/provenance in document evidence and schema; make user membership and `created_by` applicable only to human submissions. Define system replay identity and require a different system principal or different provenance/semantic content to conflict. Add PG red tests for system first submission, exact retry, different system authority conflict, provenance mismatch, and absence of a fabricated user. Update T1 schema, DTOs, T3 signatures, replay comparison fields, HTTP/internal boundaries, and rollback documentation.

### B2. Three-decimal transfers are incompatible with the physical balance and GL schema

- Plan: [money contract line 68](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:68), [document schema line 113](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:113), [cross-GL assertion line 264](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:264)
- Source: [precision rule](/Users/houssamr/Projects/syneriva/apps/erp/CLAUDE.md:71), [repository balance is decimal(15,2)](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2025_11_30_120000_create_treasury_tables.php:29), [journal debit/credit are decimal(15,2)](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2025_11_30_100000_create_journal_entries_table.php:51), while model casts claim scale 3 at [PaymentRepository.php:214](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Domain/PaymentRepository.php:214) and [JournalLine.php:54](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Accounting/Domain/JournalLine.php:54).
- Failure scenario: a valid TND transfer of `1.005` is stored as `1.005` in the document and movements but rounded by PostgreSQL in repository balances and journal lines. The document, custody balance, movement `balance_after`, and JE cease to describe the same amount; the proposed exact cross-GL test cannot pass honestly.
- Minimum correction: Add an accepted precision prerequisite or a migration widening at least `payment_repositories.balance`, `payment_repositories.last_reconciled_balance`, and `journal_lines.debit/credit` to scale 3. Add PG schema-shape and raw round-trip tests using `1.005`. Because the canonical manifest limits Push 2 to additive schema at [lines 89–93](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:89), explicitly reconcile the widening with that deployment authority rather than silently placing an `ALTER COLUMN` in the additive push.

## MAJOR

### M1. T3 cannot finish green while its only production caller remains on the removed signature

- Plan: [T3 signature replacement line 234](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:234), [caller deferred to T5](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:234), [sequential task gates line 479](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:479)
- Source: the controller calls eight scalar named arguments at [RepositoryTransferController.php:28](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Presentation/Controllers/RepositoryTransferController.php:28).
- Failure scenario: after T3, the controller invokes parameters that no longer exist. Static analysis fails and the route fatals until T5, but T5 is separately blocked on the external W1 authorization task. T3 also promises its existing transfer regression will be green.
- Minimum correction: Include an exact T3 controller/adapter transition that compiles and refuses writes safely while the flag is false, or retain an explicitly temporary compatibility method that cannot call the documentless writer. Name its removal in T5 and add the affected controller/regression tests to T3’s exact files and gate.

### M2. Convention 09 remains incomplete per task

- Plan claim: [line 43](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:43)
- Incomplete examples: [T1 lines 154–157](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:154), [T2 line 202](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:202), [T3 line 271](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:271)
- Convention: real second-company path, real second `pos_enabled` location, and repeated mutation with an explicit outcome are mandatory at [convention 09 lines 37–50](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/09-SECOND-OF-EVERYTHING.md:37).
- Failure scenario: T1 substitutes “run the migration twice” for repeating the catalogue mutation, and its second-location case merely inserts configuration against a fixture. T1–T5 generally do not state that the second `pos_enabled` location and its drawer are created through the real creation path. First/default-location leakage can therefore survive each task gate.
- Minimum correction: For every in-scope task, state the verified real company-creation and real `pos_enabled` location-creation path, assert the provisioned drawer belongs to the selected second location, and repeat the actual configuration/transfer mutation with `Unchanged` or `AlreadyRecorded`. Restructure T1 with the minimal writer needed to exercise a real rerun, or combine its gate with the writer task; migration rerun remains a separate schema test.

### M3. The promised repository-history document link has no implementation file set

- Plan: vocabulary promises repository-detail history at [line 41](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:41); authorization requires history suppression at [line 336](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:336); UI promises the document link at [line 349](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:349).
- Source: the backend movement response has no document ID/link at [RepositoryMovementController.php:101](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Presentation/Controllers/RepositoryMovementController.php:101); its web contract has none at [useRepositoryMovements.ts:38](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/treasury/hooks/useRepositoryMovements.ts:38); the tab explicitly treats transfers as having no detail route at [RepositoryMovementsTab.tsx:51](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/treasury/components/RepositoryMovementsTab.tsx:51). None is in T5’s production-file list at [line 322](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:322).
- Failure scenario: the success response may show a transient link, but after reload neither repository’s history can discover the immutable justification. The promised source-authorized/destination-only suppression is consequently unimplemented.
- Minimum correction: Add the movement controller/DTO, hook, movement tab, and repository-detail files explicitly. Specify an authorized document ID/href projection, both-leg discovery, destination-only omission, direct 404, and no embedded-evidence leak. Add backend and web tests covering reload and both repository histories.

### M4. The “ALL shadows” closure misses a live consumer, and POS cannot resolve generated globals as planned

- Plan: [lines 343–347](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:343), [compile gate line 381](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:381)
- Source: `StatementUploadWizard` still hand-rolls `{id,name,currency}` at [StatementUploadWizard.tsx:26](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/treasury/statements/StatementUploadWizard.tsx:26) and receives `usePaymentRepositories()` data at [StatementListPage.tsx:56](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/treasury/statements/StatementListPage.tsx:56). Web loads generated globals through a triple-slash reference at [vite-env.d.ts:3](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/vite-env.d.ts:3), but POS has no equivalent at [apps/pos/src/vite-env.d.ts:1](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/vite-env.d.ts:1), no TS path at [tsconfig.json:20](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/tsconfig.json:20), and no shared dependency at [package.json:17](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/package.json:17). Generated declarations are ambient namespaces at [generated.d.ts:1](/Users/houssamr/Projects/syneriva/apps/erp/packages/shared/types/generated.d.ts:1), not named `PaymentRepositoryData` exports.
- Failure scenario: one structural shadow survives convention 11, while the proposed POS `Pick<PaymentRepositoryData,…>` cannot resolve during the named compile gate.
- Minimum correction: Add `StatementUploadWizard.tsx` and derive its alias from the generated DTO. Specify the exact POS generated-type wiring—such as the same path reference used by web—and use the complete namespace `App.Modules.Treasury.Application.DTOs.PaymentRepositoryData`. Include the wiring file in T5’s production list and compile assertions.

### M5. The document-per-action architecture baseline is omitted

- Plan: T3 replaces the current service at [line 232](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:232).
- Source: the current service’s compensating delete is explicitly baselined at [document-per-action-baseline.json:34](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Architecture/baselines/document-per-action-baseline.json:34), and the ratchet rejects stale entries at [DocumentPerActionBaselineRatchetTest.php:78](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Architecture/DocumentPerActionBaselineRatchetTest.php:78).
- Failure scenario: correctly removing the draft-delete path makes the architecture gate fail because the baseline entry becomes stale. Leaving the delete merely to keep the gate green preserves a documentless compensation escape hatch.
- Minimum correction: Add the baseline JSON to T3’s exact files, remove only the Treasury entry after proving the violation disappeared, and name `DocumentPerActionBaselineRatchetTest::repository_violations_match_the_baseline_exactly()` plus the protected-blob ceiling command/lane in the T3 gate.

## MINOR

### m1. Notes canonicalization is not precise enough for the replay contract

- Plan: [line 238](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:238)
- Failure scenario: “canonicalized null/empty consistently” does not define whether omitted, `null`, `""`, whitespace-only, leading/trailing whitespace, or Unicode-equivalent strings are equal. Two implementations can disagree over whether a retry is `AlreadyRecorded` or 409.
- Minimum correction: State the exact normalization function and comparison encoding. Add provider cases for omitted/null/empty equivalence and for every retained meaningful-character difference producing 409.

### m2. “Unknown fields are rejected” lacks a Laravel mechanism and red tests

- Plan: transfer at [line 326](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:326), reversal at [line 328](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:328), configuration at [line 330](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:330)
- Failure scenario: Laravel ignores arbitrary unruled keys by default. Prohibiting a finite list does not reject `unexpected_field`, contradicting the stated wire contract.
- Minimum correction: Specify an exact allowlist comparison in each FormRequest and add named 422/no-write tests for arbitrary unknown keys.

### m3. T1 lacks an explicit enum/CHECK parity gate

- Plan: `kind` and its PHP enum are specified at [lines 109, 125, 133](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:109), but T1’s schema test starts with only table/unique identity at [line 147](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:147).
- Failure scenario: the enum and CHECK can diverge without any named T1 red assertion; discovery is deferred to broad preflight.
- Minimum correction: Add an invalid-kind direct-SQL assertion and the existing [EnumCheckParityTest method](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Architecture/EnumCheckParityTest.php:212) to T1’s exact PG gate.

### m4. Evidence baseline and one code anchor are imprecise

- Plan: [line 3](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:3) calls `f13b923…` current HEAD; current HEAD is `5fe262…`. Line 36 cites [RepositoryTransferService.php:120](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Application/Services/RepositoryTransferService.php:120) for scoping, but the tenant/company predicates are lines 121–123.
- Failure scenario: reviewers cannot distinguish the production-code baseline from the committed plan revision, and the B6 citation lands before the claimed predicates.
- Minimum correction: Record both reviewed HEAD and unchanged production-source baseline, then cite line 121 for the scoping claim.

## Round-1 closure table

| r1 finding | Disposition in rev 2 | Evidence |
|---|---|---|
| BLOCKER 1 — stale owner authority | CLOSED | Verbatim ruled text and deferral at [45–55](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:45). |
| BLOCKER 2 — canonical manifest absent | CLOSED | Required reference and variables at [429–450](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:429); verbatim checklist at [452](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:452). |
| BLOCKER 3 — unsafe replay authorization/identity | CLOSED for the r1 oracle/configuration defects | Unique, comparison fields, authorization order and red tests at [238–246](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:238), [266–268](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:266), and [357–360](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:357). The remaining normalization ambiguity is m1. |
| MAJOR 1 — tenant/company/actor relational consistency | CLOSED for human actors | Composite ownership contract and direct-SQL tests at [81, 127–131, 148–152](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:81). System authority remains B1. |
| MAJOR 2 — command path/signature | CLOSED | Exact namespace, signature, option matrix, output, exit codes, tests and manifest marker at [186–216](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:186). |
| MAJOR 3 — metadata writer/lock order | CLOSED | Named writer and common lock order at [178–184](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:178), with mutation and concurrency tests at [203, 218–224](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:203). |
| MAJOR 4 — per-task red/Convention 09 | NOT CLOSED | Exact registers were added, but T1 substitutes migration rerun for mutation rerun and T1–T5 do not pin the real second-location creation path. See M2. |
| MAJOR 5 — HTTP contract/W1 prerequisite | NOT CLOSED | W1 is correctly hard-gated at [324](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:324), but the repository-history production path is missing and arbitrary unknown-field rejection is unspecified. See M3 and m2. |
| MAJOR 6 — remaining shadow types | NOT CLOSED | The earlier listed shadows were added, but `StatementUploadWizard` and POS generated-global wiring remain omitted. See M4. |
| MINOR 1 — three citations | CLOSED | Append-only guard, route wrapper, and command-registration anchors are corrected at [72, 322, 186](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:72). |

## Rejected false positives

- Q11–Q13 are reproduced verbatim from [owner rulings 152–154](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:152). The plan explicitly defers the drawer-session model, Q12 reason enum/mapping, and Q13 alignment. `RepositoryTransferDocumentKind`, outcome enums, and the existing `MovementReasonCode` are not Q12 reason codes.
- The convention-10 table has the required eight columns and all nine mandated rows at [lines 29–39](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:29).
- The vocabulary line is compatible with convention 11 and the existing Repository glossary row. Both new concepts retain one table, writer, and existing Treasury → Repositories surface.
- The schema’s FK delete/update actions are not missing: line 81 establishes RESTRICT as the default for all unspecified FKs.
- The replay contract correctly uses `(company_id, operation_uuid)`, not an actor-inclusive unique, and explicitly makes a different actor or changed source/destination/amount/currency/notes conflict.
- Same-GL transfers deliberately produce two repository legs and no JE; cross-GL produces one balanced posted JE.
- The manifest reference, variables, no-queue/no-device declarations, web fingerprint, host backup, forward-only rollback, and U-1/U-2 promotion preconditions are present.
- B1 does not require shipping a queue, shift booking, drawer session, reason policy, W7, or variance activation. It only makes the shared prerequisite service compatible with the already-ruled W1 system-authority boundary.
- The existing raw Axios transfer hook is not double-unwrapping; the plan’s warning remains correct.

## Preserve list

- Six-task ceiling and sequential T1 → T6 structure, after repairing task boundaries.
- Exact convention-10 matrix and the two glossary concepts on the existing repository surface.
- Append-only configuration revisions and immutable transfer/reversal documents.
- Company-scoped operation UUID, independent server transfer group, exact same-actor replay, and conflict on actor or semantic drift.
- One transaction, two cross-linked legs, zero/one posted JE, immutable original, and full linked reversal.
- Tenant/company composite FKs and direct-SQL PG enforcement.
- Operation → numbering → company → sorted repository lock order.
- HTTP-only location resolution and explicit application-service authorization intents.
- Frozen internal record-and-alert behavior with no client-controlled override.
- Hard W1 prerequisite and refusal of documentless writes while the new flag is false.
- Generated numeric-string DTO direction, tenant-scoped query keys, and single-unwrapped client helpers.
- Canonical staging manifest integration and forward-only financial rollback.
- Exclusion of drawer sessions, Q12 reasons, alignment, shift booking, v2/v3 adapters, W7, and variance activation.
- The two unrelated untracked files.

## Owner decisions required

None. Q11–Q13 are ruled. W1 acceptance, precision-schema remediation, manifest U-1/U-2/U-5, and staging evidence are engineering or operational prerequisites, not unresolved policy decisions.

VERDICT: CHANGES-REQUIRED