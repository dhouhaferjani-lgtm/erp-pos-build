import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, it, expect, vi } from 'vitest'
import { ActionMenu } from './ActionMenu'

describe('ActionMenu', () => {
  const items = [
    { key: 'edit', label: 'Edit', onClick: vi.fn() },
    { key: 'delete', label: 'Delete', onClick: vi.fn(), destructive: true },
  ]

  it('renders trigger button but no menu items when closed', () => {
    render(<ActionMenu items={items} ariaLabel="Open actions" />)
    expect(screen.getByRole('button', { name: 'Open actions' })).toBeInTheDocument()
    expect(screen.queryByText('Edit')).not.toBeInTheDocument()
    expect(screen.queryByText('Delete')).not.toBeInTheDocument()
  })

  it('opens the menu when trigger button is clicked', async () => {
    const user = userEvent.setup()
    render(<ActionMenu items={items} ariaLabel="Open actions" />)
    await user.click(screen.getByRole('button', { name: 'Open actions' }))
    expect(screen.getByText('Edit')).toBeInTheDocument()
    expect(screen.getByText('Delete')).toBeInTheDocument()
  })

  it('renders menu via portal so it escapes overflow-clipping ancestors', async () => {
    const user = userEvent.setup()
    const { container } = render(
      <div
        data-testid="overflow-parent"
        style={{ overflow: 'hidden', height: 0, width: 0 }}
      >
        <ActionMenu items={items} ariaLabel="Open actions" />
      </div>,
    )
    await user.click(screen.getByRole('button', { name: 'Open actions' }))

    const overflowParent = container.querySelector('[data-testid="overflow-parent"]')
    const menuItem = screen.getByText('Edit')

    // Menu must NOT be inside the overflow-hidden ancestor
    expect(overflowParent?.contains(menuItem)).toBe(false)
  })

  it('invokes the item onClick handler and closes the menu', async () => {
    const user = userEvent.setup()
    const onClick = vi.fn()
    render(
      <ActionMenu
        items={[{ key: 'go', label: 'Go', onClick }]}
        ariaLabel="Open actions"
      />,
    )
    await user.click(screen.getByRole('button', { name: 'Open actions' }))
    await user.click(screen.getByText('Go'))
    expect(onClick).toHaveBeenCalledOnce()
    expect(screen.queryByText('Go')).not.toBeInTheDocument()
  })

  it('skips items where hidden is true', async () => {
    const user = userEvent.setup()
    render(
      <ActionMenu
        items={[
          { key: 'show', label: 'Show', onClick: vi.fn() },
          { key: 'hide', label: 'Hide', onClick: vi.fn(), hidden: true },
        ]}
        ariaLabel="Open actions"
      />,
    )
    await user.click(screen.getByRole('button', { name: 'Open actions' }))
    expect(screen.getByText('Show')).toBeInTheDocument()
    expect(screen.queryByText('Hide')).not.toBeInTheDocument()
  })

  it('disables an item when disabled is true', async () => {
    const user = userEvent.setup()
    const onClick = vi.fn()
    render(
      <ActionMenu
        items={[{ key: 'go', label: 'Go', onClick, disabled: true }]}
        ariaLabel="Open actions"
      />,
    )
    await user.click(screen.getByRole('button', { name: 'Open actions' }))
    const item = screen.getByText('Go')
    expect(item.closest('button')).toBeDisabled()
    await user.click(item)
    expect(onClick).not.toHaveBeenCalled()
  })

  it('renders nothing when there are no visible items', () => {
    render(
      <ActionMenu
        items={[{ key: 'hidden', label: 'Hidden', onClick: vi.fn(), hidden: true }]}
        ariaLabel="Open actions"
      />,
    )
    expect(screen.queryByRole('button', { name: 'Open actions' })).not.toBeInTheDocument()
  })

  it('shows a loading spinner instead of the menu glyph when isLoading is true', () => {
    render(
      <ActionMenu
        items={items}
        ariaLabel="Open actions"
        isLoading
      />,
    )
    const button = screen.getByRole('button', { name: 'Open actions' })
    expect(button).toBeDisabled()
  })
})
