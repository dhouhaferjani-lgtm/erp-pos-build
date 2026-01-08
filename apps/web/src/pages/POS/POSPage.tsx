import { useCompanyConfig } from '../../contexts'
import { StandardPOS } from './StandardPOS'

/**
 * POSPage - POS Variant Loader (Phase 1)
 *
 * This component serves as the entry point for the POS system.
 * It determines which POS variant to load based on the company's vertical configuration.
 *
 * **Phase 1 Implementation:**
 * - All verticals use StandardPOS component
 * - StandardPOS adapts its UI/behavior based on vertical config
 *
 * **Phase 2 Implementation (Future):**
 * - Load specialized variants for specific verticals:
 *   - RestaurantPOS (table management, kitchen tickets)
 *   - PharmacyPOS (prescription lookup, controlled substances)
 *   - WorkshopPOS (vehicle selection, job cards, labor tracking)
 *
 * **Supported Verticals (Phase 1):**
 * - retail, fashion, coffee_shop
 * - parts_retailer, car_glass, tire_shop, service_station
 * - parapharmacy, pharmacy (basic)
 * - restaurant (basic), mechanic (parts counter)
 */
export function POSPage() {
  const { config, isLoading } = useCompanyConfig()

  // While loading, don't render anything
  if (isLoading || !config) {
    return null
  }

  /**
   * Phase 1: Use StandardPOS for all verticals
   *
   * The StandardPOS component receives the vertical configuration
   * and adapts its interface accordingly through:
   * - Vertical-specific styling
   * - Feature toggling based on vertical
   * - Currency and locale from company config
   */
  return <StandardPOS />
}
