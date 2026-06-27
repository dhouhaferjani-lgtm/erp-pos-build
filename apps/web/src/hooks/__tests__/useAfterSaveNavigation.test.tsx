import { describe, it, expect, vi } from 'vitest'
import { renderHook } from '@testing-library/react'
import { useAfterSaveNavigation } from '../useAfterSaveNavigation'

const mockNavigate = vi.fn()
vi.mock('react-router-dom', () => ({ useNavigate: () => mockNavigate }))

describe('useAfterSaveNavigation', () => {
  it('goToRecord navigates to the record detail path', () => {
    const { result } = renderHook(() =>
      useAfterSaveNavigation({ recordPath: (id) => `/inventory/products/${id}`, listPath: '/inventory/products' }),
    )
    result.current.goToRecord('abc')
    expect(mockNavigate).toHaveBeenCalledWith('/inventory/products/abc')
  })

  it('goToList navigates to the list path', () => {
    const { result } = renderHook(() =>
      useAfterSaveNavigation({ recordPath: (id) => `/x/${id}`, listPath: '/x' }),
    )
    result.current.goToList()
    expect(mockNavigate).toHaveBeenCalledWith('/x')
  })

  it('goToNew navigates to createPath when provided', () => {
    const { result } = renderHook(() =>
      useAfterSaveNavigation({ recordPath: (id) => `/x/${id}`, listPath: '/x', createPath: '/x/new' }),
    )
    result.current.goToNew()
    expect(mockNavigate).toHaveBeenCalledWith('/x/new')
  })
})
