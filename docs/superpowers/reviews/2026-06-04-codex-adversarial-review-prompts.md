# Codex Adversarial-Review Prompts — Per-Branch Specs (2026-06-04)

Two standalone prompts for dedicated Codex sessions. All paths are ABSOLUTE so Codex can locate every file without guessing. Worktree root: `/Users/houssamr/Projects/syneriva/apps/erp.branch-tax-id` (branch `feat/branch-tax-id-spec`). Each prompt instructs Codex to SAVE its review to a file (not print inline).

---

## Prompt 1 — P0 (branch tax-ID spec)

```
Run an ADVERSARIAL design review. Repo/worktree root: /Users/houssamr/Projects/syneriva/apps/erp.branch-tax-id (Laravel ERP "AutoERP", branch feat/branch-tax-id-spec). cd there first.

REVIEW THIS SPEC:
  /Users/houssamr/Projects/syneriva/apps/erp.branch-tax-id/docs/superpowers/specs/2026-06-04-branch-tax-id-design.md

VERIFY IT AGAINST (read these AND the actual code — do not trust the spec):
  /Users/houssamr/Projects/syneriva/apps/erp.branch-tax-id/docs/superpowers/research/2026-06-04-branch-tax-id-01-model-and-flow.md
  /Users/houssamr/Projects/syneriva/apps/erp.branch-tax-id/docs/superpowers/research/2026-06-04-branch-tax-id-02-canonical-payload-impact.md
  /Users/houssamr/Projects/syneriva/apps/erp.branch-tax-id/docs/superpowers/research/2026-06-04-branch-tax-id-03-compliance-requirements.md
  /Users/houssamr/Projects/syneriva/apps/erp.branch-tax-id/docs/superpowers/research/2026-06-04-branch-tax-id-04-numbering-series.md
  /Users/houssamr/Projects/syneriva/apps/erp.branch-tax-id/docs/superpowers/research/2026-06-04-branch-tax-id-05-deep-research-multicountry.md

POSTURE: Try to BREAK the spec. Default to skepticism; verify every claim against the codebase with file:line.

ATTACK THESE LOAD-BEARING CLAIMS:
1. THE BIG ONE — spec section 7 claims moving seller.tax_number from company to branch is a VALUE-source change only, needing NO fiscal payload version bump and NO golden-fixture regen (directly contradicting research doc 02's "HIGH RISK / versioned event"). Prove or disprove. Check:
   - apps/pos/src/lib/fiscal/FiscalEventCanonicalEncoder.ts (JCS encode + hash — does it hash the whole object incl. seller?)
   - apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php validateSeller (~lines 1466-1497) + per-country tax_number regexes (~150-156): would any branch value the device might author (FR 9- or 14-digit SIRET/SIREN, TN compact matricule, etc.) be REJECTED?
   - apps/api/tests/Fixtures/Fiscal/sale-receipt-golden/** and apps/api/tests/Fixtures/Fiscal/v3-golden-hashes/** — does changing the SOURCE (not the bytes) actually leave fixtures valid? Is the "no regen" claim safe, or is a fixture pinned to a company value?
   - apps/pos/src/stores/paymentStore.ts lines 543-550 and 654, and apps/pos/src/lib/fiscal/payloads/SaleReceiptPayload.ts buildSellerBlock — confirm the seller value is just sourced, not schema-shaped.
   Is the "no version bump" conclusion SAFE, or is there a hidden break (a country where the branch value fails the regex, or a fixture pinned to a company value)?
2. The single TaxIdentityResolver + 5 server output sites. Verify each exists at the cited location and that the resolver can actually reach the correct location_id there:
   - apps/api/resources/views/pos/receipt.blade.php lines 328-329 (+ ReceiptController passing location)
   - apps/api/app/Modules/Document/Application/Services/FacturXService.php lines 110-145 (resolve from documents.location_id)
   - apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php lines 524-558 (the null-SIRET magic-attribute bug — is the fix correct?)
   - apps/api/app/Modules/Taxation/Application/Services/TEJExportService.php line 79 and apps/api/app/Modules/Taxation/Application/Services/CertificatePDFService.php line 74 — is there even a single location to resolve here, or does branch resolution not apply (export-level)? Flag if the spec overclaims.
3. The device path: spec says terminal.location is ALREADY synced via TerminalResource so Phase 2 just adds fields + flips paymentStore.ts line 545. Verify apps/api/app/Modules/POS/Presentation/Resources/TerminalResource.php sends location, apps/pos/src/stores/terminalStore.ts stores it, and terminal.location is in scope at paymentStore.ts lines 545/654 and apps/pos/src/lib/fiscal/payloads/AccountChargePayload.ts line 256.
4. "Validate-when-present, never required" + the country-mode config: any country where "never required" is actually NON-COMPLIANT (e.g. FR NF525 multi-branch)? Any inconsistency with company.tax_id having no validation today?

DELIVERABLE: SAVE the review to this FILE (do not print it inline):
  /Users/houssamr/Projects/syneriva/apps/erp.branch-tax-id/docs/superpowers/reviews/2026-06-04-branch-tax-id-P0-codex-adversarial-review.md
Classify every finding BLOCKER / MAJOR / MINOR / NIT with file:line. End with a verdict: APPROVE / APPROVE-WITH-EDITS / NEEDS-REWORK. Then print only: the file path, the verdict, and finding counts by severity.
```

---

## Prompt 2 — Program (P1–P5 spec)

```
Run an ADVERSARIAL design review. Repo/worktree root: /Users/houssamr/Projects/syneriva/apps/erp.branch-tax-id (Laravel ERP "AutoERP", branch feat/branch-tax-id-spec). cd there first.

REVIEW THIS SPEC:
  /Users/houssamr/Projects/syneriva/apps/erp.branch-tax-id/docs/superpowers/specs/2026-06-04-per-branch-program-design.md

VERIFY IT AGAINST (read these AND the actual code — do not trust the spec):
  /Users/houssamr/Projects/syneriva/apps/erp.branch-tax-id/docs/superpowers/research/2026-06-04-branch-tax-id-06-accounting-module-current-state.md
  /Users/houssamr/Projects/syneriva/apps/erp.branch-tax-id/docs/superpowers/research/2026-06-04-branch-tax-id-07-multibranch-accounting-modeling.md
  /Users/houssamr/Projects/syneriva/apps/erp.branch-tax-id/docs/superpowers/research/2026-06-04-branch-tax-id-08-cross-module-impact-and-program-scope.md
  /Users/houssamr/Projects/syneriva/apps/erp.branch-tax-id/docs/superpowers/research/2026-06-04-branch-tax-id-09a-subaccount-practice.md
  /Users/houssamr/Projects/syneriva/apps/erp.branch-tax-id/docs/superpowers/research/2026-06-04-branch-tax-id-09b-subaccount-mechanics-and-design.md
  /Users/houssamr/Projects/syneriva/apps/erp.branch-tax-id/docs/superpowers/research/2026-06-04-branch-tax-id-09c-per-branch-valuation-event-sourcing.md

ALSO cross-check against the P0 spec for contradictions:
  /Users/houssamr/Projects/syneriva/apps/erp.branch-tax-id/docs/superpowers/specs/2026-06-04-branch-tax-id-design.md

POSTURE: Try to BREAK the spec. Default to skepticism; verify every claim against the codebase with file:line.

ATTACK THESE LOAD-BEARING CLAIMS:
1. THE SPINE — "journal_entries.location_id exists but is DEAD (not in fillable, written by zero posting sites, read by zero reports)" and "the GL hash EXCLUDES location_id so adopting it is non-breaking." Verify:
   - apps/api/app/Modules/Accounting/Domain/JournalEntry.php fillable
   - apps/api/app/Modules/Accounting/Application/Services/AccountingService.php and apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php (every JournalEntry::create — does any write location_id?)
   - the GL hash service (GeneralLedgerHashService) — confirm location_id is NOT hashed.
   - the GL report services — confirm none read location_id today.
2. THE HYBRID SUB-ACCOUNTS (Decision B / doc 09b). Stress the three constraints:
   - unique(company_id, system_purpose): confirm; confirm branch children cannot share a purpose; is "purpose stays on parent + new accounts.location_id + findByPurposeForLocation()" actually workable across ALL ~40 purpose-resolution call sites?
   - THE ROLL-UP TRAP: apps/api/app/Modules/Accounting/Domain/Services/AccountHierarchyService.php calculateSubtotals (~lines 160-177) overwrites a parent's balance with the SUM of children. Is the mitigation ("post only to children, never the parent") COMPLETE? What breaks for RETROACTIVITY — a company that already has postings on the parent when the toggle is later enabled? Any OTHER report path (general ledger detail, balance sheet, exports) that breaks?
   - selective expansion (class 6/7 + liaison only; VAT/balance-sheet single): is this internally consistent, and does it match what TN/FR actually allow (doc 09a)?
3. WAC + per-branch valuation (doc 09c): is "WAC stays company-wide" preserved? Is the valuation MVP (stock_levels.qty@location × products.cost_price) actually correct? Are the event-sourcing gaps correctly characterized — POS movement null-cost at apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php issueStock (~line 894) and COGS dropping location_id (createCOGSEntry in GeneralLedgerService)? Verify.
4. PHASING & SCOPE: is P2-before-P2.5 sound? Is the P1 OwnerReportScope cleanup correctly scoped (apps/api/app/Modules/.../OwnerReportScope.php mixes parent_company_id + allowed_location_ids)? Did the cross-module survey (doc 08) MISS any module or hidden cross-module dependency? Any scope creep or under-scoping per phase?
5. INTERNAL CONTRADICTIONS between this program spec and the P0 tax-ID spec. Any "non-breaking" claim that isn't. Testability of each phase.

DELIVERABLE: SAVE the review to this FILE (do not print it inline):
  /Users/houssamr/Projects/syneriva/apps/erp.branch-tax-id/docs/superpowers/reviews/2026-06-04-per-branch-program-codex-adversarial-review.md
Classify every finding BLOCKER / MAJOR / MINOR / NIT with file:line. End with a verdict: APPROVE / APPROVE-WITH-EDITS / NEEDS-REWORK. Then print only: the file path, the verdict, and finding counts by severity.
```
