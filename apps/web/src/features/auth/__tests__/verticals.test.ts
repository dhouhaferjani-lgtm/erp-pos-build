import { describe, it, expect } from 'vitest'
import { getVerticalsForProduct } from '../config/verticals'

describe('getVerticalsForProduct', () => {
  it('returns 6 verticals for izipos', () => {
    const verticals = getVerticalsForProduct('izipos')
    expect(verticals).toHaveLength(6)
    expect(verticals.map((v) => v.key)).toEqual([
      'retail', 'pharmacy', 'coffee_shop', 'restaurant', 'fashion', 'parapharmacy',
    ])
  })

  it('returns 6 verticals for otospex', () => {
    const verticals = getVerticalsForProduct('otospex')
    expect(verticals).toHaveLength(6)
    expect(verticals.map((v) => v.key)).toEqual([
      'mechanic', 'body_shop', 'parts_retailer', 'car_glass', 'tire_shop', 'service_station',
    ])
  })

  it('each vertical has required fields', () => {
    const verticals = getVerticalsForProduct('izipos')
    for (const v of verticals) {
      expect(v.key).toBeTruthy()
      expect(v.icon).toBeTruthy()
      expect(v.bgColor).toBeTruthy()
      expect(v.strokeColor).toBeTruthy()
      expect(v.labelKey).toBeTruthy()
      expect(v.descriptionKey).toBeTruthy()
    }
  })
})
