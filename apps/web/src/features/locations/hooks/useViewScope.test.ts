import { describe, expect, it, vi } from 'vitest'
import { renderHook, act } from '@testing-library/react'

vi.mock('./useScopedLocations', () => ({
  useScopedLocations: () => ({
    data: [
      { id: 'a', name: 'Main', code: 'MAIN', type: 'shop', isDefault: true },
      { id: 'b', name: 'Warehouse', code: 'WH', type: 'warehouse', isDefault: false },
    ],
  }),
}))

vi.mock('@/stores/viewScopeStore', () => {
  const state = { scope: 'all' as 'all' | string[], setScope: vi.fn() }
  const useViewScopeStore = (selector: (value: typeof state) => unknown): unknown => selector(state)
  useViewScopeStore.getState = () => state
  return { useViewScopeStore, __state: state }
})

describe('useViewScope', () => {
  it("resolves 'all' to every scoped location", async () => {
    const { useViewScope } = await import('./useViewScope')
    const { result } = renderHook(() => useViewScope())
    expect(result.current.effectiveLocationIds).toEqual(['a', 'b'])
    expect(result.current.isAll).toBe(true)
  })

  it('keeps an explicit subset and forwards setScope', async () => {
    const store = await import('@/stores/viewScopeStore') as typeof import('@/stores/viewScopeStore') & { __state: { scope: 'all' | string[]; setScope: ReturnType<typeof vi.fn> } }
    store.__state.scope = ['b']
    const { useViewScope } = await import('./useViewScope')
    const { result } = renderHook(() => useViewScope())
    expect(result.current.effectiveLocationIds).toEqual(['b'])
    expect(result.current.isAll).toBe(false)
    act(() => { result.current.setScope('all') })
    expect(store.__state.setScope).toHaveBeenCalledWith('all')
  })

  it('clamps a persisted subset after an admin removes a location', async () => {
    const store = await import('@/stores/viewScopeStore') as typeof import('@/stores/viewScopeStore') & { __state: { scope: 'all' | string[]; setScope: ReturnType<typeof vi.fn> } }
    store.__state.scope = ['removed']
    const { useViewScope } = await import('./useViewScope')
    renderHook(() => useViewScope())
    expect(store.__state.setScope).toHaveBeenCalledWith('all')
  })
})
