import { bccomp } from '@/lib/decimal'

import type { QuoteRequestGroupSibling, QuoteRequestLine } from './types'

export interface ComparisonCell {
  siblingId: string
  line: QuoteRequestLine
  quantity: string
  unit_price: string
}

export interface ComparisonRow {
  key: string
  productId: string | null
  variantId: string | null
  label: string
  cells: Partial<Record<string, ComparisonCell>>
  bestSiblingIds: string[]
}

function lineKey(line: QuoteRequestLine): string {
  return `${line.product_id ?? ''}::${line.variant_id ?? ''}`
}

function lineLabel(line: QuoteRequestLine): string {
  return line.description ?? line.product_id ?? ''
}

function isComparisonCell(cell: ComparisonCell | undefined): cell is ComparisonCell {
  return cell !== undefined
}

export function correlateGroupLines(siblings: QuoteRequestGroupSibling[]): ComparisonRow[] {
  const rows = new Map<string, ComparisonRow>()
  const respondedSiblingIds = new Set(
    siblings
      .filter((sibling) => sibling.responded_at !== null && sibling.responded_at !== undefined)
      .map((sibling) => sibling.id),
  )

  siblings.forEach((sibling) => {
    sibling.lines.forEach((line) => {
      const key = lineKey(line)
      const existing = rows.get(key)
      const row: ComparisonRow = existing ?? {
        key,
        productId: line.product_id,
        variantId: line.variant_id,
        label: lineLabel(line),
        cells: {},
        bestSiblingIds: [],
      }

      row.cells[sibling.id] = {
        siblingId: sibling.id,
        line,
        quantity: line.quantity,
        unit_price: line.unit_price,
      }
      rows.set(key, row)
    })
  })

  return Array.from(rows.values()).map((row) => {
    const cells = Object.values(row.cells).filter(isComparisonCell)
    if (cells.length !== siblings.length) {
      return { ...row, bestSiblingIds: [] }
    }

    const respondedCells = cells.filter((cell) => respondedSiblingIds.has(cell.siblingId))
    if (respondedCells.length < 2) {
      return { ...row, bestSiblingIds: [] }
    }

    const bestPrice = respondedCells.reduce((best, cell) => (
      bccomp(cell.unit_price, best) === -1 ? cell.unit_price : best
    ), respondedCells[0].unit_price)

    return {
      ...row,
      bestSiblingIds: respondedCells
        .filter((cell) => bccomp(cell.unit_price, bestPrice) === 0)
        .map((cell) => cell.siblingId),
    }
  })
}
