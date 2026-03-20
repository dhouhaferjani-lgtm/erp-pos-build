import { useTranslation } from 'react-i18next'
import { Check } from 'lucide-react'
import { useProductConfig } from '@/contexts/ProductConfigContext'

export function RegisterBrandPanel() {
  const { t } = useTranslation(['auth'])
  const { product, productName } = useProductConfig()

  const features = t(`auth:brandPanel.${product}.features`, { returnObjects: true }) as string[]

  return (
    <div className="hidden w-[420px] shrink-0 flex-col justify-between bg-slate-900 p-10 md:flex">
      <div>
        <h1 className="text-3xl font-bold text-white">{productName}</h1>
        <p className="mt-3 text-base text-slate-400">
          {t(`auth:brandPanel.${product}.tagline`)}
        </p>

        <ul className="mt-10 space-y-4">
          {features.map((feature) => (
            <li key={feature} className="flex items-center gap-3">
              <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-green-500/20">
                <Check className="h-3.5 w-3.5 text-green-400" />
              </span>
              <span className="text-sm text-slate-300">{feature}</span>
            </li>
          ))}
        </ul>
      </div>

      <p className="text-sm text-slate-500">
        {t(`auth:brandPanel.${product}.trial`)}
      </p>
    </div>
  )
}
