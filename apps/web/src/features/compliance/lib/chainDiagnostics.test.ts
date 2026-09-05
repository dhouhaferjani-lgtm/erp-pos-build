import { describe, it, expect } from 'vitest'
import { resolveChainDiagnosticKey } from './chainDiagnostics'

/**
 * The five diagnostics the backend can actually emit, copied VERBATIM from
 * apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php
 * (:404, :430, :471, :481, :522). If a backend string is reworded, the
 * corresponding case here goes red and the mapping must be revisited — that is
 * the point of pinning the literal text rather than a paraphrase.
 */
const BACKEND_DIAGNOSTICS: readonly [string, string][] = [
  [
    'Fiscal-events chain break: canonical_bytes rehash or linkage mismatch (see structured log entry for diagnostic)',
    'chainVerification.errors.fiscalEventsChainBreak',
  ],
  [
    'Chain linkage broken: previous_hash mismatch',
    'chainVerification.errors.legacyLinkageBroken',
  ],
  [
    'sealed_hash_algorithm anomaly: NULL after backfill completion for this terminal',
    'chainVerification.errors.sealedHashAlgorithmAnomaly',
  ],
  [
    'Fiscal hash mismatch: receipt data may have been tampered with',
    'chainVerification.errors.fiscalHashMismatch',
  ],
  [
    'Z-report chain break: canonical z_session chain or legacy Z-report linkage mismatch',
    'chainVerification.errors.zReportChainBreak',
  ],
]

describe('resolveChainDiagnosticKey', () => {
  it.each(BACKEND_DIAGNOSTICS)('maps %s', (raw, expectedKey) => {
    expect(resolveChainDiagnosticKey(raw)).toBe(expectedKey)
  })

  it('returns null for a diagnostic this build does not know', () => {
    expect(resolveChainDiagnosticKey('Some brand new backend failure: details')).toBeNull()
  })

  it('returns null for an empty diagnostic rather than guessing', () => {
    expect(resolveChainDiagnosticKey('   ')).toBeNull()
  })
})
