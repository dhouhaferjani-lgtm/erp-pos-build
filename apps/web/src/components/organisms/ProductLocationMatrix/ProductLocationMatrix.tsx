import { useMemo, useState } from 'react'
import { ChevronDown, ChevronRight } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { bccomp } from '@/lib/decimal'
import { formatQuantity } from '@/lib/format'
import { borderColors, textColors, tokens } from '@/lib/designTokens'
import type { MatrixCell, MatrixRow } from '@/features/inventory/api/stockMatrix'
import { ThresholdEditCell } from '@/features/inventory/components/ThresholdEditCell'

type LocationColumn = { id: string; name: string }
type Metric = 'available' | 'on_hand' | 'thresholds'

export function ProductLocationMatrix({ rows, locations }: { rows: MatrixRow[]; locations: LocationColumn[] }) {
  const { t } = useTranslation('inventory')
  const [metric, setMetric] = useState<Metric>('available')
  const [expanded, setExpanded] = useState<Set<string>>(new Set())
  const parents = useMemo(() => {
    const seen = new Set<string>()
    return rows.filter((row) => {
      if (seen.has(row.product_id)) return false
      const parent = row.is_variant_parent || !rows.some((candidate) => candidate.product_id === row.product_id && candidate.is_variant_parent)
      if (parent) seen.add(row.product_id)
      return parent
    })
  }, [rows])
  const childrenByProduct = useMemo(() => {
    const map = new Map<string, MatrixRow[]>()
    rows.filter((row) => !row.is_variant_parent).forEach((row) => {
      const list = map.get(row.product_id) ?? []; list.push(row); map.set(row.product_id, list)
    })
    return map
  }, [rows])
  const toggle = (id: string) => setExpanded((current) => {
    const next = new Set(current)
    if (next.has(id)) next.delete(id)
    else next.add(id)
    return next
  })
  const value = (cell: MatrixCell): string => metric === 'on_hand' ? cell.on_hand : cell.available
  const tone = (cell: MatrixCell): string => {
    if (cell.min_quantity !== null && bccomp(cell.available, cell.min_quantity) < 0) return tokens.alert.warning
    if (cell.max_quantity !== null && bccomp(cell.available, cell.max_quantity) > 0) return tokens.alert.success
    return textColors.primary
  }
  return (
    <section className={`${tokens.card.base} overflow-x-auto`}>
      <div className="mb-3 flex flex-wrap gap-2" role="group" aria-label={t('stockByLocation.metric.label')}>
        {(['available', 'on_hand', 'thresholds'] as Metric[]).map((item) => <button key={item} type="button" className={`${tokens.button.secondary} ${metric === item ? tokens.button.primary : ''}`} onClick={() => setMetric(item)}>{t(`stockByLocation.metric.${item === 'on_hand' ? 'onHand' : item}`)}</button>)}
      </div>
      <div className="grid min-w-[760px]" style={{ gridTemplateColumns: `minmax(220px, 1fr) repeat(${String(locations.length)}, minmax(180px, 1fr))` }}>
        <div className={`border-b p-3 font-semibold ${borderColors.light} ${textColors.primary}`}>{t('stockByLocation.product')}</div>
        {locations.map((location) => <div key={location.id} className={`border-b p-3 font-semibold ${borderColors.light} ${textColors.primary}`}>{location.name}</div>)}
        {parents.map((row) => {
          const children = childrenByProduct.get(row.product_id) ?? []
          const expandable = row.is_variant_parent && children.length > 0
          const visibleRows = expanded.has(row.product_id) ? [row, ...children] : [row]
          return visibleRows.map((visible) => <div key={`${visible.product_id}:${visible.variant_id ?? (visible === row ? 'parent' : 'base')}`} className="contents">
            <button type="button" className={`border-b p-3 text-start ${borderColors.light} ${textColors.primary}`} onClick={() => { if (visible === row && expandable) toggle(row.product_id) }}>
              {visible === row && expandable ? (expanded.has(row.product_id) ? <ChevronDown className="me-1 inline h-4 w-4" /> : <ChevronRight className="me-1 inline h-4 w-4" />) : null}{visible.name}
            </button>
            {locations.map((location) => {
              const cell = visible.cells[location.id] ?? { on_hand: '0.0000', reserved: '0.0000', available: '0.0000', min_quantity: null, max_quantity: null }
              return <div key={location.id} className={`border-b p-3 ${borderColors.light} ${tone(cell)}`}>
                {metric === 'thresholds' && !visible.is_variant_parent ? <ThresholdEditCell row={visible} locationId={location.id} cell={cell} /> : metric === 'thresholds' ? <span aria-label={t('stockByLocation.thresholdsNotApplicable')}>—</span> : formatQuantity(value(cell))}
              </div>
            })}
          </div>)
        })}
      </div>
    </section>
  )
}
