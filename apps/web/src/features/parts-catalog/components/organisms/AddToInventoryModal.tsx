import { useState, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { X, Package } from 'lucide-react'
import { addArticleToInventory } from '../../api/partsCatalog'
import type { EnrichedArticle, BrandQualityTier } from '../../types/catalog'
import { TaxConfigurationField } from '../../../../components/molecules/TaxConfigurationField'
import { MoneyInput } from '@/components/atoms'
import { useCompanyConfig } from '@/contexts'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

interface AddToInventoryModalProps {
  isOpen: boolean
  article: EnrichedArticle | null
  onClose: () => void
  onSuccess: () => void
}

const QUALITY_TIERS: BrandQualityTier[] = [
  'oe',
  'oes',
  'premium_aftermarket',
  'aftermarket',
  'economy',
]

export function AddToInventoryModal({
  isOpen,
  article,
  onClose,
  onSuccess,
}: AddToInventoryModalProps) {
  const { t } = useTranslation(['parts-catalog', 'common'])
  const { config } = useCompanyConfig()
  const currency = config?.currency ?? 'TND'

  const defaultName = useMemo(() => {
    if (!article) return ''
    return `${article.supplier?.brand ?? 'Unknown'} - ${article.article_number}`
  }, [article])

  const defaultSku = useMemo(() => {
    if (!article) return ''
    return `${article.supplier?.brand ?? 'Unknown'}-${article.article_number}`.toUpperCase()
  }, [article])

  const defaultBarcode = useMemo(() => {
    if (!article) return ''
    const eanRef = (article.cross_references ?? []).find((r) => r.reference_type === 'ean')
    return eanRef?.reference_number ?? ''
  }, [article])

  const [name, setName] = useState(defaultName)
  const [sku, setSku] = useState(defaultSku)
  const [barcode, setBarcode] = useState(defaultBarcode)
  const [salePrice, setSalePrice] = useState('')
  const [purchasePrice, setPurchasePrice] = useState('')
  const [taxRate, setTaxRate] = useState('0')
  const [taxConfigurationId, setTaxConfigurationId] = useState<string | null>(null)
  const [qualityTier, setQualityTier] = useState<BrandQualityTier>('aftermarket')
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  // Reset form when article changes
  if (article && name === '' && defaultName !== '') {
    setName(defaultName)
    setSku(defaultSku)
    setBarcode(defaultBarcode)
  }

  if (!isOpen || !article) return null

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setError(null)
    setIsSubmitting(true)

    try {
      await addArticleToInventory({
        name: name.trim(),
        sku: sku.trim(),
        type: 'part',
        ...(barcode.trim() ? { barcode: barcode.trim() } : {}),
        ...(salePrice ? { sale_price: salePrice } : {}),
        ...(purchasePrice ? { purchase_price: purchasePrice } : {}),
        ...(taxRate ? { tax_rate: taxRate } : {}),
        is_active: true,
        automotive_metadata: {
          platform_article_id: article.id,
          platform_link_status: 'linked',
          article_number: article.article_number,
          supplier_brand: article.supplier?.brand ?? '',
          product_group_name: '',
          brand_quality_tier: qualityTier,
          confidence_score: 0,
          data_source: 'manual',
          platform_original_data: {},
          cross_references: article.cross_references ?? [],
          vehicles: article.compatible_vehicles ?? [],
          criteria: article.criteria ?? [],
        },
      })

      onSuccess()
      onClose()
    } catch (err) {
      if (err instanceof Error) {
        setError(err.message)
      } else {
        setError(t('parts-catalog:addToInventory.error'))
      }
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <div className={`fixed inset-0 z-50 flex items-center justify-center ${colorTokens.surface.overlay}`}>
      <div className={`${colorTokens.surface.base} rounded-xl shadow-xl w-full max-w-md p-6 max-h-[90vh] overflow-y-auto`}>
        <div className="flex items-center justify-between mb-5">
          <div className="flex items-center gap-2">
            <Package className={`h-5 w-5 ${colorTokens.intent.primary.text}`} />
            <h3 className={`text-lg font-semibold ${colorTokens.text.primary}`}>
              {t('parts-catalog:addToInventory.title')}
            </h3>
          </div>
          <button
            type="button"
            onClick={onClose}
            className={`p-1 rounded-lg ${colorTokens.text.disabled} ${colorTokens.intent.neutral.textHover} ${colorTokens.intent.neutral.bgHoverSoft}`}
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        {/* Article context */}
        <div className={`flex items-center gap-2 mb-5 rounded-lg ${colorTokens.surface.page} px-3 py-2`}>
          <span className={`inline-flex items-center rounded ${colorTokens.intent.primary.bgSubtle} px-1.5 py-0.5 text-xs font-semibold ${colorTokens.intent.primary.textStrong} uppercase`}>
            {article.supplier?.brand ?? 'Unknown'}
          </span>
          <code className={`text-sm font-mono ${colorTokens.text.secondary}`}>{article.article_number}</code>
        </div>

        <form onSubmit={(e) => { void handleSubmit(e) }} className="space-y-4">
          {/* Name */}
          <div>
            <label htmlFor="inv-name" className={`block text-sm font-medium ${colorTokens.text.secondary} mb-1`}>
              {t('parts-catalog:addToInventory.name')} *
            </label>
            <input
              id="inv-name"
              type="text"
              value={name}
              onChange={(e) => { setName(e.target.value) }}
              className={`w-full rounded-lg border ${colorTokens.border.default} px-3 py-2 text-sm ${colorTokens.focus.primaryBorder} focus:outline-none focus:ring-1 ${colorTokens.focus.primaryRing}`}
              required
            />
          </div>

          {/* SKU */}
          <div>
            <label htmlFor="inv-sku" className={`block text-sm font-medium ${colorTokens.text.secondary} mb-1`}>
              {t('parts-catalog:addToInventory.sku')} *
            </label>
            <input
              id="inv-sku"
              type="text"
              value={sku}
              onChange={(e) => { setSku(e.target.value) }}
              className={`w-full rounded-lg border ${colorTokens.border.default} px-3 py-2 text-sm font-mono ${colorTokens.focus.primaryBorder} focus:outline-none focus:ring-1 ${colorTokens.focus.primaryRing}`}
              required
            />
          </div>

          {/* Barcode */}
          <div>
            <label htmlFor="inv-barcode" className={`block text-sm font-medium ${colorTokens.text.secondary} mb-1`}>
              {t('parts-catalog:addToInventory.barcode')}
            </label>
            <input
              id="inv-barcode"
              type="text"
              value={barcode}
              onChange={(e) => { setBarcode(e.target.value) }}
              className={`w-full rounded-lg border ${colorTokens.border.default} px-3 py-2 text-sm font-mono ${colorTokens.focus.primaryBorder} focus:outline-none focus:ring-1 ${colorTokens.focus.primaryRing}`}
            />
          </div>

          {/* Price row */}
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label htmlFor="inv-sale" className={`block text-sm font-medium ${colorTokens.text.secondary} mb-1`}>
                {t('parts-catalog:addToInventory.salePrice')}
              </label>
              <MoneyInput
                id="inv-sale"
                currency={currency}
                min="0"
                value={salePrice}
                onChange={setSalePrice}
              />
            </div>
            <div>
              <label htmlFor="inv-purchase" className={`block text-sm font-medium ${colorTokens.text.secondary} mb-1`}>
                {t('parts-catalog:addToInventory.purchasePrice')}
              </label>
              <MoneyInput
                id="inv-purchase"
                currency={currency}
                min="0"
                value={purchasePrice}
                onChange={setPurchasePrice}
              />
            </div>
          </div>

          {/* Tax Rate */}
          <TaxConfigurationField
            label={t('parts-catalog:addToInventory.taxRate')}
            value={taxConfigurationId}
            onChange={(configId, rate) => {
              setTaxConfigurationId(configId)
              setTaxRate(rate)
            }}
          />

          {/* Quality Tier */}
          <div>
            <label htmlFor="inv-tier" className={`block text-sm font-medium ${colorTokens.text.secondary} mb-1`}>
              {t('parts-catalog:addToInventory.qualityTier')}
            </label>
            <select
              id="inv-tier"
              value={qualityTier}
              onChange={(e) => { setQualityTier(e.target.value as BrandQualityTier) }}
              className={`w-full rounded-lg border ${colorTokens.border.default} px-3 py-2 text-sm ${colorTokens.focus.primaryBorder} focus:outline-none focus:ring-1 ${colorTokens.focus.primaryRing}`}
            >
              {QUALITY_TIERS.map((tier) => (
                <option key={tier} value={tier}>
                  {t(`parts-catalog:addToInventory.tiers.${tier}`)}
                </option>
              ))}
            </select>
          </div>

          {error && <p className={`text-sm ${colorTokens.intent.danger.text}`}>{error}</p>}

          <div className="flex gap-3 pt-1">
            <button
              type="button"
              onClick={onClose}
              className={`flex-1 px-4 py-2 text-sm font-medium ${colorTokens.text.secondary} border ${colorTokens.border.default} rounded-lg ${colorTokens.intent.neutral.bgHover}`}
            >
              {t('common:actions.cancel')}
            </button>
            <button
              type="submit"
              disabled={isSubmitting || !name.trim() || !sku.trim()}
              className={`flex-1 px-4 py-2 text-sm font-medium ${colorTokens.text.inverse} ${colorTokens.intent.primary.bgStrong} rounded-lg ${colorTokens.intent.primary.bgStrongHover} disabled:opacity-50 disabled:cursor-not-allowed`}
            >
              {isSubmitting ? t('common:status.saving') : t('parts-catalog:inventory.addToInventory')}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}
