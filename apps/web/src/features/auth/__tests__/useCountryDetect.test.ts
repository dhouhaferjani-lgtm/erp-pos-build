import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { renderHook, waitFor } from '@testing-library/react'
import { useCountryDetect } from '../hooks/useCountryDetect'

describe('useCountryDetect', () => {
  beforeEach(() => { vi.restoreAllMocks() })
  afterEach(() => { vi.restoreAllMocks() })

  it('returns detected country from IP API', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce({
      ok: true,
      text: () => Promise.resolve('FR'),
    } as Response)

    const { result } = renderHook(() => useCountryDetect())
    expect(result.current.isDetecting).toBe(true)

    await waitFor(() => { expect(result.current.isDetecting).toBe(false) })
    expect(result.current.detectedCountry).toBe('FR')
  })

  it('falls back to navigator.language on fetch failure', async () => {
    vi.spyOn(globalThis, 'fetch').mockRejectedValueOnce(new Error('Network'))
    vi.spyOn(navigator, 'language', 'get').mockReturnValue('fr-FR')

    const { result } = renderHook(() => useCountryDetect())
    await waitFor(() => { expect(result.current.isDetecting).toBe(false) })
    expect(result.current.detectedCountry).toBe('FR')
  })

  it('defaults to FR when all detection fails', async () => {
    vi.spyOn(globalThis, 'fetch').mockRejectedValueOnce(new Error('Network'))
    vi.spyOn(navigator, 'language', 'get').mockReturnValue('xx')

    const { result } = renderHook(() => useCountryDetect())
    await waitFor(() => { expect(result.current.isDetecting).toBe(false) })
    expect(result.current.detectedCountry).toBe('FR')
  })
})
