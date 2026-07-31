import { motion } from 'framer-motion'
import { format } from 'date-fns'
import {
  AlertCircle,
  ArrowUpRight,
  Check,
  ClipboardList,
  Clock,
  Package,
  Scale,
  Sparkles,
  Truck,
} from 'lucide-react'
import { briefing, feed, money, schedule } from '@/lib/data'
import { useStore } from '@/lib/store'
import { Chip, PimAvatar, ToneDot, ToneIcon } from '@/components/pim'
import { cn } from '@/lib/utils'

const icons = { truck: Truck, clock: Clock, scale: Scale }

function daypart() {
  const h = new Date().getHours()
  if (h < 12) return 'morning'
  if (h < 17) return 'afternoon'
  return 'evening'
}

const rise = {
  hidden: { opacity: 0, y: 14 },
  show: (i: number) => ({
    opacity: 1,
    y: 0,
    transition: { delay: i * 0.07, duration: 0.45, ease: 'easeOut' as const },
  }),
}

export default function Today() {
  const { approved, approve, dismissed, dismiss, saleTotal, saleCount } = useStore()
  const pending = briefing.filter((b) => !approved[b.id] && !dismissed[b.id])

  return (
    <div className="space-y-8">
      {/* Header */}
      <motion.div variants={rise} initial="hidden" animate="show" custom={0}>
        <p className="text-sm font-medium text-warmgrey">
          {format(new Date(), 'EEEE, MMMM d')} · Riverside Pharmacy · Open till 10pm
        </p>
        <h1 className="mt-1 font-display text-4xl font-medium tracking-tight">
          Good {daypart()}, Nadia.
        </h1>
      </motion.div>

      {/* The briefing — the heart of Pim */}
      <motion.section
        variants={rise}
        initial="hidden"
        animate="show"
        custom={1}
        className="overflow-hidden rounded-3xl border border-line bg-white shadow-card"
      >
        <div className="flex items-start gap-4 border-b border-line/70 bg-gradient-to-br from-brand-faint to-white px-6 py-5">
          <PimAvatar size={44} />
          <div>
            <p className="font-display text-xl font-medium">
              {pending.length > 0
                ? `${pending.length === 1 ? 'One thing needs' : `${['Two', 'Three'][pending.length - 2] ?? pending.length} things need`} you this ${new Date().getHours() < 12 ? 'morning' : 'afternoon'}.`
                : 'All clear — the shop is minded.'}
            </p>
            <p className="mt-0.5 text-sm text-warmgrey">
              Everything else is already handled. You’ll find it in the feed below.
            </p>
          </div>
        </div>

        <div className="divide-y divide-line/60">
          {briefing.map((item) => {
            const Icon = icons[item.icon]
            const done = approved[item.id]
            const skipped = dismissed[item.id]
            return (
              <div
                key={item.id}
                className={cn(
                  'flex items-start gap-4 px-6 py-5 transition-opacity',
                  skipped && 'opacity-50',
                )}
              >
                <ToneIcon tone={item.tone} size={42}>
                  <Icon className="h-5 w-5" />
                </ToneIcon>
                <div className="min-w-0 flex-1">
                  <p className="font-semibold leading-snug">{item.title}</p>
                  <p className="mt-1 max-w-2xl text-sm leading-relaxed text-warmgrey">{item.body}</p>
                  <div className="mt-3 flex items-center gap-2.5">
                    {done ? (
                      <span className="inline-flex items-center gap-1.5 rounded-full bg-brand-soft px-3.5 py-1.5 text-sm font-medium text-brand-deep">
                        <Check className="h-4 w-4" />
                        {item.id === 'po-lisinopril' && 'Ordered — arrives Thursday'}
                        {item.id === 'md-ibuprofen' && 'Marked down — shelf labels printing'}
                        {item.id === 'rec-till' && 'Noted in the books'}
                      </span>
                    ) : skipped ? (
                      <span className="text-sm italic text-warmgrey">
                        Okay — Pim will raise it again at the evening check.
                      </span>
                    ) : (
                      <>
                        <button
                          onClick={() => approve(item.id)}
                          className="rounded-full bg-ink px-4 py-1.5 text-sm font-medium text-parchment transition hover:bg-brand-deep"
                        >
                          {item.primary}
                        </button>
                        {item.secondary && (
                          <button
                            onClick={() => dismiss(item.id)}
                            className="rounded-full border border-line bg-white px-4 py-1.5 text-sm font-medium text-ink/70 transition hover:bg-secondary"
                          >
                            {item.secondary}
                          </button>
                        )}
                      </>
                    )}
                  </div>
                </div>
              </div>
            )
          })}
        </div>
      </motion.section>

      {/* Stats — plain words, no dashboard-speak */}
      <motion.section
        variants={rise}
        initial="hidden"
        animate="show"
        custom={2}
        className="grid grid-cols-2 gap-4 lg:grid-cols-4"
      >
        {[
          {
            label: 'Sold today so far',
            value: money(1284 + saleTotal),
            note: `${41 + saleCount} sales · up 6% on last ${format(new Date(), 'EEEE')}`,
            icon: ArrowUpRight,
            tone: 'text-brand',
          },
          { label: 'Prescriptions to fill', value: '7', note: '2 due before noon', icon: ClipboardList, tone: 'text-sky' },
          { label: 'Running low', value: '3', note: 'Orders drafted for all three', icon: Package, tone: 'text-honey' },
          { label: 'Expiring soon', value: '2', note: 'One already marked down', icon: AlertCircle, tone: 'text-clay' },
        ].map((s) => (
          <div key={s.label} className="rounded-2xl border border-line bg-white p-4 shadow-xs">
            <div className="flex items-center justify-between">
              <p className="text-[13px] font-medium text-warmgrey">{s.label}</p>
              <s.icon className={cn('h-4 w-4', s.tone)} />
            </div>
            <p className="mt-1.5 font-display text-3xl font-medium">{s.value}</p>
            <p className="mt-1 text-xs leading-snug text-warmgrey">{s.note}</p>
          </div>
        ))}
      </motion.section>

      {/* Feed + schedule */}
      <div className="grid gap-4 lg:grid-cols-5">
        <motion.section
          variants={rise}
          initial="hidden"
          animate="show"
          custom={3}
          className="rounded-3xl border border-line bg-white p-6 shadow-xs lg:col-span-3"
        >
          <div className="flex items-center justify-between">
            <h2 className="font-display text-xl font-medium">While you were away</h2>
            <Chip tone="brand">
              <ToneDot tone="brand" pulse /> Pim working
            </Chip>
          </div>
          <ol className="mt-5 space-y-0">
            {feed.map((f, i) => (
              <li key={i} className="relative flex gap-4 pb-5 last:pb-0">
                {i < feed.length - 1 && (
                  <span className="absolute left-[5px] top-4 h-full w-px bg-line" />
                )}
                <span className="mt-1.5 h-[11px] w-[11px] shrink-0 rounded-full border-2 border-brand-soft bg-brand" />
                <div className="min-w-0">
                  <p className="text-sm leading-relaxed">{f.text}</p>
                  <p className="mt-0.5 text-xs text-warmgrey">
                    {f.time} · {f.tag}
                  </p>
                </div>
              </li>
            ))}
          </ol>
        </motion.section>

        <motion.section
          variants={rise}
          initial="hidden"
          animate="show"
          custom={4}
          className="space-y-4 lg:col-span-2"
        >
          <div className="rounded-3xl border border-line bg-white p-6 shadow-xs">
            <h2 className="font-display text-xl font-medium">Up today</h2>
            <div className="mt-4 space-y-4">
              {schedule.map((s) => (
                <div key={s.time} className="flex gap-4">
                  <p className="w-12 shrink-0 pt-0.5 font-display text-sm font-semibold">{s.time}</p>
                  <div className="min-w-0 border-l-2 border-brand-soft pl-4">
                    <p className="text-sm font-semibold">{s.title}</p>
                    <p className="text-xs text-warmgrey">{s.note}</p>
                  </div>
                </div>
              ))}
            </div>
          </div>

          <div className="rounded-3xl border border-honey/20 bg-honey-soft/50 p-6">
            <div className="flex items-center gap-2 text-honey">
              <Sparkles className="h-4 w-4" />
              <p className="text-[13px] font-semibold uppercase tracking-wide">Pim noticed</p>
            </div>
            <p className="mt-2 text-sm leading-relaxed">
              Allergy season is starting — Loratadine sales are up <strong>38%</strong> this week.
              I’ve raised the reorder point so the shelf won’t go bare over the weekend.
            </p>
          </div>
        </motion.section>
      </div>
    </div>
  )
}
