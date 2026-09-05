import { describe, it, expect } from 'vitest'

import enCrm from '@/locales/en/crm.json'
import frCrm from '@/locales/fr/crm.json'

/**
 * Keys referenced by ContactFormPage's zod schema. When a key is absent from
 * the crm namespace, i18next renders the raw key literal to the operator
 * instead of a message (DEV-QA-053). The Arabic bundle reuses enCrm
 * (see lib/i18n.ts) and fallbackLng is 'en', so covering en + fr is sufficient.
 */
const REQUIRED_VALIDATION_KEYS = ['firstNameRequired', 'invalidEmail', 'dateOfBirthFuture'] as const

describe('CRM contact validation i18n keys', () => {
  it.each([
    ['en', enCrm],
    ['fr', frCrm],
  ])('%s crm namespace defines every contact validation message', (_lang, bundle) => {
    const validation =
      (bundle as { contacts?: { validation?: Record<string, string> } }).contacts?.validation ?? {}

    for (const key of REQUIRED_VALIDATION_KEYS) {
      expect(validation[key], `missing contacts.validation.${key}`).toBeTruthy()
    }
  })
})
