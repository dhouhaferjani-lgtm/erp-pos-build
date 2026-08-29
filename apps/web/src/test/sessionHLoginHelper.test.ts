import { describe, expect, it } from 'vitest'

import { loginRequestData } from '../../e2e/session-h/helpers'

describe('Session H login helper', () => {
  it('maps a discovered tenant ID into the explicit-tenant login payload', () => {
    expect(loginRequestData({
      email: 'admin@demo.local',
      password: 'password',
      tenantId: '11111111-1111-4111-8111-111111111111',
    })).toEqual({
      email: 'admin@demo.local',
      password: 'password',
      tenant_id: '11111111-1111-4111-8111-111111111111',
    })
  })
})
