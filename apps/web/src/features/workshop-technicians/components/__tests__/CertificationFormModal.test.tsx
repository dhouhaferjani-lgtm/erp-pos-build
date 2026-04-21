import { describe, it, expect, vi, beforeEach } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { renderWithProviders } from '@/test/renderWithProviders'
import { CertificationFormModal } from '../CertificationFormModal'
import type { TechnicianCertification } from '../../api/authoringTypes'

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
    apiGet: vi.fn().mockImplementation(() => Promise.resolve([])),
    apiPost: vi.fn().mockImplementation((url: string, body: unknown) =>
      mockApiPost(url, body).then((r: { data: { data: unknown } }) => r.data.data),
    ),
    apiPatch: vi.fn().mockImplementation((url: string, body: unknown) =>
      mockApiPatch(url, body).then((r: { data: { data: unknown } }) => r.data.data),
    ),
    apiDelete: vi.fn().mockImplementation(() => Promise.resolve(undefined)),
  }
})

const TECH_ID = 'tech-1'

function buildCertification(): TechnicianCertification {
  return {
    id: 'cert-1',
    technician_profile_id: TECH_ID,
    certification_name: 'ASE Master',
    issuing_body: 'ASE',
    certificate_number: 'MA-42',
    issued_at: '2024-06-01',
    expires_at: '2029-06-01',
    notes: null,
    created_at: '2026-01-01T00:00:00Z',
  }
}

describe('CertificationFormModal', () => {
  beforeEach(() => {
    mockApiGet.mockReset()
    mockApiPost.mockReset()
    mockApiPatch.mockReset()
    mockApiDelete.mockReset()
  })

  it('renders the create title when no certification is passed', () => {
    renderWithProviders(
      <CertificationFormModal
        technicianId={TECH_ID}
        onClose={() => undefined}
        onSaved={() => undefined}
      />,
    )
    expect(screen.getByRole('heading', { name: /Add certification/i })).toBeInTheDocument()
    expect(screen.getByTestId('certification-form-modal')).toBeInTheDocument()
  })

  it('renders the edit title and pre-fills values in edit mode', () => {
    renderWithProviders(
      <CertificationFormModal
        technicianId={TECH_ID}
        certification={buildCertification()}
        onClose={() => undefined}
        onSaved={() => undefined}
      />,
    )
    expect(screen.getByRole('heading', { name: /Edit certification/i })).toBeInTheDocument()
    expect(screen.getByDisplayValue('ASE Master')).toBeInTheDocument()
    expect(screen.getByDisplayValue('ASE')).toBeInTheDocument()
  })

  it('shows a validation error when certification_name is blank on submit', async () => {
    const user = userEvent.setup()
    renderWithProviders(
      <CertificationFormModal
        technicianId={TECH_ID}
        onClose={() => undefined}
        onSaved={() => undefined}
      />,
    )
    await user.click(screen.getByRole('button', { name: /^Save$/i }))
    expect(screen.getByText(/This field is required/i)).toBeInTheDocument()
    expect(mockApiPost).not.toHaveBeenCalled()
  })

  it('POSTs the payload and fires onSaved in create mode', async () => {
    mockApiPost.mockResolvedValue({
      data: { data: { ...buildCertification(), id: 'cert-new' } },
    })
    const onSaved = vi.fn()
    const user = userEvent.setup()
    renderWithProviders(
      <CertificationFormModal
        technicianId={TECH_ID}
        onClose={() => undefined}
        onSaved={onSaved}
      />,
    )

    // The modal has 3 text <input>s (name, issuing body, certificate number)
    // + 2 date inputs + 1 textarea. The first text input is the name.
    const modal = screen.getByTestId('certification-form-modal')
    const textInputs = modal.querySelectorAll('input[type="text"]')
    const nameInput = textInputs[0] as HTMLInputElement
    await user.type(nameInput, 'ASE Master')

    await user.click(screen.getByRole('button', { name: /^Save$/i }))

    await waitFor(() => {
      expect(mockApiPost).toHaveBeenCalled()
    })
    const [url, payload] = mockApiPost.mock.calls[0] as [
      string,
      Record<string, unknown>,
    ]
    expect(url).toBe(`/workshop/technicians/${TECH_ID}/certifications`)
    expect(payload['certification_name']).toBe('ASE Master')
    await waitFor(() => {
      expect(onSaved).toHaveBeenCalled()
    })
  })

  it('PATCHes the existing certification in edit mode and fires onSaved', async () => {
    mockApiPatch.mockResolvedValue({
      data: {
        data: { ...buildCertification(), certification_name: 'ASE Master (renewed)' },
      },
    })
    const onSaved = vi.fn()
    const user = userEvent.setup()
    renderWithProviders(
      <CertificationFormModal
        technicianId={TECH_ID}
        certification={buildCertification()}
        onClose={() => undefined}
        onSaved={onSaved}
      />,
    )

    const nameInput = screen.getByDisplayValue('ASE Master')
    await user.clear(nameInput)
    await user.type(nameInput, 'ASE Master (renewed)')

    await user.click(screen.getByRole('button', { name: /^Save$/i }))

    await waitFor(() => {
      expect(mockApiPatch).toHaveBeenCalled()
    })
    const [url, payload] = mockApiPatch.mock.calls[0] as [
      string,
      Record<string, unknown>,
    ]
    expect(url).toBe(`/workshop/technicians/${TECH_ID}/certifications/cert-1`)
    expect(payload['certification_name']).toBe('ASE Master (renewed)')
    await waitFor(() => {
      expect(onSaved).toHaveBeenCalled()
    })
  })
})
