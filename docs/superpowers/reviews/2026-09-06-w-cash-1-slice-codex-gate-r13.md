# Codex slice-plan gate r13 — W-CASH-1 rev 13 (gpt-5.6-sol, high, read-only, 2026-09-06)

Input: rev 13 at 3328739cd. Verbatim.

---
Review basis: local `dev` HEAD `3328739cd080a5fe39470d48109cedb376b4c791`. The plan’s evidence SHA is its parent; governed production sources did not change between that SHA and HEAD. All 88 distinct `path:line` citations resolve at HEAD. No tests or writes were performed.

## BLOCKER

### B1 — The plan contains conflicting “binding” dispatch orders

- Plan: [lines 21–24](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:21) and especially [line 82](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:82) still say to hold P0-b pending the benchmark and owner cast ruling. That conflicts with Amendment B at [line 194](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:194) and the final order at [line 740](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:740), where those gates are resolved and only the zero detector remains.
- The deployment quotation at [line 694](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:694) also appends dispatch instructions to the manifest’s required verbatim quotation, rather than keeping dispatch in one authoritative section. The manifest requires the quotation verbatim at [manifest:267](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:267).
- Failure scenario: separate implementers can reasonably treat line 82 as “current binding order” and indefinitely hold P0-b, while another proceeds after the detector. The requested “stated once and consistently” dispatch contract is not met.
- Minimum correction: retain one binding dispatch statement, preferably line 740. Rewrite all historical rows as explicitly superseded cross-references without operative instructions; remove “Current binding order” and the obsolete benchmark/ruling hold. End the manifest quotation exactly after “it does not restate deploy mechanics.”

## MAJOR

### M1 — T3’s currency-scale factory violates two enforced architecture boundaries

- Plan: [line 515](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:515) puts `CompanyScopedCurrencyScaleResolverFactory` in `Shared/Infrastructure`, imports `App\Modules\Company\Domain\Company`, and injects that concrete Shared Infrastructure class into Treasury Application.
- Source: Shared Infrastructure may not depend on any module tier ([deptrac.yaml:75](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/deptrac.yaml:75)); Module Application may not depend on Shared Infrastructure ([deptrac.yaml:96](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/deptrac.yaml:96)). The baseline already contains two `SharedInfrastructure on ModuleDomain` violations ([deptrac.baseline.json:12](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/deptrac.baseline.json:12)), and any category or total increase fails the ratchet ([deptrac-ratchet.php:178](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tools/deptrac-ratchet.php:178), [line 208](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tools/deptrac-ratchet.php:208)).
- Failure scenario: implementing T3 literally adds both `SharedInfrastructure → ModuleDomain` and `ModuleApplication → SharedInfrastructure`; architecture CI fails.
- Minimum correction: place a factory/finder interface in `App\Shared\Contracts`, inject that interface into Treasury Application, and put the Company-model implementation in Company Infrastructure. Bind it in the provider. Add an exact red architecture test/command/lane and clarify that precision validation is the first policy operation after resolving the country scale—not literally the first statement.

### M2 — T4 lacks a full public signature and an executable red-first register

- Plan: `RepositoryTransferService::reverse(...)` remains an ellipsis at [line 575](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:575). Lines [579–586](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:579) provide filenames and prose cases, but no exact `Class::method`, first failing assertion, or named lane.
- Contract: the authoring prompt requires these fields per task at [prompt:19](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/CODEX-PROMPT-slice-plan-WCASH-1-custody-config-transfer-doc-2026-09-06.md:19). Convention 09 requires identifiable second-company, second-location and rerun tests ([convention 09:37](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/09-SECOND-OF-EVERYTHING.md:37), [review rule:81](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/09-SECOND-OF-EVERYTHING.md:81)).
- Failure scenario: implementers can choose incompatible reversal signatures and satisfy the prose with materially different assertions; reviewers cannot cite the required convention-09 evidence.
- Minimum correction: declare the complete `reverse(RepositoryTransferReversalIntent $intent): DocumentedRepositoryTransferResult` signature—or the intended alternative—and convert every reversal/frozen case into a table containing exact class/method, first assertion, command and lane. Identify the three convention-09 methods explicitly.

### M3 — T5 omits promised exact frontend files and most test contracts

- Plan: [lines 640–650](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:640) use categories such as “repository pages/forms/hooks/modals” and prose test requirements rather than exact files, methods, assertions, commands and lanes.
- Scope contract: the prompt explicitly promises `useTransferCash`, `RepositoryListPage` and `PaymentForm` at [prompt:12](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/CODEX-PROMPT-slice-plan-WCASH-1-custody-config-transfer-doc-2026-09-06.md:12).
- Source demonstrates the unresolved shadows:
  - [useTransferCash.ts:5](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/treasury/hooks/useTransferCash.ts:5)
  - [RepositoryListPage.tsx:23](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/treasury/RepositoryListPage.tsx:23)
  - [PaymentForm.tsx:60](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/treasury/PaymentForm.tsx:60)
  - [usePaymentRepositories.ts:7](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/treasury/hooks/usePaymentRepositories.ts:7)
  - [paymentRepositoryApi.ts:9](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/pos/api/paymentRepositoryApi.ts:9)
  - [POS payment.ts:27](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/types/payment.ts:27)
- Convention 11 classifies a local DTO shadow as MAJOR ([convention 11:44](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md:44)).
- Failure scenario: an implementer updates only obvious repository screens, leaving the transfer response and POS copies divergent. The formerly specified precision endpoint test has also disappeared from the normative register; [plan line 646](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:646) is only prose.
- Minimum correction: provide the complete HEAD-derived production-file census, including the three prompt-named files and every web/POS shadow. Add exact backend, Vitest/typecheck and POS regression test files, `Class::method`/test names, first assertions, commands and lanes—including the complete precision-422 endpoint snapshot test and convention-09 cases.

### M4 — T6 has test names but no assertions, exact commands/lanes, or rollback

- Plan: [lines 658–678](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:658) name tests, but not their first failing assertions. [Line 686](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:686) says to run broad groups without exact commands or lanes. The gate at [line 688](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:688) supplies no rollback.
- Failure scenario: concurrency/topology/Playwright work can be declared complete without demonstrating the intended invariant, and the preflight command omits the T6 integration and Playwright files.
- Minimum correction: add first failing assertions and exact commands/lanes for every T6 class and Playwright test. Add an explicit rollback such as “verification/handback only; preserve evidence, revert no production state,” plus the disposition for any T6-only fixture or handback artifact.

### M5 — The manifest census variable lacks required pass/fail greps

- Plan: [line 703](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:703) says WCASH retains day-one and POS VAT requirements but does not supply their exact pass/fail grep.
- Manifest: the per-slice variable expressly requires the censuses and pass/fail grep ([manifest:281](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:281)). Push 1 specifies the day-one and POS VAT commands ([manifest:81](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:81)); `tenants:run` exit codes cannot be trusted. The POS command’s clean marker and failure output are at [PosReceiptVatLegCensusCommand.php:336](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Console/Commands/PosReceiptVatLegCensusCommand.php:336) and [line 342](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Console/Commands/PosReceiptVatLegCensusCommand.php:342).
- Failure scenario: staging can promote after a swallowed child failure because the plan provides no deterministic POS-VAT verdict grep.
- Minimum correction: enumerate the exact Push-1/Push-4 census commands and pass/fail markers/counts for day-one and POS VAT in the variable row.

## MINOR

### m1 — Historical closure anchors were not re-anchored after rev 13

- Plan: [lines 39–43](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:39) point to 509, 407, 415, 664 and 411/413. The relevant current contracts are principally at 515, 415, 423 and 419/421; line 664 is now the concurrency-test heading. Lines 21 and 24 also cite nonexistent line 793.
- Failure scenario: reviewers follow an anchor to unrelated content and incorrectly conclude an accepted correction remains present.
- Minimum correction: re-anchor every changelog closure to rev-13 lines or replace closure line numbers with stable section anchors.

## Rejected false positives

- Amendment B itself is complete: the five P0 classifications are exactly `Money`, `Percent`, `Quantity`, `Geometry`, `Other`; `pos_tables.*` is Geometry; casts remain `decimal:3`; scale two is refusal-only; the detector predicate, output, hard stop, red test, command and lane are present.
- The `HasAttributes.php:1512–1515` citation is valid at HEAD and does show `BigDecimal` rounding with `RoundingMode::HALF_UP`.
- Q11–Q13 at plan lines 168–170 are verbatim matches for [owner rulings:152](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:152). The plan ships no Q12 device reason-code enum, Q11 drawer-session model, or Q13 historical alignment. Document-kind, initiator-kind and system-authority enums are not reason-code implementations.
- Omitting Q10 implementation is correct: the owner ruling expressly leaves hold lifecycle to a later slice ([owner rulings:158](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:158)).
- P0’s separately governed precision prerequisite is not WCASH scope creep. It remains separately packaged and gated.
- T1’s schemas, JSONB DTO, PHP enums, company-scoped keys and append-only contracts are complete enough to dispatch; T1/T2’s combined acceptance is explicitly defined.
- Convention 10 uses the required eight-column shape with ten decided rows. Convention 11 vocabulary agrees with the existing Repository glossary entry and assigns the two new rows to T1.
- Current-code state-machine claims check out: the scalar transfer service has no durable document, the movement port creates the paired legs, and the GL factory creates a draft that the movement service posts.
- The plan’s parent evidence SHA is not source drift: HEAD adds the rev-13 plan, while governed production files are unchanged.

## Preserve

- All Amendment-B detector, benchmark, citation and provisional-cast language.
- Exact P0-a/P0-b names, package separation, scale-two refusal, zero-detector promotion gate and forward-only rollback.
- T1/T2 schemas, enums, typed actor evidence and combined convention-09 acceptance.
- The established T3 operation identity, replay authorization order, paired-leg/GL invariants, precision ceiling and permanent document-writer cutover.
- Q11–Q13 verbatim text and explicit later-slice deferrals.
- Convention-10 matrix and convention-11 glossary commitments.
- Shared-manifest variables and copied §4 checklist, subject only to the census-grep and quotation corrections above.

## Owner decisions required

None.

VERDICT: CHANGES-REQUIRED