import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { TemplateListPage } from '../pages/TemplateListPage'
import * as countryDefaultsApi from '../api/countryDefaultsApi'

vi.mock('../api/countryDefaultsApi', () => ({
  archiveTemplate: vi.fn(),
  cloneTemplate: vi.fn(),
  listTemplates: vi.fn(),
}))

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <MemoryRouter>
      <QueryClientProvider client={queryClient}>
        <TemplateListPage />
      </QueryClientProvider>
    </MemoryRouter>
  )
}

describe('TemplateListPage', () => {
  beforeEach(() => {
    vi.mocked(countryDefaultsApi.listTemplates).mockResolvedValue([
      {
        id: 'template-1',
        domain: 'chart_of_accounts',
        name: 'PCN Tunisie 2026',
        description: 'Certified Tunisian chart',
        status: 'published',
        content_hash: '5cbeef',
        standard_ref: 'NC 41-2026',
        certified_country_codes: ['TN'],
        capability_registry_version: '2026-08-11',
        certified_by: 'admin-1',
        published_at: '2026-08-11T10:00:00Z',
        cloned_from_id: null,
      },
    ])
  })

  it('shows status, certification scope, standard, and lifecycle actions', async () => {
    renderPage()

    expect(await screen.findByText('PCN Tunisie 2026')).toBeInTheDocument()
    expect(screen.getByText('Published')).toBeInTheDocument()
    expect(screen.getByText('TN')).toBeInTheDocument()
    expect(screen.getByText('NC 41-2026')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Edit template' })).toHaveAttribute(
      'href',
      '/admin/country-defaults/templates/template-1'
    )
    expect(screen.getByRole('button', { name: 'Clone' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Archive' })).toBeInTheDocument()
  })

  it('sends the selected domain through the plain admin query', async () => {
    const user = userEvent.setup()
    renderPage()

    await screen.findByText('PCN Tunisie 2026')
    await user.selectOptions(screen.getByLabelText('Domain'), 'chart_of_accounts')

    expect(countryDefaultsApi.listTemplates).toHaveBeenCalledWith('chart_of_accounts')
  })
})
