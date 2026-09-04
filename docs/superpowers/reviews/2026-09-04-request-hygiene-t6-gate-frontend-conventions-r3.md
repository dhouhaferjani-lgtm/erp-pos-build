# FE gate r3 — Request Hygiene Phase A, Task 6 (debounce bulk pricing context, S-5)

- Date: 2026-09-04
- Gate: frontend-conventions-reviewer (adversarial close-out re-gate after fix round 2)
- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-t6`
- Branch: `lane/rh-t6-pricing-debounce`, HEAD `44d7bf248`, base `5e1e54f69`
- Round 0 `584fd3264` · fix r1 `4ec9db85a` · docs r1 `272247e59` · fix r2 `e514f884e` · docs r2 `44d7bf248`
- Prior reports (committed, unmodified): r1 `…-frontend-conventions.md`, r2 `…-frontend-conventions-r2.md`

## Verdict: **MERGE** — code is approved as-is. Five MINOR doc corrections are owed **in the merge commit**; none touch `apps/web/src`, so **no further gate round**.

M2 is resolved: the production comment, the test name and the test header now state only that the
*query* does not carry data across a key change, name the residual, and put the remount fix before the
dep fix. MINOR-7's companion test is real and green. MINOR-9's plan hygiene is in place. Every re-run
is green and I ran all of them.

What is left is residue of exactly the kind the previous two rounds already flagged: two round-0
sentences that still describe the deleted ref, a cross-reference into a block that was struck, follow-up
line numbers that shifted again when the round-2 comment was added, one escalation the list missed —
and, most interestingly, a delta claim whose own pinned test does not measure it (**MINOR-13**, which
also corrects my r2 PROBE E). None is a code defect and none overstates a guarantee in the user's
favour, so they do not justify a fourth round.

---

## 1. r2 §8 merge conditions — verification

| r2 condition | Ruling | Evidence |
|---|---|---|
| **1. M2** — scope the comment, the test name and the handback to what is proven; residual named, ticket ordered remount-first | **MET** | §2 |
| **2. MINOR-7** — rename the empty-document test, add the non-empty companion, correct §1/§7.3 | **MET on the tests; the corrected claim is itself mis-evidenced** — MINOR-13 | §3 |
| **3. MINOR-8** — strike §3's browser block, refresh §1's test row, no surviving contradiction | **PARTIALLY MET** — the strike and the 34-test row are correct; two round-0 contradictions and one dangling pointer survive — MINOR-10, MINOR-11 | §4 |
| **4. MINOR-9** — plan warnings at T2/T3, T5 struck, shipped occurrences escalated not fixed | **MET for the plan; escalation list is short by one site** — MINOR-14 | §5 |
| **5. Re-runs** | **MET, all executed by me** | §6 |
| B1 / M1 non-regression, baseline claims | **MET** | §7 |

---

## 2. Condition 1 — M2 is resolved

**Production comment.** `apps/web/src/features/documents/components/DocumentLineEditor.tsx:413-428`. The
old absolute claim ("would keep … on screen") is now "would hand … back **as this query's own data**"
(`:417-419`), followed by an explicit scope paragraph (`:422-428`): *"this removes the QUERY's own
cross-key carry-over. It does NOT by itself prove nothing stale can be on screen"*, naming `lineColumns`,
`LineItemsTable`'s component-typed cells, and the ordering — *"the remount must be fixed BEFORE the deps
are added, or every pricing answer drops focus mid-typing"* (`:427-428`). That is exactly r2 fix
directive (a), and the order is the one M2 required.

**Test name and header.** `__tests__/DocumentLineEditor.test.tsx:1174` is now
`does not carry the previous company pricing across the company key change` — a statement about the
query, not about the screen. The header `:1160-1173` states what the test proves, states what it does
**not** prove, cites the residual's two mechanisms, points at "FE gate r2 M2 + MINOR-3", and records why
this suite cannot see it (`t` mock = fresh closure per render). The assertions themselves are unchanged
(`:1215-1220`) and still cover both the display path and the `Pricing details` commit path.

**Handback.** §7.5's `KNOWN RESIDUAL (gate r2 M2)` row (`docs/handoff/HANDBACK-request-hygiene-T6-2026-09-04.md:373`)
carries the mechanism, "same ticket as MINOR-3", "measured identical at base `5e1e54f69`, so not a T6
regression", and the remount-first ordering. §8.1 (`:398-408`) reproduces PROBE D verbatim, keeps the
`CreateCreditNotePage` vs `DocumentForm` exposure distinction, and states option (b) is a separate lane.
**Accurate and sufficient.** §7.6 item 3 (the company-switch browser check) is retained (`:384`).

**No new overstatement.** I re-read every claim added in `e514f884e`; each is either something I
measured myself in r2 or a scope reduction. The one line-number defect is MINOR-12 below.

---

## 3. Condition 2 — MINOR-7

**The rename is in place** (`:1233`, `…when the first line is added…`) with an NB at `:1230-1232`
pointing at the companion. **The companion test exists** (`:1286`,
`costs one extra settling request when a line is added to a document that already has priced lines`)
and is green — I ran it.

**It measures what its assertion says.** It asserts the full ordered signature list built by the
type-guarded `signatureOfRequestBody` (`:212-225`) — `['prod-A::5.000', 'prod-A::5.000|prod-B::10.000']`
— not a bare call count, so a change in either the number of requests or the *content* of either body
fails it. As a drift pin it works.

### MINOR-13 — the companion test does not pin the delta the handback attaches to it; my r2 PROBE E number was sequence-specific

`docs/handoff/HANDBACK-request-hygiene-T6-2026-09-04.md:30` (§1 "Honest cost") and `:410-417` (§8.2)
state that this path "costs **2** requests **where the base cost 1**" and present the companion test as
the pin for that. I ran the committed test unchanged against all three component states (component
checked out from git, test file at HEAD, component restored after each — `git status --short` empty at
the end):

```
HEAD           44d7bf248 : ✓ 1 passed | 33 skipped
base           5e1e54f69 : ✓ 1 passed | 33 skipped
round 0        584fd3264 : ✓ 1 passed | 33 skipped
```

The test **passes unchanged at base and at round 0**, i.e. under the sequence it implements, base also
costs 2 requests with the identical two signatures — there is no HEAD-vs-base delta on this path. The
reason is sequencing, and it reconciles both measurements:

- **Sequence A (the committed test):** focus, `expect(apiPost).toHaveBeenCalledTimes(1)` forces the
  first fetch to dispatch, *then* add the line. Base has no debounce at all, so the add moves the key
  immediately → base = 2. HEAD's debounce moves it 250 ms later → HEAD = 2. **No delta.**
- **Sequence B (my r2 PROBE E):** the add lands before the first fetch dispatches. At base the key is
  already the two-line signature when the observer subscribes → **1** request carrying both lines
  (which is why PROBE E's base signature list was the single `prod-A::5.000|prod-B::10.000`). At HEAD
  the seeded debounce means the focus fetch goes out with the old line set and a second follows → **2**.
  **Delta of +1, but only in that window.**

So the extra request is **real but timing-dependent**, and the lane's own pinned test measures the
sequence in which it does not occur. Fix directive: re-scope §1's "Honest cost" bullet and §8.2 to
*"adding a line to a non-empty document costs 2 requests; the base cost 2 under the same sequence and 1
only when the add lands before the first fetch dispatches (r2 PROBE E) — the committed test pins the
2-request shape, not a base delta"*, and add that sentence to the test header at `:1279-1285`. This
correction is against my own r2 measurement, which the lane transcribed faithfully.

Also worth noting, non-blocking: §7.3 gives RED evidence for three new tests; the fourth (this
companion) ships with no falsification block, which is consistent with the finding above — there is
nothing it can falsify.

---

## 4. Condition 3 — MINOR-8

**Done correctly:** §3's browser block is struck in place with the reason and a pointer to §7.6
(`:185-196`), and §1's test-file row is refreshed to *"File total: **34** tests (29 pre-existing +
5 new)"* (`:22`). I counted the `it(` declarations in the file: **34**, and the suite reports 34. Correct.

### MINOR-10 — two round-0 sentences still describe the deleted ref, one of them inside §1 itself

- `…HANDBACK…:33` (§1 Behaviour delta, rule-19 bullet): *"the **ref** carries the same
  `PricingContextLineRequest[]`"*.
- `…HANDBACK…:272` (§6 item 5): *"signature, **ref payload** and the asserted `unit_price: '125'`"*.

`pricingContextLinesRef` was deleted in fix round 1 (`grep -c pricingContextLinesRef` on the component =
**0**), and §1's own first row eleven lines earlier says *"**No ref.**"* (`:21`). §6 item 3 was struck for
exactly this, item 5 was missed. Fix directive: drop "the ref carries…" / "ref payload" from both bullets.

### MINOR-11 — §6 item 6 points the reader at the block that was just struck

`…HANDBACK…:273`: *"Browser checks are owed at promotion, not at merge (**§3, last block**)"*. §3's last
block is now the struck one. Fix directive: repoint to §7.6, the live list.

---

## 5. Condition 4 — MINOR-9 (plan hygiene + escalation)

**Plan is correct.** Every live occurrence of the pattern in
`docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md` is accounted for:

| Occurrence | Disposition |
|---|---|
| `:850` (Task 2 Step 6 snippet) | Warned by the plan-wide block at `:813` ✓ |
| `:1099` (Task 3 Step 5 snippet) | Warned by the identical block at `:1078` ✓ |
| `:1662` (Task 5 Step 3) | Struck in place, with the as-shipped `LineItemEntryBar` behaviour named ✓ |
| `:1783` (Task 6) | Inside the rejected-history `<details>` at `:1764-1786` ✓ |
| `:3726` | Gate-r1 *disposition log* row ("T3 … adds … keepPreviousData"), not a prescription — **NIT**, the step it refers to is now warned in place |

Both warning blocks are byte-identical, cite the mechanism, the three code anchors and the two lanes
that removed the option, and end with the actionable rule. **The two shipped occurrences are escalated,
not silently fixed** — `PaymentListPage.tsx:117` and `StockMovementsPage.tsx:190` appear in the commit
message of `44d7bf248` and in handback §8.4 (`:424-429`), flagged as different lanes, with the
committability question left open rather than assumed. Neither file is in the lane diff. Correct.

### MINOR-14 — the escalation list is short by one shipped site

`grep -rn "placeholderData" apps/web/src` (excluding `__tests__`) returns a **third**
tenant-scoped read that §8.4 does not list:

```
apps/web/src/features/pos/hooks/useDiscountPreview.ts:87   placeholderData: keepPreviousData,
apps/web/src/features/pos/hooks/useDiscountPreview.ts:83   queryKey: tenantScopedKey(['pos', 'discount-preview', debouncedRequest]),
```

Same key shape, same class: `tenantScopedKey` suffixes do not protect a placeholder, and this one feeds
a cart discount **breakdown** rather than a list. Whether the previewed breakdown can be acted on before
the new answer lands is the lane's question, not an assumption. My r2 list missed it too. Fix directive:
add it to §8.4's escalation list as a third site.

---

## 6. Condition 5 — re-runs (all executed by me in this worktree)

| Check | Command | Result |
|---|---|---|
| typecheck | `pnpm typecheck` | **0 errors**, exit 0 |
| eslint, 2 touched files @ HEAD | `npx eslint <both>` | **0 errors, 15 warnings** (2 + 13) — same rules and counts as the `5e1e54f69` baseline I re-measured in r2: `restrict-template-expressions 280:32`, `react-hooks/exhaustive-deps 1120:6`, 10 × `no-base-to-string`, `unbound-method 428:15`, 2 × `require-await`. **No new errors, no new warnings** |
| touched dir | `npx vitest run src/features/documents/components/__tests__/` (DEFAULT pool) | **10 files / 97 tests passed**, `DocumentLineEditor.test.tsx (34 tests)` — matches handback §8.5 |
| feature tree | `npx vitest run src/features/documents/` | **53 files / 453 tests passed** — matches §8.5. See the flake NIT below |
| touched file, repeated | same file × 10 consecutive runs | **34/34 passed, 10/10 runs** |
| `audit:keys` | `pnpm audit:keys` | `Gate C … without an approved tenant scope: 0`; `0 acknowledged, 0 new, 0 stale` |

**Flake NIT (not attributable to this lane).** My **first** `src/features/documents/` run reported
`1 failed | 452 passed (453)`; I did not capture the failing name before it scrolled. I then ran the
tree **12 more times** (including once with `node_modules/.vite` cleared) — **all 53/453 green** — and the
touched file 10/10 in isolation. It is not in `DocumentLineEditor.test.tsx`. Recorded honestly as an
unattributed one-off, most likely a load/timeout flake elsewhere in the tree; promotion should watch for it.

**Full lint** (`pnpm --filter @autoerp/web lint`) is unchanged and still inherited-RED
(`audit:design-system` 14 new + 11 stale, all in `src/features/import/pages/ImportWizardPage.tsx`). r1
MINOR-5 / handback §7.5 stand: **the branch cannot claim a green full-lint gate at promotion.**

---

## 7. B1 / M1 non-regression and baseline re-verification

**The query itself is untouched since fix round 1.** `git diff 4ec9db85a..HEAD -- …/DocumentLineEditor.tsx`
is **comment-only** — six lines rewritten and nine added inside the options object at `:413-428`. `queryKey`
(`:403-407`), `queryFn` (`:408-411`), `enabled` (`:412`) and `staleTime` (`:429`) are byte-identical.
So B1 and M1, which I re-falsified myself in r2, cannot have regressed.

- **B1:** no `placeholderData` / `keepPreviousData` in the file except the anti-regression comment (`:413`).
- **M1:** one debounced value feeds all three consumers — `:388` `useDebouncedValue(pricingContextLines, 250)`,
  `:390` signature, `:398` `enabled`, `:410` body.
- **Diff surface:** 6 files, 2 code + 4 docs. **Zero `apps/api/` files.** `DocumentForm.tsx`,
  `CreateCreditNotePage.tsx`, `ProductController.php`, `LineItemsTable.tsx`, `useDebouncedValue`,
  `tenantScopedKey` and every Inventory Counting file untouched.
- **`apps/web/tools/audit-design-system-baseline.json` not in the diff** — no `--write-baseline` absorption.
- **Mechanism audit:** added lines contain no `parseFloat`, no `Number(`, no `: any` / `as any`, no
  `eslint-disable`, no `@ts-`, no `.skip(` / `.only(` / `xit(`. Rule 19 holds (all money strings).
- **Key shape unchanged:** `tenantScopedKey(['line-entry-pricing-context', partnerId ?? null, <signature>])`
  — same root, same arity, tenant/company still the suffixes. `audit:keys` = 0.
- **Rule 22:** the lane introduces no noun, no table, no write path, no second surface — the
  second-of-everything leg that applies (second **company**) is the test at `:1174`. N/A otherwise.

### r1/r2 findings — final status

| Finding | Status |
|---|---|
| **B1** `placeholderData` | Fixed and unchanged since `4ec9db85a` |
| **M1** key/body incoherence | Fixed and unchanged since `4ec9db85a` |
| **M2** overstated company-switch guarantee | **Resolved** — claims scoped, residual named and ticketed remount-first (§2) |
| MINOR-1 / MINOR-4 / MINOR-6 | Fixed (MINOR-4 partially re-broken → MINOR-12) |
| MINOR-2 | Accepted, unchanged |
| MINOR-3 | Deferred, ticketed, now merged with M2 into one ticket — correct |
| MINOR-5 | Inherited full-lint RED, unchanged, promotion-owed |
| MINOR-7 | Tests shipped; claim mis-evidenced → **MINOR-13** |
| MINOR-8 | Strike + 34-test row done; residue → **MINOR-10**, **MINOR-11** |
| MINOR-9 | Plan done; escalation short by one → **MINOR-14** |

---

## 8. Findings, ranked

**BLOCKER:** none. **MAJOR:** none.

| # | Severity | Location | Fix directive |
|---|---|---|---|
| MINOR-10 | MINOR | `docs/handoff/HANDBACK-request-hygiene-T6-2026-09-04.md:33` and `:272` | Delete the "the ref carries…" / "ref payload" phrases — the ref was deleted in fix round 1 and §1:21 already says "No ref." |
| MINOR-11 | MINOR | `…HANDBACK…:273` (§6 item 6) | Repoint "§3, last block" to §7.6; §3's block is struck |
| MINOR-12 | MINOR | `…HANDBACK…:158`, `:361`, `:373`, `:398` | Re-measure the shifted anchors: the `lineColumns` deps are now `DocumentLineEditor.tsx:1120-1135` and the eslint warning is `1120:6` (round-2's comment added 9 lines). The M2/MINOR-3 ticket currently points 9 lines off |
| MINOR-13 | MINOR | `…HANDBACK…:30` (§1 "Honest cost") and `:410-417` (§8.2); test header `__tests__/DocumentLineEditor.test.tsx:1279-1285` | Re-scope the claim: the companion test passes unchanged at HEAD, base **and** round 0, so it pins the 2-request shape, not a base delta; the +1 exists only when the add lands before the first fetch dispatches (r2 PROBE E). Corrects my own r2 number |
| MINOR-14 | MINOR | `…HANDBACK…:424-429` (§8.4) vs `apps/web/src/features/pos/hooks/useDiscountPreview.ts:83+87` | Add the third shipped `placeholderData` on a `tenantScopedKey` read to the escalation list |
| NIT-1 | NIT | `docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:3726` | Gate-r1 disposition row still names `keepPreviousData` for T3 unannotated; historical log, the live step is warned — optional |
| NIT-2 | NIT | `src/features/documents/` suite | One unattributed failure in 1 of 13 full-tree runs (452/453); 12 subsequent runs incl. cold-cache green, touched file 10/10. Watch at promotion |

---

## 9. Verdict

**MERGE.** The two code files are approved as they stand; I re-ran every gate myself and re-falsified
the one new test against both prior component states. MINOR-10 … MINOR-14 are **documentation-only**
corrections and must be folded into the merge commit (or a doc commit immediately preceding it) — none
of them touches `apps/web/src`, so **no fourth gate round is required**; the merger confirms them.

Still promotion-owed, unchanged: the §7.6 browser pass on purchase order and credit note (item 3 is the
only check that can catch the M2 residual), the MINOR-3 + M2 follow-up ticket (**remount first, deps
second**), the three escalated `placeholderData` sites, and reconciliation of the inherited full-lint RED.

_Nothing was committed, merged or pushed. The component was checked out at `5e1e54f69` and `584fd3264`
for falsification and restored from a scratchpad copy each time; `git status --short` and
`git diff --stat` are both empty at the end of this review._
