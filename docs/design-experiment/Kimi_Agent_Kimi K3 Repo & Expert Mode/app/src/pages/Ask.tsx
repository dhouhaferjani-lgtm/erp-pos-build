import { useEffect, useRef, useState } from 'react'
import { useLocation } from 'react-router'
import { AnimatePresence, motion } from 'framer-motion'
import {
  ArrowUp,
  Check,
  ClipboardCheck,
  Clock,
  ShieldCheck,
  Sparkles,
  Truck,
} from 'lucide-react'
import { handled, money, products, suggestions, week } from '@/lib/data'
import { useStore } from '@/lib/store'
import { Chip, PimAvatar } from '@/components/pim'
import { cn } from '@/lib/utils'

type Card =
  | { kind: 'product'; productId: string; actionId: string; action: string; done: string }
  | { kind: 'markdown'; actionId: string }
  | { kind: 'order'; actionId: string }
  | { kind: 'week' }

type Msg = { id: number; role: 'user' | 'pim'; text?: string; card?: Card }

const confirmText: Record<string, string> = {
  'po-amoxicillin': 'Done — PO-1042 placed with MedSource. It arrives Thursday and I’ll flag any delay.',
  'md-ibuprofen': 'Done — marked down 30% and the shelf labels are printing. I’ll watch how it moves.',
  'po-tuesday': 'Done — all three orders placed. Everything lands before Thursday.',
}

function answerFor(q: string): { text: string; card?: Card } {
  const s = q.toLowerCase()
  if (s.includes('amoxicillin'))
    return {
      text: 'Amoxicillin is moving steadily — about 3 packs a day, and you have 12 left. At that pace it runs out Thursday. MedSource can have 40 packs here before you open:',
      card: { kind: 'product', productId: 'amoxicillin', actionId: 'po-amoxicillin', action: 'Approve — $186.40', done: 'Ordered' },
    }
  if (s.includes('mark down') || s.includes('markdown') || s.includes('expir'))
    return {
      text: 'One clear candidate: Ibuprofen 400mg, batch K2204. Thirty-four packs expire in 18 days and won’t sell through in time. A 30% markdown usually clears it in a week.',
      card: { kind: 'markdown', actionId: 'md-ibuprofen' },
    }
  if (s.includes('tuesday') || s.includes('order') || s.includes('reorder'))
    return {
      text: 'Here’s what I’d order based on the last two weeks — three lines, two suppliers, everything in before Thursday:',
      card: { kind: 'order', actionId: 'po-tuesday' },
    }
  if (s.includes('week'))
    return {
      text: 'A good week. You sold $8,412 — up 6% on the week before — and Friday was your best day. The standout is antihistamines: up 38% as allergy season starts.',
      card: { kind: 'week' },
    }
  return {
    text: 'Here’s the quick picture while I dig into the detail — the shop is healthy this week, and nothing urgent is hiding in the numbers:',
    card: { kind: 'week' },
  }
}

function Spark({ bars, className }: { bars: number[]; className?: string }) {
  const max = Math.max(...bars)
  return (
    <div className={cn('flex h-10 items-end gap-1', className)}>
      {bars.map((b, i) => (
        <div
          key={i}
          className={cn('w-2 rounded-sm', i === bars.length - 1 ? 'bg-brand' : 'bg-brand/25')}
          style={{ height: `${Math.max(12, (b / max) * 100)}%` }}
        />
      ))}
    </div>
  )
}

export default function Ask() {
  const { approved, approve } = useStore()
  const location = useLocation() as { state?: { prefill?: string } }
  const [msgs, setMsgs] = useState<Msg[]>([
    {
      id: 0,
      role: 'pim',
      text: `${new Date().getHours() < 12 ? 'Morning' : new Date().getHours() < 17 ? 'Afternoon' : 'Evening'}, Nadia. Ask me anything about the shop — stock, sales, suppliers — or tell me what to do.`,
    },
  ])
  const [input, setInput] = useState('')
  const [typing, setTyping] = useState(false)
  const idRef = useRef(1)
  const bottomRef = useRef<HTMLDivElement>(null)
  const timers = useRef<ReturnType<typeof setTimeout>[]>([])

  useEffect(() => {
    if (location.state?.prefill) setInput(location.state.prefill)
  }, [location.state])

  useEffect(() => {
    bottomRef.current?.scrollIntoView({ behavior: 'smooth', block: 'end' })
  }, [msgs, typing])

  useEffect(() => () => timers.current.forEach(clearTimeout), [])

  const push = (m: Omit<Msg, 'id'>) => setMsgs((s) => [...s, { ...m, id: idRef.current++ }])

  const send = (raw: string) => {
    const text = raw.trim()
    if (!text || typing) return
    setInput('')
    push({ role: 'user', text })
    setTyping(true)
    timers.current.push(
      setTimeout(() => {
        const a = answerFor(text)
        push({ role: 'pim', text: a.text, card: a.card })
        setTyping(false)
      }, 900 + Math.random() * 600),
    )
  }

  const doApprove = (actionId: string) => {
    approve(actionId)
    timers.current.push(
      setTimeout(() => push({ role: 'pim', text: confirmText[actionId] ?? 'Done.' }), 450),
    )
  }

  const renderCard = (card: Card) => {
    if (card.kind === 'product') {
      const p = products.find((x) => x.id === card.productId)!
      const done = approved[card.actionId]
      return (
        <div className="mt-3 rounded-2xl border border-line bg-white p-4 shadow-xs">
          <div className="flex items-start justify-between gap-4">
            <div>
              <p className="font-semibold">{p.name} <span className="font-normal text-warmgrey">{p.detail}</span></p>
              <div className="mt-2 flex flex-wrap gap-1.5">
                <Chip tone="honey">{p.stock} {p.unit} left</Chip>
                <Chip tone="neutral">Sells ~{p.pacePerDay}/day</Chip>
                <Chip tone="clay">Runs out Thursday</Chip>
              </div>
            </div>
            <Spark bars={[4, 3, 5, 3, 4, 2, 3, 3, 4, 3, 2, 3, 3, 2]} />
          </div>
          <div className="mt-3 flex items-center justify-between rounded-xl bg-parchment px-3.5 py-2.5">
            <p className="text-sm">40 {p.unit} · {p.supplier} · arrives Thu</p>
            {done ? (
              <span className="flex items-center gap-1.5 text-sm font-medium text-brand-deep"><Check className="h-4 w-4" /> {card.done}</span>
            ) : (
              <button onClick={() => doApprove(card.actionId)} className="rounded-full bg-ink px-3.5 py-1.5 text-sm font-medium text-parchment transition hover:bg-brand-deep">
                {card.action}
              </button>
            )}
          </div>
        </div>
      )
    }
    if (card.kind === 'markdown') {
      const done = approved[card.actionId]
      return (
        <div className="mt-3 rounded-2xl border border-line bg-white p-4 shadow-xs">
          <p className="font-semibold">Ibuprofen 400mg · batch K2204</p>
          <div className="mt-2 flex flex-wrap gap-1.5">
            <Chip tone="clay">34 packs expire in 18 days</Chip>
            <Chip tone="honey">Worth $116 today</Chip>
          </div>
          <div className="mt-3 flex items-center justify-between rounded-xl bg-parchment px-3.5 py-2.5">
            <p className="text-sm">Mark down 30% → keep about <strong>$81</strong> instead of losing $116</p>
            {done ? (
              <span className="flex items-center gap-1.5 text-sm font-medium text-brand-deep"><Check className="h-4 w-4" /> Marked down</span>
            ) : (
              <button onClick={() => doApprove(card.actionId)} className="rounded-full bg-ink px-3.5 py-1.5 text-sm font-medium text-parchment transition hover:bg-brand-deep">
                Mark it down
              </button>
            )}
          </div>
        </div>
      )
    }
    if (card.kind === 'order') {
      const done = approved[card.actionId]
      const lines = [
        { name: 'Lisinopril 10mg · 30s', qty: '24 packs', supplier: 'MedSource', price: 42.6 },
        { name: 'Sertraline 50mg · 30s', qty: '20 packs', supplier: 'Al Safeer', price: 51.0 },
        { name: 'Loratadine 10mg · 10s', qty: '30 packs', supplier: 'Al Safeer', price: 34.5 },
      ]
      return (
        <div className="mt-3 rounded-2xl border border-line bg-white p-4 shadow-xs">
          {lines.map((l) => (
            <div key={l.name} className="flex items-center justify-between border-b border-line/60 py-2.5 last:border-0">
              <div>
                <p className="text-sm font-medium">{l.name}</p>
                <p className="text-xs text-warmgrey">{l.qty} · {l.supplier}</p>
              </div>
              <p className="text-sm font-semibold">{money(l.price)}</p>
            </div>
          ))}
          <div className="mt-3 flex items-center justify-between rounded-xl bg-parchment px-3.5 py-2.5">
            <p className="text-sm">Total <strong>{money(128.1)}</strong> · arrives by Thu</p>
            {done ? (
              <span className="flex items-center gap-1.5 text-sm font-medium text-brand-deep"><Check className="h-4 w-4" /> Placed</span>
            ) : (
              <button onClick={() => doApprove(card.actionId)} className="rounded-full bg-ink px-3.5 py-1.5 text-sm font-medium text-parchment transition hover:bg-brand-deep">
                Place all three
              </button>
            )}
          </div>
        </div>
      )
    }
    // week
    const max = Math.max(...week.map((w) => w.v))
    return (
      <div className="mt-3 rounded-2xl border border-line bg-white p-4 shadow-xs">
        <div className="flex items-center justify-between">
          <div>
            <p className="font-display text-2xl font-medium">{money(8412)}</p>
            <p className="text-xs text-warmgrey">sold this week · up 6%</p>
          </div>
          <div className="flex flex-wrap justify-end gap-1.5">
            <Chip tone="brand">Friday best · {money(1490)}</Chip>
            <Chip tone="honey">Antihistamines +38%</Chip>
          </div>
        </div>
        <div className="mt-4 flex gap-2">
          {week.map((w) => (
            <div key={w.d} className="flex flex-1 flex-col items-center gap-1.5">
              <div className="flex h-16 w-full items-end">
                <div
                  className={cn('w-full rounded-md', w.d === 'Today' ? 'bg-brand' : 'bg-brand/20')}
                  style={{ height: `${(w.v / max) * 100}%` }}
                />
              </div>
              <span className="text-[10px] font-medium text-warmgrey">{w.d}</span>
            </div>
          ))}
        </div>
      </div>
    )
  }

  return (
    <div className="grid gap-8 lg:grid-cols-3">
      {/* Chat column */}
      <div className="lg:col-span-2">
        <h1 className="font-display text-4xl font-medium tracking-tight">Ask Pim.</h1>
        <p className="mt-1 text-sm text-warmgrey">
          Ask anything about the shop — or just tell Pim what to do.
        </p>

        <div className="mt-8 space-y-6">
          <AnimatePresence initial={false}>
            {msgs.map((m) => (
              <motion.div
                key={m.id}
                initial={{ opacity: 0, y: 10 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.3 }}
                className={cn('flex gap-3', m.role === 'user' && 'justify-end')}
              >
                {m.role === 'pim' && <PimAvatar size={34} className="mt-0.5" />}
                <div className={cn('max-w-xl', m.role === 'user' && 'order-first')}>
                  {m.text && (
                    <div
                      className={cn(
                        m.role === 'user'
                          ? 'rounded-3xl rounded-br-md bg-ink px-5 py-3 text-[15px] leading-relaxed text-parchment'
                          : 'px-1 pt-1 text-[15px] leading-relaxed text-ink',
                      )}
                    >
                      {m.text}
                    </div>
                  )}
                  {m.card && renderCard(m.card)}
                </div>
              </motion.div>
            ))}
          </AnimatePresence>

          {typing && (
            <div className="flex items-center gap-3">
              <PimAvatar size={34} />
              <div className="flex items-center gap-1.5 rounded-full bg-white px-4 py-3 shadow-xs">
                <span className="typing-dot" />
                <span className="typing-dot" />
                <span className="typing-dot" />
              </div>
            </div>
          )}
          <div ref={bottomRef} />
        </div>

        {/* Suggestions */}
        <div className="mt-8 flex flex-wrap gap-2">
          {suggestions.map((s) => (
            <button
              key={s}
              onClick={() => send(s)}
              className="rounded-full border border-line bg-white px-4 py-2 text-sm font-medium text-ink/80 shadow-xs transition hover:border-brand/40 hover:text-brand-deep"
            >
              {s}
            </button>
          ))}
        </div>

        {/* Input */}
        <div className="sticky bottom-6 mt-6">
          <div className="flex items-center gap-2 rounded-full border border-line bg-white p-2 pl-6 shadow-lift">
            <input
              value={input}
              onChange={(e) => setInput(e.target.value)}
              onKeyDown={(e) => e.key === 'Enter' && send(input)}
              placeholder="Ask about stock, sales, suppliers…"
              className="flex-1 bg-transparent text-[15px] outline-none placeholder:text-warmgrey/70"
            />
            <button
              onClick={() => send(input)}
              disabled={!input.trim() || typing}
              className="flex h-10 w-10 items-center justify-center rounded-full bg-brand text-white transition hover:bg-brand-deep disabled:opacity-30"
            >
              <ArrowUp className="h-5 w-5" />
            </button>
          </div>
        </div>
      </div>

      {/* Right rail */}
      <div className="space-y-4">
        <div className="rounded-3xl border border-line bg-white p-5 shadow-xs">
          <div className="flex items-center gap-2">
            <Sparkles className="h-4 w-4 text-brand" />
            <h2 className="font-display text-lg font-medium">Pim can</h2>
          </div>
          <ul className="mt-3 space-y-2.5 text-sm">
            {[
              { icon: Truck, text: 'Draft and place supplier orders' },
              { icon: Clock, text: 'Watch expiry dates and pace' },
              { icon: ShieldCheck, text: 'File compliance and keep the log' },
              { icon: ClipboardCheck, text: 'Reconcile every sale to the bank' },
            ].map((c) => (
              <li key={c.text} className="flex items-center gap-2.5 text-ink/80">
                <span className="flex h-7 w-7 items-center justify-center rounded-lg bg-brand-soft text-brand">
                  <c.icon className="h-3.5 w-3.5" />
                </span>
                {c.text}
              </li>
            ))}
          </ul>
        </div>

        <div className="rounded-3xl border border-line bg-white p-5 shadow-xs">
          <h2 className="font-display text-lg font-medium">Handled this morning</h2>
          <ul className="mt-3 space-y-2.5">
            {handled.map((h) => (
              <li key={h} className="flex items-start gap-2.5 text-sm text-ink/80">
                <Check className="mt-0.5 h-4 w-4 shrink-0 text-brand" />
                {h}
              </li>
            ))}
          </ul>
          <p className="mt-4 border-t border-line/70 pt-3 text-xs leading-relaxed text-warmgrey">
            Pim acts on its own for routine work, and always asks before spending your money.
          </p>
        </div>
      </div>
    </div>
  )
}
