import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { CreateStockAdjustmentPage } from '../pages/CreateStockAdjustmentPage'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, second?: unknown) => (typeof second === 'string' ? second : key),
  }),
}))

const navigate = vi.fn()
vi.mock('react-router-dom', () => ({ useNavigate: () => navigate }))

const grantedPermissions = new Set<string>(['inventory.adjustments.post'])
vi.mock('@/hooks/usePermissions', () => ({
  usePermissions: () => ({ hasPermission: (p: string) => grantedPermissions.has(p) }),
}))

vi.mock('../components/LotSelect', () => ({
  LotSelect: ({ value, onChange }: { value: string; onChange: (v: string) => void }) => (
    <select
      aria-label="line.lot"
      value={value}
      onChange={(event) => {
        onChange(event.target.value)
      }}
    >
      <option value="">—</option>
      <option value="lot-1">LOT-1</option>
    </select>
  ),
}))

vi.mock('@tanstack/react-query', () => ({
  useQuery: () => ({ data: [{ id: 'loc-1', name: 'Main' }] }),
}))

/**
 * The page now uses the HOUSE ProductPicker (a searchable combobox), not a
 * parallel <Select> over a truncated product list. Stubbed to a one-click choice
 * so these tests stay about the page's own logic; the picker has its own tests.
 */
vi.mock('@/components/molecules/pickers/ProductPicker', () => ({
  ProductPicker: ({
    onChange,
    disabled,
  }: {
    onChange: (v: { id: string; sku: string; name: string } | null) => void
    disabled?: boolean
  }) => (
    <button
      type="button"
      disabled={disabled}
      onClick={() => {
        onChange({ id: 'prod-1', sku: 'W-1', name: 'Widget' })
      }}
    >
      line.product
    </button>
  ),
}))

const createMutate = vi.fn()
vi.mock('../api/queries', () => ({
  useCreateStockAdjustment: () => ({ mutateAsync: createMutate, isPending: false }),
}))

const stockLevel = vi.fn<(productId: string, locationId: string) => Promise<unknown>>()
vi.mock('../api/stockAdjustmentApi', () => ({
  stockAdjustmentApi: {
    stockLevel: (productId: string, locationId: string) => stockLevel(productId, locationId),
  },
}))

const freshLevel = {
  quantity: '50.0000',
  quantity_decimals: 3,
  requires_batch_tracking: false,
  has_lots_at_location: false,
}

beforeEach(() => {
  createMutate.mockReset()
  createMutate.mockResolvedValue({ id: 'adj-1' })
  stockLevel.mockReset()
  stockLevel.mockResolvedValue(freshLevel)
  navigate.mockReset()
  grantedPermissions.clear()
  grantedPermissions.add('inventory.adjustments.post')
})

async function pickProduct(user: ReturnType<typeof userEvent.setup>): Promise<void> {
  await user.selectOptions(screen.getByLabelText('create.locationLabel'), 'loc-1')
  await user.click(screen.getByRole('button', { name: 'line.product' }))
}

async function addLine(user: ReturnType<typeof userEvent.setup>): Promise<void> {
  await pickProduct(user)
  await user.click(screen.getByRole('button', { name: /create.addLine/ }))
  await waitFor(() => {
    expect(screen.getByLabelText('line.quantity')).toBeInTheDocument()
  })
}

describe('CreateStockAdjustmentPage — the fresh-read preflight', () => {
  it('authors observed_before from the FRESH read, not a cached list', async () => {
    const user = userEvent.setup()
    render(<CreateStockAdjustmentPage />)
    await addLine(user)

    expect(stockLevel).toHaveBeenCalledWith('prod-1', 'loc-1')
    // The unit's precision (3 dp), not the storage scale.
    expect(screen.getAllByText(/50\.000/).length).toBeGreaterThan(0)
  })

  it('surfaces a failed preflight instead of silently dropping the line', async () => {
    const user = userEvent.setup()
    stockLevel.mockRejectedValue(new Error('404'))
    render(<CreateStockAdjustmentPage />)

    await pickProduct(user)
    await user.click(screen.getByRole('button', { name: /create.addLine/ }))

    // The endpoint firstOrFail()s, so a product with no stock row here 404s.
    // Unhandled, the line simply never appeared — no message at all.
    await waitFor(() => {
      expect(screen.getByText('create.loadFailed')).toBeInTheDocument()
    })
  })

  it('refuses a duplicate line with its OWN message', async () => {
    const user = userEvent.setup()
    render(<CreateStockAdjustmentPage />)
    await addLine(user)

    await user.click(screen.getByRole('button', { name: 'line.product' }))
    await user.click(screen.getByRole('button', { name: /create.addLine/ }))

    await waitFor(() => {
      expect(screen.getByText('create.duplicateLine')).toBeInTheDocument()
    })
  })
})

describe('CreateStockAdjustmentPage — validation', () => {
  it('refuses a zero magnitude inline, mirroring the server not_in:0', async () => {
    const user = userEvent.setup()
    render(<CreateStockAdjustmentPage />)
    await addLine(user)

    await user.type(screen.getByLabelText('line.quantity'), '0')
    await user.click(screen.getByRole('button', { name: 'create.post' }))

    await waitFor(() => {
      expect(screen.getByText('validation.nonZero')).toBeInTheDocument()
    })
    expect(createMutate).not.toHaveBeenCalled()
  })

  it('refuses more than four decimal places', async () => {
    const user = userEvent.setup()
    render(<CreateStockAdjustmentPage />)
    await addLine(user)

    await user.type(screen.getByLabelText('line.quantity'), '1.23456')
    await user.click(screen.getByRole('button', { name: 'create.post' }))

    await waitFor(() => {
      expect(screen.getByText('validation.quantity')).toBeInTheDocument()
    })
    expect(createMutate).not.toHaveBeenCalled()
  })

  it('requires a lot on a NEGATIVE line when one holds stock here', async () => {
    const user = userEvent.setup()
    stockLevel.mockResolvedValue({
      ...freshLevel,
      requires_batch_tracking: true,
      has_lots_at_location: true,
    })
    render(<CreateStockAdjustmentPage />)
    await addLine(user)

    await user.selectOptions(screen.getByLabelText('line.reason'), 'adjustment_negative')
    await user.type(screen.getByLabelText('line.quantity'), '2')
    await user.click(screen.getByRole('button', { name: 'create.post' }))

    await waitFor(() => {
      expect(screen.getByText('validation.lotRequired')).toBeInTheDocument()
    })
    expect(createMutate).not.toHaveBeenCalled()
  })

  it('drops damage and write_off from a lot-tracked line', async () => {
    const user = userEvent.setup()
    stockLevel.mockResolvedValue({ ...freshLevel, requires_batch_tracking: true })
    render(<CreateStockAdjustmentPage />)
    await addLine(user)

    const options = Array.from(
      screen.getByLabelText('line.reason').querySelectorAll('option'),
    ).map((option) => option.getAttribute('value'))

    expect(options).toEqual(['adjustment_positive', 'adjustment_negative'])
  })
})

describe('CreateStockAdjustmentPage — payload', () => {
  it('sends a SIGNED string delta and the fresh anchor, and posts immediately', async () => {
    const user = userEvent.setup()
    render(<CreateStockAdjustmentPage />)
    await addLine(user)

    await user.selectOptions(screen.getByLabelText('line.reason'), 'adjustment_negative')
    await user.type(screen.getByLabelText('line.quantity'), '2.5')
    await user.click(screen.getByRole('button', { name: 'create.post' }))

    await waitFor(() => {
      expect(createMutate).toHaveBeenCalledTimes(1)
    })

    const payload = createMutate.mock.calls[0]?.[0] as {
      post_immediately: boolean
      acknowledge_stale?: boolean
      ignore_reservations?: boolean
      lines: { delta_quantity: string; observed_before: string }[]
    }

    expect(payload.post_immediately).toBe(true)
    expect(payload.lines[0]?.delta_quantity).toBe('-2.5')
    expect(typeof payload.lines[0]?.delta_quantity).toBe('string')
    expect(payload.lines[0]?.observed_before).toBe('50.0000')
    expect(payload.acknowledge_stale).toBeUndefined()
    expect(payload.ignore_reservations).toBeUndefined()
  })

  it('saves a DRAFT without posting', async () => {
    const user = userEvent.setup()
    render(<CreateStockAdjustmentPage />)
    await addLine(user)

    await user.type(screen.getByLabelText('line.quantity'), '3')
    await user.click(screen.getByRole('button', { name: 'create.saveDraft' }))

    await waitFor(() => {
      expect(createMutate).toHaveBeenCalledTimes(1)
    })
    const payload = createMutate.mock.calls[0]?.[0] as { post_immediately: boolean }
    expect(payload.post_immediately).toBe(false)
    expect(navigate).toHaveBeenCalledWith('/inventory/stock-adjustments/adj-1')
  })

  it('hides Post entirely without the posting permission', async () => {
    const user = userEvent.setup()
    grantedPermissions.clear()
    render(<CreateStockAdjustmentPage />)
    await addLine(user)

    expect(screen.queryByRole('button', { name: 'create.post' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'create.saveDraft' })).toBeInTheDocument()
  })
})
