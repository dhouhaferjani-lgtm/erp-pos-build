import type { RebalanceRow } from '../api/stockMatrix'

export interface RebalanceMove {
  row: RebalanceRow
  from: RebalanceRow['surpluses'][number]
  to: RebalanceRow['deficits'][number]
  quantity: string
}

export function pairRebalanceRows(rows: RebalanceRow[]): RebalanceMove[] {
  return rows.flatMap((row) => row.surpluses.flatMap((from) => row.deficits.map((to) => ({ row, from, to, quantity: from.excess }))))
}
