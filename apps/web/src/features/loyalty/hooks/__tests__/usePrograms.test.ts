import { describe, it, expect, vi } from 'vitest'
import { renderHook, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createElement } from 'react'
import { usePrograms, useProgram } from '../usePrograms'

vi.mock('../../api/programApi', () => ({
  listPrograms: vi.fn().mockResolvedValue([
    { id: '1', name: 'Test Program', program_type: 'points', status: 'active' },
  ]),
  getProgram: vi.fn().mockResolvedValue(
    { id: '1', name: 'Test Program', program_type: 'points', status: 'active' },
  ),
  createProgram: vi.fn(),
  updateProgram: vi.fn(),
  deleteProgram: vi.fn(),
  activateProgram: vi.fn(),
  deactivateProgram: vi.fn(),
  listActivePrograms: vi.fn(),
}))

function createWrapper() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return function Wrapper({ children }: { children: React.ReactNode }) {
    return createElement(QueryClientProvider, { client: queryClient }, children)
  }
}

describe('usePrograms', () => {
  it('fetches programs list', async () => {
    const { result } = renderHook(() => usePrograms(), { wrapper: createWrapper() })
    await waitFor(() => expect(result.current.isSuccess).toBe(true))
    expect(result.current.data).toHaveLength(1)
    expect(result.current.data?.[0].name).toBe('Test Program')
  })
})

describe('useProgram', () => {
  it('fetches single program', async () => {
    const { result } = renderHook(() => useProgram('1'), { wrapper: createWrapper() })
    await waitFor(() => expect(result.current.isSuccess).toBe(true))
    expect(result.current.data?.name).toBe('Test Program')
  })

  it('does not fetch when id is empty', () => {
    const { result } = renderHook(() => useProgram(''), { wrapper: createWrapper() })
    expect(result.current.isFetching).toBe(false)
  })
})
