# Ticket: Lane C code-phase minor carry-overs (post-launch / next maintenance window)

From the Lane C wave-2/3 fix cycle (2026-08-01). None launch-blocking; all ruled/parked by the
orchestrator with reviewer visibility.

1. **CartItem.quantity is number-typed app-wide** — the v4 refund path now carries one canonical
   decimal-string quantity end-to-end, but one exact `Math.abs` sign-flip projection remains at
   the CartItem boundary because the shared sale-path `buildLineItems()` reads the number field.
   Sign-flip on a number is exact (no arithmetic), so parked by ruling. Proper fix: retype
   `CartItem.quantity` to decimal string across cart/stores/components — cross-cutting refactor,
   post-launch.

2. **ShiftCloseRequiresZReportTest::test_close_after_z_report_succeeds fails in PG mode**
   (pre-existing, found by wave 3): the legacy server-Z path writes `period_start == period_end`,
   violating the PG-only CHECK constraint `pos_grandtotal_period`. Either the writer must emit a
   non-degenerate period or the CHECK must permit the degenerate close; needs a fiscal look
   before touching either.

3. **StrictCanonicalParserTest pre-existing failure** (found by wave-2 fix-verify round 2,
   confirmed on unmodified baseline via git-stash): stale event_version registry assumption in
   the test. Update the test's registry expectations to the post-D1/v4 world.

4. **8 deferred Minors from the wave-2 fiscal gate record**
   (docs/superpowers/reviews/2026-08-01-lane-c-wave2-gate-fiscal-review.md) — M-1 and M-7 were
   taken opportunistically by the fix wave; the remaining 6 are triaged at the Lane C final
   whole-branch review; whatever it does not force lands here.

## Additions (2026-08-01, post final review — merge-ready close-out)
5. **422 RETURN_FAILED echoes raw PG error text (incl. failing row) to the client** — response
   hygiene; found while pinning the C-6 fail-closed test. Sanitize the error body.
6. **stock_movements delta assertion** missing from the C-6 fail-closed test (rollback is
   DB-guaranteed — single transaction, nothing afterCommit — but phantom restock is the
   quietest failure; add the one-line delta).
7. **quantityMagnitude() duplicated** across ReceiptReturnService and ReceiptController —
   extract to a shared helper.
8. **Per-terminal v3-from-birth detection** in pos:disable-v4-refund-authoring to replace the
   blanket warning block.
9. **denied_kind 'server_verification_required'** free-string audit value unregistered (fraud
   taxonomy when one exists).
10. **PosAnalyticsService:77** getSalesSummary.average_ticket same AVG-float pattern (safe-listed
    line, untouched); **CashierComparisonChart.tsx:64** does Number() on money (web, out of lane).
11. **PosAnalyticsService has no training-flag filter at all** (pre-existing; wave-4 report §5.4
    has file:line).
12. **E-9 runbook must state**: pos:disable-v4-refund-authoring HALTS refunds on v3-from-birth
    terminals (re-enable is the recovery); daily check of dead-lettered fiscal_event_projections
    + non-null refund_policy_alerts.
