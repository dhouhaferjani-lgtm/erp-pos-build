import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { AxiosError, AxiosHeaders, type AxiosResponse } from 'axios'
import { AssignmentsPage } from '../pages/AssignmentsPage'
import * as countryDefaultsApi from '../api/countryDefaultsApi'

vi.mock('../api/countryDefaultsApi', () => ({
  assignTemplate: vi.fn(),
  listAssignments: vi.fn(),
  listTemplates: vi.fn(),
}))

function apiError(status: number): AxiosError {
  const response: AxiosResponse = {
    status,
    statusText: String(status),
    data: {},
    headers: {},
    config: { headers: new AxiosHeaders() },
  }
  return new AxiosError(`Request failed with status code ${status}`, undefined, undefined, undefined, response)
}

function renderPage() {
  return render(
    <MemoryRouter>
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <AssignmentsPage />
      </QueryClientProvider>
    </MemoryRouter>
  )
}

describe('AssignmentsPage', () => {
  beforeEach(() => {
    vi.mocked(countryDefaultsApi.listAssignments).mockResolvedValue({
      data: [
        {
          country_code: '*',
          name: 'Generic fallback',
          pinned: true,
          domain: 'chart_of_accounts',
          assignment_id: 'assignment-wildcard',
          template_id: 'generic-template',
          template: null,
        },
        {
          country_code: 'TN',
          name: 'TN',
          pinned: false,
          domain: 'chart_of_accounts',
          assignment_id: 'assignment-tn',
          template_id: 'old-tn-template',
          template: {
            id: 'old-tn-template', domain: 'chart_of_accounts', name: 'Assigned Tunisia 2025',
            description: null, status: 'published', content_hash: 'old-hash', standard_ref: 'NC 41',
            certified_country_codes: ['TN'], capability_registry_version: '2025',
            certified_by: 'admin-old', published_at: '2025-01-01T00:00:00Z', cloned_from_id: null,
          },
        },
      ],
      meta: { catalog_version: 'iso-3166-1-alpha-2-2024' },
    })
    vi.mocked(countryDefaultsApi.listTemplates).mockResolvedValue([
      {
        id: 'new-tn-template', domain: 'chart_of_accounts', name: 'PCN Tunisia 2026',
        description: null, status: 'published', content_hash: 'hash', standard_ref: 'NC 41',
        certified_country_codes: ['TN'], capability_registry_version: '2026-08-11',
        certified_by: 'admin-1', published_at: '2026-08-11T10:00:00Z', cloned_from_id: null,
      },
      {
        id: 'fr-template', domain: 'chart_of_accounts', name: 'PCG France 2026',
        description: null, status: 'published', content_hash: 'hash-fr', standard_ref: 'PCG',
        certified_country_codes: ['FR'], capability_registry_version: '2026-08-11',
        certified_by: 'admin-1', published_at: '2026-08-11T10:00:00Z', cloned_from_id: null,
      },
    ])
  })

  it('pins the wildcard and offers only published templates certified for each row', async () => {
    renderPage()

    expect(await screen.findByText('Generic fallback')).toBeInTheDocument()
    expect(screen.getByLabelText('Pinned wildcard assignment')).toBeInTheDocument()
    const picker = screen.getByLabelText('Template for TN')
    expect(picker).toHaveValue('old-tn-template')
    expect(within(picker).getByRole('option', { name: 'Assigned Tunisia 2025' })).toBeInTheDocument()
    expect(picker).toHaveTextContent('PCN Tunisia 2026')
    expect(picker).not.toHaveTextContent('PCG France 2026')
    expect(screen.queryByRole('option', { name: 'Unassigned' })).not.toBeInTheDocument()
  })

  it('requires confirmation that re-pointing affects newly created companies only', async () => {
    const user = userEvent.setup()
    renderPage()

    const picker = await screen.findByLabelText('Template for TN')
    await user.selectOptions(picker, 'new-tn-template')
    await user.click(screen.getByRole('button', { name: 'Re-point TN' }))

    expect(screen.getByText('This affects newly created companies only.')).toBeInTheDocument()
    expect(countryDefaultsApi.assignTemplate).not.toHaveBeenCalled()
    await user.click(screen.getByRole('button', { name: 'Confirm re-point' }))
    expect(countryDefaultsApi.assignTemplate).toHaveBeenCalledWith('TN', {
      domain: 'chart_of_accounts',
      template_id: 'new-tn-template',
    })
  })

  it('keeps authoritative assignments visible when eligible templates cannot load', async () => {
    vi.mocked(countryDefaultsApi.listTemplates).mockRejectedValue(apiError(403))
    renderPage()

    const picker = await screen.findByLabelText('Template for TN')
    expect(picker).toHaveValue('old-tn-template')
    expect(within(picker).getByRole('option', { name: 'Assigned Tunisia 2025' })).toBeInTheDocument()
    expect(screen.getByText('You do not have permission to load eligible template options. Current assignments remain visible.')).toBeInTheDocument()
  })

  it('keeps the confirmation open and shows a localized assignment failure', async () => {
    const user = userEvent.setup()
    vi.mocked(countryDefaultsApi.assignTemplate).mockRejectedValue(apiError(500))
    renderPage()

    await user.selectOptions(await screen.findByLabelText('Template for TN'), 'new-tn-template')
    await user.click(screen.getByRole('button', { name: 'Re-point TN' }))
    await user.click(screen.getByRole('button', { name: 'Confirm re-point' }))

    expect(await screen.findByText('The server could not complete the request. Try again.')).toBeInTheDocument()
    expect(screen.getByRole('dialog', { name: 'Re-point TN?' })).toBeInTheDocument()
  })
})
