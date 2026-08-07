import { describe, expect, it } from 'vitest'

import ar from '@/locales/ar/support-access.json'
import en from '@/locales/en/support-access.json'
import fr from '@/locales/fr/support-access.json'

type EventType = App.Modules.SupportAccess.Domain.Enums.SessionEventType

const eventTranslations = (events: Record<EventType, string>): Record<EventType, string> => events

describe('support-access lifecycle event translations', () => {
  it('covers every backend session event in every supported locale', () => {
    const english = eventTranslations(en.events)
    const french = eventTranslations(fr.events)
    const arabic = eventTranslations(ar.events)

    expect(Object.keys(french)).toEqual(Object.keys(english))
    expect(Object.keys(arabic)).toEqual(Object.keys(english))
  })
})
