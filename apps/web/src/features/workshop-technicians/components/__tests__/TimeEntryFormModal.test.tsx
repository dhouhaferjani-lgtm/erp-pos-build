import { describe, it, expect, vi, beforeEach } from 'vitest'
import { screen, waitFor, fireEvent } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { AxiosError, AxiosHeaders } from 'axios'
import { renderWithProviders } from '@/test/renderWithProviders'
import { TimeEntryFormModal } from '../TimeEntryFormModal'
import type { TechnicianTimeEntry } from '../../api/authoringTypes'

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

function buildTimeEntry(
  overrides: Partial<TechnicianTimeEntry> = {},
): TechnicianTimeEntry {
  return {
    id: 'te-1',
    technician_profile_id: TECH_ID,
    company_id: 'c1',
    started_at: '2026-07-01T08:00:00.000Z',
    ended_at: '2026-07-01T12:00:00.000Z',
    duration_minutes: 240,
    entry_type: 'work_order',
    work_order_id: null,
    work_order_status: null,
    source: 'manual',
    recorded_by_user_id: null,
    notes: null,
    ...overrides,
  }
}

describe('TimeEntryFormModal', () => {
  beforeEach(() => {
    mockApiGet.mockReset()
    mockApiPost.mockReset()
    mockApiPatch.mockReset()
    mockApiDelete.mockReset()
  })

  it('renders the create title when no timeEntry is passed', () => {
    renderWithProviders(
      <TimeEntryFormModal
        technicianId={TECH_ID}
        onClose={() => undefined}
        onSaved={() => undefined}
      />,
    )
    expect(screen.getByRole('heading', { name: /Log time entry/i })).toBeInTheDocument()
  })

  it('renders the edit title when timeEntry is passed', () => {
    renderWithProviders(
      <TimeEntryFormModal
        technicianId={TECH_ID}
        timeEntry={buildTimeEntry()}
        onClose={() => undefined}
        onSaved={() => undefined}
      />,
    )
    expect(screen.getByRole('heading', { name: /Edit time entry/i })).toBeInTheDocument()
  })

  it('shows validation errors when started_at / ended_at are empty', async () => {
    const user = userEvent.setup()
    renderWithProviders(
      <TimeEntryFormModal
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
      data: { data: buildTimeEntry({ id: 'te-new' }) },
    })
    const onSaved = vi.fn()
    const user = userEvent.setup()
    renderWithProviders(
      <TimeEntryFormModal
        technicianId={TECH_ID}
        onClose={() => undefined}
        onSaved={onSaved}
      />,
    )

    const modal = screen.getByTestId('time-entry-form-modal')
    const dateInputs = modal.querySelectorAll(
      'input[type="datetime-local"]',
    ) as NodeListOf<HTMLInputElement>
    fireEvent.change(dateInputs[0], { target: { value: '2026-07-01T08:00' } })
    fireEvent.change(dateInputs[1], { target: { value: '2026-07-01T12:00' } })

    await user.click(screen.getByRole('button', { name: /^Save$/i }))

    await waitFor(() => {
      expect(mockApiPost).toHaveBeenCalled()
    })
    const [url, payload] = mockApiPost.mock.calls[0] as [
      string,
      Record<string, unknown>,
    ]
    expect(url).toBe(`/workshop/technicians/${TECH_ID}/time-entries`)
    expect(payload['entry_type']).toBe('work_order')
    expect(typeof payload['started_at']).toBe('string')
    expect(typeof payload['ended_at']).toBe('string')
    await waitFor(() => {
      expect(onSaved).toHaveBeenCalled()
    })
  })

  it('surfaces the locked error on 422 TIME_ENTRY_LOCKED response', async () => {
    const lockedError = new AxiosError('locked')
    lockedError.response = {
      status: 422,
      data: { error: { code: 'TIME_ENTRY_LOCKED', message: 'locked' } },
      statusText: 'Unprocessable Entity',
      headers: {},
      config: { headers: new AxiosHeaders() },
    }
    mockApiPatch.mockRejectedValueOnce(lockedError)

    const user = userEvent.setup()
    renderWithProviders(
      <TimeEntryFormModal
        technicianId={TECH_ID}
        timeEntry={buildTimeEntry()}
        onClose={() => undefined}
        onSaved={() => undefined}
      />,
    )

    await user.click(screen.getByRole('button', { name: /^Save$/i }))

    await waitFor(() => {
      expect(
        screen.getByText(/completed or invoiced work order/i),
      ).toBeInTheDocument()
    })
  })

  it('PATCHes the existing time-entry in edit mode and fires onSaved', async () => {
    mockApiPatch.mockResolvedValue({
      data: { data: buildTimeEntry({ notes: 'updated' }) },
    })
    const onSaved = vi.fn()
    const user = userEvent.setup()
    renderWithProviders(
      <TimeEntryFormModal
        technicianId={TECH_ID}
        timeEntry={buildTimeEntry()}
        onClose={() => undefined}
        onSaved={onSaved}
      />,
    )

    await user.click(screen.getByRole('button', { name: /^Save$/i }))

    await waitFor(() => {
      expect(mockApiPatch).toHaveBeenCalled()
    })
    const [url] = mockApiPatch.mock.calls[0] as [string, Record<string, unknown>]
    expect(url).toBe(`/workshop/technicians/${TECH_ID}/time-entries/te-1`)
    await waitFor(() => {
      expect(onSaved).toHaveBeenCalled()
    })
  })
})
