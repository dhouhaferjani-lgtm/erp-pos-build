import { describe, expect, it } from 'vitest'

import {
  classifyOtospexAuthentication,
  loginRequestData,
  requireOtospexCompany,
  requireOtospexPartners,
} from '../../e2e/session-h/helpers'

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

  it('classifies an unavailable authentication response as skippable', () => {
    expect(classifyOtospexAuthentication({
      data: null,
      label: 'email-first login',
      ok: false,
      status: 503,
      text: 'Tenant database is not provisioned',
    })).toEqual({
      available: false,
      reason: 'Otospex email-first login unavailable: HTTP 503 (Tenant database is not provisioned).',
    })
  })

  it('fails an unreadable successful authentication response', () => {
    expect(() => classifyOtospexAuthentication({
      data: null,
      label: 'email-first login',
      ok: true,
      status: 200,
      text: '<html>unexpected</html>',
    })).toThrow(/unreadable successful response/i)
  })

  it('fails company API errors after authentication', () => {
    expect(() => requireOtospexCompany({
      data: [],
      ok: false,
      status: 500,
      text: 'company lookup exploded',
    })).toThrow(/company discovery failed after authentication.*HTTP 500/i)
  })

  it('fails an empty company response after authentication', () => {
    expect(() => requireOtospexCompany({ data: [], ok: true, status: 200, text: '' }))
      .toThrow(/no company/i)
  })

  it('fails partner API errors after authentication', () => {
    expect(() => requireOtospexPartners(
      { data: [], ok: false, status: 500, text: 'customer lookup exploded' },
      { data: [], ok: true, status: 200, text: '' },
    )).toThrow(/partner discovery failed after authentication.*customer HTTP 500/i)
  })

  it.each([
    {
      customers: [],
      expected: /missing a customer fixture/i,
      suppliers: [{ id: 'supplier-1', name: 'Supplier', type: 'supplier' as const }],
    },
    {
      customers: [{ id: 'customer-1', name: 'Customer', type: 'customer' as const }],
      expected: /missing a supplier-only fixture/i,
      suppliers: [],
    },
  ])('fails missing deterministic partner fixtures after authentication', ({ customers, expected, suppliers }) => {
    expect(() => requireOtospexPartners(
      { data: customers, ok: true, status: 200, text: '' },
      { data: suppliers, ok: true, status: 200, text: '' },
    )).toThrow(expected)
  })
})
