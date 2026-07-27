Test execution was permission-blocked for me as it was for all five prior reviewers, so my adjudication rests on code evidence — which is sufficient for every question the arbitration asked.

APPROVE

All six arbitration-mandated fixes are present in `821bf9d9f` and verified against code. No BLOCKER or MAJOR survives. I found no new money-corruption, authorization-bypass, or unfulfilled-spec evidence.

## Findings

**BLOCKER:** none.

**MAJOR:** none.

**MINOR (none blocking; ticket or fold into ⑤b):**

1. **The fail-loud durable-issue-event guard is untested.** `OutboundInstrumentService.php:587-589` throws `'Outbound instrument linked payment has no durable issue event.'`, but that string appears nowhere in `apps/api/tests/`. `OutboundCancelReopenTest.php:283` (`test_cancel_rejects_an_unresolvable_non_null_payment_link`) covers the adjacent missing-payment-row case, not this one. The guard is correct; it is simply unpinned against regression.
2. **Deploy-checklist grep gates do not halt a pasted block.** `docs/handoff/treasury-phase5a-deploy-checklist.md:24-27` uses bare `! grep -Eqi …` lines with no `set -e` and no `&&` chaining. Line 32 correctly asserts the greps "convert any fail-loud child result into a shell failure," but in an interactive paste the non-zero status is discarded and the following `db:seed` still runs. `&&`-chaining or a leading `set -euo pipefail` would make the stated semantics real. The patterns themselves are correct — I matched all four against the command's actual `sprintf` output (`BackfillPayableInstrumentAccountsCommand.php:57, 75, 87, 148`), including ERE-literal `\(s\)`.
3. **One backfill FAILURE path escapes the grep gate.** `BackfillPayableInstrumentAccountsCommand.php:26-32` returns `FAILURE` with `'Tenant treasury tables are unavailable.'`, which none of the four gate patterns match. A tenant whose schema is unavailable would no-op and pass the gate silently. Beyond the arbitration's literal M-A prescription, so non-blocking.
4. **The post-arbitration rerun evidence is uncommitted.** "63 tests, 341 assertions" appears only in the untracked `.gates/gate-t5a-exit-r3-request-treasury.md:40`; no committed gate file records it. The live Playwright evidence *is* committed (`docs/sessions/treasury-phase5a-e2e/REPORT.md`, refreshed to `7 passed (19.3s)` with new instrument UUIDs, so the screenshots are genuinely re-captured rather than stale).
5. **M-B listener read-port** — carried forward as ticketed per Fable's downgrade. `SyncExpenseOnInstrumentLifecycle.php` imports `PaymentInstrument` directly. Not re-litigated.

## Fable-required fix resolution

| # | Required fix | Status | Evidence |
|---|---|---|---|
| 1 | Generic `cancel()` rejects outbound | **Fixed** | `InstrumentLifecycleService.php:604-606` — guard sits *above* the status check, so it rejects outbound in every status, mirroring `:137-139` |
| 2 | Issue-conditional reversal under lock | **Fixed** | Lock `OutboundInstrumentService.php:529`; issue lookup `:567-572`; JE only when present `:634-652`; `journal_entry_id => $entry?->id` `:703` |
| 2b | Registered-never-issued still gets keyed event + sync dispatch + exact replay | **Fixed** | `action_key`/`semantic_digest` `:697-698`; `movement_id => null` `:704`; synchronous `event(new InstrumentCancelled(...))` `:709`; replay returns null IDs verbatim `:551-552` |
| 3 | Payment-linked cancel fails loud without issue event | **Fixed** | `:587-589` (untested — MINOR 1) |
| 4 | Guard test inverted + both real tests | **Fixed** | `OutboundInstrumentGuardTest.php:172` renamed, now `assertUnprocessable()` + status-unchanged assert; issued reversal `OutboundCancelReopenTest.php:77` (asserts Dr `ChecksToPay` / Cr SupplierPayable, `125.000`); GL-free replay `:120-166` (asserts `assertNull($first->journalEntryId)`, `$second->replayed`, unchanged `JournalEntry::count()`) |
| 5 | FE hides all five affordances for outbound | **Fixed** | `InstrumentDetailPage.tsx:185-189` — `!isOutbound &&` on remit/transfer/cancel/clear/bounce; tests `InstrumentDetailPage.test.tsx:186-188` (received) and `:219-220` (clearing), both with permissions explicitly granted so they test *direction*, not permission; inbound preserved at `:141` |
| 6 | Checklist accounts for `tenants:run` exit-code discard | **Fixed** | Checklist `:24-27` tee+grep gates, `:32` states the non-propagating semantics. Independently confirmed against `vendor/stancl/tenancy/src/Commands/Run.php:54` — `$this->call()` return discarded, `handle()` returns nothing → always exit 0 |

## Exit-invariant checklist

- **No generic-cancel bypass for outbound** ✅ — sole remaining generic-cancel production caller is the POS bridge (`TreasuryReceiptBridge.php:778`), and POS instruments are hard-coded `InstrumentDirection::Inbound` (`HandlesMaturityTenderLeg.php:81`), so the new guard causes no POS refund/void regression.
- **No fabricated JE for never-issued outbound** ✅ — `:634` conditional.
- **No stuck-instrument gap introduced** ✅ — I specifically checked whether the guard could strand outbound rows lacking a repository (`cancel` requires `repository_id` at `:563`). `PaymentInstrumentController::store` validates `repository_id` as `required` (`:149`), so every registered outbound instrument can reach `cancel-outbound`.
- **No double-reversal on cancel-from-Bounced** ✅ — bounce reverses the *clear* entry (Dr bank / Cr payable, `:282-295`), restoring the payable that issue created; the subsequent cancel reversal clears it exactly once.
- **Precision (rule 19)** ✅ — `bcformatStrict`/`bcsub`/`bccomp` with `$scaleResolver->getScale($instrument->currency)` (`:574, 632, 658`); no float touches money.
- **Authorization / split permissions** ✅ — `cancel-outbound` gated on `can:instruments.cancel-outbound` (`routes.php:157`), distinct from `instruments.cancel` (`:142`).
- **Rule 8 (event immutability)** ✅ — `InstrumentCancelled` is a new event; no pre-existing event restructured.
- **Listener atomicity** ✅ — `SyncExpenseOnInstrumentLifecycle` is not `ShouldQueue`, so synchronous dispatch inside the transaction is correct and a listener failure rolls the cancel back.

## Test/evidence assessment

The test additions are real behavioral pins, not assertion theater: the GL-free test counts journal entries before and after and asserts `sole()` on the action-keyed event; the issued-reversal test asserts both ledger lines by resolved account ID and the partner attribution. The guard-test inversion is a genuine inversion, not a deletion. Live E2E point 8 exercises cancel-from-bounced with expense reopen end to end against a db-per-tenant stack.

The standing gap is unchanged from Fable's note: **no reviewer has executed the suites.** Backend and frontend rerun counts are self-reported and uncommitted. Given that six independent code paths verify cleanly and the live Playwright run is committed with refreshed artifacts, this does not warrant a sixth rejection — but the counts should be captured in a committed gate file before merge, and the ⛔ multi-location §3 merge gate remains open regardless.

VERDICT: spec ✅ + quality APPROVED

`.gates/gate-t5a-exit-r3-verdict-treasury.md` is currently a zero-byte placeholder. I did not write to it per your no-edit instruction — say the word and I'll drop this verdict in.
