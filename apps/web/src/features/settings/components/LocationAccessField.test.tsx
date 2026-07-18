import { fireEvent, render, screen } from '@testing-library/react'
import { useState } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { describe, expect, it, vi } from 'vitest'
import { LocationAccessField } from './LocationAccessField'

const useManagementLocations = vi.hoisted(() => vi.fn())

vi.mock('@/features/locations/hooks/useManagementLocations', () => ({
  useManagementLocations,
}))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

function renderField(value: string[] | null, onChange = vi.fn(), props: Record<string, unknown> = {}) {
  useManagementLocations.mockReturnValue({
    data: [
      { id: 'loc-b', name: 'Branch', code: 'BR', type: 'shop', isDefault: false },
      { id: 'loc-a', name: 'Main', code: 'HQ', type: 'shop', isDefault: true },
    ],
    isLoading: false,
  })
  const queryClient = new QueryClient()
  function Harness() {
    const [currentValue, setCurrentValue] = useState(value)
    return <LocationAccessField value={currentValue} onChange={(next) => { onChange(next); setCurrentValue(next) }} {...props} />
  }
  return {
    onChange,
    ...render(
      <QueryClientProvider client={queryClient}>
        <Harness />
      </QueryClientProvider>,
    ),
  }
}

describe('LocationAccessField', () => {
  it('renders all and subset options and emits a sorted subset', () => {
    const { onChange } = renderField([])

    expect(screen.getByRole('radio', { name: /locations:staffAccess\.allLocations/ })).toBeInTheDocument()
    expect(screen.getByText('Main')).toBeInTheDocument()
    expect(screen.getByText('Branch')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('checkbox', { name: /Branch/ }))
    fireEvent.click(screen.getByRole('checkbox', { name: /Main/ }))

    expect(onChange).toHaveBeenLastCalledWith(['loc-a', 'loc-b'])
  })

  it('emits null when all locations is selected', () => {
    const onChange = vi.fn()
    renderField(['loc-a'], onChange)
    fireEvent.click(screen.getAllByRole('radio', { name: /locations:staffAccess\.allLocations/ }).at(-1)!)
    expect(onChange).toHaveBeenCalledWith(null)
  })

  it('blocks changes when disabled or read-only', () => {
    const onChange = vi.fn()
    renderField(['loc-a'], onChange, { disabled: true })
    fireEvent.click(screen.getAllByRole('radio', { name: /locations:staffAccess\.allLocations/ })[0])
    expect(onChange).not.toHaveBeenCalled()

    onChange.mockClear()
    renderField(['loc-a'], onChange, { readOnly: true })
    fireEvent.click(screen.getAllByRole('radio', { name: /locations:staffAccess\.allLocations/ }).at(-1)!)
    expect(onChange).not.toHaveBeenCalled()
  })
})
