import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import { IssueGoodwillVoucherModal } from '../IssueGoodwillVoucherModal'

// ─── i18n mock ────────────────────────────────────────────────────────────────

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

// ─── Router mock ──────────────────────────────────────────────────────────────

vi.mock('react-router-dom', () => ({}))

// ─── TanStack Query mock ──────────────────────────────────────────────────────

const mockMutate = vi.fn()
let mockIsPending = false

vi.mock('@tanstack/react-query', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@tanstack/react-query')>()
  return {
    ...actual,
    useQuery: () => ({
      data: {
        goodwill_four_eyes_threshold: '250.00',
        goodwill_bearer_default_off: true,
        voucher_default_expiry_days: 365,
      },
      isLoading: false,
    }),
    useMutation: () => ({ mutate: mockMutate, isPending: mockIsPending }),
    useQueryClient: () => ({ invalidateQueries: vi.fn() }),
  }
})

// ─── useCompany mock ──────────────────────────────────────────────────────────

vi.mock('@/hooks/useCompany', () => ({
  useCompany: () => ({ currentCompany: { id: 'c1' } }),
}))

// ─── useTerminals mock ────────────────────────────────────────────────────────

vi.mock('@/features/pos/hooks/useTerminals', () => ({
  useTerminals: () => ({
    data: [{ id: 't1', name: 'Terminal 1' }],
  }),
}))

// ─── PartnerPicker mock ───────────────────────────────────────────────────────

vi.mock('@/components/molecules/pickers/PartnerPicker', () => ({
  PartnerPicker: ({
    testId,
    onChange,
  }: {
    testId?: string
    onChange?: (value: { id: string; name: string } | null) => void
  }) => (
    <div
      data-testid={testId ?? 'partner-picker'}
      onClick={() => { onChange?.({ id: 'admin-42', name: 'Second Admin' }) }}
    />
  ),
}))

// ─── api mock (required by getErrorMessage) ───────────────────────────────────

vi.mock('@/lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn() },
  apiGet: vi.fn(),
  apiPost: vi.fn(),
  getErrorMessage: (e: unknown) =>
    e instanceof Error ? e.message : 'An error occurred',
}))

// ─── sonner mock ──────────────────────────────────────────────────────────────

vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }))

// ─── i18n lib mock ────────────────────────────────────────────────────────────

vi.mock('@/lib/i18n', () => ({ default: { t: (key: string) => key } }))

// ─── Tests ────────────────────────────────────────────────────────────────────

describe('IssueGoodwillVoucherModal', () => {
  const onClose = vi.fn()

  beforeEach(() => {
    mockMutate.mockClear()
    mockIsPending = false
    onClose.mockClear()
  })

  it('second-admin field is hidden when amount is below threshold', () => {
    render(<IssueGoodwillVoucherModal isOpen onClose={onClose} />)

    // Amount below 250
    const amountInput = screen.getByPlaceholderText('0.00')
    fireEvent.change(amountInput, { target: { value: '100' } })

    expect(screen.queryByTestId('second-admin-picker')).not.toBeInTheDocument()
  })

  it('second-admin field is shown with info alert when amount meets threshold', async () => {
    render(<IssueGoodwillVoucherModal isOpen onClose={onClose} />)

    const amountInput = screen.getByPlaceholderText('0.00')
    fireEvent.change(amountInput, { target: { value: '250' } })

    await waitFor(() => {
      expect(screen.getByTestId('second-admin-picker')).toBeInTheDocument()
    })
    expect(screen.getByText('vouchers:issueGoodwill.fourEyesInfo')).toBeInTheDocument()
  })

  it('blocks submit above threshold without second admin and shows error', async () => {
    render(<IssueGoodwillVoucherModal isOpen onClose={onClose} />)

    const amountInput = screen.getByPlaceholderText('0.00')
    fireEvent.change(amountInput, { target: { value: '300' } })

    await waitFor(() => {
      expect(screen.getByTestId('second-admin-picker')).toBeInTheDocument()
    })

    // Fill required notes field — textarea is the only textbox that isn't number type
    const textboxes = screen.getAllByRole('textbox')
    const notesTextarea = textboxes[textboxes.length - 1]
    fireEvent.change(notesTextarea, { target: { value: 'Test gesture' } })

    // Select a terminal
    const terminalSelect = screen.getByRole('combobox')
    fireEvent.change(terminalSelect, { target: { value: 't1' } })

    // Submit without setting secondAdmin — PartnerPicker is mocked to render null
    const submitButton = screen.getByRole('button', { name: 'vouchers:issueGoodwill.action' })
    fireEvent.click(submitButton)

    await waitFor(() => {
      expect(screen.getByText('vouchers:errors.FOUR_EYES_REQUIRED')).toBeInTheDocument()
    })

    // Mutation should NOT have been called
    expect(mockMutate).not.toHaveBeenCalled()
  })

  it('422 with FOUR_EYES_REQUIRED renders the friendly translated error message', async () => {
    // Simulate mutation calling onError with FOUR_EYES_REQUIRED
    mockMutate.mockImplementationOnce(
      (_payload: unknown, options: { onError?: (e: unknown) => void }) => {
        options.onError?.({
          response: { data: { error: { code: 'FOUR_EYES_REQUIRED' } } },
        })
      },
    )

    render(<IssueGoodwillVoucherModal isOpen onClose={onClose} />)

    // Amount below threshold so four-eyes guard is skipped in handleSubmit
    const amountInput = screen.getByPlaceholderText('0.00')
    fireEvent.change(amountInput, { target: { value: '50' } })

    // Fill required notes
    const textboxes = screen.getAllByRole('textbox')
    const notesTextarea = textboxes[textboxes.length - 1]
    fireEvent.change(notesTextarea, { target: { value: 'some notes' } })

    // Select a terminal so terminal_id validation passes
    const terminalSelect = screen.getByRole('combobox')
    fireEvent.change(terminalSelect, { target: { value: 't1' } })

    fireEvent.click(screen.getByRole('button', { name: 'vouchers:issueGoodwill.action' }))

    await waitFor(() => {
      expect(mockMutate).toHaveBeenCalled()
    })

    // After mutation error callback fires, domainError should show
    await waitFor(() => {
      expect(screen.getByText('vouchers:errors.FOUR_EYES_REQUIRED')).toBeInTheDocument()
    })
  })

  // ─── Fix I1: NAMED_CUSTOMER_REQUIRED and SELF_DEALING 422 paths ──────────────

  it('renders NAMED_CUSTOMER_REQUIRED error message on 422 with that code', async () => {
    mockMutate.mockImplementationOnce(
      (_payload: unknown, options: { onError?: (e: unknown) => void }) => {
        options.onError?.({
          response: { data: { error: { code: 'NAMED_CUSTOMER_REQUIRED' } } },
        })
      },
    )

    render(<IssueGoodwillVoucherModal isOpen onClose={onClose} />)

    const amountInput = screen.getByPlaceholderText('0.00')
    fireEvent.change(amountInput, { target: { value: '50' } })

    const textboxes = screen.getAllByRole('textbox')
    const notesTextarea = textboxes[textboxes.length - 1]
    fireEvent.change(notesTextarea, { target: { value: 'some notes' } })

    const terminalSelect = screen.getByRole('combobox')
    fireEvent.change(terminalSelect, { target: { value: 't1' } })

    fireEvent.click(screen.getByRole('button', { name: 'vouchers:issueGoodwill.action' }))

    await waitFor(() => {
      expect(mockMutate).toHaveBeenCalled()
    })

    await waitFor(() => {
      expect(screen.getByText('vouchers:errors.NAMED_CUSTOMER_REQUIRED')).toBeInTheDocument()
    })
  })

  it('renders SELF_DEALING error message on 422 with that code', async () => {
    mockMutate.mockImplementationOnce(
      (_payload: unknown, options: { onError?: (e: unknown) => void }) => {
        options.onError?.({
          response: { data: { error: { code: 'SELF_DEALING' } } },
        })
      },
    )

    render(<IssueGoodwillVoucherModal isOpen onClose={onClose} />)

    const amountInput = screen.getByPlaceholderText('0.00')
    fireEvent.change(amountInput, { target: { value: '50' } })

    const textboxes = screen.getAllByRole('textbox')
    const notesTextarea = textboxes[textboxes.length - 1]
    fireEvent.change(notesTextarea, { target: { value: 'some notes' } })

    const terminalSelect = screen.getByRole('combobox')
    fireEvent.change(terminalSelect, { target: { value: 't1' } })

    fireEvent.click(screen.getByRole('button', { name: 'vouchers:issueGoodwill.action' }))

    await waitFor(() => {
      expect(mockMutate).toHaveBeenCalled()
    })

    await waitFor(() => {
      expect(screen.getByText('vouchers:errors.SELF_DEALING')).toBeInTheDocument()
    })
  })

  // ─── Fix B2: secondAdmin cleared when amount drops below threshold ────────────

  it('clears secondAdmin when amount drops below four-eyes threshold', async () => {
    render(<IssueGoodwillVoucherModal isOpen onClose={onClose} />)

    const amountInput = screen.getByPlaceholderText('0.00')

    // Raise amount above threshold to show the four-eyes section
    fireEvent.change(amountInput, { target: { value: '300' } })

    await waitFor(() => {
      expect(screen.getByTestId('second-admin-picker')).toBeInTheDocument()
    })

    // Simulate selecting a second admin via the mocked PartnerPicker onClick
    fireEvent.click(screen.getByTestId('second-admin-picker'))

    // Drop amount below threshold — secondAdmin should be cleared
    fireEvent.change(amountInput, { target: { value: '50' } })

    await waitFor(() => {
      expect(screen.queryByTestId('second-admin-picker')).not.toBeInTheDocument()
    })

    // Fill required fields and submit
    const textboxes = screen.getAllByRole('textbox')
    const notesTextarea = textboxes[textboxes.length - 1]
    fireEvent.change(notesTextarea, { target: { value: 'clearing test' } })

    const terminalSelect = screen.getByRole('combobox')
    fireEvent.change(terminalSelect, { target: { value: 't1' } })

    fireEvent.click(screen.getByRole('button', { name: 'vouchers:issueGoodwill.action' }))

    await waitFor(() => {
      expect(mockMutate).toHaveBeenCalled()
    })

    // The previously selected admin must NOT appear in the payload
    const [payload] = mockMutate.mock.calls[0] as [{ second_admin_user_id: string | null }]
    expect(payload.second_admin_user_id).toBeNull()
  })
})
