# C-F0w gate r1 — FRONTEND-CONVENTIONS lens (web proforma parity)

| | |
|---|---|
| Lane | C-F0w — web detail pages render the proforma the PDF renders (Session C, document-lifecycle-dimensions; LEDGER C-26 (i)) |
| Branch / worktree | `feat/sc-f0w-web-proforma-parity` · `.worktrees/sc-f0w-web-proforma` |
| Reviewed SHA | `ddee0a195` (lane commit `17a2e0fad`) · Base `eaf80a112` |
| Date / round | 2026-08-25 · r1 (conventions) |
| Lens | frontend-conventions-reviewer — rules 7/11/14/18/19, tenantScopedKey, i18n, design tokens, owner UI rulings |
| Normative inputs | `BRIEF-C-F0w-web-proforma-parity.md` · `2026-08-25-sc-f0-gate-r1-conventions.md` §3.4 (named by the brief as the lane's spec SoT) · SPEC §2.4 (r11.2) · `.claude/context/i18n.md` |
| Sibling gate | fiscal-pos (predicate parity) runs AFTER this one. Predicate/fiscal reasoning is NOT duplicated here. |
| Tree state | worktree CLEAN at entry and at exit; every probe restored (`git status --short` empty, verified twice). No vitest pools left (`pgrep -fl vitest` empty). |

**VERDICT: ACCEPT-WITH-CONDITIONS — merge-blocking: YES (W-1 and W-2).**

This is careful work and most of it holds under adversarial probing. The predicate is
computed once on the aggregate and reaches the client as one boolean; the client
branches on that boolean and nothing else; the fail-open direction is genuinely safe
(I proved it end-to-end, not by reading the comment); the copy-parity test is
falsifiable (I tampered it red); the i18n ratchet is untouched; no baseline was
edited and no detector was evaded. Two things do not hold: the invoice page still
wears a **`Posted` status chip and an `Unpaid` payment chip beside the proforma
banner** — which the lane's OWN forbidden-token list rejects — and **no test renders
either live page**, which is exactly why nobody caught the first item.

---

## 0. What I executed (nothing below is quoted from the handback)

| Leg | Command | Result |
|---|---|---|
| typecheck | `npx tsc --noEmit -p tsconfig.json` (apps/web) | clean, exit 0 |
| lint chain | `pnpm --filter @autoerp/web lint` | **exit 0**; `✖ 6448 problems (0 errors, 6448 warnings)`; `audit:keys` Gate C 0 / 0 new / 0 stale; `audit:design-system` **807 acknowledged, 0 NEW, 0 stale**; `audit:quantity`; `test:eslint-rules` + `test:tools` 8 files / 160 tests |
| i18n ratchet | `I18N_BASELINE_PROTECTED_BLOB=26a9ae1688d… node tools/audit-i18n-completeness.mjs` | **OK** — 55 ns, authored en=9351 fr=9367 ar=4982 (1986 behind aliases), **2762 gaps held**, 1 baseline entry burned down, 0 new |
| tests by path | `npx vitest run DocumentTotals.proforma · CreditNoteDetail.proforma · proformaCopyParity · DocumentTotals · CreditNoteDetail` | **5 files / 56 tests passed** |
| feature suite | `npx vitest run src/features/documents` | **51 files / 435 tests passed** |
| tamper (i18n) | deleted `ar → documents.proforma.estimatedTotal`, re-ran copy parity | **2 tests RED** (`ar proforma copy is VERBATIM…` + key-set) → non-tautological. File restored via `git checkout --`. |
| probe (live CN page) | throwaway render test, real `en` copy, real components | banner rendered · `Posted` chip absent · gross `119.000` printed · net `100.000` absent · `Estimated total` present · whole-page token scan clean. Deleted. |
| probe (live invoice page) | 3 cases incl. `is_proforma: true` with **no** `proforma` | all green: no VAT, no net figure, no estimated total, banner present. Deleted. |
| probe (real `DocumentHeader`) | same page, `DocumentHeader` **not** mocked | **RED** — `expectNoForbiddenToken` fails on `/posted/i`, `Posted` ×1, `Unpaid` ×1. See W-1. Deleted. |

Mechanism audit (§3 of the protocol): the diff adds **no** `eslint-disable`,
`@ts-ignore`, `@ts-expect-error` or audit-ignore token anywhere
(`git diff … | grep -E '^\+.*(eslint-disable|@ts-…)'` → only handback prose); no alias
table re-exporting `tokens.*`; **no baseline file appears in the diff at all**
(`git diff --name-only … | grep -i baseline` → empty), and both ratchets report 0 NEW
on their own accounting. Nothing here was achieved by evasion.

Rule-7 replay of `packages/shared/types/generated.d.ts`: the three hunks the handback
calls "unrelated stale" are genuine. `sealed_base_era_ambiguous`,
`missing_boundary_marker` and the whole `FiscalPeriodCloseRefusalCode` type already
exist in the PHP sources **at base `eaf80a112`**
(`git grep -l … eaf80a112 -- 'apps/api/**/*.php'` finds each), and this lane touches
none of `Modules/Company/Domain/Enums` or `Modules/Inventory/Domain/Enums`
(`git diff --stat` over both → empty). The committed bundle was stale on `dev`;
regenerating it was correct and reverting would have meant hand-editing generated TS.
The two new DTO shapes match the generated emission field-for-field and in order.

---

## 1. Findings

### W-1 — [MAJOR · MERGE-BLOCKING] The invoice page wears a `Posted` status chip and an `Unpaid` payment chip beside the proforma banner

§3.4's invariant is "no seal/hash/QR/**status badge**", and the lane itself argues the
point twice in prose — `CreditNoteDetailPage.tsx:204-208` ("a `posted` credit note the
chain never sealed wearing either one contradicts the banner below it") and
`CreditNoteDetail.tsx:96-102`. The credit-note surfaces act on that. **The invoice
page does not**: it gates only the `fiscallySealed` chip.

- `apps/web/src/features/documents/invoices/InvoiceDetailPage.tsx:503-509` — the
  payment-status `StatusBadge` is gated on `isConfirmedOrPosted && paymentStatus !== null`,
  with no `isProforma` term.
- `apps/web/src/features/documents/components/DocumentHeader.tsx:125-127` — the
  lifecycle `StatusBadge` (`getStatusLabel(document.status)` →
  `sales:documents.statuses.posted` → **"Posted"**) renders unconditionally; the
  invoice page passes no suppression signal.

Proven, not inferred: rendering the live page with the real header on a
`status: 'posted'`, `is_proforma: true` invoice yields `Posted` ×1 and `Unpaid` ×1, and
the lane's own scanner fails —
`Error: the LIVE invoice page WITH its real header must not contain /posted/i — found: Posted`
(`/posted/i` is `apps/web/src/test/proformaTokens.ts:23`). The lane wrote the detector
that convicts this code and never pointed it at the page.

**Fix directive:** gate the payment-status badge with `&& !isProforma`
(`InvoiceDetailPage.tsx:503`) and thread an `isProforma`/`suppressStatus` prop into
`DocumentHeader` so `:125-127` renders nothing on a proforma — same treatment the
credit-note page already applies at `CreditNoteDetailPage.tsx:206-215`.

### W-2 — [MAJOR · MERGE-BLOCKING] Neither live detail page has a proforma test; the only page-shaped coverage is on an orphan component

The brief names §3.4 as this lane's spec SoT, and §3.4's **Tests** block requires
`invoices/__tests__/InvoiceDetailPage.test.tsx` (new) and
`credit-notes/__tests__/CreditNoteDetailPage.test.tsx` — "a confirmed-unposted fixture
shows the banner and no `TVA`/`VAT`/`Tax` string; a posted fixture is unchanged".
Neither exists on the branch. Shipped coverage is
`components/DocumentTotals.proforma.test.tsx` (a leaf component, 7 cases),
`components/CreditNoteDetail.proforma.test.tsx` (the **orphan** — see W-7) and
`locales/__tests__/proformaCopyParity.test.ts`.

I verified by throwaway probe that both live pages behave correctly **today** (banner,
chip suppression on the CN page, gross `119.000` in place of net `100.000`,
`Estimated total`, no forbidden token) — so this is not a correctness blocker. It is a
coverage blocker: the entire user-visible half of the invariant is unpinned, and W-1 is
precisely the defect a whole-page token scan would have caught on day one.

**Fix directive:** add both page tests; each must run
`expectNoForbiddenToken(container.innerHTML, …)` over the **whole page** with
`DocumentHeader` **not** mocked (mocking it is what hid W-1), plus a definitive-document
case asserting no banner and net line amounts.

### W-3 — [MAJOR] Rule 7: two brand-new hand-written mirrors of DTOs that are already generated and reachable

`apps/web/src/types/document.ts:57-77` declares `ProformaLineAmounts` and
`ProformaPresentation` field-for-field identical to the generated
`App.Modules.Document.Application.DTOs.ProformaLineAmounts` /
`ProformaPresentationData` (`packages/shared/types/generated.d.ts:830-845`), which this
same commit emits and which `apps/web` can already reference (proved by
`src/test/__fixtures__/types-canary.ts`). The house pattern for exactly this is a type
alias, used at 18+ sites — `src/features/admin/country-defaults/types.ts:1-4`,
`src/features/stock-adjustments/types/index.ts:12-16`,
`src/features/catalog/api/variantApi.ts:9-11`,
`src/features/products/api/productStock.ts:3`.

The handback's justification (residual 7: "dozens of existing fixtures construct these
interfaces") is true of `is_proforma?` / `proforma?` on the **pre-existing** hand-written
`Document` interface — that part is an accepted pattern here and I do not fault it. It
is **not** true of these two interfaces: they are new, no fixture constructs them, and
aliasing them costs nothing. As written, a future DTO field rename produces silent
drift instead of a compile error — the failure mode rule 7 exists to prevent.

**Fix directive:** replace both bodies with
`export type ProformaPresentation = App.Modules.Document.Application.DTOs.ProformaPresentationData`
and the matching `ProformaLineAmounts` alias; keep the docblocks.

### W-4 — [MINOR] Settlement surfaces survive on a proforma invoice, contradicting the component's own docblock

`DocumentTotals.tsx:97-98` states the proforma box renders "no settlement rows", and
`showBalanceDue` is correctly gated at `InvoiceDetailPage.tsx:700`. But the page above it
still renders `DocumentOutstandingCallout` (`InvoiceDetailPage.tsx:493-500`) and the
payments tab / `OutstandingAmountSection` (`:767-784`) for a proforma. No VAT leaks —
every figure there is gross — so this is a claim-consistency issue, not a fiscal one:
the page tells the customer an estimate has an outstanding balance. Flagging for the
fiscal lens and the owner rather than ruling it here.

### W-5 — [MINOR] The fail-open path is safe but silent: no amounts at all, and no signal

`DocumentTotals.tsx:110` renders an empty `<div>` when `isProforma` is true and
`proformaTotals` is null; `InvoiceDetailPage.tsx:437-447` /
`CreditNoteDetailPage.tsx:150-166` print `null` for any line the projection misses. I
confirmed by probe that this leaks nothing. But the resulting screen is an invoice with
blank unit-price and total columns and an empty totals box, with no message. Unreachable
today (both detail endpoints override the hook — `InvoiceController.php:101-106`,
`DocumentController.php:187-196`; `grep -rn "new DocumentData("` → zero direct
constructions, so all 39 `fromModel` call sites inherit the safe default), but a future
controller that forgets the override ships a silently amount-less document.

**Fix directive (optional):** render the banner's detail line plus a neutral
"amounts unavailable" `t()` string instead of nothing, so the degraded state is legible.

### W-6 — [MINOR] Inner projection fields are `!== null`-guarded, not `!= null`-guarded

`DocumentTotals.tsx:112,125,136` test `proformaTotals.stamp_duty !== null` etc. The
mirror types these non-optional (W-3), so TS is satisfied, but a payload that omits a key
reaches `formatAmount(undefined)`. Use `!= null` (or alias the generated type and keep
the strictness honest).

### W-7 — [MINOR] `CreditNoteDetail` is unreachable dead code, and its docblock says otherwise

`grep` confirms the component is exported only by
`src/features/documents/components/index.ts:21` and rendered only by its own two test
files; the live credit-note route is `CreditNoteDetailPage` (`src/routes/index.tsx:59,799`).
Its docblock at `CreditNoteDetail.tsx:69-70` asserts it "owns the only in-browser
document PRINT surface (`window.print()`)" — which is false in practice: that surface is
unreachable from the app. The lane's changes to it are correct and were required by the
brief; the problem is that they are the only place the lane's print-surface reasoning
landed. Owner rule "anything that works must be reachable" applies.

**Fix directive:** out of this lane's scope — file a ticket to either mount it or delete
it, and soften the docblock to say the surface is currently unmounted.

### W-8 — [MINOR · owner note, not a defect] A DRAFT invoice is now a proforma on screen too

`Document::isProformaOutput()` (`Document.php:628-644`) returns true for a **draft**
fiscal document, so the detail page for a draft or confirmed invoice now hides the net
subtotal, the per-rate rows and the tax amount from **internal staff**, not just from a
customer PDF. That is what §3.4 mandates ("No `apps/web` surface renders a VAT figure …
for a document `ProformaOutputPolicy` calls a proforma") and I am not re-litigating it —
but the consequence is that there is no longer a detail screen on which a user can check
the VAT of their own document before posting it. Recording for the owner.

### W-9 — [MINOR] Evidence staleness in the handback

§4.2 reports the whole-feature run as `434 tests`; the reviewed tip runs **435** (the
`eb6dc752a` fail-open probe landed after that run). `npx eslint .` is reported as 6457
warnings; the lint chain at the tip reports 6448. Both immaterial, both re-derived above.

---

## 2. What I checked and found CLEAN (no finding)

- **Predicate parity on the client.** `grep` over the four touched files for
  `fiscal_hash|fiscal_status|is_sealed|status ===|isPosted|isBooked`: every remaining
  `status` read drives a status chip, a tab or a credit-note fetch. **No proforma or tax
  decision is keyed on anything but `is_proforma`.** The `CreditNoteDetail.tsx:74-78`
  heuristic and `isBooked` are gone (`CreditNoteDetail.tsx:76-77` now:
  `isCancelled = status === CANCELLED`, `isProforma = is_proforma === true && !isCancelled`).
  Every read site compares `=== true`, so a missing field degrades to "definitive".
- **Rule 19.** No `parseFloat`/`Number(` on money in any line this lane wrote. The three
  survivors (`InvoiceDetailPage.tsx:451,460,461`) are pre-existing and untouched —
  verified against base (`git show eaf80a112:… | grep -n parseFloat` → same three at
  `:420,429,430`). The lane **removes** a real float bug:
  `CreditNoteDetail.tsx:41-42` replaces `parseFloat(amount).toFixed(companyDecimals)`
  with `formatCurrency(amount, { currency: creditNote.currency })`. The two edited legacy
  assertions (`CreditNoteDetail.test.tsx:120,297`) tighten scale from `100.00` to
  `/100[.,]000/` and carry a comment explaining the change — a bug fix, not a weakened
  test.
- **Rule 18 / tokens.** `ProformaBanner.tsx` and the whole proforma branch use
  `semanticColorTokens` only. Every token consumed
  (`intent.caution.{bgSubtle,borderSubtle,textStrong,textStronger}`, `text.{muted,primary}`,
  `border.default`) resolves to a **complete static class** in
  `src/lib/designTokens.ts:161-173,285-302` — no variant prefix or opacity modifier is
  composed onto a token anywhere in the diff, so nothing is dead-CSS. `colorClasses`
  appears only on pre-existing lines that were re-indented into a conditional; the
  design-system ratchet reports 0 NEW.
- **Rule 11 / i18n.** Every new string is a dotted `t()` key. All seven keys land in
  `en`, `fr` **and** `ar` in the single commit `17a2e0fad` (verified by
  `git show --stat`), which is what the shrink-only completeness gate requires.
  `creditNotes.postingMarker.title` / `.detail` are retired with **zero remaining
  readers** (`grep -rn postingMarker` → only `cancelledTitle`/`cancelledDetail` at
  `CreditNoteDetail.tsx:86,88`). The parity test reads the PHP as text and fails loudly
  if the values ever go dynamic; my tamper proves it is not tautological.
- **Rule 14 / query keys.** `CreditNoteDetailPage.tsx:52-54` and
  `InvoiceDetailPage.tsx:115-117` use raw axios `api.get` + a **single** `.data.data`
  unwrap — correct, and unchanged by this lane. No query key changed;
  `audit-tanstack-keys.mjs` reports Gate C 0. `DocumentTotals.tsx:60` keeps
  `tenantScopedKey(['tax-breakdown', documentId])` and merely disables the query
  (`:65`) — the VAT figures never enter the browser, which is stronger than not drawing
  them, and the test at `DocumentTotals.proforma.test.tsx:89-96` pins it.
- **Quantity precision (rule 19 display).** Untouched: both tables still use
  `formatQuantity(line.quantity, getQuantityDecimals(line))`
  (`InvoiceDetailPage.tsx:670`, `CreditNoteDetailPage.tsx:333`). No literal
  `decimalPlaces`, no scale-4 string surfaced. `audit-quantity-display.mjs` in the chain: clean.
- **Owner UI rulings.** OQ-1: no brand literal added (`grep` over the added lines for
  `AutoERP|Syneriva|Synerivia|Otospex|IziPOS` → none). OQ-5: the banner is
  `bg-amber-50` + `border-amber-200`, a subtle band, not a high-contrast decorative one.
  OQ-11: no `disabled={true}` control, no coming-soon toast, no placeholder modal
  shipped. One-main-element: on a proforma the lane **removes** competing chips on the
  credit-note surfaces — W-1 is the one place it fails to. "UI must not overstate system
  guarantees" is the whole point of the lane and is otherwise honoured: the seal chip
  comes off, the balance-reducing sentence comes off (`CreditNoteDetail.tsx:196-205`).
- **Backend wiring (FE-relevant only).** `is_proforma` is computed on the aggregate for
  every response (`DocumentData.php:279`), so no `fromModel` call site can emit a silent
  `false`; `proforma` is enrichment with a `null` default
  (`HandlesDocuments.php:78-83`) overridden by the two controllers the two detail pages
  actually call (`/invoices/{id}` → `InvoiceController::show:242` → `documentResponse`;
  `/documents/{id}` → `DocumentController::showAny:187-196`). Fiscal correctness of the
  predicate itself is the next gate's call.

---

## 3. Conditions

1. **[MERGE-BLOCKING]** Close **W-1**: suppress the lifecycle status chip
   (`DocumentHeader.tsx:125-127`, via a prop from `InvoiceDetailPage`) and the
   payment-status chip (`InvoiceDetailPage.tsx:503`) on a proforma, matching
   `CreditNoteDetailPage.tsx:206-215`.
2. **[MERGE-BLOCKING]** Close **W-2**: add `invoices/__tests__/InvoiceDetailPage.…test.tsx`
   and a proforma block in `credit-notes/__tests__/CreditNoteDetailPage.test.tsx`, each
   running `expectNoForbiddenToken` over the whole rendered page **with the real
   `DocumentHeader`**, plus a definitive-document case. Condition 1 is not proven closed
   until this test is the thing that proves it.
3. Close **W-3**: alias `ProformaPresentation` / `ProformaLineAmounts` to the generated
   `App.Modules.Document.Application.DTOs.*` types (`src/types/document.ts:57-77`).
   May ride the same round.
4. Comment/robustness round, no behaviour: **W-6** (`!= null`) and the **W-7** docblock
   softening.
5. Recorded for the owner / other lanes, not for this lane: **W-4** (settlement surfaces
   on a proforma — fiscal lens to rule), **W-5** (make the degraded state legible),
   **W-7** (mount-or-delete `CreditNoteDetail` — ticket), **W-8** (staff can no longer
   verify VAT on a draft from the detail screen), and the handback's own residual 2
   (the three regenerated enum hunks now ride this commit; tell the owning lanes).

**VERDICT: ACCEPT-WITH-CONDITIONS — merge-blocking: YES (conditions 1 and 2).**
