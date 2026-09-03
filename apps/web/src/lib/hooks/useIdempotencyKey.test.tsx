import { act, renderHook } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

import { useIdempotencyKey } from './useIdempotencyKey'

describe('useIdempotencyKey', () => {
  it('keeps one UUID until reset', () => {
    const hook = renderHook(() => useIdempotencyKey())
    const first = hook.result.current.key
    hook.rerender()
    expect(hook.result.current.key).toBe(first)
    expect(first).toMatch(/^[0-9a-f-]{36}$/)
    act(() => {
      hook.result.current.reset()
    })
    expect(hook.result.current.key).not.toBe(first)
  })
})
