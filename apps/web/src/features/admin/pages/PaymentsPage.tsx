import { useState } from 'react'
import { Link } from 'react-router-dom'
import { usePayments, useRecordPayment, useRefundPayment } from '../hooks/useBilling'
import type { Payment, PaymentStatus, PaymentProvider, RecordPaymentRequest, RefundPaymentRequest } from '../types'
import { StatusBadge, type StatusTone } from '@/components/atoms/StatusBadge/StatusBadge'
import { PageHeader } from '@/components/molecules/PageHeader'
import { colorClasses } from '@/lib/designTokens'
import { DataTable } from '@/components/molecules/DataTable/DataTable'

const STATUS_TONES: Record<PaymentStatus, StatusTone> = {
  pending: 'warning',
  processing: 'info',
  requires_action: 'warning',
  succeeded: 'success',
  failed: 'danger',
  cancelled: 'neutral',
  refunded: 'info',
  partially_refunded: 'info',
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
        <div className={`${colorClasses.textGray500}`}>Loading payments...</div>
      </div>
    )
  }

  return (
    <div className="p-8">
      <div className="mx-auto max-w-7xl">
        <PageHeader
          title="Payments"
          breadcrumb={
            <Link
              to="/admin/billing"
              className={`text-sm ${colorClasses.textBlue600} ${colorClasses.hoverTextBlue800}`}
            >
              &larr; Back to Billing
            </Link>
          }
          actions={
            <>
              <span className={`text-sm ${colorClasses.textGray500}`}>
                {paymentsData?.total ?? 0} total payments
              </span>
              <button
                onClick={() => { setShowRecordModal(true); }}
                className={`rounded-md ${colorClasses.bgBlue600} px-4 py-2 text-sm font-medium text-white ${colorClasses.hoverBgBlue700}`}
              >
                Record Payment
              </button>
            </>
          }
        />

        {/* Filters */}
        <div className="mb-6 flex gap-4">
          <select
            value={statusFilter}
            onChange={(e) => { setStatusFilter(e.target.value); }}
            className={`rounded-md border ${colorClasses.borderGray300} px-3 py-2 text-sm ${colorClasses.focusBorderBlue500} focus:outline-none focus:ring-1 ${colorClasses.focusRingBlue500}`}
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
            onChange={(e) => { setProviderFilter(e.target.value); }}
            className={`rounded-md border ${colorClasses.borderGray300} px-3 py-2 text-sm ${colorClasses.focusBorderBlue500} focus:outline-none focus:ring-1 ${colorClasses.focusRingBlue500}`}
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
          <DataTable className={`min-w-full divide-y ${colorClasses.divideGray200}`}>
            <thead className={`${colorClasses.bgGray50}`}>
              <tr>
                <th className={`px-6 py-3 text-left text-xs font-medium uppercase tracking-wider ${colorClasses.textGray500}`}>
                  Payment
                </th>
                <th className={`px-6 py-3 text-left text-xs font-medium uppercase tracking-wider ${colorClasses.textGray500}`}>
                  Tenant
                </th>
                <th className={`px-6 py-3 text-left text-xs font-medium uppercase tracking-wider ${colorClasses.textGray500}`}>
                  Provider
                </th>
                <th className={`px-6 py-3 text-left text-xs font-medium uppercase tracking-wider ${colorClasses.textGray500}`}>
                  Status
                </th>
                <th className={`px-6 py-3 text-left text-xs font-medium uppercase tracking-wider ${colorClasses.textGray500}`}>
                  Amount
                </th>
                <th className={`px-6 py-3 text-left text-xs font-medium uppercase tracking-wider ${colorClasses.textGray500}`}>
                  Date
                </th>
                <th className={`px-6 py-3 text-left text-xs font-medium uppercase tracking-wider ${colorClasses.textGray500}`}>
                  Actions
                </th>
              </tr>
            </thead>
            <tbody className={`divide-y ${colorClasses.divideGray200} bg-white`}>
              {paymentsData?.data.map((payment) => (
                <tr key={payment.id} className={`${colorClasses.hoverBgGray50}`}>
                  <td className="whitespace-nowrap px-6 py-4">
                    <div className={`font-mono text-sm ${colorClasses.textGray900}`}>
                      {payment.id.slice(0, 8)}...
                    </div>
                    {payment.reference_number && (
                      <div className={`text-sm ${colorClasses.textGray500}`}>
                        Ref: {payment.reference_number}
                      </div>
                    )}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4">
                    <div className={`font-medium ${colorClasses.textGray900}`}>
                      {payment.tenant?.name ?? 'Unknown'}
                    </div>
                    <div className={`text-sm ${colorClasses.textGray500}`}>
                      {payment.tenant?.email ?? ''}
                    </div>
                  </td>
                  <td className="whitespace-nowrap px-6 py-4">
                    <span className={`${colorClasses.textGray900}`}>
                      {PROVIDER_LABELS[payment.provider] ?? payment.provider}
                    </span>
                    {payment.payment_method_type && (
                      <div className={`text-sm ${colorClasses.textGray500}`}>
                        {payment.payment_method_type}
                      </div>
                    )}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4">
                    <StatusBadge tone={STATUS_TONES[payment.status]}>
                      {payment.status.replace('_', ' ')}
                    </StatusBadge>
                    {payment.error_message && (
                      <div className={`mt-1 text-xs ${colorClasses.textRed600}`}>
                        {payment.error_message}
                      </div>
                    )}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4">
                    <div className={`font-medium ${colorClasses.textGray900}`}>
                      {formatCurrency(payment.amount, payment.currency)}
                    </div>
                    {parseFloat(payment.fee) > 0 && (
                      <div className={`text-sm ${colorClasses.textGray500}`}>
                        Fee: {formatCurrency(payment.fee, payment.currency)}
                      </div>
                    )}
                    {parseFloat(payment.refunded_amount) > 0 && (
                      <div className={`text-sm ${colorClasses.textPurple600}`}>
                        Refunded:{' '}
                        {formatCurrency(payment.refunded_amount, payment.currency)}
                      </div>
                    )}
                  </td>
                  <td className={`whitespace-nowrap px-6 py-4 text-sm ${colorClasses.textGray900}`}>
                    {formatDate(payment.paid_at ?? payment.payment_date)}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4">
                    <div className="flex gap-2">
                      <button
                        onClick={() => { setSelectedPayment(payment); }}
                        className={`text-sm ${colorClasses.textBlue600} ${colorClasses.hoverTextBlue800}`}
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
                            className={`text-sm ${colorClasses.textRed600} ${colorClasses.hoverTextRed800}`}
                          >
                            Refund
                          </button>
                        )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </DataTable>

          {paymentsData?.data.length === 0 && (
            <div className={`py-12 text-center ${colorClasses.textGray500}`}>
              No payments found
            </div>
          )}
        </div>

        {/* Payment Details Modal */}
        {selectedPayment && !showRefundModal && (
          <div className="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
            <div className="max-h-[90vh] w-full max-w-lg overflow-auto rounded-lg bg-white p-6 shadow-xl">
              <h2 className={`mb-4 text-xl font-bold ${colorClasses.textGray900}`}>
                Payment Details
              </h2>

              <div className="space-y-3">
                <div className="flex justify-between">
                  <span className={`${colorClasses.textGray500}`}>ID:</span>
                  <span className="font-mono text-sm">{selectedPayment.id}</span>
                </div>
                <div className="flex justify-between">
                  <span className={`${colorClasses.textGray500}`}>Tenant:</span>
                  <span>{selectedPayment.tenant?.name}</span>
                </div>
                <div className="flex justify-between">
                  <span className={`${colorClasses.textGray500}`}>Provider:</span>
                  <span>
                    {PROVIDER_LABELS[selectedPayment.provider] ??
                      selectedPayment.provider}
                  </span>
                </div>
                <div className="flex justify-between">
                  <span className={`${colorClasses.textGray500}`}>Status:</span>
                  <StatusBadge tone={STATUS_TONES[selectedPayment.status]}>
                    {selectedPayment.status}
                  </StatusBadge>
                </div>
                <div className="flex justify-between">
                  <span className={`${colorClasses.textGray500}`}>Amount:</span>
                  <span>
                    {formatCurrency(
                      selectedPayment.amount,
                      selectedPayment.currency
                    )}
                  </span>
                </div>
                <div className="flex justify-between">
                  <span className={`${colorClasses.textGray500}`}>Fee:</span>
                  <span>
                    {formatCurrency(selectedPayment.fee, selectedPayment.currency)}
                  </span>
                </div>
                <div className="flex justify-between">
                  <span className={`${colorClasses.textGray500}`}>Net Amount:</span>
                  <span>
                    {formatCurrency(
                      selectedPayment.net_amount,
                      selectedPayment.currency
                    )}
                  </span>
                </div>
                {parseFloat(selectedPayment.refunded_amount) > 0 && (
                  <div className="flex justify-between">
                    <span className={`${colorClasses.textGray500}`}>Refunded:</span>
                    <span className={`${colorClasses.textPurple600}`}>
                      {formatCurrency(
                        selectedPayment.refunded_amount,
                        selectedPayment.currency
                      )}
                    </span>
                  </div>
                )}
                {selectedPayment.reference_number && (
                  <div className="flex justify-between">
                    <span className={`${colorClasses.textGray500}`}>Reference:</span>
                    <span>{selectedPayment.reference_number}</span>
                  </div>
                )}
                {selectedPayment.provider_payment_id && (
                  <div className="flex justify-between">
                    <span className={`${colorClasses.textGray500}`}>Provider ID:</span>
                    <span className="font-mono text-xs">
                      {selectedPayment.provider_payment_id}
                    </span>
                  </div>
                )}
                <div className="flex justify-between">
                  <span className={`${colorClasses.textGray500}`}>Payment Date:</span>
                  <span>
                    {formatDate(
                      selectedPayment.paid_at ?? selectedPayment.payment_date
                    )}
                  </span>
                </div>
                {selectedPayment.recorder && (
                  <div className="flex justify-between">
                    <span className={`${colorClasses.textGray500}`}>Recorded By:</span>
                    <span>{selectedPayment.recorder.name}</span>
                  </div>
                )}
                {selectedPayment.invoice && (
                  <div className="border-t pt-3">
                    <span className={`${colorClasses.textGray500}`}>Invoice:</span>
                    <div className="mt-1">
                      <span className="font-medium">
                        {selectedPayment.invoice.number}
                      </span>
                      <span className={`ml-2 ${colorClasses.textGray500}`}>
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
                    <span className={`${colorClasses.textGray500}`}>Error:</span>
                    <p className={`mt-1 text-sm ${colorClasses.textRed600}`}>
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
                      onClick={() => { setShowRefundModal(true); }}
                      className={`rounded-md ${colorClasses.bgRed600} px-4 py-2 text-sm font-medium text-white ${colorClasses.hoverBgRed700}`}
                    >
                      Refund
                    </button>
                  )}
                <button
                  onClick={() => { setSelectedPayment(null); }}
                  className={`rounded-md ${colorClasses.bgGray100} px-4 py-2 text-sm font-medium ${colorClasses.textGray700} ${colorClasses.hoverBgGray200}`}
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
              <h2 className={`mb-4 text-xl font-bold ${colorClasses.textGray900}`}>
                Record Payment
              </h2>

              <div className="space-y-4">
                <div>
                  <label className={`block text-sm font-medium ${colorClasses.textGray700}`}>
                    Tenant ID *
                  </label>
                  <input
                    type="text"
                    value={recordForm.tenant_id}
                    onChange={(e) =>
                      { setRecordForm({ ...recordForm, tenant_id: e.target.value }); }
                    }
                    placeholder="Enter tenant UUID"
                    className={`mt-1 block w-full rounded-md border ${colorClasses.borderGray300} px-3 py-2 text-sm ${colorClasses.focusBorderBlue500} focus:outline-none focus:ring-1 ${colorClasses.focusRingBlue500}`}
                  />
                </div>

                <div>
                  <label className={`block text-sm font-medium ${colorClasses.textGray700}`}>
                    Invoice ID (optional)
                  </label>
                  <input
                    type="text"
                    value={recordForm.invoice_id}
                    onChange={(e) =>
                      { setRecordForm({ ...recordForm, invoice_id: e.target.value }); }
                    }
                    placeholder="Enter invoice UUID to allocate payment"
                    className={`mt-1 block w-full rounded-md border ${colorClasses.borderGray300} px-3 py-2 text-sm ${colorClasses.focusBorderBlue500} focus:outline-none focus:ring-1 ${colorClasses.focusRingBlue500}`}
                  />
                </div>

                <div>
                  <label className={`block text-sm font-medium ${colorClasses.textGray700}`}>
                    Amount *
                  </label>
                  <input
                    type="number"
                    step="0.01"
                    value={recordForm.amount}
                    onChange={(e) =>
                      { setRecordForm({ ...recordForm, amount: e.target.value }); }
                    }
                    placeholder="0.00"
                    className={`mt-1 block w-full rounded-md border ${colorClasses.borderGray300} px-3 py-2 text-sm ${colorClasses.focusBorderBlue500} focus:outline-none focus:ring-1 ${colorClasses.focusRingBlue500}`}
                  />
                </div>

                <div>
                  <label className={`block text-sm font-medium ${colorClasses.textGray700}`}>
                    Payment Method *
                  </label>
                  <select
                    value={recordForm.provider}
                    onChange={(e) =>
                      { setRecordForm({
                        ...recordForm,
                        provider: e.target.value as
                          | 'manual'
                          | 'bank_transfer'
                          | 'cash'
                          | 'check',
                      }); }
                    }
                    className={`mt-1 block w-full rounded-md border ${colorClasses.borderGray300} px-3 py-2 text-sm ${colorClasses.focusBorderBlue500} focus:outline-none focus:ring-1 ${colorClasses.focusRingBlue500}`}
                  >
                    <option value="manual">Manual</option>
                    <option value="bank_transfer">Bank Transfer</option>
                    <option value="cash">Cash</option>
                    <option value="check">Check</option>
                  </select>
                </div>

                <div>
                  <label className={`block text-sm font-medium ${colorClasses.textGray700}`}>
                    Reference Number
                  </label>
                  <input
                    type="text"
                    value={recordForm.reference_number}
                    onChange={(e) =>
                      { setRecordForm({
                        ...recordForm,
                        reference_number: e.target.value,
                      }); }
                    }
                    placeholder="Check number, transfer reference, etc."
                    className={`mt-1 block w-full rounded-md border ${colorClasses.borderGray300} px-3 py-2 text-sm ${colorClasses.focusBorderBlue500} focus:outline-none focus:ring-1 ${colorClasses.focusRingBlue500}`}
                  />
                </div>

                <div>
                  <label className={`block text-sm font-medium ${colorClasses.textGray700}`}>
                    Payment Date
                  </label>
                  <input
                    type="date"
                    value={recordForm.payment_date}
                    onChange={(e) =>
                      { setRecordForm({
                        ...recordForm,
                        payment_date: e.target.value,
                      }); }
                    }
                    className={`mt-1 block w-full rounded-md border ${colorClasses.borderGray300} px-3 py-2 text-sm ${colorClasses.focusBorderBlue500} focus:outline-none focus:ring-1 ${colorClasses.focusRingBlue500}`}
                  />
                </div>

                <div>
                  <label className={`block text-sm font-medium ${colorClasses.textGray700}`}>
                    Notes
                  </label>
                  <textarea
                    value={recordForm.notes}
                    onChange={(e) =>
                      { setRecordForm({ ...recordForm, notes: e.target.value }); }
                    }
                    rows={3}
                    className={`mt-1 block w-full rounded-md border ${colorClasses.borderGray300} px-3 py-2 text-sm ${colorClasses.focusBorderBlue500} focus:outline-none focus:ring-1 ${colorClasses.focusRingBlue500}`}
                  />
                </div>
              </div>

              <div className="mt-6 flex justify-end gap-3">
                <button
                  onClick={() => { setShowRecordModal(false); }}
                  className={`rounded-md ${colorClasses.bgGray100} px-4 py-2 text-sm font-medium ${colorClasses.textGray700} ${colorClasses.hoverBgGray200}`}
                >
                  Cancel
                </button>
                <button
                  onClick={handleRecordPayment}
                  disabled={recordPayment.isPending}
                  className={`rounded-md ${colorClasses.bgBlue600} px-4 py-2 text-sm font-medium text-white ${colorClasses.hoverBgBlue700} disabled:opacity-50`}
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
              <h2 className={`mb-4 text-xl font-bold ${colorClasses.textGray900}`}>
                Refund Payment
              </h2>

              <div className={`mb-4 rounded-md ${colorClasses.bgYellow50} p-4`}>
                <p className={`text-sm ${colorClasses.textYellow800}`}>
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
                  <p className={`mt-1 text-sm ${colorClasses.textYellow700}`}>
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
                  <label className={`block text-sm font-medium ${colorClasses.textGray700}`}>
                    Refund Amount (leave empty for full refund)
                  </label>
                  <input
                    type="number"
                    step="0.01"
                    value={refundForm.amount}
                    onChange={(e) =>
                      { setRefundForm({ ...refundForm, amount: e.target.value }); }
                    }
                    placeholder={`Max: ${parseFloat(selectedPayment.amount) - parseFloat(selectedPayment.refunded_amount)}`}
                    className={`mt-1 block w-full rounded-md border ${colorClasses.borderGray300} px-3 py-2 text-sm ${colorClasses.focusBorderBlue500} focus:outline-none focus:ring-1 ${colorClasses.focusRingBlue500}`}
                  />
                </div>

                <div>
                  <label className={`block text-sm font-medium ${colorClasses.textGray700}`}>
                    Reason
                  </label>
                  <textarea
                    value={refundForm.reason}
                    onChange={(e) =>
                      { setRefundForm({ ...refundForm, reason: e.target.value }); }
                    }
                    rows={3}
                    placeholder="Reason for refund..."
                    className={`mt-1 block w-full rounded-md border ${colorClasses.borderGray300} px-3 py-2 text-sm ${colorClasses.focusBorderBlue500} focus:outline-none focus:ring-1 ${colorClasses.focusRingBlue500}`}
                  />
                </div>
              </div>

              <div className="mt-6 flex justify-end gap-3">
                <button
                  onClick={() => {
                    setShowRefundModal(false)
                    setRefundForm({ amount: '', reason: '' })
                  }}
                  className={`rounded-md ${colorClasses.bgGray100} px-4 py-2 text-sm font-medium ${colorClasses.textGray700} ${colorClasses.hoverBgGray200}`}
                >
                  Cancel
                </button>
                <button
                  onClick={handleRefund}
                  disabled={refundPayment.isPending}
                  className={`rounded-md ${colorClasses.bgRed600} px-4 py-2 text-sm font-medium text-white ${colorClasses.hoverBgRed700} disabled:opacity-50`}
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
