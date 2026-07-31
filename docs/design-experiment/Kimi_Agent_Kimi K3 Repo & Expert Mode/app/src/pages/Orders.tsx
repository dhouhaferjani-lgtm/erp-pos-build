import { motion } from 'framer-motion'
import { ArrowRight, Check, PackageCheck, Sparkles, X } from 'lucide-react'
import { incoming, money, pastOrders } from '@/lib/data'
import { useStore } from '@/lib/store'
import { Chip } from '@/components/pim'
import { cn } from '@/lib/utils'

const suggestionsList = [
  {
    id: 'po-lisinopril',
    title: 'Lisinopril 10mg · 30s',
    why: 'Runs out Thursday — you sell about 4 packs a day',
    qty: '24 packs',
    supplier: 'MedSource',
    price: 42.6,
    eta: 'Thursday',
  },
  {
    id: 'po-loratadine',
    title: 'Loratadine 10mg · 10s',
    why: 'Allergy season — sales up 38% and climbing',
    qty: '30 packs',
    supplier: 'Al Safeer Wholesale',
    price: 34.5,
    eta: 'Friday',
  },
  {
    id: 'po-draft-all',
    title: 'Weekly top-up · 3 lines',
    why: 'Everything else that’s running low, in one order',
    qty: 'Lisinopril, Sertraline, Amoxicillin',
    supplier: 'MedSource',
    price: 231.8,
    eta: 'Thursday',
  },
]

export default function Orders() {
  const { approved, approve, dismissed, dismiss } = useStore()
  const pending = suggestionsList.filter((s) => !dismissed[s.id])

  return (
    <div className="space-y-8">
      <div>
        <h1 className="font-display text-4xl font-medium tracking-tight">Orders.</h1>
        <p className="mt-1 text-sm text-warmgrey">Pim drafts, you approve, suppliers deliver.</p>
      </div>

      {/* Suggested */}
      <section>
        <div className="mb-3 flex items-center gap-2">
          <Sparkles className="h-4 w-4 text-brand" />
          <h2 className="font-display text-xl font-medium">Suggested for you</h2>
        </div>
        <div className="grid gap-3 md:grid-cols-3">
          {pending.map((s, i) => {
            const done = approved[s.id]
            return (
              <motion.div
                key={s.id}
                initial={{ opacity: 0, y: 10 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ delay: i * 0.06 }}
                className={cn(
                  'flex flex-col rounded-2xl border bg-white p-5 shadow-xs transition',
                  done ? 'border-brand/30 bg-brand-faint' : 'border-line',
                )}
              >
                <p className="font-semibold leading-snug">{s.title}</p>
                <p className="mt-1 text-[13px] leading-snug text-warmgrey">{s.why}</p>
                <dl className="mt-3 space-y-1 text-[13px]">
                  <div className="flex justify-between"><dt className="text-warmgrey">Order</dt><dd className="font-medium">{s.qty}</dd></div>
                  <div className="flex justify-between"><dt className="text-warmgrey">Supplier</dt><dd className="font-medium">{s.supplier}</dd></div>
                  <div className="flex justify-between"><dt className="text-warmgrey">Arrives</dt><dd className="font-medium">{s.eta}</dd></div>
                  <div className="flex justify-between border-t border-line/60 pt-1.5"><dt className="text-warmgrey">Total</dt><dd className="font-display text-base font-semibold">{money(s.price)}</dd></div>
                </dl>
                <div className="mt-4 flex gap-2">
                  {done ? (
                    <span className="flex w-full items-center justify-center gap-1.5 rounded-full bg-brand-soft py-2 text-sm font-medium text-brand-deep">
                      <Check className="h-4 w-4" /> Placed — I’ll track it
                    </span>
                  ) : (
                    <>
                      <button
                        onClick={() => approve(s.id)}
                        className="flex-1 rounded-full bg-ink py-2 text-sm font-medium text-parchment transition hover:bg-brand-deep"
                      >
                        Approve
                      </button>
                      <button
                        onClick={() => dismiss(s.id)}
                        className="flex h-9 w-9 items-center justify-center rounded-full border border-line text-ink/50 transition hover:bg-secondary"
                        aria-label="Not now"
                      >
                        <X className="h-4 w-4" />
                      </button>
                    </>
                  )}
                </div>
              </motion.div>
            )
          })}
        </div>
        {pending.length === 0 && (
          <p className="rounded-2xl border border-line bg-white px-5 py-4 text-sm text-warmgrey">
            Nothing pending — Pim will draft the next suggestions at the 5pm review.
          </p>
        )}
      </section>

      {/* On the way */}
      <section>
        <h2 className="mb-3 font-display text-xl font-medium">On the way</h2>
        <div className="space-y-3">
          {incoming.map((o) => (
            <div key={o.id} className="rounded-2xl border border-line bg-white p-5 shadow-xs">
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                  <p className="font-semibold">{o.supplier}</p>
                  <p className="mt-0.5 text-[13px] text-warmgrey">{o.summary}</p>
                </div>
                <div className="text-right">
                  <Chip tone={o.step === 1 ? 'honey' : 'brand'}>{o.status}</Chip>
                  <p className="mt-1.5 text-[13px] font-medium">{o.eta}</p>
                </div>
              </div>

              {/* progress */}
              <div className="mt-4 flex items-center gap-2">
                {o.steps.map((s, i) => (
                  <div key={s} className="flex flex-1 items-center gap-2">
                    <span
                      className={cn(
                        'flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[11px] font-bold',
                        i <= o.step ? 'bg-brand text-white' : 'bg-secondary text-warmgrey',
                      )}
                    >
                      {i < o.step ? <Check className="h-3.5 w-3.5" /> : i + 1}
                    </span>
                    <span className={cn('text-xs font-medium', i <= o.step ? 'text-ink' : 'text-warmgrey')}>{s}</span>
                    {i < o.steps.length - 1 && (
                      <span className={cn('h-px flex-1', i < o.step ? 'bg-brand' : 'bg-line')} />
                    )}
                  </div>
                ))}
              </div>

              {o.note && (
                <p className="mt-4 rounded-xl bg-honey-soft/50 px-3.5 py-2.5 text-[13px] leading-snug text-honey">
                  {o.note}
                </p>
              )}
              <div className="mt-3 flex items-center justify-between text-[13px] text-warmgrey">
                <span>{o.id.toUpperCase()}</span>
                <span className="font-semibold text-ink">{money(o.total)}</span>
              </div>
            </div>
          ))}
        </div>
      </section>

      {/* Received */}
      <section>
        <h2 className="mb-3 font-display text-xl font-medium">Received</h2>
        <div className="divide-y divide-line/60 rounded-2xl border border-line bg-white shadow-xs">
          {pastOrders.map((o) => (
            <div key={o.id} className="flex items-center gap-4 px-5 py-4">
              <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-brand-soft text-brand">
                <PackageCheck className="h-4 w-4" />
              </span>
              <div className="min-w-0 flex-1">
                <p className="text-sm font-semibold">{o.supplier}</p>
                <p className="text-xs text-warmgrey">{o.id} · {o.items}</p>
              </div>
              <p className="text-[13px] text-warmgrey">{o.date}</p>
              <p className="w-20 text-right text-sm font-semibold">{money(o.total)}</p>
              <ArrowRight className="h-4 w-4 text-warmgrey/50" />
            </div>
          ))}
        </div>
      </section>
    </div>
  )
}
