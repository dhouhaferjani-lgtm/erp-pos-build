import { describe, expect, it } from 'vitest'

import i18n from '@/lib/i18n'

describe('notifications i18n namespace', () => {
  it.each(['en', 'fr', 'ar'])('registers the namespace for %s', (language) => {
    const bundle: unknown = i18n.getResourceBundle(language, 'notifications')

    expect(bundle).toBeDefined()
    if (typeof bundle !== 'object' || bundle === null) throw new Error('notifications bundle must be an object')
    expect(bundle).toHaveProperty('title')
    expect(bundle).toHaveProperty('types.generic')
  })
})
