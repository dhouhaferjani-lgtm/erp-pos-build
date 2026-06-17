import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { WorkOrderCreatePage } from './WorkOrderCreatePage'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, opts?: { defaultValue?: string }) => opts?.defaultValue ?? key,
  }),
}))

const createMock = vi.fn()
vi.mock('../hooks/useWorkOrders', () => ({
  useCreateWorkOrder: () => ({ mutate: createMock, isPending: false }),
}))

const mockApiGet = vi.hoisted(() => vi.fn())
vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return { ...actual, api: { get: mockApiGet } }
})

function renderPage(): void {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <WorkOrderCreatePage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('WorkOrderCreatePage (canonical shell)', () => {
  beforeEach(() => {
    createMock.mockReset()
    mockApiGet.mockReset()
    mockApiGet.mockResolvedValue({ data: { data: [] } })
  })

  it('renders the create title as the single page h1 via PageHeader', () => {
    renderPage()
    expect(
      screen.getByRole('heading', { level: 1, name: 'create.title' }),
    ).toBeInTheDocument()
  })

  it('renders labelled type/currency/complaint fields via FormField + atoms', () => {
    renderPage()

    const type = screen.getByLabelText('fields.type')
    expect(type.tagName).toBe('SELECT')

    const currency = screen.getByLabelText('fields.currency')
    expect(currency.tagName).toBe('INPUT')

    const complaint = screen.getByLabelText('fields.customerComplaint')
    expect(complaint.tagName).toBe('TEXTAREA')
  })

  it('renders a submit Button and blocks submit until customer + vehicle chosen', () => {
    renderPage()

    const submit = screen.getByRole('button', { name: 'actions.create' })
    expect(submit.tagName).toBe('BUTTON')

    fireEvent.click(submit)
    // No customer/vehicle selected → validation short-circuits the mutation.
    expect(createMock).not.toHaveBeenCalled()
  })
})
