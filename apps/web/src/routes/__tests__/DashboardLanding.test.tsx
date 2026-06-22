import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter, Routes, Route } from 'react-router-dom'
import { DashboardLanding } from '../DashboardLanding'

const mockHasPermission = vi.fn()
vi.mock('@/hooks/usePermissions', () => ({
  usePermissions: () => ({ hasPermission: mockHasPermission }),
}))

function renderLanding() {
  return render(
    <MemoryRouter initialEntries={['/']}>
      <Routes>
        <Route path="/" element={<DashboardLanding />} />
        <Route path="/reports" element={<div>OWNER DASHBOARD</div>} />
        <Route path="/dashboard" element={<div>GENERIC DASHBOARD</div>} />
      </Routes>
    </MemoryRouter>,
  )
}

describe('DashboardLanding', () => {
  beforeEach(() => {
    mockHasPermission.mockReset()
  })

  it('redirects owners (dashboard.owner) to the owner reporting dashboard', () => {
    mockHasPermission.mockReturnValue(true)
    renderLanding()
    expect(screen.getByText('OWNER DASHBOARD')).toBeInTheDocument()
  })

  it('redirects non-owners to the generic dashboard', () => {
    mockHasPermission.mockReturnValue(false)
    renderLanding()
    expect(screen.getByText('GENERIC DASHBOARD')).toBeInTheDocument()
  })
})
