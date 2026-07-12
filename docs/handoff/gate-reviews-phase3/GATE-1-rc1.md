# Treasury Phase 3 — Gate 1 rc1 Adversarial Review

## Scope and diff basis

The branch base is the sanctioned Phase-3 base `f1d6c1d30`. During the review, `origin/dev` was 20 commits ahead after the treasury UI-gaps and bank-directory merges. The literal two-dot `origin/dev..HEAD` range therefore contained phantom deletions for files this branch never authored. The reviewer evaluated the authored work using the merge-base/three-dot diff (`18 files, +1324/-2`) and checked the mandatory transfer-port diff under both forms.

## Findings

No BLOCKER, HIGH, MEDIUM, or LOW correctness findings.

1. **Port and perimeter — APPROVE.** `TreasuryMovementService` is byte-untouched. The fiscal perimeter and named interlocks (`RepositoryDetailPage`, `ExpenseDetailPage`, `BankPicker`, banks, and `TopBar`) are untouched.
2. **A1 index — APPROVE.** The tenant migration uses the exact status-scoped predicate `source_type='treasury_transfer' AND status='posted'` required by the L1-1 remediation.
3. **A2 draft JE factory — APPROVE.** It creates Dr destination / Cr source lines in Draft status, requires an enclosing transaction, and never calls `postEntryNow`.
4. **A3 transfer service — APPROVE.** Both repositories are freeze-checked in the service; amounts are normalized once with `bcformatStrict` at the source-repository currency scale; virtual/inactive/missing-account cases are rejected before writes; the outer transaction and compensating delete prevent orphan drafts; returned `journal_entry_id` comes from the persisted out leg.
5. **A4 HTTP and permission contract — APPROVE.** Middleware, validation, canonical error handling, endpoint response, permission catalogs and every `treasury.adjust` role bundle match the binding contract. Backend translations are en/fr only as required.
6. **A5 reconcile pin — APPROVE.** The mixed cross-GL, same-GL, and replay scenario remains reconcile-green.
7. **Amendment A-1 — APPROVE.** The sanctioned sequential race-shape substitution is implemented and recorded with justification in the progress file.
8. **Money and Spatie checks — APPROVE.** No float money handling and no company-id-as-Spatie-team misuse exist in Wave A.

## Non-blocking notes

- Future gate reviews must use the previous gate tag or merge-base/three-dot authored range when `origin/dev` advances, avoiding phantom deletions in two-dot output.
- `transfer_completed` (instruments) and `transfer_recorded` (repositories) coexist in backend translations with distinct meanings; there is no collision.

VERDICT: APPROVE
