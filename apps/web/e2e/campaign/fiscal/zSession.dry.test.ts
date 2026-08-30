import { createHash } from 'node:crypto'
import { readFile } from 'node:fs/promises'
import { describe, expect, it } from 'vitest'
import { buildFixedZSessionEnvelopes, Z_SESSION_GOLDEN_HASHES } from './zSession.golden'

describe('L0a Z-session dry construction', () => {
  it('constructs and prints the three sealed envelopes without network access', async () => {
    const envelopes = await buildFixedZSessionEnvelopes()
    for (const envelope of Object.values(envelopes)) {
      expect(Object.keys(JSON.parse(envelope.canonicalBytes) as Record<string, unknown>)).toHaveLength(15)
      expect(createHash('sha256').update(envelope.canonicalBytes).digest('hex')).toBe(envelope.currentHash)
    }
    expect(envelopes.open.currentHash).toBe(Z_SESSION_GOLDEN_HASHES.SESSION_OPEN)
    expect(envelopes.close.currentHash).toBe(Z_SESSION_GOLDEN_HASHES.SESSION_CLOSE)
    expect(envelopes.zReport.currentHash).toBe(Z_SESSION_GOLDEN_HASHES.Z_REPORT)

    const semanticPath = new URL('./zSession.semantic.json', import.meta.url)
    const semantic = JSON.parse(await readFile(semanticPath, 'utf8')) as {
      citations: Record<string, string[]>
      values: Record<string, unknown>
    }
    expect(Object.keys(semantic.citations).sort()).toEqual(semanticLeafPaths(semantic.values).sort())
    expect(Object.values(semantic.citations).every((citations) => citations.length > 0)).toBe(true)
    expect(semantic.values).toMatchObject({
      grand_totals_after: envelopes.zReport.payload['grand_totals_after'],
      grand_totals_before: envelopes.zReport.payload['grand_totals_before'],
      operational_event_range: envelopes.zReport.payload['operational_event_range'],
      payment_method_totals: envelopes.zReport.payload['payment_method_totals'],
      receipt_totals: envelopes.zReport.payload['receipt_totals'],
      refunds_totals: envelopes.zReport.payload['refunds_totals'],
      session_event_range: envelopes.zReport.payload['session_event_range'],
      vat_breakdown: envelopes.zReport.payload['vat_breakdown'],
    })

    const dryEnvelope = (envelope: typeof envelopes.open) => {
      const transport = envelope.requestBody.envelopes[0]?.payload ?? {}
      return {
        canonical_envelope: JSON.parse(envelope.canonicalBytes) as Record<string, unknown>,
        sealed_coordinates: {
          canonical_key_count: 15,
          current_hash: envelope.currentHash,
          event_id: envelope.eventId,
          reference_event_id: transport['reference_event_id'],
          source_event_class: transport['source_event_class'],
          source_event_id: transport['source_event_id'],
        },
      }
    }
    console.log(JSON.stringify({
      SESSION_CLOSE: dryEnvelope(envelopes.close),
      SESSION_OPEN: dryEnvelope(envelopes.open),
      Z_REPORT: dryEnvelope(envelopes.zReport),
      sha256: {
        SESSION_CLOSE: Z_SESSION_GOLDEN_HASHES.SESSION_CLOSE,
        SESSION_OPEN: Z_SESSION_GOLDEN_HASHES.SESSION_OPEN,
        Z_REPORT: Z_SESSION_GOLDEN_HASHES.Z_REPORT,
      },
    }, null, 2))
  })
})

function semanticLeafPaths(value: unknown, prefix = ''): string[] {
  if (Array.isArray(value)) {
    return value.flatMap((item, index) => semanticLeafPaths(item, `${prefix}[${String(index)}]`))
  }
  if (typeof value === 'object' && value !== null) {
    return Object.entries(value).flatMap(([key, item]) =>
      semanticLeafPaths(item, prefix === '' ? key : `${prefix}.${key}`))
  }
  return [prefix]
}
