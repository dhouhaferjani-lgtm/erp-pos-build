import { act, renderHook } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

import { useIdempotencyKey } from '../useIdempotencyKey'

const UUID_V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/

describe('useIdempotencyKey', () => {
  it('keeps one UUID until reset', () => {
    const hook = renderHook(() => useIdempotencyKey())
    const first = hook.result.current.key
    hook.rerender()
    expect(hook.result.current.key).toBe(first)
    expect(first).toMatch(UUID_V4)
    act(() => {
      hook.result.current.reset()
    })
    expect(hook.result.current.key).not.toBe(first)
    expect(hook.result.current.key).toMatch(UUID_V4)
  })

  it('gives every mount its own key', () => {
    const first = renderHook(() => useIdempotencyKey())
    const second = renderHook(() => useIdempotencyKey())

    expect(first.result.current.key).toMatch(UUID_V4)
    expect(second.result.current.key).toMatch(UUID_V4)
    expect(second.result.current.key).not.toBe(first.result.current.key)
  })

  it('keeps a stable reset identity across a rerender', () => {
    const hook = renderHook(() => useIdempotencyKey())
    const reset = hook.result.current.reset
    hook.rerender()

    expect(hook.result.current.reset).toBe(reset)
  })
})
