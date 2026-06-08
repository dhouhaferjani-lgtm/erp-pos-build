import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { LineItemsTable, QuantityCell, type LineItemsTableColumn } from './LineItemsTable'

interface TestLine {
  id: string
  name: string
  quantity: string
}

const columns: LineItemsTableColumn<TestLine>[] = [
  {
    id: 'name',
    header: 'Product',
    Cell: ({ line }) => line.name,
  },
  {
    id: 'quantity',
    header: 'Qty',
    Cell: ({ line }) => line.quantity,
  },
]

describe('LineItemsTable', () => {
  it('renders one shared header set, data rows, footer, and bottom add controls', () => {
    render(
      <LineItemsTable
        title="Line items"
        lines={[
          { id: 'line-1', name: 'Paracetamol', quantity: '2' },
          { id: 'line-2', name: 'Bandage', quantity: '1' },
        ]}
        columns={columns}
        getLineKey={(line) => line.id}
        emptyTitle="No lines"
        footer={<div>Total: 3</div>}
        addControls={<button type="button">Add line</button>}
      />,
    )

    expect(screen.getByRole('heading', { name: 'Line items' })).toBeInTheDocument()
    expect(screen.getAllByRole('columnheader', { name: 'Product' })).toHaveLength(1)
    expect(screen.getByText('Paracetamol')).toBeInTheDocument()
    expect(screen.getByText('Bandage')).toBeInTheDocument()
    expect(screen.getByText('Total: 3')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Add line' })).toBeInTheDocument()
  })

  it('supports drag reorder through an optional table-level handler', () => {
    const onReorder = vi.fn()

    render(
      <LineItemsTable
        lines={[
          { id: 'line-1', name: 'A', quantity: '1' },
          { id: 'line-2', name: 'B', quantity: '1' },
        ]}
        columns={columns}
        getLineKey={(line) => line.id}
        emptyTitle="No lines"
        dragAndDrop={{
          dragAriaLabel: 'Drag row',
          onReorder,
        }}
      />,
    )

    const rows = screen.getAllByRole('row')
    fireEvent.dragStart(rows[1])
    fireEvent.dragOver(rows[2])

    expect(onReorder).toHaveBeenCalledWith(0, 1)
  })
})

describe('QuantityCell', () => {
  it('derives the native input step from the line quantity decimals', () => {
    render(
      <QuantityCell
        value="1"
        decimalPlaces={0}
        onChange={vi.fn()}
        ariaLabel="Quantity"
      />,
    )

    expect(screen.getByRole('spinbutton', { name: 'Quantity' })).toHaveAttribute('step', '1')
  })
})
