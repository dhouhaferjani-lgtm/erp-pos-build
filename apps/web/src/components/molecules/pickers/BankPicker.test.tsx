import { fireEvent, render, screen } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { BankPicker } from './BankPicker'

const mocks = vi.hoisted(() => ({ useBanks: vi.fn() }))

vi.mock('@/hooks/useBanks', () => ({ useBanks: mocks.useBanks }))
vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

const amenBank = {
  id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
  country_code: 'TN',
  name: 'AMEN BANK',
  short_name: 'AMEN',
  bic: 'CFCTTNTT',
  rib_bank_code: '07',
  city: 'TUNIS',
  is_custom: false,
}

function renderPicker(overrides: Partial<React.ComponentProps<typeof BankPicker>> = {}) {
  const props: React.ComponentProps<typeof BankPicker> = {
    id: 'bank-picker',
    country: 'TN',
    value: null,
    onChange: vi.fn(),
    isFallback: false,
    fallbackValue: '',
    onFallbackChange: vi.fn(),
    onFallbackValueChange: vi.fn(),
    ...overrides,
  }

  return { ...render(<BankPicker {...props} />), props }
}

describe('BankPicker', () => {
  beforeEach(() => {
    mocks.useBanks.mockReset()
    mocks.useBanks.mockReturnValue({ data: [amenBank], isLoading: false, isError: false })
  })

  it('toggles between the directory and fallback bank-name input', () => {
    const onFallbackChange = vi.fn()
    const onFallbackValueChange = vi.fn()
    const { rerender } = renderPicker({ onFallbackChange, onFallbackValueChange })

    fireEvent.focus(screen.getByRole('combobox'))
    fireEvent.click(screen.getByRole('button', { name: 'bank.notListed' }))
    expect(onFallbackChange).toHaveBeenCalledWith(true)

    rerender(
      <BankPicker
        id="bank-picker"
        country="TN"
        value={null}
        onChange={vi.fn()}
        isFallback
        fallbackValue="Legacy bank"
        onFallbackChange={onFallbackChange}
        onFallbackValueChange={onFallbackValueChange}
      />,
    )
    fireEvent.click(screen.getByRole('button', { name: 'bank.chooseDirectory' }))
    expect(onFallbackChange).toHaveBeenLastCalledWith(false)
    expect(onFallbackValueChange).toHaveBeenCalledWith('')
  })

  it('tracks the active option and selects it with the keyboard', () => {
    const onChange = vi.fn()
    renderPicker({ onChange })

    const input = screen.getByRole('combobox')
    fireEvent.focus(input)
    fireEvent.keyDown(input, { key: 'ArrowDown' })

    const option = screen.getByRole('option', { name: /AMEN BANK/ })
    expect(option).toHaveAttribute('id', 'bank-picker-option-aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')
    expect(input).toHaveAttribute('aria-activedescendant', option.id)

    fireEvent.keyDown(input, { key: 'Enter' })
    expect(onChange).toHaveBeenCalledWith(amenBank)
  })

  it('renders empty and error states', () => {
    mocks.useBanks.mockReturnValue({ data: [], isLoading: false, isError: false })
    const { unmount } = renderPicker()
    fireEvent.focus(screen.getByRole('combobox'))
    expect(screen.getByText('bank.empty')).toBeInTheDocument()
    unmount()

    mocks.useBanks.mockReturnValue({ data: undefined, isLoading: false, isError: true })
    renderPicker()
    fireEvent.focus(screen.getByRole('combobox'))
    expect(screen.getByText('common.error')).toBeInTheDocument()
  })
})
