import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { Link, MemoryRouter, Route, Routes, useParams } from 'react-router-dom'
import { describe, expect, it } from 'vitest'

import { KeyedByRouteId } from '../index'

/**
 * `KeyedByRouteId` is the structural cure for cross-document carry-over on the
 * detail hosts: React Router keeps the SAME element instance mounted when only
 * the `:id` param changes, so any state held below it — a guided cancel modal's
 * `reason`, a RecordPaymentModal's confirmed payment line — follows the
 * operator onto a different document. Keying the subtree on the id makes "a
 * different document" mean "a different component instance".
 *
 * The two tests below are a matched pair: the second is the negative control
 * that proves the harness can actually observe the difference.
 */
function DraftProbe() {
  const { id } = useParams()
  const [draft, setDraft] = useState('')

  return (
    <div>
      <span data-testid="doc-id">{id}</span>
      <input
        aria-label="draft"
        value={draft}
        onChange={(e) => { setDraft(e.target.value) }}
      />
    </div>
  )
}

function renderHost(wrapped: boolean) {
  const probe = <DraftProbe />

  return render(
    <MemoryRouter initialEntries={['/orders/doc-a']}>
      <Link to="/orders/doc-b">go-to-b</Link>
      <Routes>
        <Route
          path="/orders/:id"
          element={wrapped ? <KeyedByRouteId>{probe}</KeyedByRouteId> : probe}
        />
      </Routes>
    </MemoryRouter>,
  )
}

describe('KeyedByRouteId', () => {
  it('remounts its subtree when the :id route param changes', async () => {
    renderHost(true)

    await userEvent.type(screen.getByLabelText('draft'), 'entry for document A')
    expect(screen.getByLabelText('draft')).toHaveValue('entry for document A')
    expect(screen.getByTestId('doc-id')).toHaveTextContent('doc-a')

    await userEvent.click(screen.getByRole('link', { name: 'go-to-b' }))

    expect(screen.getByTestId('doc-id')).toHaveTextContent('doc-b')
    expect(screen.getByLabelText('draft')).toHaveValue('')
  })

  it('negative control: without the wrapper the same param change KEEPS the state', async () => {
    renderHost(false)

    await userEvent.type(screen.getByLabelText('draft'), 'entry for document A')
    await userEvent.click(screen.getByRole('link', { name: 'go-to-b' }))

    expect(screen.getByTestId('doc-id')).toHaveTextContent('doc-b')
    expect(screen.getByLabelText('draft')).toHaveValue('entry for document A')
  })
})
