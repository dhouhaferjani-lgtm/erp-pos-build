import { useMemo, useState } from 'react'
import { AnimatePresence, motion } from 'framer-motion'
import {
  BadgeCheck,
  Banknote,
  BookOpen,
  Check,
  CreditCard,
  Landmark,
  Minus,
  Plus,
  ScanLine,
  Search,
  ShieldCheck,
  Trash2,
  User,
  UserRound,
  X,
} from 'lucide-react'
import { money, products } from '@/lib/data'
import { useStore } from '@/lib/store'
import { cn } from '@/lib/utils'

type CartLine = { id: string; qty: number }
type PayMethod = 'cash' | 'card' | 'insurance'

function EntryCard({
  no,
  title,
  lines,
}: {
  no: string
  title: string
  lines: { side: 'Dr' | 'Cr'; account: string; amount: number }[]
}) {
  return (
    <div className="rounded-2xl border border-line bg-white p-4 shadow-xs">
      <p className="text-[13px] font-semibold text-warmgrey">
        Entry {no} · {title}
      </p>
      <div className="mt-2 divide-y divide-line/50">
        {lines.map((l) => (
          <div key={l.account} className="flex items-center gap-3 py-2">
            <span
              className={cn(
                'w-7 rounded px-1 py-0.5 text-center text-[10px] font-bold',
                l.side === 'Dr' ? 'bg-sky-soft text-sky' : 'bg-honey-soft text-honey',
              )}
            >
              {l.side}
            </span>
            <span className="flex-1 text-sm">{l.account}</span>
            <span className="font-mono text-sm tabular-nums">{money(l.amount)}</span>
          </div>
        ))}
      </div>
      <p className="mt-2 flex items-center gap-1.5 border-t border-line/60 pt-2.5 text-xs font-medium text-brand-deep">
        <Check className="h-3.5 w-3.5" /> Balanced — debits equal credits
      </p>
    </div>
  )
}

export default function Sell() {
  const { addSale } = useStore()
  const [query, setQuery] = useState('')
  const [cart, setCart] = useState<CartLine[]>([])
  const [customer, setCustomer] = useState<'walkin' | 'kareem'>('walkin')
  const [method, setMethod] = useState<PayMethod>('card')
  const [receipt, setReceipt] = useState<number | null>(null)
  const [lastSale, setLastSale] = useState<{
    gross: number
    paid: number
    method: PayMethod
    rx: boolean
  } | null>(null)
  const [ledgerOpen, setLedgerOpen] = useState(false)

  const shelf = useMemo(
    () =>
      products.filter((p) =>
        `${p.name} ${p.detail}`.toLowerCase().includes(query.toLowerCase()),
      ),
    [query],
  )

  const lines = cart
    .map((l) => ({ ...l, product: products.find((p) => p.id === l.id)! }))
    .filter((l) => l.product)

  const total = lines.reduce((s, l) => s + l.product.price * l.qty, 0)
  const hasRx = lines.some((l) => l.product.rx)
  const rxVerified = hasRx && customer === 'kareem'
  const copay = rxVerified ? 8.5 : 0

  // The double-entry Pim writes for the sale — same truth as the friendly receipt
  const journal = useMemo(() => {
    if (!lastSale) return null
    const { gross, paid, method: m } = lastSale
    const net = Math.round((gross / 1.05) * 100) / 100
    const tax = Math.round((gross - net) * 100) / 100
    const insurer = Math.round((gross - paid) * 100) / 100
    const cogs = Math.round(gross * 0.48 * 100) / 100
    const saleLines: { side: 'Dr' | 'Cr'; account: string; amount: number }[] = [
      { side: 'Dr', account: m === 'cash' ? 'Cash on hand' : 'Card clearing', amount: paid },
    ]
    if (insurer > 0) saleLines.push({ side: 'Dr', account: 'Insurance receivable', amount: insurer })
    saleLines.push(
      { side: 'Cr', account: 'Sales revenue', amount: net },
      { side: 'Cr', account: 'Sales tax payable', amount: tax },
    )
    const stockLines: { side: 'Dr' | 'Cr'; account: string; amount: number }[] = [
      { side: 'Dr', account: 'Cost of goods sold', amount: cogs },
      { side: 'Cr', account: 'Inventory', amount: cogs },
    ]
    return { saleLines, stockLines }
  }, [lastSale])

  const add = (id: string) =>
    setCart((c) => {
      const found = c.find((l) => l.id === id)
      return found
        ? c.map((l) => (l.id === id ? { ...l, qty: l.qty + 1 } : l))
        : [...c, { id, qty: 1 }]
    })

  const setQty = (id: string, qty: number) =>
    setCart((c) => (qty <= 0 ? c.filter((l) => l.id !== id) : c.map((l) => (l.id === id ? { ...l, qty } : l))))

  const charge = () => {
    const paid = method === 'insurance' ? copay : total
    addSale(paid)
    setReceipt(paid)
    setLastSale({ gross: total, paid, method, rx: rxVerified })
  }

  const reset = () => {
    setReceipt(null)
    setCart([])
    setCustomer('walkin')
    setMethod('card')
    setLastSale(null)
    setLedgerOpen(false)
  }

  const methods: { id: PayMethod; label: string; icon: typeof Banknote; disabled?: boolean }[] = [
    { id: 'cash', label: 'Cash', icon: Banknote },
    { id: 'card', label: 'Card', icon: CreditCard },
    { id: 'insurance', label: 'Insurance', icon: Landmark, disabled: !rxVerified },
  ]

  return (
    <div className="grid gap-6 lg:grid-cols-5">
      {/* Shelf side */}
      <div className="lg:col-span-3">
        <h1 className="font-display text-4xl font-medium tracking-tight">Sell.</h1>
        <p className="mt-1 text-sm text-warmgrey">
          Scan or tap — stock, the books and the receipt take care of themselves.
        </p>

        <div className="relative mt-6">
          <Search className="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-warmgrey" />
          <input
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="Search the shelf, or scan a barcode…"
            className="w-full rounded-2xl border border-line bg-white py-3.5 pl-11 pr-12 text-[15px] shadow-xs outline-none transition placeholder:text-warmgrey/70 focus:border-brand/40 focus:ring-2 focus:ring-brand/15"
          />
          <ScanLine className="absolute right-4 top-1/2 h-5 w-5 -translate-y-1/2 text-warmgrey/60" />
        </div>

        <div className="mt-4 grid grid-cols-2 gap-2.5 xl:grid-cols-3">
          {shelf.map((p) => {
            const inCart = cart.find((l) => l.id === p.id)
            return (
              <button
                key={p.id}
                onClick={() => add(p.id)}
                className={cn(
                  'group relative rounded-2xl border bg-white p-3.5 text-left shadow-xs transition hover:-translate-y-0.5 hover:shadow-card',
                  inCart ? 'border-brand/50 ring-1 ring-brand/30' : 'border-line',
                )}
              >
                <div className="flex items-start justify-between gap-2">
                  <p className="text-sm font-semibold leading-tight">{p.name}</p>
                  {p.rx ? (
                    <span className="rounded bg-sky-soft px-1.5 py-0.5 text-[10px] font-bold tracking-wide text-sky">Rx</span>
                  ) : (
                    <span className="rounded bg-secondary px-1.5 py-0.5 text-[10px] font-bold tracking-wide text-warmgrey">OTC</span>
                  )}
                </div>
                <p className="mt-0.5 line-clamp-1 text-xs text-warmgrey">{p.detail}</p>
                <div className="mt-2.5 flex items-center justify-between">
                  <p className="font-display text-lg font-medium">{money(p.price)}</p>
                  {inCart && (
                    <span className="flex h-5 min-w-5 items-center justify-center rounded-full bg-brand px-1.5 text-[11px] font-bold text-white">
                      {inCart.qty}
                    </span>
                  )}
                </div>
              </button>
            )
          })}
        </div>
      </div>

      {/* Cart side */}
      <div className="lg:col-span-2">
        <div className="sticky top-24 overflow-hidden rounded-3xl border border-line bg-white shadow-card">
          <AnimatePresence mode="wait">
            {receipt !== null ? (
              <motion.div
                key="receipt"
                initial={{ opacity: 0, scale: 0.97 }}
                animate={{ opacity: 1, scale: 1 }}
                exit={{ opacity: 0 }}
                className="flex flex-col items-center px-6 py-12 text-center"
              >
                <span className="flex h-14 w-14 items-center justify-center rounded-full bg-brand-soft text-brand">
                  <Check className="h-7 w-7" />
                </span>
                <p className="mt-4 font-display text-3xl font-medium">{money(receipt)}</p>
                <p className="mt-1 text-sm text-warmgrey">
                  {method === 'insurance' ? 'Copay collected · insurer billed automatically' : `Paid by ${method}`}
                </p>
                <div className="mt-6 w-full space-y-2 rounded-2xl bg-parchment p-4 text-left">
                  {['Stock counted down', 'Books balanced', 'Receipt texted to the customer'].map((t) => (
                    <p key={t} className="flex items-center gap-2 text-[13px] text-ink/75">
                      <Check className="h-3.5 w-3.5 text-brand" /> {t}
                    </p>
                  ))}
                </div>
                <button
                  onClick={() => setLedgerOpen(true)}
                  className="mt-3 flex w-full items-center justify-center gap-2 rounded-full border border-line bg-white py-2.5 text-[13px] font-medium text-ink/70 transition hover:border-brand/40 hover:text-brand-deep"
                >
                  <BookOpen className="h-4 w-4" /> Look underneath — the journal entry
                </button>
                <button
                  onClick={reset}
                  className="mt-6 w-full rounded-full bg-ink py-3 text-sm font-medium text-parchment transition hover:bg-brand-deep"
                >
                  New sale
                </button>
              </motion.div>
            ) : (
              <motion.div key="cart" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}>
                {/* Customer */}
                <div className="border-b border-line/70 p-4">
                  <div className="grid grid-cols-2 gap-2">
                    <button
                      onClick={() => setCustomer('walkin')}
                      className={cn(
                        'flex items-center justify-center gap-2 rounded-xl border py-2.5 text-sm font-medium transition',
                        customer === 'walkin' ? 'border-ink bg-ink text-parchment' : 'border-line bg-white text-ink/70',
                      )}
                    >
                      <User className="h-4 w-4" /> Walk-in
                    </button>
                    <button
                      onClick={() => setCustomer('kareem')}
                      className={cn(
                        'flex items-center justify-center gap-2 rounded-xl border py-2.5 text-sm font-medium transition',
                        customer === 'kareem' ? 'border-ink bg-ink text-parchment' : 'border-line bg-white text-ink/70',
                      )}
                    >
                      <UserRound className="h-4 w-4" /> Kareem H.
                    </button>
                  </div>
                </div>

                {/* Lines */}
                <div className="max-h-64 overflow-y-auto px-4 py-2">
                  {lines.length === 0 ? (
                    <p className="py-8 text-center text-sm text-warmgrey">
                      Nothing here yet — tap products to add them.
                    </p>
                  ) : (
                    lines.map((l) => (
                      <div key={l.id} className="flex items-center gap-3 border-b border-line/50 py-3 last:border-0">
                        <div className="min-w-0 flex-1">
                          <p className="truncate text-sm font-medium">{l.product.name}</p>
                          <p className="text-xs text-warmgrey">{money(l.product.price)}</p>
                        </div>
                        <div className="flex items-center gap-1.5">
                          <button onClick={() => setQty(l.id, l.qty - 1)} className="flex h-7 w-7 items-center justify-center rounded-full border border-line text-ink/70 hover:bg-secondary">
                            {l.qty === 1 ? <Trash2 className="h-3.5 w-3.5" /> : <Minus className="h-3.5 w-3.5" />}
                          </button>
                          <span className="w-6 text-center text-sm font-semibold">{l.qty}</span>
                          <button onClick={() => setQty(l.id, l.qty + 1)} className="flex h-7 w-7 items-center justify-center rounded-full border border-line text-ink/70 hover:bg-secondary">
                            <Plus className="h-3.5 w-3.5" />
                          </button>
                        </div>
                        <p className="w-16 text-right text-sm font-semibold">{money(l.product.price * l.qty)}</p>
                      </div>
                    ))
                  )}
                </div>

                {/* Rx strip */}
                {hasRx && (
                  <div
                    className={cn(
                      'mx-4 mb-3 flex items-start gap-2.5 rounded-xl px-3.5 py-3 text-[13px] leading-snug',
                      rxVerified ? 'bg-brand-faint text-brand-deep' : 'bg-honey-soft/60 text-honey',
                    )}
                  >
                    <BadgeCheck className="mt-0.5 h-4 w-4 shrink-0" />
                    {rxVerified
                      ? 'Prescription #RX-8812 on file and verified. Kareem pays an $8.50 copay — the insurer is billed the rest, automatically.'
                      : 'This basket has a prescription item. Pick a customer with a prescription on file to verify it.'}
                  </div>
                )}

                {/* Payment */}
                <div className="border-t border-line/70 p-4">
                  <div className="grid grid-cols-3 gap-2">
                    {methods.map((m) => (
                      <button
                        key={m.id}
                        disabled={m.disabled}
                        onClick={() => setMethod(m.id)}
                        className={cn(
                          'flex flex-col items-center gap-1 rounded-xl border py-2.5 text-xs font-medium transition',
                          method === m.id ? 'border-brand bg-brand-faint text-brand-deep' : 'border-line bg-white text-ink/70',
                          m.disabled && 'cursor-not-allowed opacity-40',
                        )}
                      >
                        <m.icon className="h-4 w-4" />
                        {m.label}
                      </button>
                    ))}
                  </div>

                  <div className="mt-4 space-y-1.5 text-sm">
                    <div className="flex justify-between text-warmgrey">
                      <span>Subtotal</span>
                      <span>{money(total)}</span>
                    </div>
                    <div className="flex justify-between text-warmgrey">
                      <span>Tax</span>
                      <span>Included — Pim handles it</span>
                    </div>
                    {method === 'insurance' && rxVerified && (
                      <>
                        <div className="flex justify-between text-warmgrey">
                          <span>Insurer covers</span>
                          <span>{money(Math.max(0, total - copay))}</span>
                        </div>
                        <div className="flex justify-between font-medium text-ink">
                          <span>Kareem pays</span>
                          <span>{money(copay)}</span>
                        </div>
                      </>
                    )}
                    <div className="flex items-baseline justify-between pt-2">
                      <span className="font-medium">Total</span>
                      <span className="font-display text-3xl font-medium">
                        {money(method === 'insurance' && rxVerified ? copay : total)}
                      </span>
                    </div>
                  </div>

                  <button
                    onClick={charge}
                    disabled={lines.length === 0 || (hasRx && customer === 'walkin')}
                    className="mt-4 w-full rounded-full bg-brand py-3.5 text-[15px] font-semibold text-white transition hover:bg-brand-deep disabled:cursor-not-allowed disabled:opacity-30"
                  >
                    {lines.length === 0
                      ? 'Charge'
                      : `Charge ${money(method === 'insurance' && rxVerified ? copay : total)}`}
                  </button>
                  {hasRx && customer === 'walkin' && (
                    <p className="mt-2 text-center text-xs text-honey">Verify the prescription first</p>
                  )}
                </div>
              </motion.div>
            )}
          </AnimatePresence>
        </div>
      </div>

      {/* Look underneath — the books behind the friendly receipt */}
      <AnimatePresence>
        {ledgerOpen && journal && lastSale && (
          <>
            <motion.div
              initial={{ opacity: 0 }}
              animate={{ opacity: 1 }}
              exit={{ opacity: 0 }}
              onClick={() => setLedgerOpen(false)}
              className="fixed inset-0 z-40 bg-ink/25 backdrop-blur-[2px]"
            />
            <motion.aside
              initial={{ x: '100%' }}
              animate={{ x: 0 }}
              exit={{ x: '100%' }}
              transition={{ type: 'tween', duration: 0.3, ease: 'easeOut' }}
              className="fixed right-0 top-0 z-50 flex h-full w-full max-w-md flex-col border-l border-line bg-parchment shadow-lift"
            >
              <div className="flex items-start justify-between gap-4 border-b border-line px-6 py-5">
                <div>
                  <p className="flex items-center gap-2 font-display text-xl font-medium">
                    <BookOpen className="h-5 w-5 text-brand" /> What Pim wrote in the books
                  </p>
                  <p className="mt-1 text-[13px] leading-snug text-warmgrey">
                    Written the moment you charged. You never have to read this — but your
                    accountant will love it.
                  </p>
                </div>
                <button
                  onClick={() => setLedgerOpen(false)}
                  className="rounded-full p-1.5 text-warmgrey transition hover:bg-secondary"
                  aria-label="Close"
                >
                  <X className="h-5 w-5" />
                </button>
              </div>

              <div className="flex-1 space-y-4 overflow-y-auto px-6 py-5">
                <EntryCard no="1" title="The sale" lines={journal.saleLines} />
                <EntryCard no="2" title="The stock" lines={journal.stockLines} />

                {lastSale.rx && (
                  <div className="rounded-2xl border border-brand/15 bg-brand-faint p-4">
                    <p className="flex items-center gap-2 text-[13px] font-semibold text-brand-deep">
                      <ShieldCheck className="h-4 w-4" /> Compliance, quietly
                    </p>
                    <p className="mt-1.5 text-[13px] leading-relaxed text-brand-deep/80">
                      Prescription #RX-8812 was linked to Kareem Haddad and the dispense was logged.
                      Controlled-substances register: no entry needed for this sale.
                    </p>
                  </div>
                )}

                <p className="px-1 text-xs leading-relaxed text-warmgrey">
                  Every sale, order and payment writes entries like these — thousands a month, each
                  one matched to the bank. The friendly numbers you see are simply the summary of
                  this. Same truth, two altitudes.
                </p>
              </div>
            </motion.aside>
          </>
        )}
      </AnimatePresence>
    </div>
  )
}
