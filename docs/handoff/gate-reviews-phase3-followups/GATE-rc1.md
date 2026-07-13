# Adversarial gate review — Treasury Phase ③ follow-ups

I verified all 6 items in `git diff origin/dev..HEAD` against the brief, re-deriving every claim from source (file:line). **Port `TreasuryMovementService.php` is byte-untouched** (not in the diff). No stray files.

## Verdicts

- **F-1** ✅ Zero `generated.d.ts` drift; F1-report records the transform ran (434 types, exit 0) and FE-local interfaces stay authoritative — exactly the prescribed outcome.
- **F-2** ✅ Button gated on `treasury.transfer` (`RepositoryDetailPage.tsx:316`), opens `TransferCashModal` preselected via new `initialFromRepositoryId` prop; preselection is stable+changeable (the `useEffect:109` only resets the *to* field). i18n keys `transfer.action`/`.from` exist in en/fr/ar. *Minor note:* extra `is_active && type!=='virtual'` gating beyond the brief — defensible (mirrors the modal's own active/non-virtual eligibility), not blocking.
- **F-3** ✅ The crux. Verified against `ReconcileTreasuryCommand::isSameGlAccountTransfer` (`:625`) — the exemption reads *current* `gl_account_id`, so reassignment really does flip null-JE legs to non-exempt. Guard fires only on effective change, counts this repo's `transfer`+null-JE legs, throws `DomainException`→422/BUSINESS_ERROR. Since same-GL transfers write null-JE legs on **both** repos, the updated repo always carries its own catchable leg. Race-safe: transaction + `lockForUpdate` re-read serializes against `TreasuryMovementService::transfer`'s own row locks (`:186-217`) — both interleavings converge. This exceeds the brief correctly; no BLOCKER/HIGH, so no Fable escalation needed.
- **F-4** ✅ `whereRaw`→fluent `->where`, behavior identical, no tests modified. *Minor note:* implemented via a typed anonymous-class closure (for PHPStan L8 generics) rather than a bare inline `->where` — heavier than asked but equivalent.
- **F-5** ✅ One comment only; SQL byte-identical; the `decimal(15,3)` claim is factually correct (migration `:20`).
- **F-6** ✅ `bg-white`→`colors.white`; `colors.white === 'bg-white'` → rendered class identical, zero regression.

## Residual caveat

Live `phpunit`/`vitest`/`typecheck`/`lint` and the two `node tools/audit-*.mjs` runs were blocked by the reviewing session's sandbox; findings are from source inspection corroborated by the F-reports. The implementing session independently ran the required local verification and recorded the results in `docs/handoff/treasury-phase3-followups-progress.md`.

VERDICT: APPROVE
