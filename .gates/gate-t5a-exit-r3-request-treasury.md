# Treasury Phase ⑤a whole-branch exit review — round 3 after Fable arbitration

You are the **treasury-reviewer**. Review adversarially and code-first. Start with exactly `APPROVE` or `REJECT` on its own line. Cite actual `file:line` evidence for every finding. Do not edit, commit, merge, or push.

Repository: `/Users/houssamr/Projects/syneriva/apps/erp.treasury-phase5`

Branch: `feat/treasury-phase5`

Design base: `fd10632fb`

Review range: `git diff fd10632fb...HEAD`

Read in authority order:

1. Phase ⑤ Rev 2 spec: `docs/superpowers/specs/2026-07-18-treasury-phase5-outbound-and-bank-reconciliation-design.md`.
2. The three plan reviews under `docs/superpowers/reviews/`.
3. Phase ⑤a plan: `docs/superpowers/plans/2026-07-18-treasury-phase5a-outbound-instruments.md`.
4. `CLAUDE.md` rules 1–21.
5. Both Opus exit verdicts and the controlling Fable arbitration:
   - `.gates/gate-t5a-exit-verdict-treasury.md`
   - `.gates/gate-t5a-exit-r2-verdict-treasury.md`
   - `.gates/gate-t5a-exit-escalation-verdict-treasury.md`
6. The full branch diff and all committed gate evidence.

Do not review Phase ⑤b; it has not started. Under the operating contract, Fable's arbitration controls the disputed round-2 severity: its M-B boundary observation is non-blocking/ticketed, while B1 and M-A required fixes.

## Mandatory arbitration-fix verification

Independently verify:

1. Generic inbound `InstrumentLifecycleService::cancel()` rejects every outbound instrument and directs callers to the outbound lifecycle.
2. `OutboundInstrumentService::cancel()` detects the durable issue event while holding the instrument lock. Issued instruments receive the cancellation reversal; registered-never-issued outbound instruments receive no fabricated JE, but still get an action-keyed/digested Cancelled event, synchronous `InstrumentCancelled`, and exact replay with null produced IDs.
3. Payment-linked outbound cancellation fails loud if the durable issue event is absent.
4. The old `OutboundInstrumentGuardTest` expectation is inverted, and real tests cover both issued reversal and registered-never-issued GL-free replay.
5. `InstrumentDetailPage` hides all five inbound lifecycle affordances (remit/transfer/cancel/clear/bounce) for outbound rows across relevant statuses, while inbound behavior remains.
6. The deploy checklist explicitly accounts for Stancl `tenants:run` discarding child exit codes and includes mandatory log gates for dry-run and real backfill.

## Current committed evidence

- Focused backend rerun after arbitration fixes: 63 tests, 341 assertions.
- Focused frontend rerun: 26 tests.
- Pint, touched-file PHPStan, TypeScript, and scoped ESLint: clean.
- React Doctor scan of `src/features/treasury`: no diagnostic in the modified `InstrumentDetailPage`; remaining findings are in other pre-existing files.
- Live db-per-tenant Playwright rerun: 7 passed in 19.3 seconds.
- Refreshed screenshots show no inbound lifecycle buttons on outbound detail pages.
- Reconcile after the rerun: 7 repositories checked, 0 cash freezes, 0 portfolio drift, 0 errors.

## Required output

After the first-line decision:

1. Findings ordered BLOCKER → MAJOR → MINOR with exact evidence.
2. A table resolving every Fable-required fix as fixed/not fixed.
3. Exit-invariant checklist.
4. Test/evidence assessment.
5. End with `VERDICT: spec ✅/❌ + quality APPROVED/CHANGES-REQUESTED` and, if rejected, one exact required-fix line.

Do not reject on Fable-declared non-blocking items unless new code evidence shows concrete money corruption, authorization bypass, or an explicit unfulfilled spec requirement.
