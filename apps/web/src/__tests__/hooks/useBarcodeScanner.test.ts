import { describe, it, expect, vi } from 'vitest'
import { renderHook } from '@testing-library/react'
import { useBarcodeScanner } from '@/hooks/useBarcodeScanner'

describe('useBarcodeScanner', () => {
  it('accepts onScan callback', () => {
    const onScan = vi.fn()
    const { result } = renderHook(() => useBarcodeScanner({ onScan }))
    // Hook returns void, just verify it mounts without error
    expect(result.current).toBeUndefined()
  })

  it('does not fire when disabled', () => {
    const onScan = vi.fn()
    renderHook(() => useBarcodeScanner({ onScan, enabled: false }))
    // Simulate keydown — should not trigger since disabled
    const event = new KeyboardEvent('keydown', { key: 'Enter' })
    window.dispatchEvent(event)
    expect(onScan).not.toHaveBeenCalled()
  })
})
