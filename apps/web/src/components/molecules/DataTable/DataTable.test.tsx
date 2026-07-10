import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { DataTable, type DataTableColumn } from './DataTable'

interface Row {
  id: number
  name: string
  amount: number
}

const rows: Row[] = [
  { id: 1, name: 'Alice', amount: 1200 },
  { id: 2, name: 'Bob', amount: 34 },
]

const columns: DataTableColumn<Row>[] = [
  { key: 'name', header: 'Name', render: (row) => row.name },
  {
    key: 'amount',
    header: 'Amount',
    numeric: true,
    render: (row) => row.amount.toFixed(2),
  },
]

function setup(overrides?: Partial<Parameters<typeof DataTable<Row>>[0]>) {
  return render(
    <DataTable<Row>
      columns={columns}
      data={rows}
      keyExtractor={(row) => row.id}
      {...overrides}
    />,
  )
}

describe('DataTable', () => {
  it('renders the column headers', () => {
    setup()

    expect(screen.getByRole('columnheader', { name: 'Name' })).toBeInTheDocument()
    expect(screen.getByRole('columnheader', { name: 'Amount' })).toBeInTheDocument()
  })

  it("renders a row's cells via the render function", () => {
    setup()

    expect(screen.getByText('Alice')).toBeInTheDocument()
    expect(screen.getByText('1200.00')).toBeInTheDocument()
    expect(screen.getByText('Bob')).toBeInTheDocument()
    expect(screen.getByText('34.00')).toBeInTheDocument()
  })

  it('right-aligns and applies tabular-nums to numeric column cells', () => {
    setup()

    const numericCell = screen.getByText('1200.00')
    expect(numericCell.className).toContain('text-right')
    expect(numericCell.className).toContain('tabular-nums')
  })

  it('does not apply tabular-nums to non-numeric cells', () => {
    setup()

    const textCell = screen.getByText('Alice')
    expect(textCell.className).not.toContain('tabular-nums')
  })

  it('renders skeleton rows (not data) while loading', () => {
    const { container } = setup({ isLoading: true, loadingRowCount: 3 })

    expect(screen.queryByText('Alice')).not.toBeInTheDocument()
    // 3 skeleton body rows
    const bodyRows = container.querySelectorAll('tbody tr')
    expect(bodyRows).toHaveLength(3)
    // skeleton bars use animate-pulse
    expect(container.querySelectorAll('.animate-pulse').length).toBeGreaterThan(0)
  })

  it('renders the empty state when data is empty and not loading', () => {
    setup({ data: [], emptyTitle: 'No records found' })

    expect(screen.getByText('No records found')).toBeInTheDocument()
    expect(screen.queryByText('Alice')).not.toBeInTheDocument()
  })

  it('renders a custom emptyState node when provided', () => {
    setup({ data: [], emptyState: <div>Nothing here</div> })

    expect(screen.getByText('Nothing here')).toBeInTheDocument()
  })

  it('passes through legacy table markup in children mode', () => {
    render(
      <DataTable className="legacy-table" aria-label="Legacy table">
        <tbody>
          <tr>
            <td>Legacy cell</td>
          </tr>
        </tbody>
      </DataTable>,
    )

    expect(screen.getByRole('table', { name: 'Legacy table' })).toHaveClass('legacy-table')
    expect(screen.getByRole('cell', { name: 'Legacy cell' })).toBeInTheDocument()
  })

  it('fires onRowClick when a row is clicked', () => {
    const onRowClick = vi.fn()
    setup({ onRowClick })

    fireEvent.click(screen.getByText('Alice'))

    expect(onRowClick).toHaveBeenCalledTimes(1)
    expect(onRowClick).toHaveBeenCalledWith(rows[0])
  })

  it('fires onRowClick on Enter key when a row is focused', () => {
    const onRowClick = vi.fn()
    setup({ onRowClick })

    const cell = screen.getByText('Bob')
    const tr = cell.closest('tr')
    expect(tr).not.toBeNull()
    if (tr) fireEvent.keyDown(tr, { key: 'Enter' })

    expect(onRowClick).toHaveBeenCalledWith(rows[1])
  })

  describe('row selection (opt-in)', () => {
    it('renders no checkbox column when selection is undefined', () => {
      setup()
      expect(screen.queryByRole('checkbox')).not.toBeInTheDocument()
    })

    it('renders a header checkbox and one checkbox per row when selection is provided', () => {
      setup({
        selection: {
          selectedIds: new Set<string>(),
          getRowLabel: (row: Row) => `Select ${row.name}`,
          onToggle: vi.fn(),
          onToggleAll: vi.fn(),
        },
      })
      // 1 header + 2 rows = 3 checkboxes
      expect(screen.getAllByRole('checkbox')).toHaveLength(3)
    })

    it('marks a row checkbox checked when its id is selected', () => {
      setup({
        selection: {
          selectedIds: new Set<string>(['1']),
          getRowLabel: (row: Row) => `Select ${row.name}`,
          onToggle: vi.fn(),
          onToggleAll: vi.fn(),
        },
      })
      const checkbox = screen.getByLabelText('Select Alice') as HTMLInputElement
      expect(checkbox).toBeChecked()
    })

    it('calls onToggle with the row id when a row checkbox is clicked', () => {
      const onToggle = vi.fn()
      setup({
        selection: {
          selectedIds: new Set<string>(),
          getRowLabel: (row: Row) => `Select ${row.name}`,
          onToggle,
          onToggleAll: vi.fn(),
        },
      })
      fireEvent.click(screen.getByLabelText('Select Alice'))
      expect(onToggle).toHaveBeenCalledWith('1')
    })

    it('does NOT fire onRowClick when a row checkbox is clicked', () => {
      const onRowClick = vi.fn()
      setup({
        onRowClick,
        selection: {
          selectedIds: new Set<string>(),
          getRowLabel: (row: Row) => `Select ${row.name}`,
          onToggle: vi.fn(),
          onToggleAll: vi.fn(),
        },
      })
      fireEvent.click(screen.getByLabelText('Select Alice'))
      expect(onRowClick).not.toHaveBeenCalled()
    })

    it('header checkbox is checked when all selectable rows are selected', () => {
      setup({
        selection: {
          selectedIds: new Set<string>(['1', '2']),
          getRowLabel: (row: Row) => `Select ${row.name}`,
          onToggle: vi.fn(),
          onToggleAll: vi.fn(),
        },
      })
      const header = screen.getByLabelText('Select all') as HTMLInputElement
      expect(header).toBeChecked()
      expect(header.indeterminate).toBe(false)
    })

    it('header checkbox is indeterminate when only some rows are selected', () => {
      setup({
        selection: {
          selectedIds: new Set<string>(['1']),
          getRowLabel: (row: Row) => `Select ${row.name}`,
          onToggle: vi.fn(),
          onToggleAll: vi.fn(),
        },
      })
      const header = screen.getByLabelText('Select all') as HTMLInputElement
      expect(header).not.toBeChecked()
      expect(header.indeterminate).toBe(true)
    })

    it('header checkbox is unchecked and not indeterminate when none selected', () => {
      setup({
        selection: {
          selectedIds: new Set<string>(),
          getRowLabel: (row: Row) => `Select ${row.name}`,
          onToggle: vi.fn(),
          onToggleAll: vi.fn(),
        },
      })
      const header = screen.getByLabelText('Select all') as HTMLInputElement
      expect(header).not.toBeChecked()
      expect(header.indeterminate).toBe(false)
    })

    it('calls onToggleAll when the header checkbox is clicked', () => {
      const onToggleAll = vi.fn()
      setup({
        selection: {
          selectedIds: new Set<string>(),
          getRowLabel: (row: Row) => `Select ${row.name}`,
          onToggle: vi.fn(),
          onToggleAll,
        },
      })
      fireEvent.click(screen.getByLabelText('Select all'))
      expect(onToggleAll).toHaveBeenCalledTimes(1)
    })

    it('does not render a checkbox for rows excluded by isRowSelectable', () => {
      setup({
        selection: {
          selectedIds: new Set<string>(),
          getRowLabel: (row: Row) => `Select ${row.name}`,
          onToggle: vi.fn(),
          onToggleAll: vi.fn(),
          isRowSelectable: (row) => row.id !== 2,
        },
      })
      expect(screen.getByLabelText('Select Alice')).toBeInTheDocument()
      expect(screen.queryByLabelText('Select Bob')).not.toBeInTheDocument()
    })
  })

  it('uses keyExtractor for row keys without React key warnings', () => {
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {})
    setup()

    const keyWarnings = consoleError.mock.calls.filter((args) =>
      String(args[0]).includes('unique "key"'),
    )
    expect(keyWarnings).toHaveLength(0)
    consoleError.mockRestore()
  })
})
