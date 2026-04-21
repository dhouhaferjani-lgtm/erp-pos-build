import { describe, it, expect, vi, beforeEach } from 'vitest'
import { screen, waitFor, fireEvent } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { AxiosError, AxiosHeaders } from 'axios'
import { renderWithProviders } from '@/test/renderWithProviders'
import { TimeOffFormModal } from '../TimeOffFormModal'
import type { TechnicianTimeOff } from '../../api/authoringTypes'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockApiPatch = vi.hoisted(() => vi.fn())
const mockApiDelete = vi.hoisted(() => vi.fn())

interface ApiEnvelope<T> {
  data: { data: T }
}

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
    apiGet: vi.fn().mockImplementation(() => Promise.resolve([])),
    apiPost: vi.fn().mockImplementation(async (url: string, body: unknown) => {
      const r = (await mockApiPost(url, body)) as ApiEnvelope<unknown>
      return r.data.data
    }),
    apiPatch: vi.fn().mockImplementation(async (url: string, body: unknown) => {
      const r = (await mockApiPatch(url, body)) as ApiEnvelope<unknown>
      return r.data.data
    }),
    apiDelete: vi.fn().mockImplementation(() => Promise.resolve(undefined)),
  }
})

const TECH_ID = 'tech-1'

function buildTimeOff(): TechnicianTimeOff {
  return {
    id: 'to-1',
    technician_profile_id: TECH_ID,
    starts_at: '2026-07-01T00:00:00.000Z',
    ends_at: '2026-07-05T23:59:59.000Z',
    reason_code: 'vacation',
    is_full_day: true,
    is_approved: false,
    approved_by_user_id: null,
    notes: null,
  }
}

describe('TimeOffFormModal', () => {
  beforeEach(() => {
    mockApiGet.mockReset()
    mockApiPost.mockReset()
    mockApiPatch.mockReset()
    mockApiDelete.mockReset()
  })

  it('renders the create title when no timeOff is passed', () => {
    renderWithProviders(
      <TimeOffFormModal
        technicianId={TECH_ID}
        onClose={() => undefined}
        onSaved={() => undefined}
      />,
    )
    expect(screen.getByRole('heading', { name: /Log time off/i })).toBeInTheDocument()
  })

  it('renders the edit title when timeOff is passed', () => {
    renderWithProviders(
      <TimeOffFormModal
        technicianId={TECH_ID}
        timeOff={buildTimeOff()}
        onClose={() => undefined}
        onSaved={() => undefined}
      />,
    )
    expect(screen.getByRole('heading', { name: /Edit time off/i })).toBeInTheDocument()
  })

  it('shows validation errors when starts_at / ends_at are empty', async () => {
    const user = userEvent.setup()
    renderWithProviders(
      <TimeOffFormModal
        technicianId={TECH_ID}
        onClose={() => undefined}
        onSaved={() => undefined}
      />,
    )
    await user.click(screen.getByRole('button', { name: /^Save$/i }))
    const required = screen.getAllByText(/This field is required/i)
    expect(required.length).toBeGreaterThanOrEqual(2)
    expect(mockApiPost).not.toHaveBeenCalled()
  })

  it('POSTs the payload and fires onSaved in create mode', async () => {
    mockApiPost.mockResolvedValue({
      data: { data: { ...buildTimeOff(), id: 'to-new' } },
    })
    const onSaved = vi.fn()
    const user = userEvent.setup()
    renderWithProviders(
      <TimeOffFormModal
        technicianId={TECH_ID}
        onClose={() => undefined}
        onSaved={onSaved}
      />,
    )

    const modal = screen.getByTestId('time-off-form-modal')
    const dateInputs = modal.querySelectorAll(
      'input[type="datetime-local"]',
    ) as NodeListOf<HTMLInputElement>
    fireEvent.change(dateInputs[0], { target: { value: '2026-07-01T08:00' } })
    fireEvent.change(dateInputs[1], { target: { value: '2026-07-05T17:00' } })

    await user.click(screen.getByRole('button', { name: /^Save$/i }))

    await waitFor(() => {
      expect(mockApiPost).toHaveBeenCalled()
    })
    const [url, payload] = mockApiPost.mock.calls[0] as [
      string,
      Record<string, unknown>,
    ]
    expect(url).toBe(`/workshop/technicians/${TECH_ID}/time-off`)
    expect(payload['reason_code']).toBe('vacation')
    expect(typeof payload['starts_at']).toBe('string')
    expect(typeof payload['ends_at']).toBe('string')
    await waitFor(() => {
      expect(onSaved).toHaveBeenCalled()
    })
  })

  it('surfaces the overlap error on 422 TIME_OFF_OVERLAP response', async () => {
    const overlapError = new AxiosError('overlap')
    overlapError.response = {
      status: 422,
      data: { error: { code: 'TIME_OFF_OVERLAP', message: 'overlap' } },
      statusText: 'Unprocessable Entity',
      headers: {},
      config: { headers: new AxiosHeaders() },
    }
    mockApiPost.mockRejectedValueOnce(overlapError)

    const user = userEvent.setup()
    renderWithProviders(
      <TimeOffFormModal
        technicianId={TECH_ID}
        onClose={() => undefined}
        onSaved={() => undefined}
      />,
    )

    const modal = screen.getByTestId('time-off-form-modal')
    const dateInputs = modal.querySelectorAll(
      'input[type="datetime-local"]',
    ) as NodeListOf<HTMLInputElement>
    fireEvent.change(dateInputs[0], { target: { value: '2026-07-01T08:00' } })
    fireEvent.change(dateInputs[1], { target: { value: '2026-07-05T17:00' } })

    await user.click(screen.getByRole('button', { name: /^Save$/i }))

    await waitFor(() => {
      expect(
        screen.getByText(/overlaps an existing entry/i),
      ).toBeInTheDocument()
    })
  })

  it('PATCHes the existing time-off in edit mode and fires onSaved', async () => {
    mockApiPatch.mockResolvedValue({
      data: { data: { ...buildTimeOff(), notes: 'updated' } },
    })
    const onSaved = vi.fn()
    const user = userEvent.setup()
    renderWithProviders(
      <TimeOffFormModal
        technicianId={TECH_ID}
        timeOff={buildTimeOff()}
        onClose={() => undefined}
        onSaved={onSaved}
      />,
    )

    await user.click(screen.getByRole('button', { name: /^Save$/i }))

    await waitFor(() => {
      expect(mockApiPatch).toHaveBeenCalled()
    })
    const [url] = mockApiPatch.mock.calls[0] as [string, Record<string, unknown>]
    expect(url).toBe(`/workshop/technicians/${TECH_ID}/time-off/to-1`)
    await waitFor(() => {
      expect(onSaved).toHaveBeenCalled()
    })
  })
})
