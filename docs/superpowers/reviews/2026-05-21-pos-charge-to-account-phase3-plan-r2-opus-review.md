# Phase 3 Stage B Implementation Plan R2 Opus Review

Reviewed artifact: `docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md`

Prior Opus review: `docs/superpowers/reviews/2026-05-21-pos-charge-to-account-phase3-plan-opus-review.md`

R2 Codex review: `docs/superpowers/reviews/2026-05-21-pos-charge-to-account-phase3-plan-r2-codex-review.md`

Locked spec: `docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md`

Verdict: REQUEST-CHANGES

## Findings

### 1. Treasury bridge still makes Partner balance refresh optional

The prior five Opus findings are closed, but the R2 plan still leaves one locked sync/reconciliation outcome optional. Task 8 says the bridge should "refresh Partner balance through existing accounting service if current patterns support it" (`docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md:917`-`924`). The locked spec is not conditional here: for B2C with Treasury active, "Partner balance refresh/reconciliation runs" (`docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:416`-`420`).

This is not a theoretical missing primitive. Current accounting code already injects `PartnerBalanceService` into `GeneralLedgerService` (`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:36`-`41`) and existing GL paths refresh cached partner balances after partner-affecting entries (`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:116`-`117`, `:249`-`:252`). `PartnerBalanceService::refreshPartnerBalance()` is the tenant/company-scoped service that updates receivable/credit/payable balances and emits `PartnerBalanceUpdated` (`apps/api/app/Modules/Accounting/Application/Services/PartnerBalanceService.php:286`-`337`).

Leaving this as "if current patterns support it" is a silent-downgrade risk: the AR journal can post successfully while the Partner cached receivable balance remains stale, which directly undermines the customer mirror and future offline credit decisions.

Concrete fix: make balance refresh mandatory in Task 7/8. Prefer `GeneralLedgerService::createPOSChargeEntry()` refreshing the partner balance after the AR journal write, matching existing GL methods. Add a test that the discounted or normal account charge updates the Partner receivable balance and/or emits `PartnerBalanceUpdated`; refresh failure should fail loud as part of the Treasury bridge projection, not be silently skipped.

## Prior Findings

The R2 plan resolves the five prior Opus findings:

- Validator/parser matrix is now explicit. Task 2 names tests for extra top-level keys, missing required top-level/nested keys, malformed money, malformed VAT partition, synced/pending customer variants, stale/fresh mirror variants, credit-limit present/absent, discount present/absent, nullable/populated `buyer` and `references`, nullable `non_collected_subtype`, and training-vs-production behavior (`docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md:293`-`314`).
- Device rules and rejected-flow closure are now covered. Task 4 adds `ambiguous_alias` before authoring (`:535`-`:547`), and Task 10 adds insufficient-credit and hard-stale blocked-flow tests proving no `FiscalEventEngine.append()` and no `/pos/sync/fiscal-events` call (`:1051`-`:1094`).
- AR command DTO now carries canonical VAT/line evidence. Task 7 adds `vatBreakdown` and `lineVatSummary`, explicitly copied from sealed canonical payload rather than live product/tax tables (`:792`-`:840`), and Task 8 copies `vat_breakdown` / `line_items` through `CanonicalPayloadReader::forAccountCharge($event)` (`:917`-`:923`).
- POS-core D16 grep guard is broadened to Treasury, Accounting, Document, Partner, Customer, Contact, B2B, and Sales module families (`:704`-`:715`).
- Task 6 now makes the PG merge-gate update mandatory for `AccountChargeProjectionTest` because the task adds a `jsonb` projection table and fiscal feature projection test (`:752`-`:756`).

## Standing Pattern Sweep

- D16 bounded-module seam: still sound. POS-core remains `requiresModule() === null`; Treasury and Document/Sales effects are module-gated bridges. The `Sales` token is still the correct activation token for Document/Sales facture work because `Vertical::defaultModules()` exposes `Sales`, not `Document`.
- D8 Tax Invoice boundary: still sound. POS authors `ACCOUNT_CHARGE`; Document/Sales creates a draft only and must not post or emit a Tax Invoice fiscal event (`:951`-`:1009`).
- Cross-tenant FK safety: still covered for Treasury customer and Document partner lookups (`:890`-`:924`, `:963`-`:990`), with fail-loud cross-company tests.
- AR GL correctness: discounted `SalesDiscount` line, AR/revenue/VAT lines, no `Payment` / `ReceiptPayment`, and no `createPOSPaymentEntry()` remain present (`:792`-`:858`).
- Dead-path rebuild: new DTOs, projectors, providers, tests, chokepoint script, and CI wiring all have live registration or execution steps.
- Contract drift: TS/PHP registry parity, golden canonical bytes, exact-key parser tests, and chokepoint sentinels remain required.
- Skips: no class-level skips or new skip-citation risk were found in the plan.
- Constructor injection: no production `app()`, `App::make()`, or `resolve()` path is introduced by the plan.

## Verification Commands

Commands run from `/Users/houssamr/Projects/syneriva/apps/erp.phase-3`:

- `git status --short && git branch --show-current`
- `nl -ba docs/superpowers/reviews/2026-05-21-pos-charge-to-account-phase3-plan-opus-review.md`
- `nl -ba docs/superpowers/reviews/2026-05-21-pos-charge-to-account-phase3-plan-r2-codex-review.md`
- `rg -n "extra keys|missing top-level|nested|malformed money|VAT partition|synced|pending|stale|fresh|credit-limit|discount present|nullable.*buyer|references|non_collected_subtype|training|production|ambiguous|insufficient|FiscalEventEngine\\.append|vatBreakdown|lineVatSummary|Modules\\\\Customer|Modules\\\\Contact|Modules\\\\B2B|Modules\\\\Sales|PG merge|AccountChargeProjectionTest|constructor injection|app\\(\\)|App::make|resolve\\(|skip|@group|class-level" docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md`
- `nl -ba docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md | sed -n '283,350p'`
- `nl -ba docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md | sed -n '532,548p'`
- `nl -ba docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md | sed -n '700,760p'`
- `nl -ba docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md | sed -n '792,928p'`
- `nl -ba docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md | sed -n '951,1012p'`
- `nl -ba docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md | sed -n '1048,1098p'`
- `rg -n "TBD|TODO|placeholder|\\.\\.\\.|similar to|appropriate|Use the actual|if .* before implementation|maybe|where needed|current patterns support|class-level|skip\\(|markTestSkipped|@group|@doesNotPerformAssertions" docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md`
- `nl -ba docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md | sed -n '360,422p'`
- `nl -ba docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md | sed -n '256,285p'`
- `rg -n "PartnerBalance|balance refresh|receivable_balance|credit_balance|refresh.*Partner|Partner balance|reconciliation" apps/api/app/Modules/Treasury apps/api/app/Modules/Accounting apps/api/app/Modules/Partner apps/api/app/Modules/Document -g '*.php'`
- `nl -ba apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php | sed -n '1,55p;104,125p;236,256p'`
- `nl -ba apps/api/app/Modules/Accounting/Application/Services/PartnerBalanceService.php | sed -n '286,337p'`

No runtime test suite was run; this was a plan/document review.
