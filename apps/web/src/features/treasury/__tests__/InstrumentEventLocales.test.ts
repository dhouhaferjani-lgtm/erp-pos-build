import { describe, expect, it } from 'vitest'

import ar from '@/locales/ar/treasury.json'
import en from '@/locales/en/treasury.json'
import fr from '@/locales/fr/treasury.json'

const eventTypes = [
  'created',
  'issued',
  'details_updated',
  'custody_transferred',
  'remitted',
  'cleared',
  'bounced',
  're_presented',
  'cancelled',
] as const

describe('instrument event locale completeness', () => {
  it.each(Object.entries({ ar, en, fr }))('%s resolves every backend instrument event type', (_locale, treasury) => {
    const events = treasury.instruments.events as Record<string, string>

    for (const eventType of eventTypes) {
      expect(events[eventType], `missing instruments.events.${eventType}`).toBeTruthy()
    }
  })
})
