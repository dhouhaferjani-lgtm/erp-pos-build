import { motion } from 'framer-motion'
import { ArrowDownRight, ArrowUpRight, Check, Clock, PiggyBank, Sparkles } from 'lucide-react'
import { money, takenCareOf, week } from '@/lib/data'
import { useStore } from '@/lib/store'
import { cn } from '@/lib/utils'

const spent = [
  { label: 'Stock', pct: 62, amount: 3165 },
  { label: 'Payroll', pct: 24, amount: 1226 },
  { label: 'Rent & utilities', pct: 14, amount: 714 },
]

export default function Money() {
  const { saleTotal } = useStore()
  const cameIn = 8412 + saleTotal
  const wentOut = 5105
  const max = Math.max(...week.map((w) => w.v))

  return (
    <div className="space-y-8">
      <div>
        <h1 className="font-display text-4xl font-medium tracking-tight">Money.</h1>
        <p className="mt-1 text-sm text-warmgrey">The week so far, in plain terms. No spreadsheets required.</p>
      </div>

      {/* The three numbers that matter */}
      <div className="grid gap-4 md:grid-cols-3">
        {[
          { label: 'Came in', value: cameIn, note: 'Up 6% on last week', icon: ArrowUpRight, cls: 'text-brand bg-brand-soft' },
          { label: 'Went out', value: wentOut, note: 'Stock, payroll, rent', icon: ArrowDownRight, cls: 'text-honey bg-honey-soft' },
          { label: 'Yours to keep', value: cameIn - wentOut, note: 'After everything', icon: PiggyBank, cls: 'text-sky bg-sky-soft' },
        ].map((c, i) => (
          <motion.div
            key={c.label}
            initial={{ opacity: 0, y: 10 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay: i * 0.07 }}
            className="rounded-3xl border border-line bg-white p-6 shadow-xs"
          >
            <div className="flex items-center justify-between">
              <p className="text-sm font-medium text-warmgrey">{c.label}</p>
              <span className={cn('flex h-8 w-8 items-center justify-center rounded-xl', c.cls)}>
                <c.icon className="h-4 w-4" />
              </span>
            </div>
            <p className="mt-2 font-display text-4xl font-medium tracking-tight">{money(c.value)}</p>
            <p className="mt-1 text-[13px] text-warmgrey">{c.note}</p>
          </motion.div>
        ))}
      </div>

      <div className="grid gap-4 lg:grid-cols-5">
        {/* Week chart */}
        <motion.section
          initial={{ opacity: 0, y: 10 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ delay: 0.2 }}
          className="rounded-3xl border border-line bg-white p-6 shadow-xs lg:col-span-3"
        >
          <div className="flex items-baseline justify-between">
            <h2 className="font-display text-xl font-medium">Day by day</h2>
            <p className="text-[13px] text-warmgrey">Friday was the best day</p>
          </div>
          <div className="mt-6 flex gap-3">
            {week.map((w) => (
              <div key={w.d} className="group flex flex-1 flex-col items-center gap-2">
                <span className="text-xs font-semibold opacity-0 transition group-hover:opacity-100">
                  {money(w.v + (w.d === 'Today' ? saleTotal : 0))}
                </span>
                <div className="flex h-36 w-full items-end">
                  <div
                    className={cn(
                      'w-full rounded-lg transition-all',
                      w.d === 'Today' ? 'bg-brand' : 'bg-brand/20 group-hover:bg-brand/35',
                    )}
                    style={{ height: `${(w.v / max) * 100}%` }}
                  />
                </div>
                <span className="text-xs font-medium text-warmgrey">{w.d}</span>
              </div>
            ))}
          </div>

          {/* Where it went */}
          <div className="mt-8 border-t border-line/60 pt-5">
            <h3 className="text-sm font-semibold">Where it went</h3>
            <div className="mt-3 space-y-2.5">
              {spent.map((s) => (
                <div key={s.label} className="flex items-center gap-3">
                  <p className="w-28 shrink-0 text-[13px] text-warmgrey">{s.label}</p>
                  <div className="h-2 flex-1 overflow-hidden rounded-full bg-secondary">
                    <div className="h-full rounded-full bg-honey/70" style={{ width: `${s.pct}%` }} />
                  </div>
                  <p className="w-16 text-right text-[13px] font-medium">{money(s.amount)}</p>
                </div>
              ))}
            </div>
          </div>
        </motion.section>

        <div className="space-y-4 lg:col-span-2">
          {/* Invisible compliance */}
          <motion.section
            initial={{ opacity: 0, y: 10 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay: 0.28 }}
            className="rounded-3xl border border-brand/15 bg-white p-6 shadow-xs"
          >
            <div className="flex items-center justify-between">
              <h2 className="font-display text-xl font-medium">Taken care of</h2>
              <span className="rounded-full bg-brand-soft px-2.5 py-1 text-xs font-medium text-brand-deep">by Pim</span>
            </div>
            <ul className="mt-4 space-y-3.5">
              {takenCareOf.map((t) => (
                <li key={t.title} className="flex items-start gap-3">
                  <span
                    className={cn(
                      'mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full',
                      t.done ? 'bg-brand-soft text-brand' : 'bg-honey-soft text-honey',
                    )}
                  >
                    {t.done ? <Check className="h-3 w-3" /> : <Clock className="h-3 w-3" />}
                  </span>
                  <div>
                    <p className="text-sm font-medium leading-tight">{t.title}</p>
                    <p className="mt-0.5 text-xs leading-snug text-warmgrey">{t.note}</p>
                  </div>
                </li>
              ))}
            </ul>
            <p className="mt-5 border-t border-line/60 pt-3.5 text-xs leading-relaxed text-warmgrey">
              No journals, no chart of accounts, no filings on your desk. The accounting happens
              underneath — you see the answer, not the arithmetic.
            </p>
          </motion.section>

          {/* Insight */}
          <motion.section
            initial={{ opacity: 0, y: 10 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay: 0.36 }}
            className="rounded-3xl border border-honey/20 bg-honey-soft/50 p-6"
          >
            <div className="flex items-center gap-2 text-honey">
              <Sparkles className="h-4 w-4" />
              <p className="text-[13px] font-semibold uppercase tracking-wide">Worth knowing</p>
            </div>
            <p className="mt-2 text-sm leading-relaxed">
              Antihistamines brought in <strong>$612</strong> this week — up 38%. If the season
              follows last year, that’s about <strong>$1,900 more</strong> over the next month.
              Stock is already adjusted.
            </p>
          </motion.section>
        </div>
      </div>
    </div>
  )
}
