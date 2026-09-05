# Gate r1 — Request-hygiene Phase A browser-evidence deliverable (2026-09-05)

Reviewer: frontend-conventions adversarial gate (read-only; no file was modified, no Playwright run was started).
Subject (all uncommitted):

- `apps/web/e2e/request-hygiene/phase-a-evidence.spec.ts` (2055 source lines, 31 tests)
- `apps/web/e2e/request-hygiene/support.ts`
- `apps/web/e2e/request-hygiene/pw.config.ts`
- `docs/superpowers/reviews/2026-09-05-request-hygiene-phase-a-browser-evidence.md` (the evidence doc)
- Run log `<scratch>/rh-browser/full-run.log`, ledger `<scratch>/rh-browser/evidence/ledger.json`, 27 screenshots

Repo `/Users/houssamr/Projects/syneriva/apps/erp`, branch `dev`, HEAD at review time `a622d7e97` (the branch moved past the doc's `fa000edc3` while this gate ran; see MIN-8).

## VERDICT: **REJECT** (fix + re-run required before the spec and the evidence doc can be committed)

Nothing in the deliverable is fabricated. I diffed the doc's 31 evidence rows against the machine-generated `ledger.json` table: **byte-identical, zero differences** — no measured number was hand-edited. The measurements that *are* asserted hold up, and several legs (RH-T2-b, RH-T7-a, RH-PLACEHOLDER-a/b, RH-T12-d) are model data-meaning probes.

It is nonetheless a REJECT, on three blockers:

1. The doc's central method claim — "every leg … asserts both are empty" — is **false for 10 of the 31 legs**, and one of them (`RH-T6-b`) passed while emitting **2 console errors that are not in the named-tolerance list**. That is the owner rule this deliverable exists to satisfy.
2. **7 of 31 ledger rows report `5xx` (and 2 of them `console errors`) as hardcoded literals**, not measurements — presented in the table identically to the measured rows.
3. The claim "`pnpm typecheck:e2e` covers the new directory and is green" is **false**: `e2e/tsconfig.json` includes only `campaign/**`, and ESLint ignores `e2e/*`. The new files are currently guarded by nothing. (They *do* typecheck clean when actually included — I verified this.)

Blockers 1 and 2 cannot be fixed by editing prose alone: adding the missing assertions changes what the run proves, so the spec must be corrected and **re-run** before the ledger can be published as "confirmed working".

---

## 1. Per-test verification table

Line numbers are **source** lines (`wc -l` = 2055). Note that Playwright's `:NNNN` in `full-run.log` are *compiled-JS* offsets (the cached transform is 2638 lines) — I verified `--list` reproduces the log's numbering exactly, so the log and the file under review are the same version, but the log's line numbers cannot be used to locate a test.

Legend: **Claim proven?** = does an `expect` enforce the claim in the evidence row (as opposed to merely printing it). **Guard asserted?** = is the 5xx/console capture asserted empty (owner rule).

| # | Leg (src line) | Claim in the evidence row | Claim proven? | Guard asserted? |
|---|---|---|---|---|
| 1 | `RH-SETUP` (142) | reused tenant is alive, `/auth/me = 200` | **Y** — `expect(me.status).toBe(200)` :149 | **N** — reuse branch returns at :156 before `captureGuards` :158; `fivexx: 0, consoleErrors: 0` hardcoded :154 |
| 2 | `RH-T12-a` (285) | 1 POST, 1 distinct body key, 1 payment row | **Y** — key on the **body** (correct: `PaymentForm.tsx:773`, no `Idempotency-Key` header exists in `src/`), distinct keys :331, psql count on a fresh per-run reference :330 | **Y** :329 |
| 3 | `RH-T12-b` (334) | forced 500 → unchanged retry replays the SAME key, 1 row | **Y** :379-381 | **N** — no assert; `fivexx: 0` hardcoded :377 |
| 4 | `RH-T12-c` (384) | forced 500 → amount edit rotates the key, 1 row | **Y** for rotation :434 and the row count :435; the "operator edited 5.000→6.000" premise is printed, not asserted (`amounts` :420 unused by any expect) | **N**; `fivexx: 0` hardcoded :431. Also deletes a `forcedConsole` entry it never added :426 |
| 5 | `RH-T12-d` (438) | "12.345" survives to the wire and to the DB | **Y** :464-465 (wire string + psql string) | **Y** :463 |
| 6 | `RH-SETUP-2` (474) | reused PO/warehouse/products | **N** — the reuse branch :476-484 asserts nothing; it trusts `state.json` without re-probing that the PO row still exists | **N** — hardcoded `0/0` :481 |
| 7 | `RH-T12-e` (563) | two modal opens ⇒ two DIFFERENT keys, both submits forced 500, 0 payments | **Y** for rotation :607-608; the "0 payments" delta is computed :596 but **never asserted** | **N**; `fivexx: 0` hardcoded :605 |
| 8 | `RH-T13-a` (633) | 1 POST, 1 key, exactly 1 `stock_transfers` row (delta) | **Y** :668-669 | **Y** :667 |
| 9 | `RH-T13-b` (685) | 1 POST, 1 key, exactly 1 `stock_adjustments` row (delta) | **Y** :719-720 | **Y** :718 |
| 10 | `RH-T13-c` (723) | forced 500 renders the generic toast, no raw exception, no navigation | **Y** — toast visible :745, DOM free of `SQLSTATE`/`QueryException`/path :759, still on `/new` :760 | **N**; `fivexx: 0` hardcoded :757 |
| 11 | `RH-SETUP-3` (774) | 55 payments / 43 movements / wildcard bait exists | Counts are printed, not asserted (no `>= 30` expect); the bait products are created under `expect(...).toContain(status)` on the seed path | **Y** — `assertClean()` :891 |
| 12 | `RH-T3-a` (908) | `page=1&per_page=25`, page 2 matches `meta`, labels match | **Y** :941-943 + the rendered `Page 2 of N` assertion :927 | **Y** :940 |
| 13 | `RH-T3-b` (946) | dashboard sends `page=1&per_page=5`, renders 5 rows | **Y** for `per_page` :970 and the 5 links :971; `page=1` only in `ok`, not asserted | **Y** :969 |
| 14 | `RH-T3-c` (974) | `%`/`_` NOT escaped: totals 9 / 12 / 1 | Deliberate measurement: only the control (`=1`) is asserted :1013; the 9/12 are the finding's evidence (documented at :1014-1016). Ledger result = **FAIL** while the test is green — honest, but see MAJ-3 | **Y** :1012 |
| 15 | `RH-T2-a` (1023) | server-side search/filters, `ESCAPE '!'` holds, global totals on page 2, blank filter = 200 | **Y** for the decisive ones — wildcard bait `RH%UND` must return 0 :1120, blank probe = 200 :1119, header total identical on page 2 :1121 | **Y** :1118 |
| 16 | `RH-T2-b` (1124) | 1 Reverse button = 1 reversible movement in psql, 4 write-off-reason non-issue rows have none | **Y** :1150 — rendered count `===` psql count. Best assertion in the file | **Y** :1149 |
| 17 | `RH-SETUP-4` (1157) | second company + a product that exists **in company 2 only** | Partly — creation is asserted; the *"only"* half has no negative probe against company 1 | **Y** — `assertClean()` :1184 |
| 18 | `RH-T14-a` (1208) | no two autosaves overlap; "the last body is the one persisted" | Serialization **Y** :1277 and last-wire-body **Y** :1278. The scenario string is **contradicted by its own psql** (`documents.notes = "RH-T14 body 1"`) — see MAJ-4. `saves.length` is not asserted, so a single-save run would satisfy "no overlap" vacuously | **Y** :1276 |
| 19 | `RH-T14-b` (1281) | failed autosave → retry carries the LATEST body | **Y** :1326-1327 | **N**; `fivexx: 0` hardcoded :1324 |
| 20 | `RH-T5-a` (1347) | 6 keystrokes → 1 search on 3 consumers; 4th BLOCKED | **Y** for the three measured :1394. The BLOCKED reason is **wrong** — see MAJ-1 | **Y** :1393 |
| 21 | `RH-T5-b` (1733) src :1397 | 3 chars + Enter → `resolve-code` in 36 ms | **Y** :1427, and `waitForResponse` :1411-1414 guarantees the response existed. Weak spot: `resolveWire?.startedAt ?? Date.now()` :1416 would synthesise a ~0 ms latency if the wire were missing | **Y** :1426 |
| 22 | `RH-T5-c` (1430) | company-A suggestion not offered under company B | **Y** :1464 (`count() === 0`). Scope note: proves *not offered*, not *not applicable* | **Y** :1463 |
| 23 | `RH-T7-a` (1471) | 1 stock-level read per DISTINCT product (1 / 1 / 2) | **Y** :1505-1507 — all three arms asserted | **Y** :1504 |
| 24 | `RH-T6-a` (1550) | ≤2 pricing POSTs per 6-edit burst on both editors; last price wins | **Y** :1577-1579; `creditNote.lastUnitPrice` only in `ok`, not asserted | **Y** :1576 |
| 25 | `RH-T6-b` (1582) | no pricing request under the OLD company after a switch | **Y** :1619 | **N — the actual violation.** Only `fivexx` is asserted :1618; the leg emitted **2 console errors** (fonts `ERR_NETWORK_CHANGED`, and the 422 on the pricing endpoint) and still reports PASS. Neither is in the doc's named-tolerance list |
| 26 | `RH-T4-a` (1626) | audit bounds 50/100/422/page-2/92-day | **Y** :1663-1667 | **N** — `guards.stop()` :1651, no assert |
| 27 | `RH-T4-b` (1670) | `limit` clamps into 1..100 | **Y** for 5000→100 and 0→1 :1696-1697; `limit=5 → per_page 5` only in `ok` | **N** — no assert |
| 28 | `RH-PLACEHOLDER-a` (1735) | 601 rAF frames, 0 leaking frames, marker gone after switch | **Y** :1765 (frame-level), plus the pre-switch marker assertion :1743 | **Y** :1764 |
| 29 | `RH-PLACEHOLDER-b` (1768) | 599 frames, 0 leaking frames | **Y** :1796 | **Y** :1795 |
| 30 | `RH-T8-a` (1815) | initial connect 0 sweeps, explicit reconnect 1, second inside cooldown 0 | **Y** :1925-1927. The two peer-close deltas (the F-RH-4 evidence) are reported, correctly not asserted | **N** — no assert |
| 31 | `RH-T12-f` (1943) | lost-but-committed payment ⇒ banner appears, intent rotation clears it | **Y** — upstream really 2xx :2023, banner visible :2035, hidden after `#payment-date` edit :2040, exactly 1 committed row for the key prefix :2054 | **N** — no assert |

**Guard tally: 21 of 31 assert, 10 do not** (`RH-SETUP` reuse, `RH-SETUP-2` reuse, `RH-T12-b`, `RH-T12-c`, `RH-T12-e`, `RH-T13-c`, `RH-T14-b`, `RH-T4-a`, `RH-T4-b`, `RH-T8-a`, `RH-T12-f` — 11 entries, of which `RH-T6-b` asserts 5xx only, so 10 legs assert neither or only half).

**Hardcoded ledger counters (`fivexx`/`consoleErrors` written as literals rather than `counts.*`):** :154, :481 (both counters); :377, :431, :605, :757, :1324 (`fivexx: 0`). Seven of 31 rows.

**No vacuous-pass patterns of the dangerous class**: no `expect(true)`, no `test.skip`/`only`/`fixme` (grepped: zero hits), no swallowing `?.` in a load-bearing assertion, no `.first()` used where a count claim is made (`RH-T2-b` and `RH-T3-b` both use `.count()`). The three soft spots are listed as MIN-2/MIN-3.

---

## 2. Run-log cross-check

| Check | Result |
|---|---|
| Doc table vs `ledger.json` | **31/31 rows byte-identical** (diffed programmatically) — no hand-editing |
| `31 passed (10.5m)` | Confirmed; leg durations sum to ~624 s and the ledger timestamps span 12:21:09.412 → 12:31:27.805 UTC |
| Retries / workers | `retries: 0`, `workers: 1`, `fullyParallel: false`, serial mode — `pw.config.ts:21-24` + `spec:45`. Confirmed |
| Skipped / fixme | None declared and none in the log |
| Ledger verdicts vs "31/31" | **Mismatch** — 29 PASS + 1 **FAIL** (`RH-T3-c`) + 1 **BLOCKED** (`RH-T5-a`). See MAJ-3 |
| Run window | Log 12:21:09 → 12:31:27 UTC; doc says "12:22 → 12:32" (MIN-9) |
| Spec version vs log | Same version. Playwright's `:NNNN` are compiled-JS offsets; `--list` on the current file reproduces the log's numbering exactly (I ran it) |
| Screenshots | 27 PNGs, mtimes 13:22–13:31 CET = the run window in UTC+1. Consistent |
| Fresh-vs-reused tenant | `RH-SETUP`, `RH-SETUP-2` took the **reuse** path; `RH-SETUP-3`/`-4` ran their guard. Disclosed in the rows, but doc header :15 says the tenant was "registered by the spec" (true of an earlier invocation, not this run) |

---

## 3. BLOCKED-reason verification

| Reason | Verdict | Evidence |
|---|---|---|
| (a) T5 4th consumer `CreateCountingPage` unreachable | **PARTLY TRUE — reason as written is REFUTED** | The entry bar *is* behind the wizard: `CreateCountingPage.tsx:407` sits inside `ProductSelectionStep`, rendered only when `currentStep === 'selection' && needsSelectionStep()` (`:219-226`, scope predicate `:63-70`). But the leg failed on its own selector: the scope tile is a `<button>` whose accessible name is title **+ description** — "Product Count specific products across all locations" (`CreateCountingPage.tsx:303-316` with `locales/en/inventory.json:663` + `:671`), so `getByRole('button', { name: /^Product$/ })` (`spec:1342`) can never match, and `arm()`'s failure is swallowed (`spec:1361`). The consumer is reachable with a corrected selector |
| (b) POS discount preview is Tauri-only; `apps/web` has no `POSPage` route | **CONFIRMED** | `POSPage` is exported at `features/pos/index.ts:40` and referenced only by its own test file; `routes/index.tsx` never imports it (it imports `PosHubPage:102`, `ReceiptListPage:290`, `OrdersPage:293`, `KitchenDisplayPage:294`, …). The cited `routes/index.tsx:2938-2939` comment ("Receipt register is read-only. New-sale authoring … remain retired") is accurate context |
| (c) `GET /audit/events` has no FE consumer | **CONFIRMED** | Zero hits for `audit/events|auditEvents|audit-events` anywhere in `apps/web/src`; the only audit page calls `/admin/audit-logs` (`features/admin/api/index.ts:143`) |
| (d) `echo.ts` derives WS host/port from `window.location` | **CONFIRMED** | `lib/echo.ts:27-29`: `wsHost: window.location.hostname`, `wsPort/wssPort: window.location.port …`. No `VITE_WS_HOST`/`VITE_WS_PORT` anywhere in `apps/web` |
| (e) `SplitPaymentForm` has no route | **CONFIRMED** | Referenced only by `features/treasury/index.ts:8` and test files; `SplitPaymentModal` is marked DEPRECATED at `components/organisms/index.ts:18-21` and is imported by no routed page |

---

## 4. Findings verification (F-RH-1 … F-RH-6)

| # | Verdict | Verification | Severity call |
|---|---|---|---|
| **F-RH-1** LIKE escaping missing in payment search | **CONFIRMED** | `PaymentController.php:283-290` — `$pattern = '%'.$search.'%'` then `where('reference','like',$pattern)` with no `ESCAPE`. Contrast `StockMovementController.php:79-93`, which escapes `!`,`%`,`_` and uses `LIKE ? ESCAPE '!'`. Measured 9 / 12 / 1 against a 0 / 0 / 1 expectation | Agree **MAJOR (P2)**, T3 lane. Wrong-superset results, no mutation |
| **F-RH-2** draft autosave never persists header edits | **CONFIRMED, and it is a NEW finding** | `DraftPersistenceService.php:135-136` — "Update existing draft (lines only, header is immutable for now)" → `updateDraftLines()`. Measured: five 200-answered autosaves carrying bodies 1..5, `documents.notes` stuck at body 1. I grepped the Phase-A plan and the night handover for "header is immutable"/"lines only": **no mention**, so this is not a declared residual | **Raise to MAJOR**, not P2: silent operator data loss while the UI reports "saved". Document lane |
| **F-RH-3** company switch replays company-1 product ids under company 2 (422) | **CONFIRMED as measured** (`RH-T6-b`: 2 POSTs, `X-Company-Id` = company 2, status 422) | The write-up carries **no product-code `file:line`** for the replay path — it should cite the editor that keeps its lines across a scope change (`DocumentLineEditor` `pricingContextLines`) so the receiving lane can act | P3 agreed; add the citation |
| **F-RH-4** gap-recovery sweep never fires on an unexpected drop | **CONFIRMED at code level and empirically** | `useWebSocketConnection.ts` binds `connected` (~:99), `disconnected` (~:110), `error` (~:120); `unavailable` only increments `failedAttempts` (~:133) and never clears `isConnected`. `WebSocketReconnectProvider.tsx:56` keys off `isConnected`. Trail: peer close ⇒ `connected→connecting→connected`, 0 refetches | **PRE-EXISTING, not introduced by T8.** The hook's last change is `bc1dd4faf` (2026-07-02); the provider's dependence on `isConnected` dates to `623dfbc2b` (2026-03-16); T8's three commits (`26d971bb4`, `f5b8eafb9`, `e56b6b6a7`, 2026-09-04) only reworked the cooldown. The doc's "Owner lane: T8 / promotion-relevant" reads as a T8 regression and must say "inherited". See MAJ-7 for the generalisation caveat |
| **F-RH-5** list subtitles never pluralise | **CONFIRMED but MIS-ROOTED** | The plural form **exists**: `locales/en/inventory.json` `movements.subtitle_plural`. It is dead because i18next v4 JSON uses the `_other` suffix and `lib/i18n.ts` sets no `compatibilityJSON: 'v3'`. There are **15 `_plural` keys** across `locales/en/*.json` (vs 31 correct `_other`) | Not "cosmetic, no lane": a 15-key systemic class with a mechanical fix (`_plural` → `_other`). Keep P4 severity, correct the root cause and the scope |
| **F-RH-6** PO detail offers Record Payment against an endpoint that always refuses | **CONFIRMED in code; NOT captured in the evidence** | FE: `PurchaseOrderDetailPage.tsx:325` (`canRecordPayment` on `confirmed`/`received` + outstanding) and the modal at `:709`. BE: `AllocationRefusalReason.php:70` `purchase_order_wrong_direction` → `lang/en/treasury.php:31`, verbatim the string quoted in the doc. **But no leg probes it** — the quoted 422 body appears nowhere in `full-run.log` or `ledger.json` | Valid **MAJOR (P2 UX)**; the *evidence* for it is missing (MAJ-6) |

---

## 5. Findings against the deliverable

### BLOCKERS

**BLK-1 — The doc's guard claim is false for 10 of 31 legs, and one leg passed with unexplained console errors.**
Evidence doc `:21` states "Every leg registers … and asserts both are empty… Every other 5xx or console error fails its leg." Not true for `RH-SETUP` (reuse), `RH-SETUP-2` (reuse), `RH-T12-b/c/e`, `RH-T13-c`, `RH-T14-b`, `RH-T4-a/b`, `RH-T8-a`, `RH-T12-f`; and `RH-T6-b` (`spec:1618`) asserts only `fivexx`, so it reported **PASS with `console-errors=2`** — a Google-Fonts `ERR_NETWORK_CHANGED` and a 422 on `/line-entry/pricing-context/bulk`, neither of which is in the doc's named-tolerance list (a)-(d).
*Fix:* add `expect(counts.detail, 'forbidden 5xx/console errors').toBe('[]')` to each of the 10 legs; for `RH-T6-b`, either register the two signals as named leg-scoped tolerances (`forcedConsole`) and then assert zero, or accept them as findings and stop calling the leg PASS. Then re-run.

**BLK-2 — Seven ledger rows report unmeasured constants in the 5xx / console columns.**
`spec:154` and `:481` write `fivexx: 0, consoleErrors: 0` with **no guard installed at all** on the reuse paths; `:377`, `:431`, `:605`, `:757`, `:1324` write `fivexx: 0` instead of `counts.fivexx`. Because `forced5xx` already filters the deliberate 500s, `counts.fivexx` is the honest value — and a *real* 5xx on any other endpoint during those legs is currently reported as 0.
*Fix:* pass `counts.fivexx` / `counts.consoleErrors` everywhere; install `captureGuards` before `restoreSession()` in both SETUP reuse branches and use `assertClean()`. Re-run.

**BLK-3 — "`pnpm typecheck:e2e` covers the new directory" is false; the files are guarded by nothing.**
`apps/web/e2e/tsconfig.json` `include` is `["campaign/**/*.ts", "../playwright.campaign.config.ts"]` — `request-hygiene/**` is excluded, so `typecheck:e2e` is green *because it never reads the files*. ESLint also ignores them (`eslint.config.js:43-45`, `ignores: ['e2e/*', '!e2e/campaign']`; `eslint e2e/request-hygiene/phase-a-evidence.spec.ts` → "File ignored because of a matching ignore pattern"). I verified the files **do** typecheck clean when actually included (0 errors with `include` extended).
*Fix:* add `"request-hygiene/**/*.ts"` to `e2e/tsconfig.json` `include`, re-run `pnpm typecheck:e2e`, and quote the real command output in the doc.

### MAJOR

**MAJ-1 — `RH-T5-a`'s BLOCKED is a harness bug presented as a product blocker.** `spec:1342` uses `/^Product$/`, which cannot match the tile's accessible name "Product Count specific products across all locations". Fix the selector (`{ name: /^Product\b/ }` or `hasText`) and measure the 4th consumer, or restate the row and the "§Not covered" entry as *"harness selector defect, consumer reachable"*. The doc's "an unbounded [attempt] hung for 10 min" is not in any artefact.

**MAJ-2 — The coverage table under-discloses what is still owed.** Handover §1 owes T3 "`payments.spec.ts`, W5c/W8 Playwright" and T2 "four W4 Playwright specs" (also plan `:898`, `:387`); neither is run or mentioned. Plan `:33` makes T5 promotion **conditional on browser-checking counting, transfer, replenishment and document consumers** — with counting BLOCKED that condition is unmet and the doc does not say so. Plan `:2795` (T12 Step 6) requires opening payments from **SalesOrderDetailPage** as well; only PO (`RH-T12-e`) and invoice (`RH-T12-f`) are covered. Add a "still owed after this lane" column or paragraph.

**MAJ-3 — "Green run … 31/31" hides a FAIL and a BLOCKED.** The ledger carries 29 PASS + 1 FAIL (`RH-T3-c`, deliberate measurement) + 1 BLOCKED (`RH-T5-a`). Restate header `:18` as "31 tests passed; ledger verdicts 29 PASS / 1 FAIL (deliberate LIKE measurement) / 1 BLOCKED".

**MAJ-4 — `RH-T14-a`'s scenario string contradicts its own evidence.** `spec:1274` labels the leg "the last body is the one persisted" while the same row prints `psql documents.notes = "RH-T14 body 1"`. Rename to "…and the last body is the one that reaches the wire (persistence: see F-RH-2)".

**MAJ-5 — Forced-failure tolerance is path-scoped, not request-scoped.** `support.ts:117-120` and `:133-137` tolerate **any** ≥500 on a URL substring for the whole leg. In `RH-T12-b/c` a genuine server 500 on the *retry* would be swallowed (the psql count catches it there); in `RH-T14-b` nothing does. Scope the tolerance to the responses the leg itself fulfilled (keep the `Request` objects the route handler fulfils), or add `expect(nonForcedStatuses.every(s => s < 500)).toBe(true)`.

**MAJ-6 — F-RH-6 quotes a verbatim 422 body that no artefact contains.** Add a three-line probe (`apiJson('POST','/payments', {…document_id: poId})` asserting 422 + `error.code === 'DOCUMENT_NOT_ALLOCATABLE'`) so the finding carries its own evidence, or label it "observed during lane development, not captured in the ledger".

**MAJ-7 — F-RH-4 is pre-existing and its generalisation is not measured.** State that the root cause predates Phase A (`useWebSocketConnection.ts` last touched `bc1dd4faf`, 2026-07-02; provider dependence since `623dfbc2b`, 2026-03-16) and that T8 inherits rather than introduces it. The "Reverb restart / laptop sleeping / network blip" extrapolation rests on a **mocked, server-initiated close** via `page.routeWebSocket` (`spec:1827`, `:1870`); the close code is not recorded. Either qualify the claim to "a peer-initiated close" or measure one real drop.

**MAJ-8 — F-RH-5 is mis-rooted and larger than stated.** See §4: `subtitle_plural` exists but the i18next v4 suffix is `_other`; 15 dead `_plural` keys in `locales/en/*.json`.

**MAJ-9 — The root Playwright config would pick this spec up and mutate a local DB.** `apps/web/playwright.config.ts:4` is `testDir: './e2e'` with no `testMatch`, `fullyParallel: true`, and a `webServer` that starts `pnpm dev` on :5173. On `pnpm test:e2e` the spec would register a tenant and shell out to `psql` against `127.0.0.1:5433` (`support.ts:45-57`). The smoke/campaign CI configs do **not** match it (`playwright.smoke.config.ts:8-9`, `playwright.campaign.config.ts:4-5`) — that part of the doc's harness note is accurate.
*Exact edit* — `apps/web/playwright.config.ts`, immediately after line 4:

```ts
  testDir: './e2e',
  // Live-stack evidence harness: registers a tenant and shells out to psql.
  // Never part of `pnpm test:e2e`; run it via e2e/request-hygiene/pw.config.ts.
  testIgnore: ['**/request-hygiene/**'],
```

### MINOR

- **MIN-1** `support.ts:241-243` `headerOf(wire, _name)` is dead and actively misleading: it advertises a header lookup and returns the **body** field `idempotency_key`, ignoring `_name`. Delete it (the spec uses `bodyField` everywhere).
- **MIN-2** Missing tightening assertions: `expect(posts).toHaveLength(1)` in `RH-T12-a`/`T13-a`/`T13-b` (the "×1" is printed, not locked); `expect(saves.length).toBeGreaterThanOrEqual(2)` in `RH-T14-a` (a single save satisfies "no overlap" vacuously); `expect(dbCount).toBe(0)` in `RH-T12-e` (`spec:596`); `expect(amounts[0]).not.toBe(amounts[1])` in `RH-T12-c`.
- **MIN-3** `spec:1416` `resolveWire?.startedAt ?? Date.now()` can synthesise a ~0 ms latency; assert `resolveWire` is defined first.
- **MIN-4** `forced5xx` / `forcedConsole` / `harnessPaths` are module-global and mutated without `try/finally` (`spec:337/372`, `:387/425-426` — which deletes an entry it never added, `:566-567/600-601`, `:726/753`, `:1284-1285/1319-1320`, `:1946-1947/2047-2048`). A leg that throws leaks its tolerance into the rest of the worker. `harnessPaths` additionally never clears, so any 4xx console error on a path the harness probed once is tolerated for the remainder of the run (`support.ts:110-114`).
- **MIN-5** `restoreFixtures()` (`spec:124-140`) trusts `state.json` with no existence probe; `RH-SETUP-2`'s reuse branch (`:476`) re-reports a PO id it never re-reads. A stale state file silently changes what the later legs measure.
- **MIN-6** `RH-SETUP-4` claims the product "exists in company 2 only" with no negative probe under company 1.
- **MIN-7** `RH-T12-f`'s evidence tail "psql payments for the supplier = 0" (`spec:2043`) is irrelevant — the leg uses a customer invoice. Replace with the invoice's post-sweep outstanding.
- **MIN-8** Header shas need re-anchoring: `dev` is now `a622d7e97` (the doc's `fa000edc3` was correct *during* the run — the next first-parent commit `040dbe3a2` is 13:34 CET, after the 13:31 finish). Post-run deltas under `apps/web/src`: lint-style `Array<T>`→`T[]` in `RecordPaymentModal.tsx`, `useDraftAutoSave.ts`, `SplitPaymentForm.tsx` plus `PaymentDetailPage.tsx` (+26, F-W2-13 refund lane) and three treasury locale files. Say this explicitly rather than leaving "stands for both shas" pointing at a superseded sha.
- **MIN-9** Run window: `12:21:09 → 12:31:27 UTC` (log), not "12:22 → 12:32".
- **MIN-10** Add a line noting that Playwright's `:NNNN` in the run log are compiled-JS offsets (the source is 2055 lines), so `full-run.log` line numbers do not locate a test.

### Explicitly NOT findings (checked and cleared)

- `psql` credentials in `support.ts:48-49` — `autoerp`/`autoerp_secret` is the documented local dev credential already present in `docker-compose.yml`, `.github/workflows/ci.yml`, `.env.example` and the sibling harness `e2e/money-campaign/w2b-support.ts:54`. No new secret. (Optional: read from `process.env` like the sibling.)
- `PASSWORD = 'Campaign!2026Safe'` (`spec:48`) mirrors `e2e/campaign/journey.ts:300`; it is a throwaway password for a tenant the spec itself registers.
- No secret is written into a tracked path: `state.json` (which holds the bearer token) and every screenshot go to `EVIDENCE_DIR`, defaulted **outside** the repo (`support.ts:12-13`, `:303`), and `outputDir` likewise (`pw.config.ts:15-16`, `:28`).
- `@playwright/test` only; no `any`; no `test.skip/only/fixme`; the "idempotency key is a body field, not a header" scope correction is **true** (`PaymentForm.tsx:773`; zero `Idempotency-Key` hits in `apps/web/src`).
- The 31 doc rows are byte-identical to the generated ledger — the numbers were not touched by hand.

---

## 6. Exact edit list required before commit

**Spec (`apps/web/e2e/request-hygiene/phase-a-evidence.spec.ts`)**
1. Add `expect(counts.detail, 'forbidden 5xx/console errors').toBe('[]')` to `RH-T12-b` (after :381), `RH-T12-c` (:435), `RH-T12-e` (:608), `RH-T13-c` (:760), `RH-T14-b` (:1327), `RH-T4-a` (:1667), `RH-T4-b` (:1697), `RH-T8-a` (:1927), `RH-T12-f` (:2054).
2. `RH-T6-b` (:1618): register the fonts `ERR_NETWORK_CHANGED` and the leg-provoked 422 as named leg-scoped tolerances, then assert `counts.detail === '[]'`; name both in the evidence string.
3. Replace every hardcoded `fivexx: 0` with `counts.fivexx` (:377, :431, :605, :757, :1324); remove the stray `forcedConsole.delete` at :426.
4. Install `captureGuards` before `restoreSession()` in the two SETUP reuse branches and report `assertClean()` counts instead of the literals at :154 and :481.
5. Fix the counting-wizard selector at :1342 (`/^Product\b/` or a `hasText` filter) and re-measure the 4th T5 consumer; if it still does not reach the selection step, capture the failure text in the evidence string.
6. Add the MIN-2 assertions; guard the MIN-3 fallback; wrap the tolerance mutations in `try/finally` (MIN-4).
7. Rename the `RH-T14-a` scenario string (:1274) and drop the misleading tail in `RH-T12-f` (:2050).
8. Add the F-RH-6 422 probe (or delete the verbatim quote from the finding).

**Config**
9. `apps/web/e2e/tsconfig.json` — add `"request-hygiene/**/*.ts"` to `include`.
10. `apps/web/playwright.config.ts` — insert `testIgnore: ['**/request-hygiene/**'],` after `testDir: './e2e',` (verbatim block in MAJ-9).

**Re-run**
11. Re-run the full spec with the corrected assertions (same command, `retries: 0`, `workers: 1`) and regenerate `ledger.json` + the doc table from the new run. A ledger produced by the pre-fix spec must not be committed.

**Evidence doc (`docs/superpowers/reviews/2026-09-05-request-hygiene-phase-a-browser-evidence.md`)**
12. `:21` — restate the guard paragraph to match the re-run (and name every tolerance actually used, including whatever `RH-T6-b` needs).
13. `:18` — "31 tests passed; ledger 29 PASS / 1 FAIL / 1 BLOCKED"; correct the window to 12:21:09 → 12:31:27 UTC.
14. `:10-11` — re-anchor to `a622d7e97`, list the post-run `apps/web/src` deltas (MIN-8).
15. `:119` — restate the T5 BLOCKED reason per MAJ-1, or delete the row after the re-measurement.
16. `:129` — quote the real `typecheck:e2e` output after the `include` fix; state that ESLint ignores `e2e/*` so the directory is not linted.
17. Coverage table — add the still-owed column (MAJ-2: T3 `payments.spec.ts`/W5c/W8, T2 four W4 specs, T5 promotion condition unmet, T12 SalesOrderDetailPage).
18. Findings — F-RH-2 raise to MAJOR and note it is undeclared debt; F-RH-3 add the product-code `file:line`; F-RH-4 mark pre-existing + qualify the drop shape; F-RH-5 correct the root cause (`_plural` vs `_other`, 15 keys); F-RH-6 attach or label its evidence.
19. Add the MIN-10 note about compiled-JS line numbers in the run log.

---

# Gate r2 — fix round 1 re-review (2026-09-05, read-only, no Playwright run)

Subject: the same files after fix round 1, plus the two config edits and the regenerated evidence doc. Re-run: **13:26:48 → 13:34:55 UTC on `a622d7e97`**, `32 passed (8.3m)`, ledger **30 PASS · 2 FAIL · 0 BLOCKED**.

## VERDICT: **ACCEPT-WITH-FIXES** — every BLK/MAJ/MIN from r1 is fixed and independently verified. The remaining items are documentation-accuracy nits (stale `file:line` cites, one count) that need **no re-run**.

### Independent verification I ran (not taken on report)

| Check | Command / method | Result |
|---|---|---|
| Run log ↔ spec version | `playwright test --config e2e/request-hygiene/pw.config.ts --list` | 32 tests, last two at `:2424` / `:2602` — identical to the log. Same version |
| Doc table ↔ ledger | programmatic line diff of `table.md` vs the doc's 32 rows | **32/32 byte-identical, 0 diffs** |
| Ledger verdicts | `ledger.json` parsed | `{PASS: 30, FAIL: 2}`; the only non-zero guard counter in the whole ledger is `RH-T6-b (0 5xx, 1 console)` |
| Hardcoded counters | `grep -n "fivexx: [0-9]\|consoleErrors: [0-9]"` | **0 hits**; all 32 `record()` calls pass `counts.*` / `reuseCounts.*` |
| Guard install/assert split | enumerated every `captureGuards` / `assertClean` / `expect(counts.detail)` site | **34 installs** (32 legs + 2 reuse branches), **33 assert sites**; exactly one leg (`RH-T6-b`) does not assert console errors. Matches the doc's 32/32 · 31/32 claim |
| Typecheck coverage | `tsc --listFiles -p e2e/tsconfig.json \| grep -c e2e/request-hygiene` | **3** (`support.ts`, `phase-a-evidence.spec.ts`, `pw.config.ts`); `tsc --noEmit -p e2e/tsconfig.json` → **exit 0** |
| Root config exclusion | A/B control configs in the scratchpad: same `testDir`, one with and one without the new line, both filtered to `request-hygiene` | without `testIgnore` → **32 tests in 1 file**; with it → **0 tests in 0 files**. Exclusion demonstrated, not assumed |
| CI configs untouched | `git status --porcelain apps/web/playwright.*.config.ts .github/workflows/` | empty. `smoke` (`./e2e/smoke`, `*.smoke.ts`) and `campaign` (`./e2e/campaign`, `*.campaign.ts`) still cannot match the directory |
| F-RH-6 artefact | read `evidence/F-RH-6-422.json` | both verbatim 422 bodies present, `code: DOCUMENT_NOT_ALLOCATABLE`, `details.reason` `document_not_live` (received) and `purchase_order_wrong_direction` (confirmed) |
| Screenshots | mtimes of all PNGs | 27 files, every one 14:26–14:34 CET = the rev-2 window. **No stale rev-1 artefact** |
| Conventions | grep | no `test.skip/only/fixme`, no `any`, no `Idempotency-Key` header anywhere in `src` (body assertion remains correct), module-global `forced5xx` / `forcedConsole` / `headerOf` all gone |

### r1 item disposition

| Item | Status | Line cite |
|---|---|---|
| **BLK-1** guard claim false for 10 legs | **FIXED** | `expect(counts.detail).toBe('[]')` at spec `:388, :441, :625, :842, :1420, :1778, :1812, :2062, :2192`; reuse branches `assertClean()` at `:158`, `:497`. 31/32 legs assert |
| **BLK-1** `RH-T6-b` passed with 2 untolerated console errors | **FIXED — and fixed the right way.** The 422 is *not* tolerated: the leg is recorded FAIL (`replay422.length === 0` in `ok`, spec `:1728`), the finding text is in the evidence string `:1719`, and the in-code comment `:1683-1689`/`:1737-1739` explains why the test stays green (only the T6 contract is asserted, `:1733-1734`). The Google-Fonts line became a *named third-party* tolerance (`support.ts:125-131`), not a wildcard | spec `:1691-1735` |
| **BLK-2** 7 hardcoded counters | **FIXED** | 0 literals; guards installed **before** `restoreSession()` in both reuse branches (`:150`, `:489`) |
| **BLK-3** directory not typechecked | **FIXED** | `e2e/tsconfig.json` include += `request-hygiene/**/*.ts`; verified `listFiles` = 3, exit 0 |
| **MAJ-1** T5 4th consumer | **FIXED** | selector `/^Product\b/` at spec `:1442` with the accessible-name explanation `:1436-1441`; run log shows **all four consumers at 1 search each**, ledger result PASS, BLOCKED row gone |
| **MAJ-2** under-disclosed obligations | **FIXED** (one gap, see r2-6) | new "Still owed after this lane" column: SalesOrderDetailPage host, `payments.spec.ts`+W5c/W8, four W4 specs, live campaign `limit=100`, hardware wedge, real Reverb |
| **MAJ-3** "31/31" hid FAIL/BLOCKED | **FIXED** | header now separates "Playwright verdict 32 passed" from "Ledger verdicts 30 PASS · 2 FAIL · 0 BLOCKED" |
| **MAJ-4** `RH-T14-a` scenario vs psql | **FIXED** | scenario renamed at spec `:1370` ("…reaches the WIRE (persistence: see finding F-RH-2)") |
| **MAJ-5** path-scoped tolerance | **FIXED — genuinely request-scoped.** `forcedRequests: Set<Request>` keyed on the exact `route.request()` object (`support.ts:108`, `:191`, matched at `:157`), plus a **1:1 console budget** decremented per matched line (`:110`, `:194`, `:141-147`). A second, unforced 5xx on the same URL now produces a finding | `support.ts:87-97, 105-205` |
| **MAJ-6** F-RH-6 quoted an uncaptured body | **FIXED** | new leg `RH-F6-a` (spec `:643-693`) asserts 422 + `DOCUMENT_NOT_ALLOCATABLE` on **two** PO states, asserts the button is visible, `assertClean()` at `:683`, writes `F-RH-6-422.json` (verified on disk) |
| **MAJ-7** F-RH-4 provenance + generalisation | **FIXED** | finding now carries "PRE-EXISTING (inherited, not introduced by T8)" with `bc1dd4faf` and an explicit **"Limit of the claim"** paragraph naming the mocked clean close |
| **MAJ-8** F-RH-5 re-rooted | **FIXED — and every cite checks out.** I verified all 15 `_plural` keys at the exact lines given (`locations:6`, `common:942`, `products:6,27,32,93,96`, `inventory:101,121,248,338,385,974`, `compliance:126,192`) | doc F-RH-5 |
| **MAJ-9** root config would run the spec | **FIXED** | `playwright.config.ts:5-7`; A/B control proves 32 → 0 |
| **MIN-1** dead `headerOf()` | **FIXED** | absent from `support.ts` |
| **MIN-2** missing tightenings | **FIXED — all seven** | `toHaveLength(1)` spec `:339`, `:754`, `:806`; `saves.length >= 2` `:1373`; `dbCount === 0` `:628`; `amounts[0] !== amounts[1]` `:443`; `creditNote.lastUnitPrice` `:1681`; `five.perPage` `:1814` |
| **MIN-3** synthesised latency | **FIXED** | `expect(resolveWire).toBeDefined()` `:1516`, `NaN` fallback `:1517` (fails the `< 250` assertion instead of passing) |
| **MIN-4** leaking module tolerances | **FIXED** | tolerance state is per-guard closure (`support.ts:108-111`); `harnessPaths.clear()` in `stop()` `:178` |
| **MIN-5** state.json trusted blindly | **FIXED** | `/auth/me` + `/companies/{id}` re-probed `:153-157`; PO re-read and warehouse re-confirmed `:492-496` |
| **MIN-6** "company 2 only" unproven | **FIXED** | negative probe under company 1 (run log: "0 row(s) under company 1 and 1 under company 2") |
| **MIN-7** irrelevant `RH-T12-f` tail | **FIXED** (label nit, r2-7) | now reports the invoice's post-sweep balance |
| **MIN-8/9/10** header sha, window, log offsets | **FIXED** | header `:12-13`, `:20`; harness note on compiled-JS offsets |

### Remaining edits before commit (documentation only — no re-run)

| # | Sev | Item | Fix |
|---|---|---|---|
| r2-1 | MINOR | Stale `DocumentLineEditor.tsx` cites. Doc says `:371-378` (scope correction 3 **and** F-RH-3), `:388` for `debouncedPricingLines`, `:395-411` for the query. Actual: `pricingContextLines` **`:397-405`**, `debouncedPricingLines` **`:414`**, query **`:424-436`** | correct the three cites |
| r2-2 | MINOR | `WebSocketReconnectProvider.tsx:56` is `const queryClient = useQueryClient()`; the `isConnected` read is **`:55`** | shift the cite |
| r2-3 | MINOR | F-RH-3 cites "`CompanySelector.tsx:41-48`" but two files carry that name; the invalidation effect is in **`components/organisms/CompanySelector/CompanySelector.tsx:41-48`** (a second `components/layout/CompanySelector.tsx` exists) | qualify the path |
| r2-4 | MINOR | Header says "28 screenshots"; **27** PNGs on disk (all rev-2) | correct the count |
| r2-5 | MINOR | `RH-T5-a` still downgrades to `BLOCKED` without asserting it: `blocked` is computed (spec `:1466-1469`) but only `failures` is asserted (`:1493`), so a future selector rot would silently produce a BLOCKED ledger row on a green test | add `expect(blocked, 'every T5 consumer must be measured').toEqual([])`. The current run already recorded 4/4 measured, so the ledger stays valid — but if the spec is edited after the run, say so in the doc |
| r2-6 | MINOR | Still-owed table omits one plan obligation: Task 12 Step 6 also requires proving the split total `"0.300"` accepts `"0.100" + "0.200"` and rejects `"0.100" + "0.199"` (plan `:2795`). Not covered and not listed | add a row |
| r2-7 | MINOR | `RH-T12-f` evidence prints "psql **documents.outstanding_amount**" while the query selects `balance_due::text` (spec `:2183`) — and the doc's own fix-round note says that column does not exist | rename the label to `documents.balance_due` |
| r2-8 | INFO | Not a defect of this diff: the root `playwright.config.ts` currently collects **0 tests overall** (a pre-existing `vitest/expect` `Cannot redefine property: Symbol($$jest-matchers-object)` collision while loading the other `e2e/*.spec.ts`). So a bare `--list` on the root config proves nothing by itself; I used an A/B control to demonstrate the `testIgnore`. Worth a one-line note so nobody misreads the "0" | optional note |
| r2-9 | INFO | `tolerateConsole(fragment, reason)` is unbudgeted, unlike the 1:1 HTTP budget: a leg that forces one failure tolerates any number of matching component lines. Leg-scoped and named, so low risk | optional: add a count |

### Standing judgements unchanged from r1

`RH-T6-b`'s FAIL-instead-of-tolerate is the correct resolution of BLK-1 and should not be "fixed" into green. F-RH-2 remains **MAJOR / undeclared** (silent header-edit loss with a "saved" indicator; `DraftPersistenceService.php:135-136`). F-RH-4 remains **pre-existing/inherited** by T8. The five BLOCKED reasons verified in r1 (POSPage, `/audit/events`, `echo.ts`, `SplitPaymentForm`, and now the *former* counting blocker) stand as re-stated.
