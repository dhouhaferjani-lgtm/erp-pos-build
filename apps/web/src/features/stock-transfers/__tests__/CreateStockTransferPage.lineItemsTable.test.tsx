import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { CreateStockTransferPage } from '../pages/CreateStockTransferPage'

const mockMutateAsync = vi.fn()

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

vi.mock('sonner', () => ({
  toast: {
    error: vi.fn(),
    success: vi.fn(),
  },
}))

vi.mock('@/hooks/useCurrency', () => ({
  getDecimals: () => 2,
  useCurrency: () => ({ currency: 'EUR' }),
}))

vi.mock('@/features/locations/api', () => ({
  fetchLocations: vi.fn().mockResolvedValue([
    { id: 'loc-a', name: 'Main Store' },
    { id: 'loc-b', name: 'Back Room' },
  ]),
  fetchTransactionLocations: vi.fn().mockResolvedValue([
    { id: 'loc-a', name: 'Main Store' },
    { id: 'loc-b', name: 'Back Room' },
  ]),
}))

vi.mock('../api/queries', () => ({
  useCreateStockTransfer: () => ({
    mutateAsync: mockMutateAsync,
    isPending: false,
  }),
}))

vi.mock('@/components/molecules/pickers/ProductPicker', () => ({
  ProductPicker: ({ placeholder }: { placeholder: string }) => (
    <button type="button">{placeholder}</button>
  ),
}))

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  })

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <CreateStockTransferPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('CreateStockTransferPage line table', () => {
  beforeEach(() => {
    mockMutateAsync.mockReset()
  })

  it('renders transfer lines through the shared table with a bottom add-line control', async () => {
    const user = userEvent.setup()

    renderPage()

    expect(screen.getByRole('columnheader', { name: 'create.field.product' })).toBeInTheDocument()
    expect(screen.getByRole('columnheader', { name: 'create.field.quantity' })).toBeInTheDocument()
    expect(screen.getByRole('spinbutton', { name: 'create.field.quantity' })).toHaveAttribute('step', '0.0001')
    expect(screen.getAllByRole('button', { name: 'create.field.selectProduct' })).toHaveLength(1)

    await user.click(screen.getByRole('button', { name: 'create.field.addLine' }))

    expect(screen.getAllByRole('button', { name: 'create.field.selectProduct' })).toHaveLength(2)
  })
})
