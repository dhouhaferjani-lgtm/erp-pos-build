# Gate — request-hygiene web follow-ups A (frontend-conventions, adversarial)

- Date: 2026-09-04
- Reviewer: frontend-conventions-reviewer (adversarial merge gate)
- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-webfu` (read-only review; all probes reverted)
- Branch: `lane/rh-web-followups-a` · base `e829444d5` (= `dev`, verified `git rev-parse dev` → `e829444d58c9f9c250227c18a10e7703cfef5e06`)
- Commits: `9a826d23b` (A / T13 MAJOR-4), `e56b6b6a7` (B / T8 R2-N5), `18e49f051` (C / T14 NB-r2-1/2/3), `d0c3f5136` (handback)
- Diff: 9 files, +257 / −27; `apps/web` = 8 files, `apps/api` never opened (confirmed by `git diff --stat`)

## VERDICT: **APPROVE** (merge). Zero blocking findings. Six non-blocking. Browser legs remain promotion-owed.

---

## 1. Blocking findings

**None.** Every claim in the handback was re-derived independently (red-first re-run, four mutants of my own, dead-branch falsification, per-file lint delta, merge-tree). Nothing in the handback was taken on report.

---

## 2. Non-blocking findings

- **NB-1 (MINOR, this lane) — French terminology regression inside the namespace.** `apps/web/src/locales/fr/stock-adjustments.json:26` is the **only** occurrence of "ajustement" in a file that says "régularisation" **11 times**, including the title of the very screen this toast fires on (`:5` `"Nouvelle régularisation de stock"`, `:2` `"Régularisations de stock"`). The operator sees "Nouvelle régularisation de stock" in the header and "Impossible d'enregistrer l'ajustement" in the toast — two names for one noun on one screen (`docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md`, copy layer). *Fix directive:* `"Impossible d'enregistrer la régularisation. Vérifiez si elle a bien été créée avant de réessayer."` (note the feminine agreement `créée`).
- **NB-2 (MINOR, this lane) — the corrected overclaim survives one line below the corrected docblock.** `apps/web/src/hooks/__tests__/useDraftAutoSave.state.test.tsx:521` still reads `// Unsent edit queued -> the beforeunload/navigation guard must be armed.` The docblock at `:485-499` was rewritten precisely to stop calling it a navigation guard (NB-r2-1), and `useUnsavedChangesGuard.ts:9-12` blocks no in-app navigation. *Fix directive:* drop "/navigation" — "the beforeunload guard must be armed".
- **NB-3 (MINOR, item B design tradeoff, accepted) — a late first connect arms the 30 s cooldown.** `WebSocketReconnectProvider.tsx:80-99`: a slow-but-successful first handshake at, say, t=6 s now sweeps *and* sets `lastInvalidationAt`, so a genuine drop/reconnect at t≤36 s is suppressed. That is one bounded duplicate sweep plus one bounded suppression window; it is the same arming every reconnect does and it is explicitly pinned by the new test (`WebSocketReconnectProvider.test.tsx:129-135`). Recorded, not required to change. If it ever bites, the narrow fix is to sweep without arming on the late-first-connect edge only.
- **NB-4 (MINOR, tooling blind spot, pre-existing) — `audit:i18n:local` cannot see an entirely-missing `ar` namespace file.** 21 of 56 `en` namespaces have no `ar` counterpart (`ls src/locales/{en,ar} | wc -l` → 56 / 35) and the audit reports **zero** findings for them. For this namespace the gap is deliberately closed in code: `src/lib/i18n.ts:463-465` aliases the `ar` `stock-adjustments` bundle to the **English** bundle ("the same treatment stock-transfers gets"), so the new key is automatically served to `ar`. No lane action; worth a tooling ticket.
- **NB-5 (inherited, blocks the PROMOTION BATCH, not this lane) — `pnpm --filter @autoerp/web lint` is RED at `dev`.** Two steps fail, both on files this lane does not touch:
  - `audit:design-system` → `810 violations, 796 acknowledged, 14 new, 11 stale`; **all 14 new** are `src/features/import/pages/ImportWizardPage.tsx:867/:911/:920/:1041/:1061/:1070/:1199/:1213/:1250/:1259/:1372/:1384/:1498/:1511`. Identical to T14 r2's NB-1 measurement. `tools/audit-design-system-baseline.json` is **not** in this diff — no `--write-baseline` absorption, no alias/indirection, no suppression comment (mechanism audit clean).
  - `audit:i18n:local` → `62 NEW gap(s)`, all `ar|uom|*` (58) and `ar|import|plural|unitErrors.line_*` (4). **Zero** lines mention `stock-adjustments` (grep, 0 hits) — the lane adds no i18n debt.
  Because the chain short-circuits at `audit:design-system`, I ran the remaining steps individually: `audit:quantity` **0/0/0**, `test:eslint-rules` **all 6 RuleTesters passed**, `test:tools` **8 files / 166 tests passed**.
- **NB-6 — browser legs are promotion-owed and NOT covered by this gate.** Still open from T13 §8: (1) the double-click probe on both create forms with the zero-5xx assertion, (2) MINOR-1's real-UUID capture during it. Item A closes only priority (2) of that list on the code side; nothing here observes a real `toast` render, a real Reverb handshake, or a real network failure. T8's grace window in particular has **no** staging observation of actual handshake latency — see the constant ruling in §4.

Carried-over and correctly disclosed as out of scope: T14 NB-3 (`saveNow`/`reset` have no production caller), NB-4 (floating `performSave()`), NB-5 (`||` at `useDraftAutoSave.ts:234`), T8 R2-N6 (stale handback net-diff line).

---

## 3. What held up (verified independently)

### Item A — generic error surface (`9a826d23b`)

| Claim | Verified at | Result |
|---|---|---|
| Canonical toast, not an ad-hoc one | `CreateStockAdjustmentPage.tsx:9` `import { toast } from 'sonner'`; 148 non-test `from 'sonner'` sites repo-wide; `<Toaster position="top-right" richColors />` mounted at `App.tsx:34` | OK — same mechanism and same `create.error` call shape as `CreateStockTransferPage.tsx:727`, which the T13 gate named as the model |
| Toast only when there is no envelope | `:318-326` — `const envelope = extractRefusal(error); setRefusal(envelope); if (envelope === null) toast.error(t('create.error'))` | OK |
| Envelope refusals byte-unchanged | inline surface `:648-650` `refusal !== null && acknowledgeableCode === null`; acknowledgeable codes still route to the dialog | OK — the second new test (`…test.tsx:473`) pins the boundary: a typed refusal renders inline and does **not** toast |
| The toast can't swallow a 422 | `refusals.ts:129-135` returns non-null whenever `response.data.error` is an object, and this app's validation envelope is `{error:{errors}}` → 422 goes to the inline surface, never the toast | OK — the "check whether it was created" copy is reserved for genuine transport/lost-response cases, where it is the correct thing to say |
| Retry safety behind the copy | the idempotency key is reset **only** on success (`:308-312`), so a retry after this toast replays the same key | OK — the copy under-claims rather than over-claims a guarantee (owner rule: UI must not overstate system guarantees; under-stating is safe) |
| Key exists, translated, not a raw key | `en/stock-adjustments.json:26`, `fr/stock-adjustments.json:26`, both real sentences, both inside the `create` object (same block as `create.loadFailed`, which the page already resolves) | OK (wording nit = NB-1) |
| Namespace resolution | `CreateStockAdjustmentPage.tsx:104` `useTranslation(['stock-adjustments','common'])` → default ns is `stock-adjustments`; registered at `src/lib/i18n.ts:217/274/465` and in the `ns` array `:485` | OK |

**Red-first, re-run by me** (base source restored in place, shipped test kept):
```
git show e829444d5:apps/web/src/features/stock-adjustments/pages/CreateStockAdjustmentPage.tsx > src/features/stock-adjustments/pages/CreateStockAdjustmentPage.tsx
pnpm vitest run src/features/stock-adjustments/__tests__/CreateStockAdjustmentPage.test.tsx
  × CreateStockAdjustmentPage — the generic error surface > toasts a generic error when the failure carries no refusal envelope 1093ms
  AssertionError: expected "spy" to be called 1 times, but got 0 times
  Tests  1 failed | 14 passed (15)
git checkout -- src/features/stock-adjustments   # → 0 dirty files
```

### Item B — initial-connect grace window (`e56b6b6a7`)

| Claim | Verified at | Result |
|---|---|---|
| Module constant | `WebSocketReconnectProvider.tsx:28` `const INITIAL_CONNECT_GRACE_MS = 5_000` | OK |
| Stamp taken in the effect, not in render | `:59` `const mountedAt = useRef<number \| null>(null)`; `:65` `const mountedAtMs = mountedAt.current ?? Date.now()` **inside** `useEffect` | OK — no `Date.now()` in the render body; `react-hooks/purity` silent (per-file lint below). The `?? ` form is load-bearing: mutant 3 proves a re-stamp on every effect run breaks the feature |
| First connect inside the window → no sweep, no cooldown | `:73-84` — `wasDisconnected.current = false; return` before `lastInvalidationAt` is ever written | OK, pinned by the pre-existing test `…test.tsx:65-108` |
| First connect after the window → one sweep + cooldown armed | falls through `:86-99` | OK, pinned by the new test `…test.tsx:115-138` (`toHaveBeenLastCalledWith({ refetchType: 'active' })`, then a flap at +3 s → still 1) |
| Genuine reconnects unchanged | `hasEverConnected` is now assigned unconditionally at `:74` instead of inside the branch — equivalent, since the old code assigned it on exactly the first connected run too | OK; both pre-existing tests still green |
| Give-up interaction (15 s give-up → 20 s first connect sweeps) | `useWebSocketConnection.ts:34` `CONNECTION_GIVE_UP_MS = 15000`; `giveUp()` sets `isConnecting:false, hasGivenUp:true` and **never** clears `isConnected`, so `wasDisconnected` stays true and the late connect sweeps | OK — exactly the scenario the new test drives at t=20 s |
| Mount point makes the stamp meaningful | `DashboardLayout.tsx:47` mounts the provider **inside** the authenticated layout wrapping `<Outlet/>`, so "mount" ≈ dashboard load ≈ login, not app boot. The provider is not remounted per route (it wraps the `Outlet`) | OK — the grace window measures the right interval |

**Red-first + four mutants, all run by me, each reverted (`git status` clean after each):**

| Probe | Command | Result |
|---|---|---|
| RED (base provider, shipped test) | `git show e829444d5:…/WebSocketReconnectProvider.tsx > …` | `× sweeps a FIRST connect that lands after the grace window` — `expected 1 times, got 0` · `1 failed \| 2 passed (3)` |
| Mutant 1 — grace widened to `60_000` | `sed` on `:28` | `× sweeps a FIRST connect…` · `1 failed \| 2 passed (3)` |
| Mutant 2 — grace check short-circuited to `if (false)` | `perl -0pi` on `:80` | `× ignores the initial connect: only a genuine reconnect invalidates and arms the cooldown` — `expected +0 times, got 1` · `1 failed \| 2 passed (3)` |
| Mutant 3 (my addition) — `mountedAtMs = Date.now()` re-stamped on every effect run | `perl -0pi` on `:65` | `× sweeps a FIRST connect…` · `1 failed \| 2 passed (3)` |

Mutants 1 and 2 fail on **opposite** tests: the window is measured in both directions, not merely present. Mutant 3 additionally proves the ref-stamp semantics (not just the constant) are pinned.

### Item C — dead branch + comment corrections (`18e49f051`)

| Claim | Verified at | Result |
|---|---|---|
| Deleted branch was genuinely dead | reasoning re-derived: the settle handler is reached only with `pendingRef.current === true`, and the unmount cleanup sets `pendingRef.current = false` **synchronously** (`useDraftAutoSave.ts:456`) before any promise can settle, while `performSave` refuses to refill after unmount. Falsified empirically: base file restored with `throw new Error('DEAD BRANCH REACHED')` as the first statement of the removed `if` → `pnpm vitest run src/hooks` = **16 files / 90 tests passed**, no throw | OK — dead, deletion is behaviour-preserving |
| Corrected comment relocated | `:442-455` at the unmount cleanup, where the discard actually happens | OK |
| Comment states the REAL guarantee | `useUnsavedChangesGuard.ts:9-12` verified verbatim: `beforeunload` only, "Full in-app route blocking (useBlocker) is deferred"; the "two explicit in-app discard points" are `DocumentForm.tsx:527` and `:749` (`confirmDiscard`), and `:307` installs only the listener | OK — citation `useUnsavedChangesGuard.ts:9-12` at `:452` is accurate |
| `useBlocker` residual referenced | `:453-455` + handback §4 | OK |
| `autosavePending` doc accurate | `:54-60` now: scheduled **or** in flight **or** trailing slot holds an untransmitted body; cleared only when a save settles with nothing queued | OK — matches NB-r2-3's directive and matches the code (`:212/:251/:269/:340` set/clear sites) |
| Retitled test still measures its name | `…state.test.tsx:500` "keeps the pending guard armed over an unsent body, and the unmount cleanup drops it without a POST": body asserts `autosavePending === true` while the slot holds `v1` (`:521-522`) and `apiPost` still `toHaveBeenCalledTimes(1)` after unmount + three drained microtasks (`:524-531`) | OK — title and assertions agree; nit NB-2 on the inline comment |
| No test added/removed | 16 tests before and after | OK |

### Cross-cutting checks

No `PageHeader`, form-atom, `DataTable`, `StickyFormFooter`, picker, design-token, `tenantScopedKey`, `apiGet`/`apiPost` unwrap, money or quantity surface in the diff (the only JSX-adjacent change is a `toast.error` call). No catalogue entity, no unique key, no new noun, no new route or nav entry → **second-of-everything / one-surface-per-concept / benchmark-first: N/A** for this diff (the copy-level noun clash is NB-1). No owner-ruled UI principle in scope: no competing accent or badge, no enrichment band, no disabled dead control, no brand literal ("AutoERP"/"Syneriva" absent from the diff), no refunds/counting surface, no permission gate. The one guarantee-adjacent item — the "check whether it was created" copy — under-claims rather than over-claims, which is the safe direction.

---

## 4. Rulings requested

**`ar` parity for the new key: ACCEPTABLE as shipped — no `ar/stock-adjustments.json` is required, and creating one would be a regression.** `src/locales/ar/` has no `stock-adjustments.json` (nor `stock-transfers.json`); 21 of 56 namespaces are in the same position. This is not an accident: `src/lib/i18n.ts:463-465` deliberately registers the **English** bundle as the `ar` resource for the whole namespace — *"ar has no stock-adjustments bundle: fall back to English for the whole namespace, the same treatment stock-transfers gets."* Consequence: the new `create.error` is already served to `ar` (in English) with **no** raw-key leak, which is what rule 11 protects against. Minting a lone-key `ar/stock-adjustments.json` would replace that whole-namespace alias with a partial bundle and turn every other key into a per-key fallback — strictly worse. The genuine gap is the tooling blind spot (NB-4), not this lane.

**`INITIAL_CONNECT_GRACE_MS = 5_000`: ACCEPTED as the right constant.** Four reasons. (1) It is the T8 r2 gate's own prescription — R2-N5's fix directive reads "`mountedAt` ref + 5 s". (2) It sits correctly between the two real timings it must separate: the observed handshake lands ~1 s after mount, and the give-up path it must catch is ≥15 s (`useWebSocketConnection.ts:34`), so there is a 3× margin on one side and a 3× margin on the other. (3) The failure mode of being *too short* is bounded and arguably correct: a handshake at t>5 s means queries fetched at mount are ≥5 s stale and events published in the gap really were missed, so sweeping is defensible; the residual cost is one duplicate `refetchType: 'active'` plus a 30 s cooldown (NB-3). The failure mode of being *too long* is the N-1 defect the whole lane exists to avoid. (4) Being *too short* is self-limiting: pure transport connect (`pusher` `connected`), not channel auth. **Condition:** no staging measurement of real handshake latency exists — fold a single `performance.now()` handshake-duration observation into the promotion browser leg, and if staging p95 exceeds ~3 s, raise the constant to 8–10 s (still well under the 15 s give-up). That is a data question, not a merge blocker.

---

## 5. Commands and outputs

All from `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-webfu/apps/web` unless stated. DEFAULT vitest pool, never `--singleFork`. `ps aux | grep -c '[n]ode (vitest'` = **0** at the end.

```
$ pnpm lint
  lint:eslint          → ✖ 6450 problems (0 errors, 6450 warnings)          [pre-existing repo debt]
  audit:keys           → Gate C: 0 violations; baseline 0 acknowledged / 0 new / 0 stale   PASS
  audit:design-system  → 810 violations; 796 acknowledged, 14 new, 11 stale  FAIL (exit 1)
                         all 14 new in src/features/import/pages/ImportWizardPage.tsx (untouched by this lane)
                         tools/audit-design-system-baseline.json NOT in the diff
  [chain short-circuits here]

$ pnpm audit:quantity       → raw quantity display sites: 0 total (0 baselined, 0 new, 0 stale)   PASS
$ pnpm audit:i18n:local     → 62 NEW gap(s): 58 × ar|uom|*, 4 × ar|import|plural|unitErrors.line_*
                              grep -ci 'stock-adjust' → 0                      FAIL (exit 1, pre-existing)
$ pnpm test:eslint-rules    → 6/6 RuleTesters passed (incl. no-dead-tailwind-token-interpolation,
                              no-untranslated-literal, no-literal-decimal-places)   PASS
$ pnpm test:tools           → Test Files 8 passed (8) · Tests 166 passed (166)      PASS

$ pnpm typecheck            → tsc --noEmit, exit 0                                  PASS

$ pnpm vitest run src/features/stock-adjustments src/providers src/hooks
  Test Files  24 passed (24)
  Tests      160 passed (160)                                                       PASS
  (baseline at e829444d5 per handback: 157; +3 = 2 for A, 1 for B — the three I re-drove red above)
```

**Per-file ESLint, base restored in place vs shipped, identical invocation** (`git show e829444d5:apps/web/<path> > <path>` … `git checkout -- <dirs>`, tree clean afterwards):

| Version | Result |
|---|---|
| Base `e829444d5` (6 files) | **8 problems (0 errors, 8 warnings)** — `restrict-template-expressions` ×3 (`CreateStockAdjustmentPage.tsx:362,414,437`), `unbound-method` ×1, `array-type` / `prefer-nullish-coalescing` / `set-state-in-effect` / `no-floating-promises` ×1 each (`useDraftAutoSave.ts`) |
| Shipped (6 files) | **8 problems (0 errors, 8 warnings)** — same six rules, same files, line numbers shifted only |

**Zero new errors, zero new warnings.** In particular `react-hooks/purity` does **not** fire on `WebSocketReconnectProvider.tsx` — the effect-time stamp is correct.

**Probe hygiene:** eight temporary mutations (base restore ×3 sets, four provider mutants, one dead-branch `throw`) were applied and reverted; `git status --porcelain` = **empty** at the end of the review; no leftover vitest workers.

---

## 6. Merge-tree (from the main checkout `/Users/houssamr/Projects/syneriva/apps/erp`)

```
$ git rev-parse dev
e829444d58c9f9c250227c18a10e7703cfef5e06
$ git merge-tree --write-tree dev lane/rh-web-followups-a
c7e7762e334cacb423f44593869707f187904f81
exit=0
```

**Clean — no conflicts.** Single tree oid, no `CONFLICT` section, exit 0. The lane is a fast-forward-clean merge onto `dev`.

---

## 7. Merge posture

**MERGE.** Land NB-1 (the French `régularisation` wording) and NB-2 (the one-word comment nit) as a trailing commit on this lane if convenient — neither warrants a re-gate. Promotion of the batch is still gated on the pre-existing `dev` lint red (NB-5, `ImportWizardPage`, must NOT be absorbed via `--write-baseline`) and on the owed browser legs (NB-6), including the handshake-latency observation that conditions the 5 s ruling.
