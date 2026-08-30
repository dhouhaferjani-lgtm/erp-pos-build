# I-3 code gate r1 — fiscal/POS adversarial review, 2026-08-30

Reviewer: `fiscal-pos-reviewer` (Opus, code-grounded, adversarial). Target: commit `40a97a8ba`
("feat(i3): onboarding campaign legs L5b + L9 …") in worktree
`/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/i3-z-session`.
Brief r4: `docs/sessions/session-I-process-hardening-2026-08-29/LANE-I3-z-session-leg-BRIEF.md`.
Prior spec gates: `docs/superpowers/reviews/2026-08-30-i3-z-session-brief-gate-r{1,2,3}.md`.
Live evidence: run 24 on local dev `155686736` (L0a–L9 PASS, red only on I2-F2 at L10).

Everything below was read in the tree; every claim carries `file:line`. Reproduced locally:
`pnpm --dir apps/web campaign:fiscal-test` → 3 files / 5 tests pass; `pnpm typecheck:e2e` → exit 0;
`pnpm exec eslint e2e/campaign` → exit 0.

---

## 1. What the leg actually proves

**Genuinely proven (not tautological):**

- **Server↔client canonical-encoder parity for all three z_session event types.** The server
  re-encodes and re-hashes the envelope in `StrictCanonicalParser` before storing, so
  `expect(zDetail['fiscal_hash']).toBe(zReport.currentHash)`
  (`apps/web/e2e/campaign/onboarding.campaign.ts:868`) and
  `z_last_hash: zReport.currentHash` (`:930`, served from `pos_z_reports.fiscal_hash` —
  `apps/api/app/Modules/POS/Presentation/Controllers/TerminalController.php:945`) are a real
  cross-implementation check of the vendored encoder. This is the strongest assertion in the leg.
- **Chain admission on `z_session` at sequences 1/2/3.** Genesis-seed linkage for the first event
  and prior-hash linkage for the rest (`apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php:494-533`,
  `:538-550`); `SESSION_OPEN` source tuple `pos_session`/`session_id`, `Z_REPORT`
  `reference_event_id` = the close, close/Z requiring an existing open and no prior Z
  (`OutboxIngestor.php:552-594`). The builders match device authoring exactly:
  `pos_session`/sessionId (`apps/pos/src/lib/fiscal/zSessionAuthoring.ts:539-551` vs
  `apps/web/e2e/campaign/fiscal/zSession.ts:98-99`), `pos_session_close`/closeUuid
  (`zSessionAuthoring.ts:684-686` vs `zSession.ts:156-157`), `z_report`/zUuid +
  `reference_event_id` (`zSessionAuthoring.ts:706-709` vs `zSession.ts:244-247`).
- **Exact key sets.** `SESSION_OPEN` 13 / `SESSION_CLOSE` 28 / `Z_REPORT` 32 against
  `apps/api/app/Modules/Fiscal/Domain/DTOs/SessionOpenPayload.php:9-23`,
  `SessionClosePayload.php:9-37`, `ZReportPayload.php:9-40` — pinned in
  `apps/web/e2e/campaign/fiscal/zSession.test.ts:56-60`, `:111-129`.
- **Staged projection ordering.** Close only after the open projects
  (`apps/api/app/Modules/POS/Application/Projections/ZSessionLifecycleProjection.php:239-255`),
  Z only after every verified in-window receipt projects
  (`ZReportProjection.php:305-336`); the campaign polls each stage
  (`onboarding.campaign.ts:566-575`, `:833-838`, `:864-867`).
- **Ingestion-level replay idempotency shape** — `assertReplayedFiscalResult`
  (`onboarding.campaign.ts:1152-1157`) matches `IngestionResult::idempotent()`
  (`apps/api/app/Modules/Fiscal/Application/DTOs/IngestionResult.php:75-86`) and
  `assertStoredFiscalResult` (`:1145-1150`) matches `IngestionResult::stored()` (`:48-58`).
  The four fields are copied to the wire untransformed
  (`FiscalEventIngestionController.php:175-182`). The acceptance-matrix helpers are correct.
- **Read surfaces answer**: shift OPEN→CLOSED with expected/actual/variance
  (`onboarding.campaign.ts:840-844`), `/pos/shifts/current/{code}` → null after close (`:938-945`),
  Z list count 1 (`:892-897`), z-chain-state 0 → 1 (`:530-536`, `:846-855`, `:928-936`).
- **B5 — close/Z move no repository cash** (`:961-969`), matching a close projector that touches
  only `pos_shifts` (`ZSessionLifecycleProjection.php:296-330`).
- **No negative probe touches the campaign terminal.** Correct, per gate r2 I3-R2-01: the only
  negative case is a network-free builder refusal (`zSession.test.ts:215-223`). Chain head lookup
  does not exclude quarantined rows, so this rule matters; the lane honours it.

**Device parity of the pinned vector — independently re-derived, and it holds.** I did not take
the citations on trust:

| Pinned value | Device derivation | Verdict |
|---|---|---|
| one netted rate-19 VAT row `{0,0,0}` | sale adds and refund **subtracts** into the same rate-keyed bucket (`apps/pos/src/lib/offline/zReportService.ts:924-931`, `:1063-1084`), emitted one row per bucket with `tax_rate: parseFloat(rate)` → number `19` (`:1151-1162`) | ✅ correct; `19` (number) matches the receipt's sealed `rate: '19.00'` (`e2e/campaign/fiscal/events.ts:114`) |
| one cash row `{total_amount 0.000, transaction_count 2}` | refund subtracts the amount and still `count += 1` (`zReportService.ts:940-946`), sale adds (`:1131-1136`), emitted keyed on method **code** (`:1164-1170`) | ✅ correct |
| `grand_totals_after` = sales 23.800 / tax 3.800 / refunds 23.800 / **perpetual 0.000** / lifetime **1** | `perpetual = prior + (gross_sales − refunds_amount)`, `receipt_count_lifetime = prior + sales_count` (`zReportService.ts:475-481`) | ✅ correct — perpetual 0 and lifetime 1 are the non-obvious ones and both check out |
| sale-only headline `receipt_totals {count 1, 23.800/20.000/3.800}`, `refunds_totals` positive 23.800 | refund branch never touches sales accumulators (`zReportService.ts:893-901`); `receipt_totals.count = sales_count` (`apps/pos/src/lib/fiscal/zSessionAuthoring.ts:480-485`) | ✅ correct |
| `expected_cash`/`counted_cash` 1000.000, `cash_drawer_totals {expected, opening}` | `expected = opening + cashSales + drawerNet + cashAccountCollections − cashRefundImpact` = 1000 + 0 (`zReportService.ts:291-303`); shape at `:712-715` | ✅ correct |
| `cash_count_lines[0] {expected 1000.000, transaction_count 2, balanced}` | for a cash tender `expected_amount = expectedCash` (`zReportService.ts:396-399`); `transaction_count` counts every receipt row on that method — sale **and** refund (`:412-416`) | ✅ correct |
| `operational_event_range` sale hash/seq 1 → refund hash/seq 2, count 2 | first/last snapshot hash + `hash_sequence`, `receipt_count = snapshots.length` (`zReportService.ts:747-754`) | ✅ correct |
| `session_event_range` open seq 1 → close seq 2 + ids/hashes | `zSessionAuthoring.ts:689-696` | ✅ correct |
| `formatted_z_number 'Z0001'`, `z_number 1` | `zReportService.ts:454-455`; `zSessionAuthoring.ts:469`, `:502` | ✅ correct |

**What the leg cannot prove — state it plainly in any promotion note:**

- **No total is validated by the server.** `ZReportProjection::legacyReportData()` copies
  `vat_breakdown`, `payment_method_totals`, `receipt_totals`, `grand_totals_after` and the entire
  payload (`'canonical_z_report' => $payload`) verbatim
  (`apps/api/app/Modules/POS/Application/Projections/ZReportProjection.php:130-165`, esp. `:153-155`,
  `:164`), and the close projector copies `expected_cash`/`counted_cash` and only recomputes the
  subtraction (`ZSessionLifecycleProjection.php:284-320`). Every content assertion in L9
  (`onboarding.campaign.ts:869-891`) is therefore a **round-trip of a hand-authored value** — this
  is exactly I3-F1/I3-F2, and the lane documents it. A wrong hand-authored total would still turn
  the leg green (I3-F2 confirmed in code, not merely asserted).
- **The server does not even validate the VAT sum for this journey.** `refunds_totals.count = 1 ≠ 0`
  short-circuits both sum identities
  (`apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:1063-1073`).
  The netted-zero VAT row is *admitted*, not *checked*. Only the per-row `gross = net + vat`
  identity runs (`:1041-1052`), which is trivially true at all zeros.
- **Projection-level idempotency is not exercised.** The replay is answered as idempotent at
  ingestion (`OutboxIngestor.php:1097-1113`) before any projection job is dispatched, so
  "one Z row after replay" (`onboarding.campaign.ts:899-906`) is guaranteed by ingestion, not by
  the projector's `fiscal_event_id` probe (`ZReportProjection.php:60-62`, `:73-75`).
- **Matrix rows 1, 2, 4 and 5** (422 / 403 / quarantine / conflict) are unexercised anywhere,
  including network-free. Correct by design for the campaign terminal, but the acceptance matrix in
  the brief is documentation, not coverage.

---

## 2. Findings

### [MAJOR] I3C-1 — `scripts/campaign-onboarding.sh:51-55`: the fiscal-builder drift guard's exit code is discarded

```sh
set +e
# Network-free contract tests for the vendored fiscal builders run first (fail fast, convention 08).
pnpm --dir apps/web campaign:fiscal-test
pnpm --dir apps/web campaign:onboarding
campaign_status=$?
```

`campaign_status` captures only `campaign:onboarding`. Under `set +e` a red
`campaign:fiscal-test` is silently swallowed — the comment says "fail fast" and the script does the
opposite.

Why it matters: brief r4 §2 designates the golden hash pin as *the* drift guard for the vendored
canonical encoder ("the L0a golden extension encodes the fixed vector and pins the sha256"). That
guard is now reachable from exactly four places, and three of them do not enforce it:

- `apps/web/vitest.config.ts:11` — `include: ['src/**/*.{test,spec}.{ts,tsx}', 'tools/**/…']`, so
  `pnpm test` never collects `e2e/campaign/**`.
- `scripts/preflight.sh` — contains no `campaign` reference at all (grepped).
- `.github/workflows/onboarding-campaign.yml:35` — the whole job is inert on push until
  `vars.ONBOARDING_CAMPAIGN_ON_PUSH == 'true'`.
- `.github/workflows/onboarding-campaign.yml:56-57` — the only place that actually gates, and only
  on `workflow_dispatch`.

So an encoder change that silently alters the sealed bytes ships green everywhere a developer
would look. Fix (no live re-run needed — network-free):

```sh
set +e
pnpm --dir apps/web campaign:fiscal-test
fiscal_status=$?
pnpm --dir apps/web campaign:onboarding
campaign_status=$?
set -e
[ "$fiscal_status" -eq 0 ] || campaign_status="$fiscal_status"
```

and add `pnpm --dir apps/web campaign:fiscal-test` to `scripts/preflight.sh` (it runs in 0.6 s).

### [MAJOR] I3C-2 — `onboarding.campaign.ts:985` + `:1031-1044`: L9 weakens the day-one census instead of accounting for the L4 bank row

`assertDayOneCensus` grew a `requireOnlyProvisionedRepositories = true` parameter and L9 calls it
with `false`, disabling:

```ts
if (!reuseMode && requireOnlyProvisionedRepositories) {
  expect(value.repositories, 'only the company\'s cash register and safe are provisioned').toHaveLength(2)
}
```

The relaxation is *understandable* — L4 creates a third, location-attributed repository
(`onboarding.campaign.ts:438-445`, `type: 'bank_account'`) — but it is the wrong shape. The
invariant L9 exists to defend is B5, "close moves no cash implicitly"
(brief r4 line 22), and the repository **set** is precisely where an implicit
close/Z-time provisioning would show up. Turning the count assertion off in that one leg means a
close/Z that silently created a repository would go unnoticed; the drawer-balance check
(`:961-969`) only covers the one repository it names.

Brief r4 §5 also requires "day-one census (L0's `census()`) still holds" — with the flag off it
partially does not. This is the one place the implementation deviates from the brief.

Fix: keep the assertion and make the expectation explicit, e.g. pass the expected count
(`assertDayOneCensus(census, 3)`) and additionally assert the third row is the L4 bank
(`repositories.find(r => r['code'] === bankCode)`). Requires one live re-run to confirm green.

### [MINOR] I3C-3 — the only server-**derived** Z content is left unasserted

`ZReportResource` exposes two fields the server computes rather than echoes:
`refund_vat_disclosure` (`apps/api/app/Modules/POS/Presentation/Resources/ZReportResource.php:52-57`,
detail endpoint only) and `report_data.cash_rounding_summary`, derived from **projected receipts**
(`ZReportProjection.php:160`, `:204-236`). L9 asserts neither. Given that every other content
assertion is a copy round-trip (§1), these are the only two available non-tautological content
checks on the Z surface — and `refund_vat_disclosure` is exactly the surface that discloses the
refund VAT the netted breakdown zeroes out. Brief r4 §5 made it optional ("any assertion on
`refund_vat_disclosure` must name that path and its values"), so this is not a missed requirement,
but it is the single highest-value addition to the leg.

### [MINOR] I3C-4 — `events.ts:271`: `idempotency_key` now collides across chain contexts

`idempotency_key: \`${coordinates.terminalId}:${coordinates.sequenceNumber}\`` is shared by the
receipt and z_session builders after the refactor, so `SESSION_OPEN` (z_session seq 1, L5b) and the
sale (operational seq 1, L6) send the **same** key on the same terminal. Inert today — the server
never matches on it; the only consumer writes it into a quarantine record
(`OutboxIngestor.php:1248`) and the request rule only requires a string
(`IngestFiscalEventsRequest.php:54`). But if the field is ever honoured, L6 would be swallowed as a
replay of L5b. Fix: `${terminalId}:${chainContext}:${sequenceNumber}`.

### [MINOR] I3C-5 — semantic vector under-pins two device-derived structures, and part of it is unguarded

`zSession.semantic.json` pins `cash_count` scalars but **not** `cash_count_lines[]`
(`expected_amount 1000.000`, `transaction_count 2` — both genuinely device-derived, `zReportService.ts:396-399`,
`:412-416`) and not `cash_drawer_totals` (`:712-715`). Separately, the cross-check
`expect(semantic.values).toMatchObject({...})`
(`onboarding.campaign.ts:155-164`; `zSession.dry.test.ts:24-33`) covers eight keys and omits
`event_times`, `cash_count`, `voids_totals`, `z_number` and `formatted_z_number`, so those semantic
values can drift away from the builder without any test noticing. Fix: add the two structures and
compare the full `semantic.values` against the golden payload rather than an eight-key subset.

### [MINOR] I3C-6 — Z payload diverges from the device on `legacy_report_reference`

The builder emits `legacy_report_reference: null` (`zSession.ts:203`); the device emits
`{fiscal_hash, hash_sequence, local_z_report_id, shift_id}` (`zReportService.ts:736-742`). The
`description` in `zSession.semantic.json:2` declares this, and the server does not validate the
field, so it is honest and harmless. Worth naming explicitly in the "server-minimal synthetic"
caveat in `docs/qa/ONBOARDING-CAMPAIGN.md` alongside the omitted `OPENING_FLOAT`, since
`hash_sequence` inside that object is the value a recovered device would seal next
(`TerminalController.php:952-957`).

### [MINOR] I3C-7 — a "balanced" Z is asserted over a drawer the same journey left 1250.500 richer

L9 asserts `expected = counted = 1000.000, variance = 0.000` (`onboarding.campaign.ts:841-844`)
while simultaneously asserting the repository holds `2250.500` (`:788`). The separation is
gate-ruled (I3-R1-06) and correct for how L8 is driven (server Treasury API, not a device cash
event). But note the device's own formula includes a `cashAccountCollections` term for cash
collected against customer credit accounts (`zReportService.ts:285-303`) — a device that had seen
L8 would have derived `2250.500`. Recommend one sentence under **I3-F1** in
`docs/qa/ONBOARDING-CAMPAIGN.md` so a future reader does not read "variance 0" as "drawer verified":
server-side cash collections into a POS cash repository are invisible to the session's expected cash.

### [MINOR] I3C-8 — scale-2 money derived by string truncation

`scaledMoney` (`zSession.ts:407-409`) and `fiscalMoney` (`onboarding.campaign.ts:1201-1203`) both do
`value.slice(0, -1)` to go from scale 3 to scale 2. Safe only because every literal in
`reportVector` ends in `0`; a future `'3.805'` would silently become `'3.80'` (truncation, not
rounding) and be sealed into immutable bytes. `events.ts:326-336` already uses the safer
construction (build both scales from a common stem). Fix: mirror that, or assert the dropped
character is `'0'`.

### [MINOR] I3C-9 — `semanticLeafPaths` duplicated verbatim

`onboarding.campaign.ts:1247-1256` and `zSession.dry.test.ts:62-71` are byte-identical. Export it
once from `fiscal/zSession.golden.ts`.

---

## 3. Checklist results (no findings)

- **No float touches money or quantity.** `parseFloat` / `Number(` / `toFixed` / `Math.` — zero hits
  across `fiscal/zSession.ts`, `fiscal/zSession.golden.ts`, `fiscal/events.ts`,
  `onboarding.campaign.ts`. All money is exact-scale strings; `assertMoney`/`assertMoneyEqual` go
  through `normalizeMoney` (`journey.ts:182-201`). `Date.parse` is used only for time ordering
  (`onboarding.campaign.ts:816-819`), never for money.
- **Events are immutable (rule 8).** No Event/DTO class renamed, restructured or deleted; the diff
  is test-harness only plus one shell script and one workflow step.
- **No new `onQueue(...)`**, so no horizon coverage change needed.
- **No SQLite time boundary touched** — `toSqliteUtc` is not in scope; device SQLite is untouched.
- **No device-side stock decrement, no second server decrement path** — the diff adds no projector.
- **No shift re-hydration from a server payload** — `fiscal_shift_id`/`fiscal_session_id` merge rule
  is not in scope; L5b mints the id client-side and threads it.
- **No per-line TTC-vs-HT equality assertion.** L9 asserts only aggregates; the receipt lines
  (`events.ts:280-299`, `unit_price = money.gross`) are untouched by this diff.
- **Conventions 09 / 11**: no catalogue entity, no new unique index, no new noun — `Shift`,
  `Terminal`, `Z`, `Repository` all pre-exist in the glossary. `L5b` is a ledger key, not a concept.
- **Data-meaning tests**: L9 asserts balances, counts, hashes and a post-replay row count, not
  status codes. `pollUntil(..., value => value.status === 200)` for the Z detail (`:864-867`) is a
  readiness gate followed by real assertions, which is the right shape.
- **Verification claims in the summary are true**: `campaign:fiscal-test` 5/5 green,
  `typecheck:e2e` exit 0, `eslint e2e/campaign` exit 0 — all re-run by this reviewer.
- `journey.ts:209` and `onboarding.campaign.ts:79` widened the leg regex to `/^L\d+[a-z]?/`,
  correctly matching `L5b`; the pre-declared `NOT_SCRIPTABLE` seed for L9 is removed
  (`journey.ts:148-149`) and nothing else sets that state.

## 4. Adjudication of I3-F1..F5 — all five accurate and correctly owned

| Finding | Verified against code | Verdict |
|---|---|---|
| I3-F1 server never derives expected cash | `ZSessionLifecycleProjection.php:296-320` copies `expected_cash`/`counted_cash` from the payload and only recomputes `bcsub(counted, expected, 4)` | ✅ accurate |
| I3-F2 Z totals copied, never reconciled | `ZReportProjection.php:130-165` (`:153-155`, `:164`); validator checks only per-row `gross = net + vat` and, for `refunds_count = 0`, the two sums (`FiscalPayloadConstraintValidator.php:1041-1084`) | ✅ accurate — and stronger than stated: with `refunds_count = 1` the sums are skipped entirely (`:1069-1073`) |
| I3-F3 post-Z sale accepted | `OutboxIngestor.php:553-555` returns `null` for any non-`z_session` chain context, so `SALE_RECEIPT` never reaches the Z lifecycle check; nothing else blocks it | ✅ accurate; correctly **not** probed on the campaign terminal |
| I3-F4 variance reason not on the shift surface | `ShiftResource.php:23-45` exposes expected/actual/variance/status and no reason; L9 reads it from `report_data.cash_count.variance_reason` (`onboarding.campaign.ts:874-875`) | ✅ accurate |
| I3-F5 first canonical Z reports `is_first_z_report=false` | `ZReportProjection.php:115` writes the Z envelope's `previous_hash` (the close hash, never `'GENESIS'`) into `previous_z_hash`; `ZReportResource.php:36` exposes `isFirstZReport()` | ✅ accurate; L9 pins `false` with the explanatory message (`onboarding.campaign.ts:869`) |

Ownership is recorded as "POS/fiscal — owner routing" in
`docs/qa/ONBOARDING-CAMPAIGN.md` — a placeholder rather than a named owner. Acceptable for a QA
known-red table; worth resolving when the findings are routed.

---

## VERDICT: spec ✅ (one deviation — brief §5's "day-one census still holds" is partially relaxed, see I3C-2) + quality **CHANGES-REQUESTED**

The fiscal substance is sound: the chain model, source tuples, key sets, envelope linkage and the
entire pinned semantic vector were re-derived from device code and every value checks out, and the
lane is honest about the copy-not-derive limit (I3-F1/I3-F2). Nothing here re-authors or mutates a
device-signed fact. The two MAJORs are both about the durability of the guard rather than its
correctness.

**Fix before merge:** repair the discarded `campaign:fiscal-test` exit code in
`scripts/campaign-onboarding.sh:51-55` (and add it to `scripts/preflight.sh`) — network-free, no
re-run; and restore the day-one repository-count invariant in L9 as an explicit count of 3
including the L4 bank row — one live campaign re-run to confirm green.
