import { useEffect, useMemo, useState } from 'react'
import { motion } from 'framer-motion'
import { addDays, format } from 'date-fns'
import { Check, Droplets, List, Pill, Snowflake, Sparkles, StretchHorizontal, Wind } from 'lucide-react'
import { daysLeft, money, products, type Product } from '@/lib/data'
import { useStore } from '@/lib/store'
import { Chip, StockBar, ToneDot } from '@/components/pim'
import { cn } from '@/lib/utils'

const kindIcon = { pill: Pill, inhaler: Wind, cold: Snowflake, liquid: Droplets, cream: Droplets }

type Filter = 'attention' | 'low' | 'expiring' | 'cold' | 'all'

const filters: { id: Filter; label: string }[] = [
  { id: 'attention', label: 'Needs attention' },
  { id: 'low', label: 'Running low' },
  { id: 'expiring', label: 'Expiring' },
  { id: 'cold', label: 'Cold chain' },
  { id: 'all', label: 'Everything' },
]

const isLow = (p: Product) => daysLeft(p) <= 5
const needsAttention = (p: Product) => isLow(p) || !!p.expiry

function status(p: Product) {
  if (p.expiry)
    return {
      tone: (p.expiry.days <= 18 ? 'clay' : 'honey') as 'clay' | 'honey',
      chip: `Batch ${p.expiry.lot} expires in ${p.expiry.days} days`,
      note: `${p.expiry.units} ${p.unit} won’t sell through in time`,
    }
  if (isLow(p))
    return {
      tone: 'honey' as const,
      chip: `Runs out ${format(addDays(new Date(), daysLeft(p)), 'EEEE')}`,
      note: `Sells ~${p.pacePerDay} a day · ${p.stock} ${p.unit} left`,
    }
  return {
    tone: 'brand' as const,
    chip: 'Healthy',
    note: `${p.stock} ${p.unit} · about ${Math.round(daysLeft(p) / 7)} weeks at the current pace`,
  }
}

function compactStatus(p: Product) {
  if (p.expiry) return `Exp ${p.expiry.lot} · ${p.expiry.days}d left`
  if (isLow(p)) return `Runs out ${format(addDays(new Date(), daysLeft(p)), 'EEE')}`
  return 'Healthy'
}

export default function Inventory() {
  const { approved, approve } = useStore()
  const [filter, setFilter] = useState<Filter>('attention')
  const [density, setDensity] = useState<'comfortable' | 'compact'>(
    () => (localStorage.getItem('pim-density') as 'comfortable' | 'compact') || 'comfortable',
  )

  useEffect(() => {
    localStorage.setItem('pim-density', density)
  }, [density])

  const counts = useMemo(
    () => ({
      attention: products.filter(needsAttention).length,
      low: products.filter(isLow).length,
      expiring: products.filter((p) => p.expiry).length,
      cold: products.filter((p) => p.cold).length,
      all: products.length,
    }),
    [],
  )

  const list = useMemo(() => {
    const sorted = [...products].sort((a, b) => Number(needsAttention(b)) - Number(needsAttention(a)) || daysLeft(a) - daysLeft(b))
    switch (filter) {
      case 'attention': return sorted.filter(needsAttention)
      case 'low': return sorted.filter(isLow)
      case 'expiring': return sorted.filter((p) => p.expiry)
      case 'cold': return sorted.filter((p) => p.cold)
      default: return sorted
    }
  }, [filter])

  const allDrafted = approved['po-draft-all']

  return (
    <div className="space-y-6">
      <div>
        <h1 className="font-display text-4xl font-medium tracking-tight">Stock.</h1>
        <p className="mt-1 text-sm text-warmgrey">
          {products.length * 103} products on the books · {counts.low} running low · {counts.expiring} expiring soon
        </p>
      </div>

      {/* Pim's standing suggestion */}
      <motion.div
        initial={{ opacity: 0, y: 10 }}
        animate={{ opacity: 1, y: 0 }}
        className="flex flex-wrap items-center gap-4 rounded-2xl border border-brand/15 bg-brand-faint px-5 py-4"
      >
        <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-brand-soft text-brand">
          <Sparkles className="h-4 w-4" />
        </span>
        <p className="min-w-0 flex-1 text-sm leading-relaxed">
          <strong>Pim:</strong> all {counts.low} low items can come from MedSource in one order —
          about <strong>$231.80</strong>, arriving Thursday. Want me to draft it?
        </p>
        {allDrafted ? (
          <span className="flex items-center gap-1.5 rounded-full bg-brand-soft px-4 py-2 text-sm font-medium text-brand-deep">
            <Check className="h-4 w-4" /> 3 orders drafted — review in Orders
          </span>
        ) : (
          <button
            onClick={() => approve('po-draft-all')}
            className="rounded-full bg-ink px-4 py-2 text-sm font-medium text-parchment transition hover:bg-brand-deep"
          >
            Draft all three
          </button>
        )}
      </motion.div>

      {/* Filters + density */}
      <div className="flex flex-wrap items-center gap-2">
        {filters.map((f) => (
          <button
            key={f.id}
            onClick={() => setFilter(f.id)}
            className={cn(
              'rounded-full border px-4 py-2 text-sm font-medium transition',
              filter === f.id
                ? 'border-ink bg-ink text-parchment'
                : 'border-line bg-white text-ink/70 hover:border-ink/30',
            )}
          >
            {f.label}
            <span className={cn('ml-1.5 text-xs', filter === f.id ? 'text-parchment/60' : 'text-warmgrey')}>
              {counts[f.id]}
            </span>
          </button>
        ))}

        {/* Density — a setting, not a mode */}
        <div className="ml-auto flex items-center rounded-full border border-line bg-white p-1 shadow-xs">
          {(
            [
              { id: 'comfortable', icon: StretchHorizontal, label: 'Comfortable' },
              { id: 'compact', icon: List, label: 'Compact' },
            ] as const
          ).map((d) => (
            <button
              key={d.id}
              onClick={() => setDensity(d.id)}
              className={cn(
                'flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-medium transition',
                density === d.id ? 'bg-ink text-parchment' : 'text-warmgrey hover:text-ink',
              )}
            >
              <d.icon className="h-3.5 w-3.5" />
              {d.label}
            </button>
          ))}
        </div>
      </div>

      {/* Rows */}
      {density === 'compact' ? (
        <div className="overflow-x-auto rounded-2xl border border-line bg-white shadow-xs">
          <div className="min-w-[880px]">
            <div className="grid grid-cols-[2fr_1.7fr_90px_80px_1.2fr_130px] items-center gap-3 border-b border-line bg-parchment px-4 py-2">
              {['Product', 'Status', 'Stock', 'Price', 'Supplier', ''].map((h) => (
                <span key={h} className="text-[11px] font-semibold uppercase tracking-wider text-warmgrey">
                  {h}
                </span>
              ))}
            </div>
            {list.map((p) => {
              const s = status(p)
              const actionId = `reorder-${p.id}`
              const done = approved[actionId] || (isLow(p) && allDrafted)
              return (
                <div
                  key={p.id}
                  className="grid grid-cols-[2fr_1.7fr_90px_80px_1.2fr_130px] items-center gap-3 border-b border-line/50 px-4 py-2 text-[13px] last:border-0 hover:bg-parchment/70"
                >
                  <div className="flex min-w-0 items-center gap-2">
                    <span className="shrink-0 font-medium">{p.name}</span>
                    {p.rx && (
                      <span className="rounded bg-sky-soft px-1 py-px text-[9px] font-bold tracking-wide text-sky">Rx</span>
                    )}
                    {p.cold && <Snowflake className="h-3 w-3 shrink-0 text-sky" />}
                    <span className="truncate text-xs text-warmgrey">{p.detail}</span>
                  </div>
                  <div className="flex items-center gap-2 text-warmgrey">
                    <ToneDot tone={s.tone} />
                    <span className={cn('truncate', needsAttention(p) && 'font-medium text-ink')}>{compactStatus(p)}</span>
                  </div>
                  <p className="tabular-nums">
                    {p.stock} <span className="text-warmgrey">{p.unit}</span>
                  </p>
                  <p className="tabular-nums font-medium">{money(p.price)}</p>
                  <p className="truncate text-warmgrey">{p.supplier}</p>
                  <div className="text-right">
                    {needsAttention(p) &&
                      (done ? (
                        <span className="inline-flex items-center gap-1 text-xs font-medium text-brand-deep">
                          <Check className="h-3.5 w-3.5" /> Done
                        </span>
                      ) : (
                        <button
                          onClick={() => approve(actionId)}
                          className="rounded-full border border-brand/30 px-2.5 py-1 text-xs font-medium text-brand-deep transition hover:bg-brand-soft"
                        >
                          {p.expiry && !isLow(p) ? 'Mark down' : 'Reorder'}
                        </button>
                      ))}
                  </div>
                </div>
              )
            })}
          </div>
        </div>
      ) : (
      <div className="space-y-2.5">
        {list.map((p, i) => {
          const s = status(p)
          const Icon = kindIcon[p.kind]
          const actionId = `reorder-${p.id}`
          const done = approved[actionId] || (isLow(p) && allDrafted)
          return (
            <motion.div
              key={p.id}
              initial={{ opacity: 0, y: 8 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ delay: i * 0.03, duration: 0.3 }}
              className="flex items-center gap-4 rounded-2xl border border-line bg-white px-5 py-4 shadow-xs"
            >
              <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-secondary text-ink/60">
                <Icon className="h-5 w-5" />
              </span>

              <div className="w-52 shrink-0">
                <p className="flex items-center gap-2 font-semibold leading-tight">
                  {p.name}
                  {p.rx && <span className="rounded bg-sky-soft px-1.5 py-0.5 text-[10px] font-bold tracking-wide text-sky">Rx</span>}
                  {p.cold && <Snowflake className="h-3.5 w-3.5 text-sky" />}
                </p>
                <p className="mt-0.5 text-xs text-warmgrey">{p.detail}</p>
              </div>

              <div className="hidden min-w-0 flex-1 md:block">
                <Chip tone={s.tone}>{s.chip}</Chip>
                <p className="mt-1.5 truncate text-xs text-warmgrey">{s.note}</p>
              </div>

              <div className="hidden w-28 shrink-0 lg:block">
                <StockBar value={p.stock} />
                <p className="mt-1 text-xs text-warmgrey">{p.stock} {p.unit}</p>
              </div>

              <p className="hidden w-16 shrink-0 text-right text-sm font-semibold sm:block">{money(p.price)}</p>

              <div className="w-36 shrink-0 text-right">
                {needsAttention(p) ? (
                  done ? (
                    <span className="inline-flex items-center gap-1.5 text-sm font-medium text-brand-deep">
                      <Check className="h-4 w-4" /> {p.expiry && !isLow(p) ? 'Marked down' : 'Order drafted'}
                    </span>
                  ) : (
                    <button
                      onClick={() => approve(actionId)}
                      className="rounded-full border border-brand/30 bg-white px-3.5 py-1.5 text-[13px] font-medium text-brand-deep transition hover:bg-brand-soft"
                    >
                      {p.expiry && !isLow(p) ? 'Ask Pim to mark down' : 'Ask Pim to reorder'}
                    </button>
                  )
                ) : (
                  <span className="text-xs text-warmgrey">{p.supplier}</span>
                )}
              </div>
            </motion.div>
          )
        })}
      </div>
      )}

      <p className="pt-2 text-center text-xs text-warmgrey">
        {density === 'compact'
          ? 'Same data, tighter packing — nothing hidden, nothing different underneath.'
          : 'Counts update themselves — every sale, delivery and expiry is tracked the moment it happens.'}
      </p>
    </div>
  )
}
