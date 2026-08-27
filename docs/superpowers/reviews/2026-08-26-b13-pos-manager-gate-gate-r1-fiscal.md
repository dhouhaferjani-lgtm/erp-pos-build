# B-13 POS manager gate — adversarial gate r1 (fiscal/POS lens)

**Branch** `fix/b13-pos-manager-gate-role-composition` · base `4ae7c68a8` → head `58a14ac25`
**Worktree** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/b13-pos-manager-gate` (read-only review)
**Ruling** `docs/handoff/OWNER-SHEET-2026-08-21-first-client-session.md` (B-13 "fix properly": role composition PIN-only + gate the ungated X-report surface + tooltip policy plumbing)
**Gap ticket** `docs/superpowers/tickets/2026-08-21-xreport-blind-count-gap.md`

## VERDICT

**spec ✅ · quality CHANGES-REQUESTED**

The three ruled deliverables ((ii) X report, (iii) tooltip, (iv) role composition) are delivered
and verified in code. Two defects block: one fail-OPEN in the lane's own new code, one
undisclosed bypass that defeats the regime the lane exists to enforce.

---

## 1. Verified (claim → file:line)

**(iv) Manager access composes from the PIN operator ONLY.**
- `apps/pos/src/lib/auth/roles.ts:49-50` — `hasManagerAccess(operatorRoles)` → `isManagerRole(operatorRoles)`.
  The `Math.max(operatorLevel, userLevel)` leg is **removed**, not merely unused; a two-argument
  call no longer typechecks, so no caller can reintroduce it.
- All four call sites now pass one argument and none reads `authStore.user.roles`:
  `AppShell.tsx:81`, `Header.tsx:126`, `ReportsMenu.tsx:33`, `SettingsPage.tsx:99`.
  `grep hasManagerAccess|isManagerRole|getAccessLevel` over `apps/pos/src` returns no other
  production consumer.
- **No fall-back-to-login-user mode exists.** `apps/pos/src/App.tsx:311-330` is a hard ladder:
  `!operator && hasPins === null` → loading; `!operator && hasPins === false` → `<PinSetupPage/>`;
  `!operator || isLocked` → `<PinEntryPage/>`. `<AppShell/>` (`App.tsx:352-354`) never mounts with
  `operator === null`, so "no PIN operator ⇒ CLOSED" changes no reachable behaviour and is the
  correct predicate for any future surface. Pinned by `roles.test.ts:49-50`
  (`undefined`/`[]` → false) and `roles.test.ts:54` (unknown role not elevated).
- No lockout regression for the single-account owner: `operatorStore.setupPin` seeds the first
  operator from the logged-in user's roles, and `AppShell.managerGate.test.tsx:147-155` pins it.

**Manager-only surface enumeration — every one checked.**

| Surface | file:line | Gate after |
|---|---|---|
| Route `/reports` | `AppShell.tsx:220-223` | `isManager` = PIN-only ✅ |
| Route `/shift` | `AppShell.tsx:224-227` | ✅ |
| Route `/reports/z` | `AppShell.tsx:230-233` | ✅ |
| Nav "Caisse-Shift" destination | `AppShell.tsx:81` + NavRail items | ✅ |
| ReportsMenu → X report | `ReportsMenu.tsx:59` (`managerOnly: true`) | ✅ |
| ReportsMenu → cash drawer ops | `ReportsMenu.tsx:61` | ✅ (client-side only — see F-5) |
| ReportsMenu → Z-report history | `ReportsMenu.tsx:63` | ✅ |
| Settings → device unbind | `SettingsPage.tsx:99` | ✅ |
| X-report **execution** | `Header.tsx:397-400` | NEW ✅ |
| Ungated by design | `/` , `/customers`, `/settings`, `/sales` (`AppShell.tsx:216-219`) | see **F-2** |

**X report cannot be reached by a route the gate doesn't cover.** `generateXReport` has exactly one
production caller (`Header.tsx:422`), reached only from `handleXReport` (`Header.tsx:390`), whose
first statement after the terminal null-check is the manager refusal (`Header.tsx:397-400`) — i.e.
**before** `generateXReport` → `appendXReport` authors the immutable `X_REPORT` event (rule 8).
`Header.test.tsx` asserts `generateXReport` is `not.toHaveBeenCalled()` on refusal, which is the
right assertion: hiding a rendered result would still have authored the event.

**Fail-closed before the policy answers, and offline.**
- `useCashDisclosure.ts:26-30` — initial state `'conceal'`; the effect re-arms to `'conceal'`
  **before** the new read on every `companyId` change; `catch` → `'conceal'`; returns `'conceal'`
  while `companyId` is null.
- `cashDisclosurePolicy.ts:35-47` — API first, `catch` → durable SQLite cache
  (`getCompanyFraudSettings`), `catch` → `'conceal'`; only a positive
  `require_blind_cash_count === false` discloses. Offline path is the SQLite cache, as required.
- Pinned: `useCashDisclosure.test.tsx:31-34` (pre-answer conceal), `:51-56` (DB won't open),
  `:65-77` (re-arm on company switch); `Header.test.tsx` "fails CLOSED for a cashier while the
  policy is still unresolved".

**RED-on-base is credible.** `AppShell.managerGate.test.tsx:107-128` sets `operator.roles=['cashier']`
with `user.roles=['owner']` — on base `Math.max(0,2)=2` cleared all three routes, so these cases fail
on base. Same shape for the `ReportsMenu` and `Header` X-report cases. `XReportModal`'s
`concealedTenderCodes` is a **required** prop, so the modal tests cannot compile on base. The report
honestly discloses (§5) that `roles.test.ts` could not go red on its own (old signature defaulted
arg 2 to `undefined`) and that RED was taken at the consumers — accepted.

**Rule 20 / fiscal payload.** The diff introduces no SQL, no timestamp binding (no `toISOString()`
into a SQLite comparison), no shift re-hydration from a server payload, no `onQueue`, and no
queue/projection-reachable code. `apps/api` is untouched, so no CompanyContext or scale-resolver
surface is in play. `api/reportApi.ts` and `lib/fiscal/zSessionAuthoring.ts` are **not in the
diffstat** — the signed `X_REPORT` payload is byte-identical; the mask is render-only
(`XReportModal.tsx:166-190`). No float touches money in the diff (`format()` on strings).

---

## 2. Findings

### [IMPORTANT] F-1 — `Header.tsx:139-147` — the X-report mask fails **OPEN** when the physical-tender set can't be resolved

`physicalTenderCodes` is built by joining the report's `payment_type` against a snapshot of
`usePaymentStore.getState().paymentMethods ?? []` on `is_physical` (`Header.tsx:144`). If that array
is empty (cold start where neither the API nor the SQLite cache answered —
`paymentStore.ts:975-979` explicitly logs "No payment config available"), or if a tender code present
in the shift's receipts is absent from the synced method list (deactivated/renamed method), the set
is empty and `concealedTenderCodes` is empty (`Header.tsx:161-165`) — so **cash takings render in
full while `cashDisclosure === 'conceal'`**.

Why it matters: every other leg of this lane fails CLOSED by design
(`useCashDisclosure.ts:26-30`, `cashDisclosurePolicy.ts:35-47`), and the report's own rationale for
making the prop required was "so no future caller can render the report without deciding". This one
decides *disclose* on an unresolvable input, in the one regime whose whole purpose is concealment.

It is also **not** "the identical predicate `/reports` uses" as §3 of the report claims. `/reports`
conceals off `is_physical` **carried on the report row itself** (`ReportsPage.tsx:180`
`concealed: concealCash && m.is_physical`) — no join, no miss mode. Only the policy×shift half of
the predicate is identical. Untested: no case covers `mockPaymentMethods = []`.

**Fix:** when `cashDisclosure === 'conceal' && shift !== null` and the physical set is unresolvable
(store list empty), conceal **every** tender row rather than none; or carry `is_physical` on
`XReportResponse.payment_methods` the way the `/reports` preview does. Add the empty-store test.

### [IMPORTANT] F-2 — `ReportsMenu.tsx:62` + `AppShell.tsx:219` + `TodaySalesPanel.tsx:74,242,246` — `/sales` still defeats the blind-count regime for a cashier, and the report does not disclose it

"Today's Sales" is `managerOnly: false` (`ReportsMenu.tsx:62`), navigates to `/sales`
(`Header.tsx:831`), and `/sales` is an **ungated** route (`AppShell.tsx:219`). The page loads
`fetchShiftReceipts(shiftId)` for the **currently open shift** (`TodaySalesPanel.tsx:62-74`) and
renders, per receipt, the total (`:240-243`) next to the payment-method label
(`:244-247` → `getPaymentLabel` `:43-45`), plus a net-sales headline (`:156`). A cashier sums the
CASH-labelled rows and obtains the **exact** cash takings this lane just concealed on the X report —
and that `/shift` and `/reports` already conceal. No arithmetic derivation is needed; it is the raw
data, per receipt, for the exact shift being counted.

This is server-reachable too: `cashier` holds `pos.view_receipts` and `pos.manage_shifts`
(`RolesAndPermissionsSeeder.php`, cashier block) even though it lacks `pos.view_reports` — so the
bypass survives on a correctly cashier-provisioned terminal, not only on an owner-provisioned one.

Rule 4 says **do not fix it in this lane**. But the report's §3 table (line 97) presents today's
sales as "deliberately open to all — **unchanged**" with no note that it defeats the concealment the
same lane adds two rows below, and §6 does not list it. That is the disclosure defect: the gap
ticket's own thesis ("an operator who can read the X report can reconstruct what the blind count is
concealing") applies verbatim to `/sales`, more directly. **Blocking as a disclosure item**: the
report and the LEDGER B-13 row must name `/sales` explicitly, and the owner's pending residual-(i)
product call must be put to them as covering `/reports` + X report + **`/sales`** together, not two
of three.

### [MINOR] F-3 — `cashDisclosurePolicy.ts:10-14` — stale docblock now falsified by this lane

> "`hasManagerAccess` takes the MAX of the PIN operator's roles and the logged-in device user's
> roles — on a terminal signed in with an owner account … a cashier PIN operator clears that gate."

This is the defect the lane removed. The next reader will take it as current. The *conclusion*
(the policy must be honoured independently of the route gate) still stands and should be kept —
rewrite the premise.

### [MINOR] F-4 — `Header.tsx:412-421` + `reportApi.ts:164-171` vs `ReportGenerationService.php:1290,1329` — the code join is only proven on the device builder

For `fiscal_schema_version !== 3` (or no shift) `xOpts = {}`, so `generateXReport` calls the server
`/pos/reports/x`, whose `payment_type` comes from `pos_receipt_payments.payment_type`
(`ReportGenerationService.php:1290,1329`). The mask assumes that equals the payment-method `code`
held in the store. Only the **local** builder is code-proven (`reportApi.ts:504-507` maps
`method.id → method.code`). Not a tenant-#1 exposure (v3 ⇒ local builder ⇒ `appendXReport`), but the
v2 join is unverified. The fail-closed fallback in F-1 subsumes this.

### [MINOR] F-5 — asymmetry worth stating as policy, not leaving as an accident

A cashier PIN is now refused the (read-only) X report, but still reaches End-of-Day →
`handlePrintZReport` (`Header.tsx:717`, `:523`), i.e. authoring `SESSION_CLOSE` / the Z report — the
strictly more consequential, once-per-shift immutable fiscal event. This is pre-existing and
presumably deliberate (the cashier closes their own drawer under blind count), and the seeder agrees
(`cashier` holds `pos.generate_z_report`). It should be recorded as an explicit policy line, because
after this lane the shape reads as an inconsistency.

### [MINOR] F-6 — `Header.test.tsx` "conceals nothing when there is no open shift" asserts something else

The case asserts the Reports button is absent when `mockShift = null` — true and worth pinning, but
it does not exercise the `shift === null` branch of `concealedTenderCodes` (`Header.tsx:161-165`),
which is therefore untested. Either rename the case or drive the branch through `XReportModal`
directly.

---

## 3. Ruling on the implementer's concern 2 — server trusts the device token; the PIN operator is never on the wire

**ACCEPT. The device-side gate is the effective control for tenant #1. Do not build a server-side
PIN-operator check in B-13.** Verified grounds:

1. **The server gate is independent and correct today.** `cashier` does **not** carry
   `pos.view_reports` (verified in `RolesAndPermissionsSeeder.php`: present in the manager block,
   absent from the cashier block); `ReportController::generateXReport` authorizes
   `Gate::authorize('pos.view_reports')`. A cashier-provisioned terminal is server-refused now.
2. **For a v3 terminal the server X path is retired**
   (`ReportGenerationService::assertServerReportAuthoringAllowed` throws
   `ServerFiscalAuthoringRetiredException` for `fiscal_schema_version >= 3`; `reportApi.ts:159-171`
   falls through to the device builder). Per rule 20 the client fallback **is** the primary path, so
   a server-side check would have nothing to enforce on the path tenant #1 actually uses.
3. **A device-declared `operator_id` is not a boundary.** It would arrive from a client that already
   holds the login user's bearer token; a tampered device sends the owner's id and passes. Inventing
   that wire contract would add a trust-the-client shape while buying no authz.

**Conditions of acceptance:** the gate must be recorded as an *operational* control, not a security
boundary, and F-1/F-2 must land or be disclosed, since a device-only control with a fail-open branch
and an unlisted bypass route is weaker than the ruling assumed.

### Required LEDGER row (B-13 residual / structural) — suggested wording

> **B-13 (structural, carried):** the POS manager gate is a **device-side operational control, not a
> security boundary.** The PIN operator identity is never transmitted (`apps/pos/src/lib/api.ts`
> sends only `X-Client-Type` / `Authorization: Bearer` / `X-Company-Id`); every POS report endpoint
> authorizes the **device token user** (`ReportController`, `AnalyticsController`). On the ordinary
> single-account deployment that token is the **owner's**, so anyone with physical access to the
> terminal reaches every endpoint the owner can reach, PIN or no PIN. Closing this requires
> per-operator device tokens (PIN verification issuing a server-side scoped token) — program-level,
> out of B-13. Accepted for tenant #1 because (a) `cashier` lacks `pos.view_reports`, so a
> correctly-provisioned cashier terminal is server-refused, and (b) for `fiscal_schema_version >= 3`
> the server X path is retired and the device builder is the primary path.
> **Two residuals ride on this row:** (1) blind-count concealment is defeated by the ungated
> `/sales` route (per-receipt total + tender label for the open shift — `TodaySalesPanel.tsx`), which
> must be included in the owner's pending residual-(i) product call alongside `/reports` and the X
> report; (2) POS cash-drawer deposit/payout authorize `pos.operate_terminal` server-side, which
> **cashiers hold** — manager-only there is client-side only (reported, not changed, rule 4).

---

## 4. What to fix before merge

Make the X-report mask fail CLOSED when the physical-tender set is unresolvable (`Header.tsx:139-165`,
+ empty-store test), and disclose the `/sales` blind-count bypass in the report and the LEDGER B-13
row so the owner rules on all three surfaces together.

---

## r2 scoped re-review

**Range** `58a14ac25..0c84fc7c8` (5 commits) · worktree HEAD `0c84fc7c8`, tree clean · read-only
**Scope** the r1 fiscal/POS findings only, plus new Critical/Important introduced by the fix diff.

### VERDICT (r2)

**spec ✅ · quality CHANGES-REQUESTED** — 4 of 5 r1 items fully addressed, F-6 partially; one NEW
Important authorization widening introduced by the fix round.

### r1 item disposition

| r1 | Status | Evidence |
|---|---|---|
| **F-1** mask fails OPEN | **ADDRESSED** | `apps/pos/src/api/reportApi.ts:33-72` adds `is_physical?: boolean` to `PaymentMethodItem`; `:558-566` builds `physicalByCode` from `getAllPaymentMethods` (real booleans — `paymentRepository.ts:42` maps `row.is_physical === 1`); `:723-728` emits it per row, left `undefined` for an unresolved code. `XReportModal.tsx:74-76` `isConcealed(row, conceal) = conceal && row.is_physical !== false` — UNKNOWN conceals, explicit `false` is the only disclosure. `Header.tsx:148` is now a plain boolean and reads no store; the `usePaymentStore` snapshot effect and `NO_CONCEALED_TENDERS` are gone (no `physicalTenderCodes` reference survives anywhere in `apps/pos/src`). Empty-store regression pin: `Header.test.tsx:673-681`; unknown/missing-flag pins: `XReportModal.test.tsx:111,121`. |
| **F-2** `/sales` bypass | **ADDRESSED (fixed, per ruling)** | `TodaySalesPanel.tsx:93-98` `concealTakings = cashDisclosure === 'conceal' && !hasManagerAccess(operator) && shiftId !== null`; conceals net-sales tile `:190`, avg-ticket `:200`, returns amount `:207`, per-receipt total `:279`, tender label `:289`, withdraws `SaleDetailModal` `:300-309`, note `:329-331`. Matches the ruling (conceal tender + amounts for non-managers under blind count) and fails closed pre-answer via `useCashDisclosure`. Pins `TodaySalesPanel.test.tsx:372-447` incl. manager-sees-all, blind-off, no-operator, stale-authority. |
| **F-3** falsified docblock | **ADDRESSED** | `cashDisclosurePolicy.ts:10-28` — premise marked falsified, conclusion re-argued from two surviving facts. |
| **F-5** Z/X asymmetry | **ADDRESSED** | stated as explicit policy at `Header.tsx:378-411` (handleXReport) and `:546-552` (handlePrintZReport). |
| **F-6** mis-named test | **PARTIAL** | see r2-3. |

### Scrutiny items requested

**`is_physical` on the SERVER-built rows — verified absent, and the fail-closed consequence is real.**
`XReportResource.php:23-54` emits `payment_methods => $this->getPaymentMethods()`, which is
`XReport.php:165-168` `snapshot_data['payment_methods']`, built at
`ReportGenerationService.php:1288-1300` / `:1327-1339` with exactly three keys
(`payment_type` / `total_amount` / `transaction_count`). So on the v2/server path every row has
`is_physical === undefined` ⇒ **every tender row masks** under blind count. The namespace claim
behind R1-7 is also confirmed: `ReceiptPaymentService.php:358` writes `$paymentMethod->name` and
`PosCoreReceiptProjection.php:1539-1542` documents `payment_type` as the display NAME with the code
in `payment_method_code` — the old code-join was a guaranteed no-op server-side. **Acceptable as
fail-closed, not a fiscal regression** (over-conceal, display-only), but see r2-2.

**403 handling.** `reportApi.ts:198-231` — `isAuthorizationRefusal` rethrows only
`ApiRequestError` 401/403 (`lib/api.ts:43-53`, `:137-168` construct it with the real status);
404/5xx/transport still fall back. Offline still authors locally: a transport rejection reaches
`generateLocalXReport`, and the v3 path never touches the server at all (`:202-204` early return).
No `X_REPORT` on a refusal — structurally as well as by the rethrow: `appendXReport` is guarded by
the five fiscal opts (`reportApi.ts:732-740`), which the server-fallback path never carries. Pinned
`generateXReport.authz.test.ts:83-127` (asserts `appendXReport` and even `queryAll` uncalled).

**Fiscal payload unchanged — confirmed.** `paymentMethodTotals` at `reportApi.ts:771-775` is still
the three-field allow-list, so `is_physical` never reaches the event; `lib/fiscal/*` is not in the
diffstat; `XReportResponse` is in-memory only (no persistence, no push — only `Header.tsx:94` and
`XReportModal`). No X-report print path exists that could bypass the modal mask.

**Rule 20.** The one new SQLite-time read is correct: `operatorAuthorityFreshness.ts:59` uses
`sqliteUtcToDate` on `operator_pins.synced_at`, which is `datetime('now')`-stamped on both insert
and `ON CONFLICT DO UPDATE` (`operatorPinRepository.ts:129-130,:220`), and `SELECT *` (`:96,:103`)
carries the column. No `toISOString()` is bound into any SQL comparison in the diff. No new
`onQueue`, no projection/queue-reachable code, no `apps/api` change. No float touches money.

**No manager lockout from the permission switch** (checked because it would have been Critical):
`admin` gets `Permission::all()` (`RolesAndPermissionsSeeder.php:545`); pin-data and verify-pin both
ship `getAllPermissions()->pluck('name')` (`PosAuthController.php:226`, `:99`); `AuthUserData:43`
does the same for the login user that seeds `setupPin`; empty `permissions` falls back to role names
(`roles.ts:121-126`); `pullOperatorPins` runs in the periodic sync loop (`syncService.ts:2322`), so
an online terminal refreshes `synced_at` well inside the 7-day TTL.

### New findings (fix diff only)

**[IMPORTANT] r2-1 — `roles.ts:57-60` + `SettingsPage.tsx:99,107` — the permission gate silently
promotes `accountant` on the device, including DEVICE UNBIND**

`MANAGER_SURFACE_PERMISSION.reports = 'pos.view_reports'` now gates not only the report surfaces but
also `/shift`, `/reports/z`, the opening-float tooltip and the **device-unbind** action — the
docblock at `roles.ts:47-49` says so ("which have no server permission of their own"). But
`pos.view_reports` is seeded to **two** roles, not one: `manager` (`RolesAndPermissionsSeeder.php:619`)
and **`accountant`** (`:837`, granted 2026-08-12 for POS receipt reporting). `pinHolders`
(`PosAuthController.php:46-57`) applies no role filter — any active company member with a `pos_pin`
is a PIN operator — so an accountant with a till PIN now clears every "reports" surface. At r1 head
they scored 0 on the role ladder and were refused everywhere.

Reports parity with the server is defensible. **Unbind is not**: `SettingsPage.handleConfirmUnbind`
(`:106-111`) tears down the POS session stores and calls `authStore.unbindDevice` (`:481-508`), which
clears `LOGIN_TENANT_ID` + token + `StorageKeys.TERMINAL` and resets the terminal store — the
terminal must be re-provisioned, mid-shift if that is when it happens. Binding a destructive device
action to a *reporting* permission granted to a back-office role is an authorization widening
introduced by commit `4c680daa1`, and neither the report's R1-4 section nor the LEDGER rows mention
it.

*Fix:* either add a third surface (e.g. `device` → a permission `accountant` does not hold — the
seeder has no natural one, so `pos.approve_cash_drawer_control` or an explicit
`isManagerRole(operator.roles) && permission` conjunction for unbind), or keep the role-name ladder
for unbind only and say so at `SettingsPage.tsx:99`. If the intent is that accountant *should* reach
POS reports, that is fine — but it must be a disclosed, deliberate line in the report, and unbind
must not ride on it.

**[MINOR] r2-2 — v2/server X reports now mask EVERY tender, diverging from `/reports` on the same
terminal**

`ReportsPage.tsx:180` conceals `concealCash && m.is_physical` off a row flag the preview *does*
carry, so on a v2 terminal `/reports` shows the card split while the X report shows em dashes for
every row. r1 F-1's ask was that the two surfaces agree; the fail-closed fix satisfies safety but
re-opens the divergence in the opposite direction. Disclosed in `XReportModal.tsx:65-72` and report
§"Fix-round concerns 3", so this is a note, not a block — but the durable fix is one field on
`ReportGenerationService`'s payment aggregation (`:1290`, `:1329`), which is out of this lane.

**[MINOR] r2-3 — `Header.test.tsx:715-730` — F-6 only half closed; the new case still does not test
what its name says**

The old case was correctly renamed to "makes the X report unreachable when there is no open shift"
(`:705`). The replacement, `'conceals nothing with no open shift — nothing is being counted'`
(`:715`), renders with `mockShift = null`, then **re-renders with a shift** and asserts
`x-concealed === 'true'` (`:729`). It never asserts the `shift === null` ⇒ `false` branch of
`concealPhysicalTenders` (`Header.tsx:148`), which stays unpinned, and the title is again the
opposite of the assertion — the exact defect F-6 raised.

**[MINOR] r2-4 — `operatorStore.ts:204` — `authority_stale` is evaluated once, at PIN verify**

The TTL is a snapshot taken on the offline verify branch and stored on the in-memory operator; a
session that is never locked/re-verified keeps its verdict past the 7-day boundary. Bounded in
practice by the inactivity lock, and the direction on the other side is conservative (a stale flag
is not cleared by a later successful pull either). Worth one line in the docblock at
`operatorAuthorityFreshness.ts:39-51`.

**[MINOR] r2-5 — `operatorAuthorityFreshness.test.ts:57-62` — the rule-20 guard is timezone-dependent**

`isOperatorAuthorityStale(justWritten, NOW, 1000)` only goes red for a naive `new Date()` parse when
the runner sits EAST of UTC (age becomes `+offset` > 1000ms). Under `TZ=UTC` — and `apps/pos` pins no
TZ in vitest config — the naive parse yields age 0 and the test passes anyway, so the guard against
exactly the rule-20 class of bug does not bite in CI. Set `TZ` (or assert
`sqliteUtcToDate(stamp).getTime() === NOW` directly). Same family: the `synced_at` fixture at
`operatorStore.test.ts` uses `new Date().toISOString()`, a format production never writes to that
column (`upsertOperators` writes `datetime('now')`), and the comment at
`operatorAuthorityFreshness.test.ts:65` ("the online verify path writes one") is not accurate — no
producer writes ISO into `operator_pins.synced_at`.

### What to fix before merge (r2)

Decide and disclose r2-1 — unbind must not be reachable via `pos.view_reports` (which `accountant`
holds) unless the owner rules that it should; then close r2-3 with a real `shift === null`
assertion. r2-2/r2-4/r2-5 are notes.

## r3 scoped re-review (range 0c84fc7c8..947e3b655) — VERDICT: ALL ADDRESSED — mergeable
> Provenance: verdict returned by the scoped fiscal/POS re-reviewer; its file append was lost with the lane worktree — restored by the orchestrator (final review I-2). Key evidence from the verdict:
- r2-1 ADDRESSED: third surface `terminal: 'pos.manage_terminals'` (`roles.ts:57-61`), `SettingsPage.tsx:99` gates Device & Security + unbind modal + `handleConfirmUnbind`; `ReportsMenu.tsx` split so each entry filters on the permission its handler enforces (closes R2-2); pinned by new accountant-parity tests (reads reports, refused terminal/cash_drawer) and `SettingsPage.test.tsx` 14→17.
- r2-3 ADDRESSED honestly: extracted `shouldConcealTakings(disclosure, hasOpenShift)` in `cashDisclosurePolicy.ts` with all four combinations pinned at the seam; the Header-level `shift === null` branch is genuinely unreachable from the UI.
- r2-5 ADDRESSED: TZ-independent assertions via `sqliteUtcToDate` + explicit offset computation.
- No `apps/api`, no `lib/fiscal/*`, no Event class in the diffstat; both credential-failure branches refuse before any local X_REPORT authoring; the accountant read-parity amendment is present for both LEDGER rows in the task-7 report (single shared amendment).
