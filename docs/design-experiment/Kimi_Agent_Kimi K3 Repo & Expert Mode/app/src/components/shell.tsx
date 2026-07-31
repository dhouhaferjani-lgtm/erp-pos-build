import { useState } from 'react'
import { NavLink, Outlet, useNavigate } from 'react-router'
import { format } from 'date-fns'
import {
  Car,
  Check,
  ChevronDown,
  MessageCircle,
  Package,
  Pill,
  Search,
  ShieldCheck,
  ShoppingBag,
  Store,
  Sun,
  Truck,
  Wallet,
  Wrench,
} from 'lucide-react'
import { cn } from '@/lib/utils'
import { PimAvatar } from '@/components/pim'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'

const nav = [
  { to: '/', label: 'Today', icon: Sun, end: true },
  { to: '/ask', label: 'Ask Pim', icon: MessageCircle },
  { to: '/inventory', label: 'Stock', icon: Package },
  { to: '/sell', label: 'Sell', icon: ShoppingBag },
  { to: '/orders', label: 'Orders', icon: Truck },
  { to: '/money', label: 'Money', icon: Wallet },
]

const verticals = [
  { id: 'pharmacy', name: 'Riverside Pharmacy', trade: 'Pharmacy', color: '#177A52', icon: Pill, live: true },
  { id: 'auto', name: 'Haddad Auto Parts', trade: 'Automobile', color: '#C77414', icon: Car, live: false },
  { id: 'repair', name: 'Fix & Go Repair', trade: 'Repair', color: '#3D6BB3', icon: Wrench, live: false },
  { id: 'retail', name: 'Mina’s Mini Mart', trade: 'Retail', color: '#7A4FBF', icon: Store, live: false },
]

function VerticalSwitcher() {
  const [open, setOpen] = useState(false)
  const active = verticals[0]
  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <button className="group flex items-center gap-2.5 rounded-full border border-line bg-white py-1.5 pl-2 pr-3 shadow-xs transition hover:shadow-card">
          <span
            className="flex h-6 w-6 items-center justify-center rounded-full text-white"
            style={{ backgroundColor: active.color }}
          >
            <active.icon className="h-3.5 w-3.5" />
          </span>
          <span className="text-sm font-semibold">{active.name}</span>
          <ChevronDown className={cn('h-4 w-4 text-warmgrey transition-transform', open && 'rotate-180')} />
        </button>
      </PopoverTrigger>
      <PopoverContent align="start" className="w-80 rounded-2xl border-line p-2 shadow-lift">
        <p className="px-3 pb-1.5 pt-2 text-xs font-medium uppercase tracking-wider text-warmgrey">
          Your businesses
        </p>
        {verticals.map((v) => (
          <div
            key={v.id}
            className={cn(
              'flex items-center gap-3 rounded-xl px-3 py-2.5',
              v.live ? 'bg-brand-faint' : 'opacity-70',
            )}
          >
            <span
              className="flex h-8 w-8 items-center justify-center rounded-full text-white"
              style={{ backgroundColor: v.color }}
            >
              <v.icon className="h-4 w-4" />
            </span>
            <div className="min-w-0 flex-1">
              <p className="truncate text-sm font-semibold">{v.name}</p>
              <p className="text-xs text-warmgrey">{v.trade}</p>
            </div>
            {v.live ? (
              <span className="flex items-center gap-1 rounded-full bg-brand-soft px-2 py-0.5 text-xs font-medium text-brand">
                <Check className="h-3 w-3" /> Live
              </span>
            ) : (
              <span className="rounded-full bg-secondary px-2 py-0.5 text-xs font-medium text-warmgrey">
                Soon
              </span>
            )}
          </div>
        ))}
        <p className="px-3 pb-2 pt-2 text-xs leading-relaxed text-warmgrey">
          One Pim, every trade. Each vertical speaks its own language — parts, jobs, or shelves.
        </p>
      </PopoverContent>
    </Popover>
  )
}

export default function AppShell() {
  const navigate = useNavigate()
  const [askDraft, setAskDraft] = useState('')

  return (
    <div className="flex min-h-screen bg-paper paper-grain">
      {/* Sidebar */}
      <aside className="sticky top-0 flex h-screen w-60 shrink-0 flex-col border-r border-line bg-parchment">
        <div className="flex items-center gap-3 px-5 pb-2 pt-6">
          <PimAvatar size={40} />
          <div>
            <p className="font-display text-2xl font-semibold italic leading-none">Pim</p>
            <p className="mt-0.5 text-[11px] font-medium tracking-wide text-warmgrey">minds the shop</p>
          </div>
        </div>

        <nav className="mt-4 flex-1 space-y-0.5 px-3">
          {nav.map((item) => (
            <NavLink
              key={item.to}
              to={item.to}
              end={item.end}
              className={({ isActive }) =>
                cn(
                  'group flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-all',
                  isActive
                    ? 'bg-brand-soft text-brand-deep shadow-xs'
                    : 'text-ink/70 hover:bg-secondary hover:text-ink',
                )
              }
            >
              <item.icon className="h-[18px] w-[18px]" />
              {item.label}
            </NavLink>
          ))}
        </nav>

        <div className="space-y-3 px-4 pb-5">
          <div className="rounded-2xl border border-brand/15 bg-brand-faint p-3.5">
            <div className="flex items-center gap-2 text-brand-deep">
              <ShieldCheck className="h-4 w-4" />
              <p className="text-[13px] font-semibold">Everything’s in order</p>
            </div>
            <p className="mt-1 text-xs leading-relaxed text-brand-deep/70">
              Tax, books &amp; the controlled log are current. Pim is watching.
            </p>
          </div>
          <div className="flex items-center gap-2.5 px-1">
            <div className="flex h-8 w-8 items-center justify-center rounded-full bg-honey-soft font-display text-sm font-semibold italic text-honey">
              N
            </div>
            <div className="min-w-0">
              <p className="truncate text-[13px] font-semibold">Dr. Nadia Rahal</p>
              <p className="text-[11px] text-warmgrey">Owner · Pharmacist</p>
            </div>
          </div>
        </div>
      </aside>

      {/* Main column */}
      <div className="flex min-w-0 flex-1 flex-col">
        <header className="sticky top-0 z-20 flex items-center gap-4 border-b border-line bg-paper/85 px-8 py-3.5 backdrop-blur">
          <VerticalSwitcher />
          <div className="ml-auto flex items-center gap-3">
            <p className="hidden text-sm text-warmgrey lg:block">
              {format(new Date(), 'EEEE, MMMM d')}
            </p>
            <div className="relative">
              <Search className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-warmgrey" />
              <input
                value={askDraft}
                onChange={(e) => setAskDraft(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === 'Enter') {
                    navigate('/ask', { state: { prefill: askDraft } })
                    setAskDraft('')
                  }
                }}
                placeholder="Ask Pim anything…"
                className="w-64 rounded-full border border-line bg-white py-2 pl-10 pr-4 text-sm shadow-xs outline-none transition placeholder:text-warmgrey/70 focus:border-brand/40 focus:ring-2 focus:ring-brand/15"
              />
            </div>
          </div>
        </header>

        <main className="mx-auto w-full max-w-6xl flex-1 px-8 pb-28 pt-8">
          <Outlet />
        </main>
      </div>

      {/* Floating ask — one question away, everywhere */}
      <button
        onClick={() => navigate('/ask')}
        className="fixed bottom-6 right-6 z-30 flex items-center gap-2.5 rounded-full bg-ink py-2.5 pl-3 pr-5 text-sm font-medium text-parchment shadow-lift transition hover:scale-[1.03] hover:bg-brand-deep"
      >
        <PimAvatar size={28} />
        Ask Pim
      </button>
    </div>
  )
}
