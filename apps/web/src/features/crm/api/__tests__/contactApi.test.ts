// TODO(test-pattern): this file mocks `apiGet`/`api.get` with hand-fed payloads. This antipattern
// lets the test pass even when the real backend response shape diverges (e.g. snake_case vs
// camelCase), because the mocked payload encodes the test author's assumption rather than reality.
// It was the root cause of the PR #37 casing slip. The correct fix is to validate the API contract
// via PHPUnit Feature tests with RefreshDatabase + a seeded fixture (see Testing Conventions in
// MEMORY.md and memory/feedback_frontend_api_test_antipattern.md). Component-level tests may mock
// hooks/providers, but the API contract layer should be tested against real responses.
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { fetchContacts, fetchContact, createContact, updateContact, contactKeys } from '../contactApi'

// Mock the api module
vi.mock('@/lib/api', () => ({
  api: {
    get: vi.fn(),
  },
  apiPost: vi.fn(),
  apiPatch: vi.fn(),
  apiDelete: vi.fn(),
}))

import { api, apiPost, apiPatch } from '@/lib/api'

const mockApi = api as unknown as { get: ReturnType<typeof vi.fn> }
const mockApiPost = apiPost as ReturnType<typeof vi.fn>
const mockApiPatch = apiPatch as ReturnType<typeof vi.fn>

describe('contactApi', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  describe('fetchContacts', () => {
    it('calls api.get with no params when no filters', async () => {
      const mockResponse = {
        data: {
          data: [],
          meta: { current_page: 1, last_page: 1, per_page: 25, total: 0, from: null, to: null },
        },
      }
      mockApi.get.mockResolvedValue(mockResponse)

      const result = await fetchContacts()

      expect(mockApi.get).toHaveBeenCalledWith('/contacts')
      expect(result).toEqual(mockResponse.data)
    })

    it('builds correct query params from filters', async () => {
      const mockResponse = {
        data: {
          data: [],
          meta: { current_page: 1, last_page: 1, per_page: 10, total: 0, from: null, to: null },
        },
      }
      mockApi.get.mockResolvedValue(mockResponse)

      await fetchContacts({ search: 'John', page: 2, per_page: 10 })

      const calledUrl = mockApi.get.mock.calls[0][0] as string
      expect(calledUrl).toContain('search=John')
      expect(calledUrl).toContain('page=2')
      expect(calledUrl).toContain('per_page=10')
    })

    it('includes party_id filter', async () => {
      mockApi.get.mockResolvedValue({ data: { data: [], meta: {} } })

      await fetchContacts({ party_id: 'uuid-123' })

      const calledUrl = mockApi.get.mock.calls[0][0] as string
      expect(calledUrl).toContain('party_id=uuid-123')
    })

    it('includes is_active filter', async () => {
      mockApi.get.mockResolvedValue({ data: { data: [], meta: {} } })

      await fetchContacts({ is_active: true })

      const calledUrl = mockApi.get.mock.calls[0][0] as string
      expect(calledUrl).toContain('is_active=true')
    })
  })

  describe('fetchContact', () => {
    it('calls api.get with correct endpoint and unwraps data', async () => {
      const mockContact = { id: '123', first_name: 'John', full_name: 'John Doe' }
      mockApi.get.mockResolvedValue({ data: { data: mockContact } })

      const result = await fetchContact('123')

      expect(mockApi.get).toHaveBeenCalledWith('/contacts/123')
      expect(result).toEqual(mockContact)
    })
  })

  describe('createContact', () => {
    it('sends correct payload via apiPost', async () => {
      const payload = { first_name: 'Jane', email: 'jane@example.com' }
      const mockContact = { id: '456', ...payload, full_name: 'Jane' }
      mockApiPost.mockResolvedValue(mockContact)

      const result = await createContact(payload)

      expect(mockApiPost).toHaveBeenCalledWith('/contacts', payload)
      expect(result).toEqual(mockContact)
    })
  })

  describe('updateContact', () => {
    it('uses PATCH method with correct endpoint', async () => {
      const payload = { first_name: 'Updated' }
      const mockContact = { id: '123', first_name: 'Updated', full_name: 'Updated' }
      mockApiPatch.mockResolvedValue(mockContact)

      const result = await updateContact('123', payload)

      expect(mockApiPatch).toHaveBeenCalledWith('/contacts/123', payload)
      expect(result).toEqual(mockContact)
    })
  })

  describe('contactKeys', () => {
    it('generates correct key hierarchy', () => {
      expect(contactKeys.all).toEqual(['contacts'])
      expect(contactKeys.lists()).toEqual(['contacts', 'list'])
      expect(contactKeys.list({ search: 'test' })).toEqual(['contacts', 'list', { search: 'test' }])
      expect(contactKeys.details()).toEqual(['contacts', 'detail'])
      expect(contactKeys.detail('123')).toEqual(['contacts', 'detail', '123'])
    })
  })
})
