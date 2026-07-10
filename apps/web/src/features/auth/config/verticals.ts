import {
  Store, Pill, Coffee, UtensilsCrossed, Shirt, Heart,
  Wrench, PaintBucket, Package, Car, Circle, Fuel,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import type { Product } from '@/contexts/ProductConfigContext'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

export interface VerticalConfig {
  key: string
  icon: LucideIcon
  bgColor: string
  strokeColor: string
  labelKey: string
  descriptionKey: string
}

const iziposVerticals: VerticalConfig[] = [
  { key: 'retail', icon: Store, bgColor: colorTokens.intent.info.bgSubtle, strokeColor: colorTokens.intent.info.text, labelKey: 'auth:verticals.retail.label', descriptionKey: 'auth:verticals.retail.description' },
  { key: 'pharmacy', icon: Pill, bgColor: colorTokens.intent.success.bgSubtle, strokeColor: colorTokens.intent.success.text, labelKey: 'auth:verticals.pharmacy.label', descriptionKey: 'auth:verticals.pharmacy.description' },
  { key: 'coffee_shop', icon: Coffee, bgColor: colorTokens.intent.caution.bgSubtle, strokeColor: colorTokens.intent.caution.text, labelKey: 'auth:verticals.coffee_shop.label', descriptionKey: 'auth:verticals.coffee_shop.description' },
  { key: 'restaurant', icon: UtensilsCrossed, bgColor: colorTokens.intent.promotion.bgSubtle, strokeColor: colorTokens.intent.promotion.text, labelKey: 'auth:verticals.restaurant.label', descriptionKey: 'auth:verticals.restaurant.description' },
  { key: 'fashion', icon: Shirt, bgColor: colorTokens.intent.fashion.bgSubtle, strokeColor: colorTokens.intent.fashion.text, labelKey: 'auth:verticals.fashion.label', descriptionKey: 'auth:verticals.fashion.description' },
  { key: 'parapharmacy', icon: Heart, bgColor: colorTokens.intent.notice.bgSubtle, strokeColor: colorTokens.intent.notice.text, labelKey: 'auth:verticals.parapharmacy.label', descriptionKey: 'auth:verticals.parapharmacy.description' },
]

const otospexVerticals: VerticalConfig[] = [
  { key: 'mechanic', icon: Wrench, bgColor: colorTokens.intent.primary.bgSubtle, strokeColor: colorTokens.intent.primary.text, labelKey: 'auth:verticals.mechanic.label', descriptionKey: 'auth:verticals.mechanic.description' },
  { key: 'body_shop', icon: PaintBucket, bgColor: colorTokens.intent.danger.bgSubtle, strokeColor: colorTokens.intent.danger.text, labelKey: 'auth:verticals.body_shop.label', descriptionKey: 'auth:verticals.body_shop.description' },
  { key: 'parts_retailer', icon: Package, bgColor: colorTokens.intent.ledger.bgSubtle, strokeColor: colorTokens.intent.ledger.text, labelKey: 'auth:verticals.parts_retailer.label', descriptionKey: 'auth:verticals.parts_retailer.description' },
  { key: 'car_glass', icon: Car, bgColor: colorTokens.intent.glass.bgSubtle, strokeColor: colorTokens.intent.glass.text, labelKey: 'auth:verticals.car_glass.label', descriptionKey: 'auth:verticals.car_glass.description' },
  { key: 'tire_shop', icon: Circle, bgColor: colorTokens.intent.caution.bgSubtle, strokeColor: colorTokens.intent.caution.text, labelKey: 'auth:verticals.tire_shop.label', descriptionKey: 'auth:verticals.tire_shop.description' },
  { key: 'service_station', icon: Fuel, bgColor: colorTokens.intent.success.bgSubtle, strokeColor: colorTokens.intent.success.text, labelKey: 'auth:verticals.service_station.label', descriptionKey: 'auth:verticals.service_station.description' },
]

export function getVerticalsForProduct(product: Product): VerticalConfig[] {
  return product === 'otospex' ? otospexVerticals : iziposVerticals
}
