# UI Wave 0 — M6 bridge review, round 1

**Lenses:** frontend-conventions + general
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/ui-wave0` (read-only for source; this register is the only write)
**Branch:** `codex/ui-wave0-2026-08-11`, tip `5caeb61a28b6e96ad358a4af5a17cbf993f469b1` — matches the expected tip
**Range reviewed:** `9b46e8d52..5caeb61a2`
**Contracts:** `docs/handoff/CODEX-DISPATCH-ui-wave0-2026-08-11.md` §T5:308-337, §T7:350-381, §T8:383-414, §T14:566-604, M6 row `:20`; `docs/handoff/progress/ui-wave0.progress.yaml:100-115, 209-222`
**Reviewer:** adversarial bridge reviewer (frontend-conventions lens), 2026-08-19

Commits in range:

| SHA | Task |
|---|---|
| `d52d84688` | Phase 0.6.0 — close M5 / open M6 in the ledger |
| `83b46e752` | Phase 0.5.1 — T5 (UI-12) voucher filter chip selected state |
| `4fcf447bf` | Phase 0.7.1 — T7 (UI-40) C6 detector + `colorClasses` plan |
| `ee87f153e` | Phase 0.8.1 — T8-c (UI-43 partial) orphaned coming-soon key prune |
| `4275794ee` | Phase 0.14.1 — T14 (OQ-1) app name as a config value |
| `5caeb61a2` | Phase 0.6.1 — record M6 in the ledger |

Full changed-file set (24 files) contains **no** `apps/web/src/routes/**` and **no** `apps/web/src/features/products/**` entry, so the T8-a re-touch prohibition and the T8-b BLOCKED disposition both hold by construction.

---

## 1. Verification actually executed (nothing accepted on report)

| Check | Command | Result |
|---|---|---|
| Typecheck | `pnpm --filter @autoerp/web typecheck` | exit 0 |
| Lint (chains `audit:keys`, `audit:design-system`, `audit:quantity`, RuleTester) | `pnpm --filter @autoerp/web lint` | **0 errors**, 6520 warnings (matches the ledger's 6520); Gate C 0/0/0; design-system **818 acknowledged / 0 new / 0 stale**; audit-quantity 0/0/0; all three RuleTester suites pass |
| Manifest drift | `bash scripts/factory/check-manifest-drift.sh` | **exit 0** |
| Vouchers (T5) | `pnpm vitest run src/features/vouchers --maxWorkers=1` | **12 files / 78 tests passed** — the claimed 78/78 reproduces exactly |
| Detector fixtures (T7) | `pnpm vitest run tools/__tests__/audit-design-system.test.mjs --maxWorkers=1` | **19/19** |
| Key pruning (T8-c) | `pnpm vitest run src/__tests__/i18n/comingSoonKeyPruning.test.ts` | **26/26** (13 orphan + 1 residual sweep + 10 live + 2 EN/FR alignment = 26; the claimed red 14 = 13 + 1, arithmetically consistent) |
| Brand placeholder (T14) | `pnpm vitest run src/__tests__/i18n/appNamePlaceholder.test.ts src/pages/legal/__tests__/PrivacyPolicyPage.test.tsx src/features/support-access` | **14/14**, **4/4**, support-access suites green |
| Combined focused run | vouchers + i18n + legal + support-access, `--maxWorkers=1` | **21 files / 140 tests passed, 0 failed** |
| Raw-key coverage guard | `pnpm vitest run src/lib/i18nRawKeyCoverage.test.tsx` | 5/5 passed (the suite the brief warns pins raw keys) |
| Inherited exception | `pnpm vitest run src/__tests__/i18n/arLocaleCoverage.test.ts` | 3 failed / 7 passed; missing counts **122 / 1 / 19** on `common` / `workshop-technicians` / `vehicles` — unchanged, matches the M0b exception |

Full `pnpm test` was **not** run (M8 owns the whole-branch gate; the brief's iterate-by-path rule applies here). UI E2E not run (M8 item). Nothing was pushed; no source file was modified by this review.

---

## 2. Per-task verdicts

### T5 — UI-12 voucher filter chips — **CONFIRMED CORRECT**

- The red is real and now closed: `VoucherListPage.tsx` previously carried `source === filter.value ? `` : ``` (both branches empty). At tip, `VoucherListPage.tsx:104` computes `isSelected` and `:111` renders `variant={isSelected ? 'primary' : 'secondary'}`.
- **Both states render visibly.** `Button.tsx:15` composes `intent.primary.bgStrong` + `text.inverse`; `Button.tsx:17` composes `surface.base` + `text.secondary` + `border`. The retained `className` at `VoucherListPage.tsx:116` carries **no** background/text-colour utility, so there is no colour conflict and no dead-class risk — the primary background genuinely reaches the DOM. No composed/interpolated Tailwind class was introduced (rule-18 clean; `no-dead-tailwind-token-interpolation` RuleTester passes and the design-system audit reports 0 new).
- **House pattern claim verified**: `features/import/pages/ImportHistoryPage.tsx:98` is exactly `${colorTokens.intent.primary.bgStrong} ${colorTokens.text.inverse}` for the selected chip. The Button-variant route reaches the same two tokens through the canonical atom; the brief's "unless matching the house pattern requires otherwise, and say why" escape is invoked and documented (`ui-wave0.progress.yaml:209`, commit body). This satisfies the task's intent better than a className override would, because it keeps the tokens in one place.
- **`aria-pressed` correct**: `:114` `aria-pressed={isSelected}`; the test asserts `true` on the selected chip, `false` on two others, and `true` on "All" when no filter is applied (`VoucherListPage.filterChips.test.tsx:95-97, 107-109`). State is not colour-only.
- `data-testid` contract and geometry classes unchanged; the 12 pre-existing voucher suites stay green.

### T7 — UI-40 C6 detector + plan — **CONFIRMED CORRECT, independently re-derived**

I re-derived the C6 population myself rather than trusting the report. I reconstructed the **pre-T7 detector** (`git show 9b46e8d52:apps/web/tools/audit-design-system.mjs`) into a scratch harness pointed at the **tip** `src` tree and ran both:

| Category | pre-T7 detector / tip source | tip detector / tip source |
|---|---|---|
| C1 | 12 | 12 |
| C2 | 250 | 250 |
| C3 | 458 | 458 |
| C4 | 14 | 14 |
| C5 | 2 | 2 |
| C6 | **0** | **82** |
| total | **736** | **818** |

C1–C5 are **byte-stable at the exact claimed values**; C6 moves 0 → 82; the baseline moves 736 → 818. The commit-local "T7 must not move C1–C5" criterion holds.

- **Baseline replayed entry-level** (`--write-baseline` is the acceptance risk the brief names): old 736 entries vs new 818 → **82 added, 0 removed**, and every one of the 82 added entries has the `C6|` category prefix. Nothing was swallowed; nothing that earlier milestones hand-deleted was silently resurrected. The only non-C6 line in the text diff is a trailing-comma reflow on the last pre-existing C5 entry (`C5|src/features/treasury/components/RepositoryMovementsTab.tsx|<table …>|#1`) — exactly as claimed, and it is the same entry before and after.
- **Arithmetic confirmed from the JSON output**: 82 entries / **46 distinct `file:line` sites** / **41 files**. The honest-number disclosure at `ui-wave0.progress.yaml:212` is accurate.
- **Cannot fail CI on existing code**: the audit reports 818 acknowledged / **0 new / 0 stale** inside `pnpm lint`, which exits 0. The widening is detector-only.
- **Scope judgement (fourth alternation) — accepted.** The brief's acceptance wording says "the regex family matches `Tone`/`Tones` suffixes". The executor added `Tones?` to alternations 1 and 3 **and** a value-type alternation `\bRecord\s*<[^<>]*,\s*StatusTone\s*>` (`audit-design-system.mjs:80`). This is a defensible expansion, not scope creep: a suffix-only rule cannot see `directionTone` / `matchTone` / `invoiceTone`, and the finding it implements (`02:22` F-8) counts by value type. It is disclosed in three places (source docblock `:59-74`, commit body, `ui-wave0.progress.yaml:211`) rather than absorbed silently, and it is additive-only against a regenerated baseline, so it changes no CI outcome on existing code.
- **P2-lane boundary recorded as claimed**: `18-colorclasses-migration-plan.md:296-306` (§6), the commit body, and `ui-wave0.progress.yaml:214` all state the same boundary (T7 owns only STATUS_RE + baseline + C6 fixtures + the plan doc; no tamper test, no enforcement-worktree file, no `eslint.config.js`).
- **`eslint.config.js` byte-identical**: `git diff 9b46e8d52..5caeb61a2 -- apps/web/eslint.config.js` is empty. The carve-outs are untouched and no importer was migrated.
- **Plan doc numbers re-derived independently**: `grep -rlE "^\s*import\s.*\bcolorClasses\b…" src` → **52** importers; a bare `grep -rl colorClasses src` → **54**, the two extras being `src/lib/designTokens.ts` (the definition) and `features/stock-adjustments/api/queries.ts` (a comment); distribution **40 documents / 12 admin / 0 outside the carve-out**. Every headline number in the plan reproduces. The doc contains the required importer inventory, reproduction command, sequencing, mechanical/manual split, both carve-out options, and the verbatim "the migration remains unscheduled and must be assigned to a later wave and budgeted" statement (`:4`, `:313`). It is force-added and tracked despite `docs/sessions/` being gitignored — same precedent as M1.

### T8-c — UI-43 partial — **CONFIRMED CORRECT**

- **Exactly the prescribed prune, no more.** 13 key instances across 5 bundles, counted from the diff: `en/pos` 1, `fr/pos` 1, `en/inventory` 4, `fr/inventory` 4, `ar/inventory` 3 = 13. The four brief-listed groups and nothing else.
- **No live consumers**: `grep -rn "discountComingSoon|stockComingSoon|detailsComingSoon|categorySelectionComingSoon" apps/web/src` returns zero non-test hits.
- **The three live keys survive** and are pinned two-sidedly (`comingSoonKeyPruning.test.ts:65-78`), and `i18nRawKeyCoverage.test.tsx` (the suite the brief warns about) is green.
- **`ar/inventory.json` is genuinely valid JSON with key alignment.** I re-parsed all five touched bundles plus the six T14-touched bundles with `JSON.parse` — all OK. The inline four-key line 74 was edited by verbatim string match; the resulting line is well-formed with no double comma. Deletions are symmetric across en/fr/ar for `detailsComingSoon` and both `categorySelection*` keys, so EN↔AR alignment for that namespace is preserved by construction (see P2-1 below for the *evidence* defect on this exact point).
- **Red 14/26 → 26/26 reproduces** as a count model (13 presence assertions + 1 residual sweep = 14 red) and 26/26 passes on re-run.
- **UI-43 reported PARTIAL, not closed.** `ui-wave0.progress.yaml:215` labels the commit "(UI-43 partial)"; F-2b remains an open question at `:140-141` with the four-claim itemisation and the standing prohibition; nowhere in the range does the text "Wave 0 complete" or "UI-43 closed" appear. Contract satisfied.
- **Stray-tree handling is exactly right.** `git diff --name-only 9b46e8d52..5caeb61a2 -- apps/web/apps/` is **empty** — the executor removed only the key it was told to remove and did **not** delete `apps/web/apps/web/src` (correctly left as the parent's ticket). I re-verified the unreachability claim independently: `apps/web/tsconfig.json:54` is `"include": ["src"]`; `tsconfig.json:40` maps `@/*` → `src/*` and `vite.config.ts:13` maps `@` → `./src`, so no alias resolves into the stray tree; and a grep for any relative import climbing out of `src` into `apps/` returns nothing. The one reference (`apps/web/apps/web/src/features/inventory-counting/pages/CountingDashboardPage.tsx:83`) is dead code in a tree neither the compiler, the bundler, nor the design-system scanner (`SRC_ROOT` = `apps/web/src`) ever enters.

### T14 — OQ-1 app name from config — **CONFIRMED CORRECT**

- `grep -rn "AutoERP" apps/web/src/locales/` → **0 hits**. Verified directly.
- The dead `appName` key is gone from `en`, `fr` and `ar` `common.json`, and **no consumer survives**: every remaining `appName` occurrence in `apps/web/src` is either the interpolation argument at `PrivacyPolicyPage.tsx:40,78` / `TenantSupportAccessPage.tsx:33`, or a test/comment. No `"appName"` key remains in any locale bundle.
- **Harness choice is sound.** The brief's warning is real (`ProductConfigContext.tsx:132` throws without a provider). The executor wrapped explicitly in `<ProductConfigProvider initialProduct={product}>` with `renderPage(product: Product = 'izipos')`, which adds precisely the missing provider and preserves the two pre-existing cases' behaviour. That is a defensible pick over `renderWithProviders` (which would additionally mount `CompanyConfigProvider`). The privacy test uses `renderWithProviders` with `productConfig: { product: 'otospex' }` — both proofs seed the **non-default** product, so neither can pass by coincidence, and each also pins the `izipos` branch.
- **Runtime safety re-verified independently**: `App.tsx:27` mounts `ProductConfigProvider` above `AppRoutes` (`:33`), so both the public `/privacy` route (`routes/index.tsx:383-386`) and the in-app support-access page are inside the provider. No blank-screen risk from the new hook call.
- **Arabic**: `ar/common.json` has `legal.{privacyPolicy,termsOfService,cookieConsent}` but no `legal.privacy` subtree, so Arabic privacy falls back to EN (`i18n.ts:435` `fallbackLng: 'en'`) — and the EN fallback string is now the interpolated one, so OQ-1 holds in `ar` too. The Arabic support-access subtitle **is** updated (`ar/support-access.json:4`).
- The brief's two arithmetic errors (eight vs ten locale hits; "six times" vs ten `TermsOfServicePage` occurrences) were disclosed rather than absorbed; I counted the `TermsOfServicePage.tsx` occurrences at `:37,38,49,50,107,108,131,132` and the executor's ten is correct. The Terms leak remains unfixed and reported — correct per rule 4.

---

## 3. Findings

### P2-1 — CONFIRMED — the ledger attributes M6's Arabic-alignment proof to a test that does not cover the `inventory` namespace

`docs/handoff/progress/ui-wave0.progress.yaml:221` states: *"…while ar/inventory PASSES, which is the positive evidence that the ar deletions kept that bundle aligned."*

`apps/web/src/__tests__/i18n/arLocaleCoverage.test.ts:67-75` enumerates exactly nine namespaces — `common`, `validation`, `workshop-bundles`, `workshop-technicians`, `workshop-work-orders`, `vehicles`, `vehicle-ownership`, `scheduling`, `pickers`. **`inventory` is not one of them.** I ran the suite: it executes 10 tests total (9 namespaces + 1 helper self-test), 3 failed / 7 passed, and never evaluates `ar/inventory.json`. A namespace the suite does not import cannot "pass" it, so the sentence claims positive evidence that does not exist. I also confirmed that `comingSoonKeyPruning.test.ts` is the **only** test file in `apps/web/src` that imports `ar/inventory.json`, and its alignment assertion (`:113-131`) is **EN↔FR only** — there is no EN↔AR alignment check for `inventory` anywhere.

The underlying work is fine (the deletions are symmetric across en/fr/ar, verified from the diff), so this is an evidence-integrity defect, not a code defect — but it is recorded in the permanent wave ledger and would be inherited as fact by M8's whole-branch gate.

**Fix directive:** rewrite `ui-wave0.progress.yaml:221`'s final clause to state that `arLocaleCoverage.test.ts` does **not** cover the `inventory` namespace (`:67-75`), and cite the real proof instead — the symmetric en/fr/ar deletions plus `comingSoonKeyPruning.test.ts:54,58,62` (absence in `ar/inventory`), noting that no EN↔AR alignment test covers that namespace.

### P3-1 — CONFIRMED — the T5 deviation rationale rests on a wrong Tailwind mechanism, and the classes it keeps are subject to the same objection

The disclosed rationale (`ui-wave0.progress.yaml:209`, commit body of `83b46e752`, and the in-file comment at `VoucherListPage.tsx:106-110`) says a `className` override *"would have to win a Tailwind class-order fight against them to render at all."* Class order in the `class` attribute has no effect in CSS; precedence is decided by rule order in the generated stylesheet for equal-specificity utilities. The **conclusion** (an override is unreliable) is right; the **stated mechanism** is not. The same rationale also undercuts the retained line: `VoucherListPage.tsx:116` still passes `rounded-full px-3 py-1`, which competes with `Button.tsx:53` `rounded-[var(--radius-button)]` and `Button.tsx:32` `px-4 py-2` — i.e. the chips' pill geometry relies on exactly the override behaviour the comment declares unreliable. This is **pre-existing** (the geometry className is byte-identical to the pre-fix code) and is not a regression.

**Fix directive:** reword the comment at `VoucherListPage.tsx:106-110` to say "duplicate colour utilities across `variantStyles` and `className` resolve by stylesheet order, not attribute order, so the override is not deterministic"; leave the geometry alone and let the parent ticket the `Button` geometry-conflict class separately.

### P3-2 — PLAUSIBLE — the default-selected chip puts a second solid-primary element on the screen (owner "one main element per screen")

With no `?source=` param, "All" is selected, so `VoucherListPage.tsx:111` renders a solid `intent.primary.bgStrong` chip on first paint, immediately below the page's primary CTA at `:94` (`Button` default variant is `primary`, `Button.tsx:39`). That is two competing primary accents on load, which is the signal-overload pattern `OWNER-DECISIONS-ui-audit-2026-08-10.md` targets. **Not a brief violation** — T5 explicitly instructed the executor to match the existing house treatment, and the house precedent itself pairs a primary selected chip with a primary CTA (`ImportHistoryPage.tsx:98` and `:233`). Report-only.

**Fix directive:** do not change it in this wave; route "selected filter chip uses the primary accent, competing with the page CTA" to the UI audit as a design-system-level question about the canonical chip treatment.

### P3-3 — CONFIRMED — the regenerated C6 baseline is a merge-ordering hazard for the parent

The 818-entry baseline was regenerated against this branch's tree. The branch contains `origin/dev` (0 behind), but local `dev` is **317 commits ahead** of the merge base. Any `Record<…, StatusTone>` map that exists on local `dev` in a feature/page file absent from this branch will be reported as a **new** C6 violation the moment the branch merges, and `pnpm lint` will fail. I checked today: no file changed on local `dev` since the merge base contains `StatusTone`, so the current risk is nil — but that is a point-in-time fact, and local `dev` keeps moving.

**Fix directive:** at merge time, re-run `node apps/web/tools/audit-design-system.mjs` on the merged tree and require `0 new / 0 stale`; if new C6 entries appear, fix or ticket them — **do not** re-run `--write-baseline` to absorb them.

### P3-4 — CONFIRMED (informational) — the stray nested tree remains a live parent ticket and now holds a reference to a deleted key

`apps/web/apps/web/src/features/inventory-counting/pages/CountingDashboardPage.tsx:83` calls `t('inventory:counting.detailsComingSoon')`, a key this range deleted. The tree is provably unreachable (see §2 T8-c) and the executor correctly reported it instead of deleting it, but the reference is now a dangling key in tracked source.

**Fix directive:** parent ticket only — delete the five tracked files under `apps/web/apps/web/src/` in a dedicated commit; nothing for M6 to do.

**No P1 findings.**

---

## 4. Bypasses attempted (all failed to find evasion)

1. **Baseline absorption.** Replayed `audit-design-system-baseline.json` entry-level, old vs new: 82 added / **0 removed**, every addition `C6|`-prefixed. `--write-baseline` did not swallow unrelated drift, and it did not resurrect the C2/C3 entries earlier milestones hand-deleted (a removal-count of zero forecloses both directions).
2. **Detector evasion / fake improvement.** Rather than trust "C6 0 → 82", I ran the **pre-T7 detector against the tip source tree** in an isolated harness: 736 total, C6 absent, C1–C5 identical to the post-T7 run. The C6 growth is a genuine detection widening, not a re-labelling or an alias trick.
3. **Detector weakening disguised as widening.** Diffed `audit-design-system.mjs` in full: the only functional change is inside `STATUS_RE` (`:76-81`); no carve-out was added, no category was scoped down, `isFeatureOrPageFile` is untouched, and the new negative fixture proves the atom itself is still excluded by the pre-existing scope rule rather than by a new exemption.
4. **Suppression / indirection.** No new eslint-disable, no token alias table, no re-export of `tokens.*`, no `eslint.config.js` change (byte-identical), no `colorClasses` importer moved.
5. **Dead composed Tailwind classes.** T5 introduces no template-literal class composition; the `no-dead-tailwind-token-interpolation` RuleTester passes and I checked by hand that the selected/unselected backgrounds come from complete static strings in `Button.tsx:15,17`.
6. **Locale over-deletion / under-deletion.** Independently grepped for all four key groups (zero non-test hits), parsed all eleven touched bundles, and confirmed the three live keys still resolve.
7. **Reported test counts.** Re-ran every cited number: 78/78, 19/19, 26/26, 14/14, 4/4, 5/5, and the 3-failed arLocaleCoverage exception at unchanged 122/1/19. Only one reported claim did not survive — P2-1.
8. **Silent scope creep.** Full changed-file list contains no `routes/`, no `features/products/`, no `scripts/factory/`; `check-manifest-drift.sh` exits 0; `BarcodeHero.tsx` and its three baseline entries (`:223`, `:224`, `:664`) are all present.

## 5. Failure scenarios considered

- **FS-1 — post-merge C6 breakage.** Covered by P3-3. Verified nil today; re-check at merge.
- **FS-2 — provider-less render of a page that now calls `useProductConfig`.** `ProductConfigContext.tsx:132` throws. Verified `App.tsx:27` wraps `AppRoutes`, so `/privacy` (a public route) and the tenant support page are both covered. Standing constraint: any future legal/error shell that renders these pages outside `App` must mount the provider.
- **FS-3 — Arabic privacy copy.** `ar/common.json` has no `legal.privacy`; Arabic users see the EN fallback. Pre-existing; OQ-1 still holds because the fallback string is the interpolated one. Not introduced by M6.
- **FS-4 — a future `t('subtitle')` consumer of the `support-access` namespace without the interpolation argument** would render a literal `{{appName}}`. Checked: `TenantSupportAccessPage.tsx:33` is the only consumer; the other `t('subtitle')` hits are in different namespaces (`stock-adjustments`, `stock-transfers`, `compliance`).
- **FS-5 — chip selection state lost to screen readers.** Foreclosed by `aria-pressed` plus its three test assertions.

## 6. Disposition

M6 is honest work. The four tasks land exactly what their brief rows prescribe and nothing more: T5 closes a genuinely dead ternary with a token-only, house-matching treatment plus a non-colour-only selection signal; T7's C6 growth is a real detection widening that I re-derived from scratch with the pre-fix detector, its baseline replays entry-level with 82 C6 additions and zero removals, and its C1–C5 freeze is exact; T8-c prunes precisely 13 key instances across 5 bundles with valid JSON, symmetric locale deletions and a two-sided guard against over-deletion; T14 removes every brand literal from the locale bundles with two real component-level proofs seeded to the non-default product and a correctly-minimal harness repair. The disclosures the executor volunteered — the Button-variant deviation, the fourth regex alternation, the 82-vs-46 entry/site distinction, the stray nested tree, and both brief arithmetic errors — all check out against the code, and the stray tree was reported rather than deleted, which is the right call. Every gate reproduces: typecheck 0, lint 0 errors, manifest drift exit 0, design-system 818/0/0, and 140/140 on the focused suites. One claim does **not** survive verification: the ledger cites `arLocaleCoverage` as positive evidence that the Arabic inventory deletions kept the bundle aligned, and that suite does not cover the `inventory` namespace at all — the work is right, the stated proof is not, and it is written into the permanent wave ledger where M8 would inherit it. That is a one-line documentation correction, not a code change, and it does not warrant blocking the milestone; the three P3s are report-only or merge-time hygiene. Accepting with P2-1 to be corrected in the ledger before M8 runs the whole-branch gate.

VERDICT: ACCEPT
