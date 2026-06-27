import { describe, it, expect, vi } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { BarcodeHero } from './BarcodeHero'
import type { EnrichmentStatus } from './BarcodeHero'

// i18n is initialised globally in src/test/setup.ts (imports ../lib/i18n)

// ---------------------------------------------------------------------------
// Mock the lookup hook so BarcodeHero tests stay purely visual / callback tests
// and don't need to wire real React-Query + auth stores.
// ---------------------------------------------------------------------------
vi.mock('@/features/inventory/hooks/useCatalogBarcodeLookup', () => ({
  useCatalogBarcodeLookup: vi.fn(() => ({ isSearching: false })),
}))

describe('BarcodeHero', () => {
  it('renders barcode and name inputs', () => {
    render(
      <BarcodeHero
        barcode=""
        onBarcodeChange={vi.fn()}
        name=""
        onNameChange={vi.fn()}
      />,
    )
    // barcode input has a placeholder from i18n (falls back to key in test env)
    const inputs = screen.getAllByRole('textbox')
    expect(inputs).toHaveLength(2)
  })

  it('calls onBarcodeChange when typing into the barcode input', () => {
    const onBarcodeChange = vi.fn()
    render(
      <BarcodeHero
        barcode=""
        onBarcodeChange={onBarcodeChange}
        name=""
        onNameChange={vi.fn()}
      />,
    )
    const inputs = screen.getAllByRole('textbox')
    fireEvent.change(inputs[0], { target: { value: '1234567890123' } })
    expect(onBarcodeChange).toHaveBeenCalledWith('1234567890123')
  })

  it('calls onNameChange when typing into the name input', () => {
    const onNameChange = vi.fn()
    render(
      <BarcodeHero
        barcode=""
        onBarcodeChange={vi.fn()}
        name=""
        onNameChange={onNameChange}
      />,
    )
    const inputs = screen.getAllByRole('textbox')
    fireEvent.change(inputs[1], { target: { value: 'Café Noir' } })
    expect(onNameChange).toHaveBeenCalledWith('Café Noir')
  })

  it('renders "7 fields" text when status is success with fieldsCount 7', () => {
    const status: EnrichmentStatus = { state: 'success', fieldsCount: 7 }
    render(
      <BarcodeHero
        barcode="123"
        onBarcodeChange={vi.fn()}
        name="My Product"
        onNameChange={vi.fn()}
        status={status}
      />,
    )
    expect(screen.getByText(/7 fields/i)).toBeInTheDocument()
  })

  it('renders no status pill when status is idle', () => {
    const status: EnrichmentStatus = { state: 'idle' }
    render(
      <BarcodeHero
        barcode=""
        onBarcodeChange={vi.fn()}
        name=""
        onNameChange={vi.fn()}
        status={status}
      />,
    )
    expect(screen.queryByText(/synerivia/i)).not.toBeInTheDocument()
  })

  it('renders no status pill when status is omitted', () => {
    render(
      <BarcodeHero
        barcode=""
        onBarcodeChange={vi.fn()}
        name=""
        onNameChange={vi.fn()}
      />,
    )
    expect(screen.queryByText(/synerivia/i)).not.toBeInTheDocument()
  })

  it('renders a loading indicator when status is loading', () => {
    const status: EnrichmentStatus = { state: 'loading' }
    render(
      <BarcodeHero
        barcode=""
        onBarcodeChange={vi.fn()}
        name=""
        onNameChange={vi.fn()}
        status={status}
      />,
    )
    // loading state shows "Synerivia…" text
    expect(screen.getByText(/synerivia/i)).toBeInTheDocument()
  })

  it('renders unavailable text when status is error', () => {
    const status: EnrichmentStatus = { state: 'error' }
    render(
      <BarcodeHero
        barcode=""
        onBarcodeChange={vi.fn()}
        name=""
        onNameChange={vi.fn()}
        status={status}
      />,
    )
    expect(screen.getByText(/unavailable/i)).toBeInTheDocument()
  })

  it('renders a refresh button when onManualRefresh is provided', () => {
    render(
      <BarcodeHero
        barcode=""
        onBarcodeChange={vi.fn()}
        name=""
        onNameChange={vi.fn()}
        onManualRefresh={vi.fn()}
      />,
    )
    expect(screen.getByRole('button')).toBeInTheDocument()
  })

  it('calls onManualRefresh when the refresh button is clicked', async () => {
    const onManualRefresh = vi.fn()
    const user = userEvent.setup()
    render(
      <BarcodeHero
        barcode=""
        onBarcodeChange={vi.fn()}
        name=""
        onNameChange={vi.fn()}
        onManualRefresh={onManualRefresh}
      />,
    )
    await user.click(screen.getByRole('button'))
    expect(onManualRefresh).toHaveBeenCalledTimes(1)
  })

  it('does not render a refresh button when onManualRefresh is omitted', () => {
    render(
      <BarcodeHero
        barcode=""
        onBarcodeChange={vi.fn()}
        name=""
        onNameChange={vi.fn()}
      />,
    )
    expect(screen.queryByRole('button')).not.toBeInTheDocument()
  })

  it('inputs carry the atom token class rounded-[var(--radius-input)] and NOT rounded-lg', () => {
    const { container } = render(
      <BarcodeHero
        barcode=""
        onBarcodeChange={vi.fn()}
        name=""
        onNameChange={vi.fn()}
      />,
    )
    const inputs = container.querySelectorAll('input')
    inputs.forEach((input) => {
      expect(input.className).toContain('rounded-[var(--radius-input)]')
      expect(input.className).not.toContain('rounded-lg')
    })
  })

  it('disables both inputs when disabled prop is true', () => {
    render(
      <BarcodeHero
        barcode=""
        onBarcodeChange={vi.fn()}
        name=""
        onNameChange={vi.fn()}
        disabled
      />,
    )
    const inputs = screen.getAllByRole('textbox')
    inputs.forEach((input) => {
      expect(input).toBeDisabled()
    })
  })

  it('disables the refresh button while loading', () => {
    const status: EnrichmentStatus = { state: 'loading' }
    render(
      <BarcodeHero
        barcode=""
        onBarcodeChange={vi.fn()}
        name=""
        onNameChange={vi.fn()}
        status={status}
        onManualRefresh={vi.fn()}
      />,
    )
    expect(screen.getByRole('button')).toBeDisabled()
  })
})

// ---------------------------------------------------------------------------
// BarcodeHero — lookup engine integration (new props)
// ---------------------------------------------------------------------------
describe('BarcodeHero lookup integration', () => {
  it('renders exactly ONE barcode input (no duplicate)', () => {
    render(
      <BarcodeHero
        barcode=""
        onBarcodeChange={vi.fn()}
        name=""
        onNameChange={vi.fn()}
        onProductData={vi.fn()}
        onLookupStateChange={vi.fn()}
      />,
    )
    // There should be exactly 2 textbox inputs: barcode + name (no second barcode)
    const inputs = screen.getAllByRole('textbox')
    expect(inputs).toHaveLength(2)
    // The mono (barcode) input is first
    expect(inputs[0]).toHaveClass('font-mono')
  })

  it('still renders when onProductData and onLookupStateChange are omitted (backward-compatible)', () => {
    render(
      <BarcodeHero
        barcode=""
        onBarcodeChange={vi.fn()}
        name=""
        onNameChange={vi.fn()}
      />,
    )
    const inputs = screen.getAllByRole('textbox')
    expect(inputs).toHaveLength(2)
  })

  it('disables the barcode input while isSearching=true', async () => {
    const mod = await import('@/features/inventory/hooks/useCatalogBarcodeLookup')
    vi.mocked(mod.useCatalogBarcodeLookup).mockReturnValueOnce({ isSearching: true })

    render(
      <BarcodeHero
        barcode="12345678"
        onBarcodeChange={vi.fn()}
        name=""
        onNameChange={vi.fn()}
        onProductData={vi.fn()}
        onLookupStateChange={vi.fn()}
      />,
    )
    const inputs = screen.getAllByRole('textbox')
    // First input (barcode) should be disabled while searching
    expect(inputs[0]).toBeDisabled()
  })
})
