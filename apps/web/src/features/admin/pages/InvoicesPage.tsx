import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useInvoices, useCreateInvoice } from '../hooks/useBilling'
import { getInvoiceDownloadUrl } from '../api'
import type { Invoice, InvoiceStatus, CreateInvoiceRequest } from '../types'

const STATUS_COLORS: Record<InvoiceStatus, string> = {
  draft: 'bg-gray-100 text-gray-700',
  pending: 'bg-yellow-100 text-yellow-700',
  sent: 'bg-blue-100 text-blue-700',
  paid: 'bg-green-100 text-green-700',
  partially_paid: 'bg-teal-100 text-teal-700',
  overdue: 'bg-red-100 text-red-700',
  cancelled: 'bg-gray-100 text-gray-500',
  refunded: 'bg-purple-100 text-purple-700',
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

export function InvoicesPage() {
  const [statusFilter, setStatusFilter] = useState<string>('')
  const [showCreateModal, setShowCreateModal] = useState(false)
  const [selectedInvoice, setSelectedInvoice] = useState<Invoice | null>(null)

  const { data: invoicesData, isLoading } = useInvoices({
    ...(statusFilter && { status: statusFilter }),
    per_page: 50,
  })

  const createInvoice = useCreateInvoice()

  // Form state for creating invoice
  const [createForm, setCreateForm] = useState<{
    tenant_id: string
    items: Array<{ description: string; amount: string; quantity: string }>
    notes: string
    due_date: string
  }>({
    tenant_id: '',
    items: [{ description: '', amount: '', quantity: '1' }],
    notes: '',
    due_date: '',
  })

  const handleAddItem = () => {
    setCreateForm({
      ...createForm,
      items: [...createForm.items, { description: '', amount: '', quantity: '1' }],
    })
  }

  const handleRemoveItem = (index: number) => {
    setCreateForm({
      ...createForm,
      items: createForm.items.filter((_, i) => i !== index),
    })
  }

  const handleItemChange = (
    index: number,
    field: 'description' | 'amount' | 'quantity',
    value: string
  ) => {
    const newItems = [...createForm.items]
    newItems[index][field] = value
    setCreateForm({ ...createForm, items: newItems })
  }

  const handleCreateInvoice = () => {
    const data: CreateInvoiceRequest = {
      tenant_id: createForm.tenant_id,
      items: createForm.items.map((item) => ({
        description: item.description,
        amount: parseFloat(item.amount),
        quantity: parseFloat(item.quantity),
      })),
      ...(createForm.notes && { notes: createForm.notes }),
      ...(createForm.due_date && { due_date: createForm.due_date }),
    }

    createInvoice.mutate(data, {
      onSuccess: () => {
        setShowCreateModal(false)
        setCreateForm({
          tenant_id: '',
          items: [{ description: '', amount: '', quantity: '1' }],
          notes: '',
          due_date: '',
        })
      },
    })
  }

  if (isLoading) {
    return (
      <div className="flex h-screen items-center justify-center">
        <div className="text-gray-500">Loading invoices...</div>
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
            <h1 className="mt-2 text-3xl font-bold text-gray-900">Invoices</h1>
          </div>
          <div className="flex items-center gap-4">
            <span className="text-sm text-gray-500">
              {invoicesData?.total ?? 0} total invoices
            </span>
            <button
              onClick={() => setShowCreateModal(true)}
              className="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
            >
              Create Invoice
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
            <option value="draft">Draft</option>
            <option value="pending">Pending</option>
            <option value="sent">Sent</option>
            <option value="paid">Paid</option>
            <option value="partially_paid">Partially Paid</option>
            <option value="overdue">Overdue</option>
            <option value="cancelled">Cancelled</option>
            <option value="refunded">Refunded</option>
          </select>
        </div>

        {/* Table */}
        <div className="overflow-hidden rounded-lg bg-white shadow">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                  Invoice
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                  Tenant
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                  Status
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                  Amount
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                  Due Date
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                  Actions
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 bg-white">
              {invoicesData?.data.map((invoice) => (
                <tr key={invoice.id} className="hover:bg-gray-50">
                  <td className="whitespace-nowrap px-6 py-4">
                    <div className="font-medium text-gray-900">
                      {invoice.number}
                    </div>
                    <div className="text-sm text-gray-500">
                      {formatDate(invoice.invoice_date)}
                    </div>
                  </td>
                  <td className="whitespace-nowrap px-6 py-4">
                    <div className="font-medium text-gray-900">
                      {invoice.tenant?.name ?? 'Unknown'}
                    </div>
                    <div className="text-sm text-gray-500">
                      {invoice.billing_email ?? invoice.tenant?.email ?? ''}
                    </div>
                  </td>
                  <td className="whitespace-nowrap px-6 py-4">
                    <span
                      className={`inline-flex rounded-full px-2 py-1 text-xs font-semibold ${STATUS_COLORS[invoice.status]}`}
                    >
                      {invoice.status.replace('_', ' ')}
                    </span>
                  </td>
                  <td className="whitespace-nowrap px-6 py-4">
                    <div className="font-medium text-gray-900">
                      {formatCurrency(invoice.total, invoice.currency)}
                    </div>
                    {parseFloat(invoice.amount_due) > 0 && (
                      <div className="text-sm text-red-600">
                        Due: {formatCurrency(invoice.amount_due, invoice.currency)}
                      </div>
                    )}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                    {formatDate(invoice.due_date)}
                    {invoice.status === 'overdue' && (
                      <div className="text-xs text-red-600">Overdue</div>
                    )}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4">
                    <div className="flex gap-2">
                      <button
                        onClick={() => setSelectedInvoice(invoice)}
                        className="text-sm text-blue-600 hover:text-blue-800"
                      >
                        View
                      </button>
                      {invoice.pdf_path && (
                        <a
                          href={getInvoiceDownloadUrl(invoice.id)}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="text-sm text-green-600 hover:text-green-800"
                        >
                          Download
                        </a>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>

          {invoicesData?.data.length === 0 && (
            <div className="py-12 text-center text-gray-500">
              No invoices found
            </div>
          )}
        </div>

        {/* Invoice Details Modal */}
        {selectedInvoice && (
          <div className="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
            <div className="max-h-[90vh] w-full max-w-2xl overflow-auto rounded-lg bg-white p-6 shadow-xl">
              <h2 className="mb-4 text-xl font-bold text-gray-900">
                Invoice {selectedInvoice.number}
              </h2>

              <div className="mb-6 grid grid-cols-2 gap-4">
                <div>
                  <span className="text-sm text-gray-500">Tenant</span>
                  <div className="font-medium">
                    {selectedInvoice.tenant?.name}
                  </div>
                </div>
                <div>
                  <span className="text-sm text-gray-500">Status</span>
                  <div>
                    <span
                      className={`rounded-full px-2 py-0.5 text-xs font-semibold ${STATUS_COLORS[selectedInvoice.status]}`}
                    >
                      {selectedInvoice.status}
                    </span>
                  </div>
                </div>
                <div>
                  <span className="text-sm text-gray-500">Invoice Date</span>
                  <div>{formatDate(selectedInvoice.invoice_date)}</div>
                </div>
                <div>
                  <span className="text-sm text-gray-500">Due Date</span>
                  <div>{formatDate(selectedInvoice.due_date)}</div>
                </div>
              </div>

              {/* Line Items */}
              {selectedInvoice.items && selectedInvoice.items.length > 0 && (
                <div className="mb-6">
                  <h3 className="mb-2 font-semibold text-gray-700">
                    Line Items
                  </h3>
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="border-b">
                        <th className="py-2 text-left">Description</th>
                        <th className="py-2 text-right">Qty</th>
                        <th className="py-2 text-right">Unit Price</th>
                        <th className="py-2 text-right">Amount</th>
                      </tr>
                    </thead>
                    <tbody>
                      {selectedInvoice.items.map((item) => (
                        <tr key={item.id} className="border-b">
                          <td className="py-2">{item.description}</td>
                          <td className="py-2 text-right">{item.quantity}</td>
                          <td className="py-2 text-right">
                            {formatCurrency(
                              item.unit_price,
                              selectedInvoice.currency
                            )}
                          </td>
                          <td className="py-2 text-right">
                            {formatCurrency(item.amount, selectedInvoice.currency)}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}

              {/* Totals */}
              <div className="mb-6 border-t pt-4">
                <div className="flex justify-between py-1">
                  <span className="text-gray-500">Subtotal</span>
                  <span>
                    {formatCurrency(
                      selectedInvoice.subtotal,
                      selectedInvoice.currency
                    )}
                  </span>
                </div>
                {parseFloat(selectedInvoice.tax_amount) > 0 && (
                  <div className="flex justify-between py-1">
                    <span className="text-gray-500">
                      Tax ({selectedInvoice.tax_rate}%)
                    </span>
                    <span>
                      {formatCurrency(
                        selectedInvoice.tax_amount,
                        selectedInvoice.currency
                      )}
                    </span>
                  </div>
                )}
                {parseFloat(selectedInvoice.discount_amount) > 0 && (
                  <div className="flex justify-between py-1">
                    <span className="text-gray-500">Discount</span>
                    <span className="text-green-600">
                      -
                      {formatCurrency(
                        selectedInvoice.discount_amount,
                        selectedInvoice.currency
                      )}
                    </span>
                  </div>
                )}
                <div className="flex justify-between border-t py-2 font-bold">
                  <span>Total</span>
                  <span>
                    {formatCurrency(
                      selectedInvoice.total,
                      selectedInvoice.currency
                    )}
                  </span>
                </div>
                <div className="flex justify-between py-1">
                  <span className="text-gray-500">Paid</span>
                  <span className="text-green-600">
                    {formatCurrency(
                      selectedInvoice.amount_paid,
                      selectedInvoice.currency
                    )}
                  </span>
                </div>
                <div className="flex justify-between py-1 font-semibold">
                  <span>Amount Due</span>
                  <span
                    className={
                      parseFloat(selectedInvoice.amount_due) > 0
                        ? 'text-red-600'
                        : 'text-green-600'
                    }
                  >
                    {formatCurrency(
                      selectedInvoice.amount_due,
                      selectedInvoice.currency
                    )}
                  </span>
                </div>
              </div>

              {selectedInvoice.notes && (
                <div className="mb-6 border-t pt-4">
                  <h3 className="mb-2 font-semibold text-gray-700">Notes</h3>
                  <p className="text-sm text-gray-600">
                    {selectedInvoice.notes}
                  </p>
                </div>
              )}

              <div className="flex justify-end gap-3">
                {selectedInvoice.pdf_path && (
                  <a
                    href={getInvoiceDownloadUrl(selectedInvoice.id)}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700"
                  >
                    Download PDF
                  </a>
                )}
                <button
                  onClick={() => setSelectedInvoice(null)}
                  className="rounded-md bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200"
                >
                  Close
                </button>
              </div>
            </div>
          </div>
        )}

        {/* Create Invoice Modal */}
        {showCreateModal && (
          <div className="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
            <div className="max-h-[90vh] w-full max-w-lg overflow-auto rounded-lg bg-white p-6 shadow-xl">
              <h2 className="mb-4 text-xl font-bold text-gray-900">
                Create Invoice
              </h2>

              <div className="space-y-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700">
                    Tenant ID
                  </label>
                  <input
                    type="text"
                    value={createForm.tenant_id}
                    onChange={(e) =>
                      setCreateForm({ ...createForm, tenant_id: e.target.value })
                    }
                    placeholder="Enter tenant UUID"
                    className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                  />
                </div>

                <div>
                  <label className="mb-2 block text-sm font-medium text-gray-700">
                    Line Items
                  </label>
                  {createForm.items.map((item, index) => (
                    <div key={index} className="mb-2 flex gap-2">
                      <input
                        type="text"
                        value={item.description}
                        onChange={(e) =>
                          handleItemChange(index, 'description', e.target.value)
                        }
                        placeholder="Description"
                        className="flex-1 rounded-md border border-gray-300 px-3 py-2 text-sm"
                      />
                      <input
                        type="number"
                        value={item.quantity}
                        onChange={(e) =>
                          handleItemChange(index, 'quantity', e.target.value)
                        }
                        placeholder="Qty"
                        className="w-20 rounded-md border border-gray-300 px-3 py-2 text-sm"
                      />
                      <input
                        type="number"
                        step="0.01"
                        value={item.amount}
                        onChange={(e) =>
                          handleItemChange(index, 'amount', e.target.value)
                        }
                        placeholder="Amount"
                        className="w-28 rounded-md border border-gray-300 px-3 py-2 text-sm"
                      />
                      {createForm.items.length > 1 && (
                        <button
                          type="button"
                          onClick={() => handleRemoveItem(index)}
                          className="text-red-600 hover:text-red-800"
                        >
                          &times;
                        </button>
                      )}
                    </div>
                  ))}
                  <button
                    type="button"
                    onClick={handleAddItem}
                    className="mt-2 text-sm text-blue-600 hover:text-blue-800"
                  >
                    + Add Item
                  </button>
                </div>

                <div>
                  <label className="block text-sm font-medium text-gray-700">
                    Due Date
                  </label>
                  <input
                    type="date"
                    value={createForm.due_date}
                    onChange={(e) =>
                      setCreateForm({ ...createForm, due_date: e.target.value })
                    }
                    className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                  />
                </div>

                <div>
                  <label className="block text-sm font-medium text-gray-700">
                    Notes
                  </label>
                  <textarea
                    value={createForm.notes}
                    onChange={(e) =>
                      setCreateForm({ ...createForm, notes: e.target.value })
                    }
                    rows={3}
                    className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                  />
                </div>
              </div>

              <div className="mt-6 flex justify-end gap-3">
                <button
                  onClick={() => setShowCreateModal(false)}
                  className="rounded-md bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200"
                >
                  Cancel
                </button>
                <button
                  onClick={handleCreateInvoice}
                  disabled={createInvoice.isPending}
                  className="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
                >
                  {createInvoice.isPending ? 'Creating...' : 'Create Invoice'}
                </button>
              </div>
            </div>
          </div>
        )}
      </div>
    </div>
  )
}
