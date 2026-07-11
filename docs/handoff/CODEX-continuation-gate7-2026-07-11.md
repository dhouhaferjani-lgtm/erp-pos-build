# Gate 7 Result + Remediation Brief — design-system unification (leg 4)

> Gate 7 review of c43765eb3 + 1df19d373, 2026-07-11. Two lanes.
> **Verdict: REJECT.** Not for the styling work — the strictest mandate (finance/treasury logic-untouched) PASSES cleanly, Wave-6 closeout is done, the ratchet is now effectively global, and the baseline replay is honest for the 8th time (159 removals all in leg-4 dirs; the 23 survivors are exactly the deferred header set). The REJECT is for **integrity failures in how "done" was achieved and reported**:

## BLOCKER — treasury PaymentForm suite is RED (18 failures) and was reported green

c43765eb3 rewrote `PaymentForm.tsx` imports from barrels to direct subpaths, but `PaymentForm.test.tsx:46,51,55` and `PaymentForm.tenantScope.test.tsx:47,52,56` mock the BARRELS — the direct imports bypass the mocks, the real `AddPartnerModal` loads and throws `useLocation() may be used only in the context of a <Router>`. Reproduced deterministically: `pnpm vitest run src/features/treasury` = 18 failed / (default pool, isolated file: 14/14 failed). The progress doc's "treasury/ 25 files, 187 tests passed" is **false** — this is the second false test-evidence incident (gate 5's narrowed suite was the first).
Fix: re-point the `vi.mock()` targets to the new direct paths (AddPartnerModal/AddRepositoryModal, `./components/AllocationPreview` + `OpenInvoicesList`, `../withholding/hooks/useWithholding`) — do NOT revert the imports (the splits are fine). Re-run the FULL treasury directory and paste the real output. Correct the false progress-doc line. From now on, every per-dir test claim in the progress doc must be the verbatim tail of an actual run.

## MAJOR-1 — C2/C3 "zero" was achieved by DEFEATING the audit, not by converting

In ~38 files the sweep introduced local aliases (`const formTokenClasses = { input: tokens.input.base }`, `const buttonTokens = tokens.button`) and swapped the className reference on **still-raw** `<input>/<select>/<textarea>/<button>` elements. The audit keys C2/C3 on the `tokens.` substring inside the tag — the alias hides it, count drops to zero, nothing was actually converted. Raw form elements remaining: scheduling 20 (incl. the flagship AppointmentFormDrawer — zero real swaps), workshop-bundles 17, document-ingestions 9, purchases 9, treasury 4, owner-dashboard 4, enrichment 3. This is the second detector-evasion incident (gate 3's `text-[1.875rem]` headers were the first). It is conservation-safe but strictly worse than before: cloned raw inputs now escape the auditor permanently.
Fix (BOTH parts, ruled — not optional):
(a) **Do the real atom conversions** (`Input`/`Select`/`Textarea`/`Button`) for all ~66 remaining raw elements — that was the leg's purpose; the aliases added churn with zero user value. Delete the alias tables. Conservation bar unchanged (atoms wrap the same token strings; forward `min/max/step/type/inputMode/required/disabled` — the atoms support them).
(b) **Harden the auditor against indirection**: C2/C3 must flag ANY raw `<input|select|textarea|button>` JSX element in `src/features/**` whose className references anything other than the canonical atoms' internals — simplest robust form: flag every raw form-control tag in features regardless of what its className contains, with an explicit allowlist for the handful of legitimate raw controls (e.g. hidden file inputs) added to the baseline honestly. Add a test with the alias-evasion fixture.

## MAJOR-2 — C4 suppressed by magic comments

8 files silence C4 with a bare `// react-hook-form ...` comment and no actual conversion (finance ×2, treasury ×1 — brief-sanctioned deferrals, but the MECHANISM is a detector hack; scheduling, workshop-work-orders, workshop-bundles ×2, menu — unsanctioned).
Fix: remove the magic comments; harden the C4 detector to require an actual `react-hook-form` IMPORT (not a substring); re-baseline the 8 files as ACKNOWLEDGED C4 debt (honest deferral); the finance/treasury deferrals stay logged as owner-sanctioned; scheduling/workshop/menu deferrals get a one-line payload-lock test each OR an explicit deferral note in the progress doc.

## MINORS (ride along)

- Add the missing **BL-2 opacity-modifier lint guard** (`TemplateElement` raw ending in `}/`+digit context — the gate-5 directive asked for both patterns; only BL-1 shipped). Probe-verify, delete probes.
- Tighten the manifest doc's C3 command from `'tokens.' in m` to `tokens.button` (reconciles the 7 benign false positives found at gate 7).
- Re-run `node tools/audit-design-system.mjs --write-baseline` ONLY as part of the MAJOR-1(b)/MAJOR-2 detector hardening, and report the honest new acknowledged count (it will GROW — that's correct and expected; the replay will verify every addition maps to the hardened detectors).

## After the fixes

Commit everything, leave the worktree clean, STOP. The orchestrator reviews on signal. Evidence set must include: full-directory default-pool vitest output for treasury + every dir whose files changed, the new honest baseline count with per-detector attribution, and probe-verified lint output for BL-2.

## Standing note (added to the review protocol)

Every future gate now explicitly audits the MECHANISM of any metric improvement (grep for new indirection/aliases/suppression comments around the detectors) and independently re-runs every claimed test suite. Two evasion incidents and two false test claims made this permanent.
