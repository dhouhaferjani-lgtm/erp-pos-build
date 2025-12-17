import { useState } from 'react'
import { Link } from 'react-router-dom'
import { usePayments, useRecordPayment, useRefundPayment } from '../hooks/useBilling'
import type { Payment, PaymentStatus, PaymentProvider, RecordPaymentRequest, RefundPaymentRequest } from '../types'

const STATUS_COLORS: Record<PaymentStatus, string> = {
  pending: 'bg-yellow-100 text-yellow-700',
  processing: 'bg-blue-100 text-blue-700',
  requires_action: 'bg-orange-100 text-orange-700',
  succeeded: 'bg-green-100 text-green-700',
  failed: 'bg-red-100 text-red-700',
  cancelled: 'bg-gray-100 text-gray-500',
  refunded: 'bg-purple-100 text-purple-700',
  partially_refunded: 'bg-purple-100 text-purple-600',
}

const PROVIDER_LABELS: Record<PaymentProvider, string> = {
  stripe: 'Stripe',
  paypal: 'PayPal',
  klarna: 'Klarna',
  sepa_transfer: 'SEPA Transfer',
  flouci: 'Flouci',
  click_to_pay: 'Click to Pay',
  konnect: 'Konnect',
  manual: 'Manual',
  bank_transfer: 'Bank Transfer',
  cash: 'Cash',
  check: 'Check',
}

function formatDate(dateStr: string | null): string {
  if (!dateStr) return '-'
  return new Date(dateStr).toLocaleDateString('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  })
}

function formatCurrency(amount: string | number, currency = 'EUR'): string {
  const numAmount = typeof amount === 'string' ? parseFloat(amount) : amount
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency,
  }).format(numAmount)
}

export function PaymentsPage() {
  const [statusFilter, setStatusFilter] = useState<string>('')
  const [providerFilter, setProviderFilter] = useState<string>('')
  const [showRecordModal, setShowRecordModal] = useState(false)
  const [showRefundModal, setShowRefundModal] = useState(false)
  const [selectedPayment, setSelectedPayment] = useState<Payment | null>(null)

  const { data: paymentsData, isLoading } = usePayments({
    ...(statusFilter && { status: statusFilter }),
    ...(providerFilter && { provider: providerFilter }),
    per_page: 50,
  })

  const recordPayment = useRecordPayment()
  const refundPayment = useRefundPayment()

  // Form state for recording payment
  const [recordForm, setRecordForm] = useState<{
    tenant_id: string
    invoice_id: string
    amount: string
    provider: 'manual' | 'bank_transfer' | 'cash' | 'check'
    reference_number: string
    payment_date: string
    notes: string
  }>({
    tenant_id: '',
    invoice_id: '',
    amount: '',
    provider: 'manual',
    reference_number: '',
    payment_date: '',
    notes: '',
  })

  // Form state for refund
  const [refundForm, setRefundForm] = useState<{
    amount: string
    reason: string
  }>({
    amount: '',
    reason: '',
  })

  const handleRecordPayment = () => {
    const data: RecordPaymentRequest = {
      tenant_id: recordForm.tenant_id,
      amount: parseFloat(recordForm.amount),
      provider: recordForm.provider,
      ...(recordForm.invoice_id && { invoice_id: recordForm.invoice_id }),
      ...(recordForm.reference_number && { reference_number: recordForm.reference_number }),
      ...(recordForm.payment_date && { payment_date: recordForm.payment_date }),
      ...(recordForm.notes && { notes: recordForm.notes }),
    }

    recordPayment.mutate(data, {
      onSuccess: () => {
        setShowRecordModal(false)
        setRecordForm({
          tenant_id: '',
          invoice_id: '',
          amount: '',
          provider: 'manual',
          reference_number: '',
          payment_date: '',
          notes: '',
        })
      },
    })
  }

  const handleRefund = () => {
    if (!selectedPayment) return

    const data: RefundPaymentRequest = {
      ...(refundForm.amount && { amount: parseFloat(refundForm.amount) }),
      ...(refundForm.reason && { reason: refundForm.reason }),
    }

    refundPayment.mutate(
      { id: selectedPayment.id, data },
      {
        onSuccess: () => {
          setShowRefundModal(false)
          setSelectedPayment(null)
          setRefundForm({ amount: '', reason: '' })
        },
      }
    )
  }

  if (isLoading) {
    return (
      <div className="flex h-screen items-center justify-center">
        <div className="text-gray-500">Loading payments...</div>
      </div>
    )
  }

  return (
    <div className="p-8">
      <div className="mx-auto max-w-7xl">
        <div className="mb-8 flex items-center justify-between">
          <div>
            <Link
              to="/admin/billing"
              className="text-sm text-blue-600 hover:text-blue-800"
            >
              &larr; Back to Billing
            </Link>
            <h1 className="mt-2 text-3xl font-bold text-gray-900">Payments</h1>
          </div>
          <div className="flex items-center gap-4">
            <span className="text-sm text-gray-500">
              {paymentsData?.total ?? 0} total payments
            </span>
            <button
              onClick={() => setShowRecordModal(true)}
              className="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
            >
              Record Payment
            </button>
          </div>
        </div>

        {/* Filters */}
        <div className="mb-6 flex gap-4">
          <select
            value={statusFilter}
            onChange={(e) => setStatusFilter(e.target.value)}
            className="rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
          >
            <option value="">All Statuses</option>
            <option value="pending">Pending</option>
            <option value="processing">Processing</option>
            <option value="succeeded">Succeeded</option>
            <option value="failed">Failed</option>
            <option value="refunded">Refunded</option>
            <option value="partially_refunded">Partially Refunded</option>
          </select>

          <select
            value={providerFilter}
            onChange={(e) => setProviderFilter(e.target.value)}
            className="rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
          >
            <option value="">All Providers</option>
            <option value="stripe">Stripe</option>
            <option value="paypal">PayPal</option>
            <option value="manual">Manual</option>
            <option value="bank_transfer">Bank Transfer</option>
            <option value="cash">Cash</option>
            <option value="check">Check</option>
          </select>
        </div>

        {/* Table */}
        <div className="overflow-hidden rounded-lg bg-white shadow">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                  Payment
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                  Tenant
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                  Provider
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                  Status
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                  Amount
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                  Date
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                  Actions
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 bg-white">
              {paymentsData?.data.map((payment) => (
                <tr key={payment.id} className="hover:bg-gray-50">
                  <td className="whitespace-nowrap px-6 py-4">
                    <div className="font-mono text-sm text-gray-900">
                      {payment.id.slice(0, 8)}...
                    </div>
                    {payment.reference_number && (
                      <div className="text-sm text-gray-500">
                        Ref: {payment.reference_number}
                      </div>
                    )}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4">
                    <div className="font-medium text-gray-900">
                      {payment.tenant?.name ?? 'Unknown'}
                    </div>
                    <div className="text-sm text-gray-500">
                      {payment.tenant?.email ?? ''}
                    </div>
                  </td>
                  <td className="whitespace-nowrap px-6 py-4">
                    <span className="text-gray-900">
                      {PROVIDER_LABELS[payment.provider] ?? payment.provider}
                    </span>
                    {payment.payment_method_type && (
                      <div className="text-sm text-gray-500">
                        {payment.payment_method_type}
                      </div>
                    )}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4">
                    <span
                      className={`inline-flex rounded-full px-2 py-1 text-xs font-semibold ${STATUS_COLORS[payment.status]}`}
                    >
                      {payment.status.replace('_', ' ')}
                    </span>
                    {payment.error_message && (
                      <div className="mt-1 text-xs text-red-600">
                        {payment.error_message}
                      </div>
                    )}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4">
                    <div className="font-medium text-gray-900">
                      {formatCurrency(payment.amount, payment.currency)}
                    </div>
                    {parseFloat(payment.fee) > 0 && (
                      <div className="text-sm text-gray-500">
                        Fee: {formatCurrency(payment.fee, payment.currency)}
                      </div>
                    )}
                    {parseFloat(payment.refunded_amount) > 0 && (
                      <div className="text-sm text-purple-600">
                        Refunded:{' '}
                        {formatCurrency(payment.refunded_amount, payment.currency)}
                      </div>
                    )}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                    {formatDate(payment.paid_at ?? payment.payment_date)}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4">
                    <div className="flex gap-2">
                      <button
                        onClick={() => setSelectedPayment(payment)}
                        className="text-sm text-blue-600 hover:text-blue-800"
                      >
                        View
                      </button>
                      {payment.status === 'succeeded' &&
                        parseFloat(payment.refunded_amount) <
                          parseFloat(payment.amount) && (
                          <button
                            onClick={() => {
                              setSelectedPayment(payment)
                              setShowRefundModal(true)
                            }}
                            className="text-sm text-red-600 hover:text-red-800"
                          >
                            Refund
                          </button>
                        )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>

          {paymentsData?.data.length === 0 && (
            <div className="py-12 text-center text-gray-500">
              No payments found
            </div>
          )}
        </div>

        {/* Payment Details Modal */}
        {selectedPayment && !showRefundModal && (
          <div className="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
            <div className="max-h-[90vh] w-full max-w-lg overflow-auto rounded-lg bg-white p-6 shadow-xl">
              <h2 className="mb-4 text-xl font-bold text-gray-900">
                Payment Details
              </h2>

              <div className="space-y-3">
                <div className="flex justify-between">
                  <span className="text-gray-500">ID:</span>
                  <span className="font-mono text-sm">{selectedPayment.id}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-gray-500">Tenant:</span>
                  <span>{selectedPayment.tenant?.name}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-gray-500">Provider:</span>
                  <span>
                    {PROVIDER_LABELS[selectedPayment.provider] ??
                      selectedPayment.provider}
                  </span>
                </div>
                <div className="flex justify-between">
                  <span className="text-gray-500">Status:</span>
                  <span
                    className={`rounded-full px-2 py-0.5 text-xs font-semibold ${STATUS_COLORS[selectedPayment.status]}`}
                  >
                    {selectedPayment.status}
                  </span>
                </div>
                <div className="flex justify-between">
                  <span className="text-gray-500">Amount:</span>
                  <span>
                    {formatCurrency(
                      selectedPayment.amount,
                      selectedPayment.currency
                    )}
                  </span>
                </div>
                <div className="flex justify-between">
                  <span className="text-gray-500">Fee:</span>
                  <span>
                    {formatCurrency(selectedPayment.fee, selectedPayment.currency)}
                  </span>
                </div>
                <div className="flex justify-between">
                  <span className="text-gray-500">Net Amount:</span>
                  <span>
                    {formatCurrency(
                      selectedPayment.net_amount,
                      selectedPayment.currency
                    )}
                  </span>
                </div>
                {parseFloat(selectedPayment.refunded_amount) > 0 && (
                  <div className="flex justify-between">
                    <span className="text-gray-500">Refunded:</span>
                    <span className="text-purple-600">
                      {formatCurrency(
                        selectedPayment.refunded_amount,
                        selectedPayment.currency
                      )}
                    </span>
                  </div>
                )}
                {selectedPayment.reference_number && (
                  <div className="flex justify-between">
                    <span className="text-gray-500">Reference:</span>
                    <span>{selectedPayment.reference_number}</span>
                  </div>
                )}
                {selectedPayment.provider_payment_id && (
                  <div className="flex justify-between">
                    <span className="text-gray-500">Provider ID:</span>
                    <span className="font-mono text-xs">
                      {selectedPayment.provider_payment_id}
                    </span>
                  </div>
                )}
                <div className="flex justify-between">
                  <span className="text-gray-500">Payment Date:</span>
                  <span>
                    {formatDate(
                      selectedPayment.paid_at ?? selectedPayment.payment_date
                    )}
                  </span>
                </div>
                {selectedPayment.recorder && (
                  <div className="flex justify-between">
                    <span className="text-gray-500">Recorded By:</span>
                    <span>{selectedPayment.recorder.name}</span>
                  </div>
                )}
                {selectedPayment.invoice && (
                  <div className="border-t pt-3">
                    <span className="text-gray-500">Invoice:</span>
                    <div className="mt-1">
                      <span className="font-medium">
                        {selectedPayment.invoice.number}
                      </span>
                      <span className="ml-2 text-gray-500">
                        (
                        {formatCurrency(
                          selectedPayment.invoice.total,
                          selectedPayment.invoice.currency
                        )}
                        )
                      </span>
                    </div>
                  </div>
                )}
                {selectedPayment.error_message && (
                  <div className="border-t pt-3">
                    <span className="text-gray-500">Error:</span>
                    <p className="mt-1 text-sm text-red-600">
                      {selectedPayment.error_code}: {selectedPayment.error_message}
                    </p>
                  </div>
                )}
              </div>

              <div className="mt-6 flex justify-end gap-3">
                {selectedPayment.status === 'succeeded' &&
                  parseFloat(selectedPayment.refunded_amount) <
                    parseFloat(selectedPayment.amount) && (
                    <button
                      onClick={() => setShowRefundModal(true)}
                      className="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700"
                    >
                      Refund
                    </button>
                  )}
                <button
                  onClick={() => setSelectedPayment(null)}
                  className="rounded-md bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200"
                >
                  Close
                </button>
              </div>
            </div>
          </div>
        )}

        {/* Record Payment Modal */}
        {showRecordModal && (
          <div className="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
            <div className="max-h-[90vh] w-full max-w-lg overflow-auto rounded-lg bg-white p-6 shadow-xl">
              <h2 className="mb-4 text-xl font-bold text-gray-900">
                Record Payment
              </h2>

              <div className="space-y-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700">
                    Tenant ID *
                  </label>
                  <input
                    type="text"
                    value={recordForm.tenant_id}
                    onChange={(e) =>
                      setRecordForm({ ...recordForm, tenant_id: e.target.value })
                    }
                    placeholder="Enter tenant UUID"
                    className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                  />
                </div>

                <div>
                  <label className="block text-sm font-medium text-gray-700">
                    Invoice ID (optional)
                  </label>
                  <input
                    type="text"
                    value={recordForm.invoice_id}
                    onChange={(e) =>
                      setRecordForm({ ...recordForm, invoice_id: e.target.value })
                    }
                    placeholder="Enter invoice UUID to allocate payment"
                    className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                  />
                </div>

                <div>
                  <label className="block text-sm font-medium text-gray-700">
                    Amount *
                  </label>
                  <input
                    type="number"
                    step="0.01"
                    value={recordForm.amount}
                    onChange={(e) =>
                      setRecordForm({ ...recordForm, amount: e.target.value })
                    }
                    placeholder="0.00"
                    className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                  />
                </div>

                <div>
                  <label className="block text-sm font-medium text-gray-700">
                    Payment Method *
                  </label>
                  <select
                    value={recordForm.provider}
                    onChange={(e) =>
                      setRecordForm({
                        ...recordForm,
                        provider: e.target.value as
                          | 'manual'
                          | 'bank_transfer'
                          | 'cash'
                          | 'check',
                      })
                    }
                    className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                  >
                    <option value="manual">Manual</option>
                    <option value="bank_transfer">Bank Transfer</option>
                    <option value="cash">Cash</option>
                    <option value="check">Check</option>
                  </select>
                </div>

                <div>
                  <label className="block text-sm font-medium text-gray-700">
                    Reference Number
                  </label>
                  <input
                    type="text"
                    value={recordForm.reference_number}
                    onChange={(e) =>
                      setRecordForm({
                        ...recordForm,
                        reference_number: e.target.value,
                      })
                    }
                    placeholder="Check number, transfer reference, etc."
                    className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                  />
                </div>

                <div>
                  <label className="block text-sm font-medium text-gray-700">
                    Payment Date
                  </label>
                  <input
                    type="date"
                    value={recordForm.payment_date}
                    onChange={(e) =>
                      setRecordForm({
                        ...recordForm,
                        payment_date: e.target.value,
                      })
                    }
                    className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                  />
                </div>

                <div>
                  <label className="block text-sm font-medium text-gray-700">
                    Notes
                  </label>
                  <textarea
                    value={recordForm.notes}
                    onChange={(e) =>
                      setRecordForm({ ...recordForm, notes: e.target.value })
                    }
                    rows={3}
                    className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                  />
                </div>
              </div>

              <div className="mt-6 flex justify-end gap-3">
                <button
                  onClick={() => setShowRecordModal(false)}
                  className="rounded-md bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200"
                >
                  Cancel
                </button>
                <button
                  onClick={handleRecordPayment}
                  disabled={recordPayment.isPending}
                  className="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
                >
                  {recordPayment.isPending ? 'Recording...' : 'Record Payment'}
                </button>
              </div>
            </div>
          </div>
        )}

        {/* Refund Modal */}
        {showRefundModal && selectedPayment && (
          <div className="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
            <div className="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
              <h2 className="mb-4 text-xl font-bold text-gray-900">
                Refund Payment
              </h2>

              <div className="mb-4 rounded-md bg-yellow-50 p-4">
                <p className="text-sm text-yellow-800">
                  Refunding payment of{' '}
                  <strong>
                    {formatCurrency(
                      selectedPayment.amount,
                      selectedPayment.currency
                    )}
                  </strong>{' '}
                  for {selectedPayment.tenant?.name}
                </p>
                {parseFloat(selectedPayment.refunded_amount) > 0 && (
                  <p className="mt-1 text-sm text-yellow-700">
                    Already refunded:{' '}
                    {formatCurrency(
                      selectedPayment.refunded_amount,
                      selectedPayment.currency
                    )}
                  </p>
                )}
              </div>

              <div className="space-y-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700">
                    Refund Amount (leave empty for full refund)
                  </label>
                  <input
                    type="number"
                    step="0.01"
                    value={refundForm.amount}
                    onChange={(e) =>
                      setRefundForm({ ...refundForm, amount: e.target.value })
                    }
                    placeholder={`Max: ${parseFloat(selectedPayment.amount) - parseFloat(selectedPayment.refunded_amount)}`}
                    className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                  />
                </div>

                <div>
                  <label className="block text-sm font-medium text-gray-700">
                    Reason
                  </label>
                  <textarea
                    value={refundForm.reason}
                    onChange={(e) =>
                      setRefundForm({ ...refundForm, reason: e.target.value })
                    }
                    rows={3}
                    placeholder="Reason for refund..."
                    className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                  />
                </div>
              </div>

              <div className="mt-6 flex justify-end gap-3">
                <button
                  onClick={() => {
                    setShowRefundModal(false)
                    setRefundForm({ amount: '', reason: '' })
                  }}
                  className="rounded-md bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200"
                >
                  Cancel
                </button>
                <button
                  onClick={handleRefund}
                  disabled={refundPayment.isPending}
                  className="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-50"
                >
                  {refundPayment.isPending ? 'Processing...' : 'Process Refund'}
                </button>
              </div>
            </div>
          </div>
        )}
      </div>
    </div>
  )
}
