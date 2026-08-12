import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { AssignmentsPage } from '../pages/AssignmentsPage'
import * as countryDefaultsApi from '../api/countryDefaultsApi'

vi.mock('../api/countryDefaultsApi', () => ({
  assignTemplate: vi.fn(),
  listAssignments: vi.fn(),
  listTemplates: vi.fn(),
}))

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
          template: null,
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
    render(
      <MemoryRouter>
        <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
          <AssignmentsPage />
        </QueryClientProvider>
      </MemoryRouter>
    )

    expect(await screen.findByText('Generic fallback')).toBeInTheDocument()
    expect(screen.getByLabelText('Pinned wildcard assignment')).toBeInTheDocument()
    const picker = screen.getByLabelText('Template for TN')
    expect(picker).toHaveTextContent('PCN Tunisia 2026')
    expect(picker).not.toHaveTextContent('PCG France 2026')
    expect(screen.queryByRole('option', { name: 'Unassigned' })).not.toBeInTheDocument()
  })

  it('requires confirmation that re-pointing affects newly created companies only', async () => {
    const user = userEvent.setup()
    render(
      <MemoryRouter>
        <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
          <AssignmentsPage />
        </QueryClientProvider>
      </MemoryRouter>
    )

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
})
