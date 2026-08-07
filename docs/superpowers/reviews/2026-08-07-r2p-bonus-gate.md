# Gate record — `e9971cf03` (bonus / free-goods supplier invoicing)

- **Branch:** `fix/r2p-purchasing` (worktree `apps/erp.fix-r2p-purchasing`)
- **Commit under gate:** `e9971cf03` — *fix(procurement): declare `lines.*.is_bonus_line` on `CreateSupplierInvoiceRequest`*
- **Diff base:** `a1952aa23` (siblings `11a3363ff` / `702f57974` gated separately)
- **Spec:** `docs/superpowers/tickets/2026-08-03-w4-purchasing-inventory-defects.md` §83-139 (defect #2 / MTP-PUR-16)
- **Reviewer:** imports-reviewer (adversarial, code-grounded). No code modified; the one temporary
  file swap used for red-before verification was restored (`git status --porcelain` empty).

## VERDICT: spec ✅ + quality **CHANGES-REQUESTED**

The prescribed fix (declare the rule, gate it on `PurchaseBonusGate` exactly as `CreateDocumentRequest`
does) is implemented faithfully and the root-cause claim holds. What blocks approval is what the
newly-reachable path now *allows* and what the accompanying test *pins as correct*: a bonus line
billed at full price, which puts the value of free goods into the payable with `match_status: matched`.

---

## What was verified (evidence)

### 1. Root cause — the field really was dropped ONLY at validation

Every layer below the HTTP boundary already consumed `is_bonus_line`:

| Layer | Evidence |
|---|---|
| Service input | `apps/api/app/Modules/Procurement/Application/CreateSupplierInvoiceService.php:115` (`'isBonusLine' => (bool) ($lineInput['is_bonus_line'] ?? …)`), `:160` (snapshot skip), `:188` (persisted) |
| Model | `apps/api/app/Modules/Document/Domain/DocumentLine.php:97` (fillable), `:142` (`'boolean'` cast) |
| DTO | `apps/api/app/Modules/Document/Application/DTOs/DocumentLineData.php:33,59` |
| Matcher | `apps/api/app/Modules/Procurement/Application/SupplierInvoiceMatcher.php:113` (price-check skip), `:300-317` (bonus accumulation), `:389-445` (free-window resolution) |
| Posting | `apps/api/app/Modules/Procurement/Application/SupplierInvoicePostingService.php:445` (excluded from paid aggregate), `:500` (bonus aggregate), `:215-255` (free-window consumption) |
| Credit note / rematch | `SupplierCreditNotePostingService.php:719`, `RematchDraftSupplierInvoicesCommand.php:69,75` |

Controller passes `validated()` (`SupplierInvoiceController.php:216`), so the single drop point was
`CreateSupplierInvoiceRequest::rules()`. **Claim confirmed — no second drop on the create path.**
(One adjacent drop DOES remain on the invoice-first branch — see finding #2.)

### 2. Red-before / green-after — executed, not assumed

Restored `CreateSupplierInvoiceRequest.php` to its `a1952aa23` content and re-ran the new test:

```
1) …::a_fully_received_bonus_receipt_can_be_invoiced_and_posted_with_correct_totals
   is_bonus_line must survive the HTTP boundary — Failed asserting that false is true.  (line 232)
2) …::is_bonus_line_is_prohibited_when_the_purchase_bonus_gate_is_off
   Expected response status code [422] but received 201.                                (line 364)
```

With the commit applied: `OK (2 tests, 20 assertions)`. File restored; worktree clean.
`vendor/` is a real directory (not the stale-symlink trap) and `vendor/composer/autoload_psr4.php:187`
maps `App\` to `$baseDir/app` in THIS worktree, so the runs exercised the branch code.

### 3. Blast radius — all green

| Suite | Result |
|---|---|
| `tests/Feature/Procurement/SupplierInvoiceApiTest.php` | OK 52/52, 306 assertions |
| `SupplierInvoiceMatcherTest` + `SupplierInvoiceMatcherReceiptBasisTest` | OK 25/25 |
| `SupplierInvoiceSnapshotTest` + `SupplierInvoiceReceiptClearingTest` + `PurchaseBonusQuantityEntryTest` | OK 27/27 (1 skipped, pre-existing) |
| `tests/Feature/Accounting/SupplierInvoiceGlTest.php` | OK 17/17 |
| PHPStan on the changed file | `[OK] No errors` |

*(commit message says "SupplierInvoiceApiTest (96 total across the suite run)"; the file has 52 tests — cosmetic.)*

### 4. Sales side — no cross-contamination

`git show --stat e9971cf03` = 2 files. `is_bonus_line` on the generic document path is gated at
`CreateDocumentRequest.php:129-131` / `UpdateDocumentRequest.php:107` — **untouched**. There is no
sales-specific `is_bonus_line` rule anywhere (`grep` over `app/`), so nothing on the sales side moved.

### 5. A non-bonus zero-price line is NOT silently treated as bonus

Bonus classification requires the explicit flag; a zero-priced *non*-bonus line still runs the price
check (`SupplierInvoiceMatcher.php:494-535`) → `|0 − 10.000| × qty` → `price_variance`. Clean.

---

## Findings

### [CRITICAL] The bonus line carries FULL value into the payable, and the new test pins that as "correct"
`apps/api/tests/Feature/Procurement/SupplierInvoiceBonusLineTest.php:207-224` invoices the 2 FREE units
at `unit_price '10.000'` and asserts `subtotal 120.000 / tax 22.800 / total 142.800` as the correct
outcome. Executed probe of that exact scenario (temporary instrumented copy, since deleted) shows what
posts to the GL:

```
408  Fournisseurs - Factures non parvenues   dr=100.000   (accrual, PAID units only)
4456 TVA déductible                          dr=22.800    (incl. 3.800 input VAT on the FREE units)
6585 Écart sur prix d'achat                  dr=20.000    (the free goods, expensed as unfavourable PPV)
401  Fournisseurs                            cr=142.800
invoice total=142.800  balance_due=142.800   match_status = "matched"
```

- `SupplierInvoicePostingService.php:272` bills `$billedHt = $supplierInvoice->subtotal` (includes the
  bonus line) while the accrual loop `:157-213` excludes bonus lines, and
  `GeneralLedgerService.php:1978-1980` books the delta as `priceDelta` → PPV. Nothing blocks it.
- `SupplierInvoiceMatcher.php:113` deliberately **skips the price check for bonus lines**, so the
  overcharge is invisible to three-way matching — the invoice reports `matched`.
- The module's own canonical bonus shape is **zero value**: `SupplierInvoiceSnapshotTest.php:172`
  uses `'unit_price' => '0.000'`; `SupplierInvoiceGlTest.php:356-365` models "Remise en nature" as
  `discount_percent 100.00 / line_total 0.000 / tax_amount 0.000` and asserts `net408 === '0.000'`.
- The create request declares no `discount_percent` / `line_total` for supplier-invoice lines, so
  `unit_price '0.000'` is the ONLY way to express a zero-value bonus line through this API — and the
  reference test does not use it.

**Why it matters:** the commit's purpose is to unlock this path for TN pharmacies. As shipped, the
unlocked path lets a supplier bill free goods at full price, deducts input VAT on goods received free
of charge, inflates 401 by that amount, absorbs the difference into P&L as PPV, and reports the
invoice as cleanly `matched`. The commit's acceptance test blesses that as the reference behaviour.

**Suggested fix (pick one, deliberately):**
(a) constrain the value — when `is_bonus_line` is true require `unit_price` `0` (e.g. a
`prohibited_unless`/custom rule in `CreateSupplierInvoiceRequest::rules()`), or zero the bonus line's
`line_total`/`tax_amount` in `CreateSupplierInvoiceService` around `:106-116`; and flip the E2E test to
`unit_price '0.000'` asserting `100.000 / 19.000 / 119.000` and **no** 6585 leg; or
(b) if a priced bonus line is genuinely intended, say so explicitly in the ticket/code and add the
missing control (a value-axis check for bonus lines) — do not leave `matched` on an over-billed invoice.

### [IMPORTANT] `is_bonus_line` on the invoice-first branch is now accepted but guaranteed to hard-fail
The same `rules()` declares no `lines.*.free_quantity` / `free_qty`, so `validated()` strips them and
`InvoiceFirstOrchestrator.php:100` always passes `'0.0000'` free qty into the auto-receipt. The bonus
flag itself survives (spread at `InvoiceFirstOrchestrator.php:66`), so an
`invoice_first_delivered` / `pending_receipt` request with `is_bonus_line: true` produces a receipt
line with `free_qty = 0` → `ReceiptLineConsumptionPlanner::freeMatchableQty():92-97` returns `0.0000`
→ matcher bonus arm `SupplierInvoiceMatcher.php:~425` yields **Exception** (a HARD block at post) with
an opaque message. Before this commit the same payload posted fine (flag stripped → ordinary paid line).
*Verified by reading the chain, not executed* — no test covers invoice-first + bonus.
**Fix:** declare `lines.*.free_quantity` on this request (and thread it through), or reject
`is_bonus_line` together with `invoice_first_delivered` / `pending_receipt`, and pin it with a test.

### [IMPORTANT] Gate-off rejects `is_bonus_line: false` too — 422 where the old code was harmless
Probe-executed on a gate-off tenant:
```
POST /api/v1/supplier-invoices  lines[0].is_bonus_line = false
→ 422 {"lines.0.is_bonus_line":["The lines.0.is_bonus_line field is prohibited."]}
```
`prohibited` is `! validateRequired` (`vendor/laravel/framework/.../ValidatesAttributes.php`), and
`false` satisfies `required` — so any client that always emits the flag (the web document form already
does exactly that: `apps/web/src/features/documents/DocumentForm.tsx:421`
`is_bonus_line: l.is_bonus_line ?? false`) hard-fails on non-allowlisted tenants. No current web code
posts supplier-invoice lines with the flag (`grep` over `apps/web/src`), so live blast radius is low,
and the behaviour mirrors `CreateDocumentRequest.php:129-131` — but it is a deliberate API-compat
decision that is currently unpinned. The negative test
(`SupplierInvoiceBonusLineTest.php:347-364`) only pins `true`.
**Fix:** decide (reject-any-presence vs allow-explicit-false), and pin the chosen behaviour with a
`false` case in the negative test.

### [MINOR] Only one of the gate's two axes is tested
`PurchaseBonusGate.php:25` (module) and `:36` (country allowlist) are independent. The negative test
uses the Mechanic vertical (no `PurchaseBonus` in `config/verticals.php:12-34`) with `country_code TN`,
so it exercises the module axis only. No test for module-present + non-allowlisted country.

### [MINOR] The MTP-PUR-16 tripwire still pins the OLD behaviour
`apps/web/e2e/money-campaign/purchasing-landed-cost.spec.ts:110-165` asserts
`matchable == ['10.0000','10.0000']`, `match_status === 'quantity_variance'` and `post → 422`; all
three now flip. The ticket's "When fixed" clause prescribed rewriting it. Not CI-wired (no workflow
references `money-campaign`) and sibling `11a3363ff` left MTP-PUR-17 red the same way, so this is a
consistent branch convention — but it must be tracked or the campaign rerun reads as a regression.

---

## What to fix before merge
Decide and enforce the bonus line's VALUE semantics (zero-value line + test flipped to `unit_price
'0.000'`), then close the invoice-first `free_quantity` interaction and pin the gate-off `false` case.

---

# Fix-round re-verify — `9762db355` (group B)

- **Commit:** `9762db355` — *fix(procurement): enforce zero-value bonus lines, close invoice-first gap, pin gate axes*
- **Scope of this pass:** ONLY the five findings above. Re-verified adversarially, tests re-run.
- No code modified. Two temporary probes (one boundary probe, one reverted-file probe) were created,
  executed and deleted; `git status --porcelain` shows only the untracked review records.

## VERDICT: **CLEAR TO MERGE** — one tracked follow-up, no blockers

| Finding | Status |
|---|---|
| B1 CRITICAL — bonus line carried full value | **CLOSED** (both ends: test flipped + boundary enforcement) |
| B2 IMPORTANT — invoice-first bonus gap | **CLOSED** (refusal; decision assessed correct — evidence below) |
| B3 IMPORTANT — gate-off `false` unpinned | **CLOSED** (pinned as deliberate, unchanged) |
| B4 MINOR — MTP-PUR-16 tripwire | **CLOSED** (flipped; new residual honestly documented, verified display-only) |
| B5 MINOR — gate country axis untested | **CLOSED** |

### B1(i) — zero-value bonus economics, verified end-to-end
`SupplierInvoiceBonusLineTest.php` now bills the bonus line at `unit_price '0.000'` and asserts
`100.000 / 19.000 / 119.000` plus the GL shape. Suite re-run: **OK 6/6, 53 assertions**. The GL
assertions are the exact inverse of the round-1 probe output: no `PurchasePriceVarianceExpense` and no
`PurchasePriceVarianceIncome` leg, `408` debit `100.000`, `4456` VAT debit `19.000` (free units excluded),
`401` credit `119.000`. The persisted bonus line is checked at `unit_price '0.000'` / `line_total '0.000'`,
and `match_status 'matched'` + `POST /post → 200` are retained.

### B1(ii) — request-boundary enforcement, probed at the boundary values
`CreateSupplierInvoiceRequest::withValidator()` now rejects a `is_bonus_line: true` line whose
`unit_price` is non-zero (`bccomp((string) $unitPrice, '0', 3) !== 0`), before the cross-field
short-circuit. Executed probe (temporary test, since deleted), Parapharmacy/TN gate-on tenant:

```
unit_price '0.000'  → 201   accepted
unit_price '0'      → 201   accepted
unit_price  0 (int) → 201   accepted
unit_price '0.001'  → 422   "A bonus/free-goods line must be billed at a zero unit price."
unit_price '0.001'  → 422   (X-Language: fr) "Une ligne de bonus/gratuité doit être facturée à un prix unitaire nul."
unit_price '-5.000' → 422   min:0 + the bonus-zero message
unit_price '0.0001' → 422   "Unit price may have at most 3 decimal places." (regex ceiling still first)
```

Every legitimate zero form is accepted, every non-zero form refused, and the message is translated in
both `lang/en/validation.php:181` and `lang/fr/validation.php:181`. The round-1 "red" state is already
on record: at `e9971cf03` the same full-price bonus payload returned **201** and posted
`Dr 6585 PPV 20.000` + VAT on the free units.

### B2 — the refusal decision is correct, and strictly better than the old 201
Executed the decisive probe with the request file reverted to `e9971cf03` (restored immediately after):

```
POST /supplier-invoices  invoice_first_delivered=true, lines[0].is_bonus_line=true
  create status = 201
  match_status  = "exception"
  POST /{id}/post → 422 POSTING_BLOCKED "…has a hard match violation (exception)…"
  auto-POs created = 1     stock_movements created = 1
  invoice status after post attempt = "draft"
```

So the pre-refusal payload did not merely fail later — it **moved stock** (auto-PO + posted
auto-receipt, `InvoiceFirstOrchestrator.php:36` `postImmediately: true`) and left a **permanently
unpostable draft**: the module exposes no update/delete/cancel route for supplier invoices
(`app/Modules/Procurement/Presentation/routes.php:84-120` = index / show / duplicate-reference / store /
match / link-receipts / post only), so the bonus flag on that invoice can never be cleared. Refusal at
the boundary is unambiguously better, and the new test asserts `0` auto-POs for the refused request —
i.e. no stock side effect. **Decision confirmed.** Their test re-run green (part of the 6/6).
The implementation also covers the `pending_receipt` arm
(`$this->boolean('invoice_first_delivered') || $this->boolean('pending_receipt')`), though only the
`invoice_first_delivered` arm is test-covered — see residual (c).

### B3 — pinned
`is_bonus_line_false_is_also_prohibited_when_the_gate_is_off` now pins the `false` case alongside
`true`, with the `prohibited = ! validateRequired` rationale in the docblock and the explicit statement
that this mirrors `CreateDocumentRequest.php:129-131` and is deliberate. Matches my round-1 probe
exactly. Re-run green.

### B4 — tripwire flipped; the new residual is genuinely display-only (verified)
`purchasing-landed-cost.spec.ts` MTP-PUR-16 now asserts the fixed shape (zero-value bonus line,
`match_status 'matched'`, `post → 200`) and documents the read-model residual inline. I verified the
residual is confined to presentation:

- `SupplierInvoiceController.php:614` computes `price_variance` via `matcher->priceStatus()` with **no**
  bonus check, and `:626` returns `'status' => $doc->match_status?->value` — i.e. the block's status is
  READ from the persisted column, never derived from the per-line flags.
- The persisted column is written only from the authoritative `SupplierInvoiceMatcher::match()`
  (`:113` skips bonus lines) — at creation (`CreateSupplierInvoiceService.php:219`) and at
  `POST /{id}/match` (`SupplierInvoiceController.php:272-274`).
- Postability is `assertPostable()`, whose price loop skips bonus lines at `SupplierInvoiceMatcher.php:235`.

**Confirmed: the spurious `price_variance: true` cannot affect `match_status` or postability.** It is a
false red flag shown on EVERY bonus line in the exact TN-pharmacy flow this ticket unlocks, so the
follow-up ticket should not be backlog-forever; it should also cover the sibling gap on the same read
model — `matchable` reports the PAID window for bonus rows (`:611`), which the spec comment likewise
documents as read-model shape.

### B5 — country axis pinned
`is_bonus_line_is_prohibited_when_the_module_is_enabled_but_the_country_is_not_allowlisted` uses a
Parapharmacy tenant (PurchaseBonus IS default-enabled, `config/verticals.php:360`) with an **FR**
company, isolating `PurchaseBonusGate.php:36` from the module axis at `:25`. Re-run green.

## Re-verification runs (this pass)

| Check | Result |
|---|---|
| `SupplierInvoiceBonusLineTest` | OK **6/6**, 53 assertions |
| Consumer sweep: `SupplierInvoiceApiTest`, `SupplierInvoiceMatcherTest`, `SupplierInvoiceMatcherReceiptBasisTest`, `SupplierInvoiceSnapshotTest`, `SupplierInvoiceReceiptClearingTest`, `PurchaseBonusQuantityEntryTest`, `SupplierInvoiceGlTest` | OK **121 tests / 631 assertions**, 1 skipped (pre-existing pgsql-only) |
| PHPStan L8 (request + test) | `[OK] No errors` |
| Pint (request + test + en/fr lang) | `{"result":"pass"}` |
| Boundary probe (7 unit_price shapes, en+fr) | as tabulated above |
| Reverted-file probe (invoice-first pre-refusal) | as tabulated above |

## Residuals (none blocking)

- **(a) TICKET — read-model `price_variance` / `matchable` are not bonus-aware**
  (`SupplierInvoiceController.php:611,614`). Display-only, verified harmless to status/postability, but
  user-visible on every bonus line. Orchestrator is ticketing it; confirmed appropriate.
- **(b) MINOR — gate-off + bonus + non-zero price returns two errors**: `lines.N.is_bonus_line`
  (prohibited) *and* `lines.N.unit_price` (must be zero), because the new check reads raw input before
  the gate outcome is consulted. Cosmetic, and it discloses the bonus feature's existence to a tenant
  that does not have it.
- **(c) MINOR — the `pending_receipt` arm of the invoice-first refusal is code-covered but not
  test-covered** (only `invoice_first_delivered` has a test).
- **(d) NOTE — the new checks run before the `errors()->isNotEmpty()` short-circuit**, so a bonus-shape
  error masks the cross-field PO/partner/currency errors on the same request. Intentional per the inline
  comment; benign (only affects already-invalid requests).

## Bottom line
Both blocking findings are closed at the boundary (not just in tests), the B2 decision is the safer of
the two options and is now evidenced, and the remaining items are cosmetic or a tracked display-only
ticket. **CLEAR TO MERGE.**
