# Executive Summary

v3 is substantially closer, but I cannot approve it for plan amendment yet. The owner decisions are honored, the 27-key Candidate C-v3 contract is coherent, and most round-2 findings are closed on paper. The remaining defects are in the fixes around the contract: Pass 2A still has a self-contradictory old-shape test/grep gate, the VAT partition validator rule is not implementable enough to prevent decimal/rounding drift, and the immediate Tunisia tax-number gate is described as a manual owner item rather than an enforceable Pass 2A stop. These are material implementation-plan defects, not architectural blockers.

# Round-2 Closure Verification

| Finding | Verdict | Evidence |
|---|---|---|
| B2 ZATCA XPaths missing | CLOSED | v3 §4 supplies a full XPath table for ID/UUID/date/type/currency, PIH/ICV, supplier/customer, monetary totals, tax subtotals, invoice lines, payment means, and allowance charge (`docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v3.md:145-185`). |
| B3 registry version-check consequence | CLOSED | v3 keeps D4 rewrite-in-place/no version bump (`:15`) and makes Pass 2A atomic: registry-facing parser/validator tests and fixtures are regenerated in the same commit (`:242-271`, especially `:251-260`). This is enough because owner D4 forbids a version bump. |
| P1.4 D16 grep too narrow | CLOSED | v3 §5 expands the guard beyond direct imports to shared-contract imports, container resolution, and Eloquent cross-module reads (`:197-201`). |
| P1.6 `tax_category_code` rationale | PARTIALLY-CLOSED | v3 §6 now gives the line-vs-breakdown rationale and states the partition rule (`:205-216`). It is only partially closed because the implementation rule is still underspecified; see N-16. |
| P1.7 B2B Standard Invoice gap | CLOSED | D7 makes Tauri POS B2C Simplified only and leaves the web B2B flow untouched (`:18`); D8 defers the B2B/ZATCA Tax Invoice path (`:19`); Candidate C-v3 has 27 keys and explicitly drops `invoice_subtype_code` (`:135`). |
| P1.8 parse-failure UX | CLOSED | v3 §13 explicitly accepts the transition workflow: operators hand-craft a full 27-key corrected payload via `fiscal:resolve-quarantine`, and a Phase 2 prep task will add prefill tooling (`:489-503`). This is not pleasant, but the transition risk is now named. |
| P1.9 Pass 2A/2B split-brain | PARTIALLY-CLOSED | v3 drops the feature flag and defines two sequential dev-branch commits (`:238-240`, `:273-282`). It still leaves a Pass 2A old-shape test contradiction; see N-11. |
| P1.11 Phase-2 cleanup unscheduled | CLOSED | v3 §9 adds the exact Phase-2 mirror-column audit/drop roadmap entry text and says it will be appended in Pass 2A (`:319-325`). |
| N-1 feature flag rollout contradiction | CLOSED | D6 drops the flag (`:17`), and §8 repeats there is no flag, production gate, or device/server sync mechanism (`:238-240`). |
| N-3 Task 8 immutability vs shape rewrite | CLOSED | v3 states fresh fixtures and deletion of old vectors in the same Pass 2A commit (`:251`) and atomic verification (`:264-271`), with dev-branch-only safety and no production deployment until end-of-Phase-1 (`:286-288`). |
| N-4 receipt_uuid B2B gap | CLOSED | D7/D8 remove the Standard/B2B switch from this payload (`:18-19`), and the excluded list says `invoice_subtype_code` is not carried until ZATCA implementation (`:27-29`). |
| N-7 Task 14-19 test inventory | PARTIALLY-CLOSED | v3 names the major Fiscal test helpers and adds a grep gate (`:252-260`). Two named paths are wrong in the current tree: `FiscalPayloadConstraintValidatorTest.php` is absent, and `StrictCanonicalParserTest.php` is under `apps/api/tests/Unit/Fiscal/StrictCanonicalParserTest.php`, not `Feature/Fiscal`. The requested old-shape helpers do exist in `OutboxIngestorTest.php:821-840`, `ParseFailureResumeTest.php:679-698`, `PosCoreReceiptProjectionTest.php:927-938`, and `FiscalEventIngestionEndpointTest.php:464-483`. |
| N-10 device flag sync | CLOSED | Same as N-1: no feature flag remains (`:17`, `:238-240`). |
| N-2/P3 large-receipt fixture | CLOSED | v3 §10 adds F-15 with 50 lines, 10 payments, 8 VAT rows, full buyer/reference data, parser timing, JSONB insert, and projection dispatch assertions (`:362-367`). |
| N-5 per-country tax patterns | PARTIALLY-CLOSED | v3 §7 adds FR/TN/SA/DE/IT seller and buyer regexes (`:222-234`). The Tunisia pattern is explicitly a placeholder, and its enforcement gate is not wired into Pass 2A verification; see N-14. |
| N-6 adapter scope explosion | CLOSED | v3 §14 states NF525 is the only existing adapter updated in Pass 2A, while ZATCA, DSFinV-K, IT RT, and Tunisia templates are deferred (`:506-518`). |
| N-8 GTIN canonical-only utility | CLOSED | v3 §9 introduces `CanonicalPayloadReader` in Fiscal Application Services and lists line/payment/VAT/buyer/seller reader methods plus NF525 and future adapters as consumers (`:327-340`). |
| N-9 concurrent receipt serialization | PARTIALLY-CLOSED | v3 §11 adds the one-in-flight-per-terminal rule, retry-on-`ConcurrentChainAdvanceError`, same `source_event_id`, and 3-retry escalation (`:420-434`). It does not define the mutex primitive or release semantics; see N-15. |

# New Defects

## P1

### N-11 Pass 2A Old-Shape Pin Contradicts the Grep Gate

Severity: P1.

What is wrong: v3 says Pass 2A's pre-commit gate must grep `currency_scale.*discount_total.*lines.*payment_lines` across the worktree and return zero old-shape matches after Pass 2A (`docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v3.md:260`). The same section then says Pass 2A includes a temporary integration test pinning `createOfflineReceipt` output against the old 10-key shape, deleted only in Pass 2B (`:288-289`). That is not just documentation of a known legacy source path; it asks the post-Pass-2A tree to both contain a test proving old-shape emission and contain zero old-shape signature matches. The current old path is real: `receiptService.ts` still computes the legacy v3 hash via `buildCanonicalPayload` (`apps/pos/src/lib/offline/receiptService.ts:210-233`) inside `createOfflineReceipt()` (`:300-395`).

Fix: Choose one Pass 2A invariant. Either (a) remove the temporary old-shape integration test and make Pass 2A leave no source/test old-shape signature, or (b) scope the grep gate to canonical-contract tests/fixtures and explicitly exempt the one temporary legacy test by path, with a Pass 2B deletion gate that fails if it remains.

### N-14 Tunisia Tax-Number Placeholder Has No Enforced Stop

Severity: P1.

What is wrong: v3 makes Tunisia an immediate target in D9 (`docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v3.md:20`) and adds a TN regex while explicitly calling it a placeholder pending accountant verification (`:227`, `:234`). §17 says the owner must confirm it before Pass 2A ships (`:540-543`), but §8's Pass 2A verification list does not include this gate (`:264-271`). As written, an implementer can satisfy every listed Pass 2A verification command and still ship a validator that rejects valid Tunisian matricules or accepts invalid ones in the immediate market.

Fix: Add the TN regex confirmation to the Pass 2A pre-commit/pre-merge checklist in §8, with a named evidence artifact or CI/manual gate. If confirmation is not available, do not enforce the TN regex in Pass 2A; accept `^[A-Z0-9/ -]+$` or country-code-only validation with a TODO test that fails once the accountant-approved pattern is added.

### N-16 VAT Partition Rule Is Not Implementable Enough

Severity: P1.

What is wrong: v3 §6 says the validator must assert that `vat_breakdown` is the partition of `line_items` by `(vat_rate, tax_category_code)` and that each breakdown row has totaled amounts (`docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v3.md:212-216`). It does not specify the aggregation algorithm: whether totals use `line_subtotal` + `line_vat`, whether `gross_amount` must equal net+VAT, how transaction-level discounts are allocated before comparison, or whether decimal equality uses `bccomp` at `currency_scale` after `bcformat`. Existing validator code currently only validates the 10-key monetary/list shape (`apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:143-160`), and existing projector code normalizes with `bcadd(..., self::SCALE)` rather than floats (`apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:799-805`), so the plan needs to lock the exact decimal method before implementation.

Fix: Add a concrete validator algorithm: group lines by `(vat_rate, tax_category_code)`, sum `line_subtotal` and `line_vat` with BCMath at `currency_scale`, derive gross as net+VAT, compare to each matching `vat_breakdown` row with `bccomp(..., currency_scale)`, and define how invoice-level discounts affect line/breakdown totals. Add negative tests for duplicate partition rows, missing partition rows, one-cent rounding drift, and mixed `0%` categories.

## P2

### N-12 Task 30 Refactor Scope Is Under-Itemized

Severity: P2.

What is wrong: v3 says Pass 2A absorbs the Task 30 deferred P3-1 closure and updates `Nf525DataProvider` to read the new canonical shape (`docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v3.md:248`, `:512`). It also introduces `CanonicalPayloadReader` for NF525 (`:327-338`) and estimates all Pass 2A work at ~3-4K LOC across 20+ files (`:524`). The existing provider is not a small monetary override: `mapSaleReceipt()` still maps lines, VAT, and payments from `Receipt` relations (`apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php:581-606`), void/return mappings return empty line/VAT/payment arrays (`:639-706`), and its own comment says canonical payload does not carry line-level data (`:724-730`). Candidate C-v3 now does carry `line_items`, `payments`, `vat_breakdown`, `original_receipt_reference`, buyer, and seller (`docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v3.md:53-107`). The plan names the destination but not the required line-level rewrite or tests.

Fix: Add a Pass 2A Task 30 sub-inventory: refactor `mapSaleReceipt`, `mapVoidedReceipt`, `mapReturnReceipt`, `mapLine`, `mapPayment`, and `mapVatDetail` to use `CanonicalPayloadReader` for fiscal-event-backed receipts; keep projection fallback only for `fiscal_event_id IS NULL`; add NF525 tests for canonical-only `gtin`, `tax_category_code`, foreign currency payment fields, and `original_receipt_reference`.

### N-15 Concurrent-Receipt Mutex Is a Rule, Not a Design

Severity: P2.

What is wrong: v3 §11 states "one in-flight receipt seal per terminal" and says `paymentStore.createReceiptLocalFirst()` acquires a per-terminal mutex before the SQLite transaction, retries `ConcurrentChainAdvanceError` three times, then surfaces `FiscalChainContentionError` (`docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v3.md:430-434`). It does not define the primitive, release path, or key. The existing engine only throws `ConcurrentChainAdvanceError` on the sequence UNIQUE race (`apps/pos/src/lib/fiscal/FiscalEventEngine.ts:144-155`, `:399-449`); it does not own retries or mutexing. Without a primitive and release semantics, two implementers can build incompatible behavior around transaction rollback, idempotency, and UI retry.

Fix: Specify the mutex as an in-process per-`terminal_id` Promise queue in `paymentStore` or a Tauri-side lock if multiple webviews/processes can submit checkout concurrently. Define `try/finally` release after commit/rollback, retry delay/backoff, and whether the key is exactly `tenant_id:terminal_id`. Add tests for lock release on success, validation failure before transaction, SQLite rollback, and `ConcurrentChainAdvanceError` retry exhaustion.

## Investigated But Not Findings

### N-13 CanonicalPayloadReader Service Design

Not a finding. v3 names the module and path (`apps/api/app/Modules/Fiscal/Application/Services/CanonicalPayloadReader.php`) and gives typed iterable methods (`docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v3.md:327-335`). It interacts with `StrictCanonicalParser` indirectly through the existing SoT rule: server JSONB payload is derived from verified canonical bytes by the strict parser, never accepted independently (`docs/superpowers/research/2026-05-14-offline-first-fiscal-source-of-truth-v3.md:35-38`, `:94-99`). Memory/perf is acceptable for the planned fixture scale because F-15 explicitly requires 50 lines/10 payments/8 VAT rows and parser acceptance under 100ms (`docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v3.md:362-365`). N-12 covers the missing NF525 refactor inventory, not the reader placement.

### N-17 Pass 2 Round Count / Task 28 Absorption

Not a finding as phrased. v3 does not put Task 28 in Pass 2A; it puts `/pos/receipts/sync` retirement in Pass 2B (`docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v3.md:273-282`). Spec v7 already contains the named Task 28 backend/POS test inventory (`docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md:629-645`, `:778-785`). The 4-6 review-round estimate (`docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v3.md:527-528`) is optimistic but not a correctness defect.

### N-18 Buyer Block Presence in B2C Phase 1

Not a finding. D7/D8 explicitly make the Tauri POS B2C-only and defer the B2B/ZATCA Tax Invoice path (`docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v3.md:18-19`). The buyer block is nullable in Candidate C-v3 (`:45`, `:113-120`) and v3 §5 says buyer data is captured only from POS-local mirror data or ad-hoc operator input at append time, with no projection enrichment (`:191-196`). Spec v7 also places customer-facing customer mirror/search/attach work in Phase 2+ (`docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md:68-70`). Therefore Phase 1 can legitimately emit `buyer: null`; future B2C loyalty attachment needs tests, but v3 does not claim to implement that flow now.

# Verdict

Request changes. v3 is directionally sound and does not need architectural rollback, but Pass 2A should not be dispatched until the old-shape test/grep contradiction, the TN regex gate, and the VAT partition validator algorithm are fixed in the synthesis.

VERDICT: REQUEST-CHANGES
