import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { AxiosError, AxiosHeaders, type AxiosResponse } from 'axios'
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

describe('TemplateListPage', () => {
  beforeEach(() => {
    vi.mocked(countryDefaultsApi.archiveTemplate).mockReset()
    vi.mocked(countryDefaultsApi.cloneTemplate).mockReset()
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
    expect(screen.getByText('5cbeef')).toBeInTheDocument()
    expect(screen.getByText('2026-08-11')).toBeInTheDocument()
    expect(screen.getByText('admin-1')).toBeInTheDocument()
    expect(screen.getByText('2026-08-11T10:00:00Z')).toBeInTheDocument()
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

  it('distinguishes a server query failure from an empty template library', async () => {
    vi.mocked(countryDefaultsApi.listTemplates).mockRejectedValue(apiError(500))
    renderPage()

    expect(await screen.findByText('The template library could not be loaded. Try again.')).toBeInTheDocument()
    expect(screen.queryByText('No templates in this domain.')).not.toBeInTheDocument()
  })

  it('shows lifecycle conflicts returned by archive', async () => {
    const user = userEvent.setup()
    vi.mocked(countryDefaultsApi.archiveTemplate).mockRejectedValue(apiError(409))
    renderPage()

    await user.click(await screen.findByRole('button', { name: 'Archive' }))
    expect(await screen.findByText('The request conflicts with the current template lifecycle.')).toBeInTheDocument()
  })

  it('disables every lifecycle action while a clone request is pending', async () => {
    const user = userEvent.setup()
    vi.mocked(countryDefaultsApi.cloneTemplate).mockImplementation(() => new Promise<never>(() => {}))
    renderPage()

    const cloneButton = await screen.findByRole('button', { name: 'Clone' })
    const archiveButton = screen.getByRole('button', { name: 'Archive' })
    await user.click(cloneButton)

    await waitFor(() => {
      expect(cloneButton).toBeDisabled()
      expect(archiveButton).toBeDisabled()
    })
    await user.click(cloneButton)
    await user.click(archiveButton)

    expect(countryDefaultsApi.cloneTemplate).toHaveBeenCalledTimes(1)
    expect(countryDefaultsApi.archiveTemplate).not.toHaveBeenCalled()
  })

  it('never offers archive for a draft', async () => {
    vi.mocked(countryDefaultsApi.listTemplates).mockResolvedValue([{
      id: 'draft-1', domain: 'chart_of_accounts', name: 'Draft chart', description: null,
      status: 'draft', content_hash: null, standard_ref: null, certified_country_codes: null,
      capability_registry_version: null, certified_by: null, published_at: null, cloned_from_id: null,
    }])
    renderPage()
    expect(await screen.findByRole('button', { name: 'Archive' })).toBeDisabled()
  })
})
