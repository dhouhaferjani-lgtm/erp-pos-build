import { Navigate, Route, Routes } from 'react-router'
import AppShell from '@/components/shell'
import { StoreProvider } from '@/lib/store'
import Today from '@/pages/Today'
import Ask from '@/pages/Ask'
import Inventory from '@/pages/Inventory'
import Sell from '@/pages/Sell'
import Orders from '@/pages/Orders'
import Money from '@/pages/Money'

export default function App() {
  return (
    <StoreProvider>
      <Routes>
        <Route element={<AppShell />}>
          <Route path="/" element={<Today />} />
          <Route path="/ask" element={<Ask />} />
          <Route path="/inventory" element={<Inventory />} />
          <Route path="/sell" element={<Sell />} />
          <Route path="/orders" element={<Orders />} />
          <Route path="/money" element={<Money />} />
          <Route path="*" element={<Navigate to="/" replace />} />
        </Route>
      </Routes>
    </StoreProvider>
  )
}
