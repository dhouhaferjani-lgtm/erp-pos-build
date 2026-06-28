import { describe, it, expect, vi } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import { SaveSplitButton } from './SaveSplitButton'

vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (k: string) => k }) }))

describe('SaveSplitButton', () => {
  it('renders the primary save with default label and a menu trigger', () => {
    render(<SaveSplitButton onPrimarySave={vi.fn()} onSaveAndClose={vi.fn()} />)
    expect(screen.getByRole('button', { name: 'actions.save' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'actions.openSaveMenu' })).toHaveAttribute('aria-haspopup', 'menu')
  })

  it('hides the caret when no secondary actions are provided', () => {
    render(<SaveSplitButton onPrimarySave={vi.fn()} />)
    expect(screen.queryByRole('button', { name: 'actions.openSaveMenu' })).not.toBeInTheDocument()
  })

  it('opens the menu and fires Save & Close', () => {
    const onClose = vi.fn()
    render(<SaveSplitButton onPrimarySave={vi.fn()} onSaveAndClose={onClose} />)
    fireEvent.click(screen.getByRole('button', { name: 'actions.openSaveMenu' }))
    fireEvent.click(screen.getByRole('menuitem', { name: 'actions.saveAndClose' }))
    expect(onClose).toHaveBeenCalledOnce()
  })

  it('closes the menu on Escape and returns focus to the trigger', () => {
    render(<SaveSplitButton onPrimarySave={vi.fn()} onSaveAndClose={vi.fn()} />)
    const trigger = screen.getByRole('button', { name: 'actions.openSaveMenu' })
    fireEvent.click(trigger)
    expect(screen.getByRole('menu')).toBeInTheDocument()
    fireEvent.keyDown(screen.getByRole('menu'), { key: 'Escape' })
    expect(screen.queryByRole('menu')).not.toBeInTheDocument()
    expect(trigger).toHaveFocus()
  })

  it('uses the primaryLabel override and renders as a submit for the given form', () => {
    render(<SaveSplitButton onPrimarySave={vi.fn()} primaryLabel="catalog:editor.actions.save" form="product-editor-form" />)
    const primary = screen.getByRole('button', { name: 'catalog:editor.actions.save' })
    expect(primary).toHaveAttribute('type', 'submit')
    expect(primary).toHaveAttribute('form', 'product-editor-form')
  })

  it('links the caret trigger to the menu via aria-controls', () => {
    render(<SaveSplitButton onPrimarySave={vi.fn()} onSaveAndClose={vi.fn()} />)
    const trigger = screen.getByRole('button', { name: 'actions.openSaveMenu' })
    fireEvent.click(trigger)
    const menu = screen.getByRole('menu')
    expect(trigger).toHaveAttribute('aria-controls', menu.id)
    expect(menu.id).toBeTruthy()
  })

  it('closes the menu on Tab', () => {
    render(<SaveSplitButton onPrimarySave={vi.fn()} onSaveAndClose={vi.fn()} />)
    fireEvent.click(screen.getByRole('button', { name: 'actions.openSaveMenu' }))
    fireEvent.keyDown(screen.getByRole('menu'), { key: 'Tab' })
    expect(screen.queryByRole('menu')).not.toBeInTheDocument()
  })
})
