<!-- fiscal-pos-reviewer (Claude), merge-gate round 2 (delta G1.1–G1.6), lane fix/h1-a1-buyer-block, 2026-08-30 -->

# Delta re-check register (round 2) — lane H1-a1

| | |
|---|---|
| **Branch** | `fix/h1-a1-buyer-block` (`/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/h1-a1-buyer`) |
| **Range** | `ed74968cd..fe8cbd420` — 6 commits G1.1–G1.6, 12 files (+449/−21) |
| **Method** | `git diff ed74968cd..fe8cbd420` + file reads. Confirmed every reviewed file in the working tree is byte-identical to `fe8cbd420` (`git diff fe8cbd420 -- <file>` empty for all nine), so the reads are valid despite the in-flight merge noted below. No PG/Vitest runs. |

## Regressions found: none.

## Verified — all five conditions resolved

**(1) `buyer.name` version gate — correct on both sides, shared constant on the server.**
`FiscalPayloadConstraintValidator.php:2343-2345` now computes `$requiresNonEmptyBuyerName = $saleReceiptEventVersion !== null && $saleReceiptEventVersion >= self::SALE_RECEIPT_POST_DISCOUNT_BASE_VERSION` — the **shared const** (`:433`), not a literal — and the same flag drives both the nullable-set membership (`:2347-2351`) and the strict check (`:2362`). ACCOUNT_CHARGE (`:1907`, no version) keeps `name` in the nullable set, i.e. byte-for-byte pre-M4. Device twin `FiscalEventEngine.ts:2779-2782` mirrors it, and the previously bare literal at the `customer_id` gate is now the same named const (`:1453`, `:2782`, `:2800`) with a docblock pointing at the PHP const. Tests, both sides, all new: server `test_v1_buyer_with_null_name_still_validates` (v1), `test_v4_buyer_with_null_name_still_validates` (v4), `test_v5_buyer_rejects_blank_name` (`'   '` at v5), plus `test_v1_buyer_with_empty_string_name_is_still_rejected` which pins that the *pre-existing* empty-string rule is untouched for legacy; device grandfather tests at v3 and v4 and an explicit-null reject at v5. The re-verification regression I raised (`VerifyEventChainCommand` / `ParseFailureResolutionService` re-parsing stored v1–v4 bytes) is closed.

**(2) `ContactResolverInterface` — clean seam, and the two schema preconditions hold.**
New `apps/api/app/Shared/Contracts/ContactResolverInterface.php:29-33`; implemented by `ContactService.php:22-33` with `withTrashed()->where('tenant_id',…)->where('company_id',…)->whereKey(…)->value('id')`, mirroring `PartnerService::resolveScopedPartnerId` exactly. Bound at `AppServiceProvider.php:108`. I verified the two things that would have turned this into a new hard-failure path: `Contact` **does** use `SoftDeletes` (`Contact.php:53`) so `withTrashed()` is legal, and `contacts` **does** carry `tenant_id`, `company_id` and `softDeletes()` (`2026_03_10_100001_create_contacts_table.php:18-19,32`) so neither `where` hits a missing column. `ContactService` has no constructor, so it is container-resolvable in a worker; there is still **no** `addGlobalScope` anywhere in `apps/api/app`, so no request-bound context is relied on (rule 20). Both sinks are guarded: the insert row (`PosCoreReceiptProjection.php:397`) and `SaleEarnContext(contactId: $contactId)` (`:1754`, threaded as a new parameter at `:1744`; `$view` is still used at `:1767` for `lineItems()`, so no dead parameter). Rule 6 holds — the projector imports only `App\Shared\Contracts\ContactResolverInterface` (`:56`), no `App\Modules\Contact\` import, so the D16 forbidden-pattern set is still satisfied. D16 pin extended with four assertions including a negative `str_contains` guard against the raw write (`PosCoreReceiptProjectionD16Test.php:159-186`). Three new projection tests, all clearing `CompanyContext` before `apply()`, all asserting data meaning against real rows: F-15-shaped `'contact-f15-001'` **at v5** → null FK + snapshot lands + `capturedEarn->contactId` null; cross-company uuid contact → null FK; in-scope uuid contact → FK kept + loyalty context set. `seedContact()` inserts through `DB::table('contacts')` rather than importing the Contact model into the fiscal test surface — a nice touch.

**(3) Docblock is now truthful.** `PosCoreReceiptProjection.php:103-116` states the exact scope of the defensive nulling (validator-**accepted** values only: legacy non-uuid `customer_id` at v ≤ 4, an in-syntax uuid that resolves to nothing in scope, and any `contact_id` since the validator accepts an arbitrary non-empty string for it at every version — accurate against `:2337`) and spells out the validator-**rejected** outcome with the anchors I cited (`OutboxIngestor.php:793-805`, `:987-990`): stored as `CanonicalParseFailure`, NULL payload, projections suppressed, no receipt/GL/stock until `ParseFailureResolutionService` repair, chain intact. That is the lens-4 answer, now written where the next reader will find it.

**(4) Regex reuse — no behaviour change.** `isLowerHexUuid` (`FiscalEventEngine.ts:1463`) wraps the same **non-global** `LOWER_HEX_UUID` (`:1447`), lowercase-hex only, as a type predicate; the two `receiptService.ts` call sites (`:147`, `:150`) previously passed already-`string` values to `UUID_PATTERN.test`, so the added `typeof` branch cannot change an outcome. The deleted duplicate is gone (`UUID_PATTERN` no longer exists in `apps/pos/src`). I also checked the one risk a value-import introduces: **no cycle** — `FiscalEventEngine.ts` imports nothing from `@/lib/offline` or `@/stores`, and `receiptService` already pulled the engine module in transitively via `instance.ts`.

**(5) YAML attribution.** M4 and M5 now carry `reviewer_model: codex-fallback` plus `gate_of_record: docs/superpowers/reviews/2026-08-30-session-h-h1-a1-merge-gate-r1-fiscal-pos.md`; that file exists and is tracked in the index.

## Unresolved conditions carried forward (all round-1 MINORs, none touched this round — as scoped)

- **r1-4** `receiptService.ts:165` still drops a known `tax_number` for an alias-pending B2B customer while sealing the name — owner confirmation, not a code fix.
- **r1-5** `receiptService.ts:143` still throws raw English, and a blank `customer.name` still hard-fails the v5 seal (`BuyerBlockInput.name` remains `string | null` at `FiscalEventEngine.ts:296`).
- **r1-6** downstream expansion of a now-populated `partner_id` (loyalty accrual, return-draft inheritance, named refund vouchers, analytics/filters) — callout at promotion.
- **r1-8** v4 refunds and the dormant `offlineCheckoutService.executeCheckout` still author no buyer.
- **r1-9** the M4 Playwright gate remains local-only and demo-tenant-mutating.
- New nit (not blocking): the device `SALE_RECEIPT_POST_DISCOUNT_BASE_VERSION = 5` has no cross-language drift gate the way the key-set consts do in `FiscalPayloadKeyDrift.test.ts`; and the contacts `withTrashed()` arm has no archived-contact test, unlike its partner sibling.

## Worktree state — coordination note, not a lane defect

A **merge is in progress** in this worktree: `MERGE_HEAD = c68d6062b` (contained in `dev`; `dev` tip is now `38bf860c8`), 339 staged paths, with **one unresolved conflict — `AA docs/handoff/CODEX-DISPATCH-session-H-phase1-2026-08-29.md`** (docs-only) and a staged delete of `apps/web/src/features/crm/pages/CompanyListPage.tsx` coming from the H1-cleanup work on dev. `HEAD` is still `fe8cbd420` and the branch is **not** an ancestor of `dev`. I confirmed the incoming dev range touches **none** of this lane's production files (`PosCoreReceiptProjection`, `FiscalPayloadConstraintValidator`, `ContactService`, `Contact`, `PartnerService`, `AppServiceProvider`, `FiscalEventEngine.ts`, `receiptService.ts`, `OutboxIngestor`, `StrictCanonicalParser`), so the parent's green runs are not invalidated by it — but the lane report's "branch remains clean, unmerged" line is now stale, and the `AA` must be resolved before anything is promoted.

VERDICT: ACCEPT
