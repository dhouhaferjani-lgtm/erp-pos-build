# GATE RECORD — R2-K-prev ROUND 2 (fiscal-pos axis, narrow re-gate)

**Verdict: CHANGES-REQUESTED.**
**Branch:** @ `c31483c83`; round-2 commits reviewed `a2572a865`, `13d80ede7`, `ec93d0dc7`.
**Method:** every changed line read; lane test file re-run; four independent red-runs + three reviewer probes (all patches reverted, tree clean at every checkpoint).

## Round-1 closures — ALL CONFIRMED
- **I-1 maturity over-refusal CLOSED the right way:** `HandlesMaturityTenderLeg` constructor-injected (`DepositReferenceResolutionService.php:85-87` — the same class the bridge holds at `TreasuryDepositBridge.php:72`); early return at `:192-194` after the four always-applying checks, before currency/frozen/checkpoint — matching the bridge's real control flow (`:173` flag, `:217-219` early return, `:249` port call). `exists()`→`first()` at `:113-118`. Maturity probe proven non-vacuous by targeted revert (`if (false)` → 422 with `payment_repository_frozen`).
- **Checkpoint mirror LINE-FOR-LINE FAITHFUL** vs `checkpointDisposition()` (`:852-874`): same null-checkpoint short-circuit, byte-identical company-timezone source, same `toDateString()` on both operands, same strict `>`, `allowBehindCheckpoint` correctly folded to constant-false for DEPOSIT_RECEIPT (`TreasuryDepositBridge.php:273`). No off-by-one-day surface.
- **Checkpoint probe discriminates on the ORPHAN, empirically:** with `:209` neutralized, `assertStatus(422)` and `error.code=BUSINESS_ERROR` both still PASS — only `assertNoDepositWasSealed()` fails. Exactly the implementer's stated pre-fix symptom (DomainException renderer masks the orphan). Correct probe design.
- **I-2 ticket-of-record CLOSED** (status PREVENTION LANDED / NARROWED / K-rec OPEN; maturity conditionality; original-table correction block; V2 reachability answered + pinned). **m-2/m-3 CLOSED**; **m-1 widened** (three membership sites + two-port sketch).
- **Regression:** lane file OK 22/130. `TreasuryDepositBridgeTest` 5E/4F — single pre-existing `ArgumentCountError` at `:300`; `git diff --stat` over the bridge + its test vs base is EMPTY. Ticketed. Pint pass; PHPStan clean.

## NEW FINDINGS

### I-1 (Important) — the round-2 early return REOPENED a seal-before-resolve orphan on the maturity path. Reproduced.
`:192-194` returns null (fully projectable) for every maturity tender, but the maturity branch has its own post-seal invariants nothing mirrors. `InstrumentLifecycleService::receive()` requires tender currency == COMPANY currency (`InstrumentLifecycleService.php:79`), post-seal via `TreasuryDepositBridge.php:170 → handleMaturityLeg()`. `RecordDepositRequest.php:92` accepts any `size:3` currency; controller forwards verbatim.
**Probe:** TND company/repo, CHECK method, `currency: 'EUR'` → HEAD: 422 "Instrument currency must match company currency." + **sealed DEPOSIT_RECEIPT count = 1 (permanent orphan)**; round-1 behaviour (`:192` → `if (false)`): 422 `payment_repository_currency_mismatch` + count = 0. **Regression introduced by `13d80ede7`**, with the dangerous symptom pattern (clean 422 + surviving orphan). Narrow reachability → Important, not Critical; but a prevention lane cannot merge while it demonstrably seals a receipt it cannot project.
**Fix:** replace the bare `return null` with a maturity branch mirroring that path's own invariants — at minimum company-currency equality.

### I-2 (Important) — parity table claims enumeration but omits the maturity path's other post-seal invariant. Reproduced.
Maturity branch calls `HandlesMaturityTenderLeg::portfolioAccountId()` → `InstrumentAccountResolver::resolveOrFail()` (`InstrumentAccountResolver.php:35-39`) which throws `MissingInstrumentAccountException` when the chart lacks 5312/5112 (ChecksToCollect) or 413 (EffectsReceivable) — post-seal.
**Probe:** TND currency, portfolio account NOT seeded → 422 "Missing instrument account 'checks_to_collect'…" + **sealed count = 1 (permanent orphan)**. Not a regression (round 1 never covered it) but ops-realistic — a chart seeded without instrument accounts orphans every cheque deposit. The new maturity test's own `seedChequePortfolioAccount()` (`RecordCustomerDepositTest.php:895-905`) is the tell.
**Fix:** add a `MissingInstrumentPortfolioAccount` refusal on the maturity branch, OR amend table + ticket to state the maturity invariants as enumerated-but-unmirrored K-rec residuals. The current "maturity path ⇒ nothing can fail" framing must not ship.

### m-1 (Minor) — checkpoint-logic duplication disposition: **acceptable-with-ticket-line, NOT extract-now.**
`checkpointDisposition` is private; extraction means editing `TreasuryMovementService`, whose header marks its ordering load-bearing (`:38-40`) — worse merge-time risk than 8 duplicated lines with an exact citation. But the ticket's drift section covers only the three membership sites + Account; the checkpoint duplication is recorded NOWHERE. Add as 4th entry ("fix them together or not at all"; sketch: shared `CheckpointPolicy` consumed by port + preflight).

## What to fix before merge
Maturity branch mirrors company-currency equality (+ portfolio-account resolvability, or an explicit table/ticket deferral); checkpoint duplication added as 4th drift-list entry.
