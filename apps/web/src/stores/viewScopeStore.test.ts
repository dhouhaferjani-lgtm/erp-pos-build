import { beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * Load a fresh store and company-store module so the module-scope wiring is
 * reinstalled for each test. The company id is set before importing the scope
 * store, matching the first-load hydration path used by the application.
 */
async function freshStore(companyId = 'c1') {
  vi.resetModules()

  const companyModule = await import('./companyStore')
  companyModule.useCompanyStore.setState({
    currentCompanyId: companyId,
    companies: [],
    isLoading: false,
  })

  const scopeModule = await import('./viewScopeStore')
  return { ...scopeModule, companyStore: companyModule.useCompanyStore }
}

describe('viewScopeStore', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('persists scope keyed by company id', async () => {
    const { useViewScopeStore } = await freshStore('c1')

    useViewScopeStore.getState().setScope(['loc-a'])

    const persisted = localStorage.getItem('autoerp-view-scope:c1')
    expect(persisted === null ? null : JSON.parse(persisted)).toEqual(['loc-a'])
  })

  it('resets to all on a real company change and never rehydrates A under B', async () => {
    localStorage.setItem('autoerp-view-scope:c1', JSON.stringify(['loc-a']))
    localStorage.setItem('autoerp-view-scope:c2', JSON.stringify(['loc-b']))

    const { useViewScopeStore, companyStore } = await freshStore('c1')
    expect(useViewScopeStore.getState().scope).toEqual(['loc-a'])

    companyStore.setState({ currentCompanyId: 'c2' })

    expect(useViewScopeStore.getState().scope).toBe('all')
    expect(localStorage.getItem('autoerp-view-scope:c1')).toBe(JSON.stringify(['loc-a']))
  })

  it('cross-tab storage event adopts a valid payload', async () => {
    const { useViewScopeStore } = await freshStore('c1')

    window.dispatchEvent(new StorageEvent('storage', {
      key: 'autoerp-view-scope:c1',
      newValue: JSON.stringify(['loc-b']),
    }))

    expect(useViewScopeStore.getState().scope).toEqual(['loc-b'])
  })

  it('cross-tab malformed payload is ignored and preserves the current scope', async () => {
    const { useViewScopeStore } = await freshStore('c1')
    useViewScopeStore.getState().setScope(['loc-b'])

    window.dispatchEvent(new StorageEvent('storage', {
      key: 'autoerp-view-scope:c1',
      newValue: '{not json',
    }))

    expect(useViewScopeStore.getState().scope).toEqual(['loc-b'])
  })
})
