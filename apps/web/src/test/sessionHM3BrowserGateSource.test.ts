import { readFileSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const specSource = readFileSync(
  path.resolve(process.cwd(), 'e2e/session-h/m3-dead-crm-partner-vehicle-gates.spec.ts'),
  'utf8',
)

describe('Session H M3 restricted browser identity', () => {
  it('uses a real disposable login without intercepting auth/me', () => {
    expect(specSource).not.toMatch(/page\.route\(['"]\*\*\/api\/v1\/auth\/me/)
    expect(specSource).not.toContain('useRestrictedIdentity')
    expect(specSource).toContain('loggedPage(browser, restrictedCredentials!)')
  })
})
