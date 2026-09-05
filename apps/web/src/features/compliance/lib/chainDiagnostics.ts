/**
 * Maps the backend's hash-chain diagnostic strings to translated operator copy.
 *
 * `Nf525DataProvider::verifyReceiptChain()` / `verifyZReportChain()` emit
 * hardcoded ENGLISH developer prose in `Nf525ChainVerificationResult.error`
 * (e.g. `'sealed_hash_algorithm anomaly: NULL after backfill completion for
 * this terminal'`). Rendering that verbatim breaks CLAUDE.md rule 11 (no
 * hardcoded user-facing strings) and puts implementation vocabulary in front of
 * a French or Arabic NF525 auditor.
 *
 * Until the backend emits a stable machine `error_code`, we match on the stable
 * PREFIX of each known diagnostic — the part before the colon, which is the
 * literal the backend constructs and which no diagnostic shares with another.
 * Anything unmatched is NOT presented as operator copy: the panel renders it in
 * a labelled monospace technical-detail area instead.
 *
 * Source of the five known diagnostics (verified 2026-09-05):
 *   apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php
 *     :404 'Fiscal-events chain break: …'
 *     :430 'Chain linkage broken: previous_hash mismatch'
 *     :471 'sealed_hash_algorithm anomaly: …'
 *     :481 'Fiscal hash mismatch: receipt data may have been tampered with'
 *     :522 'Z-report chain break: …'
 */

interface DiagnosticMapping {
  /** Stable literal prefix constructed by the backend. */
  readonly prefix: string
  /** Key inside the `compliance` namespace. */
  readonly key: string
}

const DIAGNOSTIC_MAPPINGS: readonly DiagnosticMapping[] = [
  {
    prefix: 'Fiscal-events chain break:',
    key: 'chainVerification.errors.fiscalEventsChainBreak',
  },
  {
    prefix: 'Chain linkage broken:',
    key: 'chainVerification.errors.legacyLinkageBroken',
  },
  {
    prefix: 'sealed_hash_algorithm anomaly:',
    key: 'chainVerification.errors.sealedHashAlgorithmAnomaly',
  },
  {
    prefix: 'Fiscal hash mismatch:',
    key: 'chainVerification.errors.fiscalHashMismatch',
  },
  {
    prefix: 'Z-report chain break:',
    key: 'chainVerification.errors.zReportChainBreak',
  },
]

/**
 * Translation key for a known backend diagnostic, or `null` when the string is
 * not one this build knows about (a new/changed backend message).
 */
export function resolveChainDiagnosticKey(error: string): string | null {
  const trimmed = error.trim()
  const match = DIAGNOSTIC_MAPPINGS.find((mapping) =>
    trimmed.startsWith(mapping.prefix)
  )

  return match?.key ?? null
}
