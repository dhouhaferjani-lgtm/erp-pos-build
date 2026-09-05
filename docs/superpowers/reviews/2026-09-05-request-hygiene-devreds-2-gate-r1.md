# Gate r1 — `lane/rh-devreds-2` (dev-reds CI reconciliation)

| | |
|---|---|
| **Branch** | `lane/rh-devreds-2` |
| **HEAD at review** | `e8829bcd0da481c67f11b3d71b153b992344ebed` |
| **Base** | local `dev` = `fa000edc39c5e2c060748db534ff0a22ed1e31a8` |
| **Worktree** | `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-devreds2` |
| **Handback** | `docs/handoff/HANDBACK-request-hygiene-devreds-2-2026-09-05.md` (re-read from disk after the in-place amend) |
| **Reviewer** | independent adversarial gate, r1 |
| **Date** | 2026-09-05 |

Commits reviewed (all 9, incl. the Deptrac bump `3ccf6a26a` and addendum `e8829bcd0` that landed mid-review):

```
e8829bcd0 docs(handoff): append item-4 addendum -- deptrac ceiling bump applied per ruling
3ccf6a26a chore(deptrac): carry the waived CustomerAdvanceClearingInterface->Document edge ... (36->37)
317b12a5b docs(handoff): dev-reds-2 reconciliation handback
b4247f966 docs(i18n): re-pin baseline mirror to the 2026-09-05 seed commit (commit 2 of 2)
89b508e1f chore(i18n): regenerate completeness baseline -- SEED REVISION (commit 1 of 2)
5524b9a69 style(web): fix 51 eslint warnings on files changed since the lint baseline
9ad5d68c3 fix(phpstan): null-guard Batch::expiry_date before toDateString() calls
fa3719e23 fix(pos-test): hoist getSyncMetadataSpy to fix vi.mock hoisting ReferenceError
3801c4f13 style(tests): fix Pint ordered_imports in ProductsImportPipelineTest
```

Working tree clean (`git status --porcelain` empty).

---

# VERDICT: **CHANGES**

The lane's purpose is to make whole-suite CI green on `dev`. **Item 5 (the ESLint autofix commit) introduces two new TypeScript compilation errors and therefore turns the required CI job `frontend-typecheck` RED — the exact opposite of the lane's purpose.** That single defect is a BLOCKER and is a two-line revert.

Everything else verified clean: items 1, 2, 3, 4 (deptrac) and the i18n ritual mechanics all reproduce exactly as claimed. Fix F-1 (and preferably F-2/F-3) and this is a MERGE.

---

## F-1 — BLOCKER — ESLint commit breaks `tsc --noEmit`; required CI job `frontend-typecheck` goes red

**File:** `apps/web/src/features/inventory/ProductForm.test.tsx:772` and `:816`
**Commit:** `5524b9a69`

The commit removed two `as HTMLInputElement` assertions:

```diff
-    expect((screen.getByLabelText('inventory:products.marginPercent') as HTMLInputElement).value).toBe('')
+    expect((screen.getByLabelText('inventory:products.marginPercent')).value).toBe('')
```

`screen.getByLabelText` returns `HTMLElement`, which has no `.value`. Reproduced from the worktree:

```
$ cd .worktrees/rh-devreds2/apps/web && ./node_modules/.bin/tsc --noEmit
src/features/inventory/ProductForm.test.tsx(772,72): error TS2339: Property 'value' does not exist on type 'HTMLElement'.
src/features/inventory/ProductForm.test.tsx(816,72): error TS2339: Property 'value' does not exist on type 'HTMLElement'.
```

Those are the **only** two errors in the whole project, and they sit on exactly the two lines this commit changed — i.e. `dev` typechecks clean and this lane breaks it.

This is a hard CI failure, not a warning. `.github/workflows/ci.yml:2475` defines job `frontend-typecheck`, `:2498` runs `pnpm typecheck` (= `tsc --noEmit`, `apps/web/package.json:21`), and `frontend-typecheck` is listed in both `needs:` (`ci.yml:2744`) and `EXPECTED_JOBS` (`ci.yml:2760`) of `all-checks-pass`.

Vitest did **not** catch this because esbuild strips types without checking them — which is why the commit's "verified by running vitest on every touched test file (131 tests, all green)" was not sufficient evidence for a type-level change.

**Mechanism — could not verify.** The commit claims these came from `eslint --fix` of three rules. They did not: `array-type`, `no-unnecessary-template-expression` and `no-confusing-void-expression` cannot produce this edit. The rule that actually flags this shape is `@typescript-eslint/no-unsafe-type-assertion` (`apps/web/eslint.config.js:166`, `'warn'`), which **has no autofixer** — ten sibling `as HTMLFormElement` assertions in the same file are still flagged and still present:

```
$ ./node_modules/.bin/eslint src/features/inventory/ProductForm.test.tsx
  376:22  warning  Unsafe type assertion: type 'HTMLFormElement' is more narrow than the original type  @typescript-eslint/no-unsafe-type-assertion
  ... (10 occurrences)
✖ 19 problems (0 errors, 19 warnings)
```

So these two hunks look like hand edits presented as autofix output. I could not determine the mechanism with certainty and am not asserting intent.

**Fix:** restore both assertions verbatim:

```ts
expect((screen.getByLabelText('inventory:products.marginPercent') as HTMLInputElement).value).toBe('')
```

Then re-run `cd apps/web && ./node_modules/.bin/tsc --noEmit` (must print nothing) and re-run `node scripts/lint-ratchet.mjs` (restoring 2 warnings costs 2 against a 49-warning improvement — still PASS).

---

## F-2 — MAJOR — i18n item 6: the owner-action list is incomplete; setting the repo variable alone will not green CI in the PR case

**Files:** `docs/handoff/HANDBACK-request-hygiene-devreds-2-2026-09-05.md` item 6; `docs/handoff/progress/enforcement-p2.progress.yaml:83`; `.github/workflows/ci.yml:2427`

The handback states the single owner action owed is setting the GH Actions repository variable `I18N_BASELINE_PROTECTED_BLOB` to `fd6dbe3952cc3daaec15dc432e6b99e007f50dd6`. That is necessary but **not sufficient**, and the gap is invisible from the handback.

`ci.yml:2427` hardcodes the blob-fetch step:

```yaml
- name: Fetch the pinned i18n baseline revision
  run: git fetch origin tag ci-pin/enforcement-p2-r1
```

with the comment "actions/checkout fetches a single commit, so that blob is not in the object store until we fetch the durable pin tag that reaches it." The lane deliberately left `i18n_baseline_pin_tag: ci-pin/enforcement-p2-r1` untouched. Verified that tag does **not** reach the new pin:

```
$ git rev-parse ci-pin/enforcement-p2-r1:apps/web/tools/i18n-completeness-baseline.json
26a9ae1688d80e0f450215326b19ccd1701c9a8f          # the OLD blob

$ git merge-base --is-ancestor 89b508e1f ci-pin/enforcement-p2-r1  →  NO
```

Consequence: on a **push to `dev`** the new blob happens to be in the checked-out tree, so `git cat-file blob fd6dbe…` succeeds and the gate passes. But on **any PR whose branch legitimately shrinks the baseline**, the pinned blob is no longer in the tree and is unreachable from the fetched tag → the checker's "cannot read the protected baseline blob" FAIL-CLOSED path (`audit-i18n-completeness.mjs:741-747`) fires. The gate is then red for a reason nobody will be looking for.

There is also a strict **ordering constraint** the handback does not state: if the owner sets the variable to `fd6dbe…` *before* this branch reaches `origin/dev`, every other branch's CI fails closed on the unfetchable blob.

**Fix (documentation, no code):** amend item 6's owner-action list to the full ritual — (1) merge + push this branch to `origin/dev` first; (2) create a new durable pin tag reaching `89b508e1f` (e.g. `ci-pin/enforcement-p2-r2`; existing pin tags are never deleted per `ci.yml:2419-2426`); (3) update `.github/workflows/ci.yml:2427` and `i18n_baseline_pin_tag` in `enforcement-p2.progress.yaml` to that tag; (4) only then set the repository variable. Steps (3) and (4) are owner/promotion actions; step (3) is a one-line code change this lane could carry if the orchestrator rules so.

---

## F-3 — MINOR — item 6: 4 of the 62 new baseline entries are real translation debt, not granularisation

**File:** `apps/web/tools/i18n-completeness-baseline.json` (commit `89b508e1f`)

The lane's argument is that the 2763 → 2816 growth is granularisation of already-known debt. **Verified true for 58 of 62, false for 4.**

Measured diff of the two baselines (removed vs added, by `locale|ns|kind`):

```
removed (9):   7  ar|sales|missing      1  ar|pos|missing      1  ar|uom|aliased
added  (62):  58  ar|uom|missing        4  ar|import|plural
```

- **The 58 `ar|uom|missing` are genuine granularisation.** `4b5b58789` is confirmed an ancestor of `dev` (`git merge-base --is-ancestor` → yes) and wires `uom: { ...enUom, ...arUom, ... }` at `apps/web/src/lib/i18n.ts:388`. Key counts: `en/uom.json` = 77 authored keys, `ar/uom.json` = 19 → exactly 58 missing. The old baseline covered all 77 with the single `ar|uom|aliased|*` catch-all. Net effect is *less* debt (19 keys genuinely translated), at finer grain. No debt hidden.
- **The 4 `ar|import|plural|unitErrors.line_{zero,two,few,many}` are NOT granularisation.** `ar|import` was never aliased — the old baseline already carried 170 per-key `ar|import|missing|*` entries. These 4 are a distinct finding type (CLDR plural completeness) that was live and *unbaselined*, i.e. a real gap the old baseline exposed as a failure and the new one now covers. Confirmed in source — `apps/web/src/locales/ar/import.json` `unitErrors` authors only `line_one` and `line_other`, while Arabic CLDR requires all six categories.

**Mitigating:** baselining plural-category gaps is the established precedent in this file (it already carries 42 `fr|*|plural|*` and `en|*|plural|*` entries), so this is consistent treatment, not laundering. **Fix:** either author the 4 Arabic plural forms (a ~4-string edit, and then they never enter the baseline), or state plainly in item 6 that 4 of the 62 are real unbaselined debt the owner is being asked to bless — the current "same known gap, finer grain" framing covers only 58.

---

## F-4 — MINOR — commit `5524b9a69` misstates which rules it applied

**Commit:** `5524b9a69` message; **files:** `apps/web/src/features/inventory/ProductForm.test.tsx:770`, `:772`, `:816`

The message names exactly three rules (`array-type`, `no-unnecessary-template-expression`, `no-confusing-void-expression`). The diff contains at least two edits outside that set:

- `-  type MutationOptions = {` → `+  interface MutationOptions {` — `@typescript-eslint/consistent-type-definitions` (behaviour-neutral, fine).
- the two `as HTMLInputElement` removals — see **F-1** (not behaviour-neutral).

For a commit whose entire safety argument rests on "these three rules are type-preserving by construction", an undisclosed fourth and fifth change void that argument. **Fix:** after F-1, restate the rule list accurately in the commit message (or amend the commit).

---

## F-5 — MINOR — item 1 fixture claim is right in substance, imprecise in detail

**File:** handback item 1

- Pint fix verified style-only: the diff is exactly one line, moving `use App\Modules\Import\Application\Jobs\EnrichImportedProductsJob;` from `ProductsImportPipelineTest.php:21` to `:19`. Reproduced: `./vendor/bin/pint --test tests/Feature/Import/ProductsImportPipelineTest.php` → `{"result":"pass"}`.
- The two failing tests do depend on owner-local files: `ProductsImportPipelineTest.php:281` (`realpath(__DIR__.'/../../../../web/e2e-local/real-produits-nobarcode.csv')`) and `:2048` (`…/real-produits.xlsx`), each asserted via `assertIsString($path, …)` at `:282` / `:2049`. Confirmed present in the main checkout, absent from the lane worktree, and untracked (`git ls-files apps/web/e2e-local/` lists only 7 `.ts`/`.md` files).
- CI-gating claim verified: `ci.yml:2244` (`- name: Feature lane Data & Console — tests/Feature/Import` → `./vendor/bin/phpunit tests/Feature/Import/`) sits in job `feature-lane-data-console` (`ci.yml:2132`), gated at `ci.yml:2137` on `vars.SELF_HOSTED_RUNNER_READY == 'true' && (workflow_dispatch || base_ref == 'main' || push to main)`, `runs-on: [self-hosted, …]`. So these two tests genuinely never run on the PR→dev gate. **Leaving them is acceptable.**
- **Imprecision:** the handback says the fixtures have "no `.gitignore` entry". True but misleading — they *are* excluded, by `.git/info/exclude:21` (`apps/web/e2e-local/`), a local untracked-file exclude. That is the more relevant fact (it is why they will never be committed by accident) and it should be what the note says.

**On the `markTestSkipped` question (report only, as instructed):** yes, a `markTestSkipped('…fixture is owner-local, see …')` guarded on `$path === false` **would be the more honest shape**, and I recommend it as a small follow-up lane — not this one. Rationale: `assertIsString` makes an environment precondition indistinguishable from a product regression, so anyone who ever flips `SELF_HOSTED_RUNNER_READY` on a machine without the fixtures gets a red that reads as a data-pipeline bug. A skip states the precondition. Counter-argument that keeps it out of *this* lane: a hard failure is a deliberate tripwire ensuring the owner notices the fixtures went missing from the self-hosted box, and converting it is a behaviour change to a test, outside a CI-reconciliation lane's remit.

---

## F-6 — INFO — item 6's stated CI symptom misattributes the failure

**File:** commit `89b508e1f` message; `apps/web/tools/audit-i18n-completeness.mjs:786-791`

The commit says "CI red: `pnpm audit:i18n:local` exits 1 ('9 baseline entries now translated…')". That message can never cause an exit 1 — the `stale` branch is a bare `console.log` with no `failed = true` (`:786-791`), and the file header states it explicitly: "Stale baseline entries are burn-down (a note, never a failure)". The actual exit-1 came from the **62 fresh findings** (`:781-785`, which does set `failed = true`). The handback body gets this right further down; the commit message does not. Worth correcting so the archaeology stays accurate. Related: the message says "8x ar|sales|missing.partners.b2b.*"; the measured count is 7 (9 removed = 7 sales + 1 pos + 1 uom-aliased).

---

## F-7 — INFO — two treasury-surface files carry cosmetic edits

`apps/web/src/components/organisms/RecordPaymentModal/RecordPaymentModal.tsx` and `apps/web/src/features/treasury/SplitPaymentForm.tsx` are touched by the ESLint commit (template-literal unwrapping and `Array<T>` → `T[]` only — no logic). Neither is a refund file and nothing under `apps/web/e2e/request-hygiene` is touched, so the "other in-flight lane" constraint is met. Flagging only because these are hot treasury surfaces and the edits are pure churn — a rebase conflict here would be annoying for zero functional gain.

---

# Verified-clean items (verbatim outputs)

### Item 1 — Pint ✅

```
$ cd .worktrees/rh-devreds2/apps/api && ./vendor/bin/pint --test tests/Feature/Import/ProductsImportPipelineTest.php
{"result":"pass"}
```

Style-only confirmed (one import line reordered). See F-5 for the fixture-dependent tests.

### Item 2 — POS vitest ✅

Diff inspected line by line: the only changes are wrapping `getSyncMetadataSpy` in `vi.hoisted()` plus a 6-line explanatory comment (`terminalStore.preWarm.test.ts:35-40`). **No assertion, no test case, no mock payload altered** — 1 deletion, 9 insertions, 8 of which are comment.

```
$ cd .worktrees/rh-devreds2/apps/pos && ./node_modules/.bin/vitest run src/stores/__tests__/terminalStore.preWarm.test.ts
 ✓ src/stores/__tests__/terminalStore.preWarm.test.ts (25 tests) 51ms
 Test Files  1 passed (1)
      Tests  25 passed (25)
```

Matches the handback's 25/25. The decision to leave the three sibling spies unhoisted is defensible (minimal fix), though they carry the same latent hazard — a future import-graph change can resurface it. Not a gate item.

### Item 3 — PHPStan / BatchExpiry nullsafe ✅ — semantically correct

```
$ cd .worktrees/rh-devreds2/apps/api && ./vendor/bin/phpstan analyse --level=8 --memory-limit=2G app/Modules/BatchExpiry
 [OK] No errors

$ ./vendor/bin/phpunit tests/Feature/BatchExpiry/BatchExpiryDailyCheckCommandTest.php
OK (3 tests, 19 assertions)
```

I pushed hard on the "does `?->` hide a data bug" question. It does not:

1. **A null `expiry_date` is a deliberate, documented domain state, not a data bug.** `Batch.php:47` casts `expiry_date => 'date'` on a nullable column, and the model handles null explicitly three times with prose that makes the intent unambiguous — `isExpired()` at `:73-79` ("A lot with NO recorded expiry (W4-1) is never expired: nobody claimed it would go off on any particular day, so asserting it has is exactly the fiction this lane removed"), `daysUntilExpiry(): ?int` at `:81-89` ("Null when the lot records no expiry — 'unknown', not 'far away'"), and `expiryStatus()` at `:91-113`. So "should a null be impossible?" — no, by design.
2. **All three call sites are already filtered upstream by SQL, so `?->` is a provable no-op at runtime.** `BatchExpiryDailyCheckCommand.php:142` is fed by `:126-130` (`->where('expiry_date', '<', $today)`); `:203` and `CriticalBatchExpiryNotification.php:44` are both fed by `getBatchesApproachingExpiry()` at `:161-170` (`->whereBetween('expiry_date', [$today, $thresholdDate])`). SQL comparison and `BETWEEN` both exclude NULL. PHPStan simply cannot see through the query builder — which is exactly the situation nullsafe is for.
3. **No empty string is produced anywhere.** `?->toDateString()` yields `null`, not `''`. Both `:142` and `:203` are `Log::warning`/`Log::info` payload arrays. `CriticalBatchExpiryNotification::via()` is `['database']` only (`:26-29`) — there is no Blade/mail template, so the value is a JSON `null` in `notifications.data`, sitting directly beside `days_until_expiry` which is *already* `?int` by design. A consumer that handles one handles the other.
4. **The convention claim checks out** — `BatchResource.php:29` uses `'expiry_date' => $this->expiry_date?->toDateString()` on the same field, one line below the same treatment of `manufacturing_date`.

No `@phpstan-ignore`, no baseline entry, no cast, no suppression. Correct fix.

### Item 4 (deptrac) ✅ — ratchet PASS, waiver claim true, improvement genuine

Reproduced independently, byte-identical to the addendum:

```
$ cd .worktrees/rh-devreds2/apps/api && php tools/deptrac-ratchet.php --config=deptrac.yaml --baseline=deptrac.baseline.json
Deptrac report: 183 violations, 14726 allowed, 13809 uncovered.

Category                                       baseline  current   status
------------------------------------------------------------------------------
ModuleApplication on ModuleInfrastructure            68       68   held
ModuleDomain on ModuleApplication                    53       53   held (domain)
ModuleInfrastructure on ModulePresentation            1        1   held
SharedContracts on ModuleApplication                 18       18   held
SharedContracts on ModuleDomain                      37       37   held
SharedDomain on ModuleDomain                          4        4   held
SharedInfrastructure on ModuleDomain                  2        2   held
------------------------------------------------------------------------------
TOTAL                                               183      183

RESULT: PASS — no boundary regression against baseline.
```

- **The waiver exists and says what the lane says it says.** `apps/api/deptrac.baseline.json` `waivers[]`, 4th entry, verbatim: `"lane": "N-6 / B-20 Phase 1 — advance on an unposted invoice"`, `"date": "2026-08-24"`, `"category": "SharedContracts on ModuleDomain"`, `"delta": 1`, `"line": "app/Shared/Contracts/Accounting/CustomerAdvanceClearingInterface.php — clearCustomerAdvanceForDocument(Document, string, ?string)"`, `"why": "Exactly the shape already waived for DocumentGlCorrectionInterface / DocumentGlPreflightInterface / DocumentGlReversalInterface: a Shared contract that lets the Document module hand a Document to Accounting WITHOUT importing GeneralLedgerService (CLAUDE.md rule 6)… Clearing a customer advance at posting has to be atomic with the seal, so the call site is inside DocumentPostingService::post()'s transaction and cannot be relocated."` The bump commit only appended a `ceiling_note`; the four waiver entries are otherwise byte-identical to `dev`.
- **The 54 → 53 lock-in is a genuine improvement, not a masked count.** The ratchet reports `current` = 53 measured live from deptrac, matching the new baseline exactly. `ModuleDomain on ModuleApplication` is a domain-leak category (`held (domain)`), so locking 53 *tightens* the gate: re-introducing that violation is now a BLOCKER rather than free headroom. Correct ratchet behaviour.
- **`--update-baseline` would indeed drop `waivers[]`.** Confirmed mechanically: `grep -n waiver tools/deptrac-ratchet.php` returns **no hits** (exit 1) — the script never reads the array, which is why the numeric ceiling had to carry the edge. And the write payload at `tools/deptrac-ratchet.php:134-142` is exactly `['generated_at' => …, 'note' => …, 'total' => $currentTotal, 'categories' => $current]` — no `waivers` key, and it is written with `file_put_contents` of a fresh `json_encode`, so a run would silently delete all four waiver entries. **Hand-editing was the right call.**
- Internal consistency: `68+53+1+18+37+4+2 = 183` = the `total` field. The addendum's reading of the script's two independent failure conditions (`:178-191` per-category, `:217-220` total, with `$baselineTotal` read as the literal field at `:162`) is accurate.

### Item 5 — ESLint ratchet ✅ PASS (but see F-1)

Swap checked before the heavy run (`free = 1226.50M`, well above the 300 MB floor). Run once:

```
$ cd .worktrees/rh-devreds2 && node scripts/lint-ratchet.mjs
=== Lint-warning ratchet ===

  @autoerp/web     baseline=  6448  current=  6399  improved — warnings fell 6448 → 6399 (-49)
  @autoerp/pos     baseline=    84  current=    84  held — 84 warnings

Improvements detected. Lock them in:
  node scripts/lint-ratchet.mjs --update-baseline

RESULT: PASS — no lint-warning regression against baseline.
```

web 6399 ≤ 6448 ✅. `--update-baseline` correctly **not** run.

**Hunk review — all 20 hunks across all 12 files inspected, not a sample.** Behaviour-preserving except F-1:

- `Array<T>` → `T[]` (6 hunks: `RecordPaymentModal.tsx:73,87`, `ProductForm.tsx:93`, `SplitPaymentForm.tsx:96,101`, `useDraftAutoSave.ts:91,103`, plus 2 in test files) — type-identical by definition.
- `` `${colorTokens.x}` `` → `colorTokens.x` (11 hunks across `ConfirmDialog.tsx:21,25,29`, `Dashboard.tsx:189,230,263`, `RecordPaymentModal.tsx:534,583,611-612,803,819,833,847,861`, `UnitsSettingsPage.tsx:81,127,152`) — verified these are plain string literals, not functions or objects (`designTokens.ts:305-309` etc., e.g. `bg: 'bg-red-500'`), so the coercion is a no-op. No dead-token-interpolation risk introduced.
- `no-confusing-void-expression` brace-wrapping (`ProductForm.tsx:882` `onChange`, `TransferSourceSuggestion.tsx:30,31,33` `onClick`/`onClose`, plus ~14 `waitFor(() => expect(...))` in tests) — React event handlers and `waitFor` callbacks are typed `void`; changing an implicit `void` return to `undefined` cannot alter behaviour. `smartPrompts.tenantScope.test.tsx:157` drops a `return` from `updateContactProfile`, whose callee is cast `(input) => void` — return value was already unusable.

Companion gates were re-run by the lane, not by me (`test:eslint-rules`, `test:tools`, `campaign:fiscal-test`, `audit-tanstack-keys`, `audit-design-system`, `audit-quantity-display` — all EXIT=0 per the handback). **Could not verify** — deliberately not re-run to respect the one-heavy-process constraint; none are affected by the diff's content.

### Item 6 — i18n ritual ✅ mechanically correct (see F-2, F-3, F-6 for the caveats)

**(b) Seed/mirror ritual — correct.** All four values agree:

```
seed blob (git rev-parse 89b508e1f:apps/web/tools/i18n-completeness-baseline.json):  fd6dbe3952cc3daaec15dc432e6b99e007f50dd6
HEAD blob:                                                                           fd6dbe3952cc3daaec15dc432e6b99e007f50dd6
working file (git hash-object):                                                      fd6dbe3952cc3daaec15dc432e6b99e007f50dd6
mirror i18n_baseline_protected_blob:                                                 fd6dbe3952cc3daaec15dc432e6b99e007f50dd6
mirror i18n_baseline_seed_commit:                                                    89b508e1f18a8f8e276c9403f8324082fec0ca62
```

The two-commit split is right: `89b508e1f` touches **only** the baseline JSON (a valid dedicated seed commit), `b4247f966` touches only the progress YAML, and no later commit re-touches the baseline file — so the seed blob still equals the working file at HEAD.

```
$ cd .worktrees/rh-devreds2/apps/web && pnpm -s audit:i18n:local
i18n completeness OK — 55 namespaces, authored keys: en=9497, fr=9514, ar=5142 authored (1921 behind aliases); 2816 known gap(s) held at the baseline.
  English-aliased namespaces — ar: 21 ns / 1921 keys served in English
EXIT=0
```

**(a) Does the new baseline hide debt the old one exposed?** Yes, but almost entirely as re-classification — 58 of 62 are the same debt at finer grain; 4 are real. Full analysis in **F-3**. Checker semantics confirmed by reading the source: `aliased` is one entry per `(locale, namespace)` (`audit-i18n-completeness.mjs:21-28`, emitted at `:526-534`), `missing`/`plural` are per key (`:29-40`), `fresh` = live finding not in the working baseline → `failed = true` (`:565`, `:781-785`), `stale` = baseline entry no longer live → note only (`:786-791`). CI's anti-growth check `addedKeysAgainstProtected` (`:768-778`) compares the **working file** against the **protected blob**, failing on any added key — so de-aliasing a namespace mechanically trips it and a re-pin is the only route, exactly as the lane argues.

**(c) What the owner must set, and is CI red until then?** The owner must set repository variable `I18N_BASELINE_PROTECTED_BLOB` = `fd6dbe3952cc3daaec15dc432e6b99e007f50dd6` (`ci.yml:2444` maps `${{ vars.I18N_BASELINE_PROTECTED_BLOB }}` into the `pnpm audit:i18n` step at `:2445`). **Yes, CI is red until then, and doubly so** — with the variable still on the old blob the checker fails first at MIRROR DRIFT (`:723-731`, variable `26a9ae16…` ≠ mirror `fd6dbe39…`), and would otherwise fail at RATCHET GROWTH (`:769-778`, 62 added entries). The local green above is not evidence about CI: `scripts/i18n-baseline-authority.sh` *derives* the value from the very mirror pin this lane wrote, so it matches by construction — the script says so itself ("THIS IS NOT A BYPASS… it cannot make CI pass"). **But the handback's owner-action list is incomplete — see F-2.**

### Item 7 — hygiene ✅ (with F-4, F-7)

- All 9 commits are conventional (`style(...)`, `fix(...)`, `chore(...)`, `docs(...)`) with scopes matching content.
- Path-scoped: each commit's file set is confined to its own item. 20 files total, listed and reviewed.
- No product-behaviour change outside items 3 and 5 — and item 3's is provably a runtime no-op (see above).
- No collision with in-flight lanes: nothing under `apps/web/e2e/request-hygiene`, no treasury refund files. See F-7 for two cosmetic treasury-surface touches.
- Working tree clean; nothing untracked left behind.
- Handback claims match reality on every item I could re-run. Deviations are F-4, F-5 (`.gitignore` vs `.git/info/exclude`), F-6 (symptom misattribution, 8 vs 7), and F-2/F-3 (incompleteness rather than inaccuracy).

---

## Could not verify

1. **Mechanism of the F-1 edit** — whether `eslint --fix` produced the `as HTMLInputElement` removals or they were hand edits. `no-unsafe-type-assertion` (the rule flagging that shape) has no fixer and left 10 sibling assertions in place, so the three named rules cannot account for it. The *effect* is fully verified; only the cause is open.
2. **That the 3 PHPStan errors and the Pint failure existed on `dev` before the lane** — not re-run against the base tree (would need a second checkout). Both are self-evident from the diffs: a nullable Carbon cast without `?->` at level 8, and a mis-ordered import under `ordered_imports`. Accepted.
3. **Companion light gates** (`test:eslint-rules`, `test:tools`, `campaign:fiscal-test`, the three audit scripts) — taken from the handback, not re-run, per the one-heavy-process constraint. None are touched by the diff.
4. **Real CI behaviour after the owner sets the variable** — reasoned from `ci.yml` and the checker source, not observed. F-2's PR-case failure is a code-reading conclusion.
5. **Whether the 4 `ar|import|plural` findings were live on `dev` before `4b5b58789`** — not bisected. They are live now and were unbaselined, which is what F-3 rests on.

---

## Required before merge

| # | Severity | Action |
|---|---|---|
| F-1 | **BLOCKER** | Restore both `as HTMLInputElement` assertions (`ProductForm.test.tsx:772,816`); re-run `tsc --noEmit` (clean) and `lint-ratchet.mjs` (PASS). |
| F-2 | MAJOR | Amend item 6's owner-action list with the pin-tag + `ci.yml:2427` + ordering steps. |
| F-3 | MINOR | Either translate the 4 Arabic plural forms, or state in item 6 that 4 of the 62 are real debt being blessed. |
| F-4 | MINOR | Correct `5524b9a69`'s rule list. |
| F-5 | MINOR | Correct the `.gitignore` → `.git/info/exclude:21` detail. Recommend a follow-up lane for the `markTestSkipped` guard (not this one). |
| F-6 | INFO | Correct item 6's CI-symptom attribution (fresh, not stale) and the 8→7 count. |
| F-7 | INFO | No action; awareness only. |

**Not merged.** Left on `lane/rh-devreds-2` for the orchestrator.
