# Adversarial Merge-Gate Register — M4 (retirement + `consolidation_frequency`), round 2

**Range reviewed:** `bbef65232..0b2148760` — the fix round answering round 1's CHANGES-REQUIRED
(`docs/handoff/reviews/dn-consolidation-build/M4-round1.md`). Commits `2bbc28b69` (RED),
`cdec3a5a2` (GREEN), `0b2148760` (docs).
**Lenses:** frontend-conventions, general.
**Everything below was re-derived from code and re-run locally.** Two of the three round-1 closures
were **probe-proven by me** (I broke the production code and watched the new tests go red, then
restored — `git status` is clean apart from this register). No count, gate result or citation in the
handback was taken on trust; three of them do not survive re-derivation (findings N1, N2).

---

## A. Round-1 findings — disposition, verified against code

| R1 | Sev | Status |
|---|---|---|
| 1 — RED retirement test deleted instead of corrected; retirement had zero coverage | P2 | **CLOSED — CONFIRMED, and stronger than claimed.** `apps/web/src/routes/DeliveryNoteConsolidationRoute.retired.test.tsx:59` asserts what actually renders (`delivery-note detail page`), with three negatives at `:60-62`. I did not accept the "probe-proven" claim: I re-introduced a `delivery-notes/consolidate` route **twice** — once ahead of `delivery-notes/:id` and once after it — and the test went red both times (`findByText('delivery-note detail page')`, the probe body rendering `Consolidate Delivery Notes probe`). Both probes reverted. Regression power is real regardless of where a future re-introduction is declared. |
| 2 — `GET /documents/{document}` unconstrained → PG 22P02 500 | P2 | **CLOSED — CONFIRMED.** `apps/api/app/Modules/Document/Presentation/routes.php:49` now carries `->whereUuid('document')`. See §B for the judgement call on the test; short version: it cannot go green while the 500 returns. I ran it (2 passed / 4 assertions) and I removed the constraint and re-ran it: **red**, with the intended message. Sibling `DocumentRevertEndpointTest` still 4/4. No regression risk: every backend test that hits `documents/{…}` interpolates a real model id (`grep -rEn "api/v1/documents/[a-z0-9-]+['\"]" tests/` → only `documents/auto-save` and this new test), and no other client calls the route (`apps/pos`, `apps/mobile` → zero hits). |
| 3 — 10 orphaned i18n leaves per locale | P2 | **CLOSED — CONFIRMED.** Exactly 10 leaves removed per locale (7 + the 3 `errors.*`), en and fr symmetrical. I re-verified per key: zero references to any deleted key anywhere in `apps/web/src`; the only surviving `deliveryNotes.consolidation.*` references are the seven `billingRefusal.*` leaves, and **all seven resolve in both locales** (I dumped the blocks and diffed the reference set against them — no key lost, no key orphaned). `ar/sales.json` has no `deliveryNotes.consolidation` block at all, so no third-locale orphan remains. `billingRefusal.title` correctly survives (consumed at `ToBillPage.tsx:75`, `PartnerDeliveryNotesTab.tsx:213`). |
| 4 — four dead exports | P3 | **CLOSED — CONFIRMED (deleted).** `git grep` at `38fdd18f9^` proves the sole non-test consumer was `components/DeliveryNoteConsolidation.tsx:13,15,16,67,75,87`; current tree has **zero** references to any of the four. Orphaned assertions were adjusted, not silently dropped, and the now-unused `apiGet` mock left `api/deliveryNotes.test.ts` with it (no remaining case in that file uses `apiGet`, so the mock removal is safe — checked). The `parseFloat` disclosure is materially wrong; see **N2**. |
| 5 — French refusal copy unasserted | P3 | **CLOSED — CONFIRMED.** `PartnerDeliveryNotesTab.test.tsx:261-294` asserts the fr `billingRefusal.guarantee` sentence and the `.removeAndRetry` button. The file's global `beforeEach` resets `i18n.changeLanguage('en')` (`:100-102`), so the added `changeLanguage('fr')` cannot leak into later cases — I checked, because an unrestored language is the classic way this pattern poisons a file. File is 14/14. |
| 6 — stale page in `docs/architecture/frontend.md` | P3 | **CLOSED, but replaced with a fresh inaccuracy.** See **N3**. |
| 7 — stale C-3 PHPStan pin | P3 | **RECORDED as required**, in both the handback and `progress.yaml` `findings:`, with the "M5 must re-derive" instruction spelled out. Accurate. |
| 8 — `B2BFieldsSection` help text / `aria-describedby` | P3 | **RECORDED as required**, verbatim, in both artefacts. Accurate. |

---

## B. The two judgement calls round 1 asked me to rule on

**B1 — is "the detail page renders" sufficient retirement proof?** **Yes**, and the negative is
present: `:60-62` assert `queryByText(/consolidat/i)`, `/regroup/i` and `dashboard fallback` are all
absent. The load-bearing assertion is the positive one, and I proved it carries the regression
(§A/R1-1). Note the negatives are belt-and-braces rather than the guard: with the consolidation copy
now deleted from `sales.json` (R1-3), a re-introduced page would render raw keys — which still
contain `consolidat`, so the regex would still catch it. Nothing here is vacuous.

**B2 — does the route-resolution assertion certify something weaker than the 22P02 class?**
**No — it is strictly stronger for this route, and it cannot go green while the 500 returns.**
`DocumentShowRouteUuidConstraintTest:96-107` asserts 404 **and** the absence of an `error` key.
- On **SQLite** (the default test driver): remove the constraint → the request reaches
  `DocumentController::showAny:170-183`, which returns 404 with `error.code = NOT_FOUND` → the
  `assertArrayNotHasKey` fails. **I ran exactly this and it is red.**
- On **PostgreSQL**: remove the constraint → `Document::find('consolidate')` against a `uuid` PK
  raises 22P02 → 500 → `assertNotFound()` fails.
Either driver is red, so no configuration exists in which the 500 returns and this test is green.
The proxy it asserts ("the segment never reaches the controller") is a superset of "no 22P02",
because 22P02 can only arise once the segment reaches the query. A PG-honest test would add nothing
beyond driver coverage. **No change required.**

---

## C. New findings, round 2

### N1 — **P2 — CONFIRMED — `docs/handoff/HANDBACK-dn-consolidation-build-2026-08-12.md`, finding-4 "Note for M5"** — the handback tells M5 that a **live** server branch is dead, and nominates it for deletion

The note reads: *"the transport this removes was `GET /delivery-notes?status=confirmed&uninvoiced=1`
— a query-param shape on the base index route (`DeliveryNoteController.php:298`), not a distinct
endpoint. **That server-side branch is now unused by the web client and is a candidate for the same
triage.**"*

The first sentence is correct. The second is false, and is contradicted by a function **28 lines
above** the one the executor deleted, in the same file:

```
apps/web/src/features/documents/api/deliveryNotes.ts:171-180
  const filterParam = filter === 'all' ? {} : { [filter]: 1 }
  const response = await api.get<PartnerDeliveryNotesResponse>('/delivery-notes', {
    params: { partner_id: partnerId, status: 'confirmed', ...filterParam, page, per_page: perPage, with_aggregates: 1 },
  })
```

With `filter === 'uninvoiced'` — the default lane of the partner "Delivery notes" tab, the M2
surface this wave built — that is exactly `status=confirmed&uninvoiced=1&partner_id=…`, and it lands
on exactly `applyDeliveryNoteFilters` (`DeliveryNoteController.php:298`,
`$request->query('uninvoiced') === '1'` → `whereDeliveryNoteUninvoiced()`). The file's own surviving
green test pins those params (`api/deliveryNotes.test.ts:38-46`).

**Failure scenario:** M5 or a later cleanup reads the handback's own triage note, deletes the
`uninvoiced` branch (or the `uninvoiced` validation key), and the partner delivery-notes tab
silently starts returning invoiced DNs in the "to bill" lane — the lane whose whole purpose is
excluding them. The claim is not hedged as an inference; it is stated as fact about the client.

**Fix directive:** correct the sentence — the `uninvoiced=1` index branch remains **live** via
`getPartnerDeliveryNotes` (`deliveryNotes.ts:171-180`); what M4 removed was only the unpaginated
convenience wrapper over the same shape, so the server branch is **not** a triage candidate.

---

### N2 — **P3 — CONFIRMED — handback "fix round 1" §finding 4 and §verification evidence; commit message `cdec3a5a2`** — the `parseFloat` disclosure and the warning-delta attribution both fail re-derivation, and re-deriving them exposes a real guard blind spot

Three claims, checked:

1. *"`calculateConsolidationTotals` also carried **four** `parseFloat` calls over money."*
   It carried **three** (`git show bbef65232:…/useDeliveryNotes.ts | grep -c parseFloat` → `3`;
   the fourth accumulator, `lineCount`, uses `dn.lines?.length`).
2. *"6,459 warnings — four fewer than round 1's 6,463, **accounted for by the deleted `parseFloat`
   helper**."* The total is right (I ran lint: **0 errors, 6459 warnings**), the attribution is not.
   I linted both changed source files at `bbef65232` and at HEAD: `3 → 1` warnings, i.e. **−2**, and
   the two that disappeared are `@typescript-eslint/no-unnecessary-condition` at old
   `useDeliveryNotes.ts:280` (`dn.lines?.length ?? 0`). **Not one of the four eliminated warnings is
   a precision warning.** The other −2 come from the deleted test code.
3. *"removes a dormant rule-19 violation **that a green test was certifying**."* The violation was
   real, but it was never certified by lint at all — which is the finding worth having:

**`precision/no-parsefloat-on-money` never fired on those three calls, and structurally cannot.**
`apps/web/eslint-rules/no-parsefloat-on-money.js:29-40` — `argName()` returns a name only for an
`Identifier` or a `MemberExpression`. `parseFloat(dn.subtotal ?? '0')` passes a `LogicalExpression`,
so `argName` returns `null` and the rule bails at `:66` before the `MONEY_NAME` test ever runs. The
idiomatic defensive form is invisible to the guard. Repo-wide exposure today, live production
surfaces included: **22 sites**, e.g. `features/documents/DocumentListPage.tsx:199-200,264-265`
(`parseFloat(doc.balance_due ?? doc.total ?? '0')`),
`features/documents/purchase-orders/PurchaseOrderDetailPage.tsx:321-323`,
`features/pos/organisms/TransactionCart/TransactionCart.tsx:321,328`.

Out of the M4 row and pre-existing, so **recorded, not blocking** — but it should be escalated to
the parent rather than buried, because it is a rule-19 guard hole, not a style nit.
**Fix directive (for a later lane):** in `argName`, unwrap `LogicalExpression` (take the left
operand), `ConditionalExpression` (take the consequent) and `TSNonNullExpression` before giving up;
then re-baseline the ratchet.

---

### N3 — **P3 — CONFIRMED — `docs/architecture/frontend.md:89-96`** — the R1-6 fix removed one inaccuracy and asserted two more

The block now reads *"### Documents Feature (**5 pages**)"* and lists `DocumentListPage`,
`DocumentDetailPage`, `DocumentForm`, `DeliveryNoteDetailPage`, `ToBillPage`.

- **`DocumentDetailPage` does not exist.** `grep -rn "DocumentDetailPage" apps/web/src` → zero hits,
  in any form. It was already wrong before this commit; the fix re-affirmed it while editing the
  very lines it sits on.
- **"5 pages" is wrong by 7.** `find apps/web/src/features/documents -name '*Page.tsx' -not -path
  '*__tests__*'` → **12**: the four at the feature root (`CreateCreditNotePage`,
  `CreateReturnNotePage`, `DocumentListPage`, `ReturnNoteListPage`) plus
  `credit-notes/CreditNoteDetailPage`, `delivery-notes/DeliveryNoteDetailPage`,
  `invoices/InvoiceDetailPage`, `purchase-orders/PurchaseOrderDetailPage`,
  `quotes/QuoteDetailPage`, `return-notes/ReturnNoteDetailPage`,
  `sales-orders/SalesOrderDetailPage`, `to-bill/ToBillPage`.

The two additions the executor authored are accurate (`ToBillPage` really is mounted at
`/sales/to-bill` — `routes/index.tsx:580,584` — and the retirement note is correct). This is
CLAUDE.md's nominated "React patterns and components" doc, so a builder reading it is told a
component exists that does not.
**Fix directive:** drop `DocumentDetailPage` and either state the real count (12) or drop the count
from the heading.

---

### N4 — **P3 — CONFIRMED — `apps/web/src/features/partners/PartnerForm.test.tsx:356`** — the headline gate is green only under the serialized pool; the default pool flakes ~50 %

The handback's evidence line is `vitest run … --maxWorkers=1 → 65 files / 533 tests passed`. I
reproduced that **3/3** with `--maxWorkers=1`. Under the **default** pool the same command failed
**2 of 4** runs, always the same case — `PartnerForm — scan-to-document prefill (Task 2) > adds a
bank account on the edit page, derives its IBAN, and submits without blocking`, at
`fireEvent.click(screen.getByRole('option', { name: /Amen Bank CFCTTNTT/i }))` after the preceding
`waitFor` had already found that option.

Localised, so the attribution is honest and not hand-waved: `src/features/partners` alone 3/3 green;
`partners + routes` green (148 tests); `partners + documents` 2/2 green (492 tests). It only appears
with all three directories loaded, i.e. under worker contention — a timing flake, not a logic
defect, and **not introduced by this delta** (no commit in `bbef65232..0b2148760` touches
`PartnerForm.*`; the last was `4d747ce41`).
**Fix directive:** record the flake and the `--maxWorkers=1` dependency in `findings:` so M5 does not
read a default-pool red as a new regression, and so the flake is not discovered during promotion.

---

### N5 — **P3 — CONFIRMED — handback finding-2 survey, and the same list in `progress.yaml`** — every line citation in the survey is off by one at HEAD, and the survey covers 14 of 59 unconstrained bindings in that file

- **Off-by-one.** The survey cites `:292`, `:327`, `:331`, `:335`, `:339`, `:343`, `:348`, `:353`,
  `:358`, `:363`, `:368`, `:372`, `:381`, and the precedents `:279` / `:284`. At HEAD those lines are
  blanks and comments; the real declarations are one lower (`delivery-notes/{deliveryNote}/confirm`
  is `:293`, additional-costs index `:328`, related `:349`, email/queue `:382`; the precedent
  `whereUuid('partner')` is `:280` and `whereUuid('deliveryNote')` is `:285`). Cause is mechanical
  and benign: the citations were taken from the file **before** this round's own one-line insertion
  at `:49`. The fix's own citation (`routes.php:49`) is correct.
- **Scope.** The commit message says *"Other unconstrained uuid params in the same file were surveyed
  and NOT touched"*, which reads as exhaustive. It is not: the file has **59** route-parameter
  bindings with no `whereUuid`, of which the survey lists 14. The 45 unlisted ones carry the same
  22P02 shape — `/quotes/{quote}` (`:58`) and its five siblings, `/orders/{order}` (`:91`) ×7,
  `/invoices/{invoice}` (`:128`) ×15, `/credit-notes/{creditNote}` (`:212`) ×4,
  `/purchase-orders/{purchaseOrder}` (`:237`) ×6, `/return-notes/{returnNote}` (`:307`) ×4, the
  `{cost}` params at `:336`/`:340`, and `/reports/customer-statement/{partnerId}` (`:401`).
Leaving them unfixed is right (the wave did not touch them). Describing the 14 as "the file" is not.
**Fix directive:** re-cite against HEAD and either state the real scope (14 of 59, chosen as the
`documents/*` neighbourhood of the fix) or say plainly that the survey is partial.

---

### N6 — **P3 — CONFIRMED — `apps/web/src/features/documents/delivery-notes/DeliveryNoteDetailPage.tsx:115-123`** — the retired URL now renders a bare error card, and the new test pins that as the intended outcome

Post-fix, `/inventory/delivery-notes/consolidate` resolves to the detail page, `GET
/documents/consolidate` is rejected at the route with a 404, `error` is set, and the page renders its
generic branch: a red box containing `t('common:error')` and **nothing else** — no back link (the
"back to delivery notes" link lives in the success branch at `:129-132`), no not-found wording, no
navigation. Round 1 correctly graded reachability as negligible (zero inbound links, re-verified:
still zero). Recording it because the milestone now ships an **executable test asserting this is
correct**, so it is a deliberate, pinned behaviour rather than an accident, and M5's "no orphaned or
mislinked routes" pass should see it stated. Not a deviation from the spec, which forbade a redirect
or compatibility alias.

---

## D. Gates I ran myself (nothing below is quoted from the handback)

| Gate | Result |
|---|---|
| `pnpm --filter @autoerp/web lint` | **0 errors**, 6459 warnings — matches the claim. `audit:keys` Gate C **0 / 0 acknowledged, 0 new, 0 stale**; `audit:design-system` **728 acknowledged, 0 new, 0 stale**; `audit:quantity` **0 total, 0 new, 0 stale**; all RuleTesters pass |
| `pnpm --filter @autoerp/web typecheck` | clean |
| `vitest run src/routes src/features/documents src/features/partners` (**default pool**) | 533 passed on 2 of 4 runs; 532/1-failed on the other 2 (see **N4**) |
| same, `--maxWorkers=1` | **65 files / 533 tests passed, 3 runs out of 3** |
| `vitest run src/features/documents/delivery-notes/PartnerDeliveryNotesTab.test.tsx` | **14/14** — matches the "was 13, +1 fr case" claim |
| `php artisan test tests/Feature/Document/DocumentShowRouteUuidConstraintTest.php` | **2 passed / 4 assertions** |
| same, **with `->whereUuid('document')` removed** (probe, reverted) | **1 failed / 1 passed** — red with the intended message |
| `php artisan test tests/Feature/Document/DocumentRevertEndpointTest.php` | **4 passed / 10 assertions** |
| `pint --test` on both changed PHP files | `{"result":"pass"}` |
| `phpstan analyse --level=8` on both changed PHP files | `[OK] No errors` |
| Retirement test **with a `delivery-notes/consolidate` route re-introduced before `:id`** (probe, reverted) | **red** |
| Retirement test **with it re-introduced after `:id`** (probe, reverted) | **red** |
| `eslint` on the two changed source files at `bbef65232` vs HEAD | 3 → 1 warnings (see **N2**) |

I did **not** re-run `tests/Feature/Document` by directory (the parent's instruction), nor the
whole-repo §6.3 sweep or React Doctor; those remain executor-recorded evidence for M5 to re-derive,
and R1-7's stale-pin caveat still applies to the baseline they are compared against.

---

## E. Bypasses attempted that FAILED (I tried to break the fix round and could not)

| Attempt | Result |
|---|---|
| Baseline absorption — did the fix round touch any audit baseline? | No. `audit-design-system-baseline.json` is **not in the delta's file list at all**, and the audit reports **0 stale** — proof the deleted code carried no baseline entries that would now dangle |
| Detector-defeating indirection on added lines (`eslint-disable`, `ts-ignore`, `ts-expect-error`, `.skip(`, alias re-export) | None — `git diff … | grep '^+' | grep -Ei …` → empty |
| Did the i18n deletion take a key some surface still renders? | No — per-key grep of all 10 leaves → zero references; the seven surviving `billingRefusal.*` references all resolve in en **and** fr |
| Did removing the `apiGet` mock from `api/deliveryNotes.test.ts` leave a case hitting real axios? | No — no remaining case in that file calls an `apiGet`-based function |
| Did `whereUuid` break a live caller? | No — every backend test interpolates a real id; no `apps/pos` / `apps/mobile` caller; the FE detail page is the only consumer |
| Does the retired URL still reach an unconstrained sub-resource (`/documents/consolidate/related`, `/pdf`…)? | No — `DeliveryNoteDetailPage.tsx:115` returns the error branch before `RelatedDocumentsTab` or any PDF/email hook can fire, so the 14 surveyed unconstrained sub-resources stay unreachable from the retired path. The fix is complete for the path this wave created |
| Did the added fr test leave `i18n` in French and poison later cases in that file? | No — global `beforeEach` at `PartnerDeliveryNotesTab.test.tsx:100-102` resets to `en`; vitest isolates per file, so no cross-file leak either |
| Is the deleted `calculateConsolidationTotals` referenced anywhere (prod or test)? | No — zero references at HEAD; sole historical consumer was the component deleted in `38fdd18f9` |
| Owner rules on the delta (OQ-1 brand strings, OQ-11 dead controls, one-main-element, guarantee copy) | Clean — `grep '^+' … -Ei 'autoerp|syneriva|synerivia|coming soon|disabled=\{true\}'` → empty; the only user-facing copy touched is the fr assertion of existing strings |
| Dead-Tailwind interpolation / raw form controls / raw `<table>` on touched lines | None — the delta adds no JSX beyond two test stubs |

---

## Disposition

The **code** of this fix round is correct, complete and honestly built. All three blocking round-1
findings are closed at the right layer: the retirement now has an executable test whose regression
power I proved twice by re-introducing the route (not by trusting the executor's probe); the route
constraint is the one-line guard round 1 asked for, and its test is red on **both** drivers if the
constraint is removed, so it cannot certify a passing 500; the 20 orphaned strings are gone with no
surviving key lost and no third-locale residue. The two P3s that were closed rather than merely
recorded (the dead exports, the French refusal coverage) were closed properly, with their orphaned
assertions adjusted rather than deleted wholesale. Neither evasion mechanism is present: no baseline
was touched, no suppression added, and the one metric that moved (−4 warnings) moved for reasons I
re-derived from source.

What blocks is not code — it is the record, and this wave has already set the standard. Round 1
blocked in part because the handback narrated the retired path as *"handled"* when it was a
PostgreSQL 500. This round's handback now tells M5 that
`GET /delivery-notes?status=confirmed&uninvoiced=1` is *"unused by the web client and a candidate for
the same triage"* — while `getPartnerDeliveryNotes` (`deliveryNotes.ts:171-180`), 28 lines above the
deletion, sends exactly that shape on the default lane of the partner tab this wave built, pinned by
that file's own green test. A triage note that nominates live code for deletion is the one kind of
documentation error that causes an outage two milestones later, and it costs one sentence to fix
(**N1**). Alongside it, three evidence claims in the same section do not survive re-derivation —
"four `parseFloat` calls" (three), "four fewer warnings accounted for by the deleted `parseFloat`
helper" (zero of the four are precision warnings), and a survey that presents 14 of 59 unconstrained
bindings as "the same file" with every citation one line short (**N2**, **N5**). Re-deriving claim 3
surfaced the finding the parent should actually read: `precision/no-parsefloat-on-money` cannot see
`parseFloat(x.total ?? '0')` at all, and 22 live sites exploit that blind spot today.

N1 must be corrected. N2/N3/N5 should be corrected in the same documentation pass since they are one
line each; N4 and N6 may ship recorded, and N2's guard hole belongs in the parent's backlog, not this
wave's. No code change is required by any finding in this register.

VERDICT: CHANGES-REQUIRED
