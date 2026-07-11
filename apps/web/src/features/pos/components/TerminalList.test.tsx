import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { TerminalList } from './TerminalList'
import type { Terminal } from '../hooks/useTerminals'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

// Mock translation hook
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

const mockTerminal: Terminal = {
  id: '1',
  type: 'physical',
  code: 'POS01',
  name: 'Main Counter Terminal',
  description: 'Primary checkout terminal',
  location_id: 'loc-1',
  location: {
    id: 'loc-1',
    name: 'Store Location',
    code: 'LOC01',
  },
  is_active: true,
  is_training_mode: false,
  activated_at: '2025-01-01T00:00:00Z',
  deactivated_at: null,
  deactivation_reason: null,
  has_history: false,
  current_sequence: 0,
  current_year: 2025,
  fiscal_schema_version: 2,
  max_discount_percent: '100.00',
  allow_line_discounts: true,
  allow_transaction_discounts: true,
  created_at: '2025-01-01T00:00:00Z',
  updated_at: '2025-01-01T00:00:00Z',
}

const mockTerminalWithReceipts: Terminal = {
  ...mockTerminal,
  id: '3',
  code: 'POS03',
  name: 'Terminal With History',
  has_history: true,
}

const mockInactiveTerminal: Terminal = {
  ...mockTerminal,
  id: '2',
  code: 'POS02',
  name: 'Backup Terminal',
  description: null,
  is_active: false,
  deactivated_at: '2025-01-02T00:00:00Z',
  deactivation_reason: 'Maintenance',
}

describe('TerminalList', () => {
  const defaultProps = {
    terminals: [],
    onEdit: vi.fn(),
    onArchive: vi.fn(),
    onDelete: vi.fn(),
    onActivate: vi.fn(),
    onDeactivate: vi.fn(),
    onToggleTraining: vi.fn(),
  }

  it('displays loading state', () => {
    render(<TerminalList {...defaultProps} isLoading={true} />)

    expect(screen.getByText('common:common.loading')).toBeInTheDocument()
  })

  it('displays empty state when no terminals', () => {
    render(<TerminalList {...defaultProps} terminals={[]} />)

    expect(screen.getByText('pos:terminal.noTerminals')).toBeInTheDocument()
    expect(screen.getByText('pos:terminal.noTerminalsDescription')).toBeInTheDocument()
  })

  it('renders table with terminals', () => {
    render(
      <TerminalList {...defaultProps} terminals={[mockTerminal, mockInactiveTerminal]} />
    )

    // Check table headers
    expect(screen.getByText('pos:terminal.code')).toBeInTheDocument()
    expect(screen.getByText('pos:terminal.name')).toBeInTheDocument()
    expect(screen.getByText('pos:terminal.location')).toBeInTheDocument()
    expect(screen.getByText('pos:terminal.status')).toBeInTheDocument()
    expect(screen.getByText('common:actions.actions')).toBeInTheDocument()

    // Check terminal data is displayed
    expect(screen.getByText('POS01')).toBeInTheDocument()
    expect(screen.getByText('Main Counter Terminal')).toBeInTheDocument()
    expect(screen.getByText('Primary checkout terminal')).toBeInTheDocument()
    expect(screen.getAllByText('Store Location').length).toBeGreaterThan(0)

    expect(screen.getByText('POS02')).toBeInTheDocument()
    expect(screen.getByText('Backup Terminal')).toBeInTheDocument()
  })

  it('displays location name or dash if no location', () => {
    const terminalWithoutLocation: Terminal = {
      ...mockTerminal,
      location: undefined,
    }

    render(<TerminalList {...defaultProps} terminals={[terminalWithoutLocation]} />)

    expect(screen.getByText('-')).toBeInTheDocument()
  })

  it('displays description only when present', () => {
    render(
      <TerminalList {...defaultProps} terminals={[mockTerminal, mockInactiveTerminal]} />
    )

    // mockTerminal has description
    expect(screen.getByText('Primary checkout terminal')).toBeInTheDocument()

    // mockInactiveTerminal has no description - should not appear
    expect(screen.queryByText('null')).not.toBeInTheDocument()
  })

  it('calls onEdit when edit button is clicked', async () => {
    const user = userEvent.setup()
    const onEdit = vi.fn()

    render(
      <TerminalList {...defaultProps} terminals={[mockTerminal]} onEdit={onEdit} />
    )

    const editButton = screen.getByTitle('common:actions.edit')
    await user.click(editButton)

    expect(onEdit).toHaveBeenCalledTimes(1)
    expect(onEdit).toHaveBeenCalledWith(mockTerminal)
  })

  it('calls onArchive when archive button is clicked', async () => {
    const user = userEvent.setup()
    const onArchive = vi.fn()

    render(
      <TerminalList {...defaultProps} terminals={[mockTerminal]} onArchive={onArchive} />
    )

    const archiveButton = screen.getByTitle('pos:terminal.archive')
    await user.click(archiveButton)

    expect(onArchive).toHaveBeenCalledTimes(1)
    expect(onArchive).toHaveBeenCalledWith(mockTerminal)
  })

  it('calls onDelete when delete button is clicked for terminal without receipts', async () => {
    const user = userEvent.setup()
    const onDelete = vi.fn()

    render(
      <TerminalList {...defaultProps} terminals={[mockTerminal]} onDelete={onDelete} />
    )

    const deleteButton = screen.getByTitle('common:actions.delete')
    await user.click(deleteButton)

    expect(onDelete).toHaveBeenCalledTimes(1)
    expect(onDelete).toHaveBeenCalledWith(mockTerminal)
  })

  it('disables delete button when terminal has receipts', () => {
    render(
      <TerminalList {...defaultProps} terminals={[mockTerminalWithReceipts]} />
    )

    const deleteButton = screen.getByTitle('pos:terminal.cannotDeleteHasHistory')
    expect(deleteButton).toBeDisabled()
  })

  it('shows deactivate button for active terminals', async () => {
    const user = userEvent.setup()
    const onDeactivate = vi.fn()

    render(
      <TerminalList
        {...defaultProps}
        terminals={[mockTerminal]}
        onDeactivate={onDeactivate}
      />
    )

    const deactivateButton = screen.getByTitle('pos:terminal.deactivate')
    expect(deactivateButton).toBeInTheDocument()

    await user.click(deactivateButton)

    expect(onDeactivate).toHaveBeenCalledTimes(1)
    expect(onDeactivate).toHaveBeenCalledWith(mockTerminal)
  })

  it('shows activate button for inactive terminals', async () => {
    const user = userEvent.setup()
    const onActivate = vi.fn()

    render(
      <TerminalList
        {...defaultProps}
        terminals={[mockInactiveTerminal]}
        onActivate={onActivate}
      />
    )

    const activateButton = screen.getByTitle('pos:terminal.activate')
    expect(activateButton).toBeInTheDocument()

    await user.click(activateButton)

    expect(onActivate).toHaveBeenCalledTimes(1)
    expect(onActivate).toHaveBeenCalledWith(mockInactiveTerminal)
  })

  it('renders status badge for each terminal', () => {
    render(
      <TerminalList {...defaultProps} terminals={[mockTerminal, mockInactiveTerminal]} />
    )

    // Check that status badges are present
    expect(screen.getByText('terminal.active')).toBeInTheDocument()
    expect(screen.getByText('terminal.inactive')).toBeInTheDocument()
  })

  it('renders multiple terminals correctly', () => {
    const terminals = [mockTerminal, mockInactiveTerminal]

    render(<TerminalList {...defaultProps} terminals={terminals} />)

    // Verify both terminals are rendered
    expect(screen.getByText('POS01')).toBeInTheDocument()
    expect(screen.getByText('POS02')).toBeInTheDocument()

    // Verify each has its action buttons
    const editButtons = screen.getAllByTitle('common:actions.edit')
    const archiveButtons = screen.getAllByTitle('pos:terminal.archive')
    const deleteButtons = screen.getAllByTitle('common:actions.delete')

    expect(editButtons).toHaveLength(2)
    expect(archiveButtons).toHaveLength(2)
    expect(deleteButtons).toHaveLength(2)
  })

  it('shows training mode badge when terminal is in training mode', () => {
    const trainingTerminal: Terminal = {
      ...mockTerminal,
      id: '4',
      is_training_mode: true,
    }

    render(<TerminalList {...defaultProps} terminals={[trainingTerminal]} />)

    expect(screen.getByText('pos:terminal.trainingMode')).toBeInTheDocument()
  })

  it('does not show training mode badge when terminal is in production mode', () => {
    render(<TerminalList {...defaultProps} terminals={[mockTerminal]} />)

    expect(screen.queryByText('pos:terminal.trainingMode')).not.toBeInTheDocument()
  })

  it('calls onToggleTraining when training toggle button is clicked', async () => {
    const user = userEvent.setup()
    const onToggleTraining = vi.fn()

    render(
      <TerminalList
        {...defaultProps}
        terminals={[mockTerminal]}
        onToggleTraining={onToggleTraining}
      />
    )

    const toggleButton = screen.getByTitle('pos:terminal.enableTraining')
    await user.click(toggleButton)

    expect(onToggleTraining).toHaveBeenCalledTimes(1)
    expect(onToggleTraining).toHaveBeenCalledWith(mockTerminal)
  })

  it('shows disable training title when terminal is in training mode', () => {
    const trainingTerminal: Terminal = {
      ...mockTerminal,
      id: '4',
      is_training_mode: true,
    }

    render(<TerminalList {...defaultProps} terminals={[trainingTerminal]} />)

    expect(screen.getByTitle('pos:terminal.disableTraining')).toBeInTheDocument()
  })

  it('applies hover styles to table rows', () => {
    render(<TerminalList {...defaultProps} terminals={[mockTerminal]} />)

    const row = screen.getByText('POS01').closest('tr')
    expect(row).toHaveClass(colorTokens.intent.neutral.bgHover)
  })
})
