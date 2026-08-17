# M4 (SV-10) — Round 4 adversarial merge gate

**Diff reviewed:** `df85d43f4..HEAD`. M4's own delta isolated as `1eef6fe79..HEAD` (`39a72abc0`, `1885a764e`, `314ea156e`, `e5152f669`, `00e8667af`, `8066db447`, `7cfecf21e`, `6961d3ad2`) — production surface is 4 `apps/pos` React files + 2 locale files. Backend files in the wider range belong to M1–M3 (ACCEPTed) and were not re-litigated.

**Lenses.** `fiscal-pos` — applies (blind-count disclosure at shift close, Z availability). `frontend-conventions` — applies (rendered-output tests, tokens, i18n). `treasury`, `tenancy-authz` — not named for M4; not applied. **Standing checks N/A by construction:** no migration, no queue, no PHP, no `app()`, no tenant query key, no money arithmetic added (`format()` / `'—'` only) — so Rule 19, migration-safety, Horizon coverage and constructor-injection have no surface in M4's delta.

**Independently reproduced:** the four suites — `Header.test.tsx` (11), `CashReconciliationSection.test.tsx` (16), `EndOfDayPreviewModal.test.tsx` (30), `CashCountTable.test.tsx` (8) = **65/65 green**; `npx tsc --noEmit` exit 0. Matches the report.

**Round-3 findings are genuinely closed.** F1 (Today's Sales) → sibling audit row + `docs/superpowers/tickets/2026-08-17-today-sales-blind-count-derivation.md`, and the audit's Result sentence is now scoped to the modal rather than the device. F2 (i18n) → `cash_count.policy_unavailable` present in `apps/pos/src/locales/en/pos.json:909` and `fr/pos.json:909`, pinned by a test that asserts both files verbatim and renders the French line. F3 (fail-closed availability) → `docs/superpowers/tickets/2026-08-17-blind-count-policy-unavailable-close.md` + a real Header test (`fetchFraudSettings` rejects, `getCompanyFraudSettings` → null ⇒ `true:none`). F4 → tolerance drill-down and net-cash-rounding now carry explicit clean-for-literal verdicts. F5 → resolution is now keyed to the terminal *object* (`Header.tsx:110-111`), so a terminal-record change makes it unresolved synchronously. F6 → `phase === 'error' ? errorMessage : …` (`EndOfDayPreviewModal.tsx:214-221`).

**Spot-checks of "clean" verdicts hold:** `ShiftClosurePage` is hard-coded static (`ShiftClosurePage.tsx:18-21`) behind `isManager` (`AppShell.tsx:223-225`); `ZReportModal` has no production JSX caller. And an independent enumeration — `grep -rln "expected_cash|expectedCash|variance" apps/pos/src --include=*.tsx` minus tests — returns **exactly** the seven files the audit lists, so the enumeration axis is complete.

What follows is new, and it lives inside the mechanism round 3 introduced.

---

## Register

### 1. P2 — CONFIRMED — `apps/pos/src/components/pos/EndOfDayPreviewModal.tsx:82`, `:110-113`, `:123-125`, `:232-234` (with `Header.tsx:110-111`)
**The parent's `cashCountsCommitted` survives the child's unmount, so after a mid-open policy re-resolution the blind, *pre*-Commit screen renders every financial amount — including a physical tender's exact expected total.**

Two independent state atoms now encode the same boundary:
- child `committed` — `useState(!blindMode)` at `CashReconciliationSection.tsx:90`, destroyed on unmount;
- parent `cashCountsCommitted` — `EndOfDayPreviewModal.tsx:82`, reset **only** when `isOpen` goes false (`:123-125`; `setCashCountsCommitted` has exactly three references, verified by grep).

Round 3's fix makes `cashCountPolicyResolved` flip to `false` synchronously whenever the `terminal` object identity changes (`Header.tsx:110-111`) — their own test `re-arms policy loading immediately when the terminal record changes` asserts exactly this state (`false:true`). While unresolved, `cashCountPolicyPending` is true and the entire preview block — `CashReconciliationSection` included — is **unmounted** (`:232-234`). When the refresh resolves, the child remounts fresh (`committed = !blindMode` = **false**, `actuals = {}`), while the parent flag is still `true` ⇒ `hideFinancialAmounts` (`:110-113`) is **false**.

**Failure scenario.** Blind mode on. Cashier counts the drawer, presses Commit Counts (parent flag → true, amounts reveal). Before confirming — while entering a variance reason or waiting for a manager PIN — a sync tick's `refreshTerminalRecord` publishes a changed terminal (`terminalStore.ts:1041`; fires on a training-mode toggle, a rename, a `pos_stock_policy` change, or a live counting-block/zone advisory — `:1017-1032`). Modal → loading → back to preview. The tender table is blind again (Expected/Variance hidden, Commit Counts button back, counts blank) but the modal now renders `Gross Sales`, `Net`, `Tax`, the full VAT table, and **every payment-method total** (`:270-281`, `:328-336`, `:362-364`). The physical CHECK/CASH rows are the *exact* per-tender expected amounts the table is hiding two elements above, and gross sales + the always-visible opening float (`CashReconciliationSection.tsx:255-265`) is the very reconstruction round 2 hid. The cashier re-enters the derived figures and commits a zero variance.

This is a literal breach of §5.1 ("no surface renders expected or variance magnitude before Commit Counts"), reached with no privilege, in the same modal M4 walked — it is the round-1 and round-2 leaks re-opened by the round-3 gate. Nothing covers it: the Header tests use a mocked modal, and every modal test passes `cashCountPolicyResolved` as a **static** prop — no test exercises `true → false → true` at all, let alone after a commit. Remedy is one line plus one rendered test: reset `cashCountsCommitted` (and `cashCountReady`/`cashCountPayload`) whenever `cashCountPolicyPending || cashCountPolicyUnavailable`, i.e. wherever the child can be torn down. Severity is P2 rather than P1 only because it needs a terminal-record change inside the open window.

### 2. P3 — CONFIRMED — `apps/pos/src/components/Header.tsx:116-231` vs `:239-245`
**The mid-open refresh path is not symmetric with the open path: it re-marks the policy resolved without clearing the previous one.**

`handleOpenEndOfDay` fails closed by nulling `fraudSettings`/`authorizedManagers` before opening (`:242-244`). The effect triggered by a *terminal change* does neither: if both the online fetch and the `company_fraud_settings_cache` read fail, no setter runs, yet `finally` still executes `setCashCountPolicyTerminal(terminal)` (`:230`) — so the modal re-resolves as `available` on the **previous** in-memory policy. Their own round-3 test observes it (`false:true` — settings retained). Impact today is low: the retained policy was fetched seconds earlier in the same open, so it cannot be a pre-SV-9 `false`. But the invariant the audit asserts — "resolution records the exact terminal object … the modal renders only loading while unresolved, an unavailable-policy error if resolution yields no settings" — is not true on this path, and it is the path that will grow (a future terminal-scoped policy would silently carry over). Either clear the settings at the top of the effect body, or state the exception in the audit.

### 3. P3 — CONFIRMED — `apps/pos/src/components/pos/EndOfDayPreviewModal.tsx:232-234`
**A transient policy re-resolution silently discards the operator's already-entered physical counts.**

Same unmount as finding 1: `actuals`, `reason` and `verifiedManager` (`CashReconciliationSection.tsx:91-93`) die with the child, with no message and no confirmation. A cashier mid-way through counting a multi-tender drawer loses the entry and must recount, triggered by an unrelated back-office edit. Neither M4 ticket covers this — `2026-08-17-blind-count-policy-unavailable-close.md` scopes only the *unavailable* (hard-block) state, not the transient refresh. Worth a line in that ticket, or preserve entry state across the pending window (which finding 1's fix should be designed alongside, not against).

---

## Bypasses I tried that FAILED (defences hold)

- **Second EOD entry point.** `setShowEndOfDay(true)` has exactly one call site (`Header.tsx:245`) and it is the re-armed handler.
- **Missing sibling surfaces.** Independent grep for `expected_cash|expectedCash|variance` over non-test `.tsx` returns precisely the seven files the audit enumerates — no surface is missing from the walk.
- **`CashDrawerModal`** (reachable from the same Reports menu, absent from the audit): manager-only (`ReportsMenu.tsx:62`), and it renders no expected/variance value — an input form only. Immaterial omission, not a finding. (Its pre-existing `parseFloat` at `CashDrawerModal.tsx:38` is **outside** this diff — adjacent Rule 19 note only, no action asked here.)
- **Prop-shape bypass of `cashCountEnabled`.** Header supplies all six required props (`Header.tsx:721-727`), `cashierUserId` defaulted to `''`, so the legacy expected-cash card (`:283-306`, byte-unchanged from base) cannot mount in production while a policy exists.
- **Non-blind / legacy regression.** `hideFinancialAmounts` requires `require_blind_cash_count === true` and `cashCountPolicyResolved` is optional — unmigrated callers render exactly as before; the pre-existing suites are green.
- **Error-precedence re-break.** `phase === 'error'` now wins, and a test proves `preview exploded` is shown while the policy message is absent — I could not construct a state where a genuine error is masked.
- **i18n fallback.** Both locale files really carry the key; the test pins the literal English and French strings and asserts the English is *absent* under `fr`, so a silent `defaultValue` fallback would fail.
- **DOM residue.** Hidden money is not rendered at all (`'—'` substitution, and the Expected/Variance columns are omitted from the table, not CSS-hidden) — nothing recoverable from the DOM or accessibility tree.
- **Vacuity.** The new regressions assert rendered amounts with `within()` scoping and cross the commit boundary in both directions; the revert/replay evidence (3 named failures for the three round-3 fixes) is specific and matches the code.

## Rationale

Rounds 1–3 hardened this modal well, and the audit is now a genuine render-path enumeration with tickets where the row does not reach — the M4 antidote is satisfied on its own terms. What fails is the last fix's blast radius: making the section unmountable on policy re-resolution introduced a second commit-boundary atom that outlives the first, and in that window the blind pre-commit screen shows the exact amounts rounds 1 and 2 removed. It is the same defect class the wave has now closed three times, it has no test, and the fix is one line.

VERDICT: CHANGES-REQUIRED
