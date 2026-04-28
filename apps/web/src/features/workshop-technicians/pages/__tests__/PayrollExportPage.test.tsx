import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { renderWithProviders } from '@/test/renderWithProviders'
import { PayrollExportPage } from '../PayrollExportPage'
import type { TechnicianProfile } from '../../api/types'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockApiPatch = vi.hoisted(() => vi.fn())
const mockApiDelete = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: {
      get: mockApiGet,
      post: mockApiPost,
      patch: mockApiPatch,
      delete: mockApiDelete,
    },
    apiGet: vi.fn().mockImplementation(async (url: string) => {
      if (url === '/workshop/technicians') {
        return [
          {
            id: 'tech-1',
            tenant_id: 't1',
            company_id: 'c1',
            user_id: 'u1',
            user_display_name: 'Alice Mechanic',
            user_email: 'alice@example.com',
            skill_level: 'senior',
            specialties: [],
            currency: 'TND',
            weekly_schedule: {
              mon: [],
              tue: [],
              wed: [],
              thu: [],
              fri: [],
              sat: [],
              sun: [],
            },
            hire_date: null,
            employment_status: 'active',
            employee_code: 'A-42',
            notes: null,
            is_active: true,
            created_at: '2026-01-01T00:00:00Z',
            updated_at: null,
          } satisfies TechnicianProfile,
        ]
      }
      return []
    }),
  }
})

describe('PayrollExportPage', () => {
  const originalCreateObjectURL = URL.createObjectURL
  const originalRevokeObjectURL = URL.revokeObjectURL

  beforeEach(() => {
    mockApiGet.mockReset()
    mockApiPost.mockReset()
    mockApiPatch.mockReset()
    mockApiDelete.mockReset()
    URL.createObjectURL = vi.fn(() => 'blob:mock-url')
    URL.revokeObjectURL = vi.fn()
  })

  afterEach(() => {
    URL.createObjectURL = originalCreateObjectURL
    URL.revokeObjectURL = originalRevokeObjectURL
  })

  it('renders the page title and the generate button', async () => {
    renderWithProviders(<PayrollExportPage />)
    expect(
      screen.getByRole('heading', { name: /Payroll exports/i }),
    ).toBeInTheDocument()
    expect(screen.getByTestId('payroll-generate-button')).toBeInTheDocument()
  })

  it('renders the technician list once technicians load', async () => {
    renderWithProviders(<PayrollExportPage />)
    await waitFor(() => {
      expect(screen.getByText(/Alice Mechanic/i)).toBeInTheDocument()
    })
    // Employee code surfaces alongside the name.
    expect(screen.getByText(/A-42/)).toBeInTheDocument()
  })

  it('POSTs the generate request and triggers a blob download', async () => {
    mockApiPost.mockResolvedValue({
      data: new Blob(['col1,col2\nval1,val2'], { type: 'text/csv' }),
    })

    const clickSpy = vi.spyOn(HTMLAnchorElement.prototype, 'click')

    const user = userEvent.setup()
    renderWithProviders(<PayrollExportPage />)

    await waitFor(() => {
      expect(screen.getByText(/Alice Mechanic/i)).toBeInTheDocument()
    })

    await user.click(screen.getByTestId('payroll-generate-button'))

    await waitFor(() => {
      expect(mockApiPost).toHaveBeenCalled()
    })
    const [url, payload, config] = mockApiPost.mock.calls[0] as [
      string,
      Record<string, unknown>,
      { responseType?: string },
    ]
    expect(url).toBe('/workshop/payroll-exports')
    expect(payload['pay_period_start']).toMatch(/^\d{4}-\d{2}-\d{2}$/)
    expect(payload['pay_period_end']).toMatch(/^\d{4}-\d{2}-\d{2}$/)
    expect(config.responseType).toBe('blob')

    await waitFor(() => {
      expect(URL.createObjectURL).toHaveBeenCalled()
    })
    expect(clickSpy).toHaveBeenCalled()

    clickSpy.mockRestore()
  })

  it('surfaces a generic error message when the API call fails', async () => {
    mockApiPost.mockRejectedValueOnce(new Error('boom'))

    const user = userEvent.setup()
    renderWithProviders(<PayrollExportPage />)

    await waitFor(() => {
      expect(screen.getByText(/Alice Mechanic/i)).toBeInTheDocument()
    })

    await user.click(screen.getByTestId('payroll-generate-button'))

    await waitFor(() => {
      expect(
        screen.getByText(/Something went wrong/i),
      ).toBeInTheDocument()
    })
  })

  it('includes selected technician_ids when checkboxes are toggled', async () => {
    mockApiPost.mockResolvedValue({
      data: new Blob(['a'], { type: 'text/csv' }),
    })

    const user = userEvent.setup()
    renderWithProviders(<PayrollExportPage />)

    await waitFor(() => {
      expect(screen.getByText(/Alice Mechanic/i)).toBeInTheDocument()
    })

    const checkbox = screen
      .getByText(/Alice Mechanic/i)
      .closest('label')
      ?.querySelector('input[type="checkbox"]') as HTMLInputElement
    expect(checkbox).not.toBeNull()
    await user.click(checkbox)

    await user.click(screen.getByTestId('payroll-generate-button'))

    await waitFor(() => {
      expect(mockApiPost).toHaveBeenCalled()
    })
    const [, payload] = mockApiPost.mock.calls[0] as [
      string,
      Record<string, unknown>,
    ]
    expect(payload['technician_ids']).toEqual(['tech-1'])
  })
})
