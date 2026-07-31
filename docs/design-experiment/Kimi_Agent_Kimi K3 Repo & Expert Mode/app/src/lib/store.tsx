import { createContext, useContext, useMemo, useState, type ReactNode } from 'react'

// Tiny shared store so actions taken in one place (Today, Ask) show up in
// others (Orders, Money) — enough to make the prototype feel alive.
type Store = {
  approved: Record<string, boolean>
  approve: (id: string) => void
  dismissed: Record<string, boolean>
  dismiss: (id: string) => void
  saleCount: number
  saleTotal: number
  addSale: (amount: number) => void
}

const Ctx = createContext<Store | null>(null)

export function StoreProvider({ children }: { children: ReactNode }) {
  const [approved, setApproved] = useState<Record<string, boolean>>({})
  const [dismissed, setDismissed] = useState<Record<string, boolean>>({})
  const [saleCount, setSaleCount] = useState(0)
  const [saleTotal, setSaleTotal] = useState(0)

  const value = useMemo<Store>(
    () => ({
      approved,
      approve: (id) => setApproved((s) => ({ ...s, [id]: true })),
      dismissed,
      dismiss: (id) => setDismissed((s) => ({ ...s, [id]: true })),
      saleCount,
      saleTotal,
      addSale: (amount) => {
        setSaleCount((c) => c + 1)
        setSaleTotal((t) => t + amount)
      },
    }),
    [approved, dismissed, saleCount, saleTotal],
  )

  return <Ctx.Provider value={value}>{children}</Ctx.Provider>
}

export function useStore() {
  const s = useContext(Ctx)
  if (!s) throw new Error('useStore outside provider')
  return s
}
