# Gate 4 Result + Continuation Brief — design-system unification

> Gate 4 review of aca6288f3 (gate-3 fixes) + 2a5b25ce3 (Wave 5 leg 2), 2026-07-10. Three lanes.
> **Verdict: APPROVE-WITH-FIXES.** Gate-3 fixes: 10/11 verified with live lint probes (template-literal selector errors correctly; colorClasses quarantine stronger than asked — even the namespace-import bypass is caught). Leg-2 sweep: mechanically expansion-diffed across all 77 files — conservation holds everywhere except ONE spot (below). Baseline replay honest for the 5th time (480 +15 −73 = 422, entry-level clean). All evidence claims reproduced exactly; all 7 dirs at zero on every manifest command and ERROR-locked.

## Step 1 — Gate-4 fixes (before leg 3)

**G4-1 (major) — restore the opening-balance wizard step-indicator pixels.** `OpeningBalanceWizardPage.tsx` (~L71, L86): past-step chip `bg-blue-200`→`bg-blue-100` and connector line `bg-blue-300`→`bg-blue-500` — a visible unlisted change caused by `semanticColorTokens` lacking blue-200/300 entries. Fix: add `intent.primary.bgSoftStrong: 'bg-blue-200'` and a `bg-blue-300` connector entry, restore the original classes exactly, and add this incident pattern to your sweep checklist: when the vocabulary lacks a shade, EXTEND the vocabulary — never substitute a neighboring shade silently.

**G4-2 (decision, ratified) — `semanticColorTokens` is RATIFIED as the canonical color vocabulary, conditional on normalization.** Review found it is substantively per-literal aliasing under semantic names (202 leaf keys → 196 distinct values; inconsistent suffix ladder; palette-named "intents" like `slate`/`indigo`/`infoAlt`; single-use micro-variants like `bgSubtleAlphaSoft`). It stays — but normalize BEFORE leg 3 uses it: (a) one meaning per suffix across all intents (e.g. `borderSubtle` = the -200 shade everywhere, `border` = -300); (b) fold `slate`/`indigo`/`infoAlt` into real intents or StatusBadge tones; (c) prune single-use micro-variants (inline the literal's semantic parent or generalize the name); (d) document the ladder in the docblock; (e) log a post-sweep task to fold the older `textColors`/`borderColors` into it so the codebase ends with ONE color vocabulary. Leg 3 must consume the normalized vocabulary.

**Minors (one cleanup commit):**
- **G4-3** Remove the +186 `@typescript-eslint/no-unnecessary-template-expression` warnings the leg-2 sweep introduced in its own dirs (`` className={`${tokens.x}`} `` → `className={tokens.x}`; inv-counting 65, import 42, opening-balances 31, partners 20, loyalty 17, compliance 7, parts-catalog 4).
- **G4-4** Correct the progress doc's +667 warning attribution: the driver was `no-deprecated` from the colorClasses quarantine (+1,923) net of leg-2 removals (−1,677); the TemplateElement selector added only +37.
- **G4-5** Update the inventory doc's C1 manifest command to include `text-\[1\.(5|875)rem\]` (gate-3 brief required it; missed), AND either widen the scanner's C1 arbitrary-value pattern to `text-\[[^\]]+\]` on `<h1>` or add "other arbitrary sizes (text-[2rem], px values) evade — accepted" next to the manifest's known-limits note.
- **G4-6** Promote the option-click-commit probe into a permanent `LineItemEntryBar.test.tsx` test (mouse click on a suggestion → `onAddProduct` called) so the focusout-close can't regress it invisibly.
- **G4-7** Progress-doc/visual-pass additions: (a) 6 inventory-counting `<h1>`s now inherit explicit `text-gray-900` via PageHeaderTitle (near-invisible, list it); (b) FraudSettingsPage save button grew (`px-6 py-2 text-sm` → Button `size="lg"` px-6 py-3 text-base) — either drop to `size="md"` or list it; (c) converted buttons lost `rounded-[var(--radius-button)]` for the atom's `rounded-md` — add "IziPOS 9px radius check" to the owner's on-device eyeball list.
- **G4-8** ImportHistoryPage status maps: restore a `default` fallback (Clock glyph) for out-of-contract runtime statuses, matching FraudAlertsPage's `?? default` robustness.

## Step 2 — Wave 5 leg 3: ALL remaining feature directories

Same per-directory protocol (manifest commands zero → baseline shrink → full-palette ERROR promotion → progress-doc entry). Remaining C7 tail (occurrence counts): auth 204*, services 176, batches 141, parapharmacy 137, pricing 123, vat-reporting 71, vouchers 69, categories, crm, products, dashboard, reports, uom, pos, expenses, coupons, promotions, stock-transfers, settings, catalog, vehicles (regression: 2), customer-history-audit, locations, company, inventory.
*auth/ + pages/legal: colors/atoms in scope; PageHeader exempt (not in app shell).
Rules: use the NORMALIZED semanticColorTokens (extend, never shade-substitute — G4-1's lesson); colorClasses stays closed; modal/drawer forms get RHF but no StickyFormFooter; deferrals logged.

**STOP after the feature-dir tail and report for Gate 5.** Gate 6 (final) = `src/components/` (545 occurrences) + `src/pages/` (78) + `src/lib` residue, Wave 6 dedup/orphans (ProductSelector→ProductPicker; location/locations family; CategorySelect vs CategorySelector; UserPicker vs UserSelector; delete orphans DocumentLineVariantSelector/ProductDetailVariantPicker/TerminalSelector; move ui/ pickers into molecules/pickers; StickyFormFooter index.ts; the dead `default_tax_configuration_id` family from G3-10), plus the deferred full-template PDF render test.

## Standing rules (all held ×4)

Never launder the baseline (entry replay re-runs every gate); every deferral and visual change logged; targeted tests only; pixel-conservative; no push/merge.
