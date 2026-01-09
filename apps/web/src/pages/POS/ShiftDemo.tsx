import { useState } from 'react'
import { ShiftDashboardPage } from '@/features/pos'
import type { Shift, Terminal } from '@/features/pos'

// Mock terminal data
const mockTerminal: Terminal = {
  id: 'term-001',
  name: 'POS Terminal 1',
  location: 'Main Counter',
}

export function ShiftDemo() {
  const [currentShift, setCurrentShift] = useState<Shift | null>({
    id: 'shift-123',
    shift_number: 42,
    terminal_id: 'term-001',
    cashier_name: 'John Doe',
    opening_cash: '100.000',
    expected_cash: '550.000',
    opened_at: new Date(Date.now() - 4 * 60 * 60 * 1000).toISOString(), // 4 hours ago
    status: 'open',
  })

  const handleOpenShift = async (openingCash: string) => {
    console.log('Opening shift with cash:', openingCash)

    // Simulate API call
    await new Promise((resolve) => setTimeout(resolve, 500))

    const newShift: Shift = {
      id: `shift-${Date.now()}`,
      shift_number: 43,
      terminal_id: mockTerminal.id,
      cashier_name: 'Current User',
      opening_cash: openingCash,
      expected_cash: openingCash,
      opened_at: new Date().toISOString(),
      status: 'open',
    }

    setCurrentShift(newShift)
    alert('Shift opened successfully!')
  }

  const handleCloseShift = async (actualCash: string) => {
    console.log('Closing shift with actual cash:', actualCash)

    if (!currentShift) return

    // Simulate API call
    await new Promise((resolve) => setTimeout(resolve, 500))

    const expected = parseFloat(currentShift.expected_cash || '0')
    const actual = parseFloat(actualCash)
    const variance = (actual - expected).toFixed(3)

    alert(
      `Shift Closed!\n\n` +
        `Expected: ${expected.toFixed(3)} TND\n` +
        `Actual: ${actual.toFixed(3)} TND\n` +
        `Variance: ${variance} TND\n\n` +
        `Z Report generated.`
    )

    setCurrentShift(null)
  }

  const handleCashDeposit = async (amount: string, reason: string) => {
    console.log('Cash deposit:', { amount, reason })

    // Simulate API call
    await new Promise((resolve) => setTimeout(resolve, 300))

    if (currentShift) {
      const newExpected =
        parseFloat(currentShift.expected_cash || '0') - parseFloat(amount)
      setCurrentShift({
        ...currentShift,
        expected_cash: newExpected.toFixed(3),
      })
    }

    alert(`Cash deposit recorded:\n${amount} TND\nReason: ${reason}`)
  }

  const handleCashPayout = async (amount: string, reason: string) => {
    console.log('Cash payout:', { amount, reason })

    // Simulate API call
    await new Promise((resolve) => setTimeout(resolve, 300))

    if (currentShift) {
      const newExpected =
        parseFloat(currentShift.expected_cash || '0') - parseFloat(amount)
      setCurrentShift({
        ...currentShift,
        expected_cash: newExpected.toFixed(3),
      })
    }

    alert(`Cash payout recorded:\n${amount} TND\nReason: ${reason}`)
  }

  const handleGenerateXReport = async () => {
    console.log('Generating X Report...')

    // Simulate API call
    await new Promise((resolve) => setTimeout(resolve, 500))

    alert(
      `X Report Generated\n\n` +
        `Shift #${currentShift?.shift_number}\n` +
        `Time: ${new Date().toLocaleTimeString()}\n\n` +
        `This is a mid-shift snapshot.\n` +
        `Data is not reset.`
    )
  }

  return (
    <div className="h-screen bg-gray-50">
      <ShiftDashboardPage
        terminal={mockTerminal}
        currentShift={currentShift}
        onOpenShift={handleOpenShift}
        onCloseShift={handleCloseShift}
        onCashDeposit={handleCashDeposit}
        onCashPayout={handleCashPayout}
        onGenerateXReport={handleGenerateXReport}
        touchOptimized={false}
      />
    </div>
  )
}
