import { describe, it, expect, vi, beforeEach } from 'vitest'
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { CreateStockAdjustmentPage } from '../pages/CreateStockAdjustmentPage'
import type { CreateStockAdjustmentInput } from '../types'

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

// Typed, so the payload assertions below read the real shape instead of casting
// `any` back into one (which is also what keeps this file's lint warnings down).
const createMutate = vi.fn<(input: CreateStockAdjustmentInput) => Promise<{ id: string }>>()
vi.mock('../api/queries', () => ({
  useCreateStockAdjustment: () => ({ mutateAsync: createMutate, isPending: false }),
}))

/** The key the Nth create call carried, or undefined if it never happened. */
function submittedKey(index: number): string | null | undefined {
  return createMutate.mock.calls[index]?.[0].idempotency_key
}


/** An axios-shaped refusal, the only error the page can actually surface. */
function refusalError(code: string): unknown {
  return { response: { data: { error: { code, message: 'refused', details: null } } } }
}

const mockResetIdempotencyKey = vi.hoisted(() => vi.fn())
// How many keys the fake hook below has minted in this test. Reset per test.
const mintedKeys = vi.hoisted(() => ({ count: 0 }))

/**
 * A STATEFUL fake of the real hook, not a frozen literal.
 *
 * A constant key cannot tell "the refused submit kept its key" apart from "the
 * page rotated it", nor one page-scope key apart from one key PER INTENT — the
 * two things these tests exist to pin. Each mounted instance mints its own
 * `adjustment-key-<n>` and rotates it only on its own `reset()`, exactly like
 * `useIdempotencyKey.ts`, with deterministic values instead of UUIDs.
 *
 * Mint order follows the page's hook order: 1 = draft intent, 2 = post intent.
 */
vi.mock('@/hooks/useIdempotencyKey', async () => {
  const { useCallback, useState } = await vi.importActual<typeof import('react')>('react')

  return {
    useIdempotencyKey: (): { key: string; reset: () => void } => {
      const mint = (): string => {
        mintedKeys.count += 1
        return `adjustment-key-${String(mintedKeys.count)}`
      }
      const [key, setKey] = useState(mint)
      const reset = useCallback(() => {
        mockResetIdempotencyKey()
        setKey(mint())
      }, [])

      return { key, reset }
    },
  }
})

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
  vi.clearAllMocks()
  mintedKeys.count = 0
  mockResetIdempotencyKey.mockReset()
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

    const payload = createMutate.mock.calls[0][0]

    // The POST intent's own key (mint #2), not the draft's — see the
    // intent-scoping test below.
    expect(payload.idempotency_key).toBe('adjustment-key-2')
    expect(payload.post_immediately).toBe(true)
    expect(payload.lines[0]?.delta_quantity).toBe('-2.5')
    expect(typeof payload.lines[0]?.delta_quantity).toBe('string')
    expect(payload.lines[0]?.observed_before).toBe('50.0000')
    expect(payload.acknowledge_stale).toBeUndefined()
    expect(payload.ignore_reservations).toBeUndefined()

    // ID-3: the key rotates only after the awaited success resolves.
    await waitFor(() => {
      expect(mockResetIdempotencyKey).toHaveBeenCalledTimes(1)
    })
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
    const payload = createMutate.mock.calls[0][0]
    expect(payload.post_immediately).toBe(false)
    // The DRAFT intent's own key (mint #1).
    expect(payload.idempotency_key).toBe('adjustment-key-1')
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

describe('CreateStockAdjustmentPage — idempotency key lifecycle', () => {
  /**
   * FE gate r1 MAJOR-1 — the invariant ID-3 exists for.
   *
   * The key must SURVIVE a refused submit so the retry is deduplicated
   * server-side, and rotate ONLY after an awaited success. A success-path
   * `toHaveBeenCalledTimes(1)` cannot see `reset()` moving into a `finally`,
   * into the `catch`, or above the `await`; this test can.
   */
  it('keeps the SAME key after a refused submit and rotates it only after a success', async () => {
    const user = userEvent.setup()
    createMutate.mockRejectedValueOnce(refusalError('INVALID_ADJUSTMENT_STATE'))
    render(<CreateStockAdjustmentPage />)
    await addLine(user)

    await user.type(screen.getByLabelText('line.quantity'), '2')
    await user.click(screen.getByRole('button', { name: 'create.post' }))

    // The refusal reaches the operator...
    await waitFor(() => {
      expect(screen.getByText('refusal.INVALID_ADJUSTMENT_STATE')).toBeInTheDocument()
    })
    // ...and the attempt is NOT over, so the key must not have rotated.
    expect(mockResetIdempotencyKey).not.toHaveBeenCalled()
    expect(navigate).not.toHaveBeenCalled()

    await user.click(screen.getByRole('button', { name: 'create.post' }))

    await waitFor(() => {
      expect(createMutate).toHaveBeenCalledTimes(2)
    })
    expect(submittedKey(0)).toBe('adjustment-key-2')
    expect(submittedKey(1)).toBe(submittedKey(0))

    // The retry succeeded: this logical attempt is done, so the key rotates once.
    await waitFor(() => {
      expect(mockResetIdempotencyKey).toHaveBeenCalledTimes(1)
    })

    await user.click(screen.getByRole('button', { name: 'create.post' }))

    await waitFor(() => {
      expect(createMutate).toHaveBeenCalledTimes(3)
    })
    expect(submittedKey(2)).not.toBe(submittedKey(0))
  })

  /**
   * FE gate r1 MAJOR-2 — `disabled={createMutation.isPending}` is async state:
   * it flips on a render that happens AFTER the click handler returns, so two
   * clicks dispatched in one task both get through. The adjustment backend has
   * no collision replay (gate M-6 debt), so the loser is a 500, not a replay —
   * the synchronous ref latch is what stops it being sent at all.
   */
  it('issues exactly ONE create request when Save & post is double-clicked', async () => {
    const user = userEvent.setup()
    // Held open: the first request is still in flight when the second click lands.
    createMutate.mockReturnValue(new Promise<{ id: string }>(() => undefined))
    render(<CreateStockAdjustmentPage />)
    await addLine(user)

    await user.type(screen.getByLabelText('line.quantity'), '2')

    const postButton = screen.getByRole('button', { name: 'create.post' })
    await act(async () => {
      fireEvent.click(postButton)
      fireEvent.click(postButton)
      // Let both submit handlers run up to their awaited request.
      await Promise.resolve()
    })
    // Give a second submit that slipped past the latch every chance to land.
    await act(async () => {
      await Promise.resolve()
    })

    expect(createMutate).toHaveBeenCalledTimes(1)
  })

  /**
   * FE gate r1 MAJOR-3 — save-draft and save-and-post are DIFFERENT intents and
   * must not share one key.
   *
   * The server replays on `(tenant, company, idempotency_key)` ALONE and never
   * compares the body. With one page-scope key: the operator saves a draft, the
   * response is lost (a network error yields no envelope, so nothing is shown),
   * they click "Save & post", the pre-check finds the committed DRAFT and
   * returns it 200 — the page navigates as if it posted, and nothing ever moved.
   */
  it('mints a key PER INTENT so a lost draft response cannot be replayed as a post', async () => {
    const user = userEvent.setup()
    // The draft commits server-side but the response never arrives.
    createMutate.mockRejectedValueOnce(new Error('network'))
    render(<CreateStockAdjustmentPage />)
    await addLine(user)

    await user.type(screen.getByLabelText('line.quantity'), '2')

    await user.click(screen.getByRole('button', { name: 'create.saveDraft' }))
    await waitFor(() => {
      expect(createMutate).toHaveBeenCalledTimes(1)
    })

    await user.click(screen.getByRole('button', { name: 'create.post' }))
    await waitFor(() => {
      expect(createMutate).toHaveBeenCalledTimes(2)
    })

    expect(createMutate.mock.calls[0][0].post_immediately).toBe(false)
    expect(createMutate.mock.calls[1][0].post_immediately).toBe(true)
    expect(submittedKey(1)).not.toBe(submittedKey(0))

    // ...and this is intent SCOPING, not blanket rotation: the draft intent's
    // own key is unchanged, so a genuine draft retry is still deduplicated.
    await user.click(screen.getByRole('button', { name: 'create.saveDraft' }))
    await waitFor(() => {
      expect(createMutate).toHaveBeenCalledTimes(3)
    })
    expect(submittedKey(2)).toBe(submittedKey(0))
  })
})
