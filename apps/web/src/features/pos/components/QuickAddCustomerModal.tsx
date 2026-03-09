import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { X, UserPlus } from 'lucide-react'
import { apiPost } from '@/lib/api'

interface QuickAddCustomerModalProps {
  isOpen: boolean
  onClose: () => void
  onCustomerCreated: (customer: { id: string; name: string; phone?: string }) => void
}

interface CreatedContact {
  id: string
  first_name: string
  last_name: string | null
  full_name: string
  phone: string | null
  email: string | null
}

export function QuickAddCustomerModal({
  isOpen,
  onClose,
  onCustomerCreated,
}: QuickAddCustomerModalProps) {
  const { t } = useTranslation(['pos'])
  const [name, setName] = useState('')
  const [phone, setPhone] = useState('')
  const [email, setEmail] = useState('')
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  if (!isOpen) return null

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setError(null)

    const trimmedName = name.trim()
    if (!trimmedName) {
      setError(t('pos:cart.customerNameRequired'))
      return
    }

    setIsSubmitting(true)
    try {
      // Split name into first_name and last_name
      const nameParts = trimmedName.split(' ')
      const firstName = nameParts[0]
      const lastName = nameParts.length > 1 ? nameParts.slice(1).join(' ') : undefined

      const contact = await apiPost<CreatedContact>('/contacts', {
        first_name: firstName,
        ...(lastName ? { last_name: lastName } : {}),
        ...(phone.trim() ? { phone: phone.trim() } : {}),
        ...(email.trim() ? { email: email.trim() } : {}),
      })

      const created: { id: string; name: string; phone?: string } = {
        id: contact.id,
        name: contact.full_name,
      }
      if (contact.phone) {
        created.phone = contact.phone
      }
      onCustomerCreated(created)

      // Reset form
      setName('')
      setPhone('')
      setEmail('')
    } catch (err) {
      if (err instanceof Error) {
        setError(err.message)
      } else {
        setError(t('pos:cart.customerCreateFailed'))
      }
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
      <div className="bg-white rounded-xl shadow-xl w-full max-w-sm p-6">
        <div className="flex items-center justify-between mb-5">
          <div className="flex items-center gap-2">
            <UserPlus className="h-5 w-5 text-blue-600" />
            <h3 className="text-lg font-semibold text-gray-900">
              {t('pos:cart.quickAddCustomer')}
            </h3>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="p-1 rounded-lg text-gray-400 hover:text-gray-600 hover:bg-gray-100"
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        <form onSubmit={(e) => { void handleSubmit(e) }} className="space-y-4">
          <div>
            <label htmlFor="quick-customer-name" className="block text-sm font-medium text-gray-700 mb-1">
              {t('pos:cart.customerName')} *
            </label>
            <input
              id="quick-customer-name"
              type="text"
              value={name}
              onChange={(e) => { setName(e.target.value) }}
              placeholder={t('pos:cart.customerName')}
              className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              autoFocus
            />
          </div>

          <div>
            <label htmlFor="quick-customer-phone" className="block text-sm font-medium text-gray-700 mb-1">
              {t('pos:cart.customerPhone')}
            </label>
            <input
              id="quick-customer-phone"
              type="tel"
              value={phone}
              onChange={(e) => { setPhone(e.target.value) }}
              placeholder={t('pos:cart.customerPhone')}
              className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            />
          </div>

          <div>
            <label htmlFor="quick-customer-email" className="block text-sm font-medium text-gray-700 mb-1">
              {t('pos:cart.customerEmail')}
            </label>
            <input
              id="quick-customer-email"
              type="email"
              value={email}
              onChange={(e) => { setEmail(e.target.value) }}
              placeholder="email@example.com"
              className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            />
          </div>

          {error && (
            <p className="text-sm text-red-600">{error}</p>
          )}

          <div className="flex gap-3 pt-1">
            <button
              type="button"
              onClick={onClose}
              className="flex-1 px-4 py-2 text-sm font-medium text-gray-700 border border-gray-300 rounded-lg hover:bg-gray-50"
            >
              {t('common:actions.cancel')}
            </button>
            <button
              type="submit"
              disabled={isSubmitting || !name.trim()}
              className="flex-1 px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {isSubmitting ? t('common:status.saving') : t('common:actions.create')}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}
