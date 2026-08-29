# Gate r1 — Lane I-1 automated onboarding campaign (fiscal/POS lens)

**Reviewer:** fiscal-pos-reviewer (adversarial, code-grounded)
**Date:** 2026-08-29
**Commit:** `008c2a199` — worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/i1-campaign` (branch `feat/i1-onboarding-campaign`, on top of dev `7d48b4de1`)
**Brief:** `docs/sessions/session-I-process-hardening-2026-08-29/LANE-I1-onboarding-campaign-BRIEF.md` (r4)
**Scope of diff:** 17 files, +2525/-2 — Playwright e2e (`apps/web/e2e/campaign/**`), `playwright.campaign.config.ts`, `apps/web/e2e/tsconfig.json`, eslint/package.json wiring, `scripts/campaign-onboarding.sh`, `.github/workflows/onboarding-campaign.yml`, `docs/qa/ONBOARDING-CAMPAIGN.md`. **No backend code.**

## VERDICT: spec ❌ + quality CHANGES-REQUESTED

The machine is well built and the fiscal envelope work is correct. It is blocked on one thing: **the gate's headline output is factually wrong** — one of the three "product findings" that keep the run RED cannot ever be true on this tree, and the commit message and operator doc repeat it as fact.

---

## What I verified GREEN (evidence, so a re-gate does not redo it)

**Vendored canonicaliser — clean.**
- `diff apps/pos/src/lib/fiscal/canonicalCore.ts apps/web/e2e/campaign/fiscal/canonicalCore.ts` → exit 0 (byte-identical). Same for `FiscalEventCanonicalEncoder.ts`.
- No `@/` alias anywhere under `apps/web/e2e/campaign/` (`grep -rn "@/"` → no match). The encoder's only import is relative: `apps/web/e2e/campaign/fiscal/FiscalEventCanonicalEncoder.ts:27` `from './canonicalCore'`.

**Hand-authored bodies vs the server contract — coherent.**
- Envelope/payload `event_time_device` split is CORRECT, not a drift: the envelope carries second-precision UTC (`apps/api/app/Modules/Fiscal/Application/Services/StrictCanonicalParser.php:674-676`, `ISO_8601_SECONDS_UTC`) while the payload carries milliseconds (`fiscal/events.ts:305-307 withMilliseconds` vs `FiscalPayloadConstraintValidator.php` `ISO_8601_DATETIME_MS_TZ`). `fiscal/events.ts:77` and `:200` deliberately differ.
- `unit_price` = gross TTC (`fiscal/events.ts:259`), `line_subtotal` = net (`:251`), `quantity: '1.000'` — the B2C POS convention. **No per-line `line_subtotal == unit_price × qty` assertion anywhere** in the campaign (rule 19 respected); fiscal integrity is asserted at the aggregate/GL level only.
- Refund is a `SALE_RECEIPT` at `event_version 4` with `invoice_type_code: 'REFUND'` (`fiscal/events.ts:137`) plus `original_receipt_reference` (`:147-152`) and `original_line_references` disposition `restock` (`:141-146`) — no invented parallel refund event type.
- `currency_scale` 3 / TND, all amounts scale-3 strings, VAT partition consistent: `vat_breakdown[0]` `rate '19.00'` + `tax_category_code ''` matches `line_items[0].vat_rate '19.00'` + `tax_category_code ''`; `subtotal 20.000 + vat_total 3.800 == total 23.800`; `payments[0].amount == total`. This satisfies the validator's §6.C partition and §6.D total cross-check.
- **No rounding drift is possible**: `receiptAmounts` (`fiscal/events.ts:292-303`) returns hardcoded literals; there is zero arithmetic in the receipt builder, so the campaign cannot compute a different number from the device's scale+1 intermediates. The GL assertions in L6/L7 pin exactly the sealed values, which is the right test (the projection must reflect, not re-derive).

**Money / rule 19 — clean.**
- `grep -rn "parseFloat|parseInt|Number\(|toFixed|Math\." apps/web/e2e/campaign` → only two hits, both inside the vendored SHA-256 (`FiscalEventCanonicalEncoder.ts:145,151`), byte-identical to the device.
- `moneySubtract` (`onboarding.campaign.ts:866-878`) is correct. Traced: `-300.000 → -300000n`; `-0.500 → -500n` (`BigInt('0500') === 500n`); delta `-1n → '-0.001'`; delta `0n → '0.000'`; delta `6000000n → '6000.000'`; delta `-6000000n → '-6000.000'`. `normalizeMoney` (`journey.ts:166-181`) throws rather than silently truncating beyond scale 3.

**Refund GL direction — the implementation is RIGHT and the brief was wrong.**
The brief's L7 row demanded the `sales_return` purpose account. The code deviates (`onboarding.campaign.ts:606-616`) and asserts a debit to `product_revenue`. Verified: `GeneralLedgerService::createPOSRefundReversalEntry` (`apps/api/.../GeneralLedgerService.php:4175`) credits the drawer GL account (`:4213-4221`) and calls `writePosRevenueAndVatLines(..., onDebitSide: true)` (`:4232-4241`) on the SAME revenue/VAT accounts. Asserting `sales_return` would have manufactured a false finding. Good routine call, well documented in the comment.

**Restock direction — correct, not assumed.** `TreasuryReceiptBridge.php:1558-1565` uses `MovementDirection::Out` for a refund tender leg; L7 asserts stock and DEFAULT lot restored to `20.000` (`onboarding.campaign.ts:577-585`).

**Toolchain.** `pnpm typecheck:e2e` → clean. `pnpm exec eslint e2e/campaign playwright.campaign.config.ts` → 0 errors / 142 warnings. Repo-wide `pnpm lint:eslint` → **0 errors** / 6600 warnings, i.e. the `'e2e/*'` + `'!e2e/campaign'` ignore change does not break CI. Workflow mirrors `smoke-test.yml`; root `package.json:19` carries `packageManager`, so `pnpm/action-setup@v5` resolves.

---

## Findings

### BLOCKER

**B-1 — `apps/web/e2e/campaign/onboarding.campaign.ts:195-196` (and the finding at `:204-226`): the I2-F1 "second company has no payment repositories" finding is FALSE BY CONSTRUCTION and can never be true.**

The company-2 check is
```ts
const ownedSafes = second.repositories.filter((repository) =>
  repository['type'] === 'safe' && repository['location_id'] === null)
```
A day-one safe is NEVER `location_id === null`:
- `apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php:196` — `$this->paymentRepositoryProvisioner->provisionForCompany($company->tenant_id, $company->id, $location->id)` — the new company's location id is passed in.
- `apps/api/app/Modules/Treasury/Application/Services/PaymentRepositoryProvisioningService.php:108-125` — `defaultRepositories()` returns BOTH `CASH-01` and `SAFE-01`, and both get the same resolved location.
- `apps/api/database/seeders/PaymentRepositorySeeder.php:83-85` — "attribute the day-one repositories to the company's own POS location **instead of leaving `location_id = NULL`**".
- G-3c is **already merged on dev** (`9badbe294`, `feat(treasury,company): G-3c company-provisioning parity`), including `database/migrations/tenant/2026_08_30_100800_backfill_company_payment_repositories.php`.

The campaign's OWN ledger proves it: the recorded evidence lists `CASH-01@01a04f36-f5f3…` and `SAFE-01@01a04f36-f5f3…` on company 2's own location (`expected_location_id: 01a04f36-f5f3-70a7-98d5-180de9f20a44`) and `"matching_cash_count": 1` — i.e. the cash half PASSED and only the null-safe half failed. The finding text says "not provisioned with company-owned payment repositories". That is not what the evidence shows.

Note the internal inconsistency: `assertDayOneCensus` (`:692`) uses the CORRECT criterion for company 1 — `repositories.filter(r => r['type'] === 'safe')`, no location predicate — while `:196` invents a stricter one for company 2.

**Why it matters:** this is a promotion gate. It is permanently RED on a fabricated defect; the commit message (`I2-F1 second company has no payment repositories (G-3c)`) and any promotion-checklist row citing this ledger assert a bug that does not exist; and lane G-3c gets re-opened to "fix" already-shipped behaviour. A gate that cries wolf on run 1 is worse than no gate.

**Fix:** change `:195-196` to `repository['type'] === 'safe' && repository['location_id'] === secondLocationId` (mirroring `:193-194`), re-run the campaign, and correct the commit message + `docs/qa/ONBOARDING-CAMPAIGN.md` / handover claims about I2-F1.

### MAJOR

**M-1 — `onboarding.campaign.ts:163-167, 193-196, 673-695` + `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentRepositoryController.php:39-43`: the campaign consumed a tenant-wide list as if it were company-scoped, and so MISSED the real second-of-everything defect it stumbled onto.**
`index()` filters by `tenant_id` only — no `company_id` — while `show()` (`:57-59`) filters by both. The ledger's company-2 census line lists all four repositories from BOTH companies under `X-Company-Id = company2`. The campaign never asserts company scoping, so this went unrecorded and was replaced by B-1's false finding. **Fix:** assert every repository returned under a company header belongs to that company (via `company_id` in `formatRepository`, or via location ownership), and record the leak as the finding if it is one.

**M-2 — `apps/web/e2e/campaign/journey.ts:325-342`: the P0 diagnosis is imprecise and its "continue anyway" comment is false on the branch it describes.**
The condition is `if (!bodyIsJson || registerResponse.status() !== 201)`. A perfectly well-formed JSON 422 (email already taken, weak password) or a JSON 500 records the finding *"Registration response body is not JSON — migration census lines leak into the HTTP body"* — a false root cause pinned on the wrong owner (`fix lane migration-echo-p0`). Separately, `expect(registerResponse.status()).toBe(201)` at `:341` **throws** on any non-201, so `await loginAs(page, credentials)` at `:342` is unreachable — contradicting the comment at `:330-331` ("continue via login so the rest of the journey is still exercised"). The live P0 is a 201 with a text prefix, which is why the run got past it. Also, "client stranded on step 4" is an inference: nothing asserts the UI is still on `/register`. **Fix:** gate the census-echo finding on `!bodyIsJson` alone; on a non-201 throw with the status + body head and no finding; assert the stranded URL if you want to keep that claim.

**M-3 — `onboarding.campaign.ts` L6 (`:473-548`): the drawer balance is never asserted after the cash sale, so L7's drawer assertion has no discriminating power.**
`TreasuryReceiptBridge.php:1558-1565` records `MovementDirection::In` for the gross tender on the drawer. L7 asserts `drawer.balance === '1000.000'` (`:617-618`) and L8 asserts `'2250.500'` (`:648-649`). Both are satisfied *identically* whether the sale movement and refund movement both happened, or **neither** happened. A regression that drops POS repository movements entirely passes L6+L7+L8. **Fix:** in L6, `pollUntil` the drawer balance to `'1023.800'` before L7 runs.

**M-4 — `onboarding.campaign.ts:448-449` vs L6/L7/L8: no post-POS trial-balance re-assertion, and per-entry balance is never checked.**
`is_balanced` + `total_debit == total_credit` are asserted only in L4, before any POS or payment posting. `assertJournalAccountCode` (`:801-810`) finds the FIRST line with a given account code and asserts one side; it never checks the entry balances or that no extra legs exist. An unbalanced or extra-leg POS entry passes. **Fix:** re-assert `/reports/trial-balance` `is_balanced` at the end of L7 and L8, and assert Σdebit == Σcredit (via `moneySubtract`) on each entry under test.

**M-5 — country parameterisation is a lie; only TN works.**
`--country` / `CAMPAIGN_COUNTRY` is advertised for DZ/FR/GB/IT/MA/US (`journey.ts:145-160`, `scripts/campaign-onboarding.sh:7`, workflow input `:19-22`, `docs/qa/ONBOARDING-CAMPAIGN.md`), but:
- the sealed seller carries `tax_number: '1234567AM000'` (`fiscal/events.ts:288`), which `apps/api/app/Shared/Domain/Validation/CountryTaxNumberRules.php:35` (FR `^([0-9]{9}|[0-9]{14})$`), `:53` (DE), `:54` (IT) **reject** — L6 would quarantine and read as a product defect;
- the VAT rate is hardcoded `'19.00'` (`fiscal/events.ts:98,169,263`);
- the second-company create hardcodes `timezone: 'Africa/Tunis'` (`onboarding.campaign.ts:181`).
**Fix:** derive seller tax number + VAT rate per country, or hard-fail at L0a with "campaign supports TN only" and drop the knob from the doc/workflow.

**M-6 — reuse mode is broken for exactly the tenant it exists for.**
`registerFreshTenant` returns early under reuse mode (`journey.ts:267-278`), but L0 still calls `assertDayOneCensus(first)` (`onboarding.campaign.ts:156`), which pins `repositories` at exactly 2 (`:693`). L4 creates a bank repository (`:401-407`), so ANY tenant that reached L4 fails L0 and every leg is SKIPPED. Additionally, reuse mode on a tenant with a posted AR/AP opening batch takes the `historical.length === 0` branch (`:248-254`) and records a PRODUCT FINDING that the code's own comment (`:245-247`) says is expected "for a reused tenant" — a manufactured finding on the staging-triage path. **Fix:** skip `assertDayOneCensus` and the L1 batch_conflict finding when `reuseMode`, or restrict reuse mode to L6–L8.

**M-7 — `journey.ts:366-376` `ensureSession` re-logs-in per leg against a 5/minute throttle.**
`RateLimiter::for('login')` is `Limit::perMinute(5)->by('login:email:'.$email.'|'.$ip)` (`apps/api/app/Providers/AppServiceProvider.php:299-308`). L1–L8 each call `loginAs` with the same email from the same IP; the fast API-only legs (L3, L4) can push past 5/minute. A 429 surfaces only as `expect(page).not.toHaveURL(/\/login/)` timing out at 30 s (`journey.ts:385`) — an opaque false RED on a promotion gate. **Fix:** persist and reuse Playwright `storageState` across legs (one login per run), or detect 429 and back off with an explicit message.

**M-8 — no tenant teardown, misleading `CAMPAIGN_KEEP_TENANT`, and credentials in public artifacts. Must be resolved BEFORE `ONBOARDING_CAMPAIGN_ON_PUSH=true`.**
Nothing deletes the registered tenant; `CAMPAIGN_KEEP_TENANT` only controls whether credentials are *printed* (`journey.ts:230-233`, `:355`). Yet `apps/web/e2e/campaign/README.md:11` says "Set `CAMPAIGN_KEEP_TENANT=1` to **retain** and print the generated login" and `docs/qa/ONBOARDING-CAMPAIGN.md` says "retains and prints" — implying non-retention otherwise. Every run leaves a permanent tenant **and a `tenant_<uuid>` PostgreSQL database**. Compounding on staging: the password is the fixed constant `'Campaign!2026Safe'` (`journey.ts:283`) at a predictable `campaign+<suffix>@test.otospex.dev`; `trace: 'on'` + `screenshot: 'on'` (`apps/web/playwright.campaign.config.ts:20-22`) capture the typed password and the bearer token, uploaded as a repo artifact for 7 days (`.github/workflows/onboarding-campaign.yml:63-71`); and `concurrency … cancel-in-progress: true` (`:27-29`) can orphan a half-projected fiscal chain mid-L6. **Fix before flipping the push variable:** teardown step or a documented staging janitor; per-run random password; `trace: 'retain-on-failure'`; fix both doc claims now.

**M-9 — `onboarding.campaign.ts:239` vs `:284-306`: L1 re-run idempotency is asserted for documents but not for partner rows.**
`expect(campaignPartners).toHaveLength(4)` runs BEFORE the re-run. After `runImportWizard(..., true)` only the HIST document delta (`:286-288`) and the workbook text (`:306`) are re-asserted. A regression that duplicates the four partners on re-run passes L1. (L2 does this correctly: products re-counted at `:336`, stock re-asserted at `:338` — so the L2 idempotency claim IS verified by assertion, the L1 one only partially.) **Fix:** re-read partners after the re-run and pin 4.

### MINOR

- **m-1 — `apps/web/playwright.campaign.config.ts:15` vs `journey.ts:527`:** `pollUntil` defaults to 45 s and L6 chains four polls (`onboarding.campaign.ts:514, 519, 526, 534`) inside a 90 s per-test timeout. The documented `projection timeout — worker running?` diagnostic (`journey.ts:538`, `scripts/campaign-onboarding.sh:46`, operator doc) can be pre-empted by the Playwright timeout. Raise the L6/L7 test timeout above Σ(poll budgets).
- **m-2 — quantities compared with the MONEY normaliser.** `assertMoneyEqual`/`normalizeMoney` pin `MONEY_SCALE = 3` (`journey.ts:8,166-181`) but are used on stock and lot quantities at `onboarding.campaign.ts:334, 338, 375, 377, 518, 523, 585`. Quantity is `decimal(N,4)` (rule 19); a legitimate scale-4 quantity throws `Money value exceeds scale 3` and reports as a script error, not a finding. Add `normalizeQuantity` at scale 4.
- **m-3 — dead code / vestigial assertion.** `journalHasAccountCode` (`onboarding.campaign.ts:812`) and `deepContains` (`:821`) are unused (confirmed by eslint `no-unused-vars`). `journalHasAccountCode` reads like the residue of the brief's `sales_return` assertion; leaving it invites a future reader to assume it is enforced. Delete both.
- **m-4 — findings from L0's register step carry `tenantId: null`.** `journey.ts:253` reads `journeyState.tenantId`, set only at `:352`. Confirmed in the live ledger. The brief requires the tenant id on every finding. Re-stamp it in `finalizeCampaignLedger`.
- **m-5 — inconsistent lot-quantity comparison.** L6 uses raw string equality `=== '19.0000' || === '19.000'` (`:521`); L7 uses `moneyIs` (`:583`). Use one helper.
- **m-6 — `MAIN` is assumed, not asserted.** `fixtures/products.csv.template:2-5` hardcodes `location_code=MAIN` while the journey takes `locations[0].id` with no check that its code is `MAIN` (`onboarding.campaign.ts:157-158`). Assert `code === 'MAIN'` at L0 so a rename fails loudly there rather than confusingly at L2/L3.
- **m-7 — `.github/workflows/onboarding-campaign.yml:16`:** `PNPM_VERSION: '9'` is declared and never used.
- **m-8 — `journey.ts:321-324` (429 branch):** correctly avoids a false finding (it throws before `recordProductFinding`), but the throw lands as leg L0 `FAIL`, which on a promotion gate reads as a product regression rather than an infra throttle (`register` = 5 per 15 min per IP, `AppServiceProvider.php:311-314`). Record it as a distinct ledger reason, e.g. `ABORTED(throttled)`.

---

## Answers to the gate questions

1. **Vendored canonicaliser / hand-authored bodies:** byte-identical, no alias, contract-coherent. **No rounding drift is possible** — the amounts are literals, there is no arithmetic in the builder, and the GL assertions pin the sealed values, which is the correct posture (projection reflects, does not re-derive).
2. **Money:** clean. Zero floats outside the vendored SHA-256. `moneySubtract` verified correct including negatives, zero and sub-unit deltas.
3. **Data-meaning assertions:** mostly real (GL legs, balances, stock, lot, allocation — not status codes). Two holes: **M-3** (drawer never pinned after the sale, so L7/L8 cannot distinguish "sale + refund" from "neither") and **M-4** (no post-POS trial balance, no per-entry balance check).
4. **Finding→FAIL / L10 gate:** the mechanism is sound — `recordProductFinding` (`journey.ts:245-257`) forces the leg to FAIL, `recordTestResult` (`:200-207`) refuses to promote a `PRODUCT FINDING` leg back to PASS, and L10 (`onboarding.campaign.ts:664-670`) turns the run red. A finding cannot go green. The failure mode here is the **opposite**: a *false* finding (**B-1**) and an imprecise P0 diagnosis (**M-2**). The 429 branch correctly avoids a false finding (m-8).
5. **Idempotency:** L2 is verified by assertion (product count + stock re-read). L1 is verified for documents only — partner rows are asserted before the re-run and never re-counted (**M-9**).
6. **Would it lie on staging:** yes, in five ways — **B-1** (false finding), **M-5** (country knob only works for TN), **M-6** (reuse mode fails at L0 and manufactures an L1 finding), **M-7** (login throttle → opaque red), **M-8** (no teardown; doc claims retention is opt-in; credentials in artifacts). The worker precondition is a printed reminder only (`scripts/campaign-onboarding.sh:46`) and its diagnostic can be pre-empted by the test timeout (m-1).

---

## What to fix before merge

Fix **B-1** (one-line `location_id === secondLocationId`), re-run the campaign, and correct the commit message + operator doc where they assert I2-F1 as a real defect; fix **M-2** (gate the census-echo finding on `!bodyIsJson`) and the **M-8** `CAMPAIGN_KEEP_TENANT` retention claim in both docs. M-1/M-3/M-4/M-9 can land as a fast follow-up lane, but **M-5/M-6/M-7/M-8 must all close before `ONBOARDING_CAMPAIGN_ON_PUSH` is set to `true` or the campaign is pointed at staging.**
