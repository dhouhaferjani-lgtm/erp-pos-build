import { describe, it, expect, vi, beforeEach } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { renderWithProviders } from '@/test/renderWithProviders'
import { UserPicker } from '../UserPicker'

// ─── API mock ─────────────────────────────────────────────────────────────────

const mockApiGet = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: { get: mockApiGet },
  }
})

// ─── i18n mock ────────────────────────────────────────────────────────────────

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

// ─── Helpers ──────────────────────────────────────────────────────────────────

function response<T>(data: T) {
  return {
    data: {
      data,
      meta: { total: Array.isArray(data) ? (data as unknown[]).length : 0, current_page: 1, per_page: 20, last_page: 1 },
    },
  }
}

const alice = {
  id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
  name: 'Alice Admin',
  email: 'alice@example.com',
}

const bob = {
  id: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
  name: 'Bob Cashier',
  email: 'bob@example.com',
}

describe('UserPicker', () => {
  const onChange = vi.fn()

  beforeEach(() => {
    mockApiGet.mockReset()
    onChange.mockReset()
  })

  it('renders the search combobox', () => {
    renderWithProviders(<UserPicker value={null} onChange={onChange} />)
    expect(screen.getByRole('combobox')).toBeInTheDocument()
  })

  it('shows a cleared state with the user name when a value is selected', () => {
    renderWithProviders(
      <UserPicker value={alice.id} selectedLabel={alice.name} onChange={onChange} />,
    )
    expect(screen.getByText('Alice Admin')).toBeInTheDocument()
    expect(screen.queryByRole('combobox')).not.toBeInTheDocument()
  })

  it('calls onChange(null) when the clear button is clicked', async () => {
    const user = userEvent.setup()
    renderWithProviders(
      <UserPicker value={alice.id} selectedLabel={alice.name} onChange={onChange} />,
    )
    await user.click(screen.getByRole('button', { name: /common\.clear/i }))
    expect(onChange).toHaveBeenCalledWith(null)
  })

  it('debounces the search and issues a request after typing', async () => {
    mockApiGet.mockResolvedValue(response([alice, bob]))
    const user = userEvent.setup()
    renderWithProviders(<UserPicker value={null} onChange={onChange} />)

    const combo = screen.getByRole('combobox')
    await user.click(combo)
    await user.type(combo, 'Ali')

    await waitFor(() => {
      expect(mockApiGet).toHaveBeenCalled()
    })
    const [url] = mockApiGet.mock.calls[mockApiGet.mock.calls.length - 1] as [string]
    expect(url).toContain('/users')
    expect(url).toContain('search=Ali')
  })

  it('applies roleFilter as a query param when provided', async () => {
    mockApiGet.mockResolvedValue(response([alice]))
    const user = userEvent.setup()
    renderWithProviders(<UserPicker value={null} onChange={onChange} roleFilter="admin" />)

    const combo = screen.getByRole('combobox')
    await user.click(combo)
    await user.type(combo, 'Ali')

    await waitFor(() => {
      expect(mockApiGet).toHaveBeenCalled()
    })
    const [url] = mockApiGet.mock.calls[mockApiGet.mock.calls.length - 1] as [string]
    expect(url).toContain('role=admin')
  })

  it('renders dropdown results with name and email', async () => {
    mockApiGet.mockResolvedValue(response([alice, bob]))
    const user = userEvent.setup()
    renderWithProviders(<UserPicker value={null} onChange={onChange} />)

    const combo = screen.getByRole('combobox')
    await user.click(combo)
    await user.type(combo, 'Ali')

    await waitFor(() => {
      expect(screen.getByText('Alice Admin')).toBeInTheDocument()
    })
    expect(screen.getByText('alice@example.com')).toBeInTheDocument()
  })

  it('calls onChange with the user UUID when a result is clicked', async () => {
    mockApiGet.mockResolvedValue(response([alice]))
    const user = userEvent.setup()
    renderWithProviders(<UserPicker value={null} onChange={onChange} />)

    const combo = screen.getByRole('combobox')
    await user.click(combo)
    await user.type(combo, 'Ali')

    await waitFor(() => {
      expect(screen.getByText('Alice Admin')).toBeInTheDocument()
    })

    await user.click(screen.getByText('Alice Admin'))
    expect(onChange).toHaveBeenCalledWith(alice.id, 'Alice Admin')
  })

  it('shows empty state message when no results are found', async () => {
    mockApiGet.mockResolvedValue(response([]))
    const user = userEvent.setup()
    renderWithProviders(<UserPicker value={null} onChange={onChange} />)

    const combo = screen.getByRole('combobox')
    await user.click(combo)
    await user.type(combo, 'xyz')

    await waitFor(() => {
      expect(screen.getByText('user.empty')).toBeInTheDocument()
    })
  })

  it('shows min-characters hint before typing 2 characters', async () => {
    const user = userEvent.setup()
    renderWithProviders(<UserPicker value={null} onChange={onChange} />)

    const combo = screen.getByRole('combobox')
    await user.click(combo)
    await user.type(combo, 'A')

    // 1 character typed — should show min-char hint, not fire a query
    expect(mockApiGet).not.toHaveBeenCalled()
    expect(screen.getByText('common.minCharacters')).toBeInTheDocument()
  })

  it('does not fire query when disabled', async () => {
    const user = userEvent.setup()
    renderWithProviders(<UserPicker value={null} onChange={onChange} disabled />)

    const combo = screen.queryByRole('combobox')
    if (combo) {
      await user.type(combo, 'Ali')
    }
    expect(mockApiGet).not.toHaveBeenCalled()
  })
})
