# C-F0w gate r2 — FRONTEND-CONVENTIONS lens (web proforma parity)

| | |
|---|---|
| Lane | C-F0w — web detail pages render the proforma the PDF renders (Session C; LEDGER C-26 (i)) |
| Branch / worktree | `feat/sc-f0w-web-proforma-parity` · `.worktrees/sc-f0w-web-proforma` |
| Reviewed SHA | `088cb9d74` · r1 SHA `ddee0a195` · fix commits `79af6c4e3` (W-1/W-2 round) + `088cb9d74` (fiscal round) · base `eaf80a112` |
| Date / round | 2026-08-26 · r2 (conventions) |
| Prior gates | `2026-08-25-sc-f0w-gate-r1-conventions.md` (ACCEPT-WITH-CONDITIONS, blocking W-1/W-2) · `2026-08-26-sc-f0w-gate-r1-fiscal.md` (W-4 / W-8 ruled; F-1 blocking, closed here) |
| Scope of r2 | verdict on W-1..W-8 + NEW breakage introduced by the two fix commits only |
| Tree state | worktree READ-ONLY and CLEAN at entry and exit (`git status --short` empty, verified twice). No file mutated, so no tamper probe was run — falsifiability argued from the shipped control/inverse pairs. No vitest pools left (`ps aux | grep vitest` empty). |

**VERDICT: ACCEPT — merge-blocking: NO.**

Both r1 blockers are genuinely closed, and I proved each by execution rather than by
reading the handback. The fix commits add no `any`, no `parseFloat` on money, no
suppression comment, no baseline edit, no new color literal, no new `t()` key, and no
query-key change. Everything still open is comment-level or another lane's ticket.

---

## 0. What I executed (nothing below is quoted from a handback)

| Leg | Command | Result |
|---|---|---|
| typecheck | `npx tsc --noEmit -p tsconfig.json` (apps/web) | clean, **exit 0** |
| lint chain | `pnpm --filter @autoerp/web lint` | **exit 0** · `✖ 6449 problems (0 errors, 6449 warnings)` · `audit:keys` Gate C **0 / 0 new / 0 stale** · `audit:design-system` **807 acknowledged, 0 NEW, 0 stale** · `audit:quantity` **0 raw sites** · i18n completeness **OK** (55 ns, en=9351 fr=9367 ar=4982, 2762 gaps held, 1 baseline entry burned down, 0 new) · `test:eslint-rules` 6/6 rule suites · `test:tools` 8 files / 160 tests |
| tests by path | `npx vitest run src/features/documents src/locales/__tests__` (DEFAULT pool) | **55 files / 484 tests PASSED**, 23.8 s |
| manifest checker | `php tools/feature-lane-manifest-check.php` | **OK** — 1439 Feature classes / 74 groups; "every `--filter` entry is anchored and uniquely matched"; parked total 1186 = the manifest's own `gated_ceiling` |
| suppression scan (fix commits only) | `git diff 79af6c4e3..088cb9d74` and `ddee0a195..79af6c4e3` piped through `grep -E '^\+.*(eslint-disable\|@ts-ignore\|@ts-expect-error\|audit-ignore\|: any\|parseFloat\|Number\()'` | **zero code hits**; the only matches are prose inside `feature-lane-manifest.json` and the l4 ticket |
| baseline honesty | `git diff --name-only` over both fix commits | **no baseline file in either commit** (`audit-design-system-baseline.json`, i18n baseline, tanstack baseline all absent); the ratchets above report 0 NEW on their own accounting |
| i18n presence | `node -e` over `src/locales/{en,fr,ar}/sales.json` | all 7 lane keys (`documents.proforma.{title,detail,estimatedTotal,stampDuty,discount,adjustment}` + `documents.creditNote.balanceNote`) present and DIFFERENT in all three locales |
| cache-poisoning probe (new, for F-3's `!== false`) | `grep -rn setQueryData src/features/documents src/features/treasury` | every hit is a TEST; **no production path writes a partial document into `['document','invoice',id]` / `['document',id]`**, so widening `isProforma` cannot be triggered by a cache write |

Mechanism audit (protocol §3): the metric that "improved" in `088cb9d74` is CI coverage
(`ProformaResourceTest` added to the `ci.yml:1044` allowlist). I did not take that on
faith — the manifest checker independently asserts every `--filter` entry is anchored and
uniquely matched against 1839 classes, and the `Document` group's `classes` 88 → 89 plus
`gated_ceiling` 1185 → 1186 reconcile with the checker's own parked count of 1186. No
alias table, no renamed-but-equivalent literal, no detector-keyword suppression.

---

## 1. Verdict on every r1 condition

### W-1 — `Posted` / `Unpaid` chips beside the proforma banner — **ADDRESSED**

- `apps/web/src/features/documents/components/DocumentHeader.tsx:41-52` — new
  `suppressStatusBadge?: boolean` prop, documented; `:60` defaults it to `false`, so all
  other callers (`QuoteDetailPage`, `SalesOrderDetailPage`, `PurchaseOrderDetailPage` —
  the complete consumer census by grep) are behaviourally untouched, and their suites are
  inside the 484 tests I ran green.
- `DocumentHeader.tsx:134-141` — the lifecycle `StatusBadge` is now behind
  `{!suppressStatusBadge && …}`.
- `apps/web/src/features/documents/invoices/InvoiceDetailPage.tsx:473` — passes
  `suppressStatusBadge={isProforma}`.
- `InvoiceDetailPage.tsx:507` — payment chip gated `isConfirmedOrPosted && !isProforma && …`.
- `InvoiceDetailPage.tsx:518` — sealed chip gated `isPosted && !isProforma`.

Verified by execution, not inference: `InvoiceDetailPage.proforma.test.tsx` renders the
live page with the REAL `DocumentHeader`, REAL `StatusBadge`, REAL `DocumentTotals` and
REAL `en` copy on a `status:'posted'`, `payment_status:'unpaid'`, `is_proforma:true`
fixture and asserts `Posted`, `Unpaid`, `Fiscally Sealed` all absent — the exact scan that
was RED at r1. Non-vacuous by construction: the sibling case
`leaves a DEFINITIVE posted invoice exactly as it was` asserts all three PRESENT from the
same fixture builder with only `is_proforma` flipped, so a blanket suppression fails the
control.

### W-2 — no live-page test — **ADDRESSED**

- `apps/web/src/features/documents/invoices/__tests__/InvoiceDetailPage.proforma.test.tsx`
  (new, 6 cases) and
- `apps/web/src/features/documents/credit-notes/__tests__/CreditNoteDetailPage.proforma.test.tsx`
  (new, 6 cases).

Both run `expectNoForbiddenToken(container.innerHTML, …)` over the whole rendered page,
both mock only fetchers/router, both carry a no-projection case and a definitive control.
The forbidden list was tightened in the same commit
(`src/test/proformaTokens.ts:15-38`: `+/chain/i`, `+/\bunpaid\b/i`, `+/\bpaid\b/i`).
Worth recording as positive evidence for the fiscal lens's W-4 ruling: the retained
`DocumentOutstandingCallout` is NOT mocked in the invoice test and the fixture
(`outstanding_amount:'238.000'`, `payment_status:'unpaid'`) makes it render — so the kept
settlement callout is inside the clean scan, not hidden from it.

### W-3 — hand-written DTO mirrors (rule 7) — **ADDRESSED**

`apps/web/src/types/document.ts:60` and `:71` are now
`export type ProformaLineAmounts = App.Modules.Document.Application.DTOs.ProformaLineAmounts`
and `… = …ProformaPresentationData`, docblocks kept. `tsc` exit 0 with the aliases in place.

### W-4 — settlement surfaces on a proforma — **RULED-ELSEWHERE (fiscal r1 §4: KEEP)**

Not re-litigated. One leftover from that ruling's own action item: the fiscal gate asked
that the box-mirrors-the-PDF / page-is-an-internal-surface split be recorded in the
`DocumentTotals.tsx:91-106` docblock. `git diff 79af6c4e3..088cb9d74 -- …/DocumentTotals.tsx`
shows only the F-2 `enabled` comment changed, so that note is **not written**. Comment-level,
non-blocking (fiscal condition 3).

### W-5 — silent fail-open (no "amounts unavailable" message) — **NOT ADDRESSED (deferred, as r1 marked it optional)**

`DocumentTotals.tsx:110-113` still renders an empty `<div>` when `isProforma` and no
projection. Pinned as safe by three tests. Owner/backlog item, not a lane defect.

### W-6 — `!== null` vs `!= null` on projection fields — **ADDRESSED**

`DocumentTotals.tsx:115,128,140` now use `!= null`, with an explanatory comment at
`:108-110`.

### W-7 — `CreditNoteDetail` unreachable + docblock overstates it — **PARTIALLY ADDRESSED**

Docblock softened at `apps/web/src/features/documents/components/CreditNoteDetail.tsx:69-76`
("the component is currently UNMOUNTED … Mount-or-delete is a separate ticket"). The ticket
itself is **not filed**: `grep -rn "mount-or-delete" docs/superpowers/tickets/` returns
nothing; the only hits are the handback's own prose
(`2026-08-25-sc-f0w-handback.md:361,467,503`). Owner rule "anything that works must be
reachable" still has no tracked owner. MINOR, out of lane scope.

### W-8 — a DRAFT invoice is a proforma on screen — **RULED-ELSEWHERE (fiscal r1 §4: predicate correct as shipped)**

Not re-litigated. See N-2 below for the one loose end.

### W-9 — evidence staleness in the handback — **PARTIALLY ADDRESSED**

`79af6c4e3` updates the handback; `088cb9d74` adds no handback entry at all, so the r2
evidence trail for the fiscal fix round exists only in the fiscal gate file. Re-derived
counts at the tip for the record: lint **6449** warnings / 0 errors;
`src/features/documents` + `src/locales/__tests__` = **55 files / 484 tests**.

---

## 2. NEW findings in the two fix commits

### N-1 — [MINOR] Both `is_proforma` docblocks still instruct `=== true`, which the fix commit just stopped doing

`088cb9d74` changed the two live fiscal pages to `!== false`
(`InvoiceDetailPage.tsx:433`, `CreditNoteDetailPage.tsx:146`) but left the guidance in the
types those pages read:

- `apps/web/src/types/document.ts:98` — "every real API response carries it. **Compare with `=== true`**."
- `apps/web/src/types/creditNote.ts:88` — "Never re-derive it. … **compare with `=== true`**."

So the canonical instruction on the security-bearing field now contradicts the shipped
call sites, and a reader who follows it re-opens exactly the F-3 hole this round closed.
The codebase also now holds two conventions for one field —
`CreditNoteDetail.tsx:83` keeps `=== true` (correctly, per the fiscal ruling to leave the
unmounted component alone) — with nowhere stating the rule.

**Fix directive:** amend both docblocks to "on a fiscal READ surface compare with
`!== false` (omission must degrade to 'print less'); elsewhere `=== true`", naming the
fiscal gate F-3 as the reason.

### N-2 — [MINOR] The commit subject of `088cb9d74` advertises a "§2.4 wording" change that is not in the commit

`git diff --name-only 79af6c4e3..088cb9d74` = `ci.yml`, `feature-lane-manifest.json`,
`DocumentTotals.tsx`, `CreditNoteDetailPage.tsx`, `InvoiceDetailPage.tsx`,
`docs/superpowers/tickets/2026-08-05-l4-web-followups.md`. No SPEC document is touched, and
the fiscal lens's W-8 recommendation (narrow §2.4 from "no `apps/web` surface" to "no
read/rendering surface", and record the edit screen as the sanctioned pre-posting VAT
check) is recorded nowhere on the branch. The subject line overstates what landed.

**Fix directive:** either land the §2.4 wording change in the SPEC or drop the claim from
the commit message at squash, and carry the W-8 recommendation to the owner sheet.

### N-3 — [MINOR] `088cb9d74` ships no handback entry

Same class as W-9. One paragraph in `2026-08-25-sc-f0w-handback.md` naming the fiscal fix
round, the four findings closed and the two deferred, keeps the lane's paper trail
self-contained.

**Everything else in the two fix commits is clean.** Verified explicitly rather than
assumed: canonical components only (`StatusBadge` atom, `DocumentHeader`, `DocumentTotals`
— no raw form control, no raw `<table>`, no parallel picker, no new page-level form so no
`StickyFormFooter` question); design tokens untouched (both commits add zero class strings,
so no interpolated-variant/opacity dead-CSS is possible; design-system ratchet 0 NEW; the
`colorClasses` warnings on the touched files are all pre-existing quarantine lines);
no new `t()` key, and the seven lane keys exist in en/fr/ar; `tenantScopedKey` unchanged
and Gate C 0; no form/RHF/zod surface touched; no `any` (typecheck exit 0, grep clean);
no `parseFloat`/`Number(` added on money (the three diff hits are comments); quantity
display untouched (`formatQuantity(line.quantity, getQuantityDecimals(line))` on both
tables, `audit:quantity` 0). Owner rulings: no brand literal added, no `disabled={true}`
control or coming-soon placeholder, no high-contrast decorative band, and the round
strictly REMOVES competing chips — the one-main-element complaint is served, not worsened.
F-1's closure is mechanically real: the manifest checker confirms the new allowlist entry
is anchored and uniquely matched, and the ceiling arithmetic reconciles with the checker's
own parked count.

---

## 3. Open items (none blocking)

1. **N-1** — reconcile the `=== true` guidance in `types/document.ts:98` and
   `types/creditNote.ts:88` with the shipped `!== false`.
2. **N-2** — land or drop the "§2.4 wording" claim; carry fiscal W-8's spec recommendation
   to the owner sheet.
3. **N-3** — handback paragraph for `088cb9d74`.
4. **W-4 residual** — record the box-mirrors-the-PDF / page-is-an-internal-surface split in
   the `DocumentTotals.tsx:91-106` docblock (fiscal condition 3).
5. **W-7** — file the mount-or-delete ticket for `CreditNoteDetail`; it exists only as
   handback prose today.
6. **W-5** — optional: make the degraded (no-projection) state legible instead of blank.
7. Carried from the fiscal gate, unchanged by this round: F-4 (test docblock), F-5 (un-mock
   one settlement component / scope the "whole page" claim), F-6 (filed as N7 in
   `docs/superpowers/tickets/2026-08-05-l4-web-followups.md`), F-7 (fr/ar rendering scan),
   F-8 (VAT-derivable credit-note modal), F-9 (docblock-only import), and the S-14
   promotion leg — `backend-test-pgsql` does not run on `push→dev`.

**VERDICT: ACCEPT — merge-blocking: NO.**
