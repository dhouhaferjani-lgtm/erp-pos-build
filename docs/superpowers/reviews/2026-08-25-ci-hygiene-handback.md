# CI-hygiene micro-lane handback — ratchet regen + actionlint

**Lane** ci-hygiene (Session A) · **Branch** `chore/ci-hygiene-ratchet-actionlint` ·
**Worktree** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/ci-hygiene` ·
**Base** local dev `d6c5a77e1`

| SHA | What |
|---|---|
| (commit 1) | `ProvisioningRequiredPurposesV1` AST ratchet regenerated (W4-9 residual / LEDGER C-32) |
| (commit 2) | `.github/workflows/ci.yml` actionlint clean-up (G-4 owed) |

---

## 1. `ProvisioningRequiredPurposesV1` AST ratchet regen

### Regen procedure (there is no generator command)

`apps/api/tools/` holds only `deptrac-ratchet.php` and `feature-lane-manifest-check.php` — no
generator exists for this manifest. The authoritative list IS the scanner inside
`ProvisioningRequiredPurposesRegistrationRatchetTest::scanThrowingSites()` (private), so the regen
was driven by invoking THAT method by reflection against the current dev tree, never by hand-typing
line numbers. The scanner output was written verbatim into
`ProvisioningRequiredPurposesV1::registeredThrowingCallSites()`; the `entries()` evidence citations
were then re-pinned by a second AST pass that re-locates each cited call/`ClassConstFetch` node in
its named method. Both scripts lived in the session scratchpad and are not committed — the frozen
data is the artefact, per the manifest's own "ADDED, NOT EDITED" docblock.

### Red evidence (before, on the lane base = dev `d6c5a77e1`)

```
Tests: 13, Assertions: 195, Failures: 2.

1) ProvisioningRequiredPurposesRegistrationRatchetTest
   ::test_every_production_throwing_purpose_resolution_site_is_registered
   (100 registered vs 103 scanned; whole-file line shift across GeneralLedgerService,
    AccountingService, AccountingOpeningService)
2) ProvisioningRequiredPurposesV1ConformanceTest
   ::test_every_evidence_citation_resolves_to_current_source_semantics
   app/.../GeneralLedgerService.php:4113|GeneralLedgerService::createFromExpense|getAccountByPurpose|Bank
   Failed asserting that null is not null.
```

Identical to the 2 inherited reds W4-9 §8.1 recorded. **Nothing else was failing.**

### What the ratchet now lists: 100 → 103 sites

**REMOVED (3)** — exactly the three W4-9 §8.1/F-7 predicted:

| site | why |
|---|---|
| `GeneralLedgerService::createPOSPaymentEntry \| getAccountByPurpose \| ProductRevenue` | dead lookup deleted (W4-9 F-5); the sale resolves through the shared specs |
| `GeneralLedgerService::createPOSRefundReversalEntry \| getAccountByPurpose \| ProductRevenue` | the refund no longer resolves its own account |
| `GeneralLedgerService::createInstrumentCancellationEntry \| getAccountByPurpose \| ProductRevenue` | the `PosRevenue` arm goes through the shared specs (W4-9 F-1) |

**ADDED (6)** — the 3 predicted, plus 3 the handback did not know about:

| site | provenance |
|---|---|
| `GeneralLedgerService.php:4141 \| posRevenueAndVatLineSpecs \| getAccountByPurpose \| ProductRevenue` | W4-9 (predicted) |
| `GeneralLedgerService.php:4158 \| posRevenueAndVatLineSpecs \| getAccountByPurpose \| SalesDiscount` | W4-9 (predicted) |
| `GeneralLedgerService.php:4170 \| posRevenueAndVatLineSpecs \| getAccountByPurpose \| VatCollected` | W4-9 (predicted) |
| `GeneralLedgerService.php:446 \| reclassifyCustomerPaymentToAdvance \| getAccountByPurpose \| CustomerReceivable` | **N-6** `012a8de5f` (411→419 reclassification GL primitive) |
| `GeneralLedgerService.php:447 \| reclassifyCustomerPaymentToAdvance \| getAccountByPurpose \| CustomerAdvance` | **N-6** `012a8de5f` |
| `GeneralLedgerService.php:4786 \| createFromExpense \| getAccountByPurpose \| Bank` | **W4-2/W4-10** `4250c54a0` — the non-cash-tender fallback (`paymentMethodAccountForExpense(...) ?? Bank`), a SECOND Bank lookup in the same method |

**D-1 (`9f2aed21e`) check, as asked: D-1 added NO new throwing purpose-resolution site.** The scan is
a whole-tree AST pass over `app/ routes/ config/ database/ bootstrap/` at the merged dev tip, so any
D-1 site would necessarily have appeared in the diff; the three unpredicted additions blame to N-6
and W4-2 (`git log -S`), not to D-1.

**RE-PINNED (34 evidence citations + every remaining frozen line number).** All from whole-file line
drift, e.g. `AccountingService::createInvoiceGLEntries` 412/416/420/424 → 612/616/620/624,
`AccountingOpeningService::postBatch` 314 → 752, `createSupplierInvoiceGrIrClearingEntry` 2051-2057 →
2259-2265, `createVoucherLedgerEntry` 2633/2634 → 2943/2944 with every
`resolveVoucherEventAccounts` source reference at a uniform +310.

**Two ambiguous re-pins were resolved by semantics, not by arithmetic:**

- `Bank` (`REQUIRED`, call-site label *"createFromExpense paid bank branch"*) now has TWO candidate
  lines. Pinned to **4765** — the `RepositoryType::BankAccount => getAccountByPurpose(Bank)` branch,
  which is the branch the label names. The new W4-10 fallback at 4786 is in the frozen list as its
  own site but is not the entry's evidence.
- The voucher `DYNAMIC` arms: 2943 is the debit call, 2944 the credit call, matching the old
  2633/2634 debit/credit pinning; each purpose's source reference was checked to land in the arm its
  entry names (`SalesReturnsClearing` 3003 = Issued/Refund debit, `VoucherLiability` 3020 = Redeemed
  debit, `PosTenderClearing` 3021, `MarketingGoodwillExpense` 3007, `RoundingLossExpense` 3026).

### Classification: NOTHING moved

The diff is **data-only** — line numbers plus the 6 add / 3 remove. Every purpose named by a new
site (`ProductRevenue`, `SalesDiscount`, `VatCollected`, `CustomerReceivable`, `CustomerAdvance`,
`Bank`) was ALREADY `REQUIRED`. The `28 + 1 + 4 + 10` partition in `assertConforms()` is untouched
and still holds.

**Registry verification by booting the container** (`php artisan tinker --execute`, treasury-gate
style — a real application boot, not a static read):

```
requiredPurposes count=28
SystemAccountPurpose::requiredPurposes count=28
distinct pinned purposes=23
pinned-but-not-REQUIRED: SalesStampDutyPayable
ProductRevenue: REQUIRED   SalesDiscount: REQUIRED   VatCollected: REQUIRED
CustomerReceivable: REQUIRED   CustomerAdvance: REQUIRED   Bank: REQUIRED
```

`SalesStampDutyPayable` is the manifest's single `SCOPE_REQUIRED` entry by design (TN stamp-duty
scope), not a regression — it is the only pinned purpose outside the REQUIRED partition, and it was
outside it before this lane too.

### Green evidence

**sqlite, by path, one process:**

```
tests/Unit/CountryDefaults/ProvisioningRequiredPurposesRegistrationRatchetTest.php
tests/Unit/CountryDefaults/ProvisioningRequiredPurposesV1ConformanceTest.php
→ OK (13 tests, 431 assertions)

tests/Feature/Accounting/SeededChartManifestRequiredPurposeCompletenessTest.php
tests/Feature/Accounting/ChartPurposeBackfillSeederParityTest.php
tests/Feature/Accounting/LiveTenantChartValidationParityTest.php
→ OK (19 tests, 448 assertions)
```

**PostgreSQL**, throwaway `autoerp_test_cih` on `127.0.0.1:5433` (`autoerp`/`autoerp_secret`),
**dropped afterwards** — all five files in one process:

```
→ OK (32 tests, 879 assertions)
```

---

## 2. `actionlint` on `.github/workflows/ci.yml`

`actionlint 1.7.12` (already installed via Homebrew — no install needed).

| | issues |
|---|---|
| **before** | **10** |
| **after** | **0** |

**Genuine actionlint errors: ZERO.** All 10 were the same shellcheck-level `SC2086:info` on ten
copies of one line — `run: echo "dir=$(composer config cache-files-dir)" >> $GITHUB_OUTPUT` (lines
45, 123, 161, 277, 384, 549, 631, 1106, 1226, 2536). Trivial, so fixed rather than listed: the
redirect target is now `>> "$GITHUB_OUTPUT"`. No workflow semantics change.

`actionlint` over the whole `.github/workflows/` directory (ci.yml, react-doctor.yml,
smoke-test.yml, sonarcloud.yml) is now **0 issues**.

### `--filter` allowlist audit (the ask: 10+ lanes unioned them by hand)

Two hand-maintained allowlists: **ci.yml:1025** (fiscal/POS lane, 138 entries) and **ci.yml:1149**
(tenancy lane, 16 entries).

- **Duplicates within a list: NONE.** 138 entries / 138 unique; 16 / 16 unique.
- **Syntactically valid regex alternations: YES.** Both compile as PCRE (`preg_match` returns
  non-false) and the 1025 list matches a sample FQCN; every entry is a plain
  `[A-Za-z_][A-Za-z0-9_]*` identifier, so no stray metacharacter, empty alternative, or unescaped
  delimiter is present.
- Four class names appear in BOTH lists (`DeviceLossIncidentTest`,
  `FiscalEventQuarantineTableTest`, `FiscalEventsImmutabilityTest`, `ReceiptChainRebuildTest`).
  That is cross-LIST, not intra-list: two different jobs deliberately run the same class, so it is
  not a de-duplication candidate. **No entries were removed.**
- Independently corroborated by the repo's own checker, which validates every `--filter` entry is
  anchored, live, and uniquely matched.

---

## 3. Gates

| Gate | Result |
|---|---|
| PHPStan level 8, touched PHP | **No errors** |
| Pint, touched PHP | **pass** (`{"result":"pass"}`) |
| `php tools/feature-lane-manifest-check.php` | **EXIT=0** — 1438 Feature classes / 74 groups, every `--filter` entry anchored and uniquely matched against 1838 test classes |
| `php tools/deptrac-ratchet.php` | **PASS**, 183/183 held, no boundary regression |
| `actionlint .github/workflows/ci.yml` | **exit 0**, 0 issues |
| `tests/Architecture/FeatureLaneManifestCheckerTest.php` (detector liveness, run because ci.yml was touched) | **OK (76 tests, 431 assertions)** |

No migration. No new test files, so no lane ceiling moves.

## 4. Production changes

- `apps/api/app/Modules/CountryDefaults/Domain/Services/ProvisioningRequiredPurposesV1.php` —
  `entries()` evidence citations re-pinned; `registeredThrowingCallSites()` regenerated (100 → 103).
  Data only; no classification, no logic, no signature changed.
- `.github/workflows/ci.yml` — 10 × `>> $GITHUB_OUTPUT` → `>> "$GITHUB_OUTPUT"`.

## 5. Residuals / deferred

1. **The frozen list will be stale again the moment `GeneralLedgerService.php` is edited.** The
   line number is deliberately part of the key, and this file is the busiest in the repo. Worth
   considering (NOT done here, out of scope): a `php artisan ratchet:regen-required-purposes`
   generator, so the regen is one command instead of a reflection script.
2. **`createFromExpense` now resolves `Bank` from two places** (4765 branch, 4786 W4-10 non-cash
   fallback). Both are frozen; only 4765 is the `Bank` entry's evidence. If the fallback later
   becomes the primary path, the entry's `call_site` label should follow it.
3. The `--filter` allowlist at ci.yml:1025 is 138 entries on ONE line. Not touched (any reflow is a
   ci.yml diff that would collide with the lanes still editing it), but it is unreviewable as
   written and is the reason this audit was asked for.
4. Untouched inherited reds recorded by W4-9 and NOT re-verified here (out of scope):
   `AdvanceReversalGlShapeTest` (11/12, decimal formatting) and the PG `pos_shifts_closed_logic`
   fixture error in `ReceiptPaymentServiceToleranceTest`.
