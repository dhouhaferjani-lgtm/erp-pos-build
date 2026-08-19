# Adversarial Merge-Gate Register — M4 (retirement + `consolidation_frequency`), round 3

**Range reviewed:** `84d4dfbff..6caec2e93` — one commit, "Phase 2.4.10", the PARENT's own record fixes
answering round 2's CHANGES-REQUIRED (`docs/handoff/reviews/dn-consolidation-build/M4-round2.md`).
**Lenses:** frontend-conventions, general.
**Nature of this round:** a truthfulness check on the parent's edits to the record. Round 2 blocked on
documentation, not code; this round therefore verifies that each corrected sentence is *itself* true,
that no correction introduced a fresh inaccuracy, and that the delta really is code-free.
**Everything below was re-derived from code at HEAD or from `bbef65232` by me.** No line number,
count or citation in the corrected handback was taken on trust; two of them do not survive
re-derivation as stated (both **understatements**, both record-only — §C).

---

## A. Conservation — the delta touches no code

| Check | Result |
|---|---|
| `git diff --stat 84d4dfbff..6caec2e93` | 3 files: `docs/architecture/frontend.md`, `docs/handoff/HANDBACK-dn-consolidation-build-2026-08-12.md`, `docs/handoff/progress/dn-consolidation-build.progress.yaml` |
| `git diff --name-only 84d4dfbff..6caec2e93 -- apps/ packages/ scripts/` | **0 files** — no source, no test, no config, no baseline |
| Audit baselines in the delta | none (`audit-design-system-baseline.json` absent from the file list; audit reports **0 stale**) |
| Suppression / evasion tokens on added lines (`eslint-disable`, `ts-ignore`, `ts-expect-error`, `.skip(`) | none |

Because the source tree is byte-identical to `84d4dfbff`, round 2's code verdict (correct, complete,
honestly built) carries unchanged. I re-ran the gates anyway (§D) rather than assert it.

---

## B. Round-2 findings — the parent's fix, verified line by line

### N1 (P2, the blocking one) — **FIXED, and the correction is true**

The handback now reads: *"CORRECTION (M4 round 2, N1): that server-side branch is **LIVE, not a
triage candidate** — `getPartnerDeliveryNotes` (`apps/web/src/features/documents/api/deliveryNotes.ts:171-180`)
sends `status=confirmed&uninvoiced=1&partner_id=…` on the partner tab's default lane, hitting the same
`DeliveryNoteController.php:298` branch, pinned by that file's own green test. Only the RETIRED page's
consumer of the un-scoped shape is gone."*

- **Citation resolves exactly.** `deliveryNotes.ts:171` is `const filterParam = filter === 'all' ? {} : { [filter]: 1 }`
  and `:172-180` is the `api.get('/delivery-notes', { params: { partner_id, status: 'confirmed', ...filterParam, page, per_page, with_aggregates: 1 } })`
  block. The cited range covers the whole shape — not one line short, not one line long.
- **The shape claim is true.** With `filter === 'uninvoiced'` the params are exactly
  `partner_id`, `status=confirmed`, `uninvoiced=1`.
- **The "pinned by a green test" claim is true.** `api/deliveryNotes.test.ts:38-46` asserts
  `mockApi.get` was called with precisely `{partner_id:'partner-42', status:'confirmed', uninvoiced:1, page:2, per_page:10, with_aggregates:1}`.
- **The deletion nomination is gone.** The sentence *"That server-side branch is now unused by the web
  client and is a candidate for the same triage"* no longer exists anywhere in the handback; the
  replacement states the opposite and attributes the correction.
- **The residual clause is accurate.** `git show bbef65232:…/deliveryNotes.ts:167-175` — the deleted
  `getInvoiceableDeliveryNotes(partnerId?: string)` sent the same three params with an **optional**
  partner id, so "the RETIRED page's consumer of the un-scoped shape" is a fair description of what
  was removed, and it does not re-imply anything about the server.

The outage class round 2 identified (a later lane deleting a live `uninvoiced` branch on the strength
of this note) is closed at the source.

### N2 (P3) — **FIXED on all three claims; escalation lands with a correct owner**

- *"three `parseFloat` calls over money (count corrected M4 round 2, N2)"* — **true**.
  `git show bbef65232:…/hooks/useDeliveryNotes.ts | grep -c parseFloat` → `3`, and the fourth
  accumulator is `lineCount += dn.lines?.length ?? 0` (old `:280`). Verified in the old file body.
- *"The two lint warnings its deletion removed are `no-unnecessary-condition`, not precision warnings"*
  — matches round 2's re-derivation (3 → 1 on the two changed source files, the two removed being the
  `dn.lines?.length ?? 0` optional-chain pair at old `:280`, which I re-confirmed is the exact line).
  The prior attribution ("accounted for by the deleted `parseFloat` helper") is gone.
- *Escalation:* `no-parsefloat-on-money.js:29-40` `argName()` and the bail at **`:66`** —
  **both citations exact**: `:66` is `if (name === null) return;`, and `argName` (`:29-40`) returns a
  name only for `Identifier` / `MemberExpression`. `DocumentListPage.tsx:199-200` and `:264-265` are
  exact (`parseFloat(doc.balance_due ?? doc.total ?? '0')` / `parseFloat(doc.total ?? '0')` at both).
- *"coordinates with enforcement-P2's RuleTester deliverable"* — **verified, not decorative**.
  `docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md:342` names
  `no-parsefloat-on-money.js` as one of exactly three rules with **no** RuleTester, and `:345`
  makes authoring one a P2 deliverable. The ticket is routed to the package that owns it.
- **One understatement, record-only:** see **R3-a**.

### N3 (P3) — **FIXED; the "12" is correct, one wording nit remains**

`docs/architecture/frontend.md:89-96` now reads *"### Documents Feature (selected pages)"* /
*"the feature holds 12 page components across its type-specific subdirectories; the load-bearing ones
for this doc are listed"*, and the `DocumentDetailPage` line is deleted.

- **`DocumentDetailPage` is gone from the doc** and `grep -rn "DocumentDetailPage" apps/web/src` → **0 hits**,
  so the doc no longer names a component that does not exist.
- **The count is right. I counted it myself:**
  `find src/features/documents -name '*Page.tsx' -not -path '*__tests__*' -not -name '*.test.tsx'` → **12**:
  `CreateCreditNotePage`, `CreateReturnNotePage`, `DocumentListPage`, `ReturnNoteListPage` (feature root),
  `credit-notes/CreditNoteDetailPage`, `delivery-notes/DeliveryNoteDetailPage`, `invoices/InvoiceDetailPage`,
  `purchase-orders/PurchaseOrderDetailPage`, `quotes/QuoteDetailPage`, `return-notes/ReturnNoteDetailPage`,
  `sales-orders/SalesOrderDetailPage`, `to-bill/ToBillPage`. **12 is the true number.**
  (`DocumentForm.tsx` exists but is not a `*Page.tsx`; the count correctly excludes it — the doc's
  own "Pages" list still names it, see **R3-b**.)

### N4 (P3) — **RECORDED, and the record matches my round-2 measurement**

Handback: *"`PartnerForm.test.tsx:356` is a contention flake under the DEFAULT vitest pool (2 of 4
default-pool runs failed; 533/533 ×3 under `--maxWorkers=1`; partners alone 3/3 green). Pre-existing,
not introduced by the M4 delta — M5 must not read it as a regression."*

Every number is verbatim my round-2 measurement, and the citation is exact: `PartnerForm.test.tsx:356`
is `fireEvent.click(screen.getByRole('option', { name: /Amen Bank CFCTTNTT/i }))`, the failing line.
Fresh data point (§D): **4 of 4 default-pool runs green today**, which is what an intermittent
contention flake looks like on a differently-loaded machine and does not contradict the record. The
record is honestly framed as an observation ("2 of 4 runs failed"), not as a deterministic property.

### N5 (P3) — **FIXED; the epoch note is exactly right, and "14 of 59" is exactly right**

Handback: *"14 of the 59 unconstrained parameter bindings in that `routes.php` (the file has more;
these are the document/delivery-note params adjacent to this wave; line numbers below are as of
`bbef65232`, one lower than HEAD after the `:49` insertion — M4-round2 N5)"*.

- **Epoch claim — verified on all 16 cited numbers, not spot-checked.** I dumped
  `bbef65232:…/routes.php` and read every cited line: `:279` `->whereUuid('partner')`,
  `:284` `->whereUuid('deliveryNote')`, `:292` `POST /delivery-notes/{deliveryNote}/confirm`,
  `:327/:331/:335/:339` additional-costs, `:343` landed-cost-breakdown, `:348` related,
  `:353` tax-breakdown, `:358` payments, `:363` credit-allocations, `:368/:372` pdf,
  `:377/:381` email. **All 16 resolve at `bbef65232`.**
- **"one lower than HEAD" — verified by four independent spot-checks at HEAD:** confirm `:292 → :293`,
  additional-costs index `:327 → :328`, related `:348 → :349`, email/queue `:381 → :382`; precedents
  `:279 → :280`, `:284 → :285`. Exactly +1, i.e. the cited numbers are one lower than HEAD, as stated.
  The shift is caused by the fix's own single insertion, and the fix's own citation `routes.php:49`
  is correct at HEAD (`->whereUuid('document')` on the `documents/{document}` route opened at `:48`).
- **"14 of the 59" — I re-derived 59 independently and got 59.** Parsing every `Route::` chain in the
  file and subtracting params carrying a `whereUuid`/`where` constraint yields **59** unconstrained
  parameter bindings, distributed `invoices 15, documents 15, orders 7, quotes 6, purchase-orders 6,
  credit-notes 4, return-notes 4, delivery-notes 1, reports 1`. The survey's 14 = the 13
  `documents/{document}` sub-resource bindings + the one `delivery-notes/{deliveryNote}/confirm`;
  the two `{cost}` bindings and the 43 other-type bindings are the unlisted remainder. Both the
  numerator and the denominator are right, and the "the file has more" hedge removes the exhaustive
  reading the old commit-message wording invited.

### N6 (P3) — **RECORDED, and the record is accurate**

Handback: *"the retired URL now renders the detail page's bare error card
(`DeliveryNoteDetailPage.tsx:115-123`, no back link) — pinned as intended by
`DeliveryNoteConsolidationRoute.retired.test.tsx`; a friendlier not-found treatment is an M5
candidate, not owed here."*

Verified at HEAD: `:115` is `if (error || !deliveryNote) {`, `:116-123` is the red card whose only
content is `<p>{t('common:error')}</p>`, closing at `:123`. The `Link to="/inventory/delivery-notes"`
back link is at `:129`, inside the success branch — so **"no back link" is literally true** for the
error branch. Reachability was re-verified as zero inbound links in round 2 and no code changed since.
Framing it as an M5 candidate rather than an M4 defect is correct: the spec forbade a redirect or
compatibility alias, so the bare card is a consequence of the ruled design, not a deviation.

---

## C. `progress.yaml`

```
-    fix_rounds: 1
-    commit: cdec3a5a2
+    fix_rounds: 2
+    commit: cdec3a5a2   # code delta unchanged in round 2 — the round-2 findings were record-only, fixed parent-side
```

`fix_rounds: 2` is correct (round 1's fix round `2bbc28b69`/`cdec3a5a2`, plus this record-only round).
The inline comment is **honest and load-bearing**: it explains why `commit:` did not advance, and it
matches reality — round 2's register closes with *"No code change is required by any finding in this
register"*, and §A above proves the delta is code-free. This is the opposite of the failure mode the
field invites (bumping a round counter while quietly moving the commit pin). `last_verdict:
CHANGES-REQUIRED` is still correct as of this file's own base. One stale pointer: **R3-c**.

---

## D. Gates I ran myself in this round (nothing below is quoted)

| Gate | Result |
|---|---|
| `pnpm --filter @autoerp/web typecheck` | **clean** |
| `pnpm --filter @autoerp/web lint` | **0 errors, 6459 warnings** — identical to round 2. `audit:keys` Gate C **0 / 0 acknowledged, 0 new, 0 stale**; `audit:design-system` **728 acknowledged, 0 new, 0 stale**; `audit:quantity` **0 total, 0 new, 0 stale**; all three RuleTesters pass |
| `vitest run src/routes src/features/documents src/features/partners` (**default pool**, ×4) | **65 files / 533 tests passed, 4 runs out of 4** — the N4 flake did not reproduce today (round 2: 2 of 4 failed). Recorded as an additional data point, not a contradiction |
| `git diff --name-only 84d4dfbff..6caec2e93 -- apps/ packages/ scripts/` | **empty** |
| Re-derivation of every number the parent wrote | `parseFloat` count **3**; page components **12**; unconstrained bindings **59**; survey items **14**; rule bail line **:66**; 16/16 survey citations resolve at `bbef65232` and are +1 at HEAD |

Backend gates were not re-run: no PHP file changed in this delta, and round 2 ran
`DocumentShowRouteUuidConstraintTest` (2 passed), its removal-probe (red), `DocumentRevertEndpointTest`
(4 passed), Pint and PHPStan on the same tree.

---

## E. New observations, round 3 — all P3 / MINOR, all record-only, none blocking

### R3-a — **P3 — `HANDBACK…:finding-4 escalation`** — "22 live sites" understates the guard hole (direction: conservative)

The escalation says *"22 live sites today"*. Re-deriving it with the rule's **actual** `MONEY_NAME`
regex (`no-parsefloat-on-money.js:18-19`) against every `parseFloat(x ?? …)` / `parseFloat(x || …)` /
`Number(x || …)` whose left operand is an identifier or member expression, I get **26 sites, of which
25 are live source** (the 26th is `features/pos/__fixtures__/productInfo.ts:119`). Beyond the four
already cited, the live set includes `pos/molecules/DiscountInput/DiscountInput.tsx:294,298`,
`pos/organisms/AdvancedPaymentsModal/AdvancedPaymentsModal.tsx:315,327`,
`pos/organisms/TransactionCart/TransactionCart.tsx:321,328`,
`inventory/components/ProductDocumentsTab.tsx:150,154`,
`documents/CreateReturnNotePage.tsx:481`, `documents/invoices/InvoiceDetailPage.tsx:420,429,430`,
`documents/purchase-orders/PurchaseOrderDetailPage.tsx:321-323`,
`documents/sales-orders/SalesOrderDetailPage.tsx:373-375`,
`documents/components/CreateCreditNoteForm.tsx:147`, and
`documents/components/costing/LandedCostBreakdown.tsx:104,105` (the last two via `Number(`, which the
same rule and the same blind spot cover).
The figure is faithfully copied from round 2 and errs toward **under**stating exposure, so it misleads
nobody into deleting or trusting anything — but the ticket should not treat 22 as the scope.
**Fix directive (parent ledger, not this wave):** record the figure as "≥25 live sites — re-derive at
ticket time", since the count moves with every burn-down.

### R3-b — **P3 — `docs/architecture/frontend.md:89-96`** — two wording imprecisions in the corrected block

1. *"12 page components across its **type-specific subdirectories**"* — only **8** of the 12 live in
   type-specific subdirectories; `CreateCreditNotePage`, `CreateReturnNotePage`, `DocumentListPage`
   and `ReturnNoteListPage` sit at the feature root. The count is right, the locational gloss is not.
2. The "Pages" list still names `DocumentForm`, which is **not** one of the 12 (`DocumentForm.tsx` is
   not a `*Page.tsx`), so a reader adding the listed items against the stated total gets a mismatch.

Strictly cosmetic, no false existence claim, and materially better than what it replaced.
**Fix directive:** say "12 page components (4 at the feature root, 8 in type-specific subdirectories)"
and mark `DocumentForm` as "form component, not counted above" — or drop the parenthetical entirely.

### R3-c — **P3 — `docs/handoff/progress/dn-consolidation-build.progress.yaml`** — `verdict:` still points at round 1

`fix_rounds` advanced to 2 but `verdict: docs/handoff/reviews/dn-consolidation-build/M4-round1.md`
was left untouched, so the field now points at a **superseded** register while `M4-round2.md` is the
operative one (and `M4-round3.md` after this commit). `last_verdict: CHANGES-REQUIRED` is correct.
A reader following the pointer gets round 1's findings, all of which are closed.
**Fix directive:** point `verdict:` at `M4-round3.md` and set `last_verdict` from this file's final
line when the parent records this round.

---

## F. Bypasses attempted that FAILED

| Attempt | Result |
|---|---|
| Did the "fix" quietly move the code pin or absorb the delta's own debt? | No — `commit:` unchanged, `apps/` untouched, no baseline file in the delta, audits report 0 new / 0 stale |
| Is any corrected citation right in the old epoch but wrong at HEAD (the trap that created N5)? | No — the fix's own citation `routes.php:49` is HEAD-correct, and the 16 legacy citations are explicitly stamped to `bbef65232` with the +1 offset stated |
| Does the N1 correction over-correct — i.e. now claim something *else* untrue about the server branch? | No — `getPartnerDeliveryNotes` really does hit `applyDeliveryNoteFilters` via `uninvoiced=1`, and the "only the retired page's consumer is gone" clause matches `bbef65232:deliveryNotes.ts:167-175` |
| Is the N2 escalation's enforcement-P2 hand-off invented? | No — `CODEX-DISPATCH-enforcement-guards-2026-08-12.md:342,345` names `no-parsefloat-on-money` as an untested rule and makes the RuleTester a P2 deliverable |
| Did the frontend.md fix replace one false claim with another (the N3 failure mode, twice already)? | No — `DocumentDetailPage` has zero references and is gone; the "12" is the number I counted myself |
| Owner rules on the delta (OQ-1 brand strings, OQ-11 dead controls, guarantee copy, one-main-element) | Clean — the delta adds no user-facing copy and no JSX; `grep '^+' … -Ei 'autoerp\|syneriva\|synerivia\|coming soon\|disabled=\{true\}'` → empty |

---

## Disposition

The parent's record fixes are accurate. N1 — the one finding that actually blocked — is fixed at the
right depth: the sentence that nominated a live server branch for deletion is gone, the replacement
states the branch is LIVE, and the citation it leans on (`deliveryNotes.ts:171-180`) resolves to
exactly the ten lines that send `status=confirmed&uninvoiced=1&partner_id=…`, pinned by that file's
own green test at `api/deliveryNotes.test.ts:38-46`. The outage path round 2 described is closed. The
four numeric corrections all survive independent re-derivation rather than merely reading plausibly:
three `parseFloat` calls (not four), twelve page components (I counted them), fifty-nine unconstrained
bindings of which the survey covers fourteen (I re-parsed the route file and got 59 with a
distribution matching the register's breakdown), and all sixteen legacy citations resolving at
`bbef65232` at exactly one below HEAD — an epoch the handback now stamps explicitly instead of leaving
the reader to discover it. The two recorded-not-fixed items (N4 flake, N6 bare error card) are stated
in the terms I measured them in, with exact citations, and the escalation is routed to the package
that actually owns it (enforcement P2 deliverable 1, which independently names this very rule as
untested). The YAML does the honest thing where dishonesty would have been invisible: `fix_rounds`
advances to 2 while `commit:` deliberately does not, with an inline comment saying why — and §A
proves the claim, since the delta touches zero files under `apps/`, `packages/` or `scripts/`, no
baseline, and no suppression. Gates re-run at this tree are unchanged (0 errors / 6459 warnings,
typecheck clean, 533/533 four times).

Three residuals remain, all P3 and all record-only: the escalation's "22 live sites" understates the
real exposure (I derive ≥25, so it errs safe), `frontend.md` calls all 12 page components
subdirectory-resident when 4 sit at the feature root and still lists `DocumentForm` outside the count,
and the YAML's `verdict:` pointer still names the round-1 register. None of them asserts anything
false about code behaviour, none can cause a deletion or a misread gate, and each is a one-line
correction that belongs in the same pass that records this verdict. Nothing here justifies a fourth
round on a code-free delta.

VERDICT: ACCEPT
