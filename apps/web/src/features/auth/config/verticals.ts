import {
  Store, Pill, Coffee, UtensilsCrossed, Shirt, Heart,
  Wrench, PaintBucket, Package, Car, Circle, Fuel,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import type { Product } from '@/contexts/ProductConfigContext'

export interface VerticalConfig {
  key: string
  icon: LucideIcon
  bgColor: string
  strokeColor: string
  labelKey: string
  descriptionKey: string
}

const iziposVerticals: VerticalConfig[] = [
  { key: 'retail', icon: Store, bgColor: 'bg-sky-50', strokeColor: 'text-sky-600', labelKey: 'auth:verticals.retail.label', descriptionKey: 'auth:verticals.retail.description' },
  { key: 'pharmacy', icon: Pill, bgColor: 'bg-green-50', strokeColor: 'text-green-600', labelKey: 'auth:verticals.pharmacy.label', descriptionKey: 'auth:verticals.pharmacy.description' },
  { key: 'coffee_shop', icon: Coffee, bgColor: 'bg-amber-50', strokeColor: 'text-amber-600', labelKey: 'auth:verticals.coffee_shop.label', descriptionKey: 'auth:verticals.coffee_shop.description' },
  { key: 'restaurant', icon: UtensilsCrossed, bgColor: 'bg-pink-50', strokeColor: 'text-pink-600', labelKey: 'auth:verticals.restaurant.label', descriptionKey: 'auth:verticals.restaurant.description' },
  { key: 'fashion', icon: Shirt, bgColor: 'bg-violet-50', strokeColor: 'text-violet-600', labelKey: 'auth:verticals.fashion.label', descriptionKey: 'auth:verticals.fashion.description' },
  { key: 'parapharmacy', icon: Heart, bgColor: 'bg-orange-50', strokeColor: 'text-orange-600', labelKey: 'auth:verticals.parapharmacy.label', descriptionKey: 'auth:verticals.parapharmacy.description' },
]

const otospexVerticals: VerticalConfig[] = [
  { key: 'mechanic', icon: Wrench, bgColor: 'bg-blue-50', strokeColor: 'text-blue-600', labelKey: 'auth:verticals.mechanic.label', descriptionKey: 'auth:verticals.mechanic.description' },
  { key: 'body_shop', icon: PaintBucket, bgColor: 'bg-red-50', strokeColor: 'text-red-600', labelKey: 'auth:verticals.body_shop.label', descriptionKey: 'auth:verticals.body_shop.description' },
  { key: 'parts_retailer', icon: Package, bgColor: 'bg-slate-50', strokeColor: 'text-slate-600', labelKey: 'auth:verticals.parts_retailer.label', descriptionKey: 'auth:verticals.parts_retailer.description' },
  { key: 'car_glass', icon: Car, bgColor: 'bg-cyan-50', strokeColor: 'text-cyan-600', labelKey: 'auth:verticals.car_glass.label', descriptionKey: 'auth:verticals.car_glass.description' },
  { key: 'tire_shop', icon: Circle, bgColor: 'bg-amber-50', strokeColor: 'text-amber-600', labelKey: 'auth:verticals.tire_shop.label', descriptionKey: 'auth:verticals.tire_shop.description' },
  { key: 'service_station', icon: Fuel, bgColor: 'bg-green-50', strokeColor: 'text-green-600', labelKey: 'auth:verticals.service_station.label', descriptionKey: 'auth:verticals.service_station.description' },
]

export function getVerticalsForProduct(product: Product): VerticalConfig[] {
  return product === 'otospex' ? otospexVerticals : iziposVerticals
}
