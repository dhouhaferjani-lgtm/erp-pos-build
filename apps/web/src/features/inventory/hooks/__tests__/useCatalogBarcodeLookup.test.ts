import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { renderHook, act } from '@testing-library/react'
import type { LookupState, SuggestedProduct } from '../../types/platform'

// ---------------------------------------------------------------------------
// Module mocks — must be hoisted above the import under test.
// ---------------------------------------------------------------------------

const mockUseCatalogLookup = vi.fn()
const mockUseBarcodeScanner = vi.fn()

vi.mock('../../api/platformQueries', () => ({
  useCatalogLookup: (barcode: string | null) => mockUseCatalogLookup(barcode),
}))

vi.mock('@/hooks/useBarcodeScanner', () => ({
  useBarcodeScanner: (opts: { onScan: (code: string) => void }) => mockUseBarcodeScanner(opts),
}))

// Import AFTER mocks are set up.
import { useCatalogBarcodeLookup } from '../useCatalogBarcodeLookup'

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeLookupResult(
  overrides: Partial<ReturnType<typeof defaultLookupResult>> = {},
): ReturnType<typeof defaultLookupResult> {
  return { ...defaultLookupResult(), ...overrides }
}

function defaultLookupResult() {
  return {
    data: undefined as
      | { status: 'found' | 'not_found' | 'error'; suggestedProduct: SuggestedProduct | null }
      | undefined,
    isLoading: false,
    isError: false,
  }
}

const suggestedProduct: SuggestedProduct = {
  name: 'Test Product',
  barcode: '12345678',
  brand: 'TestBrand',
  description: 'A test product',
  platform_product_id: 'prod-1',
  classification: {},
  ingredients: [],
  images: [],
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe('useCatalogBarcodeLookup', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    mockUseCatalogLookup.mockReturnValue(defaultLookupResult())
    mockUseBarcodeScanner.mockImplementation(() => undefined)
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.clearAllMocks()
  })

  it('returns isSearching=false when idle', () => {
    const { result } = renderHook(() =>
      useCatalogBarcodeLookup({
        barcode: '',
        onProductData: vi.fn(),
        onLookupStateChange: vi.fn(),
        onScan: vi.fn(),
      }),
    )
    expect(result.current.isSearching).toBe(false)
  })

  it('does NOT trigger lookup for barcodes shorter than 8 chars', () => {
    const onLookupStateChange = vi.fn()
    renderHook(() =>
      useCatalogBarcodeLookup({
        barcode: '1234567', // 7 chars
        onProductData: vi.fn(),
        onLookupStateChange,
        onScan: vi.fn(),
      }),
    )
    // Advance timers past debounce
    act(() => { vi.advanceTimersByTime(400) })
    // useCatalogLookup should have been called with null (no lookup)
    expect(mockUseCatalogLookup).toHaveBeenCalledWith(null)
  })

  it('debounces lookup: fires useCatalogLookup with barcode after 300ms for ≥8 chars', () => {
    // We need to track what lookup barcode is passed after the debounce.
    // Re-render with different barcodes is managed in the hook via useEffect.
    mockUseCatalogLookup.mockReturnValue(defaultLookupResult())

    const { rerender } = renderHook(
      ({ barcode }: { barcode: string }) =>
        useCatalogBarcodeLookup({
          barcode,
          onProductData: vi.fn(),
          onLookupStateChange: vi.fn(),
          onScan: vi.fn(),
        }),
      { initialProps: { barcode: '12345678' } },
    )

    // Before debounce fires: should call with null
    expect(mockUseCatalogLookup).toHaveBeenCalledWith(null)

    // After debounce fires (300ms)
    act(() => { vi.advanceTimersByTime(300) })

    // After debounce it should be called with the barcode
    expect(mockUseCatalogLookup).toHaveBeenCalledWith('12345678')

    // Changing to a new value resets the debounce
    rerender({ barcode: '99999999' })
    // Not yet fired — still debouncing
    expect(mockUseCatalogLookup).not.toHaveBeenCalledWith('99999999')
    act(() => { vi.advanceTimersByTime(300) })
    expect(mockUseCatalogLookup).toHaveBeenCalledWith('99999999')
  })

  it('calls onLookupStateChange(searching) while isLoading', () => {
    mockUseCatalogLookup.mockReturnValue(makeLookupResult({ isLoading: true }))
    const onLookupStateChange = vi.fn()

    renderHook(() =>
      useCatalogBarcodeLookup({
        barcode: '12345678',
        onProductData: vi.fn(),
        onLookupStateChange,
        onScan: vi.fn(),
      }),
    )
    act(() => { vi.advanceTimersByTime(300) })

    expect(onLookupStateChange).toHaveBeenCalledWith('searching' satisfies LookupState)
  })

  it('calls onProductData and onLookupStateChange(found) when data.status===found', () => {
    const onProductData = vi.fn()
    const onLookupStateChange = vi.fn()

    mockUseCatalogLookup.mockReturnValue(
      makeLookupResult({
        data: { status: 'found', suggestedProduct },
      }),
    )

    renderHook(() =>
      useCatalogBarcodeLookup({
        barcode: '12345678',
        onProductData,
        onLookupStateChange,
        onScan: vi.fn(),
      }),
    )
    act(() => { vi.advanceTimersByTime(300) })

    expect(onLookupStateChange).toHaveBeenCalledWith('found' satisfies LookupState)
    expect(onProductData).toHaveBeenCalledWith(suggestedProduct)
  })

  it('calls onLookupStateChange(not_found) when data.status===not_found', () => {
    mockUseCatalogLookup.mockReturnValue(
      makeLookupResult({
        data: { status: 'not_found', suggestedProduct: null },
      }),
    )
    const onLookupStateChange = vi.fn()

    renderHook(() =>
      useCatalogBarcodeLookup({
        barcode: '12345678',
        onProductData: vi.fn(),
        onLookupStateChange,
        onScan: vi.fn(),
      }),
    )
    act(() => { vi.advanceTimersByTime(300) })

    expect(onLookupStateChange).toHaveBeenCalledWith('not_found' satisfies LookupState)
  })

  it('calls onLookupStateChange(error) when isError', () => {
    mockUseCatalogLookup.mockReturnValue(makeLookupResult({ isError: true }))
    const onLookupStateChange = vi.fn()

    renderHook(() =>
      useCatalogBarcodeLookup({
        barcode: '12345678',
        onProductData: vi.fn(),
        onLookupStateChange,
        onScan: vi.fn(),
      }),
    )
    act(() => { vi.advanceTimersByTime(300) })

    expect(onLookupStateChange).toHaveBeenCalledWith('error' satisfies LookupState)
  })

  it('does NOT call onLookupStateChange/onProductData again for the same state (de-dupe)', () => {
    const onLookupStateChange = vi.fn()
    const onProductData = vi.fn()

    mockUseCatalogLookup.mockReturnValue(
      makeLookupResult({
        data: { status: 'found', suggestedProduct },
      }),
    )

    const { rerender } = renderHook(() =>
      useCatalogBarcodeLookup({
        barcode: '12345678',
        onProductData,
        onLookupStateChange,
        onScan: vi.fn(),
      }),
    )
    act(() => { vi.advanceTimersByTime(300) })

    const callsBefore = onLookupStateChange.mock.calls.length
    rerender()
    // State hasn't changed — should NOT fire again
    expect(onLookupStateChange.mock.calls.length).toBe(callsBefore)
  })

  it('wires useBarcodeScanner with the onScan callback', () => {
    const onScan = vi.fn()
    renderHook(() =>
      useCatalogBarcodeLookup({
        barcode: '',
        onProductData: vi.fn(),
        onLookupStateChange: vi.fn(),
        onScan,
      }),
    )
    // Verify useBarcodeScanner was called with an object containing an onScan function
    expect(mockUseBarcodeScanner).toHaveBeenCalledWith(
      expect.objectContaining({ onScan: expect.any(Function) as unknown }),
    )

    // Simulate a scan via the registered callback
    const registeredOnScan = (mockUseBarcodeScanner.mock.calls[0] as [{ onScan: (c: string) => void }])[0].onScan
    registeredOnScan('SCANNED123')
    expect(onScan).toHaveBeenCalledWith('SCANNED123')
  })

  it('returns isSearching=true while lookup is in flight', () => {
    mockUseCatalogLookup.mockReturnValue(makeLookupResult({ isLoading: true }))
    const { result } = renderHook(() =>
      useCatalogBarcodeLookup({
        barcode: '12345678',
        onProductData: vi.fn(),
        onLookupStateChange: vi.fn(),
        onScan: vi.fn(),
      }),
    )
    act(() => { vi.advanceTimersByTime(300) })
    expect(result.current.isSearching).toBe(true)
  })
})
