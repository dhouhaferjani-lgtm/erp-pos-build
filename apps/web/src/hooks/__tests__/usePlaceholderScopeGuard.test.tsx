import { act, cleanup, renderHook } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'

import { useCompanyStore } from '../../stores/companyStore'
import { resetAuth, seedAuth } from '../../test/seedAuth'
import { usePlaceholderScopeGuard } from '../usePlaceholderScopeGuard'

interface GuardProps {
  isPlaceholderData: boolean
  hasData: boolean
  additionalScope?: readonly unknown[]
}

function renderGuard(initialProps: GuardProps) {
  return renderHook(
    ({ isPlaceholderData, hasData, additionalScope }: GuardProps) =>
      usePlaceholderScopeGuard(isPlaceholderData, hasData, additionalScope),
    { initialProps },
  )
}

/**
 * Switch company and deliver the query's next result in ONE commit.
 *
 * That is how the real thing behaves and the guard depends on it: TanStack takes
 * the placeholder branch only when `data === undefined` for the current key
 * (`queryObserver.js:266`), so the render in which `tenantScopedKey` stamps the
 * new company is already the render that reports `isPlaceholderData`. Updating
 * the store and the props in separate commits would feed the guard a state that
 * cannot occur — settled data sitting under a scope it was never fetched for.
 */
function switchCompanyAnd(
  companyId: string,
  rerender: (props: GuardProps) => void,
  next: GuardProps,
): void {
  act(() => {
    useCompanyStore.setState({ currentCompanyId: companyId })
    rerender(next)
  })
}

/** The one transition every case needs: settle data, then offer a placeholder. */
function settled(): GuardProps {
  return { isPlaceholderData: false, hasData: true }
}

function placeholder(additionalScope?: readonly unknown[]): GuardProps {
  return additionalScope === undefined
    ? { isPlaceholderData: true, hasData: true }
    : { isPlaceholderData: true, hasData: true, additionalScope }
}

describe('usePlaceholderScopeGuard', () => {
  beforeEach(() => {
    seedAuth({ tenantId: 'tenant-1', companyId: 'company-1' })
  })

  afterEach(() => {
    cleanup()
    resetAuth()
  })

  it('passes a placeholder through when the scope has not changed (the paging case)', () => {
    const { result, rerender } = renderGuard(settled())
    expect(result.current).toBe(false)

    rerender(placeholder())

    expect(result.current).toBe(false)
  })

  it('flags a placeholder that predates a company change', () => {
    const { result, rerender } = renderGuard(settled())

    switchCompanyAnd('company-2', rerender, placeholder())

    expect(result.current).toBe(true)
  })

  it('flags a placeholder that predates a change in an additional scope dimension', () => {
    const { result, rerender } = renderGuard({ ...settled(), additionalScope: ['location-1'] })
    expect(result.current).toBe(false)

    rerender(placeholder(['location-2']))

    expect(result.current).toBe(true)
  })

  it('never records a scope from a query that has no data, so the next placeholder stays flagged', () => {
    const { result, rerender } = renderGuard(settled())

    // The disabled/pending render: not a placeholder, but no data either. If this
    // were allowed to stamp the signature, the placeholder that follows would
    // masquerade as same-scope.
    switchCompanyAnd('company-2', rerender, { isPlaceholderData: false, hasData: false })
    expect(result.current).toBe(false)

    rerender(placeholder())

    expect(result.current).toBe(true)
  })

  it('clears once the new scope settles, and re-flags on a further change', () => {
    const { result, rerender } = renderGuard(settled())

    switchCompanyAnd('company-2', rerender, placeholder())
    expect(result.current).toBe(true)

    rerender(settled())
    expect(result.current).toBe(false)

    switchCompanyAnd('company-3', rerender, placeholder())
    expect(result.current).toBe(true)
  })

  it('flags a placeholder on the way BACK to a previously-seen company', () => {
    const { result, rerender } = renderGuard(settled())

    switchCompanyAnd('company-2', rerender, settled())
    expect(result.current).toBe(false)

    // Returning to company one: whatever the observer still holds belongs to
    // company two until company one's own read settles again.
    switchCompanyAnd('company-1', rerender, placeholder())

    expect(result.current).toBe(true)
  })
})
