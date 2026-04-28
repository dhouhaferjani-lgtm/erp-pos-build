/**
 * DesignationCell Component Tests
 * TDD: Tests written FIRST before implementation
 */

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { DesignationCell } from '../DesignationCell'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, params?: Record<string, unknown>) => {
      const originalName = params?.['originalName']
      const translations: Record<string, string> = {
        'documents:lines.designation.editAriaLabel': 'Edit designation',
        'documents:lines.designation.overriddenTooltip': `Designation overridden — original: ${String(originalName ?? '')}`,
        'documents:lines.designation.resetLink': 'Reset to product name',
        'documents:lines.designation.resetAriaLabel': 'Reset designation to product name',
        'documents:lines.designation.resetDisabledTooltip': 'Product no longer exists',
        'documents:lines.designation.emptyHint': 'Designation cannot be empty',
      }
      let result = translations[key] ?? key
      if (params) {
        Object.entries(params).forEach(([k, v]) => {
          result = result.replace(`{{${k}}}`, String(v))
        })
      }
      return result
    },
  }),
}))

describe('DesignationCell', () => {
  let onCommit: ReturnType<typeof vi.fn>

  beforeEach(() => {
    onCommit = vi.fn()
  })

  // 1. pencil button is hidden by default (opacity-0)
  it('pencil button is hidden by default', () => {
    render(
      <DesignationCell
        value="Oil Filter"
        originalSnapshot="Oil Filter"
        readOnly={false}
        onCommit={onCommit}
      />
    )
    const pencil = screen.getByRole('button', { name: 'Edit designation' })
    expect(pencil).toBeInTheDocument()
    expect(pencil.className).toContain('opacity-0')
  })

  // 2. clicking pencil enters edit mode — input appears with current value
  it('clicking pencil enters edit mode', async () => {
    const user = userEvent.setup()
    render(
      <DesignationCell
        value="Oil Filter"
        originalSnapshot="Oil Filter"
        readOnly={false}
        onCommit={onCommit}
      />
    )
    await user.click(screen.getByRole('button', { name: 'Edit designation' }))
    const input = screen.getByRole('textbox')
    expect(input).toBeInTheDocument()
    expect((input as HTMLInputElement).value).toBe('Oil Filter')
  })

  // 3. Enter commits new value
  it('Enter commits new value', async () => {
    const user = userEvent.setup()
    render(
      <DesignationCell
        value="Oil Filter"
        originalSnapshot="Oil Filter"
        readOnly={false}
        onCommit={onCommit}
      />
    )
    await user.click(screen.getByRole('button', { name: 'Edit designation' }))
    const input = screen.getByRole('textbox')
    await user.clear(input)
    await user.type(input, 'Air Filter')
    await user.keyboard('{Enter}')
    expect(onCommit).toHaveBeenCalledWith('Air Filter')
    expect(screen.queryByRole('textbox')).toBeNull()
  })

  // 4. Escape cancels without calling onCommit
  it('Escape cancels without calling onCommit', async () => {
    const user = userEvent.setup()
    render(
      <DesignationCell
        value="Oil Filter"
        originalSnapshot="Oil Filter"
        readOnly={false}
        onCommit={onCommit}
      />
    )
    await user.click(screen.getByRole('button', { name: 'Edit designation' }))
    const input = screen.getByRole('textbox')
    await user.clear(input)
    await user.type(input, 'Air Filter')
    await user.keyboard('{Escape}')
    expect(onCommit).not.toHaveBeenCalled()
    expect(screen.queryByRole('textbox')).toBeNull()
    expect(screen.getByText('Oil Filter')).toBeInTheDocument()
  })

  // 5. empty description rejected with hint
  it('empty description rejected with hint', async () => {
    const user = userEvent.setup()
    render(
      <DesignationCell
        value="Oil Filter"
        originalSnapshot="Oil Filter"
        readOnly={false}
        onCommit={onCommit}
      />
    )
    await user.click(screen.getByRole('button', { name: 'Edit designation' }))
    const input = screen.getByRole('textbox')
    await user.clear(input)
    await user.keyboard('{Enter}')
    expect(onCommit).not.toHaveBeenCalled()
    expect(screen.getByText('Designation cannot be empty')).toBeInTheDocument()
  })

  // 6. overridden indicator shown when value differs from snapshot
  it('overridden indicator shown when value differs from snapshot', () => {
    render(
      <DesignationCell
        value="Custom"
        originalSnapshot="Oil Filter"
        readOnly={false}
        onCommit={onCommit}
      />
    )
    const indicator = screen.getByRole('status')
    expect(indicator).toBeInTheDocument()
  })

  // 7. overridden indicator hidden when values match
  it('overridden indicator hidden when values match', () => {
    render(
      <DesignationCell
        value="Oil Filter"
        originalSnapshot="Oil Filter"
        readOnly={false}
        onCommit={onCommit}
      />
    )
    expect(screen.queryByRole('status')).toBeNull()
  })

  // 8. overridden indicator hidden when snapshot is null
  it('overridden indicator hidden when snapshot is null', () => {
    render(
      <DesignationCell
        value="Oil Filter"
        originalSnapshot={null}
        readOnly={false}
        onCommit={onCommit}
      />
    )
    expect(screen.queryByRole('status')).toBeNull()
  })

  // 9. reset link appears in edit mode when value differs from snapshot
  it('reset link appears in edit mode when value differs from snapshot', async () => {
    const user = userEvent.setup()
    render(
      <DesignationCell
        value="Custom"
        originalSnapshot="Oil Filter"
        readOnly={false}
        onCommit={onCommit}
      />
    )
    await user.click(screen.getByRole('button', { name: 'Edit designation' }))
    expect(screen.getByRole('button', { name: 'Reset designation to product name' })).toBeInTheDocument()
  })

  // 10. reset link hidden when value matches snapshot
  it('reset link hidden when value matches snapshot', async () => {
    const user = userEvent.setup()
    render(
      <DesignationCell
        value="Oil Filter"
        originalSnapshot="Oil Filter"
        readOnly={false}
        onCommit={onCommit}
      />
    )
    await user.click(screen.getByRole('button', { name: 'Edit designation' }))
    expect(screen.queryByRole('button', { name: 'Reset designation to product name' })).toBeNull()
  })

  // 11. reset link restores snapshot and exits edit
  it('reset link restores snapshot and exits edit', async () => {
    const user = userEvent.setup()
    render(
      <DesignationCell
        value="Custom"
        originalSnapshot="Oil Filter"
        readOnly={false}
        onCommit={onCommit}
      />
    )
    await user.click(screen.getByRole('button', { name: 'Edit designation' }))
    await user.click(screen.getByRole('button', { name: 'Reset designation to product name' }))
    expect(onCommit).toHaveBeenCalledWith('Oil Filter')
    expect(screen.queryByRole('textbox')).toBeNull()
  })

  // 12. reset link disabled when productDeleted=true
  it('reset link disabled when productDeleted=true', async () => {
    const user = userEvent.setup()
    render(
      <DesignationCell
        value="Custom"
        originalSnapshot="Oil Filter"
        productDeleted={true}
        readOnly={false}
        onCommit={onCommit}
      />
    )
    await user.click(screen.getByRole('button', { name: 'Edit designation' }))
    const resetBtn = screen.getByRole('button', { name: 'Reset designation to product name' })
    expect(resetBtn).toBeDisabled()
    expect(resetBtn).toHaveAttribute('title', 'Product no longer exists')
  })

  // 13. input has dir="auto"
  it('input has dir="auto"', async () => {
    const user = userEvent.setup()
    render(
      <DesignationCell
        value="Oil Filter"
        originalSnapshot="Oil Filter"
        readOnly={false}
        onCommit={onCommit}
      />
    )
    await user.click(screen.getByRole('button', { name: 'Edit designation' }))
    expect(screen.getByRole('textbox')).toHaveAttribute('dir', 'auto')
  })

  // 14. readOnly mode: no pencil rendered
  it('readOnly mode: no pencil rendered', () => {
    render(
      <DesignationCell
        value="Oil Filter"
        originalSnapshot="Oil Filter"
        readOnly={true}
        onCommit={onCommit}
      />
    )
    expect(screen.queryByRole('button', { name: 'Edit designation' })).toBeNull()
  })

  // 15. readOnly mode: indicator still shows
  it('readOnly mode: indicator still shows when value differs from snapshot', () => {
    render(
      <DesignationCell
        value="Custom Name"
        originalSnapshot="Oil Filter"
        readOnly={true}
        onCommit={onCommit}
      />
    )
    expect(screen.getByRole('status')).toBeInTheDocument()
  })
})
